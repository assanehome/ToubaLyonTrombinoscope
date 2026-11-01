<?php
/**
 * Touba Lyon 2026 - Configuration des sessions (connexion persistante).
 *
 * À inclure juste AVANT chaque session_start(). La connexion reste active
 * (cookie valable 1 an, renouvelé à chaque visite) jusqu'à une déconnexion
 * demandée par l'utilisateur — notamment sur mobile, où le navigateur est
 * souvent fermé entre deux visites.
 *
 * Les sessions sont stockées dans le dossier privé « sessions/ » du site,
 * afin de ne pas être purgées au bout de 24 min par le ménage du serveur.
 */
if (session_status() === PHP_SESSION_NONE) {
    $__sessDuree = 60 * 60 * 24 * 365; // 1 an

    // Dossier de stockage privé (repli sur le dossier par défaut s'il n'est pas utilisable)
    $__sessDir = __DIR__ . '/sessions';
    if (!is_dir($__sessDir)) {
        @mkdir($__sessDir, 0700, true);
    }
    if (is_dir($__sessDir) && is_writable($__sessDir)) {
        $__sessHt = $__sessDir . '/.htaccess';
        if (!file_exists($__sessHt)) {
            @file_put_contents($__sessHt, "Order Deny,Allow\nDeny from all\n");
        }
        ini_set('session.save_path', $__sessDir);
    }

    // Cookie propre à Dahira : d'autres applications du même domaine (ex. Daara) utilisent
    // « PHPSESSID » à la racine et écrasaient la session de ce site à chaque visite.
    session_name('DAHIRASESSID');
    $__sessChemin = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($__sessChemin === '') { $__sessChemin = '/'; }

    ini_set('session.gc_maxlifetime', (string) $__sessDuree);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => $__sessDuree,
        'path'     => $__sessChemin,
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
