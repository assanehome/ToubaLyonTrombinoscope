<?php
/**
 * Touba Lyon 2026 - Vérification en direct d'une adresse e-mail (formulaires d'inscription).
 *
 * Répond en JSON { used: bool, status: string, message: string } : l'adresse est-elle déjà
 * utilisée par un compte (membre, administrateur, intégrateur) ? Lecture seule, limitée par session.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/email_unique_helper.php';
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$rep = ['used' => false, 'status' => '', 'message' => ''];

// Limite : 40 vérifications par heure et par session
$now = time();
$_SESSION['email_check_log'] = array_values(array_filter((array) ($_SESSION['email_check_log'] ?? []), static function ($t) use ($now) { return $t > $now - 3600; }));
if (count($_SESSION['email_check_log']) >= 40) {
    echo json_encode($rep);
    exit;
}
$_SESSION['email_check_log'][] = $now;

$email = trim((string) ($_GET['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode($rep);
    exit;
}

try {
    $st = $pdo->prepare("SELECT status FROM membres WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $row = $st->fetch();
    if ($row && $row['status'] === 'email_a_verifier') { $row = false; } // inscription jamais vérifiée : adresse libre
    if ($row) {
        $rep['used'] = true;
        if ($row['status'] === 'approved') {
            $rep['status'] = 'approved';
            $rep['message'] = "Cette adresse email est déjà utilisée : un compte existe déjà avec cette adresse.";
        } elseif ($row['status'] === 'pending') {
            $rep['status'] = 'pending';
            $rep['message'] = "Cette adresse email est déjà utilisée : une demande d'inscription est déjà en attente de validation.";
        } else {
            $rep['status'] = 'refused';
            $rep['message'] = "Cette adresse email est déjà utilisée (demande refusée). Veuillez contacter un administrateur.";
        }
    } elseif (email_autre_compte($pdo, $email) !== null) {
        $rep['used'] = true;
        $rep['status'] = 'autre';
        $rep['message'] = "Cette adresse email est déjà utilisée par un autre compte. Utilisez une autre adresse.";
    }
} catch (Exception $e) {
    error_log('Touba Lyon email_disponible : ' . $e->getMessage());
}
echo json_encode($rep, JSON_UNESCAPED_UNICODE);
