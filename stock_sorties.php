<?php
/**
 * Touba Lyon 2026 - Sorties d'équipement d'une commission (événement / prêt) et retours en stock.
 *
 * Accessible aux administrateurs et aux responsables de la commission, si la gestion de stock est activée.
 * Une sortie retire les quantités du stock ; le retour les remet (avec l'état constaté).
 * Prêt : à un membre ou à une personne externe (e-mail obligatoire) ; rapport de prêt et rapport de retour par e-mail.
 */
require_once __DIR__ . '/commission_guard.php'; // $__isAdmin, $__managedCommissions, $pdo
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/stock_logistique_helper.php';

$error = '';
$success = $_SESSION['sortie_flash'] ?? '';
unset($_SESSION['sortie_flash']);
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

$cid = (int) ($_POST['commission_id'] ?? $_GET['id'] ?? 0);
$canManage = $__isAdmin || in_array($cid, $__managedCommissions, true);
$commission = null;
if ($cid > 0) {
    try {
        $st = $pdo->prepare("SELECT id, nom, COALESCE(stock_enabled,0) AS stock_enabled FROM commissions WHERE id = ?");
        $st->execute([$cid]);
        $commission = $st->fetch();
    } catch (Exception $e) { $commission = null; }
}
if (!$commission || !$canManage) { header('Location: stock_index.php'); exit; }
$stockEnabled = ((int) $commission['stock_enabled'] === 1);
$moi = (int) ($_SESSION['player_id'] ?? 0);

// Articles + membres
$items = []; $itemsById = [];
try {
    $q = $pdo->prepare("SELECT id, nom, quantite, etat, photo FROM commission_stock WHERE commission_id = ? ORDER BY nom ASC");
    $q->execute([$cid]);
    $items = $q->fetchAll();
    foreach ($items as $it) { $itemsById[(int) $it['id']] = $it; }
} catch (Exception $e) { $items = []; }
/** Ligne d'un article choisi pour la sortie (miniature, nom, quantité, retrait). */
function so_ligne_html(array $it, int $q): string
{
    $nom = htmlspecialchars((string) $it['nom'], ENT_QUOTES);
    $dispo = (int) $it['quantite'];
    $img = !empty($it['photo'])
        ? '<img src="uploads/' . htmlspecialchars(rawurlencode((string) $it['photo']), ENT_QUOTES) . '" alt="" loading="lazy">'
        : '<span class="ph">📦</span>';
    return '<div class="so-sel" data-id="' . (int) $it['id'] . '">' . $img
        . '<span class="so-sel-nom">' . $nom . '<small>dispo : ' . $dispo . '</small></span>'
        . '<input type="number" name="qte[]" class="form-input" min="1" max="' . max(1, $dispo) . '" value="' . max(1, $q) . '">'
        . '<input type="hidden" name="item_id[]" value="' . (int) $it['id'] . '">'
        . '<button type="button" class="btn btn-secondary btn-sm so-sel-del" aria-label="Retirer">✕</button></div>';
}
$membresCom = stock_membres_commission($pdo, $cid);
$tousMembres = [];
try { $tousMembres = $pdo->query("SELECT id, prenom, nom, email FROM membres WHERE status = 'approved' ORDER BY nom ASC, prenom ASC")->fetchAll(); } catch (Exception $e) {}
$membreInfo = [];
foreach (array_merge($membresCom, $tousMembres) as $m) { $membreInfo[(int) $m['id']] = $m; }
$nomMembre = function (int $id) use ($membreInfo) { return isset($membreInfo[$id]) ? trim($membreInfo[$id]['prenom'] . ' ' . $membreInfo[$id]['nom']) : ''; };

/** Corps d'e-mail d'une sortie (prêt ou retour) : en-tête + tableau des lignes. */
function sortie_mail_inner(array $s, array $lignes, string $commissionNom, string $respNom, string $emprNom, bool $retour): string
{
    $rows = [];
    foreach ($lignes as $l) {
        $r = [htmlspecialchars((string) $l['nom']), (int) $l['quantite']];
        if ($retour) {
            $r[] = (int) $l['quantite_retournee'];
            $r[] = htmlspecialchars(stock_etats()[$l['etat_retour'] ?? 'bon'] ?? 'Bon état');
        }
        $rows[] = $r;
    }
    $head = $retour ? ['Article', 'Prêté', 'Retourné', 'État au retour'] : ['Article', 'Quantité'];
    $inner = '<h1 style="margin:0 0 14px;font-size:20px;color:#1b4332;">' . ($retour ? 'Rapport de retour de prêt' : 'Rapport de prêt de matériel') . '</h1>'
        . '<p style="margin:0 0 10px;">Commission : <strong>' . htmlspecialchars($commissionNom) . '</strong></p>'
        . '<p style="margin:0 0 6px;">Emprunteur : <strong>' . htmlspecialchars($emprNom) . '</strong></p>'
        . '<p style="margin:0 0 6px;">Responsable de la sortie : <strong>' . htmlspecialchars($respNom) . '</strong></p>'
        . '<p style="margin:0 0 6px;">Date de sortie : <strong>' . htmlspecialchars(date('d/m/Y', strtotime((string) $s['date_sortie']))) . '</strong>'
        . (!empty($s['date_retour_prevue']) ? ' — retour prévu le <strong>' . htmlspecialchars(date('d/m/Y', strtotime((string) $s['date_retour_prevue']))) . '</strong>' : '') . '</p>'
        . (trim((string) ($s['description'] ?? '')) !== '' ? '<p style="margin:0 0 10px;">Description : ' . nl2br(htmlspecialchars((string) $s['description'])) . '</p>' : '')
        . sl_table_html($head, $rows);
    if ($retour && !empty($s['retour_at'])) { $inner .= '<p style="margin:10px 0 0;">Retour complet enregistré le ' . htmlspecialchars(date('d/m/Y H:i', strtotime((string) $s['retour_at']))) . '.</p>'; }
    return $inner . '<p style="margin-top:16px;">Jërëjëf !</p>';
}

