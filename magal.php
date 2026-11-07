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
require_once __DIR__ . '/fonction_responsables_helper.php'; // responsables du Wadial Magal (comme Lecture Coran)

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__mgAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$mgMembre = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;
if (!$__mgAdmin && $mgMembre === 0) {
    header('Location: login.php');
    exit;
}
$mgOrganisateur = $__mgAdmin || magal_est_responsable($pdo, $mgMembre); // seuls les responsables désignés (et l'administrateur) ont accès
$mgFinance = $__mgAdmin || magal_est_finance($pdo, $mgMembre);
if (!$mgOrganisateur && !magal_membre_a_acces($pdo, $mgMembre)) {
    header('Location: index.php');
    exit;
}
// Gestion des responsables du Wadial Magal (appels AJAX de la fenêtre de sélection)
fr_traiter_post($pdo, 'magal', $mgOrganisateur, $mgMembre, $__mgAdmin, 'Wadial Magal');

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
// Commissions pour lesquelles le membre a été autorisé (par un responsable de commission) à créer des besoins / tâches
$mgContrib = [];
if ($mgMembre > 0) {
    try { $mgContrib = array_map('intval', $pdo->query("SELECT commission_id FROM magal_contributeurs WHERE membre_id = " . $mgMembre)->fetchAll(PDO::FETCH_COLUMN)); } catch (Exception $e) { $mgContrib = []; }
}
$mgMembreDe = array_values(array_unique(array_merge($mgMembreDe, $mgContrib)));
$mgPeutEditer = function (int $cid) use ($mgOrganisateur, $mgGeres) {
    return $mgOrganisateur || in_array($cid, $mgGeres, true);
};
// Créer des besoins / tâches : organisateurs, responsables de la commission et membres qu'ils ont autorisés
$mgPeutCreer = function (int $cid) use ($mgPeutEditer, $mgContrib) {
    return $mgPeutEditer($cid) || in_array($cid, $mgContrib, true);
};

