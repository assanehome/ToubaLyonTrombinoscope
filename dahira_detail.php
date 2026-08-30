<?php
/**
 * Touba Lyon 2026 - 🕌 Détail d'un Dahira
 *
 * Page de détail d'un dimanche de Dahira (passé, à venir ou clôturé).
 * Accessible aux membres connectés et validés (validation de présence),
 * ainsi qu'aux administrateurs / responsables Secrétariat Général.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/planning_dahira_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__dhAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Accès : membre connecté et validé, ou administrateur
if (empty($_SESSION['player_id']) && !$__dhAdmin) {
    header('Location: login.php');
    exit;
}
if (!empty($_SESSION['player_id']) && !$__dhAdmin) {
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

// Charger le Dahira
$dahira = null;
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM dahira_plannings WHERE id = ?");
        $st->execute([$id]);
        $dahira = $st->fetch();
    } catch (Exception $e) {
        $dahira = null;
    }
}

if (!$dahira) {
    header('Location: index.php');
    exit;
}

// Données partagées
$lieu = dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
$debut = dahira_param($pdo, 'dahira_debut', '17h00');
$fin = dahira_param($pdo, 'dahira_fin', '20h30');

$date = $dahira['date_dahira'];
$cloture = ((int)($dahira['cloture'] ?? 0)) === 1;
$publie = ((int)($dahira['publie'] ?? 0)) === 1;
$programme = (string)($dahira['programme'] ?? '');
$nbParticipants = $dahira['nb_participants'] ?? null;

// Nombre de participants : présences validées par les membres (sinon saisie manuelle)
$nbPresences = 0;
try {
    $stN = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'dahira' AND planning_id = ?");
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
        $stP = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'dahira' AND planning_id = ? AND membre_id = ?");
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
    <title>🕌 Détail Dahira — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .dh-wrap { max-width: 860px; margin: 0 auto; }
        .dh-hero {
            border-radius: 24px;
            padding: 1.6rem 1.6rem 1.4rem;
            background: linear-gradient(160deg, rgba(212,175,55,0.14) 0%, rgba(255,255,255,0.03) 100%);
            border: 2px solid rgba(212,175,55,0.5);
            margin-bottom: 1.4rem;
        }
        .dh-hero .dh-date { font-size: 1.9rem; font-weight: 800; color: var(--accent); line-height: 1.2; }
        .dh-hero .dh-date small { display: block; font-size: 0.85rem; font-weight: 400; color: var(--text-muted); margin-top: 0.2rem; }
        .dh-badges { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.8rem; }
        .dh-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.9rem; margin-bottom: 1.4rem; }
        .dh-item { border-radius: 14px; padding: 1rem 1.1rem; }
        .dh-item .k { font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.35rem; }
        .dh-item .v { font-size: 0.98rem; font-weight: 600; color: var(--white); white-space: pre-line; word-break: break-word; }
        .dh-item .v a { color: var(--accent); }
        .dh-back { display: inline-flex; align-items: center; gap: 0.4rem; margin-bottom: 1rem; }
        /* Animations d'entrée (comme sur l'accueil) */
        @keyframes dhCardIn {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dh-anim { animation: dhCardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both; }
        .dh-anim-1 { animation-delay: 0.05s; }
        .dh-anim-2 { animation-delay: 0.10s; }
        .dh-anim-3 { animation-delay: 0.15s; }
        .dh-anim-4 { animation-delay: 0.20s; }
        .dh-anim-5 { animation-delay: 0.25s; }
        /* Liseré doré animé en haut de l'en-tête */
        .dh-hero { position: relative; overflow: hidden; }
        .dh-hero::before {
            content: '';
            position: absolute;
            top: 0; left: -20%; right: -20%;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,0.9), rgba(241,210,121,0.95), transparent);
            background-size: 200% 100%;
            animation: dhFlow 4.5s linear infinite;
        }
        @keyframes dhFlow { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        /* Carte de validation de présence : bouton doré avec léger survol */
        .dh-presence .btn-primary { transition: transform 0.12s ease, box-shadow 0.2s ease, filter 0.2s ease; }
        .dh-presence .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.3); filter: brightness(1.05); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__dhAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>

            <div class="admin-content">
                <a href="<?php echo $__dhAdmin ? 'admin_planning.php' : 'index.php'; ?>" class="dh-back btn btn-secondary btn-sm">← Retour</a>
                <h1 class="admin-page-title">🕌 Détail Dahira</h1>

                <?php if (!empty($success)): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <div class="dh-wrap">
                    <!-- En-tête -->
                    <div class="dh-hero dh-anim dh-anim-1">
                        <div class="dh-date">
                            <?php echo ucfirst(dahira_jour_fr($date)) . ' ' . date('d/m/Y', strtotime($date)); ?>
                            <small>🕐 de <?php echo htmlspecialchars($debut); ?> à <?php echo htmlspecialchars($fin); ?></small>
                        </div>
                        <div class="dh-badges">
                            <?php if ($cloture): ?>
                                <span class="pl-badge pl-badge-ok" style="font-size:0.8rem; padding:0.3rem 0.7rem;">✅ Clôturé<?php echo !empty($nbParticipants) ? ' · ' . (int)$nbParticipants . ' pers.' : ''; ?></span>
                            <?php else: ?>
                                <span class="pl-badge pl-badge-no" style="font-size:0.8rem; padding:0.3rem 0.7rem;">🕌 Terminé</span>
                            <?php endif; ?>
                            <?php if ($publie): ?>
                                <span class="pl-badge" style="font-size:0.8rem; padding:0.3rem 0.7rem; background:rgba(37,211,102,0.12); color:#7bd8a6; border:1px solid rgba(37,211,102,0.3);">🟢 Publié</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Indicateur de participation (basé sur les présences validées) -->
                    <div class="dh-anim dh-anim-2" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.75rem; margin-bottom:1.4rem;">
                        <div class="glass-card" style="padding:1rem; text-align:center;">
                            <div class="dh-stat-valeur" style="font-size:1.8rem; font-weight:700; color:var(--accent); line-height:1.2;"><?php echo (int)$nbPartIndicateur; ?></div>
                            <div style="color:var(--text-muted); font-size:0.82rem;">👥 Participants</div>
                        </div>
                    </div>

                    <!-- Validation de présence (en haut) -->
                    <?php if ($membreId > 0 && $publie): ?>
                    <div class="glass-card dh-presence dh-anim dh-anim-3" style="margin-bottom:1.4rem; border:2px solid rgba(212,175,55,0.45);">
                        <h3 style="color:var(--white); margin-bottom:0.6rem;">✅ Validez votre présence</h3>
                        <?php if ($date <= date('Y-m-d')): ?>
                            <?php if ($presenceFaite): ?>
                                <div style="color:#7bd8a6; font-weight:700; margin-bottom:0.6rem;">✅ Présence confirmée — Jazakallahou Khair</div>
                                <button type="button" class="btn btn-secondary btn-sm" style="width:100%; text-align:center;" onclick="annulerPresence('dahira', <?php echo (int)$id; ?>, this)">↩️ Annuler ma présence</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-primary btn-sm" style="width:100%;" onclick="validerPresence('dahira', <?php echo (int)$id; ?>, this)">✅ J'étais présent(e)</button>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="color:var(--text-muted); font-size:0.85rem;">🔔 Vous pourrez valider votre présence le jour même.</div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($membreId > 0 && !$publie): ?>
                    <div class="dh-anim dh-anim-3" style="margin-bottom:1.4rem; color:var(--text-muted); font-size:0.85rem;">💤 Ce Dahira n'est pas encore publié : la validation de présence n'est pas disponible.</div>
                    <?php elseif ($membreId === 0): ?>
                    <div class="dh-anim dh-anim-3" style="margin-bottom:1.4rem; color:var(--text-muted); font-size:0.85rem;">🔐 <a href="login.php" style="color:var(--accent);">Connectez-vous</a> pour valider votre présence.</div>
                    <?php endif; ?>

                    <!-- Informations -->
                    <div class="dh-grid dh-anim dh-anim-4">
                        <div class="glass-card dh-item">
                            <div class="k">📍 Lieu</div>
                            <div class="v"><?php echo $lieu !== '' ? htmlspecialchars($lieu) : '—'; ?></div>
                        </div>
                        <div class="glass-card dh-item">
                            <div class="k">🕐 Horaires</div>
                            <div class="v"><?php echo htmlspecialchars($debut); ?> — <?php echo htmlspecialchars($fin); ?></div>
                        </div>
                        <div class="glass-card dh-item">
                            <div class="k">👥 Participants</div>
                            <div class="v"><?php echo $nbPartIndicateur > 0 ? (int)$nbPartIndicateur : '—'; ?></div>
                        </div>
                    </div>

                    <?php if ($programme !== ''): ?>
                    <!-- Programme -->
                    <div class="glass-card dh-anim dh-anim-5" style="margin-bottom:1.4rem;">
                        <h3 style="color:var(--white); margin-bottom:0.8rem;">🗓️ Programme</h3>
                        <pre style="white-space:pre-wrap; font-family:inherit; font-size:0.85rem; color:var(--white); background:rgba(255,255,255,0.03); border:1px solid var(--glass-border); border-radius:10px; padding:0.9rem;"><?php echo htmlspecialchars($programme); ?></pre>
                    </div>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div class="dh-anim dh-anim-5" style="display:flex; gap:0.6rem; flex-wrap:wrap;">
                        <a href="<?php echo $__dhAdmin ? 'admin_planning.php' : 'index.php'; ?>" class="btn btn-secondary btn-sm">← Retour</a>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        // Validation de présence (Dahira)
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
