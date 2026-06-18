<?php
/**
 * Touba Lyon 2026 - Player Logout (Ki Kan La)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear player specific session variables
unset($_SESSION['player_id']);
unset($_SESSION['player_name']);
unset($_SESSION['player_score']);

header('Location: index.php');
exit;