/** Destinataires du rapport d'un prêt : emprunteur, responsable de la sortie, responsables de la commission. */
function sortie_destinataires(PDO $pdo, array $s, array $membreInfo, int $cid): array
{
    $d = [];
    if (($s['emprunteur_type'] ?? '') === 'externe') { sl_dest($d, $s['emprunteur_email'] ?? '', (string) ($s['emprunteur_nom'] ?? '')); }
    elseif (!empty($s['emprunteur_membre_id']) && isset($membreInfo[(int) $s['emprunteur_membre_id']])) {
        $m = $membreInfo[(int) $s['emprunteur_membre_id']]; sl_dest($d, $m['email'] ?? '', trim($m['prenom'] . ' ' . $m['nom']));
    }
    if (isset($membreInfo[(int) $s['responsable_id']])) { $m = $membreInfo[(int) $s['responsable_id']]; sl_dest($d, $m['email'] ?? '', trim($m['prenom'] . ' ' . $m['nom'])); }
    foreach (stock_responsables_commission($pdo, $cid) as $m) { sl_dest($d, $m['email'] ?? '', trim($m['prenom'] . ' ' . $m['nom'])); }
    return $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stockEnabled) {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité. Veuillez réessayer.";
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'creer_sortie') {
                $type = ($_POST['type'] ?? '') === 'pret' ? 'pret' : 'evenement';
                $desc = trim((string) ($_POST['description'] ?? ''));
                $respId = (int) ($_POST['responsable_id'] ?? 0);
                $dateS = trim((string) ($_POST['date_sortie'] ?? '')) ?: date('Y-m-d');
                $dateR = trim((string) ($_POST['date_retour_prevue'] ?? ''));
                $empType = $empMid = $empNom = $empEmail = null;
                $lignesIn = [];
                $ids = (array) ($_POST['item_id'] ?? []); $qtes = (array) ($_POST['qte'] ?? []);
                foreach ($ids as $k => $iid) {
                    $iid = (int) $iid; $qte = (int) ($qtes[$k] ?? 0);
                    if ($iid > 0 && $qte > 0) { $lignesIn[$iid] = ($lignesIn[$iid] ?? 0) + $qte; }
                }
                if ($type === 'pret') {
                    $empType = ($_POST['emprunteur_type'] ?? '') === 'externe' ? 'externe' : 'membre';
                    if ($empType === 'membre') { $empMid = (int) ($_POST['emprunteur_membre_id'] ?? 0); }
                    else { $empNom = trim((string) ($_POST['ext_nom'] ?? '')); $empEmail = trim((string) ($_POST['ext_email'] ?? '')); }
                }
                if ($desc === '') { $error = "Indiquez une description."; }
                elseif (!isset($membreInfo[$respId])) { $error = "Choisissez le responsable de la sortie."; }
                elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateS) || ($dateR !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateR))) { $error = "Date invalide."; }
                elseif (empty($lignesIn)) { $error = "Ajoutez au moins un article avec sa quantité."; }
                elseif ($type === 'pret' && $empType === 'membre' && !isset($membreInfo[(int) $empMid])) { $error = "Choisissez le membre qui emprunte."; }
                elseif ($type === 'pret' && $empType === 'externe' && ($empNom === '' || !filter_var($empEmail, FILTER_VALIDATE_EMAIL))) { $error = "Pour un prêt à une personne externe, indiquez son nom et une adresse e-mail valide."; }
                else {
                    foreach ($lignesIn as $iid => $qte) {
                        if (!isset($itemsById[$iid])) { $error = "Article introuvable."; break; }
                        if ($qte > (int) $itemsById[$iid]['quantite']) { $error = "Quantité insuffisante en stock pour « " . $itemsById[$iid]['nom'] . " » (disponible : " . (int) $itemsById[$iid]['quantite'] . ")."; break; }
                    }
                }
                if ($error === '') {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO stock_sorties (commission_id, type, description, responsable_id, emprunteur_type, emprunteur_membre_id, emprunteur_nom, emprunteur_email, date_sortie, date_retour_prevue, created_by)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$cid, $type, $desc, $respId, $empType, $empMid ?: null, $empNom ?: null, $empEmail ?: null, $dateS, $dateR !== '' ? $dateR : null, $moi]);
                    $sid = (int) $pdo->lastInsertId();
                    $li = $pdo->prepare("INSERT INTO stock_sortie_lignes (sortie_id, stock_id, quantite) VALUES (?,?,?)");
                    $dec = $pdo->prepare("UPDATE commission_stock SET quantite = quantite - ? WHERE id = ? AND commission_id = ? AND quantite >= ?");
                    foreach ($lignesIn as $iid => $qte) {
                        $dec->execute([$qte, $iid, $cid, $qte]);
                        if ($dec->rowCount() < 1) { $pdo->rollBack(); throw new RuntimeException("Stock insuffisant pour « " . $itemsById[$iid]['nom'] . " »."); }
                        $li->execute([$sid, $iid, $qte]);
                    }
                    $pdo->commit();
                    $success = $type === 'pret' ? "Prêt enregistré. Le stock est mis à jour." : "Sortie pour événement enregistrée. Le stock est mis à jour.";
                    if ($type === 'pret') {
                        $sRow = ['date_sortie' => $dateS, 'date_retour_prevue' => $dateR, 'description' => $desc, 'emprunteur_type' => $empType, 'emprunteur_membre_id' => $empMid, 'emprunteur_nom' => $empNom, 'emprunteur_email' => $empEmail, 'responsable_id' => $respId];
                        $lg = []; foreach ($lignesIn as $iid => $qte) { $lg[] = ['nom' => $itemsById[$iid]['nom'], 'quantite' => $qte]; }
                        $emprNom = $empType === 'externe' ? $empNom . ' (externe)' : $nomMembre((int) $empMid);
                        $n = sl_envoyer(sortie_destinataires($pdo, $sRow, $membreInfo, $cid), 'Rapport de prêt — ' . $commission['nom'], sortie_mail_inner($sRow, $lg, $commission['nom'], $nomMembre($respId), $emprNom, false), 'Prêt de matériel');
                        $success .= " Rapport de prêt envoyé par e-mail ($n destinataire" . ($n > 1 ? 's' : '') . ").";
                    }
                    $_SESSION['sortie_flash'] = $success;
                    header('Location: stock_sorties.php?id=' . $cid);
                    exit;
                }
            } elseif ($action === 'retour') {
                $sid = (int) ($_POST['sortie_id'] ?? 0);
                $sq = $pdo->prepare("SELECT * FROM stock_sorties WHERE id = ? AND commission_id = ?");
                $sq->execute([$sid, $cid]);
                $s = $sq->fetch();
                if (!$s || $s['statut'] === 'retourne') { $error = "Sortie introuvable ou déjà retournée."; }
                else {
                    $lq = $pdo->prepare("SELECT l.*, c.nom FROM stock_sortie_lignes l JOIN commission_stock c ON c.id = l.stock_id WHERE l.sortie_id = ?");
                    $lq->execute([$sid]);
                    $lignes = $lq->fetchAll();
                    $retours = (array) ($_POST['retour'] ?? []); $etats = (array) ($_POST['etat'] ?? []);
                    $pdo->beginTransaction();
                    $upL = $pdo->prepare("UPDATE stock_sortie_lignes SET quantite_retournee = quantite_retournee + ?, etat_retour = ? WHERE id = ?");
                    $upS = $pdo->prepare("UPDATE commission_stock SET quantite = quantite + ? WHERE id = ? AND commission_id = ?");
                    $upE = $pdo->prepare("UPDATE commission_stock SET etat = ? WHERE id = ? AND commission_id = ?");
                    $totalRetourne = 0;
                    foreach ($lignes as $k => $l) {
                        $reste = (int) $l['quantite'] - (int) $l['quantite_retournee'];
                        $r = max(0, min($reste, (int) ($retours[$l['id']] ?? 0)));
                        $etat = array_key_exists((string) ($etats[$l['id']] ?? ''), stock_etats()) ? (string) $etats[$l['id']] : 'bon';
                        if ($r > 0) {
                            $upL->execute([$r, $etat, $l['id']]);
                            $upS->execute([$r, $l['stock_id'], $cid]);
                            if ($etat !== 'bon') { $upE->execute([$etat, $l['stock_id'], $cid]); }
                            $totalRetourne += $r;
                            $lignes[$k]['quantite_retournee'] = (int) $l['quantite_retournee'] + $r;
                            $lignes[$k]['etat_retour'] = $etat;
                        }
                    }
                    if ($totalRetourne <= 0) { $pdo->rollBack(); $error = "Indiquez au moins une quantité retournée."; }
                    else {
                        $tout = true; foreach ($lignes as $l) { if ((int) $l['quantite_retournee'] < (int) $l['quantite']) { $tout = false; } }
                        $pdo->prepare("UPDATE stock_sorties SET statut = ?, retour_at = " . ($tout ? 'NOW()' : 'NULL') . " WHERE id = ?")->execute([$tout ? 'retourne' : 'partiel', $sid]);
                        $pdo->commit();
                        $success = $tout ? "Retour complet enregistré : les articles sont de retour en stock." : "Retour partiel enregistré : les articles retournés sont de retour en stock.";
                        if ($s['type'] === 'pret') {
                            $s['retour_at'] = $tout ? date('Y-m-d H:i:s') : null;
                            $emprNom = $s['emprunteur_type'] === 'externe' ? ($s['emprunteur_nom'] . ' (externe)') : $nomMembre((int) $s['emprunteur_membre_id']);
                            $n = sl_envoyer(sortie_destinataires($pdo, $s, $membreInfo, $cid), 'Rapport de retour — ' . $commission['nom'], sortie_mail_inner($s, $lignes, $commission['nom'], $nomMembre((int) $s['responsable_id']), $emprNom, true), 'Retour de matériel');
                            $success .= " Rapport de retour envoyé par e-mail ($n destinataire" . ($n > 1 ? 's' : '') . ").";
                        }
                        $_SESSION['sortie_flash'] = $success;
                        header('Location: stock_sorties.php?id=' . $cid);
                        exit;
                    }
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Touba Lyon sorties : ' . $e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : "Une erreur technique est survenue.";
        }
    }
}

