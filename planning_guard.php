<?php
/**
 * Touba Lyon 2026 - Garde d'accès du « Planning Dahira ».
 *
 * Autorise l'accès :
 *   - aux ADMINISTRATEURS connectés ;
 *   - aux MEMBRES connectés désignés RESPONSABLES du Planning (fonction « planning »,
 *     comme pour la Lecture Coran ou la Finance) — table fonction_responsables.
 *
 * À inclure EN TOUT PREMIER dans admin_planning.php.
 * Expose $__plAdmin, $__plSecretariat (responsable désigné) et $__plMemberId.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/fonction_responsables_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__plAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__plMemberId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;
$__plSecretariat = (!$__plAdmin && $__plMemberId > 0) ? fr_est_responsable($pdo, 'planning', $__plMemberId) : false;

if (!$__plAdmin && !$__plSecretariat) {
    http_response_code(403);
    die('Accès refusé. Cette page est réservée aux administrateurs et aux responsables du planning.');
}
