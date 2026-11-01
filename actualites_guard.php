<?php
/**
 * Touba Lyon 2026 - Garde d'accès des « Actualités ».
 *
 * Autorise l'accès :
 *   - aux ADMINISTRATEURS connectés ;
 *   - aux MEMBRES connectés qui sont RESPONSABLES de la commission
 *     « Secrétariat Général » (table commission_gestionnaires).
 *
 * À inclure EN TOUT PREMIER dans actualites.php.
 * Expose $__actuAdmin, $__actuSecretariat et $__secretariatId.
 */
require_once __DIR__ . '/db_setup.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__actuAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$__actuSecretariat = false;

// Recherche de la commission « Secrétariat Général »
try {
    $st = $pdo->prepare("SELECT id FROM commissions WHERE LOWER(nom) LIKE '%secrétariat général%' OR LOWER(nom) LIKE '%secretariat general%' LIMIT 1");
    $st->execute();
    $__secretariatId = (int) $st->fetchColumn();
} catch (Exception $e) {
    $__secretariatId = 0;
}

// Le membre est-il responsable de cette commission ?
if (!$__actuAdmin && !empty($_SESSION['player_id']) && $__secretariatId > 0) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ?");
        $st->execute([$__secretariatId, (int) $_SESSION['player_id']]);
        $__actuSecretariat = ((int) $st->fetchColumn()) > 0;
    } catch (Exception $e) {
        $__actuSecretariat = false;
    }
}

if (!$__actuAdmin && !$__actuSecretariat) {
    http_response_code(403);
    die('Accès refusé. Cette page est réservée aux administrateurs et aux responsables de la commission Secrétariat Général.');
}
