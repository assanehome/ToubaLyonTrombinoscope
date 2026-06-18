<?php
/**
 * Touba Lyon 2026 - Configuration SMTP (envoi d'e-mails)
 *
 * Les paramètres non sensibles sont définis ici. Le mot de passe SMTP, lui,
 * n'est JAMAIS codé en dur : il est lu depuis la variable d'environnement
 * SMTP_PASS (prioritaire) ou depuis config.secret.php (clé 'smtp_pass').
 *
 * À inclure dans les scripts qui envoient des e-mails (ex: formulaire de contact) :
 *   require_once __DIR__ . '/mail_config.php';
 */

// Récupération du mot de passe SMTP depuis l'environnement ou le fichier secret.
$__smtpSecretsFile = __DIR__ . '/config.secret.php';
$__smtpSecrets = is_file($__smtpSecretsFile) ? require $__smtpSecretsFile : [];
$__smtpPass = getenv('SMTP_PASS') ?: ($__smtpSecrets['smtp_pass'] ?? '');
unset($__smtpSecrets, $__smtpSecretsFile);

// --- Paramètres du serveur SMTP ---
define('SMTP_HOST', 'smtp.strato.de');     // Serveur SMTP (ex: smtp.strato.de pour Strato)
define('SMTP_PORT', 465);                  // Port SMTP (465 pour SSL ou 587 pour TLS/STARTTLS)
define('SMTP_SECURE', 'ssl');              // Protocole de sécurité ('ssl' ou 'tls')
define('SMTP_AUTH', true);                 // Authentification requise (true/false)
define('SMTP_USER', 'noreply@toubalyon.com'); // Nom d'utilisateur SMTP (votre e-mail)
define('SMTP_PASS', $__smtpPass);          // Mot de passe (chargé depuis config.secret.php / env)

// --- Options par défaut de l'e-mail ---
define('SMTP_FROM_EMAIL', 'noreply@toubalyon.com'); // Adresse de l'expéditeur (doit correspondre à SMTP_USER)
define('SMTP_FROM_NAME', 'Touba Lyon');
define('SMTP_TO_EMAIL', 'noreply@toubalyon.com');   // Adresse de destination des messages de contact

unset($__smtpPass);
?>
