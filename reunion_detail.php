<?php
/**
 * Touba Lyon 2026 - 👥 Détail d'une réunion de commission
 *
 * Inspiré de guddi_detail.php. Accessible aux membres/responsables de la
 * commission concernée et aux administrateurs. Validation de présence,
 * lien Zoom (celui du Guddi Àjjuma) pour les réunions à distance.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_param(), dahira_presence_ouverte()
require_once __DIR__ . '/reunion_helper.php';         // reunion_periodicite_label()

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__reAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$membreId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;

if ($membreId === 0 && !$__reAdmin) {
    header('Location: login.php');
    exit;
}

// Charger la réunion
$reunion = null;
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM commission_reunions WHERE id = ?");
        $st->execute([$id]);
        $reunion = $st->fetch();
    } catch (Exception $e) {
        $reunion = null;
    }
}
if (!$reunion) {
    header('Location: ' . ($__reAdmin ? 'commission_dashboard.php' : 'index.php'));
    exit;
}

$commissionId = (int) $reunion['commission_id'];

// Accès : admin, responsable ou membre de la commission
$autorise = $__reAdmin;
if (!$autorise && $membreId > 0) {
    try {
        // membre validé ?
        $sc = $pdo->prepare("SELECT status FROM membres WHERE id = ?");
        $sc->execute([$membreId]);
        $stt = $sc->fetchColumn();
        if ($stt === 'approved') {
            $q1 = $pdo->prepare("SELECT 1 FROM commission_membres WHERE commission_id = ? AND membre_id = ? LIMIT 1");
            $q1->execute([$commissionId, $membreId]);
            $q2 = $pdo->prepare("SELECT 1 FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ? LIMIT 1");
            $q2->execute([$commissionId, $membreId]);
            $autorise = ($q1->fetchColumn() !== false) || ($q2->fetchColumn() !== false);
        }
    } catch (Exception $e) {
        $autorise = false;
    }
}
if (!$autorise) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Données
$commissionNom = reunion_commission_nom($pdo, $commissionId);
$titre = (string) $reunion['titre'];
$description = (string) ($reunion['description'] ?? '');
$date = (string) $reunion['date_reunion'];
$heure = (string) ($reunion['heure'] ?? '20h00');
$heureFin = trim((string) ($reunion['heure_fin'] ?? ''));
$mode = (string) ($reunion['mode'] ?? 'distance');
$lieu = (string) ($reunion['lieu'] ?? '');
$periodicite = (string) ($reunion['periodicite'] ?? 'ponctuelle');
$publie = ((int) ($reunion['publie'] ?? 0)) === 1;
$cloture = ((int) ($reunion['cloture'] ?? 0)) === 1;

// Lien de participation (Zoom) : celui de la réunion, sinon le lien par défaut du Guddi
$lienZoom = trim((string) ($reunion['lien'] ?? ''));
if ($lienZoom === '') { $lienZoom = trim(dahira_param($pdo, 'guddi_lien_defaut', '')); }

// Présences validées
$nbPresences = 0;
try {
    $stN = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'reunion' AND planning_id = ?");
    $stN->execute([$id]);
    $nbPresences = (int) $stN->fetchColumn();
} catch (Exception $e) { $nbPresences = 0; }

// Présence du membre connecté
$presenceFaite = false;
if ($membreId > 0) {
    try {
        $stP = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'reunion' AND planning_id = ? AND membre_id = ?");
        $stP->execute([$id, $membreId]);
        $presenceFaite = ((int) $stP->fetchColumn()) > 0;
    } catch (Exception $e) { $presenceFaite = false; }
}

$retour = $__reAdmin ? 'commission_dashboard.php' : 'index.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>👥 Détail réunion — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .gd-wrap { max-width: 860px; margin: 0 auto; }
        .gd-hero { border-radius: 24px; padding: 1.6rem 1.6rem 1.4rem; background: linear-gradient(160deg, rgba(212,175,55,0.14) 0%, rgba(255,255,255,0.03) 100%); border: 2px solid rgba(212,175,55,0.5); margin-bottom: 1.4rem; position: relative; overflow: hidden; }
        .gd-hero::before { content: ''; position: absolute; top: 0; left: -20%; right: -20%; height: 2px; background: linear-gradient(90deg, transparent, rgba(212,175,55,0.9), rgba(241,210,121,0.95), transparent); background-size: 200% 100%; animation: gdFlow 4.5s linear infinite; }
        @keyframes gdFlow { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .gd-hero .gd-date { font-size: 1.6rem; font-weight: 800; color: var(--accent); line-height: 1.25; }
        .gd-hero .gd-date small { display: block; font-size: 0.85rem; font-weight: 400; color: var(--text-muted); margin-top: 0.25rem; }
        .gd-badges { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.8rem; }
        .gd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.9rem; margin-bottom: 1.4rem; }
        .gd-item { border-radius: 14px; padding: 1rem 1.1rem; }
        .gd-item .k { font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.35rem; }
        .gd-item .v { font-size: 0.98rem; font-weight: 600; color: var(--white); white-space: pre-line; word-break: break-word; }
        .gd-back { display: inline-flex; align-items: center; gap: 0.4rem; margin-bottom: 1rem; }
        @keyframes gdCardIn { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
        .gd-anim { animation: gdCardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both; }
        .gd-anim-1 { animation-delay: 0.05s; } .gd-anim-2 { animation-delay: 0.10s; } .gd-anim-3 { animation-delay: 0.15s; } .gd-anim-4 { animation-delay: 0.20s; } .gd-anim-5 { animation-delay: 0.25s; }
        .gd-presence .btn-primary { transition: transform 0.12s ease, box-shadow 0.2s ease, filter 0.2s ease; }
        .gd-presence .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.3); filter: brightness(1.05); }
        @media (max-width: 480px) { .gd-presence { text-align: center; } .gd-presence .btn { width: 100%; } }
        .gd-zoom-btn { display: flex; align-items: center; justify-content: center; gap: 0.9rem; width: 100%; background: linear-gradient(135deg, #2d8cff 0%, #1a63d0 100%); color: #fff; font-weight: 800; font-size: 1.25rem; border: 0; border-radius: 18px; padding: 1.2rem 1.4rem; text-decoration: none; box-shadow: 0 14px 34px rgba(45,140,255,0.4), inset 0 1px 0 rgba(255,255,255,0.2); transition: transform 0.15s ease, box-shadow 0.2s ease, filter 0.2s ease; animation: gdZoomPulse 2.6s ease-in-out infinite; }
        @keyframes gdZoomPulse { 0%, 100% { box-shadow: 0 14px 34px rgba(45,140,255,0.4), inset 0 1px 0 rgba(255,255,255,0.2); } 50% { box-shadow: 0 18px 42px rgba(45,140,255,0.6), inset 0 1px 0 rgba(255,255,255,0.2); } }
        .gd-zoom-btn:hover { transform: translateY(-2px); filter: brightness(1.08); animation-play-state: paused; }
        .gd-zoom-btn .zoom-ic { width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0; background: rgba(255,255,255,0.22); display: inline-flex; align-items: center; justify-content: center; }
        .gd-zoom-btn .zoom-lbl { display: flex; flex-direction: column; align-items: flex-start; line-height: 1.2; }
        .gd-zoom-btn .zoom-lbl small { font-size: 0.78rem; font-weight: 600; opacity: 0.85; }
        .gd-zoom-hint { color: var(--text-muted); font-size: 0.8rem; margin-top: 0.6rem; text-align: center; }
        .pl-badge { font-size: 0.8rem; padding: 0.3rem 0.7rem; border-radius: 6px; }
        .pl-badge-ok { background: rgba(37,211,102,0.15); color: #7bd8a6; border: 1px solid rgba(37,211,102,0.3); }
        .pl-badge-no { background: rgba(255,255,255,0.05); color: var(--text-muted); border: 1px solid var(--glass-border); }
        .pl-badge-pub { background: rgba(45,140,255,0.15); color: #8ec5ff; border: 1px solid rgba(45,140,255,0.4); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__reAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>

            <div class="admin-content">
                <?php if ($__reAdmin): ?><a href="<?php echo $retour; ?>" class="gd-back btn btn-secondary btn-sm">← Retour</a><?php endif; ?>
                <h1 class="admin-page-title">👥 Détail réunion</h1>

                <div class="gd-wrap">
                    <!-- En-tête -->
                    <div class="gd-hero gd-anim gd-anim-1">
                        <div class="gd-date">
                            📌 <?php echo htmlspecialchars($titre); ?>
                            <small>📋 <?php echo htmlspecialchars($commissionNom); ?> · 🗓️ <?php echo date('d/m/Y', strtotime($date)); ?> · 🕐 <?php echo $heureFin !== '' ? 'de ' . htmlspecialchars($heure) . ' à ' . htmlspecialchars($heureFin) : 'à partir de ' . htmlspecialchars($heure); ?></small>
                        </div>
                        <div class="gd-badges">
                            <?php $etat = $cloture ? 'termine' : dahira_event_etat($date, $heure, ($heureFin !== '' ? $heureFin : null)); ?>
                            <?php if ($etat === 'termine'): ?>
                                <span class="pl-badge pl-badge-no">🕌 Terminée</span>
                            <?php elseif ($etat === 'encours'): ?>
                                <span class="pl-badge pl-badge-ok badge-live">En cours</span>
                            <?php else: ?>
                                <span class="pl-badge pl-badge-pub">🗓️ À venir</span>
                            <?php endif; ?>
                            <?php if ($periodicite !== 'ponctuelle'): ?>
                                <span class="pl-badge pl-badge-no">🔁 <?php echo htmlspecialchars(reunion_periodicite_label($periodicite)); ?></span>
                            <?php endif; ?>
                            <span class="pl-badge pl-badge-no"><?php echo $mode === 'presentiel' ? '🏛️ Présentiel' : '💻 À distance'; ?></span>
                        </div>
                    </div>

                    <!-- Indicateur de participation -->
                    <div class="gd-anim gd-anim-2" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.75rem; margin-bottom:1.4rem;">
                        <div class="glass-card" style="padding:1rem; text-align:center;">
                            <div style="font-size:1.8rem; font-weight:700; color:var(--accent); line-height:1.2;"><?php echo (int) $nbPresences; ?></div>
                            <div style="color:var(--text-muted); font-size:0.82rem;">👥 Participants</div>
                        </div>
                    </div>

                    <!-- Validation de présence -->
                    <?php if ($membreId > 0 && $publie): ?>
                    <div class="glass-card gd-presence gd-anim gd-anim-3" style="margin-bottom:1.4rem; border:2px solid rgba(212,175,55,0.45);">
                        <h3 style="color:var(--white); margin-bottom:0.6rem;">✅ Validez votre présence</h3>
                        <?php if ($presenceFaite): ?>
                            <div style="color:#7bd8a6; font-weight:700; margin-bottom:0.6rem;">✅ Présence confirmée — Diadieuf</div>
                            <button type="button" class="btn btn-secondary btn-sm" style="width:100%; text-align:center;" onclick="annulerPresence('reunion', <?php echo (int) $id; ?>, this)">↩️ Annuler ma présence</button>
                        <?php elseif (dahira_presence_ouverte($date, $heure)): ?>
                            <button type="button" class="btn btn-primary btn-sm" style="width:100%;" onclick="validerPresence('reunion', <?php echo (int) $id; ?>, this)">✅ J'étais présent(e)</button>
                        <?php else: ?>
                            <div style="color:var(--text-muted); font-size:0.85rem;">🔔 La validation sera possible à partir de <?php echo htmlspecialchars($heure); ?> le <?php echo date('d/m/Y', strtotime($date)); ?>.</div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($membreId > 0 && !$publie): ?>
                    <div class="gd-anim gd-anim-3" style="margin-bottom:1.4rem; color:var(--text-muted); font-size:0.85rem;">💤 Cette réunion n'est pas encore publiée : la validation de présence n'est pas disponible.</div>
                    <?php endif; ?>

                    <!-- Participer : lien Zoom (mode distance) -->
                    <?php if ($mode === 'distance' && $lienZoom !== ''): ?>
                    <div class="glass-card gd-anim gd-anim-3" style="margin-bottom:1.4rem; border:2px solid rgba(45,140,255,0.5);">
                        <h3 style="color:var(--white); margin-bottom:0.8rem;"><?php echo $cloture ? '💻 Revoir / participer' : '💻 Participer à cette réunion'; ?></h3>
                        <a class="gd-zoom-btn" href="<?php echo htmlspecialchars($lienZoom); ?>" target="_blank" rel="noopener">
                            <span class="zoom-ic"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" aria-hidden="true"><path d="M17.96 8.55V7.3c0-1.9-1.55-3.45-3.45-3.45H7.29c-1.9 0-3.45 1.55-3.45 3.45v9.4c0 1.9 1.55 3.45 3.45 3.45h7.22c1.9 0 3.45-1.55 3.45-3.45v-1.25l4.5 3.35v-14.7l-4.5 3.35Z" fill="#fff"/></svg></span>
                            <span class="zoom-lbl">Rejoindre en Zoom<small>Réunion à distance</small></span>
                        </a>
                        <div class="gd-zoom-hint"><?php echo $cloture ? 'La réunion est clôturée — vous pouvez toujours rejoindre le lien.' : '🕐 à partir de ' . htmlspecialchars($heure) . ' · le lien s\'ouvre dans un nouvel onglet'; ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Informations -->
                    <div class="gd-grid gd-anim gd-anim-4">
                        <div class="glass-card gd-item">
                            <div class="k">📋 Commission</div>
                            <div class="v"><?php echo $commissionNom !== '' ? htmlspecialchars($commissionNom) : '—'; ?></div>
                        </div>
                        <div class="glass-card gd-item">
                            <div class="k">🗓️ Date & heure</div>
                            <div class="v"><?php echo date('d/m/Y', strtotime($date)); ?> · <?php echo $heureFin !== '' ? 'de ' . htmlspecialchars($heure) . ' à ' . htmlspecialchars($heureFin) : htmlspecialchars($heure); ?></div>
                        </div>
                        <?php if ($mode === 'presentiel' && $lieu !== ''): ?>
                        <div class="glass-card gd-item">
                            <div class="k">📍 Lieu</div>
                            <div class="v"><?php echo htmlspecialchars($lieu); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="glass-card gd-item">
                            <div class="k">🔁 Périodicité</div>
                            <div class="v"><?php echo htmlspecialchars(reunion_periodicite_label($periodicite)); ?></div>
                        </div>
                        <div class="glass-card gd-item">
                            <div class="k">👥 Participants</div>
                            <div class="v"><?php echo $nbPresences > 0 ? (int) $nbPresences : '—'; ?></div>
                        </div>
                        <?php if ($description !== ''): ?>
                        <div class="glass-card gd-item" style="grid-column:1 / -1;">
                            <div class="k">📝 Ordre du jour</div>
                            <div class="v"><?php echo nl2br(htmlspecialchars($description)); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="gd-anim gd-anim-5" style="display:flex; gap:0.6rem; flex-wrap:wrap;">
                        <a href="<?php echo $retour; ?>" class="btn btn-secondary btn-sm">← Retour</a>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        var presenceToken = <?php echo json_encode(isset($trobaCsrf) ? $trobaCsrf : ''); ?>;
        function validerPresence(type, id, btn) {
            if (presenceToken === '') {
                try { presenceToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content'); } catch (e) {}
            }
            var corps = new URLSearchParams();
            corps.set('action', 'validate');
            corps.set('type', type);
            corps.set('id', String(id));
            corps.set('csrf_token', presenceToken);
            fetch('presence_validate.php', { method: 'POST', body: corps })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { window.location.reload(); }
                    else if (window.modernAlert) { window.modernAlert(d.msg || 'Erreur.', 'Erreur'); }
                    else { alert(d.msg || 'Erreur.'); }
                })
                .catch(function () {
                    if (window.modernAlert) { window.modernAlert('Une erreur est survenue. Réessayez.', 'Erreur'); }
                    else { alert('Une erreur est survenue. Réessayez.'); }
                });
        }
        function annulerPresence(type, id, btn) {
            if (window.modernConfirm) {
                window.modernConfirm(
                    'Voulez-vous vraiment <strong>annuler votre présence</strong> à cette réunion ?',
                    function () { annulerPresenceOk(type, id); },
                    { danger: true, bouton: 'Oui, annuler', titre: '↩️ Annuler ma présence' }
                );
            } else {
                annulerPresenceOk(type, id);
            }
        }
        function annulerPresenceOk(type, id) {
            var corps = new URLSearchParams();
            corps.set('action', 'cancel');
            corps.set('type', type);
            corps.set('id', String(id));
            corps.set('csrf_token', presenceToken);
            fetch('presence_validate.php', { method: 'POST', body: corps })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { window.location.reload(); }
                    else if (window.modernAlert) { window.modernAlert(d.msg || 'Erreur.'); }
                    else { alert(d.msg || 'Erreur.'); }
                })
                .catch(function () {
                    if (window.modernAlert) { window.modernAlert('Une erreur est survenue. Réessayez.'); }
                    else { alert('Une erreur est survenue. Réessayez.'); }
                });
        }
    </script>
</body>
</html>
