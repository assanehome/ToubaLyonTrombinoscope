<?php
/**
 * Touba Lyon 2026 - Database and Environment Setup
 */
require_once __DIR__ . '/db_config.php';

try {
    // 1. Create admins table if it doesn't exist
    $sqlAdmins = "CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        email VARCHAR(150) DEFAULT NULL,
        password VARCHAR(255) NOT NULL,
        reset_token VARCHAR(64) DEFAULT NULL,
        reset_expires DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sqlAdmins);

    // Migration: add email & reset columns to admins if they do not exist
    try {
        $pdo->query("SELECT reset_token FROM admins LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE admins
            ADD COLUMN email VARCHAR(150) DEFAULT NULL,
            ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL,
            ADD COLUMN reset_expires DATETIME DEFAULT NULL");
    }

    // NOTE: aucun compte administrateur par défaut n'est créé ici (sécurité).
    // Le premier administrateur est créé manuellement via le mode "Configuration
    // initiale" de admin_login.php, qui exige un mot de passe choisi par l'utilisateur.

    // 2. Create membres table if it doesn't exist (with password, score, civilite)
    $sqlMembres = "CREATE TABLE IF NOT EXISTS membres (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) NOT NULL,
        civilite VARCHAR(20) NOT NULL DEFAULT 'Goor Yalla',
        email VARCHAR(150) NOT NULL UNIQUE,
        photo_path VARCHAR(255) NOT NULL DEFAULT '',
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        password VARCHAR(255) NOT NULL DEFAULT '',
        score INT DEFAULT 0,
        reset_token VARCHAR(64) DEFAULT NULL,
        reset_expires DATETIME DEFAULT NULL,
        type_adhesion VARCHAR(30) DEFAULT NULL,
        genre VARCHAR(10) DEFAULT NULL,
        test_kourel VARCHAR(5) DEFAULT NULL,
        adresse VARCHAR(255) DEFAULT NULL,
        code_postal VARCHAR(10) DEFAULT NULL,
        commune VARCHAR(100) DEFAULT NULL,
        telephone VARCHAR(30) DEFAULT NULL,
        statut VARCHAR(30) DEFAULT NULL,
        secteur_activite VARCHAR(255) DEFAULT NULL,
        profession VARCHAR(150) DEFAULT NULL,
        commentaires TEXT DEFAULT NULL,
        annee_integration VARCHAR(10) DEFAULT NULL,
        charte_acceptee TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sqlMembres);

    // Migration: Add civilite column if it does not exist
    try {
        $pdo->query("SELECT civilite FROM membres LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres ADD COLUMN civilite VARCHAR(20) NOT NULL DEFAULT 'Goor Yalla'");
    }

    // Migration: Add password reset columns if they do not exist
    try {
        $pdo->query("SELECT reset_token FROM membres LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL, ADD COLUMN reset_expires DATETIME DEFAULT NULL");
    }

    // Migration: Add Dahira membership (adhésion) columns if they do not exist
    try {
        $pdo->query("SELECT type_adhesion FROM membres LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec(
            "ALTER TABLE membres
                ADD COLUMN type_adhesion VARCHAR(30) DEFAULT NULL,
                ADD COLUMN genre VARCHAR(10) DEFAULT NULL,
                ADD COLUMN test_kourel VARCHAR(5) DEFAULT NULL,
                ADD COLUMN adresse VARCHAR(255) DEFAULT NULL,
                ADD COLUMN code_postal VARCHAR(10) DEFAULT NULL,
                ADD COLUMN commune VARCHAR(100) DEFAULT NULL,
                ADD COLUMN telephone VARCHAR(30) DEFAULT NULL,
                ADD COLUMN statut VARCHAR(30) DEFAULT NULL,
                ADD COLUMN secteur_activite VARCHAR(255) DEFAULT NULL,
                ADD COLUMN profession VARCHAR(150) DEFAULT NULL,
                ADD COLUMN commentaires TEXT DEFAULT NULL,
                ADD COLUMN annee_integration VARCHAR(10) DEFAULT NULL,
                ADD COLUMN charte_acceptee TINYINT(1) DEFAULT 0"
        );
    }

    // Migration: photo_path doit accepter une valeur par défaut (adhésions sans photo)
    try {
        $pdo->exec("ALTER TABLE membres MODIFY COLUMN photo_path VARCHAR(255) NOT NULL DEFAULT ''");
    } catch (Exception $e) {
        // Non bloquant : si la modification échoue, on continue.
        error_log('Touba Lyon db_setup: photo_path default migration: ' . $e->getMessage());
    }

    // 3. Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/uploads';
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception("Unable to create uploads directory.");
        }
    }

    // 4. Create .htaccess inside uploads/ to restrict execution of scripts for security
    $htaccessFile = $uploadDir . '/.htaccess';
    if (!file_exists($htaccessFile)) {
        $htaccessContent = "# Restrict direct execution of scripts\n"
            . "<FilesMatch \"\\.(php|php3|php4|php5|php7|php8|phtml|pl|py|jsp|asp|sh|cgi)$\">\n"
            . "    Order Deny,Allow\n"
            . "    Deny from all\n"
            . "</FilesMatch>\n"
            . "Options -Indexes\n";
        file_put_contents($htaccessFile, $htaccessContent);
    }

} catch (Exception $e) {
    error_log('Touba Lyon: erreur de configuration de la base : ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue lors de l'initialisation. Veuillez réessayer plus tard.");
}
?>
