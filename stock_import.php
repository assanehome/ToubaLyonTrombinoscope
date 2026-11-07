<?php
/**
 * Touba Lyon 2026 - Import du stock d'une commission depuis un fichier CSV (+ photos).
 *
 * Permet de reprendre les données d'une plateforme existante : exportez-les en CSV (séparateur « ; » ou « , »),
 * avec au minimum la colonne « nom ». Colonnes reconnues (en-tête, sans tenir compte des accents ni de la casse) :
 * nom, quantite, statut, lieu, date_achat (AAAA-MM), prix_achat (prix d'achat ou valeur estimée), description,
 * photo (nom du fichier image, ex. enceinte.png) et album (autres photos, séparées par « | ») — les champs de la page de création de stock.
 * Les images sont envoyées avec le CSV (plusieurs fichiers, ou un ZIP) et rattachées par leur nom de fichier.
 */
require_once __DIR__ . '/commission_guard.php'; // $__isAdmin, $__managedCommissions, $pdo
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/stock_logistique_helper.php';

$error = '';
$success = '';
$avert = '';
$colInfo = '';
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
$zipOk = class_exists('ZipArchive');

// Modèle de fichier CSV à télécharger
if (isset($_GET['modele'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="modele_import_stock.csv"');
    echo "\xEF\xBB\xBF" . "nom;quantite;statut;lieu;date_achat;prix_achat;description;photo;album\r\n"
        . "Table pliante;20;Disponible;Box;2025-03;35,00;Table pliante 180 cm;table_pliante.png;\r\n"
        . "Enceinte;2;Disponible;Keur Serigne Touba;2024-11;120;Enceinte 300 W;enceinte.png;enceinte_2.png|enceinte_3.png\r\n";
    exit;
}

/** Clé de colonne normalisée (sans accents, minuscules, sans espaces). */
function imp_cle(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'î' => 'i', 'ï' => 'i', 'ç' => 'c', 'ù' => 'u', 'û' => 'u']);
    return trim(preg_replace('/[^a-z0-9_]+/', '_', $s), '_');
}

/** Noms d'en-tête acceptés pour chaque colonne (après normalisation). */
function imp_alias(): array
{
    return [
        'nom' => ['nom', 'designation', 'article', 'libelle', 'intitule', 'nom_article'],
        'quantite' => ['quantite', 'qte', 'quantity', 'nombre', 'stock'],
        'statut' => ['statut', 'status'],
        'etat' => ['etat', 'condition'],
        'lieu' => ['lieu', 'emplacement', 'localisation'],
        'description' => ['description', 'commentaire', 'details'],
        'prix_achat' => ['prix_achat', 'prix_d_achat', 'prix_estime', 'prix_estimee', 'valeur_estimee', 'valeur_estime', 'valeur', 'prix', 'prix_unitaire', 'achat', 'cout', 'cout_achat'],
        'date_achat' => ['date_achat', 'date_d_achat', 'date', 'annee_achat'],
        'photo' => ['photo', 'image', 'fichier', 'nom_fichier', 'nom_du_fichier', 'fichier_photo', 'nom_fichier_png', 'nom_du_fichier_png', 'nom_fichier_photo', 'photo_principale'],
        'album' => ['album', 'photos', 'images', 'autres_photos'],
    ];
}

function imp_statut(string $v): string
{
    $k = imp_cle($v);
    if (strpos($k, 'reserv') === 0) { return 'Réservé'; }
    if ($k === 'non_utilisable' || $k === 'inutilisable' || $k === 'hors_service') { return 'Non utilisable'; }
    return 'Disponible';
}

function imp_etat(string $v): string
{
    $k = imp_cle($v);
    if ($k !== '' && array_key_exists($k, stock_etats())) { return $k; }
    if (strpos($k, 'repar') !== false) { return 'a_reparer'; }
    if (strpos($k, 'endommag') !== false || strpos($k, 'casse') !== false) { return 'endommage'; }
    return 'bon';
}

