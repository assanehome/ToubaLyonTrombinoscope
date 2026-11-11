<?php
/**
 * Touba Lyon 2026 - Garde d'accès des « Actualités ».
 *
 * Autorise l'accès :
 *   - aux ADMINISTRATEURS connectés ;
 *   - aux MEMBRES connectés désignés RESPONSABLES des Actualités (fonction « actualites »,
 *     comme pour la Lecture Coran ou la Finance) — table fonction_responsables.
 *
 * À inclure EN TOUT PREMIER dans actualites.php.
 * Expose $__actuAdmin, $__actuSecretariat (responsable désigné) et $__actuMemberId.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/fonction_responsables_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__actuAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__actuMemberId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;
$__actuSecretariat = (!$__actuAdmin && $__actuMemberId > 0) ? fr_est_responsable($pdo, 'actualites', $__actuMemberId) : false;

if (!$__actuAdmin && !$__actuSecretariat) {
    http_response_code(403);
    die('Accès refusé. Cette page est réservée aux administrateurs et aux responsables des actualités.');
}
