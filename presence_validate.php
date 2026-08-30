<?php
/**
 * Touba Lyon 2026 - Validation de présence à un Dahira / Guddi Àjjuma publié
 *
 * POST : action=validate&type=dahira|guddi&id=<planning_id>&csrf_token=...
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';

header('Content-Type: application/json; charset=utf-8');

// Membre connecté requis
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['player_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Connectez-vous pour valider votre présence.']);
    exit;
}
if (!csrf_validate()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Session expirée. Rechargez la page.']);
    exit;
}

$membreId = (int) $_SESSION['player_id'];
$type = $_POST['type'] ?? '';
$planningId = (int) ($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!in_array($type, ['dahira', 'guddi'], true) || $planningId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Paramètres invalides.']);
    exit;
}

try {
    if ($action === 'validate') {
        // Vérifier que la séance est publiée et que la date est passée/aujourd'hui
        $table = $type === 'dahira' ? 'dahira_plannings' : 'guddi_plannings';
        $dateCol = $type === 'dahira' ? 'date_dahira' : 'date_guddi';
        $st = $pdo->prepare("SELECT $dateCol, publie FROM $table WHERE id = ?");
        $st->execute([$planningId]);
        $row = $st->fetch();
        if (!$row || (int)($row['publie'] ?? 0) !== 1) {
            echo json_encode(['ok' => false, 'msg' => 'Cette séance n\'est pas publiée.']);
            exit;
        }
        if ($row[$dateCol] > date('Y-m-d')) {
            echo json_encode(['ok' => false, 'msg' => 'La séance n\'a pas encore eu lieu.']);
            exit;
        }
        $pdo->prepare("INSERT IGNORE INTO presence_validations (planning_type, planning_id, membre_id) VALUES (?, ?, ?)")
            ->execute([$type, $planningId, $membreId]);
        echo json_encode(['ok' => true, 'msg' => 'Présence enregistrée. Jazakallahou Khair !']);
    } elseif ($action === 'cancel') {
        $pdo->prepare("DELETE FROM presence_validations WHERE planning_type = ? AND planning_id = ? AND membre_id = ?")
            ->execute([$type, $planningId, $membreId]);
        echo json_encode(['ok' => true, 'msg' => 'Présence annulée.']);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    }
} catch (Exception $e) {
    error_log('Touba Lyon présence : ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Une erreur technique est survenue.']);
}
