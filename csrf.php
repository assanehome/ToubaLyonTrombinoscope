<?php
/**
 * Touba Lyon 2026 - Protection CSRF (helper réutilisable)
 *
 * Usage :
 *   require_once __DIR__ . '/csrf.php';
 *   <form ...> <?php echo csrf_field(); ?> ... </form>
 *   if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate()) { ... refus ... }
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Renvoie le jeton CSRF de la session (le crée si absent). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Champ <input hidden> à insérer dans les formulaires. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Vérifie le jeton soumis (comparaison à temps constant). */
function csrf_validate(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && is_string($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}
?>
