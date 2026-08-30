<?php
/**
 * Touba Lyon 2026 - Changement de mot de passe Administrateur
 * (forcé à la première connexion ; accessible aussi volontairement)
 * Un seul champ : pas de confirmation.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';

session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

$error = '';
$success = '';
$mustChange = !empty($_SESSION['admin_must_change']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $password = $_POST['password'] ?? '';
        if ($password === '') {
            $error = "Veuillez saisir un nouveau mot de passe.";
        } elseif (strlen($password) < 6) {
            $error = "Le mot de passe doit contenir au moins 6 caractères.";
        } else {
            try {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admins SET password = ?, must_change_password = 0 WHERE id = ?");
                $stmt->execute([$hashed, $_SESSION['admin_id']]);
                $_SESSION['admin_must_change'] = false;
                $success = "Votre mot de passe a été mis à jour.";
            } catch (Exception $e) {
                error_log('Touba Lyon admin_password: ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau mot de passe - Administrateur</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="form-card" style="max-width: 450px;">
            <h1 class="form-title">Nouveau mot de passe</h1>
            <?php if ($mustChange && empty($success)): ?>
                <p style="text-align:center; color:var(--gold); margin-bottom:1.5rem;">
                    🔒 Première connexion : veuillez choisir votre propre mot de passe avant de continuer.
                </p>
            <?php endif; ?>
            <?php if (empty($success)): ?>
            <form action="admin_password.php" method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="password" class="form-label">Nouveau mot de passe</label>
                    <input type="password" id="password" name="password" class="form-input" placeholder="Au moins 6 caractères" minlength="6" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; margin-top:1.5rem; padding:1rem;">Définir le mot de passe</button>
            </form>
            <?php else: ?>
                <div style="text-align:center;">
                    <a href="admin_dashboard.php" class="btn btn-primary" style="width:100%; padding:1rem;">Continuer vers le tableau de bord</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($success)): ?><h3 class="gold-text">Mot de passe mis à jour</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?>
            </div>
            <div class="modal-body"><p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p></div>
            <div class="modal-footer">
                <?php if (!empty($success)): ?>
                    <a href="admin_dashboard.php" class="btn btn-primary btn-sm">Continuer</a>
                <?php else: ?>
                    <button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
</body>
</html>
