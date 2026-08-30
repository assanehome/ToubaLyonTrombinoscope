<?php
/**
 * Touba Lyon 2026 - Garde d'accès pour les espaces commissions.
 *
 * Autorise l'accès :
 *   - aux ADMINISTRATEURS connectés (voient toutes les commissions),
 *   - aux MEMBRES connectés qui sont RESPONSABLES d'au moins une commission
 *     (rôle par commission, table commission_gestionnaires).
 * À inclure EN TOUT PREMIER. Expose $__isAdmin et $__managedCommissions (ids).
 */
require_once __DIR__ . '/db_setup.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__managedCommissions = [];

if (!empty($_SESSION['player_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT commission_id FROM commission_gestionnaires WHERE membre_id = ?");
        $stmt->execute([(int) $_SESSION['player_id']]);
        $__managedCommissions = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        $__managedCommissions = [];
    }
    $_SESSION['is_gestion_commission'] = !empty($__managedCommissions);
}

if (!$__isAdmin && empty($__managedCommissions)) {
    header('Location: login.php');
    exit;
}
?>
