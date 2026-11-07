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
    require_once __DIR__ . '/session_config.php'; session_start();
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

// Données partagées — heures propres à la séance si renseignées, sinon valeurs par défaut
$heure = trim((string)($seance['heure'] ?? '')) !== '' ? trim((string)$seance['heure']) : dahira_param($pdo, 'guddi_heure', '20h00');
$heureFin = trim((string)($seance['heure_fin'] ?? '')) !== '' ? trim((string)$seance['heure_fin']) : dahira_param($pdo, 'guddi_heure_fin', '');
$lieuDefaut = dahira_param($pdo, 'guddi_lieu_defaut', '');
$lieuDahira = $lieuDefaut !== '' ? $lieuDefaut : dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
$modeDefaut = dahira_param($pdo, 'guddi_mode_defaut', 'distance');

$date = $seance['date_guddi'];
$actif = ((int)($seance['actif'] ?? 1)) === 1;
$cloture = ((int)($seance['cloture'] ?? 0)) === 1;
$publie = ((int)($seance['publie'] ?? 0)) === 1;
$passe = $date < date('Y-m-d');
$mode = (string)($seance['mode'] ?? '');
if ($mode === '') { $mode = $modeDefaut; }
$theme = (string)($seance['theme'] ?? '');
$presentateur = (string)($seance['presentateur'] ?? '');
$livre = (string)($seance['livre'] ?? '');
$pdfPath = (string)($seance['pdf_path'] ?? '');
$nbParticipants = $seance['nb_participants'] ?? null;
// Statut « Modifié » : la séance a été mise à jour après sa création
$updatedAt = (string)($seance['updated_at'] ?? '');
$createdAt = (string)($seance['created_at'] ?? '');
$estModifie = ($updatedAt !== '' && $createdAt !== '' && $updatedAt > $createdAt) || ($updatedAt !== '' && $createdAt === '');
// Lien de participation (Zoom) : celui de la séance, sinon le lien par défaut des paramètres
$lienZoom = (string)($seance['lien'] ?? '');
if ($lienZoom === '') { $lienZoom = dahira_param($pdo, 'guddi_lien_defaut', ''); }
$lienZoom = trim($lienZoom);

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
            border-radius: 12px;
            padding: 1.6rem 1.6rem 1.4rem;
            background: linear-gradient(160deg, rgba(212,175,55,0.14) 0%, rgba(255,255,255,0.03) 100%);
            border: 2px solid rgba(212,175,55,0.5);
            margin-bottom: 1.4rem;
        }
        .gd-hero .gd-date { font-size: 1.9rem; font-weight: 800; color: var(--accent); line-height: 1.2; }
        .gd-hero .gd-date small { display: block; font-size: 0.85rem; font-weight: 400; color: var(--text-muted); margin-top: 0.2rem; }
        .gd-badges { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.8rem; }
        .gd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.9rem; margin-bottom: 1.4rem; }
        .gd-item { border-radius: 10px; padding: 1rem 1.1rem; }
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
        /* Centrage du contenu de la carte de présence sur mobile */
        @media (max-width: 480px) {
            .gd-presence { text-align: center; }
            .gd-presence .btn { width: 100%; }
        }
        /* ── Carte « Participer » + gros bouton Zoom ── */
        .gd-zoom-btn {
            display: flex; align-items: center; justify-content: center; gap: 0.9rem;
            width: 100%;
            background: linear-gradient(135deg, #2d8cff 0%, #1a63d0 100%);
            color: #fff;
            font-weight: 800;
            font-size: 1.25rem;
            letter-spacing: 0.01em;
            border: 0;
            border-radius: 12px;
            padding: 1.2rem 1.4rem;
            text-decoration: none;
            box-shadow: 0 14px 34px rgba(45,140,255,0.4), inset 0 1px 0 rgba(255,255,255,0.2);
            transition: transform 0.15s ease, box-shadow 0.2s ease, filter 0.2s ease;
            animation: gdZoomPulse 2.6s ease-in-out infinite;
        }
        @keyframes gdZoomPulse {
            0%, 100% { box-shadow: 0 14px 34px rgba(45,140,255,0.4), inset 0 1px 0 rgba(255,255,255,0.2); }
            50% { box-shadow: 0 18px 42px rgba(45,140,255,0.6), inset 0 1px 0 rgba(255,255,255,0.2); }
        }
        .gd-zoom-btn:hover { transform: translateY(-2px); filter: brightness(1.08); animation-play-state: paused; }
        .gd-zoom-btn:active { transform: translateY(1px) scale(0.99); }
        .gd-zoom-btn .zoom-ic {
            width: 48px; height: 48px; border-radius: 10px; flex-shrink: 0;
            background: rgba(255,255,255,0.22);
            display: inline-flex; align-items: center; justify-content: center;
        }
        .gd-zoom-btn .zoom-lbl { display: flex; flex-direction: column; align-items: flex-start; line-height: 1.2; }
        .gd-zoom-btn .zoom-lbl small { font-size: 0.78rem; font-weight: 600; opacity: 0.85; }
        .gd-zoom-hint { color: var(--text-muted); font-size: 0.8rem; margin-top: 0.6rem; text-align: center; }
        /* ── Badges de statut ── */
        .pl-badge { font-size: 0.7rem; padding: 0.15rem 0.45rem; border-radius: 6px; }
        .pl-badge-ok { background: rgba(37,211,102,0.15); color: #7bd8a6; border: 1px solid rgba(37,211,102,0.3); }
        .pl-badge-no { background: rgba(255,255,255,0.05); color: var(--text-muted); border: 1px solid var(--glass-border); }
        .pl-badge-annul { background: rgba(191,33,33,0.15); color: #fca5a5; border: 1px solid rgba(191,33,33,0.4); }
        .pl-badge-pub { background: rgba(45,140,255,0.15); color: #8ec5ff; border: 1px solid rgba(45,140,255,0.4); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__guAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>

            <div class="admin-content">
                <?php if ($__guAdmin): ?><a href="admin_guddi.php" class="gd-back btn btn-secondary btn-sm">← Retour au planning</a><?php endif; ?>
                <h1 class="admin-page-title">💎 Détail Guddi Àjjuma</h1>

                <?php if (!empty($success)): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:8px;margin-bottom:1rem;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:8px;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <div class="gd-wrap">
                    <!-- En-tête -->
                    <div class="gd-hero gd-anim gd-anim-1">
                        <div class="gd-date">
                            <?php echo ucfirst(guddi_jour_fr($date)) . ' ' . date('d/m/Y', strtotime($date)); ?>
                            <small>🕐 <?php echo trim($heureFin) !== '' ? 'de ' . htmlspecialchars($heure) . ' à ' . htmlspecialchars($heureFin) : 'à partir de ' . htmlspecialchars($heure); ?></small>
                        </div>
                        <div class="gd-badges">
                            <?php $etat = dahira_event_etat($date, $heure); ?>
                            <?php if (!$actif): ?>
                                <span class="pl-badge pl-badge-annul" style="font-size:0.8rem; padding:0.3rem 0.7rem;">‼️ Annulé</span>
                            <?php elseif ($cloture): ?>
                                <span class="pl-badge pl-badge-ok" style="font-size:0.8rem; padding:0.3rem 0.7rem;">✅ Clôturée<?php echo !empty($nbParticipants) ? ' · ' . (int)$nbParticipants . ' pers.' : ''; ?></span>
                            <?php elseif ($etat === 'termine'): ?>
                                <span class="pl-badge pl-badge-no" style="font-size:0.8rem; padding:0.3rem 0.7rem;">🕌 Terminée</span>
                            <?php elseif ($etat === 'encours'): ?>
                                <span class="pl-badge pl-badge-ok badge-live" style="font-size:0.8rem; padding:0.3rem 0.7rem;">En cours</span>
                            <?php else: ?>
                                <span class="pl-badge" style="font-size:0.8rem; padding:0.3rem 0.7rem; background:rgba(45,140,255,0.15); color:#8ec5ff; border:1px solid rgba(45,140,255,0.4);">🗓️ À venir</span>
                            <?php endif; ?>
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
                        <?php if ($presenceFaite): ?>
                            <div style="color:#7bd8a6; font-weight:700; margin-bottom:0.6rem;">✅ Présence confirmée — Diadieuf</div>
                            <button type="button" class="btn btn-secondary btn-sm" style="width:100%; text-align:center;" onclick="annulerPresence('guddi', <?php echo (int)$id; ?>, this)">↩️ Annuler ma présence</button>
                        <?php elseif (dahira_presence_ouverte($date, $heure)): ?>
                            <button type="button" class="btn btn-primary btn-sm" style="width:100%;" onclick="validerPresence('guddi', <?php echo (int)$id; ?>, this)">✅ J'étais présent(e)</button>
                        <?php else: ?>
                            <div style="color:var(--text-muted); font-size:0.85rem;">🔔 La validation sera possible à partir de <?php echo htmlspecialchars($heure); ?> le <?php echo date('d/m/Y', strtotime($date)); ?>.</div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($membreId > 0 && ((int)($seance['publie'] ?? 0)) !== 1): ?>
                    <div class="gd-anim gd-anim-3" style="margin-bottom:1.4rem; color:var(--text-muted); font-size:0.85rem;">💤 Ce Guddi Àjjuma n'est pas encore publié : la validation de présence n'est pas disponible.</div>
                    <?php elseif ($membreId === 0): ?>
                    <div class="gd-anim gd-anim-3" style="margin-bottom:1.4rem; color:var(--text-muted); font-size:0.85rem;">🔐 <a href="login.php" style="color:var(--accent);">Connectez-vous</a> pour valider votre présence.</div>
                    <?php endif; ?>

                    <!-- Participer : lien Zoom (mode distance) -->
                    <?php if ($mode === 'distance' && $lienZoom !== ''): ?>
                    <div class="glass-card gd-anim gd-anim-3" style="margin-bottom:1.4rem; border:2px solid rgba(45,140,255,0.5);">
                        <h3 style="color:var(--white); margin-bottom:0.8rem;"><?php echo $cloture ? '💻 Revoir / participer' : '💻 Participer à cette séance'; ?></h3>
                        <a class="gd-zoom-btn" href="<?php echo htmlspecialchars($lienZoom); ?>" target="_blank" rel="noopener">
                            <span class="zoom-ic"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" aria-hidden="true"><path d="M17.96 8.55V7.3c0-1.9-1.55-3.45-3.45-3.45H7.29c-1.9 0-3.45 1.55-3.45 3.45v9.4c0 1.9 1.55 3.45 3.45 3.45h7.22c1.9 0 3.45-1.55 3.45-3.45v-1.25l4.5 3.35v-14.7l-4.5 3.35Z" fill="#fff"/></svg></span>
                            <span class="zoom-lbl">Rejoindre en Zoom<small>Réunion à distance</small></span>
                        </a>
                        <div class="gd-zoom-hint"><?php echo $cloture ? 'La séance est clôturée — vous pouvez toujours rejoindre le lien de la réunion.' : '🕐 à partir de ' . htmlspecialchars($heure) . ' · le lien s\'ouvre dans un nouvel onglet'; ?></div>
                    </div>
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
                        <?php if ($mode === 'presentiel' && $lieuDahira !== ''): ?>
                        <div class="glass-card gd-item">
                            <div class="k">📍 Lieu</div>
                            <div class="v"><?php echo htmlspecialchars($lieuDahira); ?></div>
                        </div>
                        <?php endif; ?>
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
                            <div class="v">
                                <?php if ($pdfPath !== ''):
                                    // Le PDF s'ouvre dans la liseuse du site, comme le Coran :
                                    // pages, zoom et glissement au doigt, sans quitter Touba Lyon.
                                    $gdLiseuse = 'pdf_viewer.php?file=' . urlencode('uploads/' . $pdfPath)
                                        . ($livre !== '' ? '&titre=' . urlencode($livre) : '')
                                        . '&retour=' . urlencode('guddi_detail.php?id=' . $id); ?>
                                    <a href="<?php echo htmlspecialchars($gdLiseuse); ?>" class="btn btn-secondary btn-sm"
                                       style="border-color:var(--accent); color:var(--accent); font-size:0.78rem; padding:0.25rem 0.6rem;">📖 Lire le PDF</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php // Détails complémentaires (texte simple) saisis pour la séance ?>
                    <?php $detailsSafe = guddi_details_texte((string)($seance['details_html'] ?? '')); ?>
                    <?php if ($detailsSafe !== ''): ?>
                    <div class="glass-card gd-anim gd-anim-4" style="margin-bottom:1.4rem;">
                        <h3 style="color:var(--white); margin:0 0 0.7rem;">📝 Détails</h3>
                        <div style="color:var(--white); font-size:0.95rem; line-height:1.6; word-break:break-word;"><?php echo $detailsSafe; ?></div>
                    </div>
                    <?php endif; ?>

                    <?php // L'enregistrement de la séance, déposé à la clôture ?>
                    <?php $audioPath = trim((string)($seance['audio_path'] ?? '')); ?>
                    <?php if ($audioPath !== ''): ?>
                    <div class="glass-card gd-anim gd-anim-5" style="margin-bottom:1.4rem;">
                        <h3 style="color:var(--white); margin:0 0 0.7rem;">🎧 Enregistrement de la séance</h3>
                        <audio controls preload="none" src="uploads/<?php echo htmlspecialchars($audioPath); ?>" style="width:100%;"></audio>
                        <p style="margin:0.5rem 0 0; font-size:0.78rem; color:var(--text-muted);">
                            <a href="uploads/<?php echo htmlspecialchars($audioPath); ?>" download style="color:var(--accent);">⬇️ Télécharger l'enregistrement</a>
                        </p>
                    </div>
                    <?php endif; ?>

                    <!-- Membres présents (trombinoscope) -->
                    <?php $presMembres = dahira_presence_membres($pdo, 'guddi', $id); ?>
                    <div class="glass-card gd-anim gd-anim-5" style="margin-bottom:1.4rem;">
                        <h3 style="color:var(--white); margin:0 0 0.8rem;">👥 Membres présents (<?php echo count($presMembres); ?>)</h3>
                        <?php if (!empty($presMembres)): ?>
                            <?php echo dahira_presence_trombi_html($presMembres); ?>
                        <?php else: ?>
                            <p style="color:var(--text-muted); font-size:0.88rem; margin:0;">Aucune présence validée pour le moment.</p>
                        <?php endif; ?>
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
                    'Voulez-vous vraiment <strong>annuler votre présence</strong> au Guddi Àjjuma ?<br><br><span style="color:var(--text-muted); font-size:0.85rem;">Vous ne serez plus compté(e) comme présent(e) pour cet événement.</span>',
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
