<?php
/**
 * Touba Lyon 2026 - Gestion de stock d'une commission.
 *
 * Accessible aux administrateurs et aux responsables de la commission concernée,
 * uniquement si la gestion de stock a été activée par l'admin pour cette commission.
 * Chaque article : photo, nom, description, quantité, statut (dont « Non utilisable »).
 */
require_once __DIR__ . '/commission_guard.php'; // $__isAdmin, $__managedCommissions, $pdo
require_once __DIR__ . '/csrf.php';

$error = '';
$success = '';

$STOCK_STATUTS = ['Disponible', 'Réservé', 'Non utilisable'];

function stock_status_style($s)
{
    if ($s === 'Non utilisable') { return 'background:rgba(220,80,80,0.16); color:#ff9a9a; border:1px solid rgba(220,80,80,0.45);'; }
    if ($s === 'Réservé') { return 'background:rgba(212,175,55,0.16); color:#ffd873; border:1px solid rgba(212,175,55,0.45);'; }
    return 'background:rgba(45,106,79,0.22); color:#7bd8a6; border:1px solid rgba(45,106,79,0.55);';
}

function stock_handle_upload($file, &$err)
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) { $err = "Format de photo invalide (JPG, PNG, WEBP)."; return null; }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) { $err = "Photo trop lourde (max 5 Mo)."; return null; }
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
        if ($mime && strpos($mime, 'image/') !== 0) { $err = "Le fichier n'est pas une image."; return null; }
    }
    $name = uniqid('stock_', true) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], __DIR__ . '/uploads/' . $name)) { $err = "Échec de l'enregistrement de la photo."; return null; }
    return $name;
}

/** Upload multiple (album photos) : retourne la liste des noms de fichiers. */
function stock_handle_uploads($files, &$err)
{
    $names = [];
    if (empty($files) || !isset($files['name']) || !is_array($files['name'])) { return $names; }
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
        $one = [
            'name' => $files['name'][$i], 'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i] ?? 0, 'size' => $files['size'][$i] ?? 0,
        ];
        $n = stock_handle_upload($one, $err);
        if ($n !== null) { $names[] = $n; }
        if ($err !== '') { break; }
    }
    return $names;
}

// Commission ciblée + droits
$cid = (int) ($_POST['commission_id'] ?? $_GET['id'] ?? 0);
$canManage = $__isAdmin || in_array($cid, $__managedCommissions, true);
$commission = null;
if ($cid > 0) {
    try {
        $st = $pdo->prepare("SELECT id, nom, COALESCE(stock_enabled,0) AS stock_enabled FROM commissions WHERE id = ?");
        $st->execute([$cid]);
        $commission = $st->fetch();
    } catch (Exception $e) {
        $commission = null;
    }
}
if (!$commission || !$canManage) {
    header('Location: commission_dashboard.php');
    exit;
}
$stockEnabled = ((int) $commission['stock_enabled'] === 1);

