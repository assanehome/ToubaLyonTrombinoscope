<?php
/**
 * Touba Lyon 2026 - Dahira - Mubawwa-A-Sidqin Homepage
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';
require_once __DIR__ . '/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not authenticated as a member (player)
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/player_approved_guard.php';

if (isset($_SESSION['player_id'])) {
    try {
        $stmtScore = $pdo->prepare("SELECT score FROM membres WHERE id = ?");
        $stmtScore->execute([$_SESSION['player_id']]);
        $pScore = $stmtScore->fetchColumn();
        if ($pScore !== false) {
            $_SESSION['player_score'] = $pScore;
        }
    } catch (Exception $e) {
        // Silent catch
    }
}

// Lecture du Coran : Juz réservés (non validés) dans une session en cours
$myWirdParts = [];
$myWirdSession = null;
if (isset($_SESSION['player_id'])) {
    try {
        $qw = $pdo->prepare("SELECT p.id, p.numero, p.session_id, s.id AS sid, s.titre, s.token FROM quran_parts p JOIN quran_sessions s ON s.id = p.session_id WHERE p.membre_id = ? AND p.statut = 'reservee' AND s.statut = 'en_cours' ORDER BY p.numero ASC");
        $qw->execute([(int) $_SESSION['player_id']]);
        $myWirdParts = $qw->fetchAll();
        if (!empty($myWirdParts)) {
            $myWirdSession = ['sid' => $myWirdParts[0]['sid'], 'titre' => $myWirdParts[0]['titre'], 'token' => $myWirdParts[0]['token']];
        }
    } catch (Exception $e) {
        $myWirdParts = [];
    }
}
// Sessions de lecture en cours (avec des Juz encore libres)
$wirdSessions = [];
if (isset($_SESSION['player_id'])) {
    try {
        $wirdSessions = $pdo->query(
            "SELECT s.id, s.titre, s.token, SUM(p.statut='lue') AS lues, SUM(p.statut='libre') AS libres
             FROM quran_sessions s JOIN quran_parts p ON p.session_id = s.id
             WHERE s.statut = 'en_cours' GROUP BY s.id, s.titre, s.token HAVING libres > 0 ORDER BY s.created_at DESC"
        )->fetchAll();
    } catch (Exception $e) {
        $wirdSessions = [];
    }
}

// Prochain Guddi Àjjuma (séance publiée, à venir ou du jour) — affiché sur l'accueil membre
require_once __DIR__ . '/planning_guddi_helper.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // pour dahira_param()
$prochainGuddi = null;
$guddiHeure = '20h00';
$guddiPresenceFaite = false;
try {
    $stG = $pdo->query("SELECT * FROM guddi_plannings WHERE publie = 1 AND date_guddi >= CURDATE() AND actif = 1 ORDER BY date_guddi ASC LIMIT 1");
    $prochainGuddi = $stG->fetch();
    $guddiHeure = dahira_param($pdo, 'guddi_heure', '20h00');
    if ($prochainGuddi && isset($_SESSION['player_id'])) {
        $stP = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'guddi' AND planning_id = ? AND membre_id = ?");
        $stP->execute([(int)$prochainGuddi['id'], (int)$_SESSION['player_id']]);
        $guddiPresenceFaite = ((int)$stP->fetchColumn()) > 0;
    }
} catch (Exception $e) {
    $prochainGuddi = null;
}

// Prochain Dahira publié (validation de présence)
$prochainDahira = null;
$dahiraHeure = '';
$dahiraPresenceFaite = false;
try {
    $stD = $pdo->query("SELECT * FROM dahira_plannings WHERE publie = 1 AND date_dahira >= CURDATE() AND a_dahira = 1 ORDER BY date_dahira ASC LIMIT 1");
    $prochainDahira = $stD->fetch();
    $dahiraHeure = dahira_param($pdo, 'dahira_debut', '17h00');
    $dahiraFin = dahira_param($pdo, 'dahira_fin', '20h30');
    $dahiraLieu = dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
    if ($prochainDahira && isset($_SESSION['player_id'])) {
        $stP = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'dahira' AND planning_id = ? AND membre_id = ?");
        $stP->execute([(int)$prochainDahira['id'], (int)$_SESSION['player_id']]);
        $dahiraPresenceFaite = ((int)$stP->fetchColumn()) > 0;
    }
} catch (Exception $e) {
    $prochainDahira = null;
}

try {
    // Retrieve only approved members qui ont une photo (exclut les adhésions Dahira sans photo)
    $stmt = $pdo->query("SELECT * FROM membres WHERE status = 'approved' AND photo_path != '' ORDER BY prenom ASC, nom ASC");
    $members = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Touba Lyon index: ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dahira - Mubawwa-A-Sidqin - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .filter-container {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }
        /* ── w-tovalidate-idx : carte moderne "Juz à valider" (couleurs du site) ── */
        .w-tovalidate-idx {
            position: relative;
            max-width: 820px;
            margin: 0 auto 1.5rem;
            padding: 1.5rem 1.5rem 1.25rem;
            border-radius: 22px;
            background: linear-gradient(160deg, rgba(27,67,50,0.6), rgba(8,28,21,0.8));
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(212,175,55,0.32);
            box-shadow:
                0 18px 45px rgba(0,0,0,0.4),
                inset 0 1px 0 rgba(255,255,255,0.06);
            overflow: hidden;
            color: #ffd873;
        }
        /* Liseré doré animé en haut de la carte */
        .w-tovalidate-idx::before {
            content: '';
            position: absolute;
            top: 0; left: -20%; right: -20%;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,0.9), rgba(241,210,121,0.95), transparent);
            background-size: 200% 100%;
            animation: wtvFlowIdxs 4.5s linear infinite;
        }
        @keyframes wtvFlowIdxs { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .wtv-idx-top { display: flex; align-items: center; gap: 0.9rem; flex-wrap: wrap; }
        .wtv-idx-icon {
            width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 1.4rem;
            background: linear-gradient(135deg, rgba(212,175,55,0.32), rgba(212,175,55,0.08));
            border: 1px solid rgba(212,175,55,0.4);
            box-shadow: 0 6px 18px rgba(212,175,55,0.2);
        }
        .wtv-idx-titles { display: flex; flex-direction: column; gap: 0.1rem; flex: 1; min-width: 170px; }
        .wtv-idx-title { font-size: 1.05rem; font-weight: 800; color: #fff; letter-spacing: -0.01em; }
        .wtv-idx-session { font-size: 0.82rem; color: #f1d279; font-weight: 600; }
        .wtv-idx-cta { margin-left: auto; white-space: nowrap; }
        .wtv-idx-count { font-size: 0.78rem; font-weight: 800; color: #0c241a; background: linear-gradient(135deg, #d4af37, #f1d279); border-radius: 50px; padding: 4px 13px; box-shadow: 0 4px 12px rgba(212,175,55,0.35); margin-left: auto; }
        .wtv-idx-list { display: flex; flex-wrap: wrap; gap: 0.55rem; margin-top: 1.1rem; }
        .wtv-item-idx {
            display: inline-flex; align-items: center; gap: 0.45rem;
            background: rgba(212,175,55,0.09);
            border: 1px solid rgba(212,175,55,0.24);
            border-radius: 12px;
            padding: 5px 6px;
            animation: wtvChipIdx 0.45s cubic-bezier(0.22,1,0.36,1) both;
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }
        .wtv-item-idx:hover { transform: translateY(-2px); border-color: rgba(212,175,55,0.55); box-shadow: 0 8px 20px rgba(0,0,0,0.3); }
        @keyframes wtvChipIdx { from { opacity: 0; transform: translateY(10px) scale(0.94); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .wtv-item-idx .wtv-num { font-weight: 800; font-size: 0.88rem; color: #f1d279; padding: 4px 6px 4px 12px; white-space: nowrap; cursor: pointer; border-radius: 8px; transition: background 0.2s; }
        .wtv-item-idx .wtv-num:hover { background: rgba(212,175,55,0.14); }
        .wtv-item-idx .wtv-btn {
            background: linear-gradient(135deg, #d4af37, #e9c766); color: #0c241a; border: 0; border-radius: 9px;
            font-weight: 800; font-size: 0.82rem; padding: 7px 13px; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
            transition: transform 0.12s ease, box-shadow 0.2s ease, filter 0.2s ease;
            box-shadow: 0 4px 0 rgba(133,105,18,0.9);
        }
        .wtv-item-idx .wtv-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 rgba(133,105,18,0.9); }
        .wtv-item-idx .wtv-btn:hover { filter: brightness(1.12); }
        .wtv-item-idx .wtv-btn--pdf { background: rgba(212,175,55,0.14); color: #f1d279; border: 1px solid rgba(212,175,55,0.3); box-shadow: none; }
        .wtv-item-idx .wtv-btn--pdf:hover { background: rgba(212,175,55,0.26); color: #fff; box-shadow: none; }
        .wtv-idx-hint { margin-top: 0.85rem; font-size: 0.78rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.4rem; }
        .wtv-idx-hint::before { content: '💡'; }
        .filter-btn {
            background: rgba(255, 255, 255, 0.05);
            color: var(--white);
            border: 1px solid var(--glass-border);
            padding: 0.6rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .filter-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--gold);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.15);
        }
        .filter-btn.active {
            background: var(--accent);
            color: var(--secondary);
            border-color: var(--accent);
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
        }
        /* ── Prochaines rencontres : animations comme le suivi Juz ── */
        .renc-card {
            position: relative;
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1rem 1.1rem;
            background: rgba(255,255,255,0.03);
            overflow: hidden;
            animation: rencCardIn 0.45s cubic-bezier(0.22,1,0.36,1) both;
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }
        .renc-card:hover { transform: translateY(-3px); border-color: rgba(212,175,55,0.55); box-shadow: 0 10px 24px rgba(0,0,0,0.35); }
        .renc-card:nth-child(1) { animation-delay: 0.08s; }
        .renc-card:nth-child(2) { animation-delay: 0.16s; }
        @keyframes rencCardIn { from { opacity: 0; transform: translateY(18px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
        /* Liseré doré animé en haut de la carte « Prochaines rencontres » */
        .renc-wrap { position: relative; overflow: hidden; }
        .renc-wrap::before {
            content: '';
            position: absolute;
            top: 0; left: -20%; right: -20%;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,0.9), rgba(241,210,121,0.95), transparent);
            background-size: 200% 100%;
            animation: rencFlow 4.5s linear infinite;
        }
        @keyframes rencFlow { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        /* Boutons d'action des cartes : survol animé */
        .renc-card .btn { transition: transform 0.12s ease, box-shadow 0.2s ease, filter 0.2s ease; }
        .renc-card .btn:hover { filter: brightness(1.08); }
        .renc-card .btn-primary:active { transform: translateY(2px); }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="dashboard-main">
        <!-- User Welcome Banner -->
        <?php if (isset($_SESSION['player_id'])): ?>
            <div class="user-welcome-banner glass-card" style="margin-top: 2rem; margin-bottom: 1rem; padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center; border-radius: 20px; flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <span style="font-size: 1.15rem; font-weight: 500;">Bienvenue, <strong class="gold-text"><?php echo htmlspecialchars($_SESSION['player_name']); ?></strong> !</span>
                    <span class="player-score-badge" style="background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: var(--white); font-weight: 800; padding: 0.5rem 1.25rem; border-radius: 50px; font-size: 1rem; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 4px 12px rgba(27,67,50,0.3);">
                        🏆 <?php echo (int)($_SESSION['player_score'] ?? 0); ?> pts
                    </span>
                </div>
                <a href="kikanla.php" class="btn btn-primary btn-sm">🎮 Jouer à Ki Kan La</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($myWirdParts) || !empty($wirdSessions)): ?>
            <form id="idx-w-form" method="POST" action="wird.php" style="display:none;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="session_id" value="<?php echo (int)$myWirdSession['sid']; ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($myWirdSession['token'], ENT_QUOTES); ?>">
                <input type="hidden" name="action" id="idx-wf-action" value="validate">
                <input type="hidden" name="part_id" id="idx-wf-pid" value="">
                <input type="hidden" name="ptoken" value="">
            </form>
            <div class="w-tovalidate-idx">
                <div class="wtv-idx-top">
                    <div class="wtv-idx-icon">📖</div>
                    <div class="wtv-idx-titles">
                        <span class="wtv-idx-title">Lecture du Coran</span>
                        <span class="wtv-idx-session">Sessions en cours · vos Juz à valider</span>
                    </div>
                    <?php if (!empty($myWirdParts)): ?>
                        <span class="wtv-idx-count"><?php echo count($myWirdParts); ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($myWirdParts)): ?>
                <div class="wtv-idx-list" style="margin-top:0.9rem;">
                    <div style="color:#ffd873; font-size:0.85rem; font-weight:700; margin-bottom:0.5rem;">📖 Vos Juz à valider — Session : <?php echo htmlspecialchars($myWirdSession['titre']); ?></div>
                    <?php foreach ($myWirdParts as $mp): ?>
                    <?php
                        $n = (int)$mp['numero'];
                        $n2 = sprintf('%02d', $n);
                        $v1 = "pdf_viewer.php?file=" . urlencode("Coran_pdf/Version_1/{$n2}.pdf");
                        $v2 = "pdf_viewer.php?file=" . urlencode("Coran_pdf/Version_2/{$n2}-quran{$n}-ar.pdf");
                    ?>
                    <span class="wtv-item-idx">
                        <span class="wtv-num">Juz <?php echo $n; ?></span>
                        <button type="button" class="wtv-btn" onclick="idxConfirmValidation(<?php echo (int)$mp['id']; ?>)">✓ Lu</button>
                        <a href="<?php echo htmlspecialchars($v1); ?>" target="_blank" class="wtv-btn wtv-btn--pdf">V1</a>
                        <a href="<?php echo htmlspecialchars($v2); ?>" target="_blank" class="wtv-btn wtv-btn--pdf">V2</a>
                    </span>
                    <?php endforeach; ?>
                </div>
                <div class="wtv-idx-hint" style="margin-top:0.7rem;">Cliquez sur ✓ Lu après chaque lecture, ou ouvrez directement le PDF souhaité.</div>
                <?php endif; ?>

                <?php if (!empty($wirdSessions)): ?>
                <div style="margin-top:0.9rem; padding-top:0.8rem; border-top:1px solid rgba(255,255,255,0.08);">
                    <div style="color:#ffd873; font-size:0.85rem; font-weight:700; margin-bottom:0.4rem;">📚 Autres sessions en cours — choisissez un Juz</div>
                    <?php foreach ($wirdSessions as $w): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; padding: 0.55rem 0; border-top: 1px solid rgba(255,255,255,0.06);">
                            <span style="color: #fff;"><strong><?php echo htmlspecialchars($w['titre']); ?></strong> <span style="color: var(--text-muted); font-size: 0.85rem;">· <?php echo (int)$w['lues']; ?>/30 lues · <strong style="color:#ffd873;"><?php echo (int)$w['libres']; ?></strong> Juz libres</span></span>
                            <a href="wird.php?s=<?php echo (int)$w['id']; ?>&t=<?php echo htmlspecialchars($w['token'], ENT_QUOTES); ?>" class="btn btn-primary btn-sm" style="white-space:nowrap;">Choisir un Juz</a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Séances publiées : Dahira & Guddi Àjjuma (validation de présence) -->
        <?php if (!empty($prochainDahira) || !empty($prochainGuddi)): ?>
        <div class="glass-card renc-wrap" style="margin-bottom: 1.5rem; padding: 1.4rem 1.5rem;">
            <div style="color: var(--gold); font-weight: 800; font-size: 1.05rem; margin-bottom: 0.9rem;">🕌 Prochaines rencontres — validez votre présence</div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem;">

                <?php if (!empty($prochainDahira)): ?>
                <?php
                    $dDate = $prochainDahira['date_dahira'];
                    $dPassed = $dDate <= date('Y-m-d');
                ?>
                <div class="renc-card">
                    <div style="font-weight:800; color:var(--accent);">🕌 Dahira — <?php echo ucfirst(dahira_jour_fr($dDate)) . ' ' . date('d/m/Y', strtotime($dDate)); ?></div>
                    <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.35rem; white-space:pre-line;">🕐 <?php echo htmlspecialchars($dahiraHeure); ?> à <?php echo htmlspecialchars($dahiraFin); ?><br>📍 <?php echo htmlspecialchars($dahiraLieu); ?></div>
                    <div style="margin-top:0.6rem;"><a href="dahira_detail.php?id=<?php echo (int)$prochainDahira['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent); width:100%; text-align:center;">👁️ Voir le détail</a></div>
                    <?php if (isset($_SESSION['player_id'])): ?>
                        <?php if ($dPassed && $dahiraPresenceFaite): ?>
                            <div style="margin-top:0.8rem; color:#7bd8a6; font-weight:700;">✅ Présence confirmée — Jazakallahou Khair</div>
                            <button type="button" class="btn btn-secondary btn-sm" style="margin-top:0.6rem; width:100%; text-align:center;" onclick="annulerPresence('dahira', <?php echo (int)$prochainDahira['id']; ?>, this)">↩️ Annuler ma présence</button>
                        <?php elseif ($dPassed): ?>
                            <button type="button" class="btn btn-primary btn-sm" style="margin-top:0.8rem; width:100%;" onclick="validerPresence('dahira', <?php echo (int)$prochainDahira['id']; ?>, this)">✅ J'étais présent(e)</button>
                        <?php else: ?>
                            <div style="margin-top:0.8rem; color:var(--text-muted); font-size:0.82rem;">🔔 Vous pourrez valider votre présence le jour même.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="margin-top:0.8rem; color:var(--text-muted); font-size:0.82rem;">🔐 <a href="login.php" style="color:var(--accent);">Connectez-vous</a> pour valider votre présence.</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($prochainGuddi)): ?>
                <?php
                    $gDate = $prochainGuddi['date_guddi'];
                    $gPassed = $gDate <= date('Y-m-d');
                    $gMode = (string)($prochainGuddi['mode'] ?? '');
                    if ($gMode === '') { $gMode = dahira_param($pdo, 'guddi_mode_defaut', 'distance'); }
                ?>
                <div class="renc-card">
                    <div style="font-weight:800; color:var(--accent);">💎 Guddi Àjjuma — <?php echo ucfirst(guddi_jour_fr($gDate)) . ' ' . date('d/m/Y', strtotime($gDate)); ?></div>
                    <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.35rem;">🕐 à partir de <?php echo htmlspecialchars($guddiHeure); ?> · <?php echo $gMode === 'presentiel' ? '🏛️ présentiel' : '💻 à distance'; ?></div>
                    <?php if (!empty($prochainGuddi['theme'])): ?><div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.2rem;">🎯 <?php echo htmlspecialchars((string)$prochainGuddi['theme']); ?></div><?php endif; ?>
                    <div style="margin-top:0.6rem;"><a href="guddi_detail.php?id=<?php echo (int)$prochainGuddi['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent); width:100%; text-align:center;">👁️ Voir le détail</a></div>
                    <?php if (isset($_SESSION['player_id'])): ?>
                        <?php if ($gPassed && $guddiPresenceFaite): ?>
                            <div style="margin-top:0.8rem; color:#7bd8a6; font-weight:700;">✅ Présence confirmée — Jazakallahou Khair</div>
                            <button type="button" class="btn btn-secondary btn-sm" style="margin-top:0.6rem; width:100%; text-align:center;" onclick="annulerPresence('guddi', <?php echo (int)$prochainGuddi['id']; ?>, this)">↩️ Annuler ma présence</button>
                        <?php elseif ($gPassed): ?>
                            <button type="button" class="btn btn-primary btn-sm" style="margin-top:0.8rem; width:100%;" onclick="validerPresence('guddi', <?php echo (int)$prochainGuddi['id']; ?>, this)">✅ J'étais présent(e)</button>
                        <?php else: ?>
                            <div style="margin-top:0.8rem; color:var(--text-muted); font-size:0.82rem;">🔔 Vous pourrez valider votre présence le jour même.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="margin-top:0.8rem; color:var(--text-muted); font-size:0.82rem;">🔐 <a href="login.php" style="color:var(--accent);">Connectez-vous</a> pour valider votre présence.</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>

        <!-- Intro Header -->
        <section class="intro-section">
            <h1 class="intro-title">Membres de <span class="gold-text">Touba Lyon</span></h1>
            <p class="intro-desc">Retrouvez l'annuaire illustré de notre communauté — photos, noms et fraternité.</p>

            <!-- Search bar -->
            <div class="search-container">
                <svg class="search-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <input type="text" id="search-input" class="search-input" placeholder="Rechercher un membre par nom ou prénom...">
            </div>

            <div class="filter-container">
                <button class="filter-btn active" data-filter="all">Tous</button>
                <button class="filter-btn" data-filter="Goor Yalla">Goor Yalla</button>
                <button class="filter-btn" data-filter="Sokhna">Sokhna</button>
            </div>
        </section>

        <!-- Grid of Members -->
        <?php if (empty($members)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">👥</div>
                <h2>Aucun membre validé</h2>
                <p style="margin-top: 0.5rem; color: var(--text-muted);">
                    Le Dahira - Mubawwa-A-Sidqin est actuellement vide ou les inscriptions sont en cours de validation.
                </p>
                <div style="margin-top: 1.5rem;">
                    <a href="register.php" class="btn btn-primary">Créer la première inscription</a>
                </div>
            </div>
        <?php else: ?>
            <div class="trombi-grid" id="trombi-grid">
                <?php foreach ($members as $m): ?>
                    <?php 
                        $fullName = $m['prenom'] . ' ' . $m['nom'];
                    ?>
                    <a class="member-card" href="membre_detail.php?id=<?php echo (int)$m['id']; ?>"
                        data-name="<?php echo htmlspecialchars($fullName); ?>" data-civilite="<?php echo htmlspecialchars($m['civilite'] ?? 'Goor Yalla'); ?>">
                        <div class="member-photo-container">
                            <img src="uploads/<?php echo htmlspecialchars($m['photo_path']); ?>" class="member-photo" alt="Photo de <?php echo htmlspecialchars($fullName); ?>" loading="lazy">
                        </div>
                        <div class="member-info">
                            <h3 class="member-name"><?php echo htmlspecialchars($fullName); ?></h3>
                            <?php if (!empty($m['civilite'])): ?>
                                <span class="member-civilite-badge"><?php echo htmlspecialchars($m['civilite']); ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <!-- No results message -->
            <div class="empty-state" id="no-results" style="display: none;">
                <div class="empty-state-icon">🔍</div>
                <h2>Aucun résultat trouvé</h2>
                <p style="margin-top: 0.5rem; color: var(--text-muted);">
                    Aucun membre ne correspond à votre recherche. Essayez d'autres termes.
                </p>
            </div>
        <?php endif; ?>
            </div>
        </div>
    </main>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <!-- Modal de confirmation validation lecture (index.php) -->
    <div id="idx-confirm-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card">
            <div class="modal-header"><h3 class="gold-text">Confirmation</h3></div>
            <div class="modal-body"><p style="color:var(--text-muted);">Avez-vous bien terminé la lecture de ce Juz ?</p></div>
            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                <button type="button" onclick="idxCloseConfirmModal()" class="btn btn-secondary btn-sm">Non, annuler</button>
                <button type="button" id="idx-confirm-btn" class="btn btn-primary btn-sm">Oui, j'ai lu</button>
            </div>
        </div>
    </div>

    <script>
        var idxConfirmPid = 0;
        function idxConfirmValidation(pid) {
            idxConfirmPid = pid;
            var modal = document.getElementById('idx-confirm-modal');
            modal.style.display = 'flex';
            setTimeout(function() { modal.classList.add('active'); }, 10);
        }
        function idxCloseConfirmModal() {
            var modal = document.getElementById('idx-confirm-modal');
            modal.classList.remove('active');
            setTimeout(function() { modal.style.display = 'none'; }, 400);
        }
        var idxConfirmBtn = document.getElementById('idx-confirm-btn');
        if (idxConfirmBtn) {
            idxConfirmBtn.addEventListener('click', function() {
                if (idxConfirmPid) {
                    document.getElementById('idx-wf-pid').value = idxConfirmPid;
                    document.getElementById('idx-w-form').submit();
                }
            });
        }
    </script>

    <script>
        // Validation de présence (Dahira / Guddi Àjjuma publiés)
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

    <script>
        const searchInput = document.getElementById('search-input');
        const filterBtns = document.querySelectorAll('.filter-btn');
        const cards = document.querySelectorAll('.member-card');
        const grid = document.getElementById('trombi-grid');
        const noResults = document.getElementById('no-results');

        let activeFilter = 'all';

        function filterMembers() {
            const term = searchInput ? searchInput.value.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim() : '';
            let matchCount = 0;

            cards.forEach(card => {
                const name = card.getAttribute('data-name').toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
                const civilite = card.getAttribute('data-civilite');

                const matchesSearch = name.includes(term);
                const matchesFilter = activeFilter === 'all' || civilite === activeFilter;

                if (matchesSearch && matchesFilter) {
                    card.style.display = 'flex';
                    matchCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (matchCount === 0) {
                grid.style.display = 'none';
                noResults.style.display = 'block';
            } else {
                grid.style.display = 'grid';
                noResults.style.display = 'none';
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterMembers);
        }

        filterBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                filterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                activeFilter = btn.getAttribute('data-filter');
                filterMembers();
            });
        });

    </script>
</body>
</html>
