<?php
/**
 * Touba Lyon 2026 - Member Login
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';
require_once __DIR__ . '/kourel_access.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to index if already logged in as a member
if (isset($_SESSION['player_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';
$info = '';

if (isset($_GET['pending_validation']) && $_GET['pending_validation'] == 1) {
    $info = "Vos modifications ont été enregistrées avec succès. Votre compte est de nouveau en attente de validation par un administrateur.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Tous les champs sont obligatoires.";
    } else {
        try {
            // Find member in membres table
            $stmt = $pdo->prepare("SELECT * FROM membres WHERE email = ?");
            $stmt->execute([$email]);
            $member = $stmt->fetch();

            if ($member && password_verify($password, $member['password'])) {
                if ($member['status'] === 'approved') {
                    $_SESSION['player_id'] = $member['id'];
                    $_SESSION['player_name'] = $member['prenom'] . ' ' . $member['nom'];
                    $_SESSION['player_score'] = $member['score'];
                    $_SESSION['is_integrateur'] = !empty($member['is_integrateur']);
                    $_SESSION['is_gestion_kourel'] = member_is_kourel_manager($pdo, (int) $member['id']);
                    try { $_SESSION['is_gestion_commission'] = ((int) $pdo->query("SELECT COUNT(*) FROM commission_gestionnaires WHERE membre_id = " . (int) $member['id'])->fetchColumn() > 0); } catch (Exception $e) { $_SESSION['is_gestion_commission'] = false; }
                    try { $_SESSION['is_suivi_integration'] = ((int) $pdo->query("SELECT COUNT(*) FROM commission_gestionnaires cg JOIN commissions c ON c.id = cg.commission_id WHERE cg.membre_id = " . (int) $member['id'] . " AND LOWER(c.nom) LIKE '%gration%'")->fetchColumn() > 0); } catch (Exception $e) { $_SESSION['is_suivi_integration'] = false; }

                    $__dest = 'index.php';
                    if (!empty($_SESSION['after_login'])) { $__dest = $_SESSION['after_login']; unset($_SESSION['after_login']); }
                    header('Location: ' . $__dest);
                    exit;
                } elseif ($member['status'] === 'pending') {
                    // Compte en attente : connexion autorisée mais accès limité au profil
                    $_SESSION['player_id'] = $member['id'];
                    $_SESSION['player_name'] = $member['prenom'] . ' ' . $member['nom'];
                    $_SESSION['player_score'] = $member['score'];
                    $_SESSION['is_integrateur'] = !empty($member['is_integrateur']);
                    $_SESSION['is_gestion_kourel'] = member_is_kourel_manager($pdo, (int) $member['id']);
                    try { $_SESSION['is_gestion_commission'] = ((int) $pdo->query("SELECT COUNT(*) FROM commission_gestionnaires WHERE membre_id = " . (int) $member['id'])->fetchColumn() > 0); } catch (Exception $e) { $_SESSION['is_gestion_commission'] = false; }
                    try { $_SESSION['is_suivi_integration'] = ((int) $pdo->query("SELECT COUNT(*) FROM commission_gestionnaires cg JOIN commissions c ON c.id = cg.commission_id WHERE cg.membre_id = " . (int) $member['id'] . " AND LOWER(c.nom) LIKE '%gration%'")->fetchColumn() > 0); } catch (Exception $e) { $_SESSION['is_suivi_integration'] = false; }
                    header('Location: profile.php');
                    exit;
                } else {
                    $error = "Votre inscription a été refusée.";
                }
            } else {
                $error = "Identifiants incorrects ou compte inexistant.";
            }
        } catch (Exception $e) {
            error_log('Touba Lyon login: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion Membre - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="form-card" style="max-width: 450px;">
            <h1 class="form-title">Connexion Membre</h1>
            <p style="text-align: center; color: var(--text-muted); margin-bottom: 2rem;">
                Connectez-vous pour accéder au Dahira - Mubawwa-A-Sidqin et jouer à Ki Kan La.
            </p>

            <form action="login.php" method="POST">
                <div class="form-group">
                    <label for="email" class="form-label">Adresse Email <span style="color:var(--danger)">*</span></label>
                    <input type="email" id="email" name="email" class="form-input" placeholder="Ex: modou@example.com" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Mot de passe <span style="color:var(--danger)">*</span></label>
                    <input type="password" id="password" name="password" class="form-input" placeholder="Votre mot de passe" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1.5rem; font-size: 1rem; padding: 1rem;">Se connecter</button>
            </form>

            <p style="text-align: center; margin-top: 1.25rem; font-size: 0.9rem;">
                <a href="forgot_password.php" style="color: var(--text-muted); text-decoration: none;">Mot de passe oublié ?</a>
            </p>

        </div>
    </main>

    <!-- Modern Notification Modal -->
    <?php if (!empty($info) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display: flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($info)): ?>
                    <h3 class="gold-text">Mise à jour réussie</h3>
                <?php else: ?>
                    <h3 style="color: var(--danger);">Une erreur est survenue</h3>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <p>
                    <?php 
                        if (!empty($info)) {
                            echo htmlspecialchars($info);
                        } else {
                            echo htmlspecialchars($error);
                        }
                    ?>
                </p>
            </div>
            <div class="modal-footer">
                <?php if (!empty($info)): ?>
                    <button onclick="closeNotificationModal()" class="btn btn-primary btn-sm">OK</button>
                <?php else: ?>
                    <button onclick="closeNotificationModal()" class="btn btn-primary btn-sm">Réessayer</button>
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
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 300);
            }
        }
    </script>
</body>
</html>
