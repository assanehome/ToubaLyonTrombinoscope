<?php
/**
 * Touba Lyon 2026 - 🗓️ Mes disponibilités (espace membre)
 *
 * Les membres d'une commission indiquent leur disponibilité pour chaque
 * dimanche de Dahira du planning. Tous les membres de la commission consultent
 * le planning des disponibilités, regroupé par sous-commission.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_date_longue()

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/player_approved_guard.php';

$membreId = (int) $_SESSION['player_id'];
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

// Commissions du membre (membre ou responsable)
$mesCommissions = [];
try {
    $q = $pdo->prepare("SELECT c.id, c.nom FROM commissions c
                        WHERE COALESCE(c.dispo_active, 1) = 1 AND c.id IN (SELECT commission_id FROM commission_membres WHERE membre_id = ?
                                       UNION SELECT commission_id FROM commission_gestionnaires WHERE membre_id = ?)
                        ORDER BY c.nom ASC");
    $q->execute([$membreId, $membreId]);
    $mesCommissions = $q->fetchAll();
} catch (Exception $e) { $mesCommissions = []; }
// Sous-commissions où le membre est « membre support » (sans appartenir à la commission)
$mesSupports = [];
try {
    $q = $pdo->prepare("SELECT sc.id, sc.nom, sc.commission_id, c.nom AS commission_nom
                        FROM sous_commission_membres scm
                        JOIN sous_commissions sc ON sc.id = scm.sous_commission_id
                        JOIN commissions c ON c.id = sc.commission_id
                        WHERE scm.membre_id = ? AND scm.support = 1 AND COALESCE(c.dispo_active, 1) = 1 ORDER BY c.nom, sc.nom");
    $q->execute([$membreId]);
    $mesSupports = $q->fetchAll();
} catch (Exception $e) { $mesSupports = []; }
$aucuneDispo = empty($mesCommissions) && empty($mesSupports);

// Prochain Dahira du planning : seul celui-là est proposé
$prochain = null;
try {
    $prochain = $pdo->query("SELECT id, date_dahira, titre FROM dahira_plannings
                             WHERE a_dahira = 1 AND option_dahira = 1 AND COALESCE(cloture,0) = 0 AND date_dahira >= CURDATE()
                             ORDER BY date_dahira ASC LIMIT 1")->fetch() ?: null;
} catch (Exception $e) { $prochain = null; }
$pid = $prochain ? (int) $prochain['id'] : 0;
// Dimanches à venir (après le prochain) : groupe fermé par défaut, pour définir ses disponibilités à l'avance
$futurs = [];
try {
    $futurs = $pdo->query("SELECT id, date_dahira, titre FROM dahira_plannings
                           WHERE a_dahira = 1 AND option_dahira = 1 AND COALESCE(cloture,0) = 0 AND date_dahira >= CURDATE() AND id <> " . (int) $pid . "
                           ORDER BY date_dahira ASC LIMIT 12")->fetchAll();
} catch (Exception $e) { $futurs = []; }
$idsFuturs = array_map(function ($d) { return (int) $d['id']; }, $futurs);

// Blocs : une carte par commission du membre, et une par sous-commission où il est « support »
// (sa disponibilité est alors enregistrée pour la commission parente)
$blocs = [];
foreach ($mesCommissions as $c) {
    $blocs[] = ['cid' => (int) $c['id'], 'ancre' => 'c' . (int) $c['id'], 'titre' => '📋 ' . $c['nom'], 'scid' => 0];
}
foreach ($mesSupports as $sp) {
    $blocs[] = ['cid' => (int) $sp['commission_id'], 'ancre' => 's' . (int) $sp['id'], 'titre' => '🧩 ' . $sp['nom'] . ' (' . $sp['commission_nom'] . ')', 'scid' => (int) $sp['id']];
}
$cidsAutorises = array_values(array_unique(array_map(function ($b) { return $b['cid']; }, $blocs)));

$error = '';
$success = $_SESSION['dispo_flash'] ?? '';
unset($_SESSION['dispo_flash']);

$ajax = !empty($_POST['ajax']); // enregistrement automatique à la sélection (réponse JSON)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        try {
            $cid = (int) ($_POST['commission_id'] ?? 0);
            if (($_POST['mode'] ?? '') === 'futurs') {
                if (!in_array($cid, $cidsAutorises, true) || empty($idsFuturs)) {
                    $error = "Commission ou dimanche invalide.";
                } else {
                    $choixF = (array) ($_POST['dispoF'] ?? []);
                    $insF = $pdo->prepare("INSERT INTO commission_disponibilites (planning_id, membre_id, commission_id, statut) VALUES (?,?,?,?)
                                           ON DUPLICATE KEY UPDATE statut = VALUES(statut), updated_at = NOW()");
                    $delF = $pdo->prepare("DELETE FROM commission_disponibilites WHERE planning_id = ? AND membre_id = ? AND commission_id = ?");
                    foreach ($idsFuturs as $pf) {
                        $vf = (string) ($choixF[$pf] ?? '');
                        if ($vf === 'dispo' || $vf === 'indispo') { $insF->execute([$pf, $membreId, $cid, $vf]); }
                        else { $delF->execute([$pf, $membreId, $cid]); }
                    }
                    if ($ajax) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['ok' => true]); exit; }
                    $_SESSION['dispo_flash'] = "Vos disponibilités pour les dimanches à venir ont été enregistrées.";
                    header('Location: disponibilites.php#' . (string) ($_POST['ancre'] ?? '') . '-f');
                    exit;
                }
            } else {
            $v = (string) ($_POST['dispo'] ?? '');
            if ($pid <= 0 || !in_array($cid, $cidsAutorises, true)) {
                $error = "Commission ou Dahira invalide.";
            } else {
                if ($v === 'dispo' || $v === 'indispo') {
                    $pdo->prepare("INSERT INTO commission_disponibilites (planning_id, membre_id, commission_id, statut) VALUES (?,?,?,?)
                                   ON DUPLICATE KEY UPDATE statut = VALUES(statut), updated_at = NOW()")->execute([$pid, $membreId, $cid, $v]);
                } else {
                    $pdo->prepare("DELETE FROM commission_disponibilites WHERE planning_id = ? AND membre_id = ? AND commission_id = ?")->execute([$pid, $membreId, $cid]);
                }
                if ($ajax) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['ok' => true]); exit; }
                $_SESSION['dispo_flash'] = "Votre disponibilité a été enregistrée.";
                header('Location: disponibilites.php#' . (string) ($_POST['ancre'] ?? ''));
                exit;
            }
            }
        } catch (Exception $e) {
            error_log('Touba Lyon disponibilites : ' . $e->getMessage());
            $error = "Une erreur technique est survenue.";
        }
    }
}

// Disponibilités du prochain Dahira : [commission_id][membre_id] = statut
$dispo = [];
if ($pid > 0) {
    try {
        $q = $pdo->prepare("SELECT commission_id, membre_id, statut FROM commission_disponibilites WHERE planning_id = ?");
        $q->execute([$pid]);
        foreach ($q as $r) { $dispo[(int) $r['commission_id']][(int) $r['membre_id']] = (string) $r['statut']; }
    } catch (Exception $e) {}
}

if ($ajax && $error !== '') { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['ok' => false, 'message' => $error]); exit; }

// Dimanches à venir : mes réponses [planning][commission] et nombre de disponibles [planning][commission]
$mesF = [];
$nbOkF = [];
if (!empty($idsFuturs)) {
    try {
        $ph = implode(',', array_fill(0, count($idsFuturs), '?'));
        $q = $pdo->prepare("SELECT planning_id, commission_id, membre_id, statut FROM commission_disponibilites WHERE planning_id IN ($ph)");
        $q->execute($idsFuturs);
        foreach ($q as $r) {
            if ((int) $r['membre_id'] === $membreId) { $mesF[(int) $r['planning_id']][(int) $r['commission_id']] = (string) $r['statut']; }
            if ($r['statut'] === 'dispo') { $nbOkF[(int) $r['planning_id']][(int) $r['commission_id']] = ($nbOkF[(int) $r['planning_id']][(int) $r['commission_id']] ?? 0) + 1; }
        }
    } catch (Exception $e) {}
}

/** Membres d'une sous-commission, en deux groupes : membres de la commission et membres supports. */
function dispo_groupes_sous(PDO $pdo, int $scId): array
{
    $membres = [];
    $supports = [];
    try {
        $q = $pdo->prepare("SELECT m.id, m.prenom, m.nom, scm.support FROM sous_commission_membres scm JOIN membres m ON m.id = scm.membre_id WHERE scm.sous_commission_id = ? AND m.status = 'approved' ORDER BY m.prenom, m.nom");
        $q->execute([$scId]);
        foreach ($q as $r) {
            if ((int) $r['support'] === 1) { $supports[] = $r; } else { $membres[] = $r; }
        }
    } catch (Exception $e) { return []; }
    $groupes = [['👥 Membres', $membres]];
    if (!empty($supports)) { $groupes[] = ['🤝 Membres supports', $supports]; }
    return $groupes;
}
/** Membres d'une commission, groupés par sous-commission : [ [titre, [membres]] , ... ]. */
function dispo_groupes(PDO $pdo, int $cid): array
{
    $membres = [];
    try {
        $q = $pdo->prepare("SELECT DISTINCT m.id, m.prenom, m.nom FROM membres m WHERE m.status = 'approved'
                            AND m.id IN (SELECT membre_id FROM commission_membres WHERE commission_id = ?
                                         UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?)
                            ORDER BY m.prenom, m.nom");
        $q->execute([$cid, $cid]);
        foreach ($q as $r) { $membres[(int) $r['id']] = $r; }
    } catch (Exception $e) { return []; }
    $groupes = [];
    $dansUneSous = [];
    try {
        $subs = $pdo->prepare("SELECT id, nom, responsable_id FROM sous_commissions WHERE commission_id = ? ORDER BY nom ASC");
        $subs->execute([$cid]);
        foreach ($subs->fetchAll() as $sc) {
            $q = $pdo->prepare("SELECT membre_id FROM sous_commission_membres WHERE sous_commission_id = ?");
            $q->execute([(int) $sc['id']]);
            $liste = [];
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                $mid = (int) $mid;
                if (isset($membres[$mid])) { $liste[] = $membres[$mid]; $dansUneSous[$mid] = true; }
            }
            usort($liste, function ($a, $b) { return strcasecmp($a['prenom'] . $a['nom'], $b['prenom'] . $b['nom']); });
            $respNom = (!empty($sc['responsable_id']) && isset($membres[(int) $sc['responsable_id']])) ? ' · 🎖️ ' . trim($membres[(int) $sc['responsable_id']]['prenom'] . ' ' . $membres[(int) $sc['responsable_id']]['nom']) : '';
            $groupes[] = ['🧩 ' . $sc['nom'] . $respNom, $liste];
        }
    } catch (Exception $e) {}
    $sans = array_values(array_filter($membres, function ($m) use ($dansUneSous) { return !isset($dansUneSous[(int) $m['id']]); }));
    if (!empty($sans) || empty($groupes)) { $groupes[] = [empty($groupes) ? '👥 Membres' : '👥 Sans sous-commission', $sans]; }
    return $groupes;
}

