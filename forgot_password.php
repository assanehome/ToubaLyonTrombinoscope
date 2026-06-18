<?php
/**
 * Touba Lyon 2026 - Mot de passe oublié (demande de réinitialisation)
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/send_mail.php';

// Déjà connecté ? Inutile de demander une réinitialisation.
if (isset($_SESSION['player_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$info = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Veuillez saisir une adresse email valide.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, prenom, nom, email FROM membres WHERE email = ?");
                $stmt->execute([$email]);
                $member = $stmt->fetch();

                if ($member) {
                    // Génère un jeton aléatoire ; on ne stocke que son hash en base.
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $expires = date('Y-m-d H:i:s', time() + 3600); // valable 1 heure

                    $upd = $pdo->prepare("UPDATE membres SET reset_token = ?, reset_expires = ? WHERE id = ?");
                    $upd->execute([$tokenHash, $expires, $member['id']]);

                    // Construit le lien de réinitialisation.
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'toubalyon.com';
                    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');
                    $resetLink = "{$scheme}://{$host}{$dir}/reset_password.php?token={$token}";

                    $prenom = $member['prenom'];
                    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES);
                    $html = '<div style="font-family: Arial, sans-serif; color: #1a1a1a; max-width: 480px; margin: auto;">'
                        . '<h2 style="color: #1b4332;">Réinitialisation de votre mot de passe</h2>'
                        . '<p>Bonjour ' . htmlspecialchars($prenom) . ',</p>'
                        . '<p>Vous avez demandé à réinitialiser le mot de passe de votre compte Trombinoscope Touba Lyon. '
                        . 'Cliquez sur le bouton ci-dessous pour en choisir un nouveau :</p>'
                        . '<p style="text-align:center; margin: 28px 0;">'
                        . '<a href="' . $safeLink . '" style="background:#1b4332; color:#fff; text-decoration:none; padding:12px 28px; border-radius:8px; font-weight:bold; display:inline-block;">Réinitialiser mon mot de passe</a>'
                        . '</p>'
                        . '<p style="font-size: 0.9em; color:#555;">Ou copiez ce lien dans votre navigateur :<br>'
                        . '<a href="' . $safeLink . '">' . $safeLink . '</a></p>'
                        . '<p style="font-size: 0.9em; color:#555;">Ce lien est valable <strong>1 heure</strong>. '
                        . 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez simplement cet e-mail : votre mot de passe restera inchangé.</p>'
                        . '<hr style="border:none; border-top:1px solid #eee; margin: 24px 0;">'
                        . '<p style="font-size: 0.8em; color:#999;">Touba Lyon — Trombinoscope 2026</p>'
                        . '</div>';

                    $sent = send_smtp_mail(
                        $member['email'],
                        $member['prenom'] . ' ' . $member['nom'],
                        'Réinitialisation de votre mot de passe - Touba Lyon',
                        $html
                    );

                    if (!$sent) {
                        error_log('Touba Lyon forgot_password: envoi SMTP échoué pour ' . $member['email']);
                    }
                }

                // Message générique dans tous les cas (évite de révéler si l'email existe).
                $info = "Si un compte est associé à cette adresse, un e-mail contenant un lien de réinitialisation vient d'être envoyé. Pensez à vérifier vos courriers indésirables.";
            } catch (Exception $e) {
                error_log('Touba Lyon forgot_password: ' . $e->getMessage());
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
    <title>Mot de passe oublié - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="form-card" style="max-width: 450px;">
            <h1 class="form-title">Mot de passe oublié</h1>
            <p style="text-align: center; color: var(--text-muted); margin-bottom: 2rem;">
                Saisissez l'adresse email de votre compte. Vous recevrez un lien pour définir un nouveau mot de passe.
            </p>

            <form action="forgot_password.php" method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="email" class="form-label">Adresse Email <span style="color:var(--danger)">*</span></label>
                    <input type="email" id="email" name="email" class="form-input" placeholder="Ex: modou@example.com" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1.5rem; font-size: 1rem; padding: 1rem;">Envoyer le lien</button>
            </form>

            <p style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem;">
                <a href="login.php" class="gold-text" style="font-weight: 600; text-decoration: none;">Retour à la connexion</a>
            </p>
        </div>
    </main>

    <?php if (!empty($info) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display: flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($info)): ?>
                    <h3 class="gold-text">E-mail envoyé</h3>
                <?php else: ?>
                    <h3 style="color: var(--danger);">Une erreur est survenue</h3>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <p>
                    <?php echo htmlspecialchars(!empty($info) ? $info : $error); ?>
                </p>
            </div>
            <div class="modal-footer">
                <?php if (!empty($info)): ?>
                    <a href="login.php" class="btn btn-primary btn-sm">Retour à la connexion</a>
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
                setTimeout(() => { modal.style.display = 'none'; }, 300);
            }
        }
    </script>
</body>
</html>
