<?php
/**
 * Touba Lyon 2026 - Database Configuration
 *
 * Les identifiants ne sont plus codés en dur ici. Ils sont chargés :
 *   1. depuis les variables d'environnement (prioritaire, recommandé en production) ;
 *   2. sinon depuis config.secret.php (fichier local non versionné).
 */

mb_internal_encoding("UTF-8");
mb_http_output("UTF-8");

// En production, ne jamais afficher les erreurs PHP brutes à l'utilisateur.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Chargement des identifiants (env > fichier secret local)
$secretsFile = __DIR__ . '/config.secret.php';
$secrets = is_file($secretsFile) ? require $secretsFile : [];

$host    = getenv('DB_HOST')    ?: ($secrets['host']    ?? '');
$db      = getenv('DB_NAME')    ?: ($secrets['db']      ?? '');
$user    = getenv('DB_USER')    ?: ($secrets['user']    ?? '');
$pass    = getenv('DB_PASS')    ?: ($secrets['pass']    ?? '');
$charset = getenv('DB_CHARSET') ?: ($secrets['charset'] ?? 'utf8mb4');

if ($host === '' || $db === '' || $user === '') {
    error_log('Touba Lyon: identifiants de base de données manquants (config.secret.php absent ou incomplet).');
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Ne jamais exposer le détail de l'erreur à l'utilisateur : on journalise côté serveur.
    error_log('Touba Lyon: échec de connexion à la base de données : ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
?>
