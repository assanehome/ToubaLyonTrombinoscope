<?php
/**
 * Touba Lyon 2026 - Garde d'accès administrateur
 *
 * À inclure EN TOUT PREMIER dans les scripts réservés aux administrateurs
 * (maintenance, génération de données). Refuse l'accès si l'utilisateur
 * n'est pas connecté en tant qu'admin.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    die('Accès refusé. Cette page est réservée aux administrateurs connectés. Connectez-vous via admin_login.php.');
}
?>
