<?php
/**
 * Touba Lyon 2026 - Restreint les membres NON validés à leur profil.
 *
 * À inclure sur les pages réservées aux membres validés (index, kikanla, play),
 * APRÈS la vérification de $_SESSION['player_id']. Prérequis : $pdo connecté.
 * Un membre en attente (ou refusé) est redirigé vers profile.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['player_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT status FROM membres WHERE id = ?");
        $stmt->execute([$_SESSION['player_id']]);
        $__pstatus = $stmt->fetchColumn();
        if ($__pstatus !== false && $__pstatus !== 'approved') {
            header('Location: profile.php');
            exit;
        }
    } catch (Exception $e) {
        // silencieux : en cas d'erreur, on ne bloque pas
    }
}
?>
