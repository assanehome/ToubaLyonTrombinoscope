<?php
/**
 * Touba Lyon 2026 - Miniatures des photos du stock.
 *
 * stock_thumb.php?f=<nom du fichier dans uploads/>&w=<largeur>
 * Renvoie une miniature (WebP, ou JPEG à défaut) de la photo, créée à la première demande puis conservée
 * dans uploads/th/ : la grille et la liste du stock chargent des images légères au lieu des photos d'origine
 * (jusqu'à 5 Mo). Réservé aux utilisateurs connectés (membres ou administrateurs).
 * Sans GD, ou pour une image trop grande à traiter, on renvoie vers la photo d'origine.
 */
require_once __DIR__ . '/session_config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['player_id'])) {
    http_response_code(403);
    exit;
}
session_write_close(); // pas de verrou de session pendant le traitement d'une image

$nom = basename((string) ($_GET['f'] ?? ''));
if (!preg_match('/^[A-Za-z0-9._-]{1,150}$/', $nom)) { http_response_code(400); exit; }
$ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { http_response_code(400); exit; }
$source = __DIR__ . '/uploads/' . $nom;
if (!is_file($source)) { http_response_code(404); exit; }

// Largeurs proposées (le cache reste borné) : on prend la plus petite qui couvre la demande
$demande = max(48, min(800, (int) ($_GET['w'] ?? 320)));
$largeur = 800;
foreach ([96, 160, 320, 480, 640, 800] as $l) { if ($l >= $demande) { $largeur = $l; break; } }

$original = 'uploads/' . rawurlencode($nom);
$webp = function_exists('imagewebp');
$dossier = __DIR__ . '/uploads/th';
$cache = $dossier . '/' . $largeur . '_' . pathinfo($nom, PATHINFO_FILENAME) . ($webp ? '.webp' : '.jpg');
$type = $webp ? 'image/webp' : 'image/jpeg';

/** Envoie le fichier de cache avec les en-têtes de mise en cache (304 si inchangé). */
$servir = function (string $fichier) use ($type) {
    $mt = (int) @filemtime($fichier);
    $etag = '"' . md5($fichier . $mt) . '"';
    header('Content-Type: ' . $type);
    header('Cache-Control: private, max-age=2592000');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mt) . ' GMT');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    header('Content-Length: ' . filesize($fichier));
    readfile($fichier);
    exit;
};

if (is_file($cache) && filemtime($cache) >= filemtime($source)) { $servir($cache); }

// Génération (GD) : si indisponible ou image trop lourde à traiter, on renvoie l'original
if (!function_exists('imagecreatetruecolor')) { header('Location: ' . $original); exit; }
$info = @getimagesize($source);
if (!$info || ($info[0] * $info[1]) > 36000000) { header('Location: ' . $original); exit; }
@ini_set('memory_limit', '256M');
switch ($info[2]) {
    case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($source); break;
    case IMAGETYPE_PNG:  $im = @imagecreatefrompng($source); break;
    case IMAGETYPE_WEBP: $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false; break;
    default: $im = false;
}
if (!$im) { header('Location: ' . $original); exit; }
// Orientation EXIF des photos de téléphone
if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
    $ex = @exif_read_data($source);
    $o = (int) ($ex['Orientation'] ?? 1);
    $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
    if ($angle !== 0) { $rot = @imagerotate($im, $angle, 0); if ($rot) { imagedestroy($im); $im = $rot; } }
}
$w0 = imagesx($im); $h0 = imagesy($im);
$ratio = min(1, $largeur / max(1, $w0));
$w1 = max(1, (int) round($w0 * $ratio)); $h1 = max(1, (int) round($h0 * $ratio));
$mini = imagecreatetruecolor($w1, $h1);
if ($webp) {
    imagealphablending($mini, false); imagesavealpha($mini, true);
    imagefill($mini, 0, 0, imagecolorallocatealpha($mini, 0, 0, 0, 127));
} else {
    imagefill($mini, 0, 0, imagecolorallocate($mini, 8, 28, 21)); // fond du site pour les PNG transparents
}
imagecopyresampled($mini, $im, 0, 0, 0, 0, $w1, $h1, $w0, $h0);
imagedestroy($im);

if (!is_dir($dossier)) { @mkdir($dossier, 0755, true); }
$ecrit = false;
if (is_dir($dossier) && is_writable($dossier)) {
    $tmp = $cache . '.' . getmypid() . '.tmp';
    $ecrit = $webp ? @imagewebp($mini, $tmp, 78) : @imagejpeg($mini, $tmp, 80);
    if ($ecrit) { $ecrit = @rename($tmp, $cache); } else { @unlink($tmp); }
}
if ($ecrit && is_file($cache)) { imagedestroy($mini); $servir($cache); }

// Cache impossible : on envoie la miniature sans la conserver
header('Content-Type: ' . $type);
header('Cache-Control: private, max-age=86400');
if ($webp) { imagewebp($mini, null, 78); } else { imagejpeg($mini, null, 80); }
imagedestroy($mini);
