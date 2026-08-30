<?php
/**
 * Touba Lyon 2026 - Service des notifications du Dahira (cloche navigateur)
 *
 * Interrogé en arrière-plan par les pages ouvertes :
 *   GET  notifications.php            → notifications à afficher + compteur
 *   POST action=affichees&ids=1,2,3   → celles que le navigateur vient de montrer
 *   POST action=lues                  → tout marquer comme lu
 *
 * Réponses en JSON. Le contrôle anti-CSRF s'applique aux POST (champ
 * csrf_token ajouté par le socle).
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/notification_helper.php';
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_validate()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf']);
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'affichees') {
        $ids = array_slice(explode(',', (string)($_POST['ids'] ?? '')), 0, 20);
        troba_notifications_mark_shown($pdo, $membre_id, $ids);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'lues') {
        troba_notifications_mark_all_read($pdo, $membre_id);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'supprimer') {
        $id = (int)($_POST['id'] ?? 0);
        troba_notifications_delete($pdo, $membre_id, $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'action inconnue']);
    exit;
}

troba_purge_notifications($pdo);

// Rattrapage : si personne n'a fini une requête depuis un moment, les
// notifications en attente partent vers les appareils à l'occasion de
// cette vérification, qui se fait en arrière-plan.
troba_notification_push_apres_reponse($pdo);

// Liste complète pour le panneau de la cloche
if (isset($_GET['liste'])) {
    $liste = [];
    foreach (troba_notifications_recent($pdo, $membre_id, 15) as $n) {
        $liste[] = [
            'id'     => (int)$n['id'],
            'titre'  => $n['title'],
            'texte'  => $n['body'],
            'lien'   => $n['url'] ?: null,
            'date'   => $n['created_at'],
            'non_lu' => $n['read_at'] === null,
        ];
    }
    echo json_encode([
        'ok'       => true,
        'liste'    => $liste,
        'non_lues' => troba_notifications_unread_count($pdo, $membre_id),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$nouvelles = [];
foreach (troba_notifications_pending($pdo, $membre_id) as $n) {
    $nouvelles[] = [
        'id'    => (int)$n['id'],
        'titre' => $n['title'],
        'texte' => $n['body'],
        'lien'  => $n['url'] ?: null,
        'date'  => $n['created_at'],
    ];
}

echo json_encode([
    'ok'         => true,
    'nouvelles'  => $nouvelles,
    'non_lues'   => troba_notifications_unread_count($pdo, $membre_id),
], JSON_UNESCAPED_UNICODE);
