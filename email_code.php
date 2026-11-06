<?php
/**
 * Touba Lyon 2026 - Validation de l'adresse e-mail par code, après l'envoi de l'inscription.
 *
 * Réponses JSON (POST). Toutes les actions concernent l'inscription en cours de vérification
 * de la session (aucune adresse n'est testable sans avoir soumis le formulaire) :
 *   - send   : renvoie un code ;
 *   - verify : contrôle le code ; en cas de succès l'inscription passe « en attente de validation admin » ;
 *   - change : corrige l'adresse e-mail de l'inscription et envoie un nouveau code.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/email_unique_helper.php';
require_once __DIR__ . '/inscription_verif_helper.php';
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function ec_rep(array $r): void { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ec_rep(['ok' => false, 'message' => 'Requête invalide.']); }

$id = (int) ($_SESSION['verif_membre_id'] ?? 0);
if ($id <= 0 || verif_etat($pdo) === '') {
    ec_rep(['ok' => false, 'expired' => true, 'message' => "Votre inscription n'est plus en cours de validation. Rechargez la page pour recommencer."]);
}
$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'send') {
        ec_rep(verif_envoyer_code($pdo, $id, false));
    }

    if ($action === 'verify') {
        $saisi = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''));
        $st = $pdo->prepare("SELECT code_hash, salt, tries, (expires_at < NOW()) AS expire FROM email_verifications WHERE membre_id = ?");
        $st->execute([$id]);
        $v = $st->fetch();
        if (!$v || (string) $v['code_hash'] === '') { ec_rep(['ok' => false, 'message' => "Demandez un code : cliquez sur « Renvoyer le code »."]); }
        if ((int) $v['expire'] === 1) { ec_rep(['ok' => false, 'message' => "Ce code a expiré. Cliquez sur « Renvoyer le code »."]); }
        if ((int) $v['tries'] >= 5) { ec_rep(['ok' => false, 'message' => "Trop d'essais. Cliquez sur « Renvoyer le code » pour en recevoir un nouveau."]); }
        $pdo->prepare("UPDATE email_verifications SET tries = tries + 1 WHERE membre_id = ?")->execute([$id]);
        if (strlen($saisi) === 6 && hash_equals((string) $v['code_hash'], hash('sha256', $v['salt'] . $saisi))) {
            $em = $pdo->prepare("SELECT email FROM membres WHERE id = ?");
            $em->execute([$id]);
            $email = (string) $em->fetchColumn();
            verif_finaliser($pdo, $id);
            unset($_SESSION['verif_membre_id']);
            $_SESSION['verif_termine'] = $email;
            ec_rep(['ok' => true, 'verified' => true, 'email' => $email, 'message' => 'Adresse e-mail vérifiée.']);
        }
        $reste = 5 - ((int) $v['tries'] + 1);
        ec_rep(['ok' => false, 'message' => "Code incorrect." . ($reste > 0 ? " Il vous reste $reste essai" . ($reste > 1 ? 's' : '') . '.' : " Cliquez sur « Renvoyer le code » pour en recevoir un nouveau.")]);
    }

    if ($action === 'change') {
        $new = trim((string) ($_POST['email'] ?? ''));
        if ($new === '' || !filter_var($new, FILTER_VALIDATE_EMAIL)) { ec_rep(['ok' => false, 'message' => "Adresse e-mail invalide."]); }
        // L'adresse est-elle libre ? (une ancienne inscription jamais vérifiée n'est pas un obstacle)
        $q = $pdo->prepare("SELECT id, status FROM membres WHERE LOWER(email) = LOWER(?) AND id <> ?");
        $q->execute([$new, $id]);
        foreach ($q->fetchAll() as $o) {
            if ($o['status'] === VERIF_STATUT) { verif_supprimer_membre($pdo, (int) $o['id']); }
            else { ec_rep(['ok' => false, 'message' => $o['status'] === 'pending'
                ? "Une demande d'inscription avec cette adresse est déjà en attente de validation."
                : "Cette adresse email est déjà utilisée par un compte existant."]); }
        }
        if (email_autre_compte($pdo, $new) !== null) { ec_rep(['ok' => false, 'message' => email_autre_compte_message()]); }
        $pdo->prepare("UPDATE membres SET email = ? WHERE id = ? AND status = ?")->execute([$new, $id, VERIF_STATUT]);
        $r = verif_envoyer_code($pdo, $id, true);
        $r['email'] = $new;
        ec_rep($r);
    }
} catch (Exception $e) {
    error_log('Touba Lyon email_code : ' . $e->getMessage());
    ec_rep(['ok' => false, 'message' => "Une erreur technique est survenue."]);
}
ec_rep(['ok' => false, 'message' => 'Action inconnue.']);