if ($stockEnabled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_lieu') {
                $ln = trim($_POST['lieu_nom'] ?? '');
                if ($ln !== '') {
                    $pdo->prepare("INSERT IGNORE INTO stock_lieux (nom) VALUES (?)")->execute([$ln]);
                    $success = "Lieu de stockage ajouté.";
                } else {
                    $error = "Le nom du lieu est obligatoire.";
                }
            } elseif ($action === 'add_item') {
                $nom = trim($_POST['nom'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $qte = max(0, (int) ($_POST['quantite'] ?? 0));
                $statut = in_array($_POST['statut'] ?? '', $STOCK_STATUTS, true) ? $_POST['statut'] : 'Disponible';
                $lieu = trim($_POST['lieu'] ?? '');
                $dateAchat = trim($_POST['date_achat'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}$/', $dateAchat)) { $dateAchat = ''; }
                $prixRaw = str_replace(',', '.', trim($_POST['prix_achat'] ?? ''));
                $prix = ($prixRaw !== '' && is_numeric($prixRaw)) ? (float) $prixRaw : null;
                if ($nom === '') {
                    $error = "Le nom de l'article est obligatoire.";
                } else {
                    $photo = stock_handle_upload($_FILES['photo'] ?? null, $error);
                    $album = ($error === '') ? stock_handle_uploads($_FILES['album'] ?? null, $error) : [];
                    if ($error === '') {
                        // Si pas de photo de couverture mais des photos d'album, la 1re devient la couverture.
                        if ($photo === null && !empty($album)) { $photo = $album[0]; }
                        $pdo->prepare("INSERT INTO commission_stock (commission_id, nom, description, quantite, statut, lieu, date_achat, prix_achat, photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                            ->execute([$cid, $nom, $desc !== '' ? $desc : null, $qte, $statut, $lieu !== '' ? $lieu : null, $dateAchat !== '' ? $dateAchat : null, $prix, $photo]);
                        $newId = (int) $pdo->lastInsertId();
                        if (!empty($album) && $newId > 0) {
                            $insP = $pdo->prepare("INSERT INTO commission_stock_photos (item_id, photo) VALUES (?, ?)");
                            foreach ($album as $ap) { $insP->execute([$newId, $ap]); }
                        }
                        $success = "Article ajouté au stock.";
                    }
                }
            } elseif ($action === 'update_item') {
                $iid = (int) ($_POST['item_id'] ?? 0);
                $nom = trim($_POST['nom'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $qte = max(0, (int) ($_POST['quantite'] ?? 0));
                $statut = in_array($_POST['statut'] ?? '', $STOCK_STATUTS, true) ? $_POST['statut'] : 'Disponible';
                $lieu = trim($_POST['lieu'] ?? '');
                $dateAchat = trim($_POST['date_achat'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}$/', $dateAchat)) { $dateAchat = ''; }
                $prixRaw = str_replace(',', '.', trim($_POST['prix_achat'] ?? ''));
                $prix = ($prixRaw !== '' && is_numeric($prixRaw)) ? (float) $prixRaw : null;
                if ($iid > 0 && $nom !== '') {
                    $photo = stock_handle_upload($_FILES['photo'] ?? null, $error);
                    if ($error === '') {
                        if ($photo !== null) {
                            $pdo->prepare("UPDATE commission_stock SET nom=?, description=?, quantite=?, statut=?, lieu=?, date_achat=?, prix_achat=?, photo=? WHERE id=? AND commission_id=?")
                                ->execute([$nom, $desc !== '' ? $desc : null, $qte, $statut, $lieu !== '' ? $lieu : null, $dateAchat !== '' ? $dateAchat : null, $prix, $photo, $iid, $cid]);
                        } else {
                            $pdo->prepare("UPDATE commission_stock SET nom=?, description=?, quantite=?, statut=?, lieu=?, date_achat=?, prix_achat=? WHERE id=? AND commission_id=?")
                                ->execute([$nom, $desc !== '' ? $desc : null, $qte, $statut, $lieu !== '' ? $lieu : null, $dateAchat !== '' ? $dateAchat : null, $prix, $iid, $cid]);
                        }
                        $success = "Article mis à jour.";
                    }
                } else {
                    $error = "Le nom de l'article est obligatoire.";
                }
            } elseif ($action === 'set_status') {
                $iid = (int) ($_POST['item_id'] ?? 0);
                $statut = in_array($_POST['statut'] ?? '', $STOCK_STATUTS, true) ? $_POST['statut'] : 'Disponible';
                if ($iid > 0) {
                    $pdo->prepare("UPDATE commission_stock SET statut=? WHERE id=? AND commission_id=?")->execute([$statut, $iid, $cid]);
                    $success = "Statut mis à jour.";
                }
            } elseif ($action === 'adjust_qty') {
                $iid = (int) ($_POST['item_id'] ?? 0);
                $delta = (int) ($_POST['delta'] ?? 0);
                if ($iid > 0 && $delta !== 0) {
                    $pdo->prepare("UPDATE commission_stock SET quantite = GREATEST(0, quantite + ?) WHERE id=? AND commission_id=?")->execute([$delta, $iid, $cid]);
                    $success = "Quantité mise à jour.";
                }
            } elseif ($action === 'delete_item') {
                $iid = (int) ($_POST['item_id'] ?? 0);
                if ($iid > 0) {
                    $pdo->prepare("DELETE FROM commission_stock WHERE id=? AND commission_id=?")->execute([$iid, $cid]);
                    $success = "Article supprimé.";
                }
            }
        } catch (Exception $e) {
            error_log('Touba Lyon commission_stock: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
}

try {
    $items = [];
    $st = $pdo->prepare("SELECT * FROM commission_stock WHERE commission_id = ? ORDER BY nom ASC");
    $st->execute([$cid]);
    $items = $st->fetchAll();
} catch (Exception $e) {
    $items = [];
}
try { $lieux = $pdo->query("SELECT nom FROM stock_lieux ORDER BY nom ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $lieux = []; }

// Indicateurs
$stkTotalQty = 0; $stkDispo = 0; $stkReserve = 0; $stkNonUtil = 0;
$stkValeur = 0.0; $stkValDispo = 0.0; $stkValReserve = 0.0; $stkValNonUtil = 0.0;
$stkValByYear = [];
foreach ($items as $it) {
    $stkTotalQty += (int) $it['quantite'];
    $v = ($it['prix_achat'] !== null) ? (float) $it['prix_achat'] * (int) $it['quantite'] : 0.0;
    $stkValeur += $v;
    if ($it['statut'] === 'Non utilisable') { $stkNonUtil++; $stkValNonUtil += $v; }
    elseif ($it['statut'] === 'Réservé') { $stkReserve++; $stkValReserve += $v; }
    else { $stkDispo++; $stkValDispo += $v; }
    if ($v > 0 && !empty($it['date_achat']) && preg_match('/^(\d{4})-/', $it['date_achat'], $ym)) {
        $stkValByYear[$ym[1]] = ($stkValByYear[$ym[1]] ?? 0) + $v;
    }
}
if (!empty($stkValByYear)) { krsort($stkValByYear); }
$totalQty = 0;
foreach ($items as $it) { $totalQty += (int) $it['quantite']; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock — <?php echo htmlspecialchars($commission['nom']); ?> — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .stock-wrap { max-width: 980px; margin: 2rem auto; }
        .stock-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
        .stock-card { border-radius: 16px; overflow: hidden; padding: 0; display: flex; flex-direction: column; }
        .stock-photo { width: 100%; aspect-ratio: 4/3; object-fit: cover; background: #081c15; display: block; }
        .stock-photo-ph { width: 100%; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center; font-size: 2.4rem; color: var(--gold); background: rgba(212,175,55,0.08); }
        .stock-body { padding: 0.9rem 1rem 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
        .stock-name { font-weight: 700; color: var(--white); font-size: 1rem; }
        .stock-desc { color: var(--text-muted); font-size: 0.82rem; line-height: 1.4; }
        .stock-badge { display: inline-block; font-size: 0.72rem; font-weight: 700; border-radius: 50px; padding: 2px 10px; width: fit-content; }
        .stock-qty { display: flex; align-items: center; gap: 0.4rem; }
        .stock-qty .q { min-width: 2.2rem; text-align: center; font-weight: 700; color: var(--white); }
        .stock-qty button { width: 28px; height: 28px; border-radius: 8px; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); color: #fff; font-size: 1rem; cursor: pointer; }
        .stock-qty button:hover { border-color: var(--accent); }
        .stock-row-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.25rem; }
        .stock-row-actions .btn { padding: 0.32rem 0.6rem; font-size: 0.72rem; }
        .stock-select { background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 8px; color: #fff; font-size: 0.78rem; padding: 0.3rem 0.5rem; color-scheme: dark; }
        .stock-select option { background-color: #0c241a; }
        /* miniature d'upload (photo / album) */
        .photo-tile { width: 116px; height: 116px; border-radius: 14px; border: 2px dashed var(--glass-border); background: rgba(255,255,255,0.04); display: flex; align-items: center; justify-content: center; cursor: pointer; overflow: hidden; position: relative; }
        .photo-tile:hover { border-color: var(--accent); }
        .photo-tile img { width: 100%; height: 100%; object-fit: cover; }
        .photo-tile .ph { display: flex; flex-direction: column; align-items: center; gap: 0.25rem; font-size: 0.72rem; color: var(--text-muted); text-align: center; padding: 0.5rem; }
        .photo-tile input[type=file] { display: none; }
        .photo-strip { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.6rem; }
        .photo-strip img { width: 54px; height: 54px; object-fit: cover; border-radius: 8px; border: 1px solid var(--glass-border); }
        /* les inputs date/nombre remplissent leur conteneur (évite le débordement mobile) */
        input[type="month"].form-input, input[type="number"].form-input { width: 100%; max-width: 100%; box-sizing: border-box; }
        /* mobile : empiler les lignes de champs (évite le chevauchement date/prix, etc.) */
        @media (max-width: 520px) {
            #stock-add-section form > div[style*="display:flex"],
            .stock-modal-body > div[style*="display:flex"] { flex-direction: column !important; gap: 0.35rem !important; }
            #stock-add-section .form-group, .stock-modal-body .form-group { min-width: 0 !important; flex-basis: auto !important; }
        }
        /* indicateurs */
        .stk-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
        .stk-stat { border-radius: 14px; padding: 0.85rem 1rem; text-align: center; }
        .stk-stat .n { font-size: 1.5rem; font-weight: 800; color: var(--white); line-height: 1.1; }
        .stk-stat .l { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; margin-top: 0.2rem; }
        /* barre filtres + vue */
        .stk-toolbar { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
        .stk-toolbar input, .stk-toolbar select { background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); font-size: 0.88rem; padding: 0.55rem 0.9rem; color-scheme: dark; }
        .stk-toolbar input { flex: 1 1 200px; border-radius: 50px; }
        .stk-toolbar select { flex: 0 1 150px; border-radius: 10px; }
        .stk-toolbar select option { background-color: #0c241a; color: #fff; }
        .stk-toolbar input:focus, .stk-toolbar select:focus { outline: none; border-color: var(--accent); }
        .stk-count { font-size: 0.8rem; color: var(--text-muted); }
        .stk-viewtog { display: inline-flex; border: 1px solid var(--glass-border); border-radius: 10px; overflow: hidden; margin-left: auto; }
        .stk-viewtog button { background: transparent; color: var(--text-muted); border: 0; padding: 0.45rem 0.8rem; font-size: 0.82rem; font-weight: 600; cursor: pointer; }
        .stk-viewtog button.active { background: var(--accent); color: var(--secondary); }
        /* vue liste */
        .stk-list { width: 100%; border-collapse: collapse; }
        .stk-list th { text-align: left; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em; color: var(--text-muted); padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--glass-border); }
        .stk-list td { padding: 0.55rem 0.6rem; border-bottom: 1px solid rgba(255,255,255,0.06); color: #fff; font-size: 0.86rem; vertical-align: middle; }
        .stk-list .li-photo { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; }
        .stk-list .li-actions { display: flex; gap: 0.3rem; flex-wrap: wrap; }
        .stk-list .li-actions .btn { padding: 0.28rem 0.55rem; font-size: 0.7rem; }
        @media (max-width: 720px) { .stk-list thead { display: none; } .stk-list, .stk-list tbody, .stk-list tr, .stk-list td { display: block; width: 100%; } .stk-list tr { border: 1px solid var(--glass-border); border-radius: 12px; margin-bottom: 0.7rem; padding: 0.6rem 0.8rem; } .stk-list td { border: none; padding: 0.2rem 0; } }
        /* modal edit (moderne) */
        #stock-modal { position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.62); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); display: none; align-items: center; justify-content: center; padding: 1rem; }
        #stock-modal.active { display: flex; }
        .stock-modal-card { width: 100%; max-width: 480px; max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; background: linear-gradient(180deg,#143a2b 0%, #0b2118 100%); border: 1px solid rgba(212,175,55,0.28); border-radius: 20px; box-shadow: 0 30px 80px rgba(0,0,0,0.6); transform: translateY(14px) scale(0.98); opacity: 0; transition: transform .28s cubic-bezier(.2,.8,.2,1), opacity .28s ease; }
        #stock-modal.active .stock-modal-card { transform: translateY(0) scale(1); opacity: 1; }
        .stock-modal-card form { display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
        .stock-modal-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.35rem; background: linear-gradient(135deg,#1b4332,#2d6a4f); flex-shrink: 0; }
        .stock-modal-head h3 { margin: 0; color: #fff; font-size: 1.05rem; }
        .stock-modal-head .x { background: rgba(255,255,255,0.15); color: #fff; border: 0; width: 28px; height: 28px; border-radius: 50%; font-size: 1.1rem; line-height: 1; cursor: pointer; flex-shrink: 0; }
        .stock-modal-head .x:hover { background: rgba(255,255,255,0.3); }
        .stock-modal-body { padding: 1.35rem; overflow-y: auto; flex: 1; }
        .stock-modal-foot { display: flex; gap: 0.5rem; justify-content: flex-end; padding: 0.9rem 1.35rem; border-top: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">

        <div class="stock-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <span>📦 Stock — <strong class="gold-text"><?php echo htmlspecialchars($commission['nom']); ?></strong> — <?php echo count($items); ?> article(s), <?php echo (int)$totalQty; ?> en stock</span>
                <a href="commission_dashboard.php" class="btn btn-secondary btn-sm">← Espaces commissions</a>
            </div>

            <?php if (!$stockEnabled): ?>
                <div class="empty-state"><div class="empty-state-icon">📦</div><p>La gestion de stock n'est pas activée pour cette commission.</p></div>
            <?php else: ?>

            <div style="margin-bottom:1.25rem;">
                <button type="button" class="btn btn-primary" onclick="toggleAddStock()">➕ Ajouter un article</button>
            </div>

            <div id="stock-add-section" style="display:none;">
            <!-- Ajouter un article -->
            <div class="form-card" style="max-width:none; margin-bottom:1.5rem; padding:1.5rem;">
                <h3 class="gold-text" style="font-size:1.15rem; font-weight:700; margin:0 0 1rem;">➕ Ajouter un article</h3>
                <form action="commission_stock.php?id=<?php echo $cid; ?>" method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                    <div class="form-group"><label class="form-label">Nom de l'article <span style="color:var(--danger)">*</span></label><input type="text" name="nom" class="form-input" required placeholder="Ex : Chaise, Marmite, Sono…"></div>
                    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
                        <div class="form-group" style="flex:1; min-width:110px;"><label class="form-label">Quantité</label><input type="number" name="quantite" class="form-input" min="0" value="1" inputmode="numeric" pattern="[0-9]*"></div>
                        <div class="form-group" style="flex:1; min-width:140px;"><label class="form-label">Statut</label>
                            <select name="statut" class="form-input">
                                <?php foreach ($STOCK_STATUTS as $s): ?><option value="<?php echo $s; ?>"><?php echo $s; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1; min-width:160px;"><label class="form-label">Lieu de stockage</label>
                            <select name="lieu" class="form-input">
                                <option value="">—</option>
                                <?php foreach ($lieux as $lg): ?><option value="<?php echo htmlspecialchars($lg, ENT_QUOTES); ?>"><?php echo htmlspecialchars($lg); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
                        <div class="form-group" style="flex:1; min-width:150px;"><label class="form-label">Date d'achat (facultatif)</label><input type="month" name="date_achat" class="form-input"></div>
                        <div class="form-group" style="flex:1; min-width:150px;"><label class="form-label">Prix d'achat ou valeur estimée € (facultatif)</label><input type="number" name="prix_achat" class="form-input" min="0" step="0.01" inputmode="decimal" placeholder="Ex : 25.00"></div>
                    </div>
                    <div class="form-group"><label class="form-label">Description (facultatif)</label><input type="text" name="description" class="form-input" placeholder="État, référence…"></div>
                    <div style="display:flex; gap:1.25rem; flex-wrap:wrap;">
                        <div class="form-group">
                            <label class="form-label">Photo (facultatif)</label>
                            <div class="photo-tile" onclick="this.querySelector('input').click()">
                                <img id="add-preview" alt="" style="display:none;">
                                <div class="ph" id="add-ph"><span style="font-size:1.7rem;">📷</span><span>Ajouter une photo</span></div>
                                <input type="file" name="photo" accept="image/*" onchange="tilePreview(this,'add-preview','add-ph')">
                            </div>
                        </div>
                        <div class="form-group" style="flex:1; min-width:140px;">
                            <label class="form-label">Album — plusieurs photos (facultatif)</label>
                            <div class="photo-tile" onclick="this.querySelector('input').click()">
                                <div class="ph"><span style="font-size:1.7rem;">🖼️</span><span>Ajouter des photos</span></div>
                                <input type="file" name="album[]" accept="image/*" multiple onchange="tileAlbum(this,'add-album-strip','add-album-count')">
                            </div>
                            <div class="photo-strip" id="add-album-strip"></div>
                            <span id="add-album-count" style="display:block; margin-top:0.35rem; font-size:0.78rem; color:var(--text-muted);"></span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Ajouter au stock</button>
                </form>
            </div>

            <!-- Lieux de stockage -->
            <div class="form-card" style="max-width:none; margin-bottom:1.5rem; padding:1.1rem 1.5rem;">
                <h3 class="gold-text" style="font-size:1rem; font-weight:700; margin:0 0 0.75rem;">📍 Lieux de stockage</h3>
                <div style="display:flex; flex-wrap:wrap; gap:0.4rem; margin-bottom:0.85rem;">
                    <?php if (empty($lieux)): ?>
                        <span style="color:var(--text-muted); font-size:0.85rem; font-style:italic;">Aucun lieu enregistré.</span>
                    <?php else: foreach ($lieux as $lg): ?>
                        <span class="stock-badge" style="background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:#fff;">📍 <?php echo htmlspecialchars($lg); ?></span>
                    <?php endforeach; endif; ?>
                </div>
                <form action="commission_stock.php?id=<?php echo $cid; ?>" method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_lieu">
                    <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                    <input type="text" name="lieu_nom" class="form-input" placeholder="Nouveau lieu (ex : Box 2, Garage…)" style="flex:1; min-width:200px;">
                    <button type="submit" class="btn btn-secondary btn-sm">➕ Ajouter le lieu</button>
                </form>
            </div>
            </div><!-- /stock-add-section -->

            <?php if (empty($items)): ?>
                <div class="empty-state"><div class="empty-state-icon">📦</div><p>Aucun article en stock. Ajoutez-en un ci-dessus.</p></div>
            <?php else: ?>
                <div style="margin-bottom:0.75rem;">
                    <button type="button" class="btn btn-secondary btn-sm" id="stk-indics-toggle" onclick="toggleIndics()">📊 Masquer les indicateurs</button>
                </div>
                <div id="stk-indics">
                <!-- Indicateurs : quantités -->
                <div class="stk-stats">
                    <div class="glass-card stk-stat"><div class="n"><?php echo count($items); ?></div><div class="l">Articles</div></div>
                    <div class="glass-card stk-stat"><div class="n"><?php echo (int)$stkTotalQty; ?></div><div class="l">En stock</div></div>
                    <div class="glass-card stk-stat"><div class="n" style="color:#7bd8a6;"><?php echo (int)$stkDispo; ?></div><div class="l">Disponibles</div></div>
                    <div class="glass-card stk-stat"><div class="n" style="color:#ffd873;"><?php echo (int)$stkReserve; ?></div><div class="l">Réservés</div></div>
                    <div class="glass-card stk-stat"><div class="n" style="color:#ff9a9a;"><?php echo (int)$stkNonUtil; ?></div><div class="l">Non utilisables</div></div>
                </div>
                <?php if ($stkValeur > 0): ?>
                <!-- Indicateurs : valeur (prix d'achat ou valeur estimée) par statut -->
                <div class="stk-stats">
                    <div class="glass-card stk-stat"><div class="n" style="font-size:1.1rem;"><?php echo htmlspecialchars(number_format($stkValeur, 2, ',', ' ')); ?> €</div><div class="l">Valeur totale</div></div>
                    <div class="glass-card stk-stat"><div class="n" style="font-size:1.05rem; color:#7bd8a6;"><?php echo htmlspecialchars(number_format($stkValDispo, 2, ',', ' ')); ?> €</div><div class="l">Val. disponibles</div></div>
                    <div class="glass-card stk-stat"><div class="n" style="font-size:1.05rem; color:#ffd873;"><?php echo htmlspecialchars(number_format($stkValReserve, 2, ',', ' ')); ?> €</div><div class="l">Val. réservés</div></div>
                    <div class="glass-card stk-stat"><div class="n" style="font-size:1.05rem; color:#ff9a9a;"><?php echo htmlspecialchars(number_format($stkValNonUtil, 2, ',', ' ')); ?> €</div><div class="l">Val. non utilisables</div></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($stkValByYear)): ?>
                <!-- Indicateurs : valeur (prix d'achat ou valeur estimée) par année d'achat -->
                <div class="stk-stats">
                    <?php foreach ($stkValByYear as $yr => $val): ?>
                    <div class="glass-card stk-stat"><div class="n" style="font-size:1.05rem;"><?php echo htmlspecialchars(number_format($val, 2, ',', ' ')); ?> €</div><div class="l">Année <?php echo htmlspecialchars($yr); ?></div></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                </div><!-- /stk-indics -->

                <!-- Filtres + choix de la vue -->
                <div class="stk-toolbar">
                    <input type="text" id="stk-search" placeholder="🔍 Rechercher un article…">
                    <select id="stk-statut"><option value="">Statut : tous</option><?php foreach ($STOCK_STATUTS as $s): ?><option value="<?php echo htmlspecialchars($s, ENT_QUOTES); ?>"><?php echo $s; ?></option><?php endforeach; ?></select>
                    <select id="stk-lieu"><option value="">Lieu : tous</option><?php foreach ($lieux as $lg): ?><option value="<?php echo htmlspecialchars($lg, ENT_QUOTES); ?>"><?php echo htmlspecialchars($lg); ?></option><?php endforeach; ?></select>
                    <select id="stk-annee"><option value="">Année : toutes</option><?php
                        $stkAnnees = [];
                        foreach ($items as $it) {
                            if (!empty($it['date_achat']) && preg_match('/^(\d{4})-/', $it['date_achat'], $ym2)) { $stkAnnees[$ym2[1]] = true; }
                        }
                        $stkAnnees = array_keys($stkAnnees); rsort($stkAnnees);
                        foreach ($stkAnnees as $an): ?><option value="<?php echo htmlspecialchars($an, ENT_QUOTES); ?>"><?php echo htmlspecialchars($an); ?></option><?php endforeach;
                        ?><option value="sans_date">Sans date</option></select>
                    <span class="stk-count" id="stk-count"></span>
                    <span class="stk-viewtog">
                        <button type="button" id="stk-view-grid" class="active" onclick="stkView('grid')">🔲 Grille</button>
                        <button type="button" id="stk-view-list" onclick="stkView('list')">📋 Liste</button>
                    </span>
                </div>

                <div id="stock-grid-view">
                <div class="stock-grid">
                    <?php foreach ($items as $it): ?>
                        <?php
                            $itJson = htmlspecialchars(json_encode([
                                'id' => (int)$it['id'], 'nom' => $it['nom'], 'description' => $it['description'] ?? '',
                                'quantite' => (int)$it['quantite'], 'statut' => $it['statut'], 'lieu' => $it['lieu'] ?? '',
                                'date_achat' => $it['date_achat'] ?? '', 'prix_achat' => ($it['prix_achat'] !== null ? (string)$it['prix_achat'] : ''),
                            ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                            $itSearch = htmlspecialchars(mb_strtolower(($it['nom'] ?? '') . ' ' . ($it['description'] ?? '') . ' ' . ($it['lieu'] ?? '')), ENT_QUOTES);
                            $itAnnee = '';
                            if (!empty($it['date_achat']) && preg_match('/^(\d{4})-\d{2}$/', $it['date_achat'], $ya)) { $itAnnee = $ya[1]; }
                        ?>
                        <div class="glass-card stock-card stk-filterable" data-search="<?php echo $itSearch; ?>" data-statut="<?php echo htmlspecialchars($it['statut'], ENT_QUOTES); ?>" data-lieu="<?php echo htmlspecialchars($it['lieu'] ?? '', ENT_QUOTES); ?>" data-annee="<?php echo htmlspecialchars($itAnnee, ENT_QUOTES); ?>" data-prix="<?php echo $it['prix_achat'] !== null ? (float)$it['prix_achat'] : ''; ?>" data-qte="<?php echo (int)$it['quantite']; ?>">
                            <?php if (!empty($it['photo'])): ?>
                                <img src="uploads/<?php echo htmlspecialchars($it['photo']); ?>" alt="" class="stock-photo">
                            <?php else: ?>
                                <div class="stock-photo-ph">📦</div>
                            <?php endif; ?>
                            <div class="stock-body">
                                <div class="stock-name"><?php echo htmlspecialchars($it['nom']); ?></div>
                                <span class="stock-badge" style="<?php echo stock_status_style($it['statut']); ?>"><?php echo htmlspecialchars($it['statut']); ?></span>
                                <?php if (!empty($it['lieu'])): ?><div style="color:var(--text-muted); font-size:0.8rem;">📍 <?php echo htmlspecialchars($it['lieu']); ?></div><?php endif; ?>
                                <?php if (!empty($it['description'])): ?><div class="stock-desc"><?php echo htmlspecialchars($it['description']); ?></div><?php endif; ?>
                                <div style="color:#fff; font-size:0.86rem;">Quantité : <strong><?php echo (int)$it['quantite']; ?></strong> <span style="color:var(--text-muted);">en stock</span></div>
                                <?php if (!empty($it['date_achat']) || $it['prix_achat'] !== null): ?>
                                <div style="color:var(--text-muted); font-size:0.8rem;">
                                    <?php if (!empty($it['date_achat'])): $__p = explode('-', $it['date_achat']); ?>🗓️ <?php echo htmlspecialchars(($__p[1] ?? '') . '/' . ($__p[0] ?? '')); ?><?php endif; ?>
                                    <?php if ($it['prix_achat'] !== null): ?><?php echo !empty($it['date_achat']) ? ' · ' : ''; ?>💶 <?php echo htmlspecialchars(number_format((float)$it['prix_achat'], 2, ',', ' ')); ?> €<?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <div class="stock-row-actions">
                                    <a href="stock_item.php?id=<?php echo (int)$it['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">👁️ Détails</a>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick='openStockEdit(<?php echo $itJson; ?>)'>✏️ Modifier</button>
                                    <form action="commission_stock.php?id=<?php echo $cid; ?>" method="POST" style="margin:0;" id="stock-del-form-<?php echo (int)$it['id']; ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                                        <input type="hidden" name="item_id" value="<?php echo (int)$it['id']; ?>">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteItem(<?php echo (int)$it['id']; ?>, <?php echo htmlspecialchars(json_encode($it['nom']), ENT_QUOTES); ?>)">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                </div><!-- /stock-grid-view -->

                <!-- Vue liste -->
                <div id="stock-list-view" style="display:none;">
                    <table class="stk-list">
                        <thead><tr><th>Photo</th><th>Nom</th><th>Statut</th><th>Lieu</th><th>Qté</th><th>Date</th><th>Prix</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($items as $it): ?>
                                <?php
                                    $liJson = htmlspecialchars(json_encode([
                                        'id' => (int)$it['id'], 'nom' => $it['nom'], 'description' => $it['description'] ?? '',
                                        'quantite' => (int)$it['quantite'], 'statut' => $it['statut'], 'lieu' => $it['lieu'] ?? '',
                                        'date_achat' => $it['date_achat'] ?? '', 'prix_achat' => ($it['prix_achat'] !== null ? (string)$it['prix_achat'] : ''),
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                                    $liSearch = htmlspecialchars(mb_strtolower(($it['nom'] ?? '') . ' ' . ($it['description'] ?? '') . ' ' . ($it['lieu'] ?? '')), ENT_QUOTES);
                                    $liDate = '';
                                    $liAnnee = '';
                                    if (!empty($it['date_achat']) && preg_match('/^(\d{4})-(\d{2})$/', $it['date_achat'], $m2)) { $liDate = $m2[2] . '/' . $m2[1]; $liAnnee = $m2[1]; }
                                ?>
                                <tr class="stk-filterable" data-search="<?php echo $liSearch; ?>" data-statut="<?php echo htmlspecialchars($it['statut'], ENT_QUOTES); ?>" data-lieu="<?php echo htmlspecialchars($it['lieu'] ?? '', ENT_QUOTES); ?>" data-annee="<?php echo htmlspecialchars($liAnnee, ENT_QUOTES); ?>" data-prix="<?php echo $it['prix_achat'] !== null ? (float)$it['prix_achat'] : ''; ?>" data-qte="<?php echo (int)$it['quantite']; ?>">
                                    <td><?php if (!empty($it['photo'])): ?><img src="uploads/<?php echo htmlspecialchars($it['photo']); ?>" alt="" class="li-photo"><?php else: ?><span style="font-size:1.4rem;">📦</span><?php endif; ?></td>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($it['nom']); ?></td>
                                    <td><span class="stock-badge" style="<?php echo stock_status_style($it['statut']); ?>"><?php echo htmlspecialchars($it['statut']); ?></span></td>
                                    <td><?php echo !empty($it['lieu']) ? '📍 ' . htmlspecialchars($it['lieu']) : '—'; ?></td>
                                    <td><strong><?php echo (int)$it['quantite']; ?></strong></td>
                                    <td><?php echo $liDate !== '' ? htmlspecialchars($liDate) : '—'; ?></td>
                                    <td><?php echo ($it['prix_achat'] !== null) ? htmlspecialchars(number_format((float)$it['prix_achat'], 2, ',', ' ')) . ' €' : '—'; ?></td>
                                    <td>
                                        <div class="li-actions">
                                            <a href="stock_item.php?id=<?php echo (int)$it['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">👁️</a>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick='openStockEdit(<?php echo $liJson; ?>)'>✏️</button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteItem(<?php echo (int)$it['id']; ?>, <?php echo htmlspecialchars(json_encode($it['nom']), ENT_QUOTES); ?>)">🗑️</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="empty-state" id="stock-noresult" style="display:none;"><div class="empty-state-icon">🔍</div><p>Aucun article ne correspond à votre recherche.</p></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

            </div>
        </div>
    </main>

    <!-- Modale de modification d'un article -->
    <div id="stock-modal">
        <div class="stock-modal-card">
            <div class="stock-modal-head">
                <h3>✏️ Modifier l'article</h3>
                <button type="button" class="x" onclick="closeStockEdit()" aria-label="Fermer">&times;</button>
            </div>
            <form action="commission_stock.php?id=<?php echo $cid; ?>" method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                <input type="hidden" name="item_id" id="se-id" value="">
                <div class="stock-modal-body">
                <div class="form-group"><label class="form-label">Nom <span style="color:var(--danger)">*</span></label><input type="text" name="nom" id="se-nom" class="form-input" required></div>
                <div style="display:flex; gap:1rem; flex-wrap:wrap;">
                    <div class="form-group" style="flex:1; min-width:110px;"><label class="form-label">Quantité</label><input type="number" name="quantite" id="se-qte" class="form-input" min="0" inputmode="numeric" pattern="[0-9]*"></div>
                    <div class="form-group" style="flex:1; min-width:130px;"><label class="form-label">Statut</label>
                        <select name="statut" id="se-statut" class="form-input">
                            <?php foreach ($STOCK_STATUTS as $s): ?><option value="<?php echo $s; ?>"><?php echo $s; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1; min-width:150px;"><label class="form-label">Lieu de stockage</label>
                        <select name="lieu" id="se-lieu" class="form-input">
                            <option value="">—</option>
                            <?php foreach ($lieux as $lg): ?><option value="<?php echo htmlspecialchars($lg, ENT_QUOTES); ?>"><?php echo htmlspecialchars($lg); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display:flex; gap:1rem; flex-wrap:wrap;">
                    <div class="form-group" style="flex:1; min-width:140px;"><label class="form-label">Date d'achat</label><input type="month" name="date_achat" id="se-date" class="form-input"></div>
                    <div class="form-group" style="flex:1; min-width:140px;"><label class="form-label">Prix d'achat ou valeur estimée €</label><input type="number" name="prix_achat" id="se-prix" class="form-input" min="0" step="0.01" inputmode="decimal"></div>
                </div>
                <div class="form-group"><label class="form-label">Description</label><input type="text" name="description" id="se-desc" class="form-input"></div>
                <div class="form-group">
                    <label class="form-label">Remplacer la photo (facultatif)</label>
                    <div class="photo-tile" onclick="this.querySelector('input').click()">
                        <img id="edit-preview" alt="" style="display:none;">
                        <div class="ph" id="edit-ph"><span style="font-size:1.7rem;">📷</span><span>Changer la photo</span></div>
                        <input type="file" name="photo" accept="image/*" onchange="tilePreview(this,'edit-preview','edit-ph')">
                    </div>
                </div>
                </div><!-- /stock-modal-body -->
                <div class="stock-modal-foot">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeStockEdit()">Annuler</button>
                    <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirmation de suppression d'un article (popup moderne) -->
    <div id="stock-del-modal" class="modal-overlay">
        <div class="modal-card glass-card" style="max-width:400px;">
            <div class="modal-header"><h3 style="color:var(--danger);">🗑️ Supprimer l'article</h3></div>
            <div class="modal-body"><p>Voulez-vous vraiment supprimer <strong id="stock-del-name" class="gold-text"></strong> du stock ? Cette action est définitive.</p></div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; justify-content:flex-end;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeDelItem()">Annuler</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="doDeleteItem()">Supprimer</button>
            </div>
        </div>
    </div>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header"><?php if (!empty($success)): ?><h3 class="gold-text">Réussi</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?></div>
            <div class="modal-body"><p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>

    <script>
        function tilePreview(input, imgId, phId){
            var img = document.getElementById(imgId), ph = document.getElementById(phId);
            if (!img) return;
            if (input.files && input.files[0]) { img.src = URL.createObjectURL(input.files[0]); img.style.display = 'block'; if (ph) ph.style.display = 'none'; }
            else { img.style.display = 'none'; img.removeAttribute('src'); if (ph) ph.style.display = 'flex'; }
        }
        function tileAlbum(input, stripId, countId){
            var strip = document.getElementById(stripId), cnt = document.getElementById(countId);
            if (strip) { strip.innerHTML = ''; }
            var n = input.files ? input.files.length : 0;
            for (var i = 0; i < n && i < 12 && strip; i++) { var im = document.createElement('img'); im.src = URL.createObjectURL(input.files[i]); strip.appendChild(im); }
            if (cnt) cnt.textContent = n > 0 ? (n + ' photo(s) sélectionnée(s)') : '';
        }
        function openStockEdit(it){
            document.getElementById('se-id').value = it.id;
            document.getElementById('se-nom').value = it.nom || '';
            document.getElementById('se-qte').value = it.quantite || 0;
            document.getElementById('se-desc').value = it.description || '';
            document.getElementById('se-statut').value = it.statut || 'Disponible';
            var lieuSel = document.getElementById('se-lieu'); if (lieuSel) lieuSel.value = it.lieu || '';
            var dEl = document.getElementById('se-date'); if (dEl) dEl.value = it.date_achat || '';
            var pEl = document.getElementById('se-prix'); if (pEl) pEl.value = it.prix_achat || '';
            var ep = document.getElementById('edit-preview'); if (ep) { ep.style.display = 'none'; ep.removeAttribute('src'); }
            var eph = document.getElementById('edit-ph'); if (eph) eph.style.display = 'flex';
            document.getElementById('stock-modal').classList.add('active');
        }
        function closeStockEdit(){ document.getElementById('stock-modal').classList.remove('active'); }
        document.getElementById('stock-modal').addEventListener('click', function (e) { if (e.target === this) closeStockEdit(); });

        // Section d'ajout : déplacée en bas de la page, ouverte via le bouton du haut.
        (function () { var s = document.getElementById('stock-add-section'); var w = document.querySelector('.stock-wrap'); if (s && w) { w.appendChild(s); } })();
        function toggleAddStock(){
            var s = document.getElementById('stock-add-section'); if (!s) return;
            var show = (s.style.display === 'none' || s.style.display === '');
            s.style.display = show ? 'block' : 'none';
            if (show) { s.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        }

        // Suppression d'un article (popup moderne)
        var stockDelId = null;
        function confirmDeleteItem(id, nom){
            stockDelId = id;
            document.getElementById('stock-del-name').textContent = nom;
            var m = document.getElementById('stock-del-modal'); m.style.display = 'flex'; m.classList.add('active');
        }
        function closeDelItem(){ var m = document.getElementById('stock-del-modal'); m.classList.remove('active'); m.style.display = 'none'; stockDelId = null; }
        function doDeleteItem(){ if (stockDelId != null) { var f = document.getElementById('stock-del-form-' + stockDelId); if (f) f.submit(); } }
        document.getElementById('stock-del-modal').addEventListener('click', function (e) { if (e.target === this) closeDelItem(); });

        // Ouvrir / fermer les indicateurs
        function toggleIndics(){
            var d = document.getElementById('stk-indics'); var b = document.getElementById('stk-indics-toggle');
            if (!d) return;
            var hidden = (d.style.display === 'none');
            d.style.display = hidden ? '' : 'none';
            if (b) b.textContent = hidden ? '📊 Masquer les indicateurs' : '📊 Afficher les indicateurs';
            try { localStorage.setItem('stk_indics', hidden ? '1' : '0'); } catch (e) {}
        }
        (function () {
            try {
                if (localStorage.getItem('stk_indics') === '0') {
                    var d = document.getElementById('stk-indics'); var b = document.getElementById('stk-indics-toggle');
                    if (d) d.style.display = 'none';
                    if (b) b.textContent = '📊 Afficher les indicateurs';
                }
            } catch (e) {}
        })();

        // Bascule Grille / Liste
        function stkView(mode){
            var g = document.getElementById('stock-grid-view');
            var l = document.getElementById('stock-list-view');
            var bg = document.getElementById('stk-view-grid');
            var bl = document.getElementById('stk-view-list');
            if (!g || !l) return;
            if (mode === 'list') { g.style.display = 'none'; l.style.display = 'block'; if (bg) bg.classList.remove('active'); if (bl) bl.classList.add('active'); }
            else { g.style.display = 'block'; l.style.display = 'none'; if (bl) bl.classList.remove('active'); if (bg) bg.classList.add('active'); }
            try { localStorage.setItem('stk_view', mode); } catch (e) {}
            if (window.stkFilter) window.stkFilter();
        }
        // Filtres (recherche + statut + lieu + année)
        (function () {
            var search = document.getElementById('stk-search');
            if (!search) return;
            var selS = document.getElementById('stk-statut');
            var selL = document.getElementById('stk-lieu');
            var selA = document.getElementById('stk-annee');
            var noRes = document.getElementById('stock-noresult');
            var countEl = document.getElementById('stk-count');
            function norm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim(); }
            window.stkFilter = function () {
                var term = norm(search.value);
                var st = selS ? selS.value : '';
                var li = selL ? selL.value : '';
                var an = selA ? selA.value : '';
                var listV = document.getElementById('stock-list-view');
                var active = (listV && listV.style.display !== 'none') ? listV : document.getElementById('stock-grid-view');
                var items = active ? active.querySelectorAll('.stk-filterable') : [];
                var n = 0;
                Array.prototype.forEach.call(items, function (el) {
                    var show = (!term || norm(el.getAttribute('data-search')).indexOf(term) !== -1)
                        && (!st || el.getAttribute('data-statut') === st)
                        && (!li || el.getAttribute('data-lieu') === li)
                        && (!an || el.getAttribute('data-annee') === an);
                    el.style.display = show ? '' : 'none';
                    if (show) n++;
                });
                if (noRes) noRes.style.display = n === 0 ? 'block' : 'none';
                if (countEl) countEl.textContent = n + ' article(s)';
                if (window.stkMajIndics) window.stkMajIndics();
            };
            search.addEventListener('input', window.stkFilter);
            if (selS) selS.addEventListener('change', window.stkFilter);
            if (selL) selL.addEventListener('change', window.stkFilter);
            if (selA) selA.addEventListener('change', window.stkFilter);
            var saved = null; try { saved = localStorage.getItem('stk_view'); } catch (e) {}
            if (saved === 'list') { stkView('list'); } else { window.stkFilter(); }
        })();

        // Recalcule dynamiquement les indicateurs selon les articles actuellement
        // affichés (après filtres recherche/statut/lieu/année).
        (function () {
            var zone = document.getElementById('stk-indics');
            var statEls = { total: null, dispo: null, reserve: null, nonutil: null,
                            valTotal: null, valDispo: null, valReserve: null, valNonutil: null };
            function q(sel){ return zone ? zone.querySelector(sel) : null; }
            function attacher() {
                if (!zone) return;
                // Indicateurs quantités
                var stats = zone.querySelectorAll('.stk-stats');
                if (stats.length >= 1) {
                    var c = stats[0].querySelectorAll('.stk-stat');
                    statEls.total = c[1]; statEls.dispo = c[2]; statEls.reserve = c[3]; statEls.nonutil = c[4];
                }
                // Indicateurs valeur (bloc conditionnel)
                for (var i = 1; i < stats.length; i++) {
                    var cc = stats[i].querySelectorAll('.stk-stat');
                    if (cc.length >= 4 && cc[0].querySelector('.l') && /Valeur totale/.test(cc[0].querySelector('.l').textContent)) {
                        statEls.valTotal = cc[0]; statEls.valDispo = cc[1]; statEls.valReserve = cc[2]; statEls.valNonutil = cc[3];
                        break;
                    }
                }
                // Indicateurs par année : on met en évidence l'année sélectionnée
                var selA = document.getElementById('stk-annee');
                if (selA) {
                    var an = selA.value;
                    for (var i = 0; i < stats.length; i++) {
                        var cc = stats[i].querySelectorAll('.stk-stat');
                        var isYear = cc.length >= 1 && cc[0].querySelector('.l') && /^Année/.test(cc[0].querySelector('.l').textContent);
                        if (isYear) {
                            Array.prototype.forEach.call(cc, function (el) {
                                var lbl = el.querySelector('.l') ? el.querySelector('.l').textContent : '';
                                var m = lbl.match(/Année (\d{4})/);
                                var y = m ? m[1] : '';
                                if (an === y) { el.style.outline = '2px solid var(--accent)'; el.style.background = 'rgba(212,175,55,0.12)'; }
                                else { el.style.outline = ''; el.style.background = ''; }
                            });
                        }
                    }
                }
            }
            window.stkMajIndics = function () {
                if (!zone) return;
                var actif = (document.getElementById('stock-list-view').style.display !== 'none')
                    ? document.getElementById('stock-list-view')
                    : document.getElementById('stock-grid-view');
                var visibles = actif ? actif.querySelectorAll('.stk-filterable') : [];
                var total = 0, dispo = 0, reserve = 0, nonutil = 0;
                var vTotal = 0, vDispo = 0, vReserve = 0, vNonutil = 0;
                Array.prototype.forEach.call(visibles, function (el) {
                    if (el.style.display === 'none') return;
                    total++;
                    var st = el.getAttribute('data-statut') || '';
                    var prix = parseFloat(el.getAttribute('data-prix') || '0') || 0;
                    var qte = parseInt(el.getAttribute('data-qte') || '1', 10) || 1;
                    var v = prix * qte;
                    if (st === 'Non utilisable') { nonutil++; vNonutil += v; }
                    else if (st === 'Réservé') { reserve++; vReserve += v; }
                    else { dispo++; vDispo += v; }
                    vTotal += v;
                });
                function txt(el, val, estValeur) {
                    if (!el) return;
                    var n = el.querySelector('.n'); if (!n) return;
                    if (estValeur) { n.textContent = val.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; }
                    else { n.textContent = String(val); }
                }
                txt(statEls.total, total, false);
                txt(statEls.dispo, dispo, false);
                txt(statEls.reserve, reserve, false);
                txt(statEls.nonutil, nonutil, false);
                txt(statEls.valTotal, vTotal, true);
                txt(statEls.valDispo, vDispo, true);
                txt(statEls.valReserve, vReserve, true);
                txt(statEls.valNonutil, vNonutil, true);
                // Masquer le bloc valeur s'il n'y a plus de valeur
                var stats = zone.querySelectorAll('.stk-stats');
                for (var i = 1; i < stats.length; i++) {
                    var cc = stats[i].querySelectorAll('.stk-stat');
                    if (cc.length >= 4 && cc[0].querySelector('.l') && /Valeur totale/.test(cc[0].querySelector('.l').textContent)) {
                        stats[i].style.display = vTotal > 0 ? '' : 'none';
                        break;
                    }
                }
                // Les attributs data-prix / data-qte sont déjà portés par les
                // cartes (grille) et les lignes (liste) côté PHP.
                attacher();
            };
            // Initialisation au chargement (les valeurs PHP sont déjà justes,
            // cela synchronise l'état visuel de la zone indicateurs).
            if (window.stkFilter) window.stkMajIndics();
        })();
    </script>
</body>
</html>
