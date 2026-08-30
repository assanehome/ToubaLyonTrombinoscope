<?php
/**
 * Touba Lyon 2026 - Garde d'accès pour la gestion des lectures du Coran (Khatm).
 * Autorise l'administrateur et les responsables de la commission « Culte ».
 * Expose $__isAdmin et $__isCulteManager.
 */
require_once __DIR__ . '/db_setup.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$__isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__isCulteManager = $__isAdmin;
if (!$__isAdmin && !empty($_SESSION['player_id'])) {
    try {
        $__isCulteManager = ((int) $pdo->query(
            "SELECT COUNT(*) FROM commission_gestionnaires cg
             JOIN commissions c ON c.id = cg.commission_id
             WHERE cg.membre_id = " . (int) $_SESSION['player_id'] . " AND LOWER(c.nom) LIKE '%culte%'"
        )->fetchColumn() > 0);
    } catch (Exception $e) {
        $__isCulteManager = false;
    }
    $_SESSION['is_gestion_culte'] = $__isCulteManager;
}
if (!$__isCulteManager) {
    header('Location: login.php');
    exit;
}
?>