// Sorties de la commission
$sorties = []; $lignesParSortie = [];
try {
    $q = $pdo->prepare("SELECT * FROM stock_sorties WHERE commission_id = ? ORDER BY (statut = 'retourne') ASC, id DESC LIMIT 100");
    $q->execute([$cid]);
    $sorties = $q->fetchAll();
    if (!empty($sorties)) {
        $ph = implode(',', array_fill(0, count($sorties), '?'));
        $q = $pdo->prepare("SELECT l.*, c.nom FROM stock_sortie_lignes l JOIN commission_stock c ON c.id = l.stock_id WHERE l.sortie_id IN ($ph)");
        $q->execute(array_map(function ($s) { return (int) $s['id']; }, $sorties));
        foreach ($q->fetchAll() as $l) { $lignesParSortie[(int) $l['sortie_id']][] = $l; }
    }
} catch (Exception $e) { $sorties = []; }
$enCours = array_values(array_filter($sorties, function ($s) { return $s['statut'] !== 'retourne'; }));
$histo = array_values(array_filter($sorties, function ($s) { return $s['statut'] === 'retourne'; }));
$ouvrirForm = ($error !== '' && ($_POST['action'] ?? '') === 'creer_sortie');
$csrf = csrf_field();
$empNomAffiche = function (array $s) use ($nomMembre) {
    if ($s['type'] !== 'pret') { return ''; }
    return $s['emprunteur_type'] === 'externe' ? trim((string) $s['emprunteur_nom']) . ' (externe — ' . $s['emprunteur_email'] . ')' : $nomMembre((int) $s['emprunteur_membre_id']);
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📤 Sorties de stock — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .so-wrap { max-width: 900px; margin: 0 auto; }
        .so-card { border: 1px solid rgba(212,175,55,0.38); border-radius: 12px; background: rgba(255,255,255,0.03); padding: 0.85rem 1rem; margin-bottom: 0.6rem; }
        .so-top { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; }
        .so-titre { color: var(--white); font-weight: 800; }
        .so-meta { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.2rem; line-height: 1.5; }
        .so-ligne { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; padding: 0.45rem 0; border-top: 1px solid rgba(255,255,255,0.07); }
        .so-ligne .so-nom { flex: 1 1 160px; color: var(--white); font-size: 0.9rem; }
        .so-ligne input[type=number] { width: 5.5rem; }
        .so-form-ligne { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem; }
        .so-form-ligne select { flex: 1; min-width: 0; }
        .so-form-ligne input { width: 6rem; }
        .so-sub { color: var(--text-muted); font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin: 1rem 0 0.4rem; }
        #so-lignes { margin-bottom: 0.5rem; }
        #so-lignes:empty::before { content: "Aucun article choisi."; display: block; color: var(--text-muted); font-size: 0.88rem; margin-bottom: 0.2rem; }
        .so-sel { display: flex; align-items: center; gap: 0.6rem; padding: 0.45rem 0.6rem; border: 1px solid rgba(212,175,55,0.3); border-radius: 10px; background: rgba(255,255,255,0.03); margin-bottom: 0.4rem; }
        .so-sel img, .so-sel .ph { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; flex: 0 0 auto; display: flex; align-items: center; justify-content: center; background: rgba(212,175,55,0.1); }
        .so-sel-nom { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; color: var(--white); font-size: 0.9rem; font-weight: 700; line-height: 1.25; }
        .so-sel-nom small { color: var(--text-muted); font-weight: 500; font-size: 0.74rem; }
        .so-sel input[type=number] { width: 5rem; flex: 0 0 auto; }
        .pk-card { width: min(860px, 96vw); height: min(88vh, 780px); height: min(88dvh, 780px); display: flex; flex-direction: column; background: #0c241a; border: 1px solid var(--glass-border); border-top: 4px solid var(--accent); border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); overflow: hidden; transform: scale(0.96) translateY(14px); transition: transform 0.3s cubic-bezier(0.34,1.56,0.64,1); }
        .modal-overlay.active .pk-card { transform: none; }
        .pk-head { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; padding: 0.9rem 1.1rem 0.4rem; }
        .pk-head h3 { margin: 0; font-size: 1.15rem; color: var(--gold); }
        .pk-x { background: transparent; border: 0; color: var(--text-muted); font-size: 1.4rem; line-height: 1; cursor: pointer; padding: 0.2rem 0.5rem; }
        .pk-tools { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; padding: 0.4rem 1.1rem 0.7rem; }
        .pk-tools input[type=text] { flex: 1 1 200px; min-width: 0; }
        .pk-chip { border: 1px solid rgba(212,175,55,0.45); background: transparent; color: var(--white); border-radius: 999px; padding: 0.45rem 0.95rem; font-size: 0.85rem; font-weight: 700; cursor: pointer; }
        .pk-chip.on { background: rgba(212,175,55,0.22); color: var(--gold); }
        .pk-grid { flex: 1 1 auto; overflow-y: auto; padding: 0.3rem 1.1rem 0.9rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.7rem; align-content: start; }
        .pk-item { position: relative; border: 2px solid rgba(255,255,255,0.1); border-radius: 12px; overflow: hidden; background: rgba(255,255,255,0.04); cursor: pointer; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .pk-item:hover { border-color: rgba(212,175,55,0.55); }
        .pk-item.on { border-color: var(--gold); box-shadow: 0 0 0 2px rgba(212,175,55,0.25); }
        .pk-item.off { opacity: 0.45; cursor: not-allowed; }
        .pk-img { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: #081c15; }
        .pk-ph { width: 100%; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; background: rgba(212,175,55,0.08); }
        .pk-check { position: absolute; top: 6px; right: 6px; width: 26px; height: 26px; border-radius: 50%; background: var(--gold); color: #0c241a; display: none; align-items: center; justify-content: center; font-weight: 900; font-size: 0.9rem; }
        .pk-item.on .pk-check { display: flex; }
        .pk-info { padding: 0.5rem 0.6rem 0.6rem; }
        .pk-nom { color: var(--white); font-weight: 700; font-size: 0.85rem; line-height: 1.25; }
        .pk-dispo { color: var(--text-muted); font-size: 0.75rem; margin-top: 0.15rem; }
        .pk-step { display: none; align-items: center; gap: 0.3rem; margin-top: 0.45rem; }
        .pk-item.on .pk-step { display: flex; }
        .pk-step button { width: 30px; height: 30px; border-radius: 999px; border: 1px solid var(--gold); background: transparent; color: var(--gold); font-weight: 800; font-size: 1rem; cursor: pointer; padding: 0; }
        .pk-step input { width: 48px; min-width: 0; text-align: center; padding: 0.3rem 0.2rem; }
        .pk-vide { display: none; text-align: center; color: var(--text-muted); padding: 1.5rem 0; grid-column: 1 / -1; }
        .pk-foot { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; padding: 0.8rem 1.1rem; border-top: 1px solid rgba(255,255,255,0.1); }
        .pk-foot .pk-n { color: var(--text-muted); font-size: 0.88rem; }
        .pk-foot .pk-btns { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">
            <div class="so-wrap">
                <div class="admin-welcome-banner glass-card" style="margin-bottom:1rem; padding:1.1rem 1.5rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:0.8rem;">
                    <span>📤 Sorties de stock — <strong class="gold-text"><?php echo $h($commission['nom']); ?></strong></span>
                    <a href="commission_stock.php?id=<?php echo $cid; ?>" class="btn btn-secondary btn-sm">← Stock</a>
                </div>
                <?php if ($success !== ''): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($success); ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($error); ?></div><?php endif; ?>

                <?php if (!$stockEnabled): ?>
                    <div class="empty-state"><div class="empty-state-icon">📦</div><p>La gestion de stock n'est pas activée pour cette commission.</p></div>
                <?php else: ?>

                <details class="glass-card"<?php echo $ouvrirForm ? ' open' : ''; ?>>
                    <summary>➕ Nouvelle sortie d'équipement</summary>
                    <?php if (empty($items)): ?>
                        <p style="color:var(--text-muted);">Aucun article en stock pour le moment.</p>
                    <?php else: ?>
                    <form method="POST" action="stock_sorties.php?id=<?php echo $cid; ?>" id="so-form">
                        <?php echo $csrf; ?>
                        <input type="hidden" name="action" value="creer_sortie">
                        <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                        <div class="form-group"><label class="form-label">Type de sortie</label>
                            <select name="type" id="so-type" class="form-input">
                                <option value="evenement"<?php echo (($_POST['type'] ?? '') !== 'pret') ? ' selected' : ''; ?>>🎪 Événement</option>
                                <option value="pret"<?php echo (($_POST['type'] ?? '') === 'pret') ? ' selected' : ''; ?>>🤝 Prêt</option>
                            </select></div>
                        <div class="form-group"><label class="form-label">Description <span style="color:var(--danger)">*</span></label>
                            <textarea name="description" class="form-input" rows="2" required placeholder="Ex : Gamou de Lyon — sonorisation et tables"><?php echo $h($_POST['description'] ?? ''); ?></textarea></div>
                        <div class="form-group"><label class="form-label">Responsable de la sortie <span style="color:var(--danger)">*</span></label>
                            <select name="responsable_id" class="form-input" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ($membresCom as $m): ?><option value="<?php echo (int) $m['id']; ?>"<?php echo (int) ($_POST['responsable_id'] ?? 0) === (int) $m['id'] ? ' selected' : ''; ?>><?php echo $h(trim($m['prenom'] . ' ' . $m['nom'])); ?></option><?php endforeach; ?>
                            </select></div>
                        <div id="so-pret" style="display:none;">
                            <div class="form-group"><label class="form-label">À qui prête-t-on ?</label>
                                <select name="emprunteur_type" id="so-emp-type" class="form-input">
                                    <option value="membre"<?php echo (($_POST['emprunteur_type'] ?? '') !== 'externe') ? ' selected' : ''; ?>>👤 Un membre</option>
                                    <option value="externe"<?php echo (($_POST['emprunteur_type'] ?? '') === 'externe') ? ' selected' : ''; ?>>🌍 Une personne externe</option>
                                </select></div>
                            <div class="form-group" id="so-emp-membre"><label class="form-label">Membre emprunteur</label>
                                <select name="emprunteur_membre_id" class="form-input">
                                    <option value="">— Choisir un membre —</option>
                                    <?php foreach ($tousMembres as $m): ?><option value="<?php echo (int) $m['id']; ?>"<?php echo (int) ($_POST['emprunteur_membre_id'] ?? 0) === (int) $m['id'] ? ' selected' : ''; ?>><?php echo $h(trim($m['nom'] . ' ' . $m['prenom'])); ?></option><?php endforeach; ?>
                                </select></div>
                            <div id="so-emp-externe" style="display:none;">
                                <div class="form-group"><label class="form-label">Nom de la personne / structure</label><input type="text" name="ext_nom" class="form-input" value="<?php echo $h($_POST['ext_nom'] ?? ''); ?>"></div>
                                <div class="form-group"><label class="form-label">Adresse e-mail <span style="color:var(--danger)">*</span></label><input type="email" name="ext_email" class="form-input" value="<?php echo $h($_POST['ext_email'] ?? ''); ?>" placeholder="Pour recevoir le rapport de prêt"></div>
                            </div>
                            <div class="form-group"><label class="form-label">Retour prévu le (facultatif)</label><input type="date" name="date_retour_prevue" class="form-input" value="<?php echo $h($_POST['date_retour_prevue'] ?? ''); ?>"></div>
                        </div>
                        <div class="form-group"><label class="form-label">Date de sortie</label><input type="date" name="date_sortie" class="form-input" value="<?php echo $h($_POST['date_sortie'] ?? date('Y-m-d')); ?>"></div>
                        <?php
                            // Articles déjà choisis (conservés si l'enregistrement a échoué)
                            $preSel = [];
                            if ($error !== '' && ($_POST['action'] ?? '') === 'creer_sortie') {
                                foreach ((array) ($_POST['item_id'] ?? []) as $k => $iid) {
                                    $iid = (int) $iid; $qq = (int) (((array) ($_POST['qte'] ?? []))[$k] ?? 1);
                                    if ($iid > 0 && isset($itemsById[$iid])) { $preSel[$iid] = ($preSel[$iid] ?? 0) + max(1, $qq); }
                                }
                            }
                        ?>
                        <div class="form-group"><label class="form-label">Articles à sortir <span style="color:var(--danger)">*</span></label>
                            <div id="so-lignes" data-items="<?php echo $h(json_encode(array_map(function ($i) { return ['id' => (int) $i['id'], 'nom' => (string) $i['nom'], 'q' => (int) $i['quantite'], 'photo' => (string) ($i['photo'] ?? '')]; }, $items), JSON_UNESCAPED_UNICODE)); ?>"><?php foreach ($preSel as $iid => $qq) { echo so_ligne_html($itemsById[$iid], $qq); } ?></div>
                            <button type="button" class="btn btn-secondary btn-sm" id="so-pick-open">🖼️ Choisir les articles</button></div>
                        <button type="submit" class="btn btn-primary">📤 Enregistrer la sortie</button>
                    </form>
                    <?php endif; ?>
                </details>

                <div class="so-sub">📤 Sorties en cours (<?php echo count($enCours); ?>)</div>
                <?php if (empty($enCours)): ?><p style="color:var(--text-muted); margin:0 0 1rem;">Aucune sortie en cours.</p><?php endif; ?>
                <?php foreach ($enCours as $s): $lg = $lignesParSortie[(int) $s['id']] ?? []; ?>
                    <div class="so-card">
                        <div class="so-top">
                            <span class="so-titre"><?php echo $s['type'] === 'pret' ? '🤝 Prêt' : '🎪 Événement'; ?> — <?php echo $h(date('d/m/Y', strtotime((string) $s['date_sortie']))); ?></span>
                            <span class="badge"><?php echo $s['statut'] === 'partiel' ? '🔄 Retour partiel' : '⏳ En cours'; ?></span>
                        </div>
                        <div class="so-meta">
                            <?php echo nl2br($h($s['description'])); ?><br>
                            🎖️ Responsable : <?php echo $h($nomMembre((int) $s['responsable_id'])); ?>
                            <?php if ($s['type'] === 'pret'): ?><br>👤 Emprunteur : <?php echo $h($empNomAffiche($s)); ?><?php endif; ?>
                            <?php if (!empty($s['date_retour_prevue'])): ?><br>⏳ Retour prévu le <?php echo $h(date('d/m/Y', strtotime((string) $s['date_retour_prevue']))); ?><?php endif; ?>
                        </div>
                        <form method="POST" action="stock_sorties.php?id=<?php echo $cid; ?>" style="margin-top:0.5rem;">
                            <?php echo $csrf; ?>
                            <input type="hidden" name="action" value="retour">
                            <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                            <input type="hidden" name="sortie_id" value="<?php echo (int) $s['id']; ?>">
                            <?php foreach ($lg as $l): $reste = (int) $l['quantite'] - (int) $l['quantite_retournee']; ?>
                                <div class="so-ligne">
                                    <span class="so-nom"><?php echo $h($l['nom']); ?> <span style="color:var(--text-muted);">· sorti : <?php echo (int) $l['quantite']; ?> · déjà rendu : <?php echo (int) $l['quantite_retournee']; ?></span></span>
                                    <?php if ($reste > 0): ?>
                                        <input type="number" class="form-input" name="retour[<?php echo (int) $l['id']; ?>]" min="0" max="<?php echo $reste; ?>" value="<?php echo $reste; ?>" title="Quantité retournée (reste : <?php echo $reste; ?>)">
                                        <select class="form-input" name="etat[<?php echo (int) $l['id']; ?>]" style="width:9.5rem;">
                                            <?php foreach (stock_etats() as $ek => $el): ?><option value="<?php echo $ek; ?>"><?php echo $h($el); ?></option><?php endforeach; ?>
                                        </select>
                                    <?php else: ?><span class="badge badge-approved">✅ Rendu</span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:0.5rem;">↩️ Enregistrer le retour en stock</button>
                        </form>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($histo)): ?>
                <details class="glass-card" style="margin-top:1rem;">
                    <summary>✅ Historique des sorties retournées (<?php echo count($histo); ?>)</summary>
                    <?php foreach ($histo as $s): $lg = $lignesParSortie[(int) $s['id']] ?? []; ?>
                        <div class="so-card">
                            <div class="so-top"><span class="so-titre"><?php echo $s['type'] === 'pret' ? '🤝 Prêt' : '🎪 Événement'; ?> — <?php echo $h(date('d/m/Y', strtotime((string) $s['date_sortie']))); ?></span><span class="badge badge-approved">Retourné<?php echo !empty($s['retour_at']) ? ' le ' . $h(date('d/m/Y', strtotime((string) $s['retour_at']))) : ''; ?></span></div>
                            <div class="so-meta"><?php echo nl2br($h($s['description'])); ?><?php if ($s['type'] === 'pret'): ?><br>👤 <?php echo $h($empNomAffiche($s)); ?><?php endif; ?>
                                <br><?php foreach ($lg as $l): ?><?php echo $h($l['nom']); ?> ×<?php echo (int) $l['quantite']; ?> <?php echo stock_etat_badge((string) ($l['etat_retour'] ?? 'bon')); ?> <?php endforeach; ?></div>
                        </div>
                    <?php endforeach; ?>
                </details>
                <?php endif; ?>

                <?php endif; ?>
            </div>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <?php if ($stockEnabled && !empty($items)): ?>
    <div id="so-pick" class="modal-overlay" style="display:none;" aria-hidden="true">
        <div class="pk-card" role="dialog" aria-modal="true" aria-label="Choisir les articles à sortir">
            <div class="pk-head"><h3>🖼️ Articles à sortir</h3><button type="button" class="pk-x" id="pk-close" aria-label="Fermer">✕</button></div>
            <div class="pk-tools">
                <input type="text" id="pk-search" class="form-input" placeholder="🔍 Rechercher un article…" autocomplete="off">
                <button type="button" class="pk-chip on" id="pk-all">Tous</button>
                <button type="button" class="pk-chip" id="pk-sel">Sélectionnés</button>
            </div>
            <div class="pk-grid" id="pk-grid"></div>
            <div class="pk-foot">
                <span class="pk-n" id="pk-n"></span>
                <span class="pk-btns"><button type="button" class="btn btn-secondary btn-sm" id="pk-cancel">Annuler</button><button type="button" class="btn btn-primary btn-sm" id="pk-ok">✅ Valider la sélection</button></span>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <script>
        (function () {
            function $(id) { return document.getElementById(id); }
            function norm(s) { s = (s || '').toLowerCase(); try { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {} return s; }
            function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (txt !== undefined) { e.textContent = txt; } return e; }
            function items() { var b = $('so-lignes'); try { return JSON.parse(b.getAttribute('data-items') || '[]'); } catch (e) { return []; } }
            function lignes() {
                var m = {}, b = $('so-lignes');
                if (b) { Array.prototype.forEach.call(b.querySelectorAll('.so-sel'), function (d) { m[d.getAttribute('data-id')] = parseInt(d.querySelector('input[type=number]').value, 10) || 1; }); }
                return m;
            }
            function photoNode(it, cls, phCls, phTxt) {
                if (it.photo) { var i = el('img', cls); i.src = 'uploads/' + encodeURIComponent(it.photo); i.alt = ''; i.loading = 'lazy'; return i; }
                return el('div', phCls, phTxt || '📦');
            }
            var draft = {}, seulementSel = false;

            // ----- ligne choisie dans le formulaire
            function ligne(it, q) {
                var d = el('div', 'so-sel'); d.setAttribute('data-id', it.id);
                d.appendChild(it.photo ? photoNode(it, '', '') : el('span', 'ph', '📦'));
                var n = el('span', 'so-sel-nom', it.nom); n.appendChild(el('small', '', 'dispo : ' + it.q)); d.appendChild(n);
                var qi = el('input', 'form-input'); qi.type = 'number'; qi.name = 'qte[]'; qi.min = '1'; qi.max = String(Math.max(1, it.q)); qi.value = q; d.appendChild(qi);
                var h = el('input'); h.type = 'hidden'; h.name = 'item_id[]'; h.value = it.id; d.appendChild(h);
                var b = el('button', 'btn btn-secondary btn-sm so-sel-del', '✕'); b.type = 'button'; b.setAttribute('aria-label', 'Retirer'); d.appendChild(b);
                return d;
            }
            function majBouton() {
                var b = $('so-pick-open'); if (!b) { return; }
                var n = Object.keys(lignes()).length;
                b.textContent = n ? '✏️ Modifier la sélection (' + n + ')' : '🖼️ Choisir les articles';
            }

            // ----- fenêtre de choix
            function majCompte() {
                var n = Object.keys(draft).length;
                $('pk-n').textContent = n + ' article' + (n > 1 ? 's' : '') + ' sélectionné' + (n > 1 ? 's' : '');
                $('pk-sel').textContent = 'Sélectionnés (' + n + ')';
            }
            function filtrer() {
                var q = norm($('pk-search').value.trim()), vus = 0;
                Array.prototype.forEach.call($('pk-grid').querySelectorAll('.pk-item'), function (c) {
                    var ok = (q === '' || (c.getAttribute('data-t') || '').indexOf(q) !== -1) && (!seulementSel || c.classList.contains('on'));
                    c.style.display = ok ? '' : 'none';
                    if (ok) { vus++; }
                });
                $('pk-vide').style.display = vus ? 'none' : 'block';
            }
            function carte(it) {
                var on = Object.prototype.hasOwnProperty.call(draft, it.id);
                var c = el('div', 'pk-item' + (on ? ' on' : '') + (it.q <= 0 ? ' off' : ''));
                c.setAttribute('data-id', it.id); c.setAttribute('data-t', norm(it.nom));
                c.appendChild(photoNode(it, 'pk-img', 'pk-ph', '📦'));
                c.appendChild(el('span', 'pk-check', '✓'));
                var inf = el('div', 'pk-info');
                inf.appendChild(el('div', 'pk-nom', it.nom));
                inf.appendChild(el('div', 'pk-dispo', it.q > 0 ? 'Disponible : ' + it.q : 'Épuisé'));
                var st = el('div', 'pk-step');
                var mo = el('button', '', '−'); mo.type = 'button'; mo.setAttribute('data-d', '-1');
                var qi = el('input', 'form-input'); qi.type = 'number'; qi.min = '1'; qi.max = String(Math.max(1, it.q)); qi.value = on ? draft[it.id] : 1; qi.setAttribute('inputmode', 'numeric');
                var pl = el('button', '', '+'); pl.type = 'button'; pl.setAttribute('data-d', '1');
                st.appendChild(mo); st.appendChild(qi); st.appendChild(pl);
                inf.appendChild(st); c.appendChild(inf);
                return c;
            }
            function ouvrir() {
                var o = $('so-pick'); if (!o) { return; }
                draft = lignes(); seulementSel = false;
                $('pk-search').value = ''; $('pk-all').classList.add('on'); $('pk-sel').classList.remove('on');
                var g = $('pk-grid'); g.innerHTML = '';
                items().forEach(function (it) { g.appendChild(carte(it)); });
                g.appendChild(el('div', 'pk-vide', 'Aucun article trouvé.')).id = 'pk-vide';
                majCompte(); filtrer();
                o.style.display = 'flex'; o.setAttribute('aria-hidden', 'false');
                window.requestAnimationFrame(function () { o.classList.add('active'); });
                document.body.style.overflow = 'hidden';
            }
            function fermer() {
                var o = $('so-pick'); if (!o || !o.classList.contains('active')) { return; }
                o.classList.remove('active'); o.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                setTimeout(function () { if (!o.classList.contains('active')) { o.style.display = 'none'; } }, 320);
            }
            function valider() {
                var b = $('so-lignes');
                if (b) { b.innerHTML = ''; items().forEach(function (it) { if (Object.prototype.hasOwnProperty.call(draft, it.id)) { b.appendChild(ligne(it, draft[it.id])); } }); }
                majBouton(); fermer();
            }
            function bornes(it, v) { v = parseInt(v, 10) || 1; return Math.max(1, Math.min(Math.max(1, it.q), v)); }
            function article(id) { var r = null; items().forEach(function (it) { if (String(it.id) === String(id)) { r = it; } }); return r; }

            document.addEventListener('click', function (e) {
                var t = e.target;
                if (t.closest('#so-pick-open')) { e.preventDefault(); ouvrir(); return; }
                var del = t.closest('.so-sel-del');
                if (del) { var d = del.closest('.so-sel'); if (d) { d.remove(); majBouton(); } return; }
                var o = $('so-pick');
                if (!o || !o.classList.contains('active')) { return; }
                if (t === o || t.closest('#pk-close') || t.closest('#pk-cancel')) { fermer(); return; }
                if (t.closest('#pk-ok')) { valider(); return; }
                if (t.closest('#pk-all')) { seulementSel = false; $('pk-all').classList.add('on'); $('pk-sel').classList.remove('on'); filtrer(); return; }
                if (t.closest('#pk-sel')) { seulementSel = true; $('pk-sel').classList.add('on'); $('pk-all').classList.remove('on'); filtrer(); return; }
                var card = t.closest('.pk-item'); if (!card) { return; }
                var it = article(card.getAttribute('data-id')); if (!it) { return; }
                var qi = card.querySelector('.pk-step input');
                var bt = t.closest('.pk-step button');
                if (bt) { var v = bornes(it, (parseInt(qi.value, 10) || 1) + parseInt(bt.getAttribute('data-d'), 10)); qi.value = v; draft[it.id] = v; return; }
                if (t.closest('.pk-step')) { return; }
                if (it.q <= 0) { return; }
                if (Object.prototype.hasOwnProperty.call(draft, it.id)) { delete draft[it.id]; card.classList.remove('on'); }
                else { draft[it.id] = bornes(it, qi.value); card.classList.add('on'); }
                majCompte();
                if (seulementSel) { filtrer(); }
            });
            document.addEventListener('input', function (e) {
                if (e.target.id === 'pk-search') { filtrer(); return; }
                var qi = e.target.closest ? e.target.closest('.pk-step input') : null;
                if (qi) { var card = qi.closest('.pk-item'), it = card && article(card.getAttribute('data-id')); if (it && Object.prototype.hasOwnProperty.call(draft, it.id)) { draft[it.id] = bornes(it, qi.value); } }
            });
            document.addEventListener('change', function (e) {
                var qi = e.target.closest ? e.target.closest('.pk-step input') : null;
                if (qi) { var card = qi.closest('.pk-item'), it = card && article(card.getAttribute('data-id')); if (it) { qi.value = bornes(it, qi.value); if (Object.prototype.hasOwnProperty.call(draft, it.id)) { draft[it.id] = parseInt(qi.value, 10); } } }
            });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fermer(); } });
            // enregistrement : au moins un article
            document.addEventListener('submit', function (e) {
                var f = e.target;
                if (f && f.id === 'so-form' && Object.keys(lignes()).length === 0) {
                    e.preventDefault(); e.stopPropagation();
                    if (window.modernAlert) { window.modernAlert('Choisissez au moins un article à sortir.'); } else { ouvrir(); }
                }
            }, true);

            // ----- type de sortie / emprunteur
            function maj() {
                var type = $('so-type'), pret = $('so-pret'), et = $('so-emp-type');
                if (!type || !pret || !et) { return; }
                pret.style.display = type.value === 'pret' ? '' : 'none';
                $('so-emp-membre').style.display = et.value === 'externe' ? 'none' : '';
                $('so-emp-externe').style.display = et.value === 'externe' ? '' : 'none';
            }
            document.addEventListener('change', function (e) { if (e.target.id === 'so-type' || e.target.id === 'so-emp-type') { maj(); } });
            document.addEventListener('soft:updated', function () { maj(); majBouton(); });
            maj(); majBouton();
        })();
    </script>
</body>
</html>
