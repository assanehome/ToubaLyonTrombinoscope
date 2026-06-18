<?php
/**
 * Touba Lyon 2026 - Réinitialisation du mot de passe (via lien e-mail)
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';
require_once __DIR__ . '/csrf.php';

$error = '';
$success = '';
$validToken = false;
$token = '';

/**
 * Recherche un membre dont le jeton de réinitialisation (haché) correspond
 * et n'est pas expiré. Retourne la ligne ou false.
 */
function find_member_by_token(PDO $pdo, string $token)
{
    if ($token === '' || !ctype_xdigit($token)) {
        return false;
    }
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("SELECT id, prenom FROM membres WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$tokenHash]);
    return $stmt->fetch();
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = trim($_POST['token'] ?? '');

        if (!csrf_validate()) {
            $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
            $validToken = (bool) find_member_by_token($pdo, $token);
        } else {
            $member = find_member_by_token($pdo, $token);

            if (!$member) {
                $error = "Ce lien de réinitialisation est invalide ou a expiré. Veuillez en demander un nouveau.";
            } else {
                $validToken = true;
                $password = $_POST['password'] ?? '';
                $passwordConfirm = $_POST['password_confirm'] ?? '';

                if (empty($password) || empty($passwordConfirm)) {
                    $error = "Veuillez remplir les deux champs de mot de passe.";
                } elseif (strlen($password) < 6) {
                    $error = "Le mot de passe doit contenir au moins 6 caractères.";
                } elseif ($password !== $passwordConfirm) {
                    $error = "Les deux mots de passe ne correspondent pas.";
                } else {
                    // Met à jour le mot de passe et invalide le jeton.
                    $hashed = password_hash($password, PASSWORD_BCRYPT);
                    $upd = $pdo->prepare("UPDATE membres SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
                    $upd->execute([$hashed, $member['id']]);

                    $validToken = false;
                    $success = "Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.";
                }
            }
        }
    } else {
        // GET : on valide le jeton fourni dans l'URL.
        $token = trim($_GET['token'] ?? '');
        $member = find_member_by_token($pdo, $token);
        if ($member) {
            $validToken = true;
        } else {
            $error = "Ce lien de réinitialisation est invalide ou a expiré. Veuillez en demander un nouveau.";
        }
    }
} catch (Exception $e) {
    error_log('Touba Lyon reset_password: ' . $e->getMessage());
    $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialiser le mot de passe - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="form-card" style="max-width: 450px;">
            <h1 class="form-title">Nouveau mot de passe</h1>

            <?php if ($validToken && empty($success)): ?>
                <p style="text-align: center; color: var(--text-muted); margin-bottom: 2rem;">
                    Choisissez un nouveau mot de passe pour votre compte.
                </p>
                <form action="reset_password.php" method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">

                    <div class="form-group">
                        <label for="password" class="form-label">Nouveau mot de passe <span style="color:var(--danger)">*</span></label>
                        <input type="password" id="password" name="password" class="form-input" placeholder="Au moins 6 caractères" required>
                    </div>

                    <div class="form-group">
                        <label for="password_confirm" class="form-label">Confirmer le mot de passe <span style="color:var(--danger)">*</span></label>
                        <input type="password" id="password_confirm" name="password_confirm" class="form-input" placeholder="Saisissez à nouveau le mot de passe" required>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1.5rem; font-size: 1rem; padding: 1rem;">Définir le mot de passe</button>
                </form>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-muted); margin-bottom: 2rem;">
                    <?php if (!empty($success)): ?>
                        Votre mot de passe a bien été mis à jour.
                    <?php else: ?>
                        Pour réinitialiser votre mot de passe, demandez un nouveau lien.
                    <?php endif; ?>
                </p>
                <div style="text-align: center;">
                    <?php if (!empty($success)): ?>
                        <a href="login.php" class="btn btn-primary" style="width: 100%; padding: 1rem;">Se connecter</a>
                    <?php else: ?>
                        <a href="forgot_password.php" class="btn btn-primary" style="width: 100%; padding: 1rem;">Demander un nouveau lien</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display: flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($success)): ?>
                    <h3 class="gold-text">Mot de passe réinitialisé</h3>
                <?php else: ?>
                    <h3 style="color: var(--danger);">Une erreur est survenue</h3>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p>
            </div>
            <div class="modal-footer">
                <?php if (!empty($success)): ?>
                    <a href="login.php" class="btn btn-primary btn-sm">Se connecter</a>
                <?php else: ?>
                    <button onclick="closeNotificationModal()" class="btn btn-primary btn-sm">OK</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <script>
        function closeNotificationModal() {
            const modal = document.getElementById('notification-modal');
            if (modal) {
                modal.classList.remove('active');
                setTimeout(() => { modal.style.display = 'none'; }, 300);
            }
        }
    </script>
</body>
</html>