/** Date d'achat ramenée au format AAAA-MM (accepte AAAA-MM, AAAA-MM-JJ, MM/AAAA, JJ/MM/AAAA). */
function imp_date(string $v): ?string
{
    $v = trim($v);
    $a = $m = null;
    if (preg_match('/^(\d{4})-(\d{2})(-\d{2})?$/', $v, $x)) { $a = $x[1]; $m = $x[2]; }
    elseif (preg_match('/^(\d{1,2})\/(\d{4})$/', $v, $x)) { $a = $x[2]; $m = $x[1]; }
    elseif (preg_match('/^\d{1,2}\/(\d{1,2})\/(\d{4})$/', $v, $x)) { $a = $x[2]; $m = $x[1]; }
    if ($a === null || (int) $m < 1 || (int) $m > 12) { return null; }
    return $a . '-' . sprintf('%02d', (int) $m);
}

$rapport = null;
$tropGros = ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0);
if ($tropGros) { $error = "Envoi trop volumineux pour le serveur : réduisez le nombre ou le poids des photos (ou envoyez-les en plusieurs fois)."; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stockEnabled && !$tropGros) {
    if (!csrf_validate()) { $error = "Échec de validation de sécurité. Veuillez réessayer."; }
    else {
        $f = $_FILES['fichier'] ?? null;
        $mode = ($_POST['mode'] ?? '') === 'maj' ? 'maj' : 'nouveaux';
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $error = "Choisissez un fichier CSV."; }
        elseif (($f['size'] ?? 0) > 2 * 1024 * 1024) { $error = "Fichier trop volumineux (2 Mo maximum)."; }
        else {
            $contenu = file_get_contents($f['tmp_name']);
            if (substr($contenu, 0, 3) === "\xEF\xBB\xBF") { $contenu = substr($contenu, 3); }
            if (!mb_check_encoding($contenu, 'UTF-8')) { $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252'); }
            $lignes = preg_split('/\r\n|\n|\r/', trim($contenu));
            $sep = (substr_count($lignes[0] ?? '', ';') >= substr_count($lignes[0] ?? '', ',')) ? ';' : ',';
            // En-têtes → colonnes canoniques (via les noms alternatifs)
            $alias = imp_alias();
            $entete = []; $colIgnorees = [];
            foreach (str_getcsv(array_shift($lignes) ?? '', $sep) as $col) {
                $k = imp_cle((string) $col);
                $canon = $k;
                foreach ($alias as $cle => $liste) { if (in_array($k, $liste, true)) { $canon = $cle; break; } }
                $entete[] = $canon;
                if (trim((string) $col) !== '' && !array_key_exists($canon, $alias)) { $colIgnorees[] = trim((string) $col); }
            }
            if (!in_array('nom', $entete, true)) { $error = "Colonne « nom » introuvable : la première ligne doit contenir les en-têtes."; }
            else {
                // Photos envoyées avec le CSV : images ou ZIP, retrouvées par leur nom de fichier
                $extImg = ['jpg', 'jpeg', 'png', 'webp'];
                $pool = [];
                $zipIgnore = false;
                $pf = $_FILES['photos'] ?? null;
                if ($pf && is_array($pf['name'] ?? null)) {
                    foreach ($pf['name'] as $i => $n) {
                        if (($pf['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { continue; }
                        $ext = strtolower(pathinfo((string) $n, PATHINFO_EXTENSION));
                        $tmp = $pf['tmp_name'][$i];
                        if ($ext === 'zip') {
                            if (!$zipOk) { $zipIgnore = true; continue; }
                            $z = new ZipArchive();
                            if ($z->open($tmp) === true) {
                                for ($j = 0; $j < $z->numFiles; $j++) {
                                    $s = $z->statIndex($j);
                                    $en = (string) $s['name'];
                                    if (substr($en, -1) === '/' || stripos($en, '__MACOSX') !== false) { continue; }
                                    $b = strtolower(basename($en));
                                    $e = pathinfo($b, PATHINFO_EXTENSION);
                                    if ($b === '' || $b[0] === '.' || !in_array($e, $extImg, true)) { continue; }
                                    $pool[$b] = ['zip' => $tmp, 'entry' => $en, 'ext' => $e, 'size' => (int) $s['size']];
                                }
                                $z->close();
                            }
                        } elseif (in_array($ext, $extImg, true)) {
                            $pool[strtolower(basename((string) $n))] = ['tmp' => $tmp, 'ext' => $ext, 'size' => (int) $pf['size'][$i]];
                        }
                    }
                }
                $manquantes = []; $photoErr = []; $nbPhotos = 0;
                /** Enregistre une photo du lot dans uploads/ et retourne son nom, ou null. */
                $sauverPhoto = function (string $nomFichier) use (&$pool, &$manquantes, &$photoErr, &$nbPhotos) {
                    $k = strtolower(basename(trim($nomFichier)));
                    if ($k === '') { return null; }
                    if (!isset($pool[$k])) { $manquantes[$k] = true; return null; }
                    $p = $pool[$k];
                    if ($p['size'] > 5 * 1024 * 1024) { $photoErr[$k] = 'plus de 5 Mo'; return null; }
                    $dest = uniqid('stock_', true) . '.' . $p['ext'];
                    $chemin = __DIR__ . '/uploads/' . $dest;
                    $ok = false;
                    if (isset($p['tmp'])) {
                        $ok = @copy($p['tmp'], $chemin);
                        if ($ok && @getimagesize($chemin) === false) { @unlink($chemin); $ok = false; }
                    } else {
                        $z = new ZipArchive();
                        if ($z->open($p['zip']) === true) {
                            $d = $z->getFromName($p['entry']);
                            $z->close();
                            if ($d !== false && @getimagesizefromstring($d) !== false) { $ok = (file_put_contents($chemin, $d) !== false); }
                        }
                    }
                    if (!$ok) { $photoErr[$k] = 'image illisible'; return null; }
                    $nbPhotos++;
                    return $dest;
                };

                $colReconnues = array_values(array_unique(array_filter($entete, function ($k) use ($alias) { return array_key_exists($k, $alias); })));
                $nouveaux = 0; $maj = 0; $ignores = 0;
                $existe = $pdo->prepare("SELECT id FROM commission_stock WHERE commission_id = ? AND LOWER(nom) = LOWER(?) LIMIT 1");
                $ins = $pdo->prepare("INSERT INTO commission_stock (commission_id, nom, description, quantite, statut, lieu, date_achat, prix_achat, etat, photo) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $upd = $pdo->prepare("UPDATE commission_stock SET quantite = ?, statut = ?, lieu = COALESCE(?, lieu), etat = ?, description = COALESCE(?, description), prix_achat = COALESCE(?, prix_achat), date_achat = COALESCE(?, date_achat) WHERE id = ?");
                $updPhoto = $pdo->prepare("UPDATE commission_stock SET photo = ? WHERE id = ?");
                $insAlbum = $pdo->prepare("INSERT INTO commission_stock_photos (item_id, photo) VALUES (?, ?)");
                $pdo->beginTransaction();
                foreach ($lignes as $ligne) {
                    if (trim($ligne) === '') { continue; }
                    $c = str_getcsv($ligne, $sep);
                    $r = [];
                    foreach ($entete as $i => $k) { if (!isset($r[$k]) || $r[$k] === '') { $r[$k] = trim((string) ($c[$i] ?? '')); } }
                    $nom = mb_substr($r['nom'] ?? '', 0, 150);
                    if ($nom === '') { $ignores++; continue; }
                    $qte = max(0, (int) preg_replace('/[^0-9\-]/', '', $r['quantite'] ?? '0'));
                    $statut = imp_statut($r['statut'] ?? '');
                    $etat = imp_etat($r['etat'] ?? '');
                    $lieu = ($r['lieu'] ?? '') !== '' ? mb_substr($r['lieu'], 0, 150) : null;
                    $desc = ($r['description'] ?? '') !== '' ? $r['description'] : null;
                    $prix = ($r['prix_achat'] ?? '') !== '' ? (float) str_replace([' ', ',', '€'], ['', '.', ''], $r['prix_achat']) : null;
                    $da = imp_date($r['date_achat'] ?? '');
                    // Photos de la ligne : colonne « photo » puis « album » (noms séparés par « | »)
                    $noms = array_values(array_filter(array_map('trim', preg_split('/\|/', ($r['photo'] ?? '') . '|' . ($r['album'] ?? '')))));
                    $existe->execute([$cid, $nom]);
                    $id = (int) $existe->fetchColumn();
                    if ($id > 0) {
                        if ($mode === 'maj') {
                            $upd->execute([$qte, $statut, $lieu, $etat, $desc, $prix, $da, $id]);
                            $sauvees = [];
                            foreach ($noms as $nf) { $s = $sauverPhoto($nf); if ($s !== null) { $sauvees[] = $s; } }
                            if (!empty($sauvees)) {
                                $updPhoto->execute([$sauvees[0], $id]);
                                foreach (array_slice($sauvees, 1) as $s) { $insAlbum->execute([$id, $s]); }
                            }
                            $maj++;
                        } else { $ignores++; }
                    } else {
                        $sauvees = [];
                        foreach ($noms as $nf) { $s = $sauverPhoto($nf); if ($s !== null) { $sauvees[] = $s; } }
                        $ins->execute([$cid, $nom, $desc, $qte, $statut, $lieu, $da, $prix, $etat, $sauvees[0] ?? null]);
                        $nid = (int) $pdo->lastInsertId();
                        foreach (array_slice($sauvees, 1) as $s) { $insAlbum->execute([$nid, $s]); }
                        $nouveaux++;
                        if ($lieu !== null) { try { $pdo->prepare("INSERT IGNORE INTO stock_lieux (nom) VALUES (?)")->execute([$lieu]); } catch (Exception $e) {} }
                    }
                }
                $pdo->commit();
                $success = "Import terminé : $nouveaux article(s) ajouté(s), $maj mis à jour, $ignores ignoré(s)" . ($nbPhotos > 0 ? ", $nbPhotos photo(s) rattachée(s)." : '.');
                $msgs = [];
                if (!empty($manquantes)) { $msgs[] = "Photos citées dans le CSV mais non envoyées : " . implode(', ', array_slice(array_keys($manquantes), 0, 15)) . (count($manquantes) > 15 ? '…' : '') . '.'; }
                if (!empty($photoErr)) { $l = []; foreach ($photoErr as $k => $v) { $l[] = "$k ($v)"; } $msgs[] = "Photos non importées : " . implode(', ', array_slice($l, 0, 15)) . '.'; }
                if ($zipIgnore) { $msgs[] = "Les fichiers ZIP n'ont pas été lus (extension ZIP indisponible sur le serveur) : envoyez les images directement."; }
                $avert = implode(' ', $msgs);
                $colInfo = 'Colonnes reconnues : ' . (empty($colReconnues) ? 'aucune' : implode(', ', $colReconnues)) . '.'
                    . (!empty($colIgnorees) ? ' Colonnes ignorées (non reconnues) : ' . implode(', ', array_slice($colIgnorees, 0, 15)) . '.' : '');
            }
        }
    }
}
$csrf = csrf_field();
$maxFichiers = (int) ini_get('max_file_uploads');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📥 Import du stock — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">
            <div style="max-width:900px; margin:0 auto;">
                <div class="admin-welcome-banner glass-card" style="margin-bottom:1rem; padding:1.1rem 1.5rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:0.8rem;">
                    <span>📥 Import du stock — <strong class="gold-text"><?php echo $h($commission['nom']); ?></strong></span>
                    <a href="commission_stock.php?id=<?php echo $cid; ?>" class="btn btn-secondary btn-sm">← Stock</a>
                </div>
                <?php if ($success !== ''): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($success); ?></div><?php endif; ?>
                <?php if ($colInfo !== ''): ?><div style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.15);color:var(--text-muted);padding:0.7rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:0.88rem;line-height:1.5;">🧾 <?php echo $h($colInfo); ?></div><?php endif; ?>
                <?php if ($avert !== ''): ?><div style="background:rgba(212,175,55,0.12);border:1px solid rgba(212,175,55,0.45);color:#ffd873;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;">⚠️ <?php echo $h($avert); ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($error); ?></div><?php endif; ?>
                <?php if (!$stockEnabled): ?>
                    <div class="empty-state"><div class="empty-state-icon">📦</div><p>La gestion de stock n'est pas activée pour cette commission.</p></div>
                <?php else: ?>
                <div class="glass-card" style="padding:1.2rem 1.4rem;">
                    <p style="color:var(--text-muted); font-size:0.88rem; margin:0 0 0.8rem; line-height:1.55;">
                        Reprenez le stock d'une plateforme existante : exportez-le en <strong>CSV</strong> (séparateur « ; » ou « , »), puis importez le fichier ici.
                        La première ligne doit contenir les en-têtes. Seule la colonne <strong>nom</strong> est obligatoire. Les colonnes sont celles de la page de création de stock :
                        <em>nom</em>, <em>quantite</em>, <em>statut</em> (Disponible / Réservé / Non utilisable), <em>lieu</em> (lieu de stockage), <em>date_achat</em> (AAAA-MM),
                        <em>prix_achat</em> (prix d'achat ou valeur estimée, en €), <em>description</em>, <em>photo</em> (nom du fichier image, ex. <em>enceinte.png</em>)
                        et <em>album</em> (autres photos, séparées par « | »). Les colonnes inconnues sont ignorées et signalées après l'import.
                    </p>
                    <p style="margin:0 0 1rem;"><a href="stock_import.php?id=<?php echo $cid; ?>&modele=1" class="btn btn-secondary btn-sm" data-ajax-off>⬇️ Télécharger le modèle CSV</a></p>
                    <form method="POST" action="stock_import.php?id=<?php echo $cid; ?>" enctype="multipart/form-data">
                        <?php echo $csrf; ?>
                        <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                        <div class="form-group"><label class="form-label">Fichier CSV <span style="color:var(--danger)">*</span></label><input type="file" name="fichier" accept=".csv,text/csv" class="form-input" required></div>
                        <div class="form-group"><label class="form-label">Photos des articles (facultatif)</label>
                            <input type="file" name="photos[]" accept=".png,.jpg,.jpeg,.webp<?php echo $zipOk ? ',.zip' : ''; ?>" class="form-input" multiple>
                            <small style="color:var(--text-muted); display:block; margin-top:0.3rem; line-height:1.5;">
                                Images PNG, JPG ou WEBP (5 Mo max chacune), rattachées aux articles par leur <strong>nom de fichier</strong> (colonnes photo / album).
                                <?php echo $zipOk ? "Vous pouvez aussi envoyer un seul fichier <strong>.zip</strong> contenant toutes les images." : "Jusqu'à " . max(1, $maxFichiers) . " fichiers par envoi."; ?>
                            </small></div>
                        <div class="form-group"><label class="form-label">Articles déjà présents (même nom)</label>
                            <select name="mode" class="form-input">
                                <option value="nouveaux">Ne rien changer : ajouter seulement les nouveaux articles</option>
                                <option value="maj">Mettre à jour la quantité, l'état, les informations et les photos</option>
                            </select></div>
                        <button type="submit" class="btn btn-primary">📥 Importer</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script src="modern-file.js" defer></script>
</body>
</html>
