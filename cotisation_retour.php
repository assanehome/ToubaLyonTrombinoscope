<?php
/**
 * Touba Lyon 2026 - Retour de paiement Stripe (cotisation)
 *
 * Vérifie la session Stripe Checkout après paiement, marque la cotisation
 * réglée et envoie le remerciement, puis redirige vers « Ma cotisation ».
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/cotisation_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}

$sessionId = (string) ($_GET['session_id'] ?? '');
if ($sessionId === '') {
    header('Location: cotisation.php');
    exit;
}

$conf = cotisation_stripe_config($pdo);
if ($conf['sk'] === '') {
    header('Location: cotisation.php');
    exit;
}

$res = cotisation_stripe_api($conf['sk'], 'GET', 'checkout/sessions/' . rawurlencode($sessionId));
if ($res['ok']) {
    $s = $res['data'];
    $paid = ($s['payment_status'] ?? '') === 'paid' || ($s['status'] ?? '') === 'complete';
    $mid = (int) ($s['metadata']['membre_id'] ?? 0);
    $periode = (string) ($s['metadata']['periode'] ?? '');
    // Repli : client_reference_id "membre:periode"
    if (($mid === 0 || $periode === '') && !empty($s['client_reference_id'])) {
        $parts = explode(':', (string) $s['client_reference_id']);
        if (count($parts) === 2) { $mid = (int) $parts[0]; $periode = $parts[1]; }
    }
    if ($paid && $mid > 0 && preg_match('/^\d{4}-\d{2}$/', $periode)) {
        cotisation_marquer_paye($pdo, $mid, $periode, 'stripe', $sessionId);
        header('Location: cotisation.php?ok=1');
        exit;
    }
}
header('Location: cotisation.php?annule=1');
exit;
