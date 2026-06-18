<?php
/**
 * Touba Lyon 2026 - Admin Login / Setup
 */
require_once __DIR__ . '/db_setup.php';

session_start();

// Redirect to dashboard if already logged in
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin_dashboard.php');
    exit;
}

$error = '';
$success = '';

// Check if any admin exists in the database
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM admins");
    $adminCount = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log('Touba Lyon admin_login: ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

$setupMode = ($adminCount == 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($setupMode) {
        // Create first admin account
        $username = trim($_POST['username'] ?? '');
        $admin_email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if (empty($username) || empty($admin_email) || empty($password) || empty($password_confirm)) {
            $error = "Tous les champs sont obligatoires.";
        } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $error = "L'adresse email n'est pas valide.";
        } elseif (strlen($password) < 6) {
            $error = "Le mot de passe doit contenir au moins 6 caractères.";
        } elseif ($password !== $password_confirm) {
            $error = "Les mots de passe ne correspondent pas.";
        } else {
            try {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO admins (username, email, password) VALUES (?, ?, ?)");
                $stmt->execute([$username, $admin_email, $hashedPassword]);
                $success = "Compte administrateur créé avec succès ! Vous pouvez maintenant vous connecter.";
                $setupMode = false; // Turn off setup mode so standard login is displayed
            } catch (Exception $e) {
                error_log('Touba Lyon admin_login (setup): ' . $e->getMessage());
                $error = "Erreur lors de la création du compte administrateur. Veuillez réessayer.";
            }
        }
    } else {
        // Standard admin authentication
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = "Veuillez remplir tous les champs.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
                $stmt->execute([$username]);
                $admin = $stmt->fetch();

                if ($admin && password_verify($password, $admin['password'])) {
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    header('Location: admin_dashboard.php');
                    exit;
                } else {
                    $error = "Identifiant ou mot de passe incorrect.";
                }
            } catch (Exception $e) {
                error_log('Touba Lyon admin_login (auth): ' . $e->getMessage());
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
    <title><?php echo $setupMode ? 'Configuration Administrateur' : 'Connexion Admin'; ?> - Trombinoscope</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="form-card">
            <?php if ($setupMode): ?>
                <h1 class="form-title">Configuration Initiale</h1>
                <p style="text-align: center; color: var(--text-muted); margin-bottom: 1.5rem;">
                    Aucun compte administrateur n'a été configuré. Créez le premier administrateur pour continuer.
                </p>
            <?php else: ?>
                <h1 class="form-title">Espace Administration</h1>
            <?php endif; ?>

            <form action="admin_login.php" method="POST">
                <div class="form-group">
                    <label for="username" class="form-label">Nom d'utilisateur</label>
                    <input type="text" id="username" name="username" class="form-input" placeholder="Ex: admin" required autofocus>
                </div>

                <?php if ($setupMode): ?>
                    <div class="form-group">
                        <label for="email" class="form-label">Adresse email <span style="color:var(--danger)">*</span></label>
                        <input type="email" id="email" name="email" class="form-input" placeholder="Pour la récupération du mot de passe" required>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="password" class="form-label">Mot de passe</label>
                    <input type="password" id="password" name="password" class="form-input" placeholder="••••••••" required>
                </div>

                <?php if ($setupMode): ?>
                    <div class="form-group">
                        <label for="password_confirm" class="form-label">Confirmer le mot de passe</label>
                        <input type="password" id="password_confirm" name="password_confirm" class="form-input" placeholder="••••••••" required>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">
                    <?php echo $setupMode ? "Créer le compte administrateur" : "Se connecter"; ?>
                </button>
            </form>

            <?php if (!$setupMode): ?>
                <p style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem;">
                    <a href="admin_forgot_password.php" style="color: var(--text-muted); text-decoration: none;">Mot de passe administrateur oublié ?</a>
                </p>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modern Notification Modal -->
    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display: flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($success)): ?>
                    <h3 class="gold-text">Succès</h3>
                <?php else: ?>
                    <h3 style="color: var(--danger);">Erreur</h3>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <p>
                    <?php 
                        if (!empty($success)) {
                            echo htmlspecialchars($success);
                        } else {
                            echo htmlspecialchars($error);
                        }
                    ?>
                </p>
            </div>
            <div class="modal-footer">
                <button onclick="closeNotificationModal()" class="btn btn-primary btn-sm">OK</button>
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
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 300);
            }
        }
    </script>
</body>
</html>
