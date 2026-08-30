<?php
/**
 * Touba Lyon 2026 - Garde d'accès pour admin_reponses.php
 *
 * Autorise l'accès aux ADMINISTRATEURS et aux INTÉGRATEURS connectés.
 * Si un intégrateur doit encore changer son mot de passe (première connexion),
 * il est redirigé vers la page de changement.
 * À inclure EN TOUT PREMIER.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__isIntegrateur = !empty($_SESSION['integrateur_id']);

if (!$__isAdmin && !$__isIntegrateur) {
    header('Location: integrateur_login.php');
    exit;
}

// Première connexion intégrateur : changement de mot de passe obligatoire.
if ($__isIntegrateur && !empty($_SESSION['integrateur_must_change'])
    && basename($_SERVER['PHP_SELF']) !== 'integrateur_password.php') {
    header('Location: integrateur_password.php');
    exit;
}
?>
