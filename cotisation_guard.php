<?php
/**
 * Touba Lyon 2026 - Garde d'accès de la gestion des cotisations.
 *
 * Autorise l'accès :
 *   - aux ADMINISTRATEURS connectés ;
 *   - aux MEMBRES connectés RESPONSABLES de la commission « Finance »
 *     (ou « Trésorerie ») — table commission_gestionnaires.
 *
 * À inclure EN TOUT PREMIER dans admin_cotisations.php.
 * Expose $__cotAdmin (bool) et $__cotFinance (bool).
 */
require_once __DIR__ . '/db_setup.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__cotAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__cotFinance = false;

// Recherche de la commission « Finance » / « Trésorerie »
try {
    $st = $pdo->prepare("SELECT id FROM commissions WHERE LOWER(nom) LIKE '%finance%' OR LOWER(nom) LIKE '%trésor%' OR LOWER(nom) LIKE '%tresor%' LIMIT 1");
    $st->execute();
    $__financeId = (int) $st->fetchColumn();
} catch (Exception $e) {
    $__financeId = 0;
}

if (!$__cotAdmin && !empty($_SESSION['player_id']) && $__financeId > 0) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ?");
        $st->execute([$__financeId, (int) $_SESSION['player_id']]);
        $__cotFinance = ((int) $st->fetchColumn()) > 0;
    } catch (Exception $e) {
        $__cotFinance = false;
    }
}

if (!$__cotAdmin && !$__cotFinance) {
    http_response_code(403);
    die('Accès refusé. Cette page est réservée aux administrateurs et aux responsables de la commission Finance.');
}
