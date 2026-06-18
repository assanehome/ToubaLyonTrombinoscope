<?php
/**
 * Touba Lyon 2026 - Database Setup & Update Page
 * ⚠️ Réservé aux administrateurs connectés (voir admin_guard.php).
 */
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/db_config.php';

$steps = [];
$success = true;
$errorMsg = '';

try {
    // Step 1: admins table
    $sqlAdmins = "CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sqlAdmins);
    $steps[] = ['name' => "Création/Vérification de la table 'admins'", 'status' => 'success', 'desc' => 'Table vérifiée ou créée avec succès.'];

    // Step 2: vérification du compte administrateur (aucun compte par défaut n'est créé)
    $checkAdmins = $pdo->query("SELECT COUNT(*) FROM admins");
    if ($checkAdmins->fetchColumn() == 0) {
        $steps[] = ['name' => "Compte administrateur", 'status' => 'info', 'desc' => "Aucun administrateur n'existe encore. Créez-le via la page de connexion admin (mode Configuration initiale) avec un mot de passe fort."];
    } else {
        $steps[] = ['name' => "Vérification du compte administrateur", 'status' => 'info', 'desc' => 'Au moins un administrateur existe déjà en base de données.'];
    }

    // Step 3: membres table
    $sqlMembres = "CREATE TABLE IF NOT EXISTS membres (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) NOT NULL,
        civilite VARCHAR(20) NOT NULL DEFAULT 'Goor Yalla',
        email VARCHAR(150) NOT NULL UNIQUE,
        photo_path VARCHAR(255) NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        password VARCHAR(255) NOT NULL,
        score INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sqlMembres);
    $steps[] = ['name' => "Création/Vérification de la table 'membres'", 'status' => 'success', 'desc' => 'Table vérifiée ou créée avec succès.'];

    // Step 4: Migration - password column in membres
    try {
        $pdo->query("SELECT password FROM membres LIMIT 1");
        $steps[] = ['name' => "Vérification de la colonne 'password' dans 'membres'", 'status' => 'info', 'desc' => "La colonne existe déjà."];
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres ADD COLUMN password VARCHAR(255) NOT NULL DEFAULT ''");
        $steps[] = ['name' => "Migration de la colonne 'password' dans 'membres'", 'status' => 'success', 'desc' => "Colonne 'password' ajoutée avec succès."];
    }

    // Step 5: Migration - score column in membres
    try {
        $pdo->query("SELECT score FROM membres LIMIT 1");
        $steps[] = ['name' => "Vérification de la colonne 'score' dans 'membres'", 'status' => 'info', 'desc' => "La colonne existe déjà."];
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres ADD COLUMN score INT DEFAULT 0");
        $steps[] = ['name' => "Migration de la colonne 'score' dans 'membres'", 'status' => 'success', 'desc' => "Colonne 'score' ajoutée avec succès."];
    }

    // Step 5b: Migration - civilite column in membres
    try {
        $pdo->query("SELECT civilite FROM membres LIMIT 1");
        $steps[] = ['name' => "Vérification de la colonne 'civilite' dans 'membres'", 'status' => 'info', 'desc' => "La colonne existe déjà."];
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres ADD COLUMN civilite VARCHAR(20) NOT NULL DEFAULT 'Goor Yalla'");
        $steps[] = ['name' => "Migration de la colonne 'civilite' dans 'membres'", 'status' => 'success', 'desc' => "Colonne 'civilite' ajoutée avec succès."];
    }
    // Step 5b: comptage des membres sans mot de passe (aucun mot de passe par défaut n'est appliqué)
    try {
        $stmtNoPass = $pdo->query("SELECT COUNT(*) FROM membres WHERE password = '' OR password IS NULL");
        $noPassCount = (int)$stmtNoPass->fetchColumn();
        if ($noPassCount > 0) {
            $steps[] = ['name' => "Mots de passe des membres", 'status' => 'info', 'desc' => "{$noPassCount} membre(s) sans mot de passe défini. Aucun mot de passe par défaut n'est appliqué (sécurité) : ces membres devront en définir un via la procédure de réinitialisation."];
        } else {
            $steps[] = ['name' => "Mots de passe des membres", 'status' => 'info', 'desc' => "Tous les membres ont un mot de passe défini."];
        }
    } catch (Exception $e) {
        error_log('Touba Lyon db_update: ' . $e->getMessage());
        $steps[] = ['name' => "Mots de passe des membres", 'status' => 'error', 'desc' => "Erreur lors de la vérification des mots de passe."];
    }

    // Step 6: Drop joueurs table
    try {
        $pdo->exec("DROP TABLE IF EXISTS joueurs;");
        $steps[] = ['name' => "Suppression de la table obsolète 'joueurs'", 'status' => 'success', 'desc' => "Table 'joueurs' supprimée (fusionnée avec 'membres')."];
    } catch (Exception $e) {
        $steps[] = ['name' => "Nettoyage de la table 'joueurs'", 'status' => 'error', 'desc' => "Impossible de supprimer la table 'joueurs' : " . $e->getMessage()];
    }

    // Step 7: Create uploads dir & htaccess
    $uploadDir = __DIR__ . '/uploads';
    if (!file_exists($uploadDir)) {
        if (mkdir($uploadDir, 0755, true)) {
            $steps[] = ['name' => "Création du dossier 'uploads/'", 'status' => 'success', 'desc' => "Dossier créé avec succès."];
        } else {
            $steps[] = ['name' => "Création du dossier 'uploads/'", 'status' => 'error', 'desc' => "Impossible de créer le dossier."];
        }
    } else {
        $steps[] = ['name' => "Vérification du dossier 'uploads/'", 'status' => 'info', 'desc' => "Dossier déjà existant."];
    }

    $htaccessFile = $uploadDir . '/.htaccess';
    if (!file_exists($htaccessFile)) {
        $htaccessContent = "# Restrict direct execution of scripts\n"
            . "<FilesMatch \"\\.(php|php3|php4|php5|php7|php8|phtml|pl|py|jsp|asp|sh|cgi)$\">\n"
            . "    Order Deny,Allow\n"
            . "    Deny from all\n"
            . "</FilesMatch>\n"
            . "Options -Indexes\n";
        if (file_put_contents($htaccessFile, $htaccessContent)) {
            $steps[] = ['name' => "Création du fichier sécurisé '.htaccess'", 'status' => 'success', 'desc' => "Fichier de restriction d'exécution écrit avec succès."];
        } else {
            $steps[] = ['name' => "Création du fichier sécurisé '.htaccess'", 'status' => 'error', 'desc' => "Impossible de créer le fichier .htaccess."];
        }
    } else {
        $steps[] = ['name' => "Vérification du fichier sécurisé '.htaccess'", 'status' => 'info', 'desc' => "Fichier déjà existant."];
    }

} catch (Exception $e) {
    $success = false;
    $errorMsg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mise à jour de la Base de Données - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .step-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
        }
        .step-info {
            text-align: left;
        }
        .step-name {
            font-weight: 600;
            color: var(--white);
            font-size: 1rem;
        }
        .step-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        .status-badge {
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            text-transform: uppercase;
        }
        .status-success {
            background: rgba(27, 67, 50, 0.3);
            color: var(--accent);
            border: 1px solid var(--accent);
        }
        .status-info {
            background: rgba(255, 255, 255, 0.08);
            color: var(--white);
            border: 1px solid var(--glass-border);
        }
        .status-error {
            background: rgba(220, 53, 69, 0.15);
            color: var(--danger);
            border: 1px solid var(--danger);
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="form-card" style="max-width: 700px;">
            <h1 class="form-title">Mise à jour de la Base de Données</h1>
            <p style="text-align: center; color: var(--text-muted); margin-bottom: 2rem;">
                Rapport de vérification et d'installation de l'environnement de base de données.
            </p>

            <?php if (!$success): ?>
                <div class="alert alert-danger" style="margin-bottom: 2rem;">
                    <strong>Erreur fatale :</strong> <?php echo htmlspecialchars($errorMsg); ?>
                </div>
            <?php else: ?>
                <div class="alert alert-success" style="margin-bottom: 2rem; display: flex; align-items: center; gap: 1rem;">
                    <span>✔️</span>
                    <div style="text-align: left;">
                        <strong>Mise à jour réussie !</strong><br>
                        Toutes les vérifications et migrations de schémas ont été complétées avec succès.
                    </div>
                </div>
            <?php endif; ?>

            <div style="margin-top: 1.5rem;">
                <?php foreach ($steps as $step): ?>
                    <div class="step-item">
                        <div class="step-info">
                            <div class="step-name"><?php echo htmlspecialchars($step['name']); ?></div>
                            <div class="step-desc"><?php echo htmlspecialchars($step['desc']); ?></div>
                        </div>
                        <div>
                            <?php if ($step['status'] === 'success'): ?>
                                <span class="status-badge status-success">Réussi</span>
                            <?php elseif ($step['status'] === 'info'): ?>
                                <span class="status-badge status-info">Info</span>
                            <?php else: ?>
                                <span class="status-badge status-error">Erreur</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="text-align: center; margin-top: 2.5rem; display: flex; gap: 1rem; justify-content: center;">
                <a href="index.php" class="btn btn-primary">Voir l'accueil</a>
                <a href="admin_login.php" class="btn btn-secondary">Espace Administration</a>
            </div>
            
            <p style="color: var(--danger); font-size: 0.8rem; margin-top: 2rem; text-align: center; font-weight: 600;">
                ⚠️ AVERTISSEMENT : Par sécurité, ce fichier de mise à jour (db_update.php) devrait être supprimé en production après exécution.
            </p>
        </div>
    </main>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

</body>
</html>
