<?php
/**
 * Touba Lyon 2026 - MODÈLE d'identifiants sensibles.
 *
 * Copiez ce fichier en "config.secret.php" puis renseignez vos vraies valeurs.
 * config.secret.php est exclu du dépôt (.gitignore) et ne doit JAMAIS être versionné.
 *
 * En production, vous pouvez aussi définir des variables d'environnement
 * (prioritaires) : DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET, SMTP_PASS.
 */
return [
    // --- Base de données ---
    'host'      => 'votre-hote-mysql',
    'db'        => 'nom_de_la_base',
    'user'      => 'utilisateur_db',
    'pass'      => 'MOT_DE_PASSE_DB',
    'charset'   => 'utf8mb4',

    // --- SMTP (envoi d'e-mails) ---
    'smtp_pass' => 'MOT_DE_PASSE_SMTP',
];
