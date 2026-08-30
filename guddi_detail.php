<?php
/**
 * Touba Lyon 2026 - 💎 Détail d'une séance « Guddi Àjjuma »
 *
 * Page de détail d'un jeudi passé ou clôturé. Accessible depuis
 * admin_guddi.php via ?id=<id>, et aux membres connectés et validés
 * (validation de présence) via les notifications.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_param, wa_link, wa_button
require_once __DIR__ . '/planning_guddi_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__guAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Accès : membre connecté et validé, ou administrateur
if (empty($_SESSION['player_id']) && !$__guAdmin) {
    header('Location: login.php');
    exit;
}
if (!empty($_SESSION['player_id']) && !$__guAdmin) {
    try {
        $st = $pdo->prepare("SELECT status FROM membres WHERE id = ?");
        $st->execute([(int) $_SESSION['player_id']]);
        $stt = $st->fetchColumn();
        if ($stt !== false && $stt !== 'approved') {
            header('Location: profile.php');
            exit;
        }
    } catch (Exception $e) {
        // silencieux
    }
}

$error = '';
$success = '';

// Charger la séance
$seance = null;
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM guddi_plannings WHERE id = ?");
        $st->execute([$id]);
        $seance = $st->fetch();
    } catch (Exception $e) {
        $seance = null;
    }
}

if (!$seance) {
    header('Location: ' . ($__guAdmin ? 'admin_guddi.php' : 'index.php'));
    exit;
}

// Données partagées
$heure = dahira_param($pdo, 'guddi_heure', '20h00');
$lieuDefaut = dahira_param($pdo, 'guddi_lieu_defaut', '');
$lieuDahira = $lieuDefaut !== '' ? $lieuDefaut : dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
$modeDefaut = dahira_param($pdo, 'guddi_mode_defaut', 'distance');

$date = $seance['date_guddi'];
$actif = ((int)($seance['actif'] ?? 1)) === 1;
$cloture = ((int)($seance['cloture'] ?? 0)) === 1;
$mode = (string)($seance['mode'] ?? '');
if ($mode === '') { $mode = $modeDefaut; }
$theme = (string)($seance['theme'] ?? '');
$presentateur = (string)($seance['presentateur'] ?? '');
$livre = (string)($seance['livre'] ?? '');
$pdfPath = (string)($seance['pdf_path'] ?? '');
$nbParticipants = $seance['nb_participants'] ?? null;

// Nombre de participants : présences validées par les membres (sinon saisie manuelle)
$nbPresences = 0;
try {
    $stN = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'guddi' AND planning_id = ?");
    $stN->execute([$id]);
    $nbPresences = (int) $stN->fetchColumn();
} catch (Exception $e) {
    $nbPresences = 0;
}
$nbPartIndicateur = $nbPresences > 0 ? $nbPresences : (int) ($nbParticipants ?? 0);

// Présence du membre connecté
$presenceFaite = false;
$membreId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;
if ($membreId > 0) {
    try {
        $stP = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'guddi' AND planning_id = ? AND membre_id = ?");
        $stP->execute([$id, $membreId]);
        $presenceFaite = ((int) $stP->fetchColumn()) > 0;
    } catch (Exception $e) {
        $presenceFaite = false;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💎 Détail Guddi Àjjuma — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .gd-wrap { max-width: 860px; margin: 0 auto; }
        .gd-hero {
            border-radius: 24px;
            padding: 1.6rem 1.6rem 1.4rem;
            background: linear-gradient(160deg, rgba(212,175,55,0.14) 0%, rgba(255,255,255,0.03) 100%);
            border: 2px solid rgba(212,175,55,0.5);
            margin-bottom: 1.4rem;
        }
        .gd-hero .gd-date { font-size: 1.9rem; font-weight: 800; color: var(--accent); line-height: 1.2; }
        .gd-hero .gd-date small { display: block; font-size: 0.85rem; font-weight: 400; color: var(--text-muted); margin-top: 0.2rem; }
        .gd-badges { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.8rem; }
        .gd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.9rem; margin-bottom: 1.4rem; }
        .gd-item { border-radius: 14px; padding: 1rem 1.1rem; }
        .gd-item .k { font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.35rem; }
        .gd-item .v { font-size: 0.98rem; font-weight: 600; color: var(--white); white-space: pre-line; word-break: break-word; }
        .gd-item .v a { color: var(--accent); }
        .gd-back { display: inline-flex; align-items: center; gap: 0.4rem; margin-bottom: 1rem; }
        /* Animations d'entrée (comme sur l'accueil) */
        @keyframes gdCardIn {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .gd-anim { animation: gdCardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both; }
        .gd-anim-1 { animation-delay: 0.05s; }
        .gd-anim-2 { animation-delay: 0.10s; }
        .gd-anim-3 { animation-delay: 0.15s; }
        .gd-anim-4 { animation-delay: 0.20s; }
        .gd-anim-5 { animation-delay: 0.25s; }
        /* Liseré doré animé en haut de l'en-tête */
        .gd-hero { position: relative; overflow: hidden; }
        .gd-hero::before {
            content: '';
            position: absolute;
            top: 0; left: -20%; right: -20%;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,0.9), rgba(241,210,121,0.95), transparent);
            background-size: 200% 100%;
            animation: gdFlow 4.5s linear infinite;
        }
        @keyframes gdFlow { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        /* Carte de validation de présence : bouton doré avec léger survol */
        .gd-presence .btn-primary { transition: transform 0.12s ease, box-shadow 0.2s ease, filter 0.2s ease; }
        .gd-presence .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.3); filter: brightness(1.05); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__guAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>

            <div class="admin-content">
                <a href="<?php echo $__guAdmin ? 'admin_guddi.php' : 'index.php'; ?>" class="gd-back btn btn-secondary btn-sm">← Retour<?php echo $__guAdmin ? ' au planning' : ''; ?></a>
                <h1 class="admin-page-title">💎 Détail Guddi Àjjuma</h1>

                <?php if (!empty($success)): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <div class="gd-wrap">
                    <!-- En-tête -->
                    <div class="gd-hero gd-anim gd-anim-1">
                        <div class="gd-date">
                            <?php echo ucfirst(guddi_jour_fr($date)) . ' ' . date('d/m/Y', strtotime($date)); ?>
                            <small>🕐 à partir de <?php echo htmlspecialchars($heure); ?></small>
                        </div>
                        <div class="gd-badges">
                            <?php if ($cloture): ?>
                                <span class="pl-badge pl-badge-ok" style="font-size:0.8rem; padding:0.3rem 0.7rem;">✅ Clôturée<?php echo !empty($nbParticipants) ? ' · ' . (int)$nbParticipants . ' pers.' : ''; ?></span>
                            <?php elseif ($actif): ?>
                                <span class="pl-badge pl-badge-no" style="font-size:0.8rem; padding:0.3rem 0.7rem;">💎 Terminé</span>
                            <?php else: ?>
                                <span class="pl-badge pl-badge-annul" style="font-size:0.8rem; padding:0.3rem 0.7rem;">‼️ Annulé</span>
                            <?php endif; ?>
                            <span class="pl-badge" style="font-size:0.8rem; padding:0.3rem 0.7rem; background:rgba(212,175,55,0.12); color:#ffd873; border:1px solid rgba(212,175,55,0.35);">
                                <?php echo $mode === 'presentiel' ? '🏛️ En présentiel' : '💻 À distance (Zoom)'; ?>
                            </span>
                        </div>
                    </div>

                    <!-- Indicateur de participation (basé sur les présences validées) -->
                    <div class="gd-anim gd-anim-2" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.75rem; margin-bottom:1.4rem;">
                        <div class="glass-card" style="padding:1rem; text-align:center;">
                            <div class="gd-stat-valeur" style="font-size:1.8rem; font-weight:700; color:var(--accent); line-height:1.2;"><?php echo (int)$nbPartIndicateur; ?></div>
                            <div style="color:var(--text-muted); font-size:0.82rem;">👥 Participants</div>
                        </div>
                    </div>

                    <!-- Validation de présence (en haut) -->
                    <?php if ($membreId > 0 && ((int)($seance['publie'] ?? 0)) === 1): ?>
                    <div class="glass-card gd-presence gd-anim gd-anim-3" style="margin-bottom:1.4rem; border:2px solid rgba(212,175,55,0.45);">
                        <h3 style="color:var(--white); margin-bottom:0.6rem;">✅ Validez votre présence</h3>
                        <?php if ($date <= date('Y-m-d')): ?>
                            <?php if ($presenceFaite): ?>
                                <div style="color:#7bd8a6; font-weight:700; margin-bottom:0.6rem;">✅ Présence confirmée — Jazakallahou Khair</div>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="annulerPresence('guddi', <?php echo (int)$id; ?>, this)">↩️ Annuler ma présence</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-primary btn-sm" style="width:100%;" onclick="validerPresence('guddi', <?php echo (int)$id; ?>, this)">✅ J'étais présent(e)</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="color:var(--text-muted); font-size:0.85rem;">🔔 Vous pourrez valider votre présence le jour même.</div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($membreId > 0 && ((int)($seance['publie'] ?? 0)) !== 1): ?>
                    <div class="gd-anim gd-anim-3" style="margin-bottom:1.4rem; color:var(--text-muted); font-size:0.85rem;">💤 Ce Guddi Àjjuma n'est pas encore publié : la validation de présence n'est pas disponible.</div>
                    <?php elseif ($membreId === 0): ?>
                    <div class="gd-anim gd-anim-3" style="margin-bottom:1.4rem; color:var(--text-muted); font-size:0.85rem;">🔐 <a href="login.php" style="color:var(--accent);">Connectez-vous</a> pour valider votre présence.</div>
                    <?php endif; ?>

                    <!-- Informations -->
                    <div class="gd-grid gd-anim gd-anim-4">
                        <div class="glass-card gd-item">
                            <div class="k">🎯 Thème</div>
                            <div class="v"><?php echo $theme !== '' ? htmlspecialchars($theme) : '—'; ?></div>
                        </div>
                        <div class="glass-card gd-item">
                            <div class="k">🎤 Présentateur</div>
                            <div class="v"><?php echo $presentateur !== '' ? htmlspecialchars($presentateur) : '—'; ?></div>
                        </div>
                        <div class="glass-card gd-item">
                            <div class="k">📍 Lieu</div>
                            <div class="v"><?php echo $mode === 'presentiel' && $lieuDahira !== '' ? htmlspecialchars($lieuDahira) : '—'; ?></div>
                        </div>
                        <div class="glass-card gd-item">
                            <div class="k">📖 Livre étudié</div>
                            <div class="v"><?php echo $livre !== '' ? htmlspecialchars($livre) : '—'; ?></div>
                        </div>
                        <div class="glass-card gd-item">
                            <div class="k">👥 Participants</div>
                            <div class="v"><?php echo $nbPartIndicateur > 0 ? (int)$nbPartIndicateur : '—'; ?></div>
                        </div>
                        <div class="glass-card gd-item">
                            <div class="k">📄 Livre étudié (PDF)</div>
                            <div class="v"><?php echo $pdfPath !== '' ? '<a href="uploads/' . htmlspecialchars($pdfPath) . '" target="_blank" rel="noopener">Voir le PDF</a>' : '—'; ?></div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="gd-anim gd-anim-5" style="display:flex; gap:0.6rem; flex-wrap:wrap;">
                        <a href="<?php echo $__guAdmin ? 'admin_guddi.php' : 'index.php'; ?>" class="btn btn-secondary btn-sm">← Retour</a>
                        <?php if ($__guAdmin && !$cloture && !$actif): ?>
                            <a href="admin_guddi.php" class="btn btn-primary btn-sm">↩️ Réactiver</a>
                        <?php endif; ?>
                        <?php if ($__guAdmin && !$cloture): ?>
                            <a href="admin_guddi.php" class="btn btn-secondary btn-sm" style="border-color:rgba(37,211,102,0.6); color:#7bd8a6;">✅ Clôturer</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        // Validation de présence (Guddi Àjjuma)
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
                    else { alert(d.msg || 'Erreur.'); }
                })
                .catch(function () { alert('Une erreur est survenue. Réessayez.'); });
        }
        function annulerPresence(type, id, btn) {
            var corps = new URLSearchParams();
            corps.set('action', 'cancel');
            corps.set('type', type);
            corps.set('id', String(id));
            corps.set('csrf_token', presenceToken);
            fetch('presence_validate.php', { method: 'POST', body: corps })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { window.location.reload(); }
                    else { alert(d.msg || 'Erreur.'); }
                })
                .catch(function () { alert('Une erreur est survenue. Réessayez.'); });
        }
    </script>
</body>
</html>
