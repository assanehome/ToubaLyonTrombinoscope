<?php
/**
 * Touba Lyon 2026 - Lecture des enregistrements du Guddi Àjjuma (sans téléchargement)
 *
 * guddi_audio.php?id=<séance> envoie l'enregistrement de la séance au lecteur de la page (balise <audio>), avec prise en charge
 * du déplacement dans l'enregistrement (en-têtes Range). Le dossier des enregistrements est fermé à tout accès direct : le fichier
 * ne se lit qu'ici, pour un membre validé ou un administrateur connecté. Pas de nom de fichier dans la réponse, pas de mise en cache,
 * et refus d'une ouverture directe dans le navigateur (nouvel onglet, cadre) : l'enregistrement s'écoute dans les pages du site.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/planning_guddi_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$admin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$membreId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;
if (!$admin && $membreId <= 0) { http_response_code(403); exit; }
if (!$admin) {
    try {
        $st = $pdo->prepare("SELECT status FROM membres WHERE id = ?");
        $st->execute([$membreId]);
        if ($st->fetchColumn() !== 'approved') { http_response_code(403); exit; }
    } catch (Exception $e) { http_response_code(403); exit; }
}
// Pas d'ouverture directe de l'adresse (barre d'adresse, nouvel onglet, cadre) : seulement depuis un lecteur audio
$dest = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
if (in_array($dest, ['document', 'iframe', 'frame', 'embed', 'object'], true)) { http_response_code(403); exit; }

$id = (int) ($_GET['id'] ?? 0);
$chemin = null;
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT audio_path FROM guddi_plannings WHERE id = ?");
        $st->execute([$id]);
        $ap = (string) $st->fetchColumn();
        $chemin = $ap !== '' ? guddi_audio_fichier_abs($ap) : null;
    } catch (Exception $e) { $chemin = null; }
}
if ($chemin === null || !is_file($chemin)) { http_response_code(404); exit; }
guddi_audio_proteger_dossier(); // le dossier reste fermé à l'accès direct

session_write_close(); // pas de verrou de session pendant la lecture
$taille = (int) filesize($chemin);
$types = ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'wav' => 'audio/wav'];
$type = $types[strtolower(pathinfo($chemin, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

$debut = 0;
$fin = $taille - 1;
$partiel = false;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $debut = max(0, $taille - (int) $m[2]); }
    else { $debut = (int) $m[1]; if ($m[2] !== '') { $fin = min($fin, (int) $m[2]); } }
    if ($debut > $fin || $debut >= $taille) {
        http_response_code(416);
        header('Content-Range: bytes */' . $taille);
        exit;
    }
    $partiel = true;
}

header('Content-Type: ' . $type);
header('Accept-Ranges: bytes');
header('Content-Disposition: inline');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if ($partiel) {
    http_response_code(206);
    header('Content-Range: bytes ' . $debut . '-' . $fin . '/' . $taille);
}
header('Content-Length: ' . ($fin - $debut + 1));

@set_time_limit(0);
$f = fopen($chemin, 'rb');
if ($f === false) { http_response_code(500); exit; }
fseek($f, $debut);
$reste = $fin - $debut + 1;
while ($reste > 0 && !feof($f) && !connection_aborted()) {
    $bloc = fread($f, (int) min(65536, $reste));
    if ($bloc === false || $bloc === '') { break; }
    echo $bloc;
    $reste -= strlen($bloc);
    flush();
}
fclose($f);
