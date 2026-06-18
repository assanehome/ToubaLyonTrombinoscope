<?php
/**
 * Touba Lyon 2026 - Confinement de l'espace administrateur
 *
 * À inclure en haut des pages publiques / membres. Si un administrateur est
 * connecté, il est renvoyé vers le tableau de bord : il ne peut pas basculer
 * sur le site public ou l'espace membre tant qu'il est connecté en admin.
 *
 * À inclure AVANT toute sortie HTML.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin_dashboard.php');
    exit;
}
?>
