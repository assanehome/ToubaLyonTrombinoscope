<?php
/**
 * Touba Lyon 2026 - Garde d'accès pour la gestion des lectures du Coran (Khatm).
 * Autorise :
 *   - l'administrateur et les responsables de la commission « Culte » (accès complet) ;
 *   - les délégués désignés par un responsable Culte (création de sessions) ;
 *   - les gestionnaires d'un Kamil (suivi des seules sessions qui leur sont confiées).
 * Expose $__isAdmin, $__isCulteManager (accès complet), $__isCulteDelegate,
 * $__kamilKeys (clés des Kamils gérés) et $__wirdMemberId.
 */
require_once __DIR__ . '/db_setup.php';
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

/** Clé d'un Kamil : identifiant de série (sessions périodiques) ou de la session. */
function wird_session_key(array $s): int
{
    return (int) (!empty($s['serie_id']) ? $s['serie_id'] : $s['id']);
}

/** Le membre peut-il gérer cette session ? (accès complet ou gestionnaire du Kamil) */
function wird_can_manage_session(array $s, bool $isFull, array $kamilKeys): bool
{
    return $isFull || in_array(wird_session_key($s), $kamilKeys, true);
}

$__isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__isCulteManager = $__isAdmin;
$__isCulteDelegate = false;
$__kamilKeys = [];
$__wirdMemberId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;
if (!$__isAdmin && $__wirdMemberId > 0) {
    try {
        $__isCulteManager = ((int) $pdo->query(
            "SELECT COUNT(*) FROM commission_gestionnaires cg
             JOIN commissions c ON c.id = cg.commission_id
             WHERE cg.membre_id = " . $__wirdMemberId . " AND LOWER(c.nom) LIKE '%culte%'"
        )->fetchColumn() > 0);
    } catch (Exception $e) {
        $__isCulteManager = false;
    }
    $_SESSION['is_gestion_culte'] = $__isCulteManager;
    if (!$__isCulteManager) {
        try {
            $__isCulteDelegate = ((int) $pdo->query("SELECT COUNT(*) FROM culte_delegues WHERE membre_id = " . $__wirdMemberId)->fetchColumn() > 0);
            $__kamilKeys = array_map('intval', $pdo->query("SELECT session_key FROM quran_session_gestionnaires WHERE membre_id = " . $__wirdMemberId)->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            $__isCulteDelegate = false;
            $__kamilKeys = [];
        }
    }
}
if (!$__isCulteManager && !$__isCulteDelegate && empty($__kamilKeys)) {
    header('Location: login.php');
    exit;
}
?>
