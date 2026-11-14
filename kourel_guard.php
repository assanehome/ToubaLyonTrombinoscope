<?php
/**
 * Touba Lyon 2026 - Garde d'accès pour l'espace Kurels (modèle commission complet).
 *
 * Autorise :
 *   - les ADMINISTRATEURS,
 *   - les responsables désignés des Kurels ($__isKurelAdmin) : créer/gérer tous les Kurels,
 *   - les responsables d'un Kurel donné ($__managedKourels) : gérer les membres de leurs Kurels.
 * Expose : $__isAdmin, $__isKurelAdmin, $__managedKourels.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/kourel_access.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__isKurelAdmin = $__isAdmin;
$__managedKourels = [];

if (!empty($_SESSION['player_id'])) {
    $__pid = (int) $_SESSION['player_id'];
    $__isKurelAdmin = $__isKurelAdmin || member_is_kourel_admin($pdo, $__pid);
    $__managedKourels = member_managed_kourels($pdo, $__pid);
    // Drapeau du menu membre : uniquement les rôles réels du membre (jamais le simple statut d'administrateur)
    $_SESSION['is_gestion_kourel'] = (member_is_kourel_admin($pdo, $__pid) || !empty($__managedKourels));
}

if (!$__isKurelAdmin && empty($__managedKourels)) {
    header('Location: login.php');
    exit;
}
?>