// Membres de chaque bloc, regroupés par sous-commission
foreach ($blocs as $k => $bl) {
    $blocs[$k]['groupes'] = $bl['scid'] > 0 ? dispo_groupes_sous($pdo, $bl['scid']) : dispo_groupes($pdo, $bl['cid']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🗓️ Mes disponibilités — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .dp-wrap { max-width: 900px; margin: 1.5rem auto; }
        .dp-card { border-radius: 12px; padding: 1.1rem 1.3rem; margin-bottom: 1.2rem; }
        .dp-card h2 { margin: 0 0 0.3rem; font-size: 1.1rem; color: var(--white); }
        .dp-hint { color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.9rem; }
        .dp-row { display: flex; align-items: center; justify-content: space-between; gap: 0.7rem; flex-wrap: wrap; padding: 0.6rem 0.8rem; margin-bottom: 0.5rem; background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border); border-radius: 10px; }
        .dp-date { color: var(--white); font-weight: 700; }
        .dp-date small { display: block; color: var(--text-muted); font-weight: 500; }
        .dp-choix { display: flex; gap: 0.35rem; }
        .dp-choix input { position: absolute; opacity: 0; pointer-events: none; }
        .dp-choix label { padding: 0.4rem 0.8rem; border-radius: 12px; border: 1px solid var(--glass-border); color: var(--text-muted); font-size: 0.82rem; font-weight: 600; cursor: pointer; user-select: none; }
        .dp-choix input:checked + label.l-dispo { background: rgba(37,211,102,0.2); border-color: rgba(37,211,102,0.6); color: #7bd8a6; }
        .dp-choix input:checked + label.l-indispo { background: rgba(191,33,33,0.2); border-color: rgba(191,33,33,0.6); color: #fca5a5; }
        .dp-choix input:checked + label.l-nr { background: rgba(255,255,255,0.1); color: var(--white); }
        .dp-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table.dp-table { border-collapse: collapse; width: 100%; font-size: 0.85rem; }
        .dp-table th, .dp-table td { padding: 0.45rem 0.6rem; border-bottom: 1px solid var(--glass-border); text-align: center; white-space: nowrap; }
        .dp-table th:first-child, .dp-table td:first-child { text-align: left; position: sticky; left: 0; background: #0f1f17; z-index: 1; }
        .dp-table thead th { color: var(--gold); font-weight: 700; font-size: 0.78rem; }
        .dp-table tr.dp-grp td { background: rgba(212,175,55,0.08); color: var(--gold); font-weight: 700; text-align: left; }
        .dp-table tr.dp-grp td:first-child { background: #1d2a1b; }
        .dp-table tr.dp-me td:first-child { color: var(--accent); font-weight: 700; }
        .dp-ok { color: #7bd8a6; font-weight: 800; }
        .dp-ko { color: #fca5a5; font-weight: 800; }
        .dp-nr { color: var(--text-muted); }
        .dp-table tfoot td { font-weight: 700; color: #7bd8a6; border-bottom: 0; }
        .dp-table tfoot td:first-child { color: var(--text-muted); }
        .dp-sc { border: 1px solid var(--glass-border); border-radius: 10px; background: rgba(255,255,255,0.03); margin-bottom: 0.7rem; overflow: hidden; }
        .dp-sc-tete { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; padding: 0.6rem 0.9rem; background: rgba(212,175,55,0.09); border-bottom: 1px solid var(--glass-border); }
        .dp-sc-titre { color: var(--gold); font-weight: 800; font-size: 0.9rem; }
        .dp-sc-n { color: #7bd8a6; font-weight: 700; font-size: 0.82rem; }
        .dp-m { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; padding: 0.55rem 0.9rem; border-bottom: 1px solid rgba(255,255,255,0.06); }
        .dp-m:last-child { border-bottom: 0; }
        .dp-m-nom { color: var(--white); font-size: 0.92rem; min-width: 0; word-break: break-word; }
        .dp-m.dp-me .dp-m-nom { color: var(--accent); font-weight: 700; }
        .dp-b { flex: 0 0 auto; padding: 0.2rem 0.65rem; border-radius: 12px; font-size: 0.78rem; font-weight: 700; border: 1px solid var(--glass-border); white-space: nowrap; }
        .dp-b-ok { background: rgba(37,211,102,0.15); border-color: rgba(37,211,102,0.5); color: #7bd8a6; }
        .dp-b-ko { background: rgba(191,33,33,0.15); border-color: rgba(191,33,33,0.5); color: #fca5a5; }
        .dp-b-nr { color: var(--text-muted); }
        /* Choix de disponibilité : boutons modernes */
        .dp-form { margin-top: 0.7rem; padding: 0.9rem 1rem 1rem; border: 1px solid rgba(212,175,55,0.35); border-radius: 12px; background: rgba(212,175,55,0.05); position: relative; }
        .dp-form-tete { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.8rem; }
        /* Interrupteur segmenté : Dispo / Pas dispo / Pas défini */
        .dp-seg { display: flex; gap: 4px; padding: 4px; border-radius: 999px; background: rgba(0,0,0,0.28); border: 1px solid rgba(255,255,255,0.1); box-shadow: inset 0 1px 3px rgba(0,0,0,0.35); }
        .dp-seg input { position: absolute; opacity: 0; pointer-events: none; }
        .dp-opt { flex: 1 1 0; min-width: 0; display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.7rem 0.6rem; border-radius: 999px; border: 0; background: transparent; color: var(--text-muted); font-weight: 700; font-size: 0.88rem; text-align: center; white-space: nowrap; cursor: pointer; user-select: none; -webkit-tap-highlight-color: transparent; transition: background .2s, color .2s, box-shadow .2s, transform .12s; }
        .dp-opt .ic { width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; font-size: 0.8rem; font-weight: 900; line-height: 1; border: 1.5px solid currentColor; opacity: 0.75; transition: background .2s, color .2s, border-color .2s, opacity .2s, transform .2s; }
        .dp-opt:hover { color: var(--white); background: rgba(255,255,255,0.07); }
        .dp-opt:active { transform: scale(0.96); }
        .dp-seg input:checked + .dp-opt { color: #fff; }
        .dp-seg input:checked + .dp-opt .ic { opacity: 1; background: rgba(255,255,255,0.95); border-color: transparent; transform: scale(1.08); }
        .dp-seg input:checked + .dp-o-ok { background: linear-gradient(135deg, #1f9d55, #25d366); box-shadow: 0 4px 14px rgba(37,211,102,0.35); }
        .dp-seg input:checked + .dp-o-ok .ic { color: #1f9d55; }
        .dp-seg input:checked + .dp-o-ko { background: linear-gradient(135deg, #b91c1c, #e04b4b); box-shadow: 0 4px 14px rgba(224,75,75,0.35); }
        .dp-seg input:checked + .dp-o-ko .ic { color: #b91c1c; }
        .dp-seg input:checked + .dp-o-nr { background: linear-gradient(135deg, #475569, #64748b); box-shadow: 0 4px 14px rgba(100,116,139,0.35); }
        .dp-seg input:checked + .dp-o-nr .ic { color: #475569; }
        .dp-seg input:focus-visible + .dp-opt { outline: 2px solid var(--accent); outline-offset: 2px; }
        .dp-save { width: 100%; margin-top: 0.8rem; justify-content: center; }
        @media (max-width: 420px) { .dp-opt { padding: 0.65rem 0.3rem; font-size: 0.78rem; gap: 0.3rem; } .dp-opt .ic { width: 18px; height: 18px; font-size: 0.7rem; } }
        /* Dimanches à venir : groupe fermé par défaut */
        .dp-futurs { margin-top: 0.8rem; border: 1px solid rgba(212,175,55,0.38); border-radius: 12px; background: rgba(255,255,255,0.03); overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.16); }
        .dp-futurs > summary { cursor: pointer; list-style: none; display: flex; align-items: center; gap: 0.4rem; padding: 0.85rem 1rem; color: var(--gold); font-weight: 800; font-size: 0.95rem; transition: background .15s; }
        .dp-futurs > summary::-webkit-details-marker { display: none; }
        .dp-futurs > summary::after { content: '▸'; margin-left: auto; color: var(--gold); transition: transform .2s; }
        .dp-futurs > summary:hover { background: rgba(212,175,55,0.08); }
        .dp-futurs[open] > summary::after { transform: rotate(90deg); }
        .dp-futurs[open] > summary { border-bottom: 1px solid rgba(212,175,55,0.3); background: rgba(212,175,55,0.1); }
        .dp-futurs-n { color: var(--text-muted); font-weight: 600; font-size: 0.78rem; }
        .dp-futurs-corps { padding: 0.8rem 1rem 1rem; position: relative; }
        .dp-fr { display: flex; align-items: center; justify-content: space-between; gap: 0.7rem; flex-wrap: wrap; padding: 0.65rem 0.8rem; margin-bottom: 0.5rem; border: 1px solid rgba(212,175,55,0.3); border-radius: 10px; background: rgba(255,255,255,0.04); }
        .dp-mini { gap: 3px; padding: 3px; }
        .dp-mini .dp-opt { padding: 0.4rem 0.8rem; font-size: 0.8rem; gap: 0.35rem; }
        .dp-mini .dp-opt .ic { width: 18px; height: 18px; font-size: 0.68rem; }
        @media (max-width: 520px) { .dp-fr { flex-direction: column; align-items: stretch; } .dp-mini .dp-opt { justify-content: center; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="admin-content">
                <h1 class="admin-page-title">🗓️ Mes disponibilités</h1>
                <div class="dp-wrap">

                <?php if ($aucuneDispo): ?>
                    <div class="glass-card dp-card"><p style="color:var(--text-muted); margin:0;">🗓️ La définition des disponibilités n'est activée pour aucune de vos commissions. Les responsables peuvent l'activer dans les paramètres de leur commission.</p></div>
                <?php elseif (!$prochain): ?>
                    <div class="glass-card dp-card"><p style="color:var(--text-muted); margin:0;">Aucun Dahira n'est planifié pour le moment.</p></div>
                <?php else: ?>
                <div class="glass-card dp-card" style="border-color:rgba(212,175,55,0.45);">
                    <h2>📅 Prochain Dahira : <?php echo $h(ucfirst(dahira_date_longue($prochain['date_dahira']))); ?></h2>
                    <?php if (!empty($prochain['titre'])): ?><p class="dp-hint" style="margin:0 0 0.4rem;">📌 <?php echo $h($prochain['titre']); ?></p><?php endif; ?>
                    <p class="dp-hint" style="margin:0;">Indiquez votre disponibilité <strong>pour chacune de vos commissions</strong> ci-dessous. Les membres d'une commission voient les réponses de cette commission.</p>
                </div>

                <?php foreach ($blocs as $bl):
                    $cid = (int) $bl['cid'];
                    $cur = $dispo[$cid][$membreId] ?? '';
                    $nbOk = 0; $nbKo = 0; $nbNr = 0; $vus = [];
                    foreach ($bl['groupes'] as $g) { foreach ($g[1] as $m) {
                        $mid = (int) $m['id'];
                        if (isset($vus[$mid])) { continue; }
                        $vus[$mid] = true;
                        $s = $dispo[$cid][$mid] ?? '';
                        if ($s === 'dispo') { $nbOk++; } elseif ($s === 'indispo') { $nbKo++; } else { $nbNr++; }
                    } }
                ?>
                <div class="glass-card dp-card" id="<?php echo $h($bl['ancre']); ?>">
                    <h2><?php echo $h($bl['titre']); ?></h2>
                    <form method="POST" action="disponibilites.php" class="dp-form" data-auto="1">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                        <input type="hidden" name="ancre" value="<?php echo $h($bl['ancre']); ?>">
                        <div class="dp-form-tete">
                            <div class="dp-date">Ma disponibilité<small>pour <?php echo $h($bl['scid'] > 0 ? $bl['titre'] : preg_replace('/^📋 /u', '', $bl['titre'])); ?></small></div>
                            <?php if ($cur === 'dispo'): ?><span class="dp-b dp-b-ok dp-cur">✅ Enregistrée : disponible</span>
                            <?php elseif ($cur === 'indispo'): ?><span class="dp-b dp-b-ko dp-cur">❌ Enregistrée : indisponible</span>
                            <?php else: ?><span class="dp-b dp-b-nr dp-cur">— Non précisée</span><?php endif; ?>
                        </div>
                        <div class="dp-seg">
                            <input type="radio" id="<?php echo $h($bl['ancre']); ?>-o" name="dispo" value="dispo"<?php echo $cur === 'dispo' ? ' checked' : ''; ?>><label class="dp-opt dp-o-ok" for="<?php echo $h($bl['ancre']); ?>-o"><span class="ic">✓</span><span>Disponible</span></label>
                            <input type="radio" id="<?php echo $h($bl['ancre']); ?>-n" name="dispo" value="indispo"<?php echo $cur === 'indispo' ? ' checked' : ''; ?>><label class="dp-opt dp-o-ko" for="<?php echo $h($bl['ancre']); ?>-n"><span class="ic">✕</span><span>Pas disponible</span></label>
                            <input type="radio" id="<?php echo $h($bl['ancre']); ?>-x" name="dispo" value=""<?php echo $cur === '' ? ' checked' : ''; ?>><label class="dp-opt dp-o-nr" for="<?php echo $h($bl['ancre']); ?>-x"><span class="ic">?</span><span>Pas défini</span></label>
                        </div>
                        <noscript><button type="submit" class="btn btn-primary dp-save">💾 Enregistrer ma disponibilité</button></noscript>
                    </form>

                    <?php if (!empty($futurs)):
                        $nbRep = 0;
                        foreach ($futurs as $df) { if (isset($mesF[(int) $df['id']][$cid])) { $nbRep++; } }
                    ?>
                    <details class="dp-futurs" id="<?php echo $h($bl['ancre']); ?>-f">
                        <summary>📅 Dimanches à venir (<?php echo count($futurs); ?>) <span class="dp-futurs-n"><?php echo $nbRep; ?> renseigné<?php echo $nbRep > 1 ? 's' : ''; ?></span></summary>
                        <form method="POST" action="disponibilites.php" class="dp-futurs-corps" data-auto="1">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="mode" value="futurs">
                            <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                            <input type="hidden" name="ancre" value="<?php echo $h($bl['ancre']); ?>">
                            <?php foreach ($futurs as $df): $pf = (int) $df['id']; $cf = $mesF[$pf][$cid] ?? ''; $rid = $bl['ancre'] . '-f' . $pf; ?>
                                <div class="dp-fr" data-prev="<?php echo $h($cf); ?>">
                                    <div class="dp-date"><?php echo $h(ucfirst(dahira_date_longue($df['date_dahira']))); ?>
                                        <small><?php if (!empty($df['titre'])) { echo '📌 ' . $h($df['titre']) . ' · '; } ?><span class="dp-cnt" data-n="<?php echo (int) ($nbOkF[$pf][$cid] ?? 0); ?>">✅ <?php echo (int) ($nbOkF[$pf][$cid] ?? 0); ?> disponible(s)</span></small></div>
                                    <div class="dp-seg dp-mini">
                                        <input type="radio" id="<?php echo $h($rid); ?>-o" name="dispoF[<?php echo $pf; ?>]" value="dispo"<?php echo $cf === 'dispo' ? ' checked' : ''; ?>><label class="dp-opt dp-o-ok" for="<?php echo $h($rid); ?>-o" title="Disponible"><span class="ic">✓</span><span>Dispo</span></label>
                                        <input type="radio" id="<?php echo $h($rid); ?>-n" name="dispoF[<?php echo $pf; ?>]" value="indispo"<?php echo $cf === 'indispo' ? ' checked' : ''; ?>><label class="dp-opt dp-o-ko" for="<?php echo $h($rid); ?>-n" title="Pas disponible"><span class="ic">✕</span><span>Pas dispo</span></label>
                                        <input type="radio" id="<?php echo $h($rid); ?>-x" name="dispoF[<?php echo $pf; ?>]" value=""<?php echo $cf === '' ? ' checked' : ''; ?>><label class="dp-opt dp-o-nr" for="<?php echo $h($rid); ?>-x" title="Pas défini"><span class="ic">?</span><span>Pas défini</span></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <noscript><button type="submit" class="btn btn-primary dp-save">💾 Enregistrer mes disponibilités à venir</button></noscript>
                        </form>
                    </details>
                    <?php endif; ?>

                    <div class="dp-sum" data-sum="1" style="margin:0.8rem 0 0.4rem; font-size:0.85rem; color:var(--text-muted);">
                        <span class="dp-ok">✅ <?php echo $nbOk; ?> disponible<?php echo $nbOk > 1 ? 's' : ''; ?></span> · <span class="dp-ko">❌ <?php echo $nbKo; ?> indisponible<?php echo $nbKo > 1 ? 's' : ''; ?></span> · <span class="dp-nr">— <?php echo $nbNr; ?> non précisé<?php echo $nbNr > 1 ? 's' : ''; ?></span>
                    </div>
                    <?php foreach ($bl['groupes'] as $g):
                        $nbOkG = 0;
                        foreach ($g[1] as $m) { if (($dispo[$cid][(int) $m['id']] ?? '') === 'dispo') { $nbOkG++; } }
                    ?>
                    <div class="dp-sc">
                        <div class="dp-sc-tete">
                            <span class="dp-sc-titre"><?php echo $h($g[0]); ?></span>
                            <span class="dp-sc-n" data-total="<?php echo count($g[1]); ?>">✅ <?php echo $nbOkG; ?>/<?php echo count($g[1]); ?></span>
                        </div>
                        <?php if (empty($g[1])): ?>
                            <div class="dp-m dp-nr">Aucun membre.</div>
                        <?php endif; ?>
                        <?php foreach ($g[1] as $m): $mid = (int) $m['id']; $s = $dispo[$cid][$mid] ?? ''; ?>
                            <div class="dp-m<?php echo $mid === $membreId ? ' dp-me' : ''; ?>" data-mid="<?php echo $mid; ?>">
                                <span class="dp-m-nom"><?php echo $h(trim($m['prenom'] . ' ' . $m['nom'])); ?><?php echo $mid === $membreId ? ' (moi)' : ''; ?></span>
                                <?php if ($s === 'dispo'): ?><span class="dp-b dp-b-ok">✅ Disponible</span>
                                <?php elseif ($s === 'indispo'): ?><span class="dp-b dp-b-ko">❌ Indisponible</span>
                                <?php else: ?><span class="dp-b dp-b-nr">— Non précisé</span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                </div>
            </div>
        </div>
    </main>

    <?php if ($success !== '' || $error !== ''): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header"><?php if ($success !== ''): ?><h3 class="gold-text">Réussi</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?></div>
            <div class="modal-body"><p><?php echo $h($success !== '' ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/modern_popup.php'; ?>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <div id="dp-toast" style="position:fixed; left:50%; bottom:1.2rem; transform:translateX(-50%) translateY(20px); opacity:0; pointer-events:none; transition:opacity .2s, transform .2s; z-index:10060; padding:0.65rem 1.1rem; border-radius:12px; font-weight:700; font-size:0.9rem; box-shadow:0 8px 24px rgba(0,0,0,0.45);"></div>
    <script>
    // Enregistrement automatique : dès qu'un choix est fait, sans bouton
    (function () {
        var toast = document.getElementById('dp-toast'), tt = null;
        function say(ok, msg) {
            toast.textContent = msg;
            toast.style.background = ok ? '#14532d' : '#7f1d1d';
            toast.style.border = '1px solid ' + (ok ? '#25d366' : '#e04b4b');
            toast.style.color = '#fff';
            toast.style.opacity = '1'; toast.style.transform = 'translateX(-50%) translateY(0)';
            clearTimeout(tt);
            tt = setTimeout(function () { toast.style.opacity = '0'; toast.style.transform = 'translateX(-50%) translateY(20px)'; }, 1800);
        }
        var TXT = { dispo: ['dp-b-ok', '✅ Disponible', '✅ Enregistrée : disponible'], indispo: ['dp-b-ko', '❌ Indisponible', '❌ Enregistrée : indisponible'], '': ['dp-b-nr', '— Non précisé', '— Non précisée'] };
        function setBadge(el, v, longue) {
            if (!el) { return; }
            var t = TXT[v] || TXT[''];
            el.className = el.className.replace(/dp-b-(ok|ko|nr)/g, '').trim() + ' ' + t[0];
            el.textContent = longue ? t[2] : t[1];
        }
        // Recalcule les totaux d'une carte de commission à partir des badges affichés
        function recompte(carte) {
            var vus = {}, ok = 0, ko = 0, nr = 0;
            carte.querySelectorAll('.dp-m[data-mid]').forEach(function (r) {
                var id = r.getAttribute('data-mid'), b = r.querySelector('.dp-b');
                if (!b || vus[id]) { return; }
                vus[id] = true;
                if (b.classList.contains('dp-b-ok')) { ok++; } else if (b.classList.contains('dp-b-ko')) { ko++; } else { nr++; }
            });
            var sum = carte.querySelector('[data-sum]');
            if (sum) {
                sum.innerHTML = '<span class="dp-ok">✅ ' + ok + ' disponible' + (ok > 1 ? 's' : '') + '</span> · <span class="dp-ko">❌ ' + ko + ' indisponible' + (ko > 1 ? 's' : '') + '</span> · <span class="dp-nr">— ' + nr + ' non précisé' + (nr > 1 ? 's' : '') + '</span>';
            }
            carte.querySelectorAll('.dp-sc').forEach(function (g) {
                var n = g.querySelector('.dp-sc-n'), okg = 0;
                g.querySelectorAll('.dp-m[data-mid] .dp-b-ok').forEach(function () { okg++; });
                if (n) { n.textContent = '✅ ' + okg + '/' + n.getAttribute('data-total'); }
            });
        }
        document.addEventListener('change', function (e) {
            var r = e.target;
            if (!r || r.type !== 'radio') { return; }
            var form = r.form;
            if (!form || form.getAttribute('data-auto') !== '1') { return; }
            var carte = form.closest('.dp-card');
            var fd = new FormData(form);
            fd.append('ajax', '1');
            fetch('disponibilites.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (x) { return x.json(); })
                .then(function (d) {
                    if (!d.ok) { say(false, d.message || 'Erreur : disponibilité non enregistrée.'); return; }
                    say(true, '✅ Enregistré');
                    var v = r.value;
                    if (form.classList.contains('dp-form')) {
                        // prochain Dahira : badge du formulaire + ma ligne dans la liste des membres
                        setBadge(form.querySelector('.dp-cur'), v, true);
                        if (carte) {
                            carte.querySelectorAll('.dp-m.dp-me .dp-b').forEach(function (b) { setBadge(b, v, false); });
                            recompte(carte);
                        }
                    } else {
                        // dimanches à venir : compteur du dimanche + « n renseigné(s) »
                        var ligne = r.closest('.dp-fr'), cnt = ligne && ligne.querySelector('.dp-cnt');
                        if (cnt) {
                            var prev = ligne.getAttribute('data-prev') || '', n = parseInt(cnt.getAttribute('data-n'), 10) || 0;
                            n = n - (prev === 'dispo' ? 1 : 0) + (v === 'dispo' ? 1 : 0);
                            cnt.setAttribute('data-n', n); cnt.textContent = '✅ ' + n + ' disponible(s)';
                            ligne.setAttribute('data-prev', v);
                        }
                        var det = form.closest('.dp-futurs'), sn = det && det.querySelector('.dp-futurs-n');
                        if (sn) {
                            var k = 0;
                            form.querySelectorAll('.dp-fr').forEach(function (l) { if (l.getAttribute('data-prev')) { k++; } });
                            sn.textContent = k + ' renseigné' + (k > 1 ? 's' : '');
                        }
                    }
                })
                .catch(function () { say(false, 'Connexion impossible : disponibilité non enregistrée.'); });
        });
    })();
    </script>
    <script>
    // Ouvre le groupe « Dimanches à venir » ciblé par l'ancre (après enregistrement)
    (function () {
        var h = window.location.hash; if (!h) { return; }
        var el = document.getElementById(h.slice(1)); if (!el) { return; }
        var n = el; while (n) { if (n.tagName === 'DETAILS') { n.open = true; } n = n.parentElement; }
        setTimeout(function () { el.scrollIntoView({ block: 'center' }); }, 100);
    })();
    </script>
</body>
</html>
