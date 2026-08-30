<?php
/**
 * Touba Lyon 2026 - Abonnement aux notifications « application fermée »
 * (Trombinoscope / Dahira)
 *
 *   POST action=abonner    endpoint, p256dh, auth   → enregistre l'appareil
 *   POST action=desabonner endpoint                 → retire l'appareil
 *   GET                                            → clé publique VAPID
 *
 * Le contrôle anti-CSRF s'applique aux POST (champ csrf_token).
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/push_helper.php';
require_once __DIR__ . '/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['player_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'non connecté']);
    exit;
}

$membre_id = (int)$_SESSION['player_id'];
$conf = push_vapid_config();

// La clé publique permet au navigateur de s'abonner
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode([
        'ok'         => $conf !== null,
        'cle'        => $conf['public'] ?? null,
        'disponible' => $conf !== null,
    ]);
    exit;
}

if (!csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

if ($conf === null) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'notifications non configurées']);
    exit;
}

$action   = $_POST['action'] ?? 'abonner';
$endpoint = trim((string)($_POST['endpoint'] ?? ''));

if ($endpoint === '' || !preg_match('#^https://[^\s]{10,500}$#', $endpoint)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'abonnement invalide']);
    exit;
}

if ($action === 'desabonner') {
    push_delete_subscription($pdo, $endpoint);
    echo json_encode(['ok' => true]);
    exit;
}

$p256dh = trim((string)($_POST['p256dh'] ?? ''));
$auth   = trim((string)($_POST['auth'] ?? ''));

// Tailles attendues : 65 octets pour la clé publique, 16 pour le secret
if (strlen(push_b64url_decode($p256dh)) !== 65 || strlen(push_b64url_decode($auth)) !== 16) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'clés d\'abonnement inattendues']);
    exit;
}

$ok = push_save_subscription($pdo, $membre_id, $endpoint, $p256dh, $auth);
echo json_encode(['ok' => $ok]);
