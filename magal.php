<?php
/**
 * Touba Lyon 2026 - 🕌 Wadial Magal
 *
 * Préparation annuelle du Wadial Magal : commissions impliquées, besoins par
 * commission, tâches par commission et gestion financière.
 * Accès : administrateurs, organisateurs (responsables Secrétariat / Finance /
 * Magal) et membres des commissions impliquées.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/notification_helper.php'; // troba_notify_membre()
require_once __DIR__ . '/magal_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__mgAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$mgMembre = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;
if (!$__mgAdmin && $mgMembre === 0) {
    header('Location: login.php');
    exit;
}
$mgOrganisateur = $__mgAdmin || magal_est_organisateur($pdo, $mgMembre);
$mgFinance = $__mgAdmin || magal_est_finance($pdo, $mgMembre);
if (!$mgOrganisateur && !magal_membre_implique($pdo, $mgMembre)) {
    header('Location: index.php');
    exit;
}

// Commissions dont le membre est responsable (droit d'édition de leurs besoins / tâches)
$mgGeres = [];
if ($mgMembre > 0) {
    try {
        $mgGeres = array_map('intval', $pdo->query("SELECT commission_id FROM commission_gestionnaires WHERE membre_id = " . $mgMembre)->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { $mgGeres = []; }
}
// Commissions dont le membre fait partie
$mgMembreDe = $mgGeres;
if ($mgMembre > 0) {
    try {
        $mgMembreDe = array_values(array_unique(array_merge($mgGeres, array_map('intval', $pdo->query("SELECT commission_id FROM commission_membres WHERE membre_id = " . $mgMembre)->fetchAll(PDO::FETCH_COLUMN)))));
    } catch (Exception $e) {}
}
$mgPeutEditer = function (int $cid) use ($mgOrganisateur, $mgGeres) {
    return $mgOrganisateur || in_array($cid, $mgGeres, true);
};

// Édition (année) et onglet
$anneeCourante = magal_annee_defaut(); // édition proposée par défaut (à partir de 2027)
$annee = (int) ($_GET['annee'] ?? $_POST['annee'] ?? $anneeCourante);
if ($annee < MAGAL_PREMIERE_ANNEE || $annee > $anneeCourante + 3) { $annee = $anneeCourante; }
$onglets = ['commissions' => '📋 Commissions', 'besoins' => '🧰 Besoins', 'taches' => '✅ Tâches'];
if ($mgOrganisateur || $mgFinance) { $onglets['finance'] = '💶 Finance'; }
$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'commissions');
if (!isset($onglets[$tab])) { $tab = 'commissions'; }

$error = '';
$success = $_SESSION['magal_flash'] ?? '';
unset($_SESSION['magal_flash']);

/** Redirection après action (évite le renvoi du formulaire). */
function magal_redirect(int $annee, string $tab, string $msg = ''): void
{
    if ($msg !== '') { $_SESSION['magal_flash'] = $msg; }
    header('Location: magal.php?annee=' . $annee . '&tab=' . urlencode($tab));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $cid = (int) ($_POST['commission_id'] ?? 0);
        try {
            // Une commission doit être impliquée dans l'édition pour recevoir besoins / tâches
            $impliquee = false;
            if ($cid > 0) {
                $q = $pdo->prepare("SELECT 1 FROM magal_commissions WHERE annee = ? AND commission_id = ?");
                $q->execute([$annee, $cid]);
                $impliquee = ($q->fetchColumn() !== false);
            }

            if ($action === 'add_commission') {
                if (!$mgOrganisateur) { $error = "Action réservée aux organisateurs."; }
                else {
                    $ex = $pdo->prepare("SELECT nom FROM commissions WHERE id = ?");
                    $ex->execute([$cid]);
                    $cnom = $ex->fetchColumn();
                    if ($cnom === false) { $error = "Commission introuvable."; }
                    else {
                        $ins = $pdo->prepare("INSERT IGNORE INTO magal_commissions (annee, commission_id) VALUES (?, ?)");
                        $ins->execute([$annee, $cid]);
                        if ($ins->rowCount() > 0) {
                            $resp = $pdo->prepare("SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?");
                            $resp->execute([$cid]);
                            foreach ($resp->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                                troba_notify_membre($pdo, (int) $rid, 'magal', '🕌 Wadial Magal ' . $annee, 'Votre commission « ' . $cnom . ' » est impliquée dans le Wadial Magal ' . $annee . '. Renseignez vos besoins et vos tâches.', 'magal.php?annee=' . $annee . '&tab=besoins');
                            }
                        }
                        magal_redirect($annee, 'commissions', "Commission « " . $cnom . " » ajoutée au Wadial Magal " . $annee . ".");
                    }
                }
            } elseif ($action === 'remove_commission') {
                if (!$mgOrganisateur) { $error = "Action réservée aux organisateurs."; }
                else {
                    $pdo->prepare("DELETE FROM magal_besoins WHERE annee = ? AND commission_id = ?")->execute([$annee, $cid]);
                    $pdo->prepare("DELETE FROM magal_taches WHERE annee = ? AND commission_id = ?")->execute([$annee, $cid]);
                    $pdo->prepare("DELETE FROM magal_commissions WHERE annee = ? AND commission_id = ?")->execute([$annee, $cid]);
                    magal_redirect($annee, 'commissions', "Commission retirée du Wadial Magal " . $annee . ".");
                }
            } elseif ($action === 'add_besoin') {
                $lib = trim($_POST['libelle'] ?? '');
                $qte = trim($_POST['quantite'] ?? '');
                if (!$impliquee || !$mgPeutEditer($cid)) { $error = "Action non autorisée."; }
                elseif ($lib === '') { $error = "Le besoin est obligatoire."; }
                else {
                    $pdo->prepare("INSERT INTO magal_besoins (annee, commission_id, libelle, quantite, created_by) VALUES (?,?,?,?,?)")
                        ->execute([$annee, $cid, mb_substr($lib, 0, 255), ($qte !== '' ? mb_substr($qte, 0, 60) : null), $mgMembre ?: null]);
                    magal_redirect($annee, 'besoins', "Besoin ajouté.");
                }
            } elseif ($action === 'set_besoin_statut' || $action === 'delete_besoin') {
                $bid = (int) ($_POST['id'] ?? 0);
                $br = $pdo->prepare("SELECT commission_id FROM magal_besoins WHERE id = ? AND annee = ?");
                $br->execute([$bid, $annee]);
                $bcid = (int) $br->fetchColumn();
                if ($bcid <= 0 || !$mgPeutEditer($bcid)) { $error = "Action non autorisée."; }
                elseif ($action === 'delete_besoin') {
                    $pdo->prepare("DELETE FROM magal_besoins WHERE id = ?")->execute([$bid]);
                    magal_redirect($annee, 'besoins', "Besoin supprimé.");
                } else {
                    $st = (string) ($_POST['statut'] ?? '');
                    if (!isset(magal_statuts_besoin()[$st])) { $error = "Statut invalide."; }
                    else {
                        $pdo->prepare("UPDATE magal_besoins SET statut = ? WHERE id = ?")->execute([$st, $bid]);
                        magal_redirect($annee, 'besoins', "Statut du besoin mis à jour.");
                    }
                }
            } elseif ($action === 'add_tache') {
                $titre = trim($_POST['titre'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $ass = (int) ($_POST['assignee_id'] ?? 0);
                $ech = trim($_POST['echeance'] ?? '');
                if (!$impliquee || !$mgPeutEditer($cid)) { $error = "Action non autorisée."; }
                elseif ($titre === '') { $error = "Le titre de la tâche est obligatoire."; }
                else {
                    if ($ech !== '' && !DateTime::createFromFormat('Y-m-d', $ech)) { $ech = ''; }
                    // Le responsable doit appartenir à la commission
                    if ($ass > 0) {
                        $ok = $pdo->prepare("SELECT 1 FROM (SELECT membre_id FROM commission_membres WHERE commission_id = ? UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?) x WHERE membre_id = ?");
                        $ok->execute([$cid, $cid, $ass]);
                        if ($ok->fetchColumn() === false) { $ass = 0; }
                    }
                    $pdo->prepare("INSERT INTO magal_taches (annee, commission_id, titre, description, assignee_id, echeance, created_by) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$annee, $cid, mb_substr($titre, 0, 255), ($desc !== '' ? $desc : null), ($ass > 0 ? $ass : null), ($ech !== '' ? $ech : null), $mgMembre ?: null]);
                    if ($ass > 0 && $ass !== $mgMembre) {
                        troba_notify_membre($pdo, $ass, 'magal', '🕌 Wadial Magal : nouvelle tâche', 'Une tâche vous est confiée : ' . $titre, 'magal.php?annee=' . $annee . '&tab=taches');
                    }
                    magal_redirect($annee, 'taches', "Tâche ajoutée.");
                }
            } elseif ($action === 'set_tache_statut' || $action === 'delete_tache') {
                $tid = (int) ($_POST['id'] ?? 0);
                $tr = $pdo->prepare("SELECT commission_id, assignee_id FROM magal_taches WHERE id = ? AND annee = ?");
                $tr->execute([$tid, $annee]);
                $trow = $tr->fetch();
                $tcid = $trow ? (int) $trow['commission_id'] : 0;
                $estAssigne = $trow && $mgMembre > 0 && (int) $trow['assignee_id'] === $mgMembre;
                if ($tcid <= 0) { $error = "Tâche introuvable."; }
                elseif ($action === 'delete_tache') {
                    if (!$mgPeutEditer($tcid)) { $error = "Action non autorisée."; }
                    else {
                        $pdo->prepare("DELETE FROM magal_taches WHERE id = ?")->execute([$tid]);
                        magal_redirect($annee, 'taches', "Tâche supprimée.");
                    }
                } else {
                    // Le responsable de la tâche peut aussi en changer le statut
                    $st = (string) ($_POST['statut'] ?? '');
                    if (!$mgPeutEditer($tcid) && !$estAssigne) { $error = "Action non autorisée."; }
                    elseif (!isset(magal_statuts_tache()[$st])) { $error = "Statut invalide."; }
                    else {
                        $pdo->prepare("UPDATE magal_taches SET statut = ?, updated_at = NOW() WHERE id = ?")->execute([$st, $tid]);
                        magal_redirect($annee, 'taches', "Statut de la tâche mis à jour.");
                    }
                }
            } elseif ($action === 'add_finance' || $action === 'delete_finance') {
                if (!$mgFinance) { $error = "Action réservée à la Finance."; }
                elseif ($action === 'delete_finance') {
                    $pdo->prepare("DELETE FROM magal_finance WHERE id = ? AND annee = ?")->execute([(int) ($_POST['id'] ?? 0), $annee]);
                    magal_redirect($annee, 'finance', "Ligne supprimée.");
                } else {
                    $type = ($_POST['type'] ?? '') === 'recette' ? 'recette' : 'depense';
                    $lib = trim($_POST['libelle'] ?? '');
                    $mt = str_replace(',', '.', trim($_POST['montant'] ?? ''));
                    $dop = trim($_POST['date_op'] ?? '');
                    $note = trim($_POST['note'] ?? '');
                    if ($lib === '') { $error = "Le libellé est obligatoire."; }
                    elseif (!is_numeric($mt) || (float) $mt <= 0) { $error = "Le montant doit être un nombre positif."; }
                    else {
                        if ($dop !== '' && !DateTime::createFromFormat('Y-m-d', $dop)) { $dop = ''; }
                        $pdo->prepare("INSERT INTO magal_finance (annee, type, libelle, montant, commission_id, date_op, note, created_by) VALUES (?,?,?,?,?,?,?,?)")
                            ->execute([$annee, $type, mb_substr($lib, 0, 255), round((float) $mt, 2), ($cid > 0 ? $cid : null), ($dop !== '' ? $dop : null), ($note !== '' ? $note : null), $mgMembre ?: null]);
                        magal_redirect($annee, 'finance', ($type === 'recette' ? "Recette" : "Dépense") . " enregistrée.");
                    }
                }
            } else {
                $error = "Action non reconnue.";
            }
        } catch (Exception $e) {
            error_log('Touba Lyon magal : ' . $e->getMessage());
            $error = "Une erreur technique est survenue.";
        }
    }
}

// ── Données de la page ──
$toutesCommissions = [];
try { $toutesCommissions = $pdo->query("SELECT id, nom FROM commissions ORDER BY nom ASC")->fetchAll(); } catch (Exception $e) {}
$nomCommission = [];
foreach ($toutesCommissions as $c) { $nomCommission[(int) $c['id']] = (string) $c['nom']; }

$impliquees = [];
try {
    $q = $pdo->prepare("SELECT commission_id FROM magal_commissions WHERE annee = ?");
    $q->execute([$annee]);
    $impliquees = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {}
// Tri par nom
usort($impliquees, function ($a, $b) use ($nomCommission) { return strcasecmp($nomCommission[$a] ?? '', $nomCommission[$b] ?? ''); });
// Un membre simple ne voit le détail (besoins / tâches) que de ses commissions
$visibles = $mgOrganisateur ? $impliquees : array_values(array_filter($impliquees, function ($c) use ($mgMembreDe) { return in_array($c, $mgMembreDe, true); }));

$besoins = [];
$taches = [];
try {
    $q = $pdo->prepare("SELECT * FROM magal_besoins WHERE annee = ? ORDER BY FIELD(statut,'a_trouver','en_cours','obtenu'), id ASC");
    $q->execute([$annee]);
    foreach ($q as $r) { $besoins[(int) $r['commission_id']][] = $r; }
    $q = $pdo->prepare("SELECT t.*, CONCAT(m.prenom, ' ', m.nom) AS assignee_nom FROM magal_taches t LEFT JOIN membres m ON m.id = t.assignee_id WHERE t.annee = ? ORDER BY FIELD(t.statut,'en_cours','a_faire','fait'), t.echeance IS NULL, t.echeance ASC, t.id ASC");
    $q->execute([$annee]);
    foreach ($q as $r) { $taches[(int) $r['commission_id']][] = $r; }
} catch (Exception $e) {}

// Membres de chaque commission visible (pour attribuer les tâches)
$membresCom = [];
if ($tab === 'taches') {
    foreach ($visibles as $cid) {
        try {
            $q = $pdo->prepare("SELECT DISTINCT m.id, m.prenom, m.nom FROM membres m WHERE m.status = 'approved' AND m.id IN (SELECT membre_id FROM commission_membres WHERE commission_id = ? UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?) ORDER BY m.prenom, m.nom");
            $q->execute([$cid, $cid]);
            $membresCom[$cid] = $q->fetchAll();
        } catch (Exception $e) { $membresCom[$cid] = []; }
    }
}

// Finance
$lignes = [];
$totRecettes = 0.0;
$totDepenses = 0.0;
$parCommission = [];
if ($tab === 'finance' && ($mgOrganisateur || $mgFinance)) {
    try {
        $q = $pdo->prepare("SELECT * FROM magal_finance WHERE annee = ? ORDER BY COALESCE(date_op, DATE(created_at)) DESC, id DESC");
        $q->execute([$annee]);
        $lignes = $q->fetchAll();
    } catch (Exception $e) {}
    foreach ($lignes as $l) {
        $m = (float) $l['montant'];
        if ($l['type'] === 'recette') { $totRecettes += $m; }
        else {
            $totDepenses += $m;
            $k = (int) ($l['commission_id'] ?? 0);
            $parCommission[$k] = ($parCommission[$k] ?? 0) + $m;
        }
    }
}
$eur = function (float $v) { return number_format($v, 2, ',', ' ') . ' €'; };
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
$stBesoin = magal_statuts_besoin();
$stTache = magal_statuts_tache();
$aujourdhui = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🕌 Wadial Magal — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .mg-wrap { max-width: 980px; margin: 2rem auto; }
        .mg-tabs { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 1.2rem; }
        .mg-tabs a { padding: 0.5rem 1rem; border-radius: 12px; border: 1px solid var(--glass-border); color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.9rem; }
        .mg-tabs a.active { background: rgba(212,175,55,0.15); border-color: rgba(212,175,55,0.6); color: var(--gold); }
        .mg-card { border-radius: 12px; padding: 1.1rem 1.3rem; margin-bottom: 1rem; }
        .mg-card h3 { margin: 0 0 0.7rem; color: var(--white); font-size: 1.05rem; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .mg-count { font-size: 0.72rem; font-weight: 800; color: #0c241a; background: linear-gradient(135deg,#d4af37,#f1d279); border-radius: 12px; padding: 2px 10px; }
        .mg-row { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.5rem 0.8rem; margin-bottom: 0.45rem; }
        .mg-row .mg-main { flex: 1; min-width: 160px; }
        .mg-row .mg-sub { color: var(--text-muted); font-size: 0.78rem; margin-top: 2px; }
        .mg-row.done .mg-main strong { text-decoration: line-through; opacity: 0.6; }
        .mg-form { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; margin-top: 0.7rem; }
        .mg-form .form-group { margin: 0; flex: 1; min-width: 150px; }
        .mg-sel { background: rgba(255,255,255,0.06); border: 1px solid var(--glass-border); color: var(--white); border-radius: 8px; padding: 0.3rem 0.5rem; font-size: 0.82rem; color-scheme: dark; }
        .mg-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.7rem; margin-bottom: 1.1rem; }
        .mg-stat { border-radius: 10px; padding: 0.8rem 1rem; text-align: center; }
        .mg-stat .v { font-size: 1.4rem; font-weight: 800; color: var(--white); }
        .mg-stat .l { color: var(--text-muted); font-size: 0.78rem; }
        .mg-badge { font-size: 0.72rem; padding: 0.15rem 0.55rem; border-radius: 12px; white-space: nowrap; border: 1px solid; }
        .mg-b-ok { background: rgba(37,211,102,0.15); color: #7bd8a6; border-color: rgba(37,211,102,0.4); }
        .mg-b-att { background: rgba(255,193,7,0.15); color: #ffd873; border-color: rgba(255,193,7,0.4); }
        .mg-b-info { background: rgba(45,140,255,0.15); color: #8ec5ff; border-color: rgba(45,140,255,0.4); }
        .mg-b-red { background: rgba(191,33,33,0.15); color: #fca5a5; border-color: rgba(191,33,33,0.45); }
        @media (max-width: 600px) { .mg-form .form-group { min-width: 100%; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__mgAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">
        <div class="mg-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.25rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:1rem;">
                <span>🕌 Wadial Magal — <strong class="gold-text"><?php echo (int) $annee; ?></strong></span>
                <form method="GET" action="magal.php" style="margin:0; display:flex; align-items:center; gap:0.5rem;">
                    <input type="hidden" name="tab" value="<?php echo $h($tab); ?>">
                    <label for="mg-annee" style="color:var(--text-muted); font-size:0.85rem;">Édition</label>
                    <select id="mg-annee" name="annee" class="mg-sel" onchange="this.form.submit()">
                        <?php for ($y = MAGAL_PREMIERE_ANNEE; $y <= $anneeCourante + 2; $y++): ?>
                            <option value="<?php echo $y; ?>"<?php echo $y === $annee ? ' selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>

            <nav class="mg-tabs">
                <?php foreach ($onglets as $k => $lbl): ?>
                    <a href="magal.php?annee=<?php echo (int) $annee; ?>&tab=<?php echo $h($k); ?>"<?php echo $k === $tab ? ' class="active"' : ''; ?>><?php echo $lbl; ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'commissions'): ?>
                <div class="glass-card mg-card">
                    <h3>📋 Commissions impliquées <span class="mg-count"><?php echo count($impliquees); ?></span></h3>
                    <?php if (empty($impliquees)): ?>
                        <p style="color:var(--text-muted); margin:0;">Aucune commission impliquée pour l'édition <?php echo (int) $annee; ?>.</p>
                    <?php endif; ?>
                    <?php foreach ($impliquees as $cid):
                        $nb = count($besoins[$cid] ?? []);
                        $nbObt = count(array_filter($besoins[$cid] ?? [], function ($b) { return $b['statut'] === 'obtenu'; }));
                        $nt = count($taches[$cid] ?? []);
                        $ntFait = count(array_filter($taches[$cid] ?? [], function ($t) { return $t['statut'] === 'fait'; }));
                    ?>
                        <div class="mg-row">
                            <div class="mg-main">
                                <strong><?php echo $h($nomCommission[$cid] ?? ('Commission #' . $cid)); ?></strong>
                                <div class="mg-sub">🧰 <?php echo $nbObt; ?>/<?php echo $nb; ?> besoin(s) obtenu(s) · ✅ <?php echo $ntFait; ?>/<?php echo $nt; ?> tâche(s) faite(s)</div>
                            </div>
                            <?php if ($mgOrganisateur): ?>
                            <form method="POST" action="magal.php" style="margin:0;" data-confirm="Retirer « <?php echo $h($nomCommission[$cid] ?? ''); ?> » du Wadial Magal <?php echo (int) $annee; ?> ? Ses besoins et tâches seront supprimés." data-confirm-danger="1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="remove_commission">
                                <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                                <input type="hidden" name="commission_id" value="<?php echo (int) $cid; ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;">Retirer</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($mgOrganisateur):
                        $dispo = array_filter($toutesCommissions, function ($c) use ($impliquees) { return !in_array((int) $c['id'], $impliquees, true); });
                    ?>
                        <?php if (!empty($dispo)): ?>
                        <form method="POST" action="magal.php" class="mg-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="add_commission">
                            <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                            <div class="form-group">
                                <label class="form-label">➕ Impliquer une commission</label>
                                <select name="commission_id" class="form-input" required>
                                    <option value="">— Choisir —</option>
                                    <?php foreach ($dispo as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo $h($c['nom']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Ajouter</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            <?php elseif ($tab === 'besoins'): ?>
                <?php if (empty($visibles)): ?>
                    <div class="glass-card mg-card"><p style="color:var(--text-muted); margin:0;">Aucune commission impliquée pour le moment.</p></div>
                <?php endif; ?>
                <?php foreach ($visibles as $cid): $edit = $mgPeutEditer($cid); $liste = $besoins[$cid] ?? []; ?>
                    <div class="glass-card mg-card">
                        <h3>🧰 <?php echo $h($nomCommission[$cid] ?? ''); ?> <span class="mg-count"><?php echo count($liste); ?></span></h3>
                        <?php if (empty($liste)): ?><p style="color:var(--text-muted); margin:0 0 0.4rem;">Aucun besoin renseigné.</p><?php endif; ?>
                        <?php foreach ($liste as $b): $bc = $b['statut'] === 'obtenu' ? 'mg-b-ok' : ($b['statut'] === 'en_cours' ? 'mg-b-info' : 'mg-b-att'); ?>
                            <div class="mg-row<?php echo $b['statut'] === 'obtenu' ? ' done' : ''; ?>">
                                <div class="mg-main"><strong><?php echo $h($b['libelle']); ?></strong><?php if (!empty($b['quantite'])): ?><div class="mg-sub">Quantité : <?php echo $h($b['quantite']); ?></div><?php endif; ?></div>
                                <?php if ($edit): ?>
                                <form method="POST" action="magal.php" style="margin:0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="set_besoin_statut">
                                    <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
                                    <select name="statut" class="mg-sel" onchange="this.form.submit()">
                                        <?php foreach ($stBesoin as $k => $lbl): ?><option value="<?php echo $k; ?>"<?php echo $b['statut'] === $k ? ' selected' : ''; ?>><?php echo $h($lbl); ?></option><?php endforeach; ?>
                                    </select>
                                </form>
                                <form method="POST" action="magal.php" style="margin:0;" data-confirm="Supprimer ce besoin ?" data-confirm-danger="1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_besoin">
                                    <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;" title="Supprimer">🗑️</button>
                                </form>
                                <?php else: ?>
                                    <span class="mg-badge <?php echo $bc; ?>"><?php echo $h($stBesoin[$b['statut']] ?? $b['statut']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($edit): ?>
                        <form method="POST" action="magal.php" class="mg-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="add_besoin">
                            <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                            <input type="hidden" name="commission_id" value="<?php echo (int) $cid; ?>">
                            <div class="form-group" style="flex:2;"><label class="form-label">Besoin</label><input type="text" name="libelle" class="form-input" required maxlength="255" placeholder="Ex : 20 tables, sono, vaisselle…"></div>
                            <div class="form-group"><label class="form-label">Quantité (facultatif)</label><input type="text" name="quantite" class="form-input" maxlength="60" placeholder="Ex : 20"></div>
                            <button type="submit" class="btn btn-primary btn-sm">➕ Ajouter</button>
                        </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

            <?php elseif ($tab === 'taches'): ?>
                <?php if (empty($visibles)): ?>
                    <div class="glass-card mg-card"><p style="color:var(--text-muted); margin:0;">Aucune commission impliquée pour le moment.</p></div>
                <?php endif; ?>
                <?php foreach ($visibles as $cid): $edit = $mgPeutEditer($cid); $liste = $taches[$cid] ?? []; ?>
                    <div class="glass-card mg-card">
                        <h3>✅ <?php echo $h($nomCommission[$cid] ?? ''); ?> <span class="mg-count"><?php echo count($liste); ?></span></h3>
                        <?php if (empty($liste)): ?><p style="color:var(--text-muted); margin:0 0 0.4rem;">Aucune tâche.</p><?php endif; ?>
                        <?php foreach ($liste as $t):
                            $retard = $t['statut'] !== 'fait' && !empty($t['echeance']) && $t['echeance'] < $aujourdhui;
                            $estAssigne = $mgMembre > 0 && (int) $t['assignee_id'] === $mgMembre;
                            $tc = $t['statut'] === 'fait' ? 'mg-b-ok' : ($t['statut'] === 'en_cours' ? 'mg-b-info' : 'mg-b-att');
                        ?>
                            <div class="mg-row<?php echo $t['statut'] === 'fait' ? ' done' : ''; ?>">
                                <div class="mg-main">
                                    <strong><?php echo $h($t['titre']); ?></strong>
                                    <div class="mg-sub">
                                        👤 <?php echo !empty(trim((string) $t['assignee_nom'])) ? $h($t['assignee_nom']) : 'Non attribuée'; ?>
                                        <?php if (!empty($t['echeance'])): ?> · 📅 <span<?php echo $retard ? ' style="color:#fca5a5; font-weight:700;"' : ''; ?>><?php echo date('d/m/Y', strtotime($t['echeance'])); ?><?php echo $retard ? ' (en retard)' : ''; ?></span><?php endif; ?>
                                    </div>
                                    <?php if (!empty($t['description'])): ?><div class="mg-sub"><?php echo nl2br($h($t['description'])); ?></div><?php endif; ?>
                                </div>
                                <?php if ($edit || $estAssigne): ?>
                                <form method="POST" action="magal.php" style="margin:0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="set_tache_statut">
                                    <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>">
                                    <select name="statut" class="mg-sel" onchange="this.form.submit()">
                                        <?php foreach ($stTache as $k => $lbl): ?><option value="<?php echo $k; ?>"<?php echo $t['statut'] === $k ? ' selected' : ''; ?>><?php echo $h($lbl); ?></option><?php endforeach; ?>
                                    </select>
                                </form>
                                <?php else: ?>
                                    <span class="mg-badge <?php echo $tc; ?>"><?php echo $h($stTache[$t['statut']] ?? $t['statut']); ?></span>
                                <?php endif; ?>
                                <?php if ($edit): ?>
                                <form method="POST" action="magal.php" style="margin:0;" data-confirm="Supprimer cette tâche ?" data-confirm-danger="1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_tache">
                                    <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;" title="Supprimer">🗑️</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($edit): ?>
                        <form method="POST" action="magal.php" class="mg-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="add_tache">
                            <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                            <input type="hidden" name="commission_id" value="<?php echo (int) $cid; ?>">
                            <div class="form-group" style="flex:2;"><label class="form-label">Tâche</label><input type="text" name="titre" class="form-input" required maxlength="255" placeholder="Ex : Réserver la salle"></div>
                            <div class="form-group"><label class="form-label">Responsable</label>
                                <select name="assignee_id" class="form-input">
                                    <option value="0">— Non attribuée —</option>
                                    <?php foreach (($membresCom[$cid] ?? []) as $m): ?><option value="<?php echo (int) $m['id']; ?>"><?php echo $h(trim($m['prenom'] . ' ' . $m['nom'])); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label class="form-label">Échéance</label><input type="date" name="echeance" class="form-input"></div>
                            <div class="form-group" style="flex-basis:100%;"><label class="form-label">Détails (facultatif)</label><input type="text" name="description" class="form-input" placeholder="Précisions"></div>
                            <button type="submit" class="btn btn-primary btn-sm">➕ Ajouter</button>
                        </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

            <?php elseif ($tab === 'finance'): ?>
                <div class="mg-stats">
                    <div class="glass-card mg-stat"><div class="v" style="color:#7bd8a6;"><?php echo $eur($totRecettes); ?></div><div class="l">📥 Recettes</div></div>
                    <div class="glass-card mg-stat"><div class="v" style="color:#fca5a5;"><?php echo $eur($totDepenses); ?></div><div class="l">📤 Dépenses</div></div>
                    <?php $solde = $totRecettes - $totDepenses; ?>
                    <div class="glass-card mg-stat"><div class="v" style="color:<?php echo $solde >= 0 ? '#ffd873' : '#fca5a5'; ?>;"><?php echo $eur($solde); ?></div><div class="l">⚖️ Solde</div></div>
                </div>

                <?php if ($mgFinance): ?>
                <div class="glass-card mg-card">
                    <h3>➕ Nouvelle opération</h3>
                    <form method="POST" action="magal.php" class="mg-form" style="margin-top:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_finance">
                        <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                        <div class="form-group"><label class="form-label">Type</label>
                            <select name="type" class="form-input"><option value="depense">📤 Dépense</option><option value="recette">📥 Recette</option></select>
                        </div>
                        <div class="form-group" style="flex:2;"><label class="form-label">Libellé</label><input type="text" name="libelle" class="form-input" required maxlength="255" placeholder="Ex : Location de salle"></div>
                        <div class="form-group"><label class="form-label">Montant (€)</label><input type="text" name="montant" class="form-input" required inputmode="decimal" placeholder="0,00"></div>
                        <div class="form-group"><label class="form-label">Commission</label>
                            <select name="commission_id" class="form-input">
                                <option value="0">— Aucune —</option>
                                <?php foreach ($impliquees as $cid): ?><option value="<?php echo (int) $cid; ?>"><?php echo $h($nomCommission[$cid] ?? ''); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Date</label><input type="date" name="date_op" class="form-input" value="<?php echo date('Y-m-d'); ?>"></div>
                        <div class="form-group" style="flex-basis:100%;"><label class="form-label">Note (facultatif)</label><input type="text" name="note" class="form-input"></div>
                        <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if (!empty($parCommission)): ?>
                <div class="glass-card mg-card">
                    <h3>📊 Dépenses par commission</h3>
                    <?php arsort($parCommission); foreach ($parCommission as $cid => $tot): ?>
                        <div class="mg-row"><div class="mg-main"><strong><?php echo $cid > 0 ? $h($nomCommission[$cid] ?? ('Commission #' . $cid)) : 'Hors commission'; ?></strong></div><span class="mg-badge mg-b-red"><?php echo $eur($tot); ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="glass-card mg-card">
                    <h3>🧾 Opérations <span class="mg-count"><?php echo count($lignes); ?></span></h3>
                    <?php if (empty($lignes)): ?><p style="color:var(--text-muted); margin:0;">Aucune opération enregistrée pour <?php echo (int) $annee; ?>.</p><?php endif; ?>
                    <?php foreach ($lignes as $l): $rec = $l['type'] === 'recette'; ?>
                        <div class="mg-row">
                            <div class="mg-main">
                                <strong><?php echo $rec ? '📥' : '📤'; ?> <?php echo $h($l['libelle']); ?></strong>
                                <div class="mg-sub">
                                    <?php echo !empty($l['date_op']) ? date('d/m/Y', strtotime($l['date_op'])) : date('d/m/Y', strtotime($l['created_at'])); ?>
                                    <?php if (!empty($l['commission_id'])): ?> · <?php echo $h($nomCommission[(int) $l['commission_id']] ?? ''); ?><?php endif; ?>
                                    <?php if (!empty($l['note'])): ?> · <?php echo $h($l['note']); ?><?php endif; ?>
                                </div>
                            </div>
                            <span class="mg-badge <?php echo $rec ? 'mg-b-ok' : 'mg-b-red'; ?>"><?php echo $rec ? '+' : '−'; ?><?php echo $eur((float) $l['montant']); ?></span>
                            <?php if ($mgFinance): ?>
                            <form method="POST" action="magal.php" style="margin:0;" data-confirm="Supprimer cette opération ?" data-confirm-danger="1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_finance">
                                <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;" title="Supprimer">🗑️</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
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
<script src="modern-date.js" defer></script>
</body>
</html>