// Édition (année) et onglet
$anneeCourante = magal_annee_defaut(); // édition proposée par défaut (à partir de 2027)
$annee = (int) ($_GET['annee'] ?? $_POST['annee'] ?? $anneeCourante);
$anneeMax = $anneeCourante + 10; // liste des éditions : de 2027 à dix ans après l'année en cours
if ($annee < MAGAL_PREMIERE_ANNEE || $annee > $anneeMax) { $annee = $anneeCourante; }
// Accueil, ouvert par défaut : les besoins et tâches en cours, par statut
$onglets = ['accueil' => '🏠 Accueil'];
if ($mgOrganisateur) { $onglets['commissions'] = '📋 Commissions'; }
$onglets['besoins'] = '🧰 Besoins';
$onglets['taches'] = '✅ Tâches';
if ($mgOrganisateur || $mgFinance) { $onglets['finance'] = '💶 Finance'; }
$tabDefaut = (string) array_key_first($onglets);
$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? $tabDefaut);
if (!isset($onglets[$tab])) { $tab = $tabDefaut; }

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
            } elseif ($action === 'magal_set_contrib') {
                // Un responsable de la commission (ou un organisateur) autorise ou retire un membre : créer besoins et tâches de cette commission
                $rid = (int) ($_POST['membre_id'] ?? 0);
                $actif = (($_POST['enable'] ?? '') === '1');
                if (!$impliquee || !$mgPeutEditer($cid)) { $error = "Action non autorisée."; }
                else {
                    $okM = $pdo->prepare("SELECT 1 FROM (SELECT membre_id FROM commission_membres WHERE commission_id = ? UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?) x WHERE membre_id = ?");
                    $okM->execute([$cid, $cid, $rid]);
                    if ($rid <= 0 || $okM->fetchColumn() === false) { $error = "Ce membre ne fait pas partie de la commission."; }
                    elseif ($actif) {
                        $ins = $pdo->prepare("INSERT IGNORE INTO magal_contributeurs (commission_id, membre_id, ajoute_par) VALUES (?, ?, ?)");
                        $ins->execute([$cid, $rid, $mgMembre > 0 ? $mgMembre : null]);
                        if ($ins->rowCount() > 0 && $rid !== $mgMembre) {
                            $nmC = $pdo->prepare("SELECT nom FROM commissions WHERE id = ?"); $nmC->execute([$cid]);
                            troba_notify_membre($pdo, $rid, 'magal', '🕌 Wadial Magal : vous pouvez créer des besoins et tâches', 'Pour la commission « ' . (string) $nmC->fetchColumn() . ' » : la fonction Wadial Magal est dans votre menu.', 'magal.php?annee=' . $annee . '&tab=besoins');
                        }
                        magal_redirect($annee, 'besoins', "Membre autorisé à créer des besoins et des tâches.");
                    } else {
                        $pdo->prepare("DELETE FROM magal_contributeurs WHERE commission_id = ? AND membre_id = ?")->execute([$cid, $rid]);
                        magal_redirect($annee, 'besoins', "Autorisation retirée.");
                    }
                }
            } elseif ($action === 'add_besoin') {
                $lib = trim($_POST['libelle'] ?? '');
                $qte = trim($_POST['quantite'] ?? '');
                $ech = trim($_POST['echeance'] ?? '');
                if (!$impliquee || !$mgPeutCreer($cid)) { $error = "Action non autorisée."; }
                elseif ($lib === '') { $error = "Le besoin est obligatoire."; }
                elseif ($ech !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ech)) { $error = "Échéance invalide."; }
                else {
                    $pdo->prepare("INSERT INTO magal_besoins (annee, commission_id, libelle, quantite, echeance, created_by) VALUES (?,?,?,?,?,?)")
                        ->execute([$annee, $cid, mb_substr($lib, 0, 255), ($qte !== '' ? mb_substr($qte, 0, 60) : null), ($ech !== '' ? $ech : null), $mgMembre ?: null]);
                    magal_redirect($annee, 'besoins', "Besoin ajouté.");
                }
            } elseif ($action === 'set_besoin_statut' || $action === 'delete_besoin' || $action === 'set_besoin_echeance') {
                $bid = (int) ($_POST['id'] ?? 0);
                $br = $pdo->prepare("SELECT commission_id, created_by FROM magal_besoins WHERE id = ? AND annee = ?");
                $br->execute([$bid, $annee]);
                $brow = $br->fetch();
                $bcid = $brow ? (int) $brow['commission_id'] : 0;
                $bmien = $brow && $mgMembre > 0 && (int) ($brow['created_by'] ?? 0) === $mgMembre; // demandeur direct
                // Statut / échéance : organisateurs, responsable de la commission ou demandeur ; suppression : sans le demandeur
                if ($bcid <= 0 || !($mgPeutEditer($bcid) || ($bmien && $action !== 'delete_besoin'))) { $error = "Action non autorisée."; }
                elseif ($action === 'set_besoin_echeance') {
                    $ech = trim((string) ($_POST['echeance'] ?? ''));
                    if ($ech !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ech)) { $error = "Échéance invalide."; }
                    else {
                        $pdo->prepare("UPDATE magal_besoins SET echeance = ? WHERE id = ?")->execute([$ech !== '' ? $ech : null, $bid]);
                        magal_redirect($annee, 'besoins', $ech !== '' ? "Échéance du besoin enregistrée." : "Échéance du besoin retirée.");
                    }
                }
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
                if (!$impliquee || !$mgPeutCreer($cid)) { $error = "Action non autorisée."; }
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
                $tr = $pdo->prepare("SELECT commission_id, assignee_id, created_by FROM magal_taches WHERE id = ? AND annee = ?");
                $tr->execute([$tid, $annee]);
                $trow = $tr->fetch();
                $tcid = $trow ? (int) $trow['commission_id'] : 0;
                $estAssigne = $trow && $mgMembre > 0 && (int) $trow['assignee_id'] === $mgMembre;
                $estCreateur = $trow && $mgMembre > 0 && (int) ($trow['created_by'] ?? 0) === $mgMembre;
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
                    if (!$mgPeutEditer($tcid) && !$estAssigne && !$estCreateur) { $error = "Action non autorisée."; }
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
$contributeurs = []; $gestParCom = [];
try {
    foreach ($pdo->query("SELECT commission_id, membre_id FROM magal_contributeurs") as $r) { $contributeurs[(int) $r['commission_id']][] = (int) $r['membre_id']; }
    foreach ($pdo->query("SELECT commission_id, membre_id FROM commission_gestionnaires") as $r) { $gestParCom[(int) $r['commission_id']][] = (int) $r['membre_id']; }
} catch (Exception $e) {}

