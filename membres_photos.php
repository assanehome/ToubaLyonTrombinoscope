<?php
/**
 * Touba Lyon 2026 - Photos des membres pour les listes déroulantes
 *
 * modern-select.js affiche une miniature devant chaque nom des listes de
 * membres (responsable, membre, intégrateur…). Cette page lui donne, en une
 * fois, la photo de chaque membre validé : { "12": "uploads/abc.jpg", … }.
 * Rien d'autre (ni nom, ni contact) : le nom est déjà dans la liste.
 * Réservée aux personnes connectées ; lecture seule.
 */
require_once __DIR__ . '/db_setup.php';
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
header('Content-Type: application/json; charset=UTF-8');

$connecte = (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)
    || !empty($_SESSION['player_id']) || !empty($_SESSION['integrateur_id']);
if (!$connecte) {
    http_response_code(403);
    header('Cache-Control: no-store');
    echo '{}';
    exit;
}
// Session libérée tout de suite : la liste se charge sans bloquer les autres requêtes
session_write_close();

$photos = [];
try {
    $q = $pdo->query("SELECT id, photo_path FROM membres WHERE status = 'approved' AND photo_path <> ''");
    foreach ($q as $r) {
        // Même chemin que partout dans le site (uploads/ + photo_path), sans remontée de dossier
        $p = str_replace('\\', '/', (string) $r['photo_path']);
        if (strpos($p, '..') !== false) { continue; }
        $photos[(string) (int) $r['id']] = 'uploads/' . implode('/', array_map('rawurlencode', explode('/', $p)));
    }
} catch (Exception $e) {
    $photos = [];
}
// Gardées quelques minutes par le navigateur : une liste de plus ne recharge rien
header('Cache-Control: private, max-age=300');
echo json_encode((object) $photos, JSON_UNESCAPED_SLASHES);
