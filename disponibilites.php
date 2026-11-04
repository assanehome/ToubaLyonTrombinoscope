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
                        WHERE c.id IN (SELECT commission_id FROM commission_membres WHERE membre_id = ?
                                       UNION SELECT commission_id FROM commission_gestionnaires WHERE membre_id = ?)
                        ORDER BY c.nom ASC");
    $q->execute([$membreId, $membreId]);
    $mesCommissions = $q->fetchAll();
} catch (Exception $e) { $mesCommissions = []; }
if (empty($mesCommissions)) {
    header('Location: index.php');
    exit;
}

// Prochains dimanches de Dahira du planning
$dimanches = [];
try {
    $dimanches = $pdo->query("SELECT id, date_dahira, titre FROM dahira_plannings
                              WHERE a_dahira = 1 AND option_dahira = 1 AND COALESCE(cloture,0) = 0 AND date_dahira >= CURDATE()
                              ORDER BY date_dahira ASC LIMIT 12")->fetchAll();
} catch (Exception $e) { $dimanches = []; }
$idsDim = array_map(function ($d) { return (int) $d['id']; }, $dimanches);

$error = '';
$success = $_SESSION['dispo_flash'] ?? '';
unset($_SESSION['dispo_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        try {
            $choix = (array) ($_POST['dispo'] ?? []);
            $ins = $pdo->prepare("INSERT INTO commission_disponibilites (planning_id, membre_id, statut) VALUES (?,?,?)
                                  ON DUPLICATE KEY UPDATE statut = VALUES(statut), updated_at = NOW()");
            $del = $pdo->prepare("DELETE FROM commission_disponibilites WHERE planning_id = ? AND membre_id = ?");
            foreach ($idsDim as $pid) {
                $v = (string) ($choix[$pid] ?? '');
                if ($v === 'dispo' || $v === 'indispo') { $ins->execute([$pid, $membreId, $v]); }
                else { $del->execute([$pid, $membreId]); }
            }
            $_SESSION['dispo_flash'] = "Vos disponibilités ont été enregistrées.";
            header('Location: disponibilites.php');
            exit;
        } catch (Exception $e) {
            error_log('Touba Lyon disponibilites : ' . $e->getMessage());
            $error = "Une erreur technique est survenue.";
        }
    }
}

// Mes réponses
$mes = [];
try {
    $q = $pdo->prepare("SELECT planning_id, statut FROM commission_disponibilites WHERE membre_id = ?");
    $q->execute([$membreId]);
    foreach ($q as $r) { $mes[(int) $r['planning_id']] = (string) $r['statut']; }
} catch (Exception $e) {}

// Disponibilités de tous les membres, pour les dimanches affichés
$dispo = []; // [membre_id][planning_id] = statut
if (!empty($idsDim)) {
    try {
        $ph = implode(',', array_fill(0, count($idsDim), '?'));
        $q = $pdo->prepare("SELECT planning_id, membre_id, statut FROM commission_disponibilites WHERE planning_id IN ($ph)");
        $q->execute($idsDim);
        foreach ($q as $r) { $dispo[(int) $r['membre_id']][(int) $r['planning_id']] = (string) $r['statut']; }
    } catch (Exception $e) {}
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
        .dp-card { border-radius: 16px; padding: 1.1rem 1.3rem; margin-bottom: 1.2rem; }
        .dp-card h2 { margin: 0 0 0.3rem; font-size: 1.1rem; color: var(--white); }
        .dp-hint { color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.9rem; }
        .dp-row { display: flex; align-items: center; justify-content: space-between; gap: 0.7rem; flex-wrap: wrap; padding: 0.6rem 0.8rem; margin-bottom: 0.5rem; background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border); border-radius: 12px; }
        .dp-date { color: var(--white); font-weight: 700; }
        .dp-date small { display: block; color: var(--text-muted); font-weight: 500; }
        .dp-choix { display: flex; gap: 0.35rem; }
        .dp-choix input { position: absolute; opacity: 0; pointer-events: none; }
        .dp-choix label { padding: 0.4rem 0.8rem; border-radius: 50px; border: 1px solid var(--glass-border); color: var(--text-muted); font-size: 0.82rem; font-weight: 600; cursor: pointer; user-select: none; }
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

                <div class="glass-card dp-card">
                    <h2>Mes disponibilités pour les prochains Dahira</h2>
                    <p class="dp-hint">Indiquez pour chaque dimanche du planning si vous êtes disponible. Les membres de vos commissions voient ces réponses.</p>
                    <?php if (empty($dimanches)): ?>
                        <p style="color:var(--text-muted); margin:0;">Aucun Dahira n'est planifié pour le moment.</p>
                    <?php else: ?>
                    <form method="POST" action="disponibilites.php">
                        <?php echo csrf_field(); ?>
                        <?php foreach ($dimanches as $d): $pid = (int) $d['id']; $cur = $mes[$pid] ?? ''; ?>
                            <div class="dp-row">
                                <div class="dp-date"><?php echo $h(ucfirst(dahira_date_longue($d['date_dahira']))); ?><?php if (!empty($d['titre'])): ?><small><?php echo $h($d['titre']); ?></small><?php endif; ?></div>
                                <div class="dp-choix">
                                    <input type="radio" id="d<?php echo $pid; ?>-o" name="dispo[<?php echo $pid; ?>]" value="dispo"<?php echo $cur === 'dispo' ? ' checked' : ''; ?>><label class="l-dispo" for="d<?php echo $pid; ?>-o">✅ Disponible</label>
                                    <input type="radio" id="d<?php echo $pid; ?>-n" name="dispo[<?php echo $pid; ?>]" value="indispo"<?php echo $cur === 'indispo' ? ' checked' : ''; ?>><label class="l-indispo" for="d<?php echo $pid; ?>-n">❌ Indisponible</label>
                                    <input type="radio" id="d<?php echo $pid; ?>-x" name="dispo[<?php echo $pid; ?>]" value=""<?php echo $cur === '' ? ' checked' : ''; ?>><label class="l-nr" for="d<?php echo $pid; ?>-x">Non précisé</label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary" style="margin-top:0.6rem;">💾 Enregistrer mes disponibilités</button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (!empty($dimanches)): foreach ($mesCommissions as $c):
                    $groupes = dispo_groupes($pdo, (int) $c['id']);
                ?>
                <div class="glass-card dp-card">
                    <h2>📋 Planning des disponibilités — <?php echo $h($c['nom']); ?></h2>
                    <p class="dp-hint">✅ disponible · ❌ indisponible · — non précisé</p>
                    <div class="dp-table-wrap">
                    <table class="dp-table">
                        <thead>
                            <tr>
                                <th>Membre</th>
                                <?php foreach ($dimanches as $d): ?><th><?php echo date('d/m', strtotime($d['date_dahira'])); ?></th><?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $totaux = array_fill_keys($idsDim, 0); $vus = []; ?>
                        <?php foreach ($groupes as $g): ?>
                            <tr class="dp-grp"><td colspan="<?php echo count($dimanches) + 1; ?>"><?php echo $h($g[0]); ?> (<?php echo count($g[1]); ?>)</td></tr>
                            <?php if (empty($g[1])): ?>
                                <tr><td colspan="<?php echo count($dimanches) + 1; ?>" class="dp-nr" style="text-align:left;">Aucun membre.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($g[1] as $m): $mid = (int) $m['id']; ?>
                                <tr<?php echo $mid === $membreId ? ' class="dp-me"' : ''; ?>>
                                    <td><?php echo $h(trim($m['prenom'] . ' ' . $m['nom'])); ?></td>
                                    <?php foreach ($dimanches as $d): $pid = (int) $d['id']; $s = $dispo[$mid][$pid] ?? '';
                                        // Un membre présent dans plusieurs sous-commissions n'est compté qu'une fois
                                        if ($s === 'dispo' && empty($vus[$mid][$pid])) { $totaux[$pid]++; }
                                        $vus[$mid][$pid] = true;
                                    ?>
                                        <td><?php echo $s === 'dispo' ? '<span class="dp-ok">✅</span>' : ($s === 'indispo' ? '<span class="dp-ko">❌</span>' : '<span class="dp-nr">—</span>'); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr><td>Disponibles</td><?php foreach ($dimanches as $d): ?><td><?php echo (int) $totaux[(int) $d['id']]; ?></td><?php endforeach; ?></tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
                <?php endforeach; endif; ?>

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
</body>
</html>
