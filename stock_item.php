<?php
/**
 * Touba Lyon 2026 - Fiche détaillée d'un article de stock + album photos.
 * Accessible à l'admin et aux responsables de la commission de l'article.
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
function si_upload($file, &$err)
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
function si_uploads($files, &$err)
{
    $names = [];
    if (empty($files) || !isset($files['name']) || !is_array($files['name'])) { return $names; }
    for ($i = 0, $c = count($files['name']); $i < $c; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
        $one = ['name' => $files['name'][$i], 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i] ?? 0, 'size' => $files['size'][$i] ?? 0];
        $n = si_upload($one, $err);
        if ($n !== null) { $names[] = $n; }
        if ($err !== '') { break; }
    }
    return $names;
}

$iid = (int) ($_POST['item_id'] ?? $_GET['id'] ?? 0);
$item = null;
if ($iid > 0) {
    try { $st = $pdo->prepare("SELECT * FROM commission_stock WHERE id = ?"); $st->execute([$iid]); $item = $st->fetch(); } catch (Exception $e) { $item = null; }
}
if (!$item) { header('Location: commission_dashboard.php'); exit; }
$cid = (int) $item['commission_id'];
$canManage = $__isAdmin || in_array($cid, $__managedCommissions, true);
if (!$canManage) { header('Location: commission_dashboard.php'); exit; }
$comNom = '';
try { $cq = $pdo->prepare("SELECT nom FROM commissions WHERE id = ?"); $cq->execute([$cid]); $comNom = (string) $cq->fetchColumn(); } catch (Exception $e) { $comNom = ''; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_photos') {
                $names = si_uploads($_FILES['album'] ?? null, $error);
                if ($error === '' && !empty($names)) {
                    $ins = $pdo->prepare("INSERT INTO commission_stock_photos (item_id, photo) VALUES (?, ?)");
                    foreach ($names as $n) { $ins->execute([$iid, $n]); }
                    if (empty($item['photo'])) { $pdo->prepare("UPDATE commission_stock SET photo = ? WHERE id = ?")->execute([$names[0], $iid]); }
                    $success = "Photo(s) ajoutée(s) à l'album.";
                } elseif ($error === '') {
                    $error = "Aucune photo sélectionnée.";
                }
            } elseif ($action === 'delete_photo') {
                $pid = (int) ($_POST['photo_id'] ?? 0);
                if ($pid > 0) {
                    $pdo->prepare("DELETE FROM commission_stock_photos WHERE id = ? AND item_id = ?")->execute([$pid, $iid]);
                    $success = "Photo supprimée de l'album.";
                }
            } elseif ($action === 'set_cover') {
                $pv = trim($_POST['photo'] ?? '');
                if ($pv !== '') {
                    $pdo->prepare("UPDATE commission_stock SET photo = ? WHERE id = ?")->execute([$pv, $iid]);
                    $success = "Photo de couverture mise à jour.";
                }
            }
        } catch (Exception $e) {
            error_log('Touba Lyon stock_item: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
        try { $st = $pdo->prepare("SELECT * FROM commission_stock WHERE id = ?"); $st->execute([$iid]); $item = $st->fetch(); } catch (Exception $e) {}
    }
}

$album = [];
try { $st = $pdo->prepare("SELECT id, photo FROM commission_stock_photos WHERE item_id = ? ORDER BY id ASC"); $st->execute([$iid]); $album = $st->fetchAll(); } catch (Exception $e) { $album = []; }

$dateFr = '';
if (!empty($item['date_achat']) && preg_match('/^(\d{4})-(\d{2})$/', $item['date_achat'], $mm)) { $dateFr = $mm[2] . '/' . $mm[1]; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($item['nom']); ?> — Stock — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .si-wrap { max-width: 900px; margin: 2rem auto; }
        .si-top { display: grid; grid-template-columns: 260px 1fr; gap: 1.5rem; }
        @media (max-width: 640px) { .si-top { grid-template-columns: 1fr; } }
        .si-cover { width: 100%; aspect-ratio: 4/3; object-fit: cover; border-radius: 16px; background: #081c15; }
        .si-cover-ph { width: 100%; aspect-ratio: 4/3; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: var(--gold); background: rgba(212,175,55,0.08); border-radius: 16px; }
        .si-rows { display: flex; flex-direction: column; }
        .si-row { display: flex; justify-content: space-between; gap: 1rem; padding: 0.6rem 0; border-bottom: 1px solid rgba(255,255,255,0.07); }
        .si-row:last-child { border-bottom: none; }
        .si-row .k { color: var(--text-muted); font-size: 0.85rem; }
        .si-row .v { color: #fff; font-weight: 600; text-align: right; }
        .si-badge { display: inline-block; font-size: 0.72rem; font-weight: 700; border-radius: 50px; padding: 2px 10px; }
        .si-album { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.85rem; }
        .si-ph-card { position: relative; border-radius: 12px; overflow: hidden; border: 1px solid var(--glass-border); }
        .si-ph-card img { width: 100%; aspect-ratio: 1/1; object-fit: cover; display: block; }
        .si-ph-actions { display: flex; gap: 0.3rem; padding: 0.4rem; }
        .si-ph-actions .btn { padding: 0.25rem 0.5rem; font-size: 0.7rem; flex: 1; justify-content: center; }
        .si-cover-tag { position: absolute; top: 6px; left: 6px; background: rgba(212,175,55,0.9); color: #0c241a; font-size: 0.62rem; font-weight: 800; padding: 2px 8px; border-radius: 50px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">

        <div class="si-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.1rem 1.75rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <span>📦 <strong class="gold-text"><?php echo htmlspecialchars($item['nom']); ?></strong> — <?php echo htmlspecialchars($comNom); ?></span>
                <a href="commission_stock.php?id=<?php echo $cid; ?>" class="btn btn-secondary btn-sm">← Retour au stock</a>
            </div>

            <div class="glass-card" style="border-radius:18px; padding:1.5rem; margin-bottom:1.5rem;">
                <div class="si-top">
                    <div>
                        <?php if (!empty($item['photo'])): ?>
                            <img src="uploads/<?php echo htmlspecialchars($item['photo']); ?>" alt="" class="si-cover" style="cursor:zoom-in;" onclick="stockZoom('uploads/<?php echo htmlspecialchars($item['photo'], ENT_QUOTES); ?>')">
                        <?php else: ?>
                            <div class="si-cover-ph">📦</div>
                        <?php endif; ?>
                    </div>
                    <div class="si-rows">
                        <div class="si-row"><span class="k">Statut</span><span class="v"><span class="si-badge" style="<?php echo stock_status_style($item['statut']); ?>"><?php echo htmlspecialchars($item['statut']); ?></span></span></div>
                        <div class="si-row"><span class="k">Quantité</span><span class="v"><?php echo (int)$item['quantite']; ?> en stock</span></div>
                        <div class="si-row"><span class="k">Lieu de stockage</span><span class="v"><?php echo !empty($item['lieu']) ? '📍 ' . htmlspecialchars($item['lieu']) : '—'; ?></span></div>
                        <div class="si-row"><span class="k">Date d'achat</span><span class="v"><?php echo $dateFr !== '' ? '🗓️ ' . htmlspecialchars($dateFr) : '—'; ?></span></div>
                        <div class="si-row"><span class="k">Prix d'achat</span><span class="v"><?php echo ($item['prix_achat'] !== null) ? '💶 ' . htmlspecialchars(number_format((float)$item['prix_achat'], 2, ',', ' ')) . ' €' : '—'; ?></span></div>
                        <div class="si-row"><span class="k">Description</span><span class="v" style="max-width:60%;"><?php echo !empty($item['description']) ? htmlspecialchars($item['description']) : '—'; ?></span></div>
                    </div>
                </div>
            </div>

            <!-- Album photos -->
            <div class="glass-card" style="border-radius:18px; padding:1.5rem; margin-bottom:1.5rem;">
                <h3 class="gold-text" style="font-size:1.1rem; font-weight:700; margin:0 0 1rem;">🖼️ Album photos (<?php echo count($album); ?>)</h3>

                <form action="stock_item.php" method="POST" enctype="multipart/form-data" style="display:flex; gap:0.6rem; flex-wrap:wrap; align-items:flex-end; margin-bottom:1.25rem;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_photos">
                    <input type="hidden" name="item_id" value="<?php echo $iid; ?>">
                    <div class="form-group" style="flex:1; min-width:220px; margin:0;">
                        <label class="form-label">Ajouter des photos (plusieurs possibles)</label>
                        <input type="file" name="album[]" class="form-input" accept="image/*" multiple>
                    </div>
                    <button type="submit" class="btn btn-primary">Ajouter à l'album</button>
                </form>

                <?php if (empty($album)): ?>
                    <p style="color:var(--text-muted); font-style:italic; margin:0;">Aucune photo dans l'album. Ajoutez-en ci-dessus.</p>
                <?php else: ?>
                    <div class="si-album">
                        <?php foreach ($album as $ph): ?>
                            <?php $isCover = (!empty($item['photo']) && $item['photo'] === $ph['photo']); ?>
                            <div class="si-ph-card glass-card">
                                <?php if ($isCover): ?><span class="si-cover-tag">Couverture</span><?php endif; ?>
                                <img src="uploads/<?php echo htmlspecialchars($ph['photo']); ?>" alt="" style="cursor:zoom-in;" onclick="stockZoom('uploads/<?php echo htmlspecialchars($ph['photo'], ENT_QUOTES); ?>')">
                                <div class="si-ph-actions">
                                    <?php if (!$isCover): ?>
                                    <form action="stock_item.php" method="POST" style="margin:0; flex:1; display:flex;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="set_cover">
                                        <input type="hidden" name="item_id" value="<?php echo $iid; ?>">
                                        <input type="hidden" name="photo" value="<?php echo htmlspecialchars($ph['photo'], ENT_QUOTES); ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" title="Définir comme couverture">⭐</button>
                                    </form>
                                    <?php endif; ?>
                                    <form action="stock_item.php" method="POST" style="margin:0; flex:1; display:flex;" id="ph-del-form-<?php echo (int)$ph['id']; ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_photo">
                                        <input type="hidden" name="item_id" value="<?php echo $iid; ?>">
                                        <input type="hidden" name="photo_id" value="<?php echo (int)$ph['id']; ?>">
                                        <button type="button" class="btn btn-danger btn-sm" title="Supprimer" onclick="confirmSubmit('ph-del-form-<?php echo (int)$ph['id']; ?>', 'Supprimer cette photo de l\'album ?')">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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

    <!-- Lightbox : zoom photo (pas de nouvelle page) -->
    <div id="zoom-modal" onclick="closeZoom()" style="display:none; position:fixed; inset:0; z-index:4000; background:rgba(0,0,0,0.9); align-items:center; justify-content:center; padding:1rem; cursor:zoom-out;">
        <img id="zoom-img" src="" alt="" style="max-width:96vw; max-height:92vh; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.6);">
    </div>

    <!-- Popup de confirmation moderne (réutilisable) -->
    <div id="confirm-modal" class="modal-overlay">
        <div class="modal-card glass-card" style="max-width:400px;">
            <div class="modal-header"><h3 id="confirm-title" style="color:var(--danger);">🗑️ Confirmation</h3></div>
            <div class="modal-body"><p id="confirm-msg">Confirmer cette action ?</p></div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; justify-content:flex-end;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeConfirm()">Annuler</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="doConfirm()">Confirmer</button>
            </div>
        </div>
    </div>

    <script>
        function stockZoom(src){ var m = document.getElementById('zoom-modal'); document.getElementById('zoom-img').src = src; m.style.display = 'flex'; }
        function closeZoom(){ var m = document.getElementById('zoom-modal'); m.style.display = 'none'; document.getElementById('zoom-img').src = ''; }
        var __confirmFormId = null;
        function confirmSubmit(formId, msg){
            __confirmFormId = formId;
            document.getElementById('confirm-msg').textContent = msg || 'Confirmer cette action ?';
            var m = document.getElementById('confirm-modal'); m.style.display = 'flex'; m.classList.add('active');
        }
        function closeConfirm(){ var m = document.getElementById('confirm-modal'); m.classList.remove('active'); m.style.display = 'none'; __confirmFormId = null; }
        function doConfirm(){ if (__confirmFormId) { var f = document.getElementById(__confirmFormId); if (f) f.submit(); } }
        document.getElementById('confirm-modal').addEventListener('click', function (e) { if (e.target === this) closeConfirm(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeZoom(); closeConfirm(); } });
    </script>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
</body>
</html>
