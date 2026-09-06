<?php
/**
 * Touba Lyon 2026 - Validation de présence à un Dahira / Guddi Àjjuma publié
 *
 * POST : action=validate&type=dahira|guddi&id=<planning_id>&csrf_token=...
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_param(), dahira_presence_ouverte()

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

if (!in_array($type, ['dahira', 'guddi', 'reunion', 'repetition'], true) || $planningId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Paramètres invalides.']);
    exit;
}

try {
    if ($action === 'validate') {
        // Vérifier que la séance est publiée et que la date est passée/aujourd'hui
        if ($type === 'dahira') { $table = 'dahira_plannings'; $dateCol = 'date_dahira'; }
        elseif ($type === 'reunion') { $table = 'commission_reunions'; $dateCol = 'date_reunion'; }
        elseif ($type === 'repetition') { $table = 'kourel_repetitions'; $dateCol = 'date_reunion'; }
        else { $table = 'guddi_plannings'; $dateCol = 'date_guddi'; }
        // Pour les réunions / répétitions, l'heure de début est propre à chacune (colonne heure)
        $selectCols = ($type === 'reunion' || $type === 'repetition') ? "$dateCol, publie, heure" : "$dateCol, publie";
        $st = $pdo->prepare("SELECT $selectCols FROM $table WHERE id = ?");
        $st->execute([$planningId]);
        $row = $st->fetch();
        if (!$row || (int)($row['publie'] ?? 0) !== 1) {
            echo json_encode(['ok' => false, 'msg' => 'Cette séance n\'est pas publiée.']);
            exit;
        }
        // La validation n'est ouverte qu'à partir de l'heure de début de la séance
        if ($type === 'dahira') { $heureDebut = dahira_param($pdo, 'dahira_debut', '17h00'); }
        elseif ($type === 'reunion' || $type === 'repetition') { $heureDebut = (string) ($row['heure'] ?? '20h00'); }
        else { $heureDebut = dahira_param($pdo, 'guddi_heure', '20h00'); }
        if (!dahira_presence_ouverte((string) $row[$dateCol], $heureDebut)) {
            echo json_encode(['ok' => false, 'msg' => 'La validation ne sera possible qu\'à partir de l\'heure de début (' . htmlspecialchars($heureDebut) . ').']);
            exit;
        }
        $pdo->prepare("INSERT IGNORE INTO presence_validations (planning_type, planning_id, membre_id) VALUES (?, ?, ?)")
            ->execute([$type, $planningId, $membreId]);
        echo json_encode(['ok' => true, 'msg' => 'Présence enregistrée. Diadieuf !']);
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
