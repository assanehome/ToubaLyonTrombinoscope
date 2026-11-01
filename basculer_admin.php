<?php
/**
 * Touba Lyon 2026 - Basculer entre l'espace membre et l'espace administration
 *
 * Un membre à qui l'administration a accordé le rôle d'administrateur
 * (membres.is_admin) peut déjà se connecter à l'espace admin avec ses
 * identifiants de membre. Cette page lui évite de ressortir et de retaper son
 * mot de passe : elle ouvre l'autre espace dans la même session.
 *
 * Le droit est relu en base à chaque bascule — jamais depuis la session : un
 * rôle retiré se referme à la tentative suivante.
 *
 * Toujours en POST avec le jeton anti-CSRF : c'est un changement d'état, pas
 * une page à ouvrir.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

/** Ferme le mode administration / gestion et renvoie le membre chez lui. */
function retour_espace_membre(): void
{
    unset(
        $_SESSION['admin_logged_in'],
        $_SESSION['admin_username'],
        $_SESSION['admin_is_member'],
        $_SESSION['admin_member_id'],
        $_SESSION['admin_must_change'],
        $_SESSION['admin_id']
    );
    header('Location: index.php');
    exit;
}

$membre_id = (int) ($_SESSION['player_id'] ?? 0);
$vers = (string) ($_POST['vers'] ?? '');

// Sans compte membre en session, la bascule n'a pas de sens.
if ($membre_id <= 0) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate()) {
    // Requête inattendue : on ne change rien
    header('Location: ' . (!empty($_SESSION['admin_logged_in']) ? 'admin_dashboard.php' : 'index.php'));
    exit;
}

if ($vers === 'membre') {
    retour_espace_membre();
}

// Le rôle est relu en base : il a pu être retiré depuis la connexion.
try {
    $q = $pdo->prepare("SELECT id, prenom, nom FROM membres WHERE id = ? AND is_admin = 1 AND status = 'approved'");
    $q->execute([$membre_id]);
    $membre = $q->fetch();
} catch (Exception $e) {
    error_log('Touba Lyon basculer_admin: ' . $e->getMessage());
    $membre = null;
}

if (!$membre) {
    // Rôle absent ou retiré : si la session était en administration, on la referme.
    if (!empty($_SESSION['admin_logged_in'])) {
        retour_espace_membre();
    }
    header('Location: index.php');
    exit;
}

// Même session d'administration que par admin_login.php pour un membre-admin.
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = trim(($membre['prenom'] ?? '') . ' ' . ($membre['nom'] ?? ''));
$_SESSION['admin_is_member'] = true;
$_SESSION['admin_member_id'] = (int) $membre['id'];
$_SESSION['admin_must_change'] = false;
unset($_SESSION['admin_id']);

header('Location: admin_dashboard.php');
exit;
