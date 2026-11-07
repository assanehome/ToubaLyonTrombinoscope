<?php
/**
 * Touba Lyon 2026 - Garde d'accès du « Planning Guddi Àjjuma ».
 *
 * Autorise l'accès :
 *   - aux ADMINISTRATEURS connectés ;
 *   - aux MEMBRES connectés qui sont RESPONSABLES de la commission
 *     « Culte » (table commission_gestionnaires).
 *
 * À inclure EN TOUT PREMIER dans admin_guddi.php.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/fonction_responsables_helper.php'; // responsables désignés du Guddi Àjjuma

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__guAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__guCulte = false;

// Recherche de la commission « Culte »
try {
    $st = $pdo->prepare("SELECT id FROM commissions WHERE LOWER(nom) LIKE '%culte%' LIMIT 1");
    $st->execute();
    $__culteId = (int) $st->fetchColumn();
} catch (Exception $e) {
    $__culteId = 0;
}

// Le membre est-il responsable désigné du Guddi Àjjuma ? (seuls eux et l'administrateur y ont accès)
if (!$__guAdmin && !empty($_SESSION['player_id'])) {
    $__guCulte = fr_est_responsable($pdo, 'guddi', (int) $_SESSION['player_id']);
}

if (!$__guAdmin && !$__guCulte) {
    http_response_code(403);
    die('Accès refusé. Cette page est réservée aux administrateurs et aux responsables du Guddi Àjjuma.');
}
