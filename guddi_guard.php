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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

// Le membre est-il responsable de cette commission ?
if (!$__guAdmin && !empty($_SESSION['player_id']) && $__culteId > 0) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ?");
        $st->execute([$__culteId, (int) $_SESSION['player_id']]);
        $__guCulte = ((int) $st->fetchColumn()) > 0;
    } catch (Exception $e) {
        $__guCulte = false;
    }
}

if (!$__guAdmin && !$__guCulte) {
    http_response_code(403);
    die('Accès refusé. Cette page est réservée aux administrateurs et aux responsables de la commission Culte.');
}