$besoins = [];
$taches = [];
try {
    $q = $pdo->prepare("SELECT * FROM magal_besoins WHERE annee = ? ORDER BY FIELD(statut,'a_trouver','en_cours','obtenu'), (echeance IS NULL), echeance ASC, id ASC");
    $q->execute([$annee]);
    foreach ($q as $r) { $besoins[(int) $r['commission_id']][] = $r; }
    $q = $pdo->prepare("SELECT t.*, CONCAT(m.prenom, ' ', m.nom) AS assignee_nom FROM magal_taches t LEFT JOIN membres m ON m.id = t.assignee_id WHERE t.annee = ? ORDER BY FIELD(t.statut,'en_cours','a_faire','fait'), t.echeance IS NULL, t.echeance ASC, t.id ASC");
    $q->execute([$annee]);
    foreach ($q as $r) { $taches[(int) $r['commission_id']][] = $r; }
} catch (Exception $e) {}

// Membres de chaque commission visible (pour attribuer les tâches)
$membresCom = [];
if ($tab === 'taches' || $tab === 'besoins') {
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

// Indicateurs de l'édition (limités aux commissions visibles par le membre)
$ind = ['com' => count($visibles), 'bT' => 0, 'bOk' => 0, 'bEnc' => 0, 'bAtt' => 0, 'bRet' => 0, 'tT' => 0, 'tOk' => 0, 'tEnc' => 0, 'tAtt' => 0, 'tRet' => 0, 'parCom' => []];
foreach ($visibles as $icid) {
    $c = ['nom' => (string) ($nomCommission[$icid] ?? ''), 'bT' => 0, 'bOk' => 0, 'tT' => 0, 'tOk' => 0, 'ret' => 0];
    foreach (($besoins[$icid] ?? []) as $ib) {
        $ind['bT']++; $c['bT']++;
        if ($ib['statut'] === 'obtenu') { $ind['bOk']++; $c['bOk']++; }
        else {
            if ($ib['statut'] === 'en_cours') { $ind['bEnc']++; } else { $ind['bAtt']++; }
            if (!empty($ib['echeance']) && $ib['echeance'] < $aujourdhui) { $ind['bRet']++; $c['ret']++; }
        }
    }
    foreach (($taches[$icid] ?? []) as $it) {
        $ind['tT']++; $c['tT']++;
        if ($it['statut'] === 'fait') { $ind['tOk']++; $c['tOk']++; }
        else {
            if ($it['statut'] === 'en_cours') { $ind['tEnc']++; } else { $ind['tAtt']++; }
            if (!empty($it['echeance']) && $it['echeance'] < $aujourdhui) { $ind['tRet']++; $c['ret']++; }
        }
    }
    $ind['parCom'][] = $c;
}
$indTot = $ind['bT'] + $ind['tT'];
$indPct = $indTot > 0 ? (int) round(100 * ($ind['bOk'] + $ind['tOk']) / $indTot) : 0;
$indPctB = $ind['bT'] > 0 ? (int) round(100 * $ind['bOk'] / $ind['bT']) : 0;
$indPctT = $ind['tT'] > 0 ? (int) round(100 * $ind['tOk'] / $ind['tT']) : 0;
$indRet = $ind['bRet'] + $ind['tRet'];
$indFin = null;
if ($mgOrganisateur || $mgFinance) {
    try {
        $qF = $pdo->prepare("SELECT type, COALESCE(SUM(montant), 0) FROM magal_finance WHERE annee = ? GROUP BY type");
        $qF->execute([$annee]);
        $indFin = ['recette' => 0.0, 'depense' => 0.0];
        foreach ($qF->fetchAll(PDO::FETCH_NUM) as $rF) { if (isset($indFin[$rF[0]])) { $indFin[$rF[0]] = (float) $rF[1]; } }
    } catch (Exception $e) { $indFin = null; }
}
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
        .mg-bar { height: 8px; border-radius: 999px; background: rgba(255,255,255,0.08); overflow: hidden; }
        .mg-bar > span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #2d6a4f, #d4af37); }
        .mg-ind-row { display: grid; grid-template-columns: minmax(120px, 1.2fr) 2fr; gap: 0.3rem 0.9rem; align-items: center; padding: 0.55rem 0; border-top: 1px solid rgba(255,255,255,0.07); }
        .mg-ind-row .mg-ind-nom { color: var(--white); font-weight: 700; font-size: 0.9rem; }
        .mg-ind-row .mg-ind-txt { grid-column: 1 / -1; color: var(--text-muted); font-size: 0.78rem; }
        @media (max-width: 600px) { .mg-ind-row { grid-template-columns: 1fr; } }
        .mg-badge { font-size: 0.72rem; padding: 0.15rem 0.55rem; border-radius: 12px; white-space: nowrap; border: 1px solid; }
        .mg-b-ok { background: rgba(37,211,102,0.15); color: #7bd8a6; border-color: rgba(37,211,102,0.4); }
        .mg-b-att { background: rgba(255,193,7,0.15); color: #ffd873; border-color: rgba(255,193,7,0.4); }
        .mg-b-info { background: rgba(45,140,255,0.15); color: #8ec5ff; border-color: rgba(45,140,255,0.4); }
        .mg-b-red { background: rgba(191,33,33,0.15); color: #fca5a5; border-color: rgba(191,33,33,0.45); }
        @media (max-width: 600px) { .mg-form .form-group { min-width: 100%; } }
        /* ── Sélecteur à deux listes ── */
        #members-modal { position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; padding: 1rem; }
        #members-modal.active { display: flex; }
        #sub-members-modal { position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; padding: 1rem; }
        #sub-members-modal.active { display: flex; }
        .tl-card { width: 100%; max-width: 820px; max-height: 90vh; display: flex; flex-direction: column; background: linear-gradient(180deg,#123528,#0c241a); border: 1px solid rgba(212,175,55,0.25); border-radius: 12px; overflow: hidden; box-shadow: 0 30px 80px rgba(0,0,0,0.55); }
        .tl-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; background: linear-gradient(135deg,#1b4332,#2d6a4f); flex-shrink: 0; }
        .tl-head h3 { margin: 0; color: #fff; font-size: 1.05rem; }
        .tl-x { background: rgba(255,255,255,0.15); color: #fff; border: 0; width: 28px; height: 28px; border-radius: 50%; font-size: 1.1rem; line-height: 1; cursor: pointer; flex-shrink: 0; }
        .tl-cols { display: flex; flex: 1; min-height: 0; }
        .tl-col { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .tl-col + .tl-col { border-left: 1px solid var(--glass-border); }
        .tl-col-head { padding: 0.75rem 1rem 0.5rem; flex-shrink: 0; }
        .tl-col-head .tl-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gold); font-weight: 700; }
        .tl-col-head input { width: 100%; margin-top: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 12px; color: #fff; font-size: 0.85rem; padding: 0.45rem 0.9rem; }
        .tl-col-head input:focus { outline: none; border-color: var(--accent); }
        .tl-list { flex: 1; overflow-y: auto; padding: 0.5rem 0.75rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
        .tl-item { display: flex; align-items: center; gap: 0.6rem; padding: 0.4rem 0.5rem; border: 1px solid var(--glass-border); border-radius: 10px; background: rgba(255,255,255,0.03); }
        .tl-av { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .tl-av--ph { background: rgba(212,175,55,0.15); color: var(--gold); font-size: 0.72rem; font-weight: 700; border: 1px solid rgba(212,175,55,0.35); }
        .tl-name { flex: 1; color: #fff; font-size: 0.88rem; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tl-item button { flex-shrink: 0; border: 0; border-radius: 8px; width: 30px; height: 30px; font-size: 1.1rem; line-height: 1; cursor: pointer; }
        .tl-add-btn { background: rgba(212,175,55,0.2); color: var(--gold); }
        .tl-add-btn:hover { background: rgba(212,175,55,0.35); }
        .tl-rem-btn { background: rgba(255,80,80,0.18); color: #ff8a8a; }
        .tl-rem-btn:hover { background: rgba(255,80,80,0.32); }
        .tl-empty { color: var(--text-muted); font-size: 0.82rem; font-style: italic; padding: 0.75rem 0.5rem; text-align: center; }
        .tl-foot { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.85rem 1.25rem; border-top: 1px solid var(--glass-border); flex-wrap: wrap; flex-shrink: 0; }
        .tl-count { font-size: 0.8rem; color: var(--text-muted); }
        @media (max-width: 600px) { .tl-cols { flex-direction: column; } .tl-col + .tl-col { border-left: 0; border-top: 1px solid var(--glass-border); } .tl-list { max-height: 30vh; } }
        .tl-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .tl-chip { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.25rem 0.75rem 0.25rem 0.3rem; border: 1px solid rgba(212,175,55,0.4); border-radius: 10px; background: rgba(255,255,255,0.05); color: var(--white); font-size: 0.88rem; font-weight: 600; }
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
                    <select id="mg-annee" name="annee" class="mg-sel" style="width:8.5rem;" onchange="this.form.submit()">
                        <?php for ($y = MAGAL_PREMIERE_ANNEE; $y <= $anneeMax; $y++): ?>
                            <option value="<?php echo $y; ?>"<?php echo $y === $annee ? ' selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>

            <?php if ($mgOrganisateur) { echo fr_bloc_html($pdo, 'magal', 'Wadial Magal', 'magal.php'); } ?>
            <?php // Indicateurs repliés par défaut : l'accueil montre d'abord le travail en cours ?>
            <details class="glass-card" style="margin-bottom:1rem;">
                <summary>📊 Indicateurs — Wadial Magal <?php echo (int) $annee; ?></summary>
                <div class="mg-stats" style="margin-top:0.7rem;">
                    <div class="glass-card mg-stat"><div class="v"><?php echo (int) $ind['com']; ?></div><div class="l">📋 Commission(s) impliquée(s)</div></div>
                    <div class="glass-card mg-stat"><div class="v"><?php echo (int) $ind['bOk']; ?>/<?php echo (int) $ind['bT']; ?></div><div class="l">🧰 Besoins obtenus (<?php echo (int) $indPctB; ?> %)</div></div>
                    <div class="glass-card mg-stat"><div class="v"><?php echo (int) $ind['tOk']; ?>/<?php echo (int) $ind['tT']; ?></div><div class="l">✅ Tâches faites (<?php echo (int) $indPctT; ?> %)</div></div>
                    <div class="glass-card mg-stat"><div class="v" style="color:<?php echo $indRet > 0 ? '#fca5a5' : '#7bd8a6'; ?>;"><?php echo (int) $indRet; ?></div><div class="l">⏰ En retard (<?php echo (int) $ind['bRet']; ?> besoin(s), <?php echo (int) $ind['tRet']; ?> tâche(s))</div></div>
                    <div class="glass-card mg-stat"><div class="v" style="color:#ffd873;"><?php echo (int) $ind['bEnc'] + (int) $ind['tEnc']; ?></div><div class="l">⏳ En cours</div></div>
                    <div class="glass-card mg-stat"><div class="v"><?php echo (int) $ind['bAtt'] + (int) $ind['tAtt']; ?></div><div class="l">🕓 À trouver / à faire</div></div>
                    <?php if ($indFin !== null): $indSolde = $indFin['recette'] - $indFin['depense']; ?>
                    <div class="glass-card mg-stat"><div class="v" style="color:#7bd8a6;"><?php echo number_format($indFin['recette'], 2, ',', ' '); ?> €</div><div class="l">📥 Recettes</div></div>
                    <div class="glass-card mg-stat"><div class="v" style="color:#fca5a5;"><?php echo number_format($indFin['depense'], 2, ',', ' '); ?> €</div><div class="l">📤 Dépenses</div></div>
                    <div class="glass-card mg-stat"><div class="v" style="color:<?php echo $indSolde >= 0 ? '#ffd873' : '#fca5a5'; ?>;"><?php echo number_format($indSolde, 2, ',', ' '); ?> €</div><div class="l">⚖️ Solde</div></div>
                    <?php endif; ?>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; gap:0.6rem; margin:0.2rem 0 0.35rem; font-size:0.85rem; color:var(--text-muted);"><span>Avancement global (besoins obtenus + tâches faites)</span><strong style="color:var(--gold);"><?php echo (int) $indPct; ?> %</strong></div>
                <div class="mg-bar"><span style="width:<?php echo (int) $indPct; ?>%;"></span></div>
                <?php if (!empty($ind['parCom'])): ?>
                <div style="margin-top:0.9rem;">
                    <?php foreach ($ind['parCom'] as $ic): $it2 = $ic['bT'] + $ic['tT']; $ip = $it2 > 0 ? (int) round(100 * ($ic['bOk'] + $ic['tOk']) / $it2) : 0; ?>
                    <div class="mg-ind-row">
                        <div class="mg-ind-nom">📋 <?php echo $h($ic['nom']); ?></div>
                        <div class="mg-bar"><span style="width:<?php echo (int) $ip; ?>%;"></span></div>
                        <div class="mg-ind-txt">🧰 <?php echo (int) $ic['bOk']; ?>/<?php echo (int) $ic['bT']; ?> besoin(s) · ✅ <?php echo (int) $ic['tOk']; ?>/<?php echo (int) $ic['tT']; ?> tâche(s) · <?php echo (int) $ip; ?> %<?php if ($ic['ret'] > 0): ?> · <span style="color:#fca5a5; font-weight:700;">⏰ <?php echo (int) $ic['ret']; ?> en retard</span><?php endif; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </details>
            <nav class="mg-tabs">
                <?php foreach ($onglets as $k => $lbl): ?>
                    <a href="magal.php?annee=<?php echo (int) $annee; ?>&tab=<?php echo $h($k); ?>"<?php echo $k === $tab ? ' class="active"' : ''; ?>><?php echo $lbl; ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'accueil'):
                // Le travail en cours des commissions visibles, rangé par statut ;
                // ce qui est obtenu ou fait se retrouve dans les onglets Besoins / Tâches
                $acc = [
                    ['t', 'en_cours',  '⏳ Tâches en cours'],
                    ['t', 'a_faire',   '🕓 Tâches à faire'],
                    ['b', 'a_trouver', '🔎 Besoins à trouver'],
                    ['b', 'en_cours',  '⏳ Besoins en cours'],
                ];
                $accLignes = [];
                foreach ($visibles as $acid) {
                    foreach (($taches[$acid] ?? []) as $at) { $accLignes['t'][$at['statut']][] = $at; }
                    foreach (($besoins[$acid] ?? []) as $ab) { $accLignes['b'][$ab['statut']][] = $ab; }
                }
                $accCreables = array_values(array_filter($visibles, $mgPeutCreer));
                $accVide = true;
            ?>
                <?php if (!empty($accCreables)): ?>
                <div class="glass-card mg-card">
                    <h3>➕ Ajouter un besoin ou une tâche</h3>
                    <?php foreach ($accCreables as $acid): ?>
                        <div class="mg-row">
                            <div class="mg-main"><strong>📋 <?php echo $h($nomCommission[$acid] ?? ''); ?></strong></div>
                            <a class="btn btn-secondary btn-sm" href="magal.php?annee=<?php echo (int) $annee; ?>&tab=besoins#ajout-besoin-<?php echo (int) $acid; ?>">🧰 ➕ Besoin</a>
                            <a class="btn btn-primary btn-sm" href="magal.php?annee=<?php echo (int) $annee; ?>&tab=taches#ajout-tache-<?php echo (int) $acid; ?>">✅ ➕ Tâche</a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (empty($visibles)): ?>
                    <div class="glass-card mg-card"><p style="color:var(--text-muted); margin:0;">Aucune commission impliquée pour le moment.</p></div>
                <?php endif; ?>

                <?php foreach ($acc as [$accType, $accStatut, $accTitre]):
                    $accListe = $accLignes[$accType][$accStatut] ?? [];
                    if (empty($accListe)) { continue; }
                    $accVide = false;
                ?>
                    <details class="glass-card mg-card" open>
                        <summary><?php echo $accTitre; ?> <span class="mg-count"><?php echo count($accListe); ?></span></summary>
                        <div style="margin-top:0.7rem;">
                        <?php foreach ($accListe as $al):
                            $alRetard = !empty($al['echeance']) && $al['echeance'] < $aujourdhui;
                            $alTab = $accType === 't' ? 'taches' : 'besoins';
                        ?>
                            <div class="mg-row">
                                <div class="mg-main">
                                    <strong><?php echo $h($accType === 't' ? $al['titre'] : $al['libelle']); ?></strong>
                                    <div class="mg-sub">
                                        📋 <?php echo $h($nomCommission[(int) $al['commission_id']] ?? ''); ?>
                                        <?php if ($accType === 't'): ?> · 👤 <?php echo !empty(trim((string) $al['assignee_nom'])) ? $h($al['assignee_nom']) : 'Non attribuée'; ?><?php endif; ?>
                                        <?php if ($accType === 'b' && !empty($al['quantite'])): ?> · Quantité : <?php echo $h($al['quantite']); ?><?php endif; ?>
                                        <?php if (!empty($al['echeance'])): ?> · 📅 <span<?php echo $alRetard ? ' style="color:#fca5a5; font-weight:700;"' : ''; ?>><?php echo $h(date('d/m/Y', strtotime($al['echeance']))); ?><?php echo $alRetard ? ' (en retard)' : ''; ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <a class="btn btn-secondary btn-sm" href="magal.php?annee=<?php echo (int) $annee; ?>&tab=<?php echo $alTab; ?>">Gérer</a>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
                <?php if ($accVide && !empty($visibles)): ?>
                    <div class="glass-card mg-card"><p style="color:var(--text-muted); margin:0;">✅ Aucune tâche ni aucun besoin en cours pour l'édition <?php echo (int) $annee; ?>.</p></div>
                <?php endif; ?>

            <?php elseif ($tab === 'commissions'): ?>
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
                <?php foreach ($visibles as $cid): $edit = $mgPeutEditer($cid); $creer = $mgPeutCreer($cid); $liste = $besoins[$cid] ?? []; ?>
                    <div class="glass-card mg-card">
                        <h3>🧰 <?php echo $h($nomCommission[$cid] ?? ''); ?> <span class="mg-count"><?php echo count($liste); ?></span></h3>
                        <?php if (empty($liste)): ?><p style="color:var(--text-muted); margin:0 0 0.4rem;">Aucun besoin renseigné.</p><?php endif; ?>
                        <?php foreach ($liste as $b): $peutB = $edit || ($mgMembre > 0 && (int) ($b['created_by'] ?? 0) === $mgMembre); $bc = $b['statut'] === 'obtenu' ? 'mg-b-ok' : ($b['statut'] === 'en_cours' ? 'mg-b-info' : 'mg-b-att'); ?>
                            <div class="mg-row<?php echo $b['statut'] === 'obtenu' ? ' done' : ''; ?>">
                                <div class="mg-main"><strong><?php echo $h($b['libelle']); ?></strong><?php if (!empty($b['quantite'])): ?><div class="mg-sub">Quantité : <?php echo $h($b['quantite']); ?></div><?php endif; ?><?php if (!empty($b['echeance'])): $retardB = $b['statut'] !== 'obtenu' && $b['echeance'] < $aujourdhui; ?><div class="mg-sub">📅 Échéance : <span<?php echo $retardB ? ' style="color:#fca5a5; font-weight:700;"' : ''; ?>><?php echo $h(date('d/m/Y', strtotime($b['echeance']))); ?></span><?php echo $retardB ? ' · en retard' : ''; ?></div><?php endif; ?></div>
                                <?php if ($peutB): ?>
                                <form method="POST" action="magal.php" style="margin:0;" title="Échéance">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="set_besoin_echeance">
                                    <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
                                    <input type="date" name="echeance" class="mg-sel" value="<?php echo $h($b['echeance'] ?? ''); ?>" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                </form>
                                <form method="POST" action="magal.php" style="margin:0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="set_besoin_statut">
                                    <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
                                    <select name="statut" class="mg-sel" onchange="this.form.submit()">
                                        <?php foreach ($stBesoin as $k => $lbl): ?><option value="<?php echo $k; ?>"<?php echo $b['statut'] === $k ? ' selected' : ''; ?>><?php echo $h($lbl); ?></option><?php endforeach; ?>
                                    </select>
                                </form>
                                <?php if ($edit): ?>
                                <form method="POST" action="magal.php" style="margin:0;" data-confirm="Supprimer ce besoin ?" data-confirm-danger="1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_besoin">
                                    <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $b['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;" title="Supprimer">🗑️</button>
                                </form>
                                <?php endif; ?>
                                <?php else: ?>
                                    <span class="mg-badge <?php echo $bc; ?>"><?php echo $h($stBesoin[$b['statut']] ?? $b['statut']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($creer): ?>
                        <form method="POST" action="magal.php" class="mg-form" id="ajout-besoin-<?php echo (int) $cid; ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="add_besoin">
                            <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                            <input type="hidden" name="commission_id" value="<?php echo (int) $cid; ?>">
                            <div class="form-group" style="flex:2;"><label class="form-label">Besoin</label><input type="text" name="libelle" class="form-input" required maxlength="255" placeholder="Ex : 20 tables, sono, vaisselle…"></div>
                            <div class="form-group"><label class="form-label">Quantité (facultatif)</label><input type="text" name="quantite" class="form-input" maxlength="60" placeholder="Ex : 20"></div>
                            <div class="form-group"><label class="form-label">Échéance (facultatif)</label><input type="date" name="echeance" class="form-input"></div>
                            <button type="submit" class="btn btn-primary btn-sm">➕ Ajouter</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($edit): $mcAut = $contributeurs[$cid] ?? []; ?>
                        <details class="glass-card" style="margin-top:0.8rem;">
                            <summary>👥 Membres autorisés à créer des besoins et tâches (<?php echo count($mcAut); ?>)</summary>
                            <p style="color:var(--text-muted); font-size:0.82rem; margin:0.5rem 0;">Les membres cochés peuvent ajouter des besoins et des tâches du Wadial Magal pour cette commission. La fonction Wadial Magal apparaît alors dans leur menu.</p>
                            <?php foreach (($membresCom[$cid] ?? []) as $m): if (in_array((int) $m['id'], $gestParCom[$cid] ?? [], true)) { continue; } ?>
                                <form method="POST" action="magal.php" style="margin:0 0 0.4rem;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="magal_set_contrib">
                                    <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                                    <input type="hidden" name="commission_id" value="<?php echo (int) $cid; ?>">
                                    <input type="hidden" name="membre_id" value="<?php echo (int) $m['id']; ?>">
                                    <label style="display:flex; align-items:center; gap:0.7rem; margin:0; cursor:pointer; color:var(--white);">
                                        <input type="checkbox" class="checkbox-switch" name="enable" value="1"<?php echo in_array((int) $m['id'], $mcAut, true) ? ' checked' : ''; ?> onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                        <span><?php echo $h(trim($m['prenom'] . ' ' . $m['nom'])); ?></span>
                                    </label>
                                </form>
                            <?php endforeach; ?>
                        </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

            <?php elseif ($tab === 'taches'): ?>
                <?php if (empty($visibles)): ?>
                    <div class="glass-card mg-card"><p style="color:var(--text-muted); margin:0;">Aucune commission impliquée pour le moment.</p></div>
                <?php endif; ?>
                <?php foreach ($visibles as $cid): $edit = $mgPeutEditer($cid); $creer = $mgPeutCreer($cid); $liste = $taches[$cid] ?? []; ?>
                    <div class="glass-card mg-card">
                        <h3>✅ <?php echo $h($nomCommission[$cid] ?? ''); ?> <span class="mg-count"><?php echo count($liste); ?></span></h3>
                        <?php if (empty($liste)): ?><p style="color:var(--text-muted); margin:0 0 0.4rem;">Aucune tâche.</p><?php endif; ?>
                        <?php foreach ($liste as $t):
                            $retard = $t['statut'] !== 'fait' && !empty($t['echeance']) && $t['echeance'] < $aujourdhui;
                            $estAssigne = $mgMembre > 0 && (int) $t['assignee_id'] === $mgMembre;
                            $estCreateur = $mgMembre > 0 && (int) ($t['created_by'] ?? 0) === $mgMembre;
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
                                <?php if ($edit || $estAssigne || $estCreateur): ?>
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
                        <?php if ($creer): ?>
                        <form method="POST" action="magal.php" class="mg-form" id="ajout-tache-<?php echo (int) $cid; ?>">
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
<script>
// Arrivée depuis « ➕ Besoin / ➕ Tâche » de l'accueil : le formulaire de la
// commission est mis en évidence et son premier champ prêt à la saisie
(function () {
    var cible = location.hash && document.getElementById(location.hash.slice(1));
    if (!cible || !/^ajout-/.test(cible.id)) { return; }
    cible.scrollIntoView({ block: 'center' });
    cible.style.outline = '2px solid var(--gold)';
    cible.style.borderRadius = '10px';
    var champ = cible.querySelector('input[type="text"]');
    if (champ) { setTimeout(function () { champ.focus(); }, 300); }
})();
</script>
</body>
</html>
