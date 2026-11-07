<?php
/**
 * Touba Lyon 2026 - 📋 Mes commissions (espace membre)
 *
 * Pour chaque commission dont le membre fait partie (membre ou responsable) :
 * ses responsables, ses membres (par sous-commission), ses réunions et leurs
 * comptes rendus, et le lien vers le Wadial Magal et les disponibilités.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/magal_helper.php'; // magal_annee_defaut() (liste des années à partir de 2027)
require_once __DIR__ . '/sous_commission_helper.php'; // un membre = une seule sous-commission par commission
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_event_etat()
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/notification_helper.php'; // troba_notify_membre()

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

/** Compte rendu HTML : ne garde que les balises de mise en forme, sans attributs dangereux. */
function mc_cr_html(string $html): string
{
    $clean = strip_tags($html, '<p><br><strong><b><em><i><u><s><ul><ol><li><h3><h4><h5><a><blockquote><span><div>');
    $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
    $clean = preg_replace('/(href\s*=\s*)("|\')\s*javascript:[^"\']*(\2)/i', '$1$2#$3', $clean);
    $clean = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $clean);
    return (string) $clean;
}

// Gestion des membres d'une sous-commission par son responsable (appels AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === 'sub_member') {
    header('Content-Type: application/json; charset=UTF-8');
    $rep = ['ok' => false, 'error' => 'Action refusée.'];
    try {
        if (!csrf_validate()) {
            $rep['error'] = 'Échec de validation de sécurité. Rechargez la page.';
        } else {
            $scId = (int) ($_POST['sous_commission_id'] ?? 0);
            $mid = (int) ($_POST['membre_id'] ?? 0);
            $act = (string) ($_POST['action'] ?? '');
            $q = $pdo->prepare("SELECT id, commission_id, nom, accepte_supports, responsable_id FROM sous_commissions WHERE id = ?");
            $q->execute([$scId]);
            $sc = $q->fetch();
            if (!$sc || (int) ($sc['responsable_id'] ?? 0) !== $membreId) {
                $rep['error'] = "Vous n'êtes pas responsable de cette sous-commission.";
            } elseif ($mid <= 0) {
                $rep['error'] = 'Membre invalide.';
            } elseif ($act === 'remove') {
                if ($mid === $membreId) {
                    $rep['error'] = 'Vous ne pouvez pas vous retirer : vous êtes le responsable de cette sous-commission.';
                } else {
                    $pdo->prepare("DELETE FROM sous_commission_membres WHERE sous_commission_id = ? AND membre_id = ?")->execute([$scId, $mid]);
                    $rep = ['ok' => true];
                }
            } elseif ($act === 'add') {
                $cidSc = (int) $sc['commission_id'];
                $ok = $pdo->prepare("SELECT 1 FROM membres WHERE id = ? AND status = 'approved'");
                $ok->execute([$mid]);
                $inCom = $pdo->prepare("SELECT 1 FROM (SELECT membre_id FROM commission_membres WHERE commission_id = ? UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?) x WHERE membre_id = ?");
                $inCom->execute([$cidSc, $cidSc, $mid]);
                $dansCom = ($inCom->fetchColumn() !== false);
                if ($ok->fetchColumn() === false) {
                    $rep['error'] = 'Membre introuvable ou non validé.';
                } elseif (!$dansCom && empty($sc['accepte_supports'])) {
                    $rep['error'] = "Ce membre ne fait pas partie de la commission et la sous-commission n'accepte pas de membres supports.";
                } elseif ($dansCom && ($autreSc = sc_autre_sous_commission($pdo, $cidSc, $scId, $mid)) !== null) {
                    $rep['error'] = sc_message_deja_dans($autreSc);
                } else {
                    $ins = $pdo->prepare("INSERT IGNORE INTO sous_commission_membres (sous_commission_id, membre_id, support) VALUES (?, ?, ?)");
                    $ins->execute([$scId, $mid, $dansCom ? 0 : 1]);
                    if ($ins->rowCount() > 0) {
                        troba_notify_membre($pdo, $mid, 'commission', $dansCom ? '🧩 Sous-commission' : '🤝 Membre support',
                            ($dansCom ? 'Vous avez été affecté(e) à' : 'Vous aidez') . ' la sous-commission « ' . $sc['nom'] . ' »' . ($dansCom ? '.' : ' en tant que membre support.'), 'mes_commissions.php');
                    }
                    $rep = ['ok' => true];
                }
            }
        }
    } catch (Exception $e) {
        error_log('Touba Lyon mes_commissions (sous-commission) : ' . $e->getMessage());
        $rep['error'] = 'Une erreur technique est survenue.';
    }
    echo json_encode($rep);
    exit;
}
// Commissions du membre, avec son rôle
$commissions = [];
try {
    $q = $pdo->prepare("SELECT c.id, c.nom,
                               EXISTS(SELECT 1 FROM commission_gestionnaires g WHERE g.commission_id = c.id AND g.membre_id = :m1) AS est_resp,
                               COALESCE(c.dispo_active, 1) AS dispo_active,
                               COALESCE(c.stock_enabled, 0) AS stock_enabled
                        FROM commissions c
                        WHERE c.id IN (SELECT commission_id FROM commission_membres WHERE membre_id = :m2
                                       UNION SELECT commission_id FROM commission_gestionnaires WHERE membre_id = :m3)
                        ORDER BY est_resp DESC, c.nom ASC");
    $q->execute([':m1' => $membreId, ':m2' => $membreId, ':m3' => $membreId]);
    $commissions = $q->fetchAll();
} catch (Exception $e) { $commissions = []; }
$ids = array_map(function ($c) { return (int) $c['id']; }, $commissions);

// Sous-commissions où le membre est « membre support » (sans appartenir à la commission)
$mesSupports = [];
try {
    $q = $pdo->prepare("SELECT sc.id, sc.nom, c.nom AS commission_nom, CONCAT(r.prenom, ' ', r.nom) AS resp_nom
                        FROM sous_commission_membres scm
                        JOIN sous_commissions sc ON sc.id = scm.sous_commission_id
                        JOIN commissions c ON c.id = sc.commission_id
                        LEFT JOIN membres r ON r.id = sc.responsable_id
                        WHERE scm.membre_id = ? AND scm.support = 1 ORDER BY c.nom, sc.nom");
    $q->execute([$membreId]);
    $mesSupports = $q->fetchAll();
    // Membres de ces sous-commissions (membres de la commission et supports)
    $mesSupportsMembres = [];
    if (!empty($mesSupports)) {
        $idsSc = array_map(function ($x) { return (int) $x['id']; }, $mesSupports);
        $phSc = implode(',', array_fill(0, count($idsSc), '?'));
        $qm = $pdo->prepare("SELECT scm.sous_commission_id, scm.support, m.prenom, m.nom FROM sous_commission_membres scm JOIN membres m ON m.id = scm.membre_id WHERE scm.sous_commission_id IN ($phSc) AND m.status = 'approved' ORDER BY scm.support, m.prenom, m.nom");
        $qm->execute($idsSc);
        foreach ($qm as $rm) { $mesSupportsMembres[(int) $rm['sous_commission_id']][] = ['nom' => trim($rm['prenom'] . ' ' . $rm['nom']), 'support' => (int) $rm['support'] === 1]; }
    }
} catch (Exception $e) { $mesSupports = []; }

$responsables = [];   // [cid] => [noms]
$membres = [];        // [cid] => [id => nom]
$sousComs = [];       // [cid] => [ [nom, [noms]] ]
$reunions = [];       // [cid] => [rows]
$magalAnnee = magal_annee_defaut();
$magalCommissions = [];

if (!empty($ids)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $q = $pdo->prepare("SELECT g.commission_id, m.prenom, m.nom FROM commission_gestionnaires g JOIN membres m ON m.id = g.membre_id WHERE g.commission_id IN ($ph) AND m.status = 'approved' ORDER BY m.prenom, m.nom");
        $q->execute($ids);
        foreach ($q as $r) { $responsables[(int) $r['commission_id']][] = trim($r['prenom'] . ' ' . $r['nom']); }

        $q = $pdo->prepare("SELECT x.commission_id, m.id, m.prenom, m.nom FROM (
                                SELECT commission_id, membre_id FROM commission_membres WHERE commission_id IN ($ph)
                                UNION SELECT commission_id, membre_id FROM commission_gestionnaires WHERE commission_id IN ($ph)
                            ) x JOIN membres m ON m.id = x.membre_id WHERE m.status = 'approved' ORDER BY m.prenom, m.nom");
        $q->execute(array_merge($ids, $ids));
        foreach ($q as $r) { $membres[(int) $r['commission_id']][(int) $r['id']] = trim($r['prenom'] . ' ' . $r['nom']); }

        $q = $pdo->prepare("SELECT sc.commission_id, sc.id, sc.nom, sc.responsable_id, sc.accepte_supports, scm.membre_id, scm.support, ms.prenom AS ms_prenom, ms.nom AS ms_nom FROM sous_commissions sc LEFT JOIN sous_commission_membres scm ON scm.sous_commission_id = sc.id LEFT JOIN membres ms ON ms.id = scm.membre_id WHERE sc.commission_id IN ($ph) ORDER BY sc.nom");
        $q->execute($ids);
        $tmp = [];
        foreach ($q as $r) {
            $cid = (int) $r['commission_id'];
            if (!isset($tmp[$cid][(int) $r['id']])) { $tmp[$cid][(int) $r['id']] = ['id' => (int) $r['id'], 'cid' => $cid, 'resp_id' => (int) ($r['responsable_id'] ?? 0), 'accepte' => (int) ($r['accepte_supports'] ?? 0), 'ids' => [], 'nom' => (string) $r['nom'], 'resp' => (isset($membres[$cid][(int) ($r['responsable_id'] ?? 0)]) ? $membres[$cid][(int) $r['responsable_id']] : ''), 'membres' => [], 'supports' => []]; }
            if ($r['membre_id'] !== null && (int) ($r['support'] ?? 0) === 1) { $tmp[$cid][(int) $r['id']]['supports'][] = trim($r['ms_prenom'] . ' ' . $r['ms_nom']); }
            elseif ($r['membre_id'] !== null && isset($membres[$cid][(int) $r['membre_id']])) { $tmp[$cid][(int) $r['id']]['membres'][] = $membres[$cid][(int) $r['membre_id']]; }
            if ($r['membre_id'] !== null) { $tmp[$cid][(int) $r['id']]['ids'][] = (int) $r['membre_id']; }
        }
        foreach ($tmp as $cid => $liste) { $sousComs[$cid] = array_values($liste); }

        $q = $pdo->prepare("SELECT id, commission_id, titre, date_reunion, heure, heure_fin, mode, publie, cloture, annule, compte_rendu, sondage_statut
                            FROM commission_reunions
                            WHERE commission_id IN ($ph) AND COALESCE(annule,0) = 0 AND (publie = 1 OR cloture = 1 OR sondage_statut = 'ouvert')
                            ORDER BY date_reunion DESC, id DESC");
        $q->execute($ids);
        foreach ($q as $r) { $reunions[(int) $r['commission_id']][] = $r; }

        $q = $pdo->prepare("SELECT commission_id FROM magal_commissions WHERE annee = ?");
        $q->execute([$magalAnnee]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mc) { $magalCommissions[(int) $mc] = true; }
    } catch (Exception $e) { error_log('Touba Lyon mes_commissions : ' . $e->getMessage()); }
}

// Sous-commissions dont le membre est responsable (gestion depuis son espace)
$smgData = [];
foreach ($sousComs as $cidG => $subsG) {
    foreach ($subsG as $sg) {
        if ((int) ($sg['resp_id'] ?? 0) === $membreId) {
            $autresIds = [];
            foreach ($subsG as $og) { if ((int) $og['id'] !== (int) $sg['id']) { foreach ($og['ids'] as $oid) { if (isset($membres[$cidG][(int) $oid])) { $autresIds[(int) $oid] = true; } } } }
            $smgData[(int) $sg['id']] = ['autres' => array_keys($autresIds), 'cid' => (int) $cidG, 'nom' => $sg['nom'], 'accepte' => (int) $sg['accepte'], 'ids' => array_values(array_map('intval', $sg['ids'])), 'cmembers' => array_map('intval', array_keys($membres[$cidG] ?? []))];
        }
    }
}
$tousMembres = [];
if (!empty($smgData)) {
    try {
        foreach ($pdo->query("SELECT id, prenom, nom FROM membres WHERE status = 'approved' ORDER BY prenom, nom") as $rm) {
            $tousMembres[] = ['id' => (int) $rm['id'], 'name' => trim($rm['prenom'] . ' ' . $rm['nom'])];
        }
    } catch (Exception $e) { $tousMembres = []; }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📋 Mes commissions — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .mc-wrap { max-width: 860px; margin: 0 auto; }
        .mc-card { border-radius: 12px; padding: 1.2rem 1.4rem; margin-bottom: 1.2rem; }
        .mc-head { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.4rem; }
        .mc-head h2 { margin: 0; color: var(--white); font-size: 1.2rem; }
        .mc-sub { color: var(--text-muted); font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin: 0.9rem 0 0.4rem; }
        .mc-chips { display: flex; flex-wrap: wrap; gap: 0.35rem; }
        .mc-chip { background: rgba(255,255,255,0.06); border: 1px solid var(--glass-border); border-radius: 12px; padding: 0.2rem 0.7rem; font-size: 0.82rem; color: var(--white); }
        .mc-chip.resp { border-color: rgba(212,175,55,0.5); color: var(--gold); }
        .mc-grp { border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.55rem 0.9rem; background: rgba(255,255,255,0.03); margin-bottom: 0.5rem; }
        .mc-grp > summary { cursor: pointer; list-style: none; font-weight: 700; color: var(--white); font-size: 0.9rem; display: flex; align-items: center; gap: 0.4rem; }
        .mc-grp > summary::-webkit-details-marker { display: none; }
        .mc-reu { border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.55rem 0.8rem; margin-top: 0.5rem; background: rgba(255,255,255,0.04); }
        .mc-reu-top { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .mc-reu-titre { color: var(--white); font-weight: 700; }
        .mc-reu-meta { color: var(--text-muted); font-size: 0.8rem; margin-top: 2px; }
        .mc-cr { margin-top: 0.6rem; padding: 0.7rem 0.9rem; background: rgba(255,255,255,0.05); border-radius: 8px; color: var(--white); font-size: 0.9rem; line-height: 1.55; }
        .mc-cr h3, .mc-cr h4 { color: var(--gold); margin: 0.6rem 0 0.2rem; }
        .mc-cr ul, .mc-cr ol { padding-left: 1.3rem; }
        .mc-cr blockquote { border-left: 3px solid var(--gold); margin: 0.4rem 0; padding-left: 0.7rem; color: var(--text-muted); }
        .mc-cr a { color: var(--accent); }
        .mc-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.8rem; }
        .mc-frame-full { display: block; width: 100%; border: 0; min-height: 260px; background: transparent; overflow: hidden; }
        /* Une commission = un groupe, au même format que les autres groupes de l'application */
        details.mc-com { border: 1px solid rgba(212,175,55,0.38); border-radius: 12px; background: rgba(255,255,255,0.03); overflow: hidden; margin: 0 0 1rem; padding: 0; box-shadow: 0 2px 10px rgba(0,0,0,0.16); }
        details.mc-com > summary { cursor: pointer; list-style: none; display: flex; align-items: center; gap: 0.5rem; margin: 0; padding: 0.85rem 1rem; color: var(--white); font-weight: 800; font-size: 0.92rem; transition: background .15s; }
        details.mc-com > summary::-webkit-details-marker { display: none; }
        details.mc-com > summary::after { content: '▸'; margin-left: auto; color: var(--gold); transition: transform .2s; }
        details.mc-com[open] > summary::after { transform: rotate(90deg); }
        details.mc-com > summary:hover { background: rgba(212,175,55,0.08); }
        details.mc-com[open] > summary { background: rgba(212,175,55,0.1); border-bottom: 1px solid rgba(212,175,55,0.3); color: var(--gold); }
        .mc-com-nom { flex: 1 1 auto; min-width: 0; }
        .mc-com-nom .mg-n { color: var(--text-muted); font-weight: 600; font-size: 0.8rem; }
        .mc-com-badges { display: flex; gap: 0.35rem; flex-wrap: wrap; flex: 0 0 auto; }
        .mc-com-body { padding: 0; }
        @media (max-width: 480px) { .mc-com-badges { display: none; } }
        .mc-com .mc-card-in { border: 0; background: transparent; box-shadow: none; margin: 0; border-radius: 0; padding: 1rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="admin-content">
                <h1 class="admin-page-title">📋 Mes commissions</h1>
                <div class="mc-wrap">

                <?php if (empty($commissions) && empty($mesSupports)): ?>
                    <div class="glass-card mc-card"><p style="margin:0; color:var(--text-muted);">Vous n'êtes membre d'aucune commission pour le moment.</p></div>
                <?php endif; ?>

                <?php if (!empty($mesSupports)): ?>
                <div class="glass-card mc-card">
                    <div class="mc-head"><h2>🤝 Mes sous-commissions (membre support)</h2></div>
                    <p style="margin:0 0 0.5rem; color:var(--text-muted); font-size:0.85rem;">Vous aidez ces sous-commissions sans faire partie de la commission.</p>
                    <?php foreach ($mesSupports as $ms): ?>
                        <div class="mc-reu">
                            <div class="mc-reu-top"><span class="mc-reu-titre">🧩 <?php echo $h($ms['nom']); ?></span><span class="badge">📋 <?php echo $h($ms['commission_nom']); ?></span></div>
                            <div class="mc-chips" style="margin-top:0.4rem;">
                                <?php foreach (($mesSupportsMembres[(int) $ms['id']] ?? []) as $mb): ?><span class="mc-chip"><?php echo $mb['support'] ? '🤝 ' : ''; ?><?php echo $h($mb['nom']); ?></span><?php endforeach; ?>
                            </div>
                            <?php if (!empty(trim((string) $ms['resp_nom']))): ?><div class="mc-reu-meta">🎖️ Responsable : <?php echo $h($ms['resp_nom']); ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="mc-actions"><a href="disponibilites.php" class="btn btn-secondary btn-sm">🗓️ Mes disponibilités</a></div>
                </div>
                <?php endif; ?>
                <?php
                    // Wadial Magal : accès du membre (pour le raccourci de gestion des responsables)
                    $accesMagal = false;
                    try { require_once __DIR__ . '/magal_helper.php'; $accesMagal = magal_membre_a_acces($pdo, $membreId); } catch (Throwable $e) { $accesMagal = false; }
                ?>
                <?php foreach ($commissions as $c):
                    $cid = (int) $c['id'];
                    $estResp = !empty($c['est_resp']);
                    $liste = $reunions[$cid] ?? [];
                    $aVenir = []; $closes = []; $sondages = [];
                    foreach ($liste as $r) {
                        if ((int) $r['cloture'] === 1) { $closes[] = $r; }
                        elseif ($r['sondage_statut'] === 'ouvert' && (int) $r['publie'] !== 1) { $sondages[] = $r; }
                        else { $aVenir[] = $r; }
                    }
                    $aVenir = array_reverse($aVenir); // prochaines d'abord
                    $nbMembres = count($membres[$cid] ?? []);
                ?>
                <?php
                    // Intitulés de groupe : commissions dont je suis responsable, puis celles dont je suis membre
                    $groupeCourant = $estResp ? 'resp' : 'membre';
                    if ($groupeCourant !== ($groupePrec ?? null)) {
                        $nbResp = count(array_filter($commissions, static function ($x) { return !empty($x['est_resp']); }));
                        if ($nbResp > 0 && $nbResp < count($commissions)) {
                            echo '<h2 style="margin:' . (($groupePrec ?? null) === null ? '0' : '1.6rem') . ' 0 0.7rem; font-size:1.05rem; color:var(--gold);">'
                                . ($estResp ? '🎖️ Commissions dont je suis responsable (' . $nbResp . ')' : '👥 Commissions dont je suis membre (' . (count($commissions) - $nbResp) . ')') . '</h2>';
                        }
                        $groupePrec = $groupeCourant;
                    }
                ?>
                <details class="mc-com" id="commission-<?php echo $cid; ?>" open>
                    <summary>
                        <span class="mc-com-nom">📋 <?php echo $h($c['nom']); ?> <span class="mg-n">(<?php echo $nbMembres; ?> membre(s))</span></span>
                        <span class="mc-com-badges"><?php if ($estResp): ?><span class="badge badge-approved">🎖️ Responsable</span><?php else: ?><span class="badge">Membre</span><?php endif; ?></span>
                    </summary>
                    <div class="mc-com-body">
                    <div class="mc-card mc-card-in">


                    <div class="mc-sub">🎖️ Responsable(s)</div>
                    <?php if (empty($responsables[$cid])): ?>
                        <p style="margin:0; color:var(--text-muted); font-size:0.88rem;">Aucun responsable désigné.</p>
                    <?php else: ?>
                        <div class="mc-chips"><?php foreach ($responsables[$cid] as $n): ?><span class="mc-chip resp"><?php echo $h($n); ?></span><?php endforeach; ?></div>
                    <?php endif; ?>

                    <details class="mc-grp" style="margin-top:0.8rem;">
                        <summary>👥 Membres de la commission (<?php echo $nbMembres; ?>)</summary>
                        <div class="mc-chips" style="margin-top:0.6rem;">
                            <?php foreach (($membres[$cid] ?? []) as $mid => $nom): ?>
                                <span class="mc-chip<?php echo $mid === $membreId ? ' resp' : ''; ?>"><?php echo $h($nom); ?><?php echo $mid === $membreId ? ' (moi)' : ''; ?></span>
                            <?php endforeach; ?>
                        </div>
                    </details>

                    <?php if (!empty($sousComs[$cid])): ?>
                    <details class="mc-grp">
                        <summary>🧩 Sous-commissions (<?php echo count($sousComs[$cid]); ?>)</summary>
                        <?php foreach ($sousComs[$cid] as $sc): ?>
                            <div id="sc-<?php echo (int) $sc['id']; ?>" style="margin-top:0.6rem;">
                                <strong style="color:var(--white); font-size:0.88rem;">🧩 <?php echo $h($sc['nom']); ?></strong>
                                <span style="color:var(--text-muted); font-size:0.78rem;">(<?php echo count($sc['membres']); ?>)</span>
                                <?php if (!empty($sc['resp'])): ?>
                                    <span style="color:var(--gold); font-size:0.8rem; font-weight:700;">🎖️ Responsable : <?php echo $h($sc['resp']); ?></span>
                                <?php endif; ?>
                                <?php if ((int) $sc['resp_id'] === $membreId): ?>
                                    <div style="margin:0.4rem 0 0.2rem;"><button type="button" class="btn btn-primary btn-sm" onclick="smgOpen(<?php echo (int) $sc['id']; ?>)">👥 Gérer les membres</button> <span style="color:var(--text-muted); font-size:0.78rem;">Vous êtes responsable de cette sous-commission.</span></div>
                                <?php endif; ?>
                                <div class="mc-chips" style="margin-top:0.3rem;">
                                    <?php if (empty($sc['membres'])): ?><span style="color:var(--text-muted); font-size:0.82rem;">Aucun membre affecté.</span><?php endif; ?>
                                    <?php foreach ($sc['membres'] as $n): ?><span class="mc-chip"><?php echo $h($n); ?></span><?php endforeach; ?>
                                    <?php foreach (($sc['supports'] ?? []) as $n): ?><span class="mc-chip" title="Membre support">🤝 <?php echo $h($n); ?></span><?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </details>
                    <?php endif; ?>

                    <div class="mc-sub">📅 Réunions</div>
                    <?php if (empty($liste)): ?>
                        <p style="margin:0; color:var(--text-muted); font-size:0.88rem;">Aucune réunion pour le moment.</p>
                    <?php endif; ?>

                    <?php if (!empty($sondages)): ?>
                    <details class="mc-grp" open>
                        <summary>📊 Sondages de date en cours (<?php echo count($sondages); ?>)</summary>
                        <?php foreach ($sondages as $r): ?>
                            <div class="mc-reu">
                                <div class="mc-reu-top">
                                    <span class="mc-reu-titre">📌 <?php echo $h($r['titre']); ?></span>
                                    <a href="reunion_sondage.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-primary btn-sm">📊 Voter pour la date</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </details>
                    <?php endif; ?>

                    <?php if (!empty($aVenir)): ?>
                    <details class="mc-grp" open>
                        <summary>🚀 En cours ou à venir (<?php echo count($aVenir); ?>)</summary>
                        <?php foreach ($aVenir as $r):
                            $hf = trim((string) ($r['heure_fin'] ?? ''));
                            $etat = dahira_event_etat((string) $r['date_reunion'], (string) $r['heure'], ($hf !== '' ? $hf : null));
                        ?>
                            <div class="mc-reu">
                                <div class="mc-reu-top">
                                    <span class="mc-reu-titre">📌 <?php echo $h($r['titre']); ?></span>
                                    <a href="reunion_detail.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-secondary btn-sm">📊 Détail</a>
                                </div>
                                <div class="mc-reu-meta">🗓️ <?php echo date('d/m/Y', strtotime($r['date_reunion'])); ?> · 🕐 <?php echo $h($r['heure']); ?><?php echo $hf !== '' ? ' – ' . $h($hf) : ''; ?> · <?php echo $r['mode'] === 'presentiel' ? '🏛️ Présentiel' : '💻 À distance'; ?><?php echo $etat === 'encours' ? ' · <strong style="color:#7bd8a6;">En cours</strong>' : ''; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </details>
                    <?php endif; ?>

                    <?php if (!empty($closes)): ?>
                    <details class="mc-grp">
                        <summary>✅ Réunions clôturées et comptes rendus (<?php echo count($closes); ?>)</summary>
                        <?php foreach ($closes as $r):
                            $hf = trim((string) ($r['heure_fin'] ?? ''));
                            $cr = trim((string) ($r['compte_rendu'] ?? ''));
                            $aCr = trim(strip_tags($cr)) !== '';
                        ?>
                            <div class="mc-reu">
                                <div class="mc-reu-top">
                                    <span class="mc-reu-titre">📌 <?php echo $h($r['titre']); ?></span>
                                    <a href="reunion_detail.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-secondary btn-sm">📊 Détail</a>
                                </div>
                                <div class="mc-reu-meta">🗓️ <?php echo date('d/m/Y', strtotime($r['date_reunion'])); ?> · 🕐 <?php echo $h($r['heure']); ?><?php echo $hf !== '' ? ' – ' . $h($hf) : ''; ?></div>
                                <?php if ($aCr): ?>
                                <details style="margin-top:0.5rem;">
                                    <summary style="cursor:pointer; color:var(--gold); font-weight:600; font-size:0.85rem;">📝 Lire le compte rendu</summary>
                                    <div class="mc-cr"><?php echo mc_cr_html($cr); ?></div>
                                </details>
                                <?php else: ?>
                                    <div class="mc-reu-meta">📝 Pas de compte rendu pour cette réunion.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </details>
                    <?php endif; ?>

                    <div class="mc-actions">
                        <?php if ((int) ($c['dispo_active'] ?? 1) === 1): ?><a href="disponibilites.php#c<?php echo $cid; ?>" class="btn btn-secondary btn-sm">🗓️ Mes disponibilités</a><?php endif; ?>
                        <?php if (isset($magalCommissions[$cid]) && $accesMagal): ?>
                            <a href="magal.php?annee=<?php echo (int) $magalAnnee; ?>&tab=taches" class="btn btn-secondary btn-sm" style="border-color:var(--gold); color:var(--gold);">🕌 Wadial Magal <?php echo (int) $magalAnnee; ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                    </div>
                </details>
                <?php endforeach; ?>

                </div>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php if (!empty($smgData)): ?>
    <!-- Gestion des membres d'une sous-commission par son responsable -->
    <style>
        #smg-modal { position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; padding: 1rem; }
        #smg-modal.active { display: flex; }
        .smg-card { width: 100%; max-width: 760px; max-height: 90vh; display: flex; flex-direction: column; background: linear-gradient(180deg,#123528,#0c241a); border: 1px solid rgba(212,175,55,0.3); border-radius: 12px; overflow: hidden; box-shadow: 0 30px 80px rgba(0,0,0,0.55); }
        .smg-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; background: linear-gradient(135deg,#1b4332,#2d6a4f); }
        .smg-head h3 { margin: 0; color: #fff; font-size: 1.05rem; }
        .smg-x { background: none; border: 0; color: #fff; font-size: 1.7rem; line-height: 1; cursor: pointer; }
        .smg-note { padding: 0.55rem 1.25rem; font-size: 0.8rem; color: var(--text-muted); border-bottom: 1px solid rgba(255,255,255,0.08); }
        .smg-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 0; min-height: 0; flex: 1; overflow: hidden; }
        .smg-col { display: flex; flex-direction: column; min-height: 0; border-right: 1px solid rgba(255,255,255,0.08); }
        .smg-col:last-child { border-right: 0; }
        .smg-col-head { padding: 0.7rem 0.9rem; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .smg-title { color: var(--gold); font-weight: 700; font-size: 0.82rem; margin-bottom: 0.4rem; }
        .smg-col-head input { width: 100%; box-sizing: border-box; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.14); border-radius: 8px; color: #fff; padding: 0.5rem 0.7rem; font-size: 0.88rem; }
        .smg-list { overflow-y: auto; padding: 0.4rem; min-height: 180px; max-height: 50vh; }
        .smg-item { display: flex; align-items: center; gap: 0.6rem; padding: 0.4rem 0.5rem; border-radius: 8px; }
        .smg-item:hover { background: rgba(255,255,255,0.05); }
        .smg-av { width: 30px; height: 30px; border-radius: 50%; background: rgba(212,175,55,0.22); color: var(--gold); display: flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 800; flex-shrink: 0; }
        .smg-name { flex: 1; color: #fff; font-size: 0.88rem; min-width: 0; }
        .smg-tag { font-size: 0.66rem; font-weight: 700; color: #8ec5ff; border: 1px solid rgba(45,140,255,0.4); border-radius: 12px; padding: 0.08rem 0.45rem; margin-left: 0.3rem; white-space: nowrap; }
        .smg-btn { width: 30px; height: 30px; border-radius: 50%; border: 0; cursor: pointer; font-size: 1.15rem; font-weight: 800; line-height: 1; flex-shrink: 0; }
        .smg-btn.add { background: rgba(37,211,102,0.2); color: #7bd8a6; }
        .smg-btn.rem { background: rgba(191,33,33,0.2); color: #fca5a5; }
        .smg-vide { padding: 1rem; text-align: center; color: var(--text-muted); font-size: 0.85rem; }
        .smg-foot { display: flex; justify-content: space-between; align-items: center; gap: 0.6rem; padding: 0.8rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.08); color: var(--text-muted); font-size: 0.8rem; }
        @media (max-width: 600px) { .smg-cols { grid-template-columns: 1fr; overflow-y: auto; } .smg-col { border-right: 0; border-bottom: 1px solid rgba(255,255,255,0.08); } .smg-list { max-height: 34vh; } }
    </style>
    <div id="smg-modal">
        <div class="smg-card">
            <div class="smg-head"><h3 id="smg-title">Membres</h3><button type="button" class="smg-x" onclick="smgClose()" aria-label="Fermer">&times;</button></div>
            <div class="smg-note" id="smg-note"></div>
            <div class="smg-cols">
                <div class="smg-col">
                    <div class="smg-col-head"><div class="smg-title">Membres disponibles</div><input type="text" id="smg-search" placeholder="🔍 Rechercher un membre…" autocomplete="off"></div>
                    <div class="smg-list" id="smg-available"></div>
                </div>
                <div class="smg-col">
                    <div class="smg-col-head"><div class="smg-title">Dans la sous-commission (<span id="smg-count">0</span>)</div></div>
                    <div class="smg-list" id="smg-selected"></div>
                </div>
            </div>
            <div class="smg-foot"><span id="smg-avail">&nbsp;</span><button type="button" class="btn btn-primary btn-sm" onclick="smgClose()">Terminé</button></div>
        </div>
    </div>
    <script>
        var SMG = <?php echo json_encode((object) $smgData, JSON_UNESCAPED_UNICODE); ?>;
        var SMG_TOUS = <?php echo json_encode($tousMembres, JSON_UNESCAPED_UNICODE); ?>;
        var SMG_CSRF = <?php echo json_encode(csrf_token()); ?>;
        var smgId = null, smgSel = new Set(), smgDirty = false;
        function smgNorm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim(); }
        function smgEsc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
        function smgSub() { return SMG[smgId] || { cid: 0, nom: '', accepte: 0, ids: [], cmembers: [] }; }
        function smgDansCom(id) { return (smgSub().cmembers || []).map(Number).indexOf(id) !== -1; }
        function smgIni(n) { var p = String(n).trim().split(/\s+/); return ((p[0] || '').charAt(0) + (p[1] || '').charAt(0)).toUpperCase() || '?'; }
        function smgTag(id) { return smgDansCom(id) ? '' : '<span class="smg-tag">🤝 support</span>'; }
        function smgRender() {
            var term = smgNorm(document.getElementById('smg-search').value);
            var av = document.getElementById('smg-available'), se = document.getElementById('smg-selected');
            av.innerHTML = ''; se.innerHTML = '';
            var na = 0, ns = 0, sub = smgSub();
            SMG_TOUS.forEach(function (m) {
                if (smgSel.has(m.id)) {
                    se.insertAdjacentHTML('beforeend', '<div class="smg-item"><span class="smg-av">' + smgEsc(smgIni(m.name)) + '</span><span class="smg-name">' + smgEsc(m.name) + smgTag(m.id) + '</span><button type="button" class="smg-btn rem" onclick="smgRemove(' + m.id + ')" title="Retirer">&times;</button></div>');
                    ns++;
                } else {
                    if (!smgDansCom(m.id) && !sub.accepte) { return; }
                    if (smgDansCom(m.id) && (sub.autres || []).map(Number).indexOf(m.id) !== -1) { return; } // déjà dans une autre sous-commission
                    if (term && smgNorm(m.name).indexOf(term) === -1) { return; }
                    av.insertAdjacentHTML('beforeend', '<div class="smg-item"><span class="smg-av">' + smgEsc(smgIni(m.name)) + '</span><span class="smg-name">' + smgEsc(m.name) + smgTag(m.id) + '</span><button type="button" class="smg-btn add" onclick="smgAdd(' + m.id + ')" title="Ajouter">+</button></div>');
                    na++;
                }
            });
            if (!na) { av.innerHTML = '<div class="smg-vide">Aucun membre disponible.</div>'; }
            if (!ns) { se.innerHTML = '<div class="smg-vide">Aucun membre dans la sous-commission.</div>'; }
            document.getElementById('smg-count').textContent = ns;
            document.getElementById('smg-avail').textContent = na + ' disponible(s)';
        }
        function smgPersist(add, mid) {
            var params = new URLSearchParams();
            params.append('ajax', 'sub_member');
            params.append('action', add ? 'add' : 'remove');
            params.append('sous_commission_id', smgId);
            params.append('membre_id', mid);
            params.append('csrf_token', SMG_CSRF);
            return fetch('mes_commissions.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString(), credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (!j.ok) { throw new Error(j.error || 'Erreur'); } });
        }
        function smgAlert(msg) { if (window.modernAlert) { window.modernAlert(msg, 'Erreur'); } else { alert(msg); } }
        function smgAdd(id) {
            smgSel.add(id); smgDirty = true; smgRender();
            smgPersist(true, id).catch(function (e) { smgSel.delete(id); smgRender(); smgAlert("Échec de l'ajout : " + e.message); });
        }
        function smgRemove(id) {
            smgSel.delete(id); smgDirty = true; smgRender();
            smgPersist(false, id).catch(function (e) { smgSel.add(id); smgRender(); smgAlert("Échec du retrait : " + e.message); });
        }
        function smgOpen(id) {
            smgId = id; smgDirty = false;
            var sub = smgSub();
            smgSel = new Set((sub.ids || []).map(Number));
            document.getElementById('smg-title').textContent = 'Membres — ' + sub.nom;
            document.getElementById('smg-note').textContent = sub.accepte
                ? 'Membres de la commission et membres supports (hors commission) acceptés.'
                : 'Membres de la commission uniquement.';
            document.getElementById('smg-search').value = '';
            smgRender();
            document.getElementById('smg-modal').classList.add('active');
        }
        function smgClose() {
            document.getElementById('smg-modal').classList.remove('active');
            if (smgDirty) { window.location.href = window.location.pathname + '#sc-' + smgId; window.location.reload(); }
        }
        document.getElementById('smg-search').addEventListener('input', smgRender);
        document.getElementById('smg-modal').addEventListener('click', function (e) { if (e.target === this) { smgClose(); } });
        // Retour sur la sous-commission modifiée : ouvre les groupes qui la contiennent
        (function () {
            var h = window.location.hash;
            if (h.indexOf('#sc-') !== 0) { return; }
            var el = document.getElementById(h.slice(1));
            if (!el) { return; }
            var n = el;
            while (n) { if (n.tagName === 'DETAILS') { n.open = true; } n = n.parentElement; }
            el.scrollIntoView({ block: 'center' });
        })();
    </script>
    <?php endif; ?>
    <script>
    // Gestion de la commission intégrée (bloc identique à l'espace commission) : hauteur automatique + position de la fenêtre visible
    (function () {
        var frames = [].slice.call(document.querySelectorAll('iframe.mc-frame-full'));
        if (!frames.length) { return; }
        function visible(f) {
            var r = f.getBoundingClientRect();
            var top = Math.max(0, -r.top);
            var h = Math.max(0, Math.min(window.innerHeight, r.bottom) - Math.max(0, r.top));
            try { f.contentWindow.postMessage({ type: 'embed-viewport', top: top, height: h || window.innerHeight }, location.origin); } catch (e) { /* ignoré */ }
        }
        function tous() { frames.forEach(visible); }
        window.addEventListener('message', function (e) {
            if (e.origin !== location.origin) { return; }
            var d = e.data || {};
            if (d.type !== 'embed-height') { return; }
            frames.forEach(function (f) { if (f.contentWindow === e.source) { f.style.height = Math.max(260, d.h) + 'px'; visible(f); } });
        });
        window.addEventListener('scroll', tous, { passive: true });
        window.addEventListener('resize', tous);
        frames.forEach(function (f) { f.addEventListener('load', function () { visible(f); }); });
        // Le cadre de gestion se charge dès que le groupe est ouvert (ouvert par défaut ; chargement à l'ouverture pour les autres)
        document.querySelectorAll('details.mc-com').forEach(function (d) {
            function charger() { var f = d.querySelector('iframe.mc-frame-full'); if (f && !f.getAttribute('src')) { f.setAttribute('src', f.getAttribute('data-src')); } }
            d.addEventListener('toggle', function () { if (d.open) { charger(); } });
            if (d.open) { charger(); }
        });
    })();
    </script>
</body>
</html>
