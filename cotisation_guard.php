<?php
/**
 * Touba Lyon 2026 - Garde d'accès de la Finance (gestion des cotisations).
 *
 * Autorise l'accès :
 *   - aux ADMINISTRATEURS connectés ;
 *   - aux MEMBRES connectés désignés RESPONSABLES Finance (fonction « cotisation »,
 *     comme pour la Lecture Coran ou le Wadial Magal) — table fonction_responsables.
 *
 * À inclure EN TOUT PREMIER dans admin_cotisations.php.
 * Expose $__cotAdmin (bool), $__cotFinance (bool : responsable désigné) et $__cotMemberId.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/fonction_responsables_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__cotAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__cotMemberId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;
$__cotFinance = (!$__cotAdmin && $__cotMemberId > 0) ? fr_est_responsable($pdo, 'cotisation', $__cotMemberId) : false;

if (!$__cotAdmin && !$__cotFinance) {
    http_response_code(403);
    die('Accès refusé. Cette page est réservée aux administrateurs et aux responsables Finance.');
}
