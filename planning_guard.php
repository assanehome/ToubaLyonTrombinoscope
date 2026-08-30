<?php
/**
 * Touba Lyon 2026 - Garde d'accès du « Planning Dahira ».
 *
 * Autorise l'accès :
 *   - aux ADMINISTRATEURS connectés ;
 *   - aux MEMBRES connectés qui sont RESPONSABLES de la commission
 *     « Secrétariat Général » (table commission_gestionnaires).
 *
 * À inclure EN TOUT PREMIER dans admin_planning.php.
 */
require_once __DIR__ . '/db_setup.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__plAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__plSecretariat = false;

// Recherche de la commission « Secrétariat Général »
try {
    $st = $pdo->prepare("SELECT id FROM commissions WHERE LOWER(nom) LIKE '%secrétariat général%' OR LOWER(nom) LIKE '%secretariat general%' LIMIT 1");
    $st->execute();
    $__secretariatId = (int) $st->fetchColumn();
} catch (Exception $e) {
    $__secretariatId = 0;
}

// Le membre est-il responsable de cette commission ?
if (!$__plAdmin && !empty($_SESSION['player_id']) && $__secretariatId > 0) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ?");
        $st->execute([$__secretariatId, (int) $_SESSION['player_id']]);
        $__plSecretariat = ((int) $st->fetchColumn()) > 0;
    } catch (Exception $e) {
        $__plSecretariat = false;
    }
}

if (!$__plAdmin && !$__plSecretariat) {
    http_response_code(403);
    die('Accès refusé. Cette page est réservée aux administrateurs et aux responsables de la commission Secrétariat Général.');
}
