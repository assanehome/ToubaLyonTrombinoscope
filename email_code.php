<?php
/**
 * Touba Lyon 2026 - Validation de l'adresse e-mail par code (inscription).
 *
 * Réponses JSON. Actions (POST) :
 *   - send   : envoie un code à 6 chiffres à l'adresse (refusée si déjà utilisée) ;
 *   - verify : contrôle le code saisi ; en cas de succès, l'adresse est marquée « vérifiée » en session ;
 *   - status : l'adresse est-elle déjà vérifiée dans cette session ?
 * L'inscription (register.php, inscription_rapide.php) exige une adresse vérifiée.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/email_unique_helper.php';
require_once __DIR__ . '/send_mail.php';
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function ec_rep(array $r): void { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ec_rep(['ok' => false, 'message' => 'Requête invalide.']); }

$action = (string) ($_POST['action'] ?? '');
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ec_rep(['ok' => false, 'message' => "Adresse e-mail invalide."]);
}
$now = time();

if ($action === 'status') {
    ec_rep(['ok' => true, 'verified' => email_est_verifie($email)]);
}

if ($action === 'send') {
    // Adresse déjà utilisée : inutile d'envoyer un code
    try {
        $st = $pdo->prepare("SELECT status FROM membres WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        $row = $st->fetch();
        if ($row) {
            ec_rep(['ok' => false, 'used' => true, 'message' => $row['status'] === 'pending'
                ? "Une demande d'inscription avec cette adresse est déjà en attente de validation."
                : ($row['status'] === 'approved' ? "Cette adresse email est déjà utilisée : un compte existe déjà." : "Cette adresse email a été refusée. Veuillez contacter un administrateur.")]);
        }
        if (email_autre_compte($pdo, $email) !== null) {
            ec_rep(['ok' => false, 'used' => true, 'message' => email_autre_compte_message()]);
        }
    } catch (Exception $e) {
        error_log('Touba Lyon email_code : ' . $e->getMessage());
        ec_rep(['ok' => false, 'message' => "Une erreur technique est survenue."]);
    }

    // Délai entre deux envois (session) : 60 s
    // (uniquement pour la même adresse : changer d'adresse permet d'en demander un tout de suite)
$dernier = (($_SESSION['email_code']['email'] ?? '') === $email) ? (int) ($_SESSION['email_code']['sent'] ?? 0) : 0;
    if ($dernier > $now - 60) {
        ec_rep(['ok' => false, 'wait' => 60 - ($now - $dernier), 'message' => "Patientez quelques secondes avant de redemander un code."]);
    }
    // Limites en base : 3 envois / 30 min par adresse, 10 / heure par IP
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    try {
        $c1 = $pdo->prepare("SELECT COUNT(*) FROM email_verif_envois WHERE email = ? AND created_at > (NOW() - INTERVAL 30 MINUTE)");
        $c1->execute([$email]);
        $c2 = $pdo->prepare("SELECT COUNT(*) FROM email_verif_envois WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
        $c2->execute([$ip]);
        if ((int) $c1->fetchColumn() >= 3 || (int) $c2->fetchColumn() >= 10) {
            ec_rep(['ok' => false, 'message' => "Trop de demandes de code. Réessayez dans quelques minutes."]);
        }
        $pdo->prepare("INSERT INTO email_verif_envois (email, ip) VALUES (?, ?)")->execute([$email, $ip]);
        $pdo->exec("DELETE FROM email_verif_envois WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    } catch (Exception $e) {
        error_log('Touba Lyon email_code limite : ' . $e->getMessage());
    }

    $code = (string) random_int(100000, 999999);
    $salt = bin2hex(random_bytes(8));
    $html = '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;">'
        . '<h2 style="color:#1b4332;">As-salaamu haleykum 🙏</h2>'
        . '<p style="color:#333;">Voici votre code pour valider votre adresse e-mail et finaliser votre inscription au Dahira :</p>'
        . '<p style="text-align:center;margin:22px 0;"><span style="display:inline-block;font-size:32px;letter-spacing:8px;font-weight:bold;color:#1b4332;background:#f6f3e8;border:1px solid #d4af37;border-radius:12px;padding:12px 22px;">' . $code . '</span></p>'
        . '<p style="color:#555;font-size:14px;">Ce code est valable <strong>10 minutes</strong>. Si vous n\'êtes pas à l\'origine de cette demande, ignorez simplement ce message.</p>'
        . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p></div>';
    $texte = "Votre code de vérification : $code (valable 10 minutes).";
    $ok = false;
    try { $ok = send_smtp_mail($email, '', 'Votre code de vérification — Dahira Touba Lyon', $html, $texte); } catch (Throwable $e) { error_log('Touba Lyon email_code envoi : ' . $e->getMessage()); }
    if (!$ok) {
        ec_rep(['ok' => false, 'message' => "L'envoi du code a échoué. Vérifiez l'adresse et réessayez."]);
    }
    $_SESSION['email_code'] = ['email' => $email, 'salt' => $salt, 'hash' => hash('sha256', $salt . $code), 'exp' => $now + 600, 'tries' => 0, 'sent' => $now];
    unset($_SESSION['email_verifie']);
    ec_rep(['ok' => true, 'message' => "Code envoyé à $email. Pensez à vérifier vos courriers indésirables."]);
}

if ($action === 'verify') {
    $saisi = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
    $c = $_SESSION['email_code'] ?? null;
    if (!is_array($c) || ($c['email'] ?? '') !== $email) {
        ec_rep(['ok' => false, 'message' => "Demandez d'abord un code pour cette adresse."]);
    }
    if ((int) $c['exp'] < $now) {
        unset($_SESSION['email_code']);
        ec_rep(['ok' => false, 'expired' => true, 'message' => "Ce code a expiré. Demandez-en un nouveau."]);
    }
    if ((int) $c['tries'] >= 5) {
        unset($_SESSION['email_code']);
        ec_rep(['ok' => false, 'expired' => true, 'message' => "Trop d'essais. Demandez un nouveau code."]);
    }
    $_SESSION['email_code']['tries'] = (int) $c['tries'] + 1;
    if (strlen($saisi) === 6 && hash_equals((string) $c['hash'], hash('sha256', $c['salt'] . $saisi))) {
        unset($_SESSION['email_code']);
        $_SESSION['email_verifie'] = ['email' => $email, 'ts' => $now];
        ec_rep(['ok' => true, 'verified' => true, 'message' => "Adresse e-mail vérifiée."]);
    }
    $reste = 5 - (int) $_SESSION['email_code']['tries'];
    ec_rep(['ok' => false, 'message' => "Code incorrect." . ($reste > 0 ? " Il vous reste $reste essai" . ($reste > 1 ? 's' : '') . '.' : '')]);
}

ec_rep(['ok' => false, 'message' => 'Action inconnue.']);
