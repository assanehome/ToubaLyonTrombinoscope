<?php
/**
 * Touba Lyon 2026 - Suivi d'une session de lecture du Coran (Khatm).
 * Réservé à l'admin et aux responsables de la commission « Culte ».
 * Tableau de bord, rappels (e-mail / WhatsApp), libération d'une partie.
 */
require_once __DIR__ . '/wird_guard.php';   // $__isAdmin, $__isCulteManager, $pdo
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/send_mail.php';
require_once __DIR__ . '/contact.php';       // wa_number()
require_once __DIR__ . '/dahira_emails.php';
require_once __DIR__ . '/notification_helper.php'; // cloche + Web Push
require_once __DIR__ . '/wird_period_helper.php';  // libellé de période

$error = '';
$success = '';

$sid = (int) ($_POST['session_id'] ?? $_GET['s'] ?? 0);
$session = null;
if ($sid > 0) {
    try { $st = $pdo->prepare("SELECT * FROM quran_sessions WHERE id = ?"); $st->execute([$sid]); $session = $st->fetch(); } catch (Exception $e) { $session = null; }
}
if (!$session) { header('Location: wird_admin.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'free_part') {
                $pid = (int) ($_POST['part_id'] ?? 0);
                if ($pid > 0) {
                    $pdo->prepare("UPDATE quran_parts SET statut='libre', membre_id=NULL, membre_nom=NULL, owner_token=NULL, reserved_at=NULL, validated_at=NULL WHERE id=? AND session_id=?")->execute([$pid, $sid]);
                    $success = "Partie libérée.";
                }
            } elseif ($action === 'remind_all') {
                // Rappel e-mail aux membres (connectés) ayant réservé mais pas validé.
                $q = $pdo->prepare("SELECT p.numero, m.prenom, m.nom, m.email FROM quran_parts p JOIN membres m ON m.id = p.membre_id WHERE p.session_id=? AND p.statut='reservee' AND m.email IS NOT NULL AND m.email <> ''");
                $q->execute([$sid]);
                $cible = $q->fetchAll();
                $sent = 0;
                foreach ($cible as $t) {
                    $inner = '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Rappel — lecture du Coran</h1>'
                        . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($t['prenom'] . ' ' . $t['nom']) . '</strong>,</p>'
                        . '<p style="margin:0 0 14px;">Vous avez réservé la <strong>partie ' . (int)$t['numero'] . ' (Juz ' . (int)$t['numero'] . ')</strong> de la lecture « ' . htmlspecialchars($session['titre']) . ' ». '
                        . 'Merci de la lire et de <strong>valider votre lecture</strong> dès que possible, afin de compléter le Khatm.</p>'
                        . '<p style="margin:0;">Jërëjëf pour votre engagement !</p>';
                    if (@send_smtp_mail($t['email'], $t['prenom'] . ' ' . $t['nom'], 'Rappel — lecture du Coran', dahira_email_wrap($inner, 'Lecture du Coran'))) { $sent++; }
                }
                $success = $sent . " rappel(s) e-mail envoyé(s).";
            } elseif ($action === 'set_launch' || $action === 'close_period') {
                // Contrôle des périodes de la série : lancer / annuler le lancement / clôturer
                $tid = (int) ($_POST['target_id'] ?? 0);
                // Autorisation : la période cible doit être la session courante ou appartenir à la même série
                $autorise = ($tid === $sid);
                if (!$autorise && $tid > 0) {
                    $chk = $pdo->prepare("SELECT serie_id FROM quran_sessions WHERE id = ?");
                    $chk->execute([$tid]);
                    $ts = $chk->fetchColumn();
                    $curSerie = (int) ($session['serie_id'] ?? 0);
                    $autorise = ($ts !== false && $curSerie > 0 && (int)$ts === $curSerie);
                }
                if (!$autorise || $tid <= 0) {
                    $error = "Période non autorisée.";
                } elseif ($action === 'set_launch') {
                    $val = ((int)($_POST['val'] ?? 0) === 1) ? 1 : 0;
                    if ($val === 1) {
                        // Interdire le lancement tant que la période précédente n'est pas clôturée
                        $blocked = false;
                        $srow = $pdo->prepare("SELECT serie_id, date_debut FROM quran_sessions WHERE id=?");
                        $srow->execute([$tid]);
                        $srow = $srow->fetch();
                        if ($srow && !empty($srow['serie_id']) && !empty($srow['date_debut'])) {
                            $pv = $pdo->prepare("SELECT statut FROM quran_sessions WHERE serie_id=? AND id<>? AND (date_debut < ? OR (date_debut = ? AND id < ?)) ORDER BY date_debut DESC, id DESC LIMIT 1");
                            $pv->execute([(int)$srow['serie_id'], $tid, $srow['date_debut'], $srow['date_debut'], $tid]);
                            $prevStatut = $pv->fetchColumn();
                            if ($prevStatut !== false && $prevStatut !== 'terminee') { $blocked = true; }
                        }
                        if ($blocked) {
                            $error = "Impossible de lancer cette période : la période précédente n'est pas encore clôturée.";
                        } else {
                            $pdo->prepare("UPDATE quran_sessions SET lance=1, statut='en_cours', closed_at=NULL WHERE id=?")->execute([$tid]);
                            $success = "Période lancée — désormais visible des membres.";
                            // Notifier tous les membres (cloche + Web Push)
                            try {
                                $ss = $pdo->prepare("SELECT titre, token, periodicite, serie_id, date_debut, date_fin FROM quran_sessions WHERE id=?");
                                $ss->execute([$tid]);
                                $ss = $ss->fetch();
                                if ($ss) {
                                    $perLbl = wird_period_label($pdo, ['id' => $tid] + $ss);
                                    $body = 'La lecture « ' . $ss['titre'] . ' » est ouverte' . ($perLbl !== '' ? ' — ' . $perLbl : '') . '. Choisissez votre Juz et lisez-le. Jërëjëf ! 🤲';
                                    $lnk = 'wird.php?s=' . (int)$tid . '&t=' . $ss['token'];
                                    $nNotif = troba_notify_all_membres($pdo, 'wird_lance', '📖 Lecture du Coran ouverte', $body, $lnk);
                                    $success .= ' ' . (int)$nNotif . ' membre(s) notifié(s).';
                                }
                            } catch (Throwable $e) { error_log('Touba Lyon wird_suivi notif lancement: ' . $e->getMessage()); }
                        }
                    } else {
                        $pdo->prepare("UPDATE quran_sessions SET lance=0 WHERE id=?")->execute([$tid]);
                        $success = "Lancement annulé — période masquée aux membres.";
                    }
                } else { // close_period
                    $launchNext = ((int)($_POST['launch_next'] ?? 0) === 1);
                    $pdo->prepare("UPDATE quran_sessions SET statut='terminee', closed_at=NOW() WHERE id=?")->execute([$tid]);
                    $msg = "Période clôturée.";
                    if ($launchNext) {
                        $curSerie = (int) ($session['serie_id'] ?? 0);
                        if ($curSerie > 0) {
                            $nx = $pdo->prepare("SELECT id FROM quran_sessions WHERE serie_id=? AND id<>? AND statut<>'terminee' AND lance=0 ORDER BY date_debut ASC, id ASC LIMIT 1");
                            $nx->execute([$curSerie, $tid]);
                            $nid = $nx->fetchColumn();
                            if ($nid) {
                                $pdo->prepare("UPDATE quran_sessions SET lance=1, statut='en_cours', closed_at=NULL WHERE id=?")->execute([(int)$nid]);
                                $msg .= " Période suivante lancée.";
                                // Notifier les membres de l'ouverture de la nouvelle période
                                try {
                                    $ss2 = $pdo->prepare("SELECT titre, token, periodicite, serie_id, date_debut, date_fin FROM quran_sessions WHERE id=?");
                                    $ss2->execute([(int)$nid]);
                                    $ss2 = $ss2->fetch();
                                    if ($ss2) {
                                        $perLbl2 = wird_period_label($pdo, ['id' => (int)$nid] + $ss2);
                                        $body2 = 'La lecture « ' . $ss2['titre'] . ' » est ouverte' . ($perLbl2 !== '' ? ' — ' . $perLbl2 : '') . '. Choisissez votre Juz et lisez-le. Jërëjëf ! 🤲';
                                        $lnk2 = 'wird.php?s=' . (int)$nid . '&t=' . $ss2['token'];
                                        $nN2 = troba_notify_all_membres($pdo, 'wird_lance', '📖 Lecture du Coran ouverte', $body2, $lnk2);
                                        $msg .= ' ' . (int)$nN2 . ' membre(s) notifié(s).';
                                    }
                                } catch (Throwable $e) { error_log('Touba Lyon wird_suivi notif suivante: ' . $e->getMessage()); }
                            } else {
                                $msg .= " Aucune période suivante à lancer.";
                            }
                        }
                    }
                    $success = $msg;
                }
                // Rafraîchir la session courante après modification
                try { $rs = $pdo->prepare("SELECT * FROM quran_sessions WHERE id=?"); $rs->execute([$sid]); $rr = $rs->fetch(); if ($rr) { $session = $rr; } } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            error_log('Touba Lyon wird_suivi: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
}

try {
    $st = $pdo->prepare("SELECT p.*, m.telephone AS m_tel, m.email AS m_email, m.photo_path AS m_photo FROM quran_parts p LEFT JOIN membres m ON m.id = p.membre_id WHERE p.session_id=? ORDER BY p.numero ASC");
    $st->execute([$sid]);
    $parts = $st->fetchAll();
} catch (Exception $e) { $parts = []; }
$nbLues = 0; $nbRes = 0; $participants = [];
foreach ($parts as $p) {
    if ($p['statut'] === 'lue') { $nbLues++; } elseif ($p['statut'] === 'reservee') { $nbRes++; }
    if ($p['statut'] !== 'libre') {
        $key = !empty($p['membre_id']) ? 'm' . (int)$p['membre_id'] : 'n' . mb_strtolower(trim($p['membre_nom'] ?? ''));
        $participants[$key] = true;
    }
}
$nbPart = count($participants);
$nbLibres = max(0, 30 - $nbLues - $nbRes);
$pct = count($parts) ? round($nbLues / count($parts) * 100) : 0;

// ── Calendrier de la série (sessions périodiques chaînées) ──
$serieId = (int) ($session['serie_id'] ?? 0);
$seriePeriods = [];
if ($serieId > 0) {
    try {
        $q = $pdo->prepare(
            "SELECT s.id, s.titre, s.token, s.statut, s.date_debut, s.date_fin, s.periodicite, s.lance,
                    SUM(p.statut='lue') AS lues, SUM(p.statut='reservee') AS res, COUNT(p.id) AS tot,
                    COUNT(DISTINCT CASE WHEN p.membre_id IS NOT NULL AND p.membre_id>0 THEN p.membre_id END) AS participants
             FROM quran_sessions s
             LEFT JOIN quran_parts p ON p.session_id = s.id
             WHERE s.serie_id = ?
             GROUP BY s.id, s.titre, s.token, s.statut, s.date_debut, s.date_fin, s.periodicite, s.lance
             ORDER BY s.date_debut ASC, s.id ASC"
        );
        $q->execute([$serieId]);
        $seriePeriods = $q->fetchAll();
    } catch (Exception $e) { $seriePeriods = []; }
}
// Existe-t-il une période planifiée non encore lancée (pour proposer « lancer la suivante ») ?
$hasNextToLaunch = false;
foreach ($seriePeriods as $z) { if ((int)$z['lance'] === 0 && $z['statut'] !== 'terminee') { $hasNextToLaunch = true; break; } }
// État de diffusion de la session affichée
$curLance   = ((int)($session['lance'] ?? 1) === 1);
$curEnCours = (($session['statut'] ?? '') === 'en_cours');
$curPeriodic = (($session['periodicite'] ?? 'ponctuelle') !== 'ponctuelle');

$baseUrl = rtrim(dahira_base_url(), '/');
$waGroup = '';
try { $waGroup = (string) $pdo->query("SELECT valeur FROM app_settings WHERE cle='wa_group_link'")->fetchColumn(); } catch (Exception $e) { $waGroup = ''; }
$sessLink = $baseUrl . '/wird.php?s=' . (int) $sid . '&t=' . $session['token'];
$pendingList = [];
$doneList = [];
foreach ($parts as $p) {
    if ($p['statut'] === 'reservee') { $pendingList[] = '• Juz ' . (int) $p['numero'] . ' : ' . ($p['membre_nom'] ?? '?'); }
    elseif ($p['statut'] === 'lue') { $doneList[] = '✅ Juz ' . (int) $p['numero'] . ' : ' . ($p['membre_nom'] ?? '?'); }
}
$waReminderMsg = "📖 " . $session['titre'] . "\n🕌 Avancement : " . $nbLues . "/30 lues · " . $nbRes . " réservées · " . $nbLibres . " libres (" . $pct . "%)\n\n"
    . (!empty($doneList) ? "*Déjà lus :*\n" . implode("\n", $doneList) . "\n\n" : "")
    . (!empty($pendingList) ? "*À lire et valider :*\n" . implode("\n", $pendingList) . "\n\n" : "")
    . "👉 Lien : " . $sessLink;

// ── Actions complètes (comme wird_admin) : message d'invitation + période suivante ──
$invPer = wird_period_label($pdo, $session);
$shareMsg = "Assalamu aleykum 🙏\n\n"
    . "📖 *Lecture collective du Coran (Khatm)*\n"
    . "« " . $session['titre'] . " »\n"
    . (!empty($session['description']) ? ($session['description'] . "\n") : "")
    . ($invPer !== '' ? ('🗓️ ' . $invPer . "\n") : "")
    . "\nChaque personne choisit un ou plusieurs Juz, le(s) lit, puis *valide sa lecture* pour compléter le Khatm ensemble.\n\n"
    . "👉 Choisissez votre Juz ici :\n" . $sessLink . "\n\n"
    . "Qu'Allah accepte nos lectures et nos invocations. 🤲\n"
    . "— Dahira Touba Lyon (Mubawwa-A-Sidqin)";
// Période suivante de la série (si périodique)
$nextPerId = null;
if (($session['periodicite'] ?? 'ponctuelle') !== 'ponctuelle') {
    $serieN = (int)($session['serie_id'] ?: $session['id']);
    try {
        if (!empty($session['date_debut'])) {
            $nq = $pdo->prepare("SELECT id FROM quran_sessions WHERE serie_id=? AND (date_debut > ? OR (date_debut = ? AND id > ?)) ORDER BY date_debut ASC, id ASC LIMIT 1");
            $nq->execute([$serieN, $session['date_debut'], $session['date_debut'], (int)$session['id']]);
        } else {
            $nq = $pdo->prepare("SELECT id FROM quran_sessions WHERE serie_id=? AND id > ? ORDER BY id ASC LIMIT 1");
            $nq->execute([$serieN, (int)$session['id']]);
        }
        $nextPerId = $nq->fetchColumn();
    } catch (Exception $e) { $nextPerId = null; }
}
$unJuzSess = ((int)($session['un_juz_par_membre'] ?? 0) === 1);

function wsuivi_badge($s)
{
    if ($s === 'lue') { return '<span class="badge" style="background:rgba(45,106,79,0.25); color:#7bd8a6; border:1px solid rgba(45,106,79,0.5);">✓ Lue</span>'; }
    if ($s === 'reservee') { return '<span class="badge" style="background:rgba(212,175,55,0.16); color:#ffd873; border:1px solid rgba(212,175,55,0.45);">Réservée</span>'; }
    return '<span class="badge" style="background:rgba(255,255,255,0.05); color:var(--text-muted);">Libre</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi — <?php echo htmlspecialchars($session['titre']); ?> - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .ws-wrap { max-width: 900px; margin: 2rem auto; }
        .ws-prog { height: 10px; border-radius: 50px; background: rgba(255,255,255,0.08); overflow: hidden; margin: 0.4rem 0 0.6rem; }
        .ws-prog > i { display: block; height: 100%; background: linear-gradient(90deg,#2d6a4f,#7bd8a6); }
        .ws-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
        .ws-stat { border-radius: 14px; padding: 0.85rem 1rem; text-align: center; }
        .ws-stat .n { font-size: 1.5rem; font-weight: 800; color: var(--white); line-height: 1.1; }
        .ws-stat .l { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; margin-top: 0.2rem; }
        /* Liste des participants en cartes (divs, plus de tableau) */
        .ws-list { display: flex; flex-direction: column; gap: 0.5rem; }
        .ws-row { display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap; border: 1px solid var(--glass-border); border-radius: 12px; padding: 0.55rem 0.9rem; background: rgba(255,255,255,0.02); }
        .ws-row-juz { font-weight: 800; color: var(--gold); min-width: 54px; }
        .ws-row-part { flex: 1 1 150px; min-width: 130px; }
        .ws-nom { color: #fff; font-weight: 600; }
        .ws-row-stat { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .ws-dates { color: var(--text-muted); font-size: 0.78rem; }
        .ws-row-act { margin-left: auto; display: flex; gap: 0.35rem; flex-wrap: wrap; }
        .ws-row-act .btn { padding: 0.3rem 0.6rem; font-size: 0.74rem; }
        /* Avatar du participant (aligné comme sur wird) */
        .ws-part { display: inline-flex; align-items: center; gap: 0.5rem; }
        .ws-ava { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 50%; overflow: hidden; flex-shrink: 0; border: 2px solid var(--accent); background: var(--primary); box-shadow: 0 2px 6px rgba(0,0,0,0.3); }
        .ws-ava img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ws-ava-ini { font-size: 0.85rem; font-weight: 800; color: var(--gold); text-transform: uppercase; }
        @media (max-width: 600px){
            .ws-row { flex-direction: column; align-items: center; text-align: center; gap: 0.45rem; padding: 0.9rem; }
            .ws-row-juz { font-size: 1rem; }
            .ws-row-part { flex: none; width: 100%; }
            .ws-part { flex-direction: column; gap: 0.3rem; }
            .ws-ava { width: 64px; height: 64px; }
            .ws-ava-ini { font-size: 1.5rem; }
            /* Un Juz lu porte deux dates, un Juz réservé une seule : à l'horizontale
               la carte lue s'élargit et les cartes ne s'alignent plus. En colonne,
               toutes ont la même largeur. */
            .ws-row-stat { justify-content: center; flex-direction: column; width: 100%; gap: 0.3rem; }
            .ws-dates { text-align: center; }
            .ws-row-act { margin-left: 0; width: 100%; flex-direction: column; gap: 0.4rem; }
            .ws-row-act form, .ws-row-act .btn { width: 100%; }
            /* En-tête du suivi : centré, informations sur plusieurs lignes, pleine largeur */
            .ws-head { flex-direction: column; text-align: center; justify-content: center; padding: 1rem 1rem; }
            .ws-head .ws-head-info { width: 100%; }
            .ws-head .ws-head-per { display: block; margin-top: 0.25rem; }
            .ws-head .btn { width: 100%; }
            /* Indicateurs : 2 par ligne, centrés, pleine largeur */
            .ws-stats { grid-template-columns: repeat(2, 1fr); }
            /* Barre Diffusion : centrée, actions empilées pleine largeur */
            .ws-diffusion { flex-direction: column; align-items: stretch; text-align: center; }
            .ws-diffusion > div { margin-left: 0 !important; width: 100%; flex-direction: column; }
            .ws-diffusion form, .ws-diffusion .btn { width: 100%; justify-content: center; }
            /* Rappels : boutons pleine largeur */
            .ws-remind { flex-direction: column; align-items: stretch; }
            .ws-remind form, .ws-remind .btn { width: 100%; justify-content: center; }
            /* Calendrier de la série : liens pleine largeur */
            .ws-cal-links { flex-direction: column; }
            .ws-cal-links .btn { width: 100%; justify-content: center; }
            /* Barre d'actions complète : boutons pleine largeur, centrés */
            .ws-actionsbar { flex-direction: column; align-items: stretch; }
            .ws-actionsbar form, .ws-actionsbar .btn { width: 100%; justify-content: center; }
        }
        /* Calendrier de la série (périodes) */
        .ws-cal { display: flex; flex-direction: column; gap: 0.6rem; }
        .ws-cal-row { border: 1px solid var(--glass-border); border-radius: 12px; padding: 0.7rem 0.9rem; background: rgba(255,255,255,0.03); }
        .ws-cal-row.current { border-color: rgba(212,175,55,0.6); background: rgba(212,175,55,0.07); }
        .ws-cal-head { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .ws-cal-week { font-weight: 800; color: var(--white); font-size: 0.95rem; }
        .ws-cal-dates { color: #7dd3fc; font-size: 0.82rem; font-weight: 600; }
        .ws-cal-foot { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; color: var(--text-muted); font-size: 0.82rem; }
        .ws-cal-links { display: flex; gap: 0.35rem; flex-wrap: wrap; }
        .ws-cal-links .btn { padding: 0.3rem 0.6rem; font-size: 0.74rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">

        <div class="ws-wrap">
            <div class="admin-welcome-banner glass-card ws-head" style="margin-bottom:1.25rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <?php require_once __DIR__ . '/wird_period_helper.php'; $wsPeriode = wird_period_label($pdo, $session); ?>
                <span class="ws-head-info">📊 Suivi — <strong class="gold-text"><?php echo htmlspecialchars($session['titre']); ?></strong><?php if ($wsPeriode !== ''): ?> <span class="ws-head-per" style="color:#7dd3fc; font-weight:600; font-size:0.9rem;">🗓️ <?php echo htmlspecialchars($wsPeriode); ?></span><?php endif; ?></span>
                <a href="wird_admin.php" class="btn btn-secondary btn-sm">← Sessions</a>
            </div>

            <!-- Indicateurs de suivi (en haut) -->
            <div class="ws-stats">
                <div class="glass-card ws-stat"><div class="n" style="color:#7bd8a6;"><?php echo $nbLues; ?></div><div class="l">Lues</div></div>
                <div class="glass-card ws-stat"><div class="n" style="color:#ffd873;"><?php echo $nbRes; ?></div><div class="l">Réservées</div></div>
                <div class="glass-card ws-stat"><div class="n"><?php echo $nbLibres; ?></div><div class="l">Libres</div></div>
                <div class="glass-card ws-stat"><div class="n"><?php echo $nbPart; ?></div><div class="l">Participants</div></div>
                <div class="glass-card ws-stat"><div class="n" style="font-size:1.25rem;"><?php echo $pct; ?>%</div><div class="l">Avancement</div></div>
                <div class="glass-card ws-stat"><div class="n" style="font-size:1.15rem;"><?php echo htmlspecialchars(wird_duree_jh($session['created_at'] ?? null, $session['closed_at'] ?? null)); ?></div><div class="l">Durée</div></div>
            </div>

            <!-- Diffusion : lancer / annuler le lancement / clôturer -->
            <div class="glass-card ws-diffusion" style="border-radius:16px; padding:1rem 1.25rem; margin-bottom:1.25rem; display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                <span style="color:#fff; font-weight:600;">Diffusion :</span>
                <?php if ($curLance): ?>
                    <span class="badge" style="background:rgba(45,106,79,0.25); color:#7bd8a6; border:1px solid rgba(45,106,79,0.5);">🚀 Lancée — visible des membres</span>
                <?php else: ?>
                    <span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-muted);">⏳ En attente — masquée aux membres</span>
                <?php endif; ?>
                <div style="margin-left:auto; display:flex; gap:0.4rem; flex-wrap:wrap;">
                    <?php if ($curLance): ?>
                        <a href="https://wa.me/?text=<?php echo rawurlencode($shareMsg); ?>" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 WhatsApp (invitation)</a>
                        <?php if ($waGroup !== ''): ?>
                        <button type="button" class="btn btn-sm" style="background:#128C7E; border:1px solid #128C7E; color:#fff; font-weight:700;" onclick="shareGroup(<?php echo htmlspecialchars(json_encode($shareMsg), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($waGroup), ENT_QUOTES); ?>)">🟢 Groupe (copier)</button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$curLance): ?>
                        <form action="wird_suivi.php" method="POST" style="margin:0;" data-confirm="Lancer cette session ? Elle deviendra visible des membres.">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="set_launch">
                            <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                            <input type="hidden" name="target_id" value="<?php echo $sid; ?>">
                            <input type="hidden" name="val" value="1">
                            <button type="submit" class="btn btn-primary btn-sm">🚀 Lancer</button>
                        </form>
                    <?php elseif ($curEnCours): ?>
                        <form action="wird_suivi.php" method="POST" style="margin:0;" data-confirm="Annuler le lancement ? La session sera masquée aux membres.">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="set_launch">
                            <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                            <input type="hidden" name="target_id" value="<?php echo $sid; ?>">
                            <input type="hidden" name="val" value="0">
                            <button type="submit" class="btn btn-danger btn-sm">Annuler le lancement</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass-card" style="border-radius:16px; padding:1.25rem 1.5rem; margin-bottom:1.25rem;">
                <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
                    <strong style="color:#fff;"><?php echo $nbLues; ?>/30 lues</strong>
                    <span style="color:var(--text-muted); font-size:0.9rem;"><?php echo $nbRes; ?> réservées · <?php echo 30 - $nbLues - $nbRes; ?> libres · <?php echo $pct; ?>%</span>
                </div>
                <div class="ws-prog"><i style="width:<?php echo $pct; ?>%;"></i></div>
                <div class="ws-remind" style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.85rem;">
                    <?php if ($curLance && $nbRes > 0): ?>
                    <form action="wird_suivi.php" method="POST" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="remind_all">
                        <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                        <button type="submit" class="btn btn-primary btn-sm">✉️ Rappel e-mail aux réservataires</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($curLance): ?>
                    <a href="https://wa.me/?text=<?php echo rawurlencode($waReminderMsg); ?>" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 Rappel WhatsApp</a>
                    <?php endif; ?>
                    <?php if ($curLance && $nbRes > 0): ?>
                    <form action="wird_admin.php" method="POST" style="margin:0;" data-confirm="Envoyer une notification (cloche + e-mail) aux membres ayant des Juz réservés non validés ?">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="notify_to_validate">
                        <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                        <button type="submit" class="btn btn-sm" style="background:#128C7E; border:1px solid #128C7E; color:#fff; font-weight:700;">🔔 Notifier Juz à valider</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($curLance && $waGroup !== ''): ?>
                    <button type="button" class="btn btn-sm" style="background:#128C7E; border:1px solid #128C7E; color:#fff; font-weight:700;" onclick="shareGroup(<?php echo htmlspecialchars(json_encode($waReminderMsg), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($waGroup), ENT_QUOTES); ?>)">🟢 Groupe (copier)</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass-card" style="border-radius:16px; padding:1.25rem 1.5rem;">
                <div class="ws-list">
                    <?php foreach ($parts as $p): ?>
                        <?php $wa = !empty($p['m_tel']) ? wa_number($p['m_tel']) : ''; ?>
                        <div class="ws-row">
                            <div class="ws-row-juz">Juz <?php echo (int)$p['numero']; ?></div>
                            <div class="ws-row-part">
                                <?php if (!empty($p['membre_nom'])): ?>
                                    <span class="ws-part">
                                        <span class="ws-ava"><?php
                                            $mph = trim((string)($p['m_photo'] ?? ''));
                                            if ($mph !== '') { echo '<img src="uploads/' . htmlspecialchars($mph) . '" alt="" loading="lazy">'; }
                                            else { echo '<span class="ws-ava-ini">' . htmlspecialchars(mb_strtoupper(mb_substr(trim((string)$p['membre_nom']), 0, 1))) . '</span>'; }
                                        ?></span>
                                        <span class="ws-nom"><?php echo htmlspecialchars($p['membre_nom']); ?></span>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">— libre</span>
                                <?php endif; ?>
                            </div>
                            <div class="ws-row-stat">
                                <?php echo wsuivi_badge($p['statut']); ?>
                                <?php if ($p['reserved_at'] || $p['validated_at']): ?>
                                    <span class="ws-dates"><?php
                                        $d = [];
                                        if ($p['reserved_at']) { $d[] = 'Rés. ' . date('d/m H:i', strtotime($p['reserved_at'])); }
                                        if ($p['validated_at']) { $d[] = 'Lu ' . date('d/m H:i', strtotime($p['validated_at'])); }
                                        echo htmlspecialchars(implode(' · ', $d));
                                    ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($p['statut'] !== 'libre'): ?>
                            <div class="ws-row-act">
                                <?php if ($p['statut'] === 'reservee' && $wa !== ''): ?>
                                    <a href="https://wa.me/<?php echo $wa; ?>?text=<?php echo rawurlencode('Assalamu aleykum, rappel pour la lecture du Juz ' . (int)$p['numero'] . ' (' . $session['titre'] . '). Merci de valider ta lecture.'); ?>" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 WhatsApp</a>
                                <?php endif; ?>
                                <form action="wird_suivi.php" method="POST" style="margin:0;" data-confirm="Libérer cette partie ?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="free_part">
                                    <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                                    <input type="hidden" name="part_id" value="<?php echo (int)$p['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">Libérer</button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($seriePeriods) && count($seriePeriods) > 1): ?>
            <div class="glass-card" style="border-radius:16px; padding:1.25rem 1.5rem; margin-top:1.25rem;">
                <strong style="color:var(--gold); font-size:1.05rem; display:block; margin-bottom:0.9rem;">🗓️ Calendrier de la série — <?php echo count($seriePeriods); ?> période(s)</strong>
                <div class="ws-cal">
                <?php $ci = 0; foreach ($seriePeriods as $sp): $ci++;
                    $spTot = max(1, (int)$sp['tot']); $spLues = (int)$sp['lues']; $spRes = (int)$sp['res'];
                    $spPct = round($spLues / $spTot * 100);
                    $isCur = ((int)$sp['id'] === $sid);
                    $spEnCours = ($sp['statut'] === 'en_cours');
                    $spLance = ((int)$sp['lance'] === 1);
                    $dr = !empty($sp['date_debut']) ? ('du ' . date('d/m/Y', strtotime($sp['date_debut'])) . (!empty($sp['date_fin']) ? ' au ' . date('d/m/Y', strtotime($sp['date_fin'])) : '')) : '';
                ?>
                    <div class="ws-cal-row<?php echo $isCur ? ' current' : ''; ?>">
                        <div class="ws-cal-head">
                            <span class="ws-cal-week">Période <?php echo $ci; ?></span>
                            <?php if ($dr !== ''): ?><span class="ws-cal-dates">🗓️ <?php echo htmlspecialchars($dr); ?></span><?php endif; ?>
                            <span class="badge <?php echo $spEnCours ? 'badge-approved' : 'badge-pending'; ?>"><?php echo $spEnCours ? 'En cours' : 'Clôturée'; ?></span>
                            <?php if ($spEnCours): ?>
                                <span class="badge" style="<?php echo $spLance ? 'background:rgba(45,106,79,0.25); color:#7bd8a6; border:1px solid rgba(45,106,79,0.5);' : 'background:rgba(255,255,255,0.06); color:var(--text-muted);'; ?>"><?php echo $spLance ? '🚀 Lancée' : '⏳ En attente'; ?></span>
                            <?php endif; ?>
                            <?php if ($isCur): ?><span class="badge" style="background:rgba(212,175,55,0.2); color:#ffd873; border:1px solid rgba(212,175,55,0.5);">Affichée</span><?php endif; ?>
                        </div>
                        <div class="ws-prog" style="margin:0.5rem 0 0.35rem;"><i style="width:<?php echo $spPct; ?>%;"></i></div>
                        <div class="ws-cal-foot">
                            <span><strong style="color:#7bd8a6;"><?php echo $spLues; ?></strong>/30 lues · <?php echo $spRes; ?> réservées · <?php echo (int)$sp['participants']; ?> participant(s) · <strong><?php echo $spPct; ?>%</strong></span>
                            <span class="ws-cal-links">
                                <?php if (!$spLance && $sp['statut'] !== 'terminee'): ?>
                                    <form action="wird_suivi.php" method="POST" style="margin:0;" data-confirm="Lancer la période <?php echo $ci; ?> ? Elle deviendra visible des membres.">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="set_launch">
                                        <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                                        <input type="hidden" name="target_id" value="<?php echo (int)$sp['id']; ?>">
                                        <input type="hidden" name="val" value="1">
                                        <button type="submit" class="btn btn-primary btn-sm">🚀 Lancer</button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!$isCur): ?><a href="wird_suivi.php?s=<?php echo (int)$sp['id']; ?>" class="btn btn-secondary btn-sm">📊 Suivi</a><?php endif; ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php // Les actions de la session ferment la page : on lit d'abord les Juz,
                  // et la clôture se trouve auprès des autres décisions. ?>
            <div class="glass-card ws-actionsbar" style="border-radius:16px; padding:1.1rem 1.25rem; margin-top:1.25rem; display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                <span style="color:var(--gold); font-weight:700; width:100%; margin-bottom:0.15rem;">Actions de la session</span>
                <?php if ($nextPerId): ?>
                    <a href="wird_suivi.php?s=<?php echo (int)$nextPerId; ?>" class="btn btn-sm" style="background:rgba(125,211,252,0.15); border:1px solid rgba(125,211,252,0.5); color:#7dd3fc; font-weight:700;">🔁 Période suivante</a>
                <?php endif; ?>
                <?php if ($curEnCours): ?>
                    <form action="wird_admin.php" method="POST" style="margin:0;" data-confirm="<?php echo $unJuzSess ? 'Autoriser plusieurs Juz par membre pour cette lecture ?' : 'Limiter à un seul Juz par membre pour cette lecture ?'; ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="set_juz_mode">
                        <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                        <input type="hidden" name="val" value="<?php echo $unJuzSess ? 0 : 1; ?>">
                        <button type="submit" class="btn btn-secondary btn-sm"><?php echo $unJuzSess ? 'Autoriser plusieurs Juz' : 'Limiter à 1 Juz/membre'; ?></button>
                    </form>
                <?php else: ?>
                    <form action="wird_admin.php" method="POST" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="reopen_session">
                        <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">↩️ Rouvrir</button>
                    </form>
                <?php endif; ?>
                <?php if ($curEnCours): ?>
                    <form id="close-only-form" action="wird_suivi.php" method="POST" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="close_period">
                        <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                        <input type="hidden" name="target_id" value="<?php echo $sid; ?>">
                        <input type="hidden" name="launch_next" value="0">
                    </form>
                    <form id="close-next-form" action="wird_suivi.php" method="POST" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="close_period">
                        <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                        <input type="hidden" name="target_id" value="<?php echo $sid; ?>">
                        <input type="hidden" name="launch_next" value="1">
                    </form>
                    <?php if ($curPeriodic && $hasNextToLaunch): ?>
                        <button type="button" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);" onclick="document.getElementById('close-modal').style.display='flex';">Clôturer</button>
                    <?php else: ?>
                        <form action="wird_suivi.php" method="POST" style="margin:0;" data-confirm="Clôturer cette période ? Elle ne sera plus modifiable.">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="close_period">
                            <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                            <input type="hidden" name="target_id" value="<?php echo $sid; ?>">
                            <input type="hidden" name="launch_next" value="0">
                            <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">Clôturer</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
                <form action="wird_admin.php" method="POST" style="margin:0;" data-confirm="Supprimer définitivement cette session et tout son suivi ? Cette action est irréversible.">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_session">
                    <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                    <button type="submit" class="btn btn-danger btn-sm">🗑️ Supprimer</button>
                </form>
            </div>
        </div>

            </div>
        </div>
    </main>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header"><?php if (!empty($success)): ?><h3 class="gold-text">Réussi</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?></div>
            <div class="modal-body"><p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal de clôture avec option « lancer la période suivante » -->
    <div id="close-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card">
            <div class="modal-header"><h3 class="gold-text">Clôturer la période</h3></div>
            <div class="modal-body"><p style="color:var(--text-muted);">Cette période sera clôturée (non modifiable). Voulez-vous <strong>lancer la période suivante</strong> pour les membres ?</p></div>
            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem; flex-wrap:wrap;">
                <button type="button" onclick="document.getElementById('close-modal').style.display='none';" class="btn btn-secondary btn-sm">Annuler</button>
                <button type="button" onclick="document.getElementById('close-only-form').submit();" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">Clôturer seulement</button>
                <button type="button" onclick="document.getElementById('close-next-form').submit();" class="btn btn-primary btn-sm">Clôturer + lancer la suivante</button>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/modern_popup.php'; ?>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <script>
        function shareGroup(msg, link){
            try { navigator.clipboard.writeText(msg); } catch (e) {}
            window.location.href = link;
        }
    </script>
</body>
</html>
