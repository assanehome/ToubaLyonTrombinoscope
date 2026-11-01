<?php
/**
 * Touba Lyon 2026 - Garde d'accès administrateur
 *
 * À inclure EN TOUT PREMIER dans les scripts réservés aux administrateurs
 * (maintenance, génération de données). Refuse l'accès si l'utilisateur
 * n'est pas connecté en tant qu'admin.
 */
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    // Un membre est peut-être connecté : son espace lui reste ouvert.
    $__retourMembre = !empty($_SESSION['player_id'])
        ? '<a href="index.php" class="btn btn-secondary">↩ Mon espace membre</a>'
        : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accès réservé — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .garde-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
        .garde-carte { max-width: 460px; width: 100%; text-align: center; padding: 2.25rem 1.75rem; border-radius: 20px; }
        .garde-icone { font-size: 2.6rem; line-height: 1; margin-bottom: 0.9rem; }
        .garde-carte h1 { color: #fff; font-size: 1.35rem; margin: 0 0 0.6rem; }
        .garde-carte p { color: var(--text-muted); font-size: 0.93rem; line-height: 1.6; margin: 0 0 1.5rem; }
        .garde-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; justify-content: center; }
        @media (max-width: 480px) { .garde-actions .btn { width: 100%; justify-content: center; } }
    </style>
</head>
<body>
    <div class="garde-wrap">
        <div class="glass-card garde-carte">
            <div class="garde-icone">🔒</div>
            <h1 class="gold-text">Accès réservé</h1>
            <p>
                Cette page est réservée aux <strong style="color:#fff;">administrateurs connectés</strong>
                du Dahira. Connectez-vous à l'espace d'administration pour y accéder.
            </p>
            <div class="garde-actions">
                <a href="admin_login.php" class="btn btn-primary">🔐 Connexion administrateur</a>
                $__retourMembre
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    exit;
}

// Première connexion : forcer le changement de mot de passe avant tout accès.
if (!empty($_SESSION['admin_must_change']) && basename($_SERVER['PHP_SELF'] ?? '') !== 'admin_password.php') {
    header('Location: admin_password.php');
    exit;
}
?>
