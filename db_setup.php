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
        nom VARCHAR(100) DEFAULT NULL,
        prenom VARCHAR(100) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL,
        password VARCHAR(255) NOT NULL,
        must_change_password TINYINT(1) NOT NULL DEFAULT 0,
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

    // Migration: add nom, prenom, must_change_password to admins if they do not exist
    try {
        $pdo->query("SELECT must_change_password FROM admins LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE admins
            ADD COLUMN nom VARCHAR(100) DEFAULT NULL,
            ADD COLUMN prenom VARCHAR(100) DEFAULT NULL,
            ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    }

    // NOTE: aucun compte administrateur par défaut n'est créé ici (sécurité).
    // Le premier administrateur est créé manuellement via le mode "Configuration
    // initiale" de admin_login.php, qui exige un mot de passe choisi par l'utilisateur.

    // 1b. Create integrateurs table (comptes intégrateurs, créés par l'admin)
    $sqlInteg = "CREATE TABLE IF NOT EXISTS integrateurs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) DEFAULT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        must_change_password TINYINT(1) NOT NULL DEFAULT 1,
        reset_token VARCHAR(64) DEFAULT NULL,
        reset_expires DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sqlInteg);

    // Migration: add prenom to integrateurs if it does not exist
    try {
        $pdo->query("SELECT prenom FROM integrateurs LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE integrateurs ADD COLUMN prenom VARCHAR(100) DEFAULT NULL AFTER nom");
    }

    // Migration: add telephone to integrateurs if it does not exist
    try {
        $pdo->query("SELECT telephone FROM integrateurs LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE integrateurs ADD COLUMN telephone VARCHAR(30) DEFAULT NULL AFTER email");
    }

    // 1c. Create commissions table + seed defaults
    $pdo->exec("CREATE TABLE IF NOT EXISTS commissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    if ((int)$pdo->query("SELECT COUNT(*) FROM commissions")->fetchColumn() === 0) {
        $stmtC = $pdo->prepare("INSERT INTO commissions (nom) VALUES (?)");
        foreach (['InfoCom', 'Organisation', 'Cuisine'] as $cName) { $stmtC->execute([$cName]); }
    }
    // La commission « Culte » gère les Kurels : on s'assure qu'elle existe.
    try { $pdo->prepare("INSERT IGNORE INTO commissions (nom) VALUES ('Culte')")->execute(); } catch (Exception $e) {}
    // La commission « Kurels » : ses responsables peuvent créer des Kurels et gérer leurs membres.
    try { $pdo->prepare("INSERT IGNORE INTO commissions (nom) VALUES ('Kurels')")->execute(); } catch (Exception $e) {}
    // La commission « Intégration » : ses responsables accèdent au Suivi intégration.
    try { $pdo->prepare("INSERT IGNORE INTO commissions (nom) VALUES ('Intégration')")->execute(); } catch (Exception $e) {}

    // 1d. Create secteurs (secteurs d'activité) table + seed defaults
    $pdo->exec("CREATE TABLE IF NOT EXISTS secteurs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(150) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    if ((int)$pdo->query("SELECT COUNT(*) FROM secteurs")->fetchColumn() === 0) {
        $stmtSec = $pdo->prepare("INSERT INTO secteurs (nom) VALUES (?)");
        $secteursDefaut = [
            'Agriculture, Agroalimentaire & Pêche',
            "Artisanat & Métiers d'art",
            'Automobile, Aéronautique & Transport',
            'Banque, Assurance & Finance',
            'Bâtiment & Travaux Publics (BTP)',
            'Commerce, Distribution & E-commerce',
            'Culture, Médias & Divertissement',
            'Éducation, Formation & Enseignement',
            'Énergie, Environnement & Recyclage',
            'Hôtellerie, Restauration & Tourisme',
            'Industrie, Chimie & Pharmacie',
            'Informatique, Télécoms & Tech / SaaS',
            'Immobilier & Architecture',
            'Santé, Social & Services à la personne',
            'Services aux entreprises (Conseil, Marketing, Juridique)',
            'Secteur public, Administration & Associations',
        ];
        foreach ($secteursDefaut as $sName) { $stmtSec->execute([$sName]); }
    }

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
        integrateur VARCHAR(150) DEFAULT NULL,
        integrateur_id INT DEFAULT NULL,
        souhait_commission VARCHAR(150) DEFAULT NULL,
        presentation_ok VARCHAR(20) DEFAULT NULL,
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

    // Migration: Add secretariat management columns (from responses Excel) if missing
    try {
        $pdo->query("SELECT integrateur FROM membres LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres
            ADD COLUMN integrateur VARCHAR(150) DEFAULT NULL,
            ADD COLUMN souhait_commission VARCHAR(150) DEFAULT NULL,
            ADD COLUMN presentation_ok VARCHAR(20) DEFAULT NULL");
    }

    // Migration: Add integrateur_id (association intégrateur ↔ inscrit) if missing
    try {
        $pdo->query("SELECT integrateur_id FROM membres LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres ADD COLUMN integrateur_id INT DEFAULT NULL");
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

    // Consentement RGPD (traitement des données et de la photo) : preuve horodatée
    try { $pdo->query("SELECT rgpd_accepte FROM membres LIMIT 1"); } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres ADD COLUMN rgpd_accepte TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN rgpd_date DATETIME NULL DEFAULT NULL, ADD COLUMN rgpd_version VARCHAR(20) NULL DEFAULT NULL");
    }

    // Migration: rôle intégrateur porté par un membre (remplace les comptes intégrateurs séparés)
    try {
        $pdo->query("SELECT is_integrateur FROM membres LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres ADD COLUMN is_integrateur TINYINT(1) NOT NULL DEFAULT 0");
        // Migration best-effort : rapprocher les anciens intégrateurs (table integrateurs) d'un membre par e-mail.
        try {
            $oldIntegs = $pdo->query("SELECT id, email FROM integrateurs")->fetchAll();
            foreach ($oldIntegs as $oi) {
                $stmtM = $pdo->prepare("SELECT id FROM membres WHERE email = ? LIMIT 1");
                $stmtM->execute([$oi['email']]);
                $mid = $stmtM->fetchColumn();
                if ($mid) {
                    $pdo->prepare("UPDATE membres SET is_integrateur = 1 WHERE id = ?")->execute([(int)$mid]);
                    // Repointer les assignations vers l'id du membre (valeur négative temporaire anti-collision)
                    $pdo->prepare("UPDATE membres SET integrateur_id = ? WHERE integrateur_id = ?")
                        ->execute([-1 * (int)$mid, (int)$oi['id']]);
                }
            }
            // Références non migrées (ancien intégrateur sans membre correspondant) : annulées
            $pdo->exec("UPDATE membres SET integrateur_id = NULL WHERE integrateur_id > 0");
            // Remettre les valeurs migrées en positif
            $pdo->exec("UPDATE membres SET integrateur_id = -integrateur_id WHERE integrateur_id < 0");
        } catch (Exception $e2) {
            error_log('Touba Lyon db_setup: migration rôle intégrateur: ' . $e2->getMessage());
        }
    }

    // Migration: rôle administrateur porté par un membre (le compte admin par défaut reste inchangé)
    try {
        $pdo->query("SELECT is_admin FROM membres LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
        // Best-effort : les admins existants (hors admin par défaut = plus petit id) qui correspondent
        // à un membre par e-mail reçoivent le rôle admin.
        try {
            $defId = (int)$pdo->query("SELECT MIN(id) FROM admins")->fetchColumn();
            $sqlOthers = "SELECT email FROM admins WHERE email IS NOT NULL AND email <> ''";
            if ($defId) { $sqlOthers .= " AND id <> " . $defId; }
            $others = $pdo->query($sqlOthers)->fetchAll(PDO::FETCH_COLUMN);
            foreach ($others as $em) {
                $pdo->prepare("UPDATE membres SET is_admin = 1 WHERE email = ?")->execute([$em]);
            }
        } catch (Exception $e2) {
            error_log('Touba Lyon db_setup: migration rôle admin: ' . $e2->getMessage());
        }
    }

    // Migration: photo_path doit accepter une valeur par défaut (adhésions sans photo)
    try {
        $pdo->exec("ALTER TABLE membres MODIFY COLUMN photo_path VARCHAR(255) NOT NULL DEFAULT ''");
    } catch (Exception $e) {
        // Non bloquant : si la modification échoue, on continue.
        error_log('Touba Lyon db_setup: photo_path default migration: ' . $e->getMessage());
    }

    // Migration: rôle « gestion des Kurels » porté par un membre (donné par l'admin)
    try {
        $pdo->query("SELECT is_gestion_kourel FROM membres LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE membres ADD COLUMN is_gestion_kourel TINYINT(1) NOT NULL DEFAULT 0");
    }

    // Table des Kurels (un Kurel = un groupe de membres)
    $pdo->exec("CREATE TABLE IF NOT EXISTS kourels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(150) NOT NULL,
        description TEXT DEFAULT NULL,
        responsable_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Table de liaison Kurel <-> Membre
    $pdo->exec("CREATE TABLE IF NOT EXISTS kourel_membres (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kourel_id INT NOT NULL,
        membre_id INT NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_km (kourel_id, membre_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Responsables (rôle de gestion) d'un Kurel — modèle identique aux commissions
    $pdo->exec("CREATE TABLE IF NOT EXISTS kourel_gestionnaires (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kourel_id INT NOT NULL,
        membre_id INT NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kg (kourel_id, membre_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Membres d'une commission
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_membres (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commission_id INT NOT NULL,
        membre_id INT NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cm (commission_id, membre_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Responsables (rôle de gestion) d'une commission
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_gestionnaires (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commission_id INT NOT NULL,
        membre_id INT NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cg (commission_id, membre_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Disponibilités des membres des commissions pour les dimanches du planning Dahira
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_disponibilites (
        planning_id INT NOT NULL,
        membre_id INT NOT NULL,
        statut VARCHAR(10) NOT NULL DEFAULT 'dispo',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (planning_id, membre_id),
        INDEX idx_membre (membre_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Sous-commissions : créées par les responsables d'une commission, qui y affectent
    // des membres de leur commission (tables séparées pour ne pas interférer avec les commissions).
    $pdo->exec("CREATE TABLE IF NOT EXISTS sous_commissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commission_id INT NOT NULL,
        nom VARCHAR(150) NOT NULL,
        created_by INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_sc (commission_id, nom)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sous_commission_membres (
        sous_commission_id INT NOT NULL,
        membre_id INT NOT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (sous_commission_id, membre_id),
        INDEX idx_membre (membre_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Responsable d'une sous-commission (membre de la commission, affecté à la sous-commission)
    try { $pdo->query("SELECT responsable_id FROM sous_commissions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE sous_commissions ADD COLUMN responsable_id INT NULL DEFAULT NULL"); }
    // Membre « support » d'une sous-commission : membre quelconque ajouté à la sous-commission,
    // sans faire partie de la commission parente
    try { $pdo->query("SELECT support FROM sous_commission_membres LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE sous_commission_membres ADD COLUMN support TINYINT(1) NOT NULL DEFAULT 0"); }
    // Option d'une sous-commission : autorise l'ajout de n'importe quel membre (membres supports)
    try { $pdo->query("SELECT accepte_supports FROM sous_commissions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE sous_commissions ADD COLUMN accepte_supports TINYINT(1) NOT NULL DEFAULT 0"); }
    // Les sous-commissions qui ont déjà des membres supports gardent cette possibilité
    try { $pdo->exec("UPDATE sous_commissions SET accepte_supports = 1 WHERE accepte_supports = 0 AND id IN (SELECT DISTINCT sous_commission_id FROM sous_commission_membres WHERE support = 1)"); } catch (Exception $e) { /* sans importance */ }

    // Lien Zoom par défaut d'une commission (pré-rempli pour ses réunions à distance)
    try {
        $pdo->query("SELECT lien_zoom FROM commissions LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE commissions ADD COLUMN lien_zoom VARCHAR(500) NULL DEFAULT NULL");
    }

    // Gestion de stock par commission (activée par l'admin)
    try {
        $pdo->query("SELECT stock_enabled FROM commissions LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE commissions ADD COLUMN stock_enabled TINYINT(1) NOT NULL DEFAULT 0");
    }
    // Articles en stock d'une commission
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_stock (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commission_id INT NOT NULL,
        nom VARCHAR(150) NOT NULL,
        description TEXT DEFAULT NULL,
        quantite INT NOT NULL DEFAULT 0,
        statut VARCHAR(30) NOT NULL DEFAULT 'Disponible',
        lieu VARCHAR(150) DEFAULT NULL,
        date_achat VARCHAR(7) DEFAULT NULL,
        prix_achat DECIMAL(10,2) DEFAULT NULL,
        photo VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Ajout de la colonne lieu si la table existait déjà sans elle
    try {
        $pdo->query("SELECT lieu FROM commission_stock LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE commission_stock ADD COLUMN lieu VARCHAR(150) DEFAULT NULL AFTER statut");
    }
    // Date d'achat (AAAA-MM) + prix d'achat, si absents
    try {
        $pdo->query("SELECT date_achat FROM commission_stock LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE commission_stock ADD COLUMN date_achat VARCHAR(7) DEFAULT NULL, ADD COLUMN prix_achat DECIMAL(10,2) DEFAULT NULL");
    }
    // Album photos d'un article de stock (plusieurs photos par article)
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_stock_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        photo VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_item (item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Lieux de stockage (liste partagée, enrichissable)
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_lieux (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(150) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ((int)$pdo->query("SELECT COUNT(*) FROM stock_lieux")->fetchColumn() === 0) {
        $stmtL = $pdo->prepare("INSERT INTO stock_lieux (nom) VALUES (?)");
        foreach (['Keur Serigne Touba', 'Box'] as $ln) { $stmtL->execute([$ln]); }
    }

    // Lecture collective du Coran (Khatm) — sessions de la commission Culte
    $pdo->exec("CREATE TABLE IF NOT EXISTS quran_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titre VARCHAR(150) NOT NULL,
        description TEXT DEFAULT NULL,
        groupe VARCHAR(100) DEFAULT NULL,
        token VARCHAR(32) NOT NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'en_cours',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        closed_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->query("SELECT groupe FROM quran_sessions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE quran_sessions ADD COLUMN groupe VARCHAR(100) DEFAULT NULL AFTER description"); }
    // Périodicité du Khatm : 'ponctuelle' (par défaut) ou 'semaine' / 'quinzaine' / 'mois'
    try { $pdo->query("SELECT periodicite FROM quran_sessions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE quran_sessions ADD COLUMN periodicite VARCHAR(20) NOT NULL DEFAULT 'ponctuelle' AFTER groupe"); }
    // Réallouer automatiquement les mêmes Juz aux mêmes membres à la période suivante
    try { $pdo->query("SELECT realloc_meme_membre FROM quran_sessions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE quran_sessions ADD COLUMN realloc_meme_membre TINYINT(1) NOT NULL DEFAULT 0 AFTER periodicite"); }
    // Lien vers la session précédente (chaînage des périodes)
    try { $pdo->query("SELECT parent_id FROM quran_sessions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE quran_sessions ADD COLUMN parent_id INT DEFAULT NULL AFTER realloc_meme_membre"); }
    // Dates de la période (calendrier des sessions périodiques)
    try { $pdo->query("SELECT date_debut FROM quran_sessions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE quran_sessions ADD COLUMN date_debut DATE DEFAULT NULL AFTER parent_id"); }
    try { $pdo->query("SELECT date_fin FROM quran_sessions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE quran_sessions ADD COLUMN date_fin DATE DEFAULT NULL AFTER date_debut"); }
    // Identifiant de série (regroupe toutes les périodes d'un même Khatm périodique)
    try { $pdo->query("SELECT serie_id FROM quran_sessions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE quran_sessions ADD COLUMN serie_id INT DEFAULT NULL AFTER date_fin"); }
    // Lancement : une période/session n'est visible des membres que si lancée (défaut 1 = compat.)
    try { $pdo->query("SELECT lance FROM quran_sessions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE quran_sessions ADD COLUMN lance TINYINT(1) NOT NULL DEFAULT 1 AFTER serie_id"); }
    // Un seul Juz par membre (1) ou plusieurs autorisés (0). Défaut 0 en base pour
    // préserver les sessions existantes ; les nouvelles sessions par défaut = 1 (formulaire).
    try { $pdo->query("SELECT un_juz_par_membre FROM quran_sessions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE quran_sessions ADD COLUMN un_juz_par_membre TINYINT(1) NOT NULL DEFAULT 0 AFTER lance"); }
    // Groupes de lecture (Magal, Gamou, Hebdomadaire… enrichissable)
    $pdo->exec("CREATE TABLE IF NOT EXISTS quran_groupes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ((int)$pdo->query("SELECT COUNT(*) FROM quran_groupes")->fetchColumn() === 0) {
        $stmtG = $pdo->prepare("INSERT INTO quran_groupes (nom) VALUES (?)");
        foreach (['Magal', 'Gamou', 'Hebdomadaire'] as $g) { $stmtG->execute([$g]); }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS quran_parts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        numero INT NOT NULL,
        membre_id INT DEFAULT NULL,
        membre_nom VARCHAR(150) DEFAULT NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'libre',
        owner_token VARCHAR(64) DEFAULT NULL,
        reserved_at DATETIME DEFAULT NULL,
        validated_at DATETIME DEFAULT NULL,
        UNIQUE KEY uniq_sp (session_id, numero),
        KEY idx_sess (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Gestionnaires d'un Kamil : membres de la commission Culte désignés pour suivre
    // et gérer une session (ou toute une série périodique : session_key = serie_id ou id).
    $pdo->exec("CREATE TABLE IF NOT EXISTS quran_session_gestionnaires (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_key INT NOT NULL,
        membre_id INT NOT NULL,
        granted_by INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_qsg (session_key, membre_id),
        INDEX idx_membre (membre_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Délégués : membres autorisés par un responsable Culte à créer des sessions de lecture
    $pdo->exec("CREATE TABLE IF NOT EXISTS culte_delegues (
        membre_id INT NOT NULL PRIMARY KEY,
        granted_by INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Réglages généraux (clé/valeur) — ex. lien du groupe WhatsApp par défaut
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
        cle VARCHAR(64) PRIMARY KEY,
        valeur TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Groupe WhatsApp par défaut (initialisé une fois ; modifiable ensuite depuis « Lecture Coran »)
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('wa_group_link', ?)")->execute(['https://chat.whatsapp.com/JhKwDnFv3cF8mwOkyDvxZv']); } catch (Exception $e) {}

    // Notifications du Dahira (cloche navigateur) — liées aux comptes membres
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        membre_id INT NOT NULL,
        kind VARCHAR(40) NOT NULL,
        title VARCHAR(150) NOT NULL,
        body VARCHAR(500) NOT NULL,
        url VARCHAR(190) NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        shown_at DATETIME NULL DEFAULT NULL,
        read_at DATETIME NULL DEFAULT NULL,
        deleted_at DATETIME NULL DEFAULT NULL,
        INDEX idx_membre_created (membre_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Migration : colonne deleted_at (suppression douce) si absente
    try {
        $pdo->query("SELECT deleted_at FROM notifications LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
    }
    // Migration : colonne pushed_at (Web Push vers les appareils) si absente
    try {
        $pdo->query("SELECT pushed_at FROM notifications LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN pushed_at DATETIME NULL DEFAULT NULL");
    }

    // Abonnements Web Push (notifications mobiles « application fermée »)
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        membre_id INT NOT NULL,
        endpoint VARCHAR(500) NOT NULL,
        endpoint_hash CHAR(64) NOT NULL,
        p256dh VARCHAR(200) NOT NULL,
        auth VARCHAR(100) NOT NULL,
        user_agent VARCHAR(190) NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_sent_at DATETIME NULL DEFAULT NULL,
        last_status INT NULL DEFAULT NULL,
        UNIQUE KEY uniq_endpoint (endpoint_hash),
        INDEX idx_membre (membre_id),
        FOREIGN KEY (membre_id) REFERENCES membres(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Planning hebdomadaire du Dahira (commission Secrétariat Général) :
    // un dimanche sur deux, avec le programme de la séance et les envois.
    $pdo->exec("CREATE TABLE IF NOT EXISTS dahira_plannings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date_dahira DATE NOT NULL UNIQUE,
        commission_id INT NULL DEFAULT NULL,
        a_dahira TINYINT(1) NOT NULL DEFAULT 1,
        programme TEXT NULL,
        whatsapp_envoye TINYINT(1) NOT NULL DEFAULT 0,
        email_envoye TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_date (date_dahira)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Migration : colonne a_dahira (statut « Dahira » ou « pas Dahira ») si absente
    try {
        $pdo->query("SELECT a_dahira FROM dahira_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN a_dahira TINYINT(1) NOT NULL DEFAULT 1");
    }
    // Migration : colonne cloture (Dahira clôturé) si absente
    try {
        $pdo->query("SELECT cloture FROM dahira_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN cloture TINYINT(1) NOT NULL DEFAULT 0");
    }
    // Migration : colonne nb_participants (nombre de participants, facultatif) si absente
    try {
        $pdo->query("SELECT nb_participants FROM dahira_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN nb_participants INT NULL DEFAULT NULL");
    }
    // Migrations : horaires spécifiques à un Dahira (remplacent les horaires par défaut)
    try { $pdo->query("SELECT heure_debut FROM dahira_plannings LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN heure_debut VARCHAR(20) NULL DEFAULT NULL"); }
    try { $pdo->query("SELECT heure_fin FROM dahira_plannings LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN heure_fin VARCHAR(20) NULL DEFAULT NULL"); }
    // Migration : titre du Dahira (facultatif — ex. « Magal de… », « Gamou »)
    try { $pdo->query("SELECT titre FROM dahira_plannings LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN titre VARCHAR(200) NULL DEFAULT NULL"); }
    // Migration : image jointe à un Dahira (affichée dans la page détail)
    try { $pdo->query("SELECT image_path FROM dahira_plannings LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN image_path VARCHAR(255) NULL DEFAULT NULL"); }
    // Migration : type d'événement (dahira, ag, gamou, magal, ngan, deplacement) — les lignes existantes restent des Dahira
    try { $pdo->query("SELECT type_evenement FROM dahira_plannings LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN type_evenement VARCHAR(20) NOT NULL DEFAULT 'dahira'"); }
    // Migration : description du Dahira (facultatif)
    try { $pdo->query("SELECT description FROM dahira_plannings LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN description TEXT NULL DEFAULT NULL"); }
    // Migration : option « Dahira » d'un dimanche planifié. Par défaut un dimanche planifié n'est
    // PAS un Dahira ; la Secrétaire active l'option. Les plannings existants étaient tous des Dahira.
    try {
        $pdo->query("SELECT option_dahira FROM dahira_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN option_dahira TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE dahira_plannings SET option_dahira = 1");
    }
    // Migration : colonne publie (affichage sur l'accueil membre + validation de présence) si absente
    try {
        $pdo->query("SELECT publie FROM dahira_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE dahira_plannings ADD COLUMN publie TINYINT(1) NOT NULL DEFAULT 0");
    }
    // Réglages du planning (lieu, horaires, groupe WhatsApp) — clé/valeur
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('dahira_lieu', '1 rue du 35 régiment d''aviation, 69500 Bron')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('dahira_debut', '17h00')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('dahira_fin', '20h30')")->execute(); } catch (Exception $e) {}
    // Programme par défaut (modèle réutilisable, chargé à la demande dans le Prochain Dahira)
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('dahira_programme_defaut', '')")->execute(); } catch (Exception $e) {}
    // Le lien du groupe WhatsApp utilise la clé commune « wa_group_link » (partagée avec wird_admin).

    // Planning « Guddi Àjjuma » (commission Culte) : tous les jeudis.
    $pdo->exec("CREATE TABLE IF NOT EXISTS guddi_plannings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date_guddi DATE NOT NULL UNIQUE,
        commission_id INT NULL DEFAULT NULL,
        actif TINYINT(1) NOT NULL DEFAULT 1,
        theme VARCHAR(255) NULL DEFAULT NULL,
        presentateur VARCHAR(255) NULL DEFAULT NULL,
        lien VARCHAR(500) NULL DEFAULT NULL,
        livre VARCHAR(255) NULL DEFAULT NULL,
        pdf_path VARCHAR(255) NULL DEFAULT NULL,
        mode VARCHAR(20) NULL DEFAULT NULL,
        whatsapp_envoye TINYINT(1) NOT NULL DEFAULT 0,
        email_envoye TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_date (date_guddi)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Migration : colonne livre (livre à étudier de la séance) si absente
    try {
        $pdo->query("SELECT livre FROM guddi_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guddi_plannings ADD COLUMN livre VARCHAR(255) NULL DEFAULT NULL");
    }
    // Migration : colonne pdf_path (programme PDF joint à la séance) si absente
    try {
        $pdo->query("SELECT pdf_path FROM guddi_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guddi_plannings ADD COLUMN pdf_path VARCHAR(255) NULL DEFAULT NULL");
    }
    // Migration : colonne mode (distance / presentiel) si absente
    try {
        $pdo->query("SELECT mode FROM guddi_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guddi_plannings ADD COLUMN mode VARCHAR(20) NULL DEFAULT NULL");
    }
    // Migration : colonne cloture (séance clôturée) si absente
    try {
        $pdo->query("SELECT cloture FROM guddi_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guddi_plannings ADD COLUMN cloture TINYINT(1) NOT NULL DEFAULT 0");
    }
    // Migration : colonne nb_participants (nombre de participants, facultatif) si absente
    try {
        $pdo->query("SELECT nb_participants FROM guddi_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guddi_plannings ADD COLUMN nb_participants INT NULL DEFAULT NULL");
    }
    // Migration : colonne publie (affichage sur l'accueil membre + validation de présence) si absente
    try {
        $pdo->query("SELECT publie FROM guddi_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guddi_plannings ADD COLUMN publie TINYINT(1) NOT NULL DEFAULT 0");
    }
    // Migration : colonne audio_path (enregistrement de la séance, déposé à la clôture) si absente
    try {
        $pdo->query("SELECT audio_path FROM guddi_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guddi_plannings ADD COLUMN audio_path VARCHAR(255) NULL DEFAULT NULL");
    }
    // Migration : colonnes heure / heure_fin propres à la séance (sinon valeurs par défaut) si absentes
    try {
        $pdo->query("SELECT heure FROM guddi_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guddi_plannings ADD COLUMN heure VARCHAR(20) NULL DEFAULT NULL");
    }
    try {
        $pdo->query("SELECT heure_fin FROM guddi_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guddi_plannings ADD COLUMN heure_fin VARCHAR(20) NULL DEFAULT NULL");
    }
    // Migration : colonne details_html (détails complémentaires en HTML) si absente
    try {
        $pdo->query("SELECT details_html FROM guddi_plannings LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE guddi_plannings ADD COLUMN details_html TEXT NULL DEFAULT NULL");
    }
    // Réglages du Guddi Àjjuma (heure, thème, présentateur, lien par défaut)
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_heure', '20h00')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_heure_fin', '21h30')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_theme_defaut', 'Sëriñ Tuubaa ak Gammu')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_presentateur_defaut', 'Oustaz Sëriñ Mbàcke Géy')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_lien_defaut', 'https://us06web.zoom.us/j/81338485985?pwd=RDNzM3hBWkpmdTBCTE0rS1lDS2w5QT09')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_mode_defaut', 'distance')")->execute(); } catch (Exception $e) {}

    // Validations de présence des membres (Dahira / Guddi Àjjuma / Réunions de commission publiés)
    $pdo->exec("CREATE TABLE IF NOT EXISTS presence_validations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        planning_type VARCHAR(20) NOT NULL,
        planning_id INT NOT NULL,
        membre_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_presence (planning_type, planning_id, membre_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Réunions de commission (créées par les responsables de commission, ponctuelles ou périodiques)
    $pdo->exec("CREATE TABLE IF NOT EXISTS commission_reunions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commission_id INT NOT NULL,
        titre VARCHAR(200) NOT NULL,
        description TEXT NULL DEFAULT NULL,
        date_reunion DATE NOT NULL,
        heure VARCHAR(20) NOT NULL DEFAULT '20h00',
        mode VARCHAR(20) NOT NULL DEFAULT 'distance',
        lieu VARCHAR(255) NULL DEFAULT NULL,
        lien VARCHAR(500) NULL DEFAULT NULL,
        periodicite VARCHAR(20) NOT NULL DEFAULT 'ponctuelle',
        serie_id INT NULL DEFAULT NULL,
        publie TINYINT(1) NOT NULL DEFAULT 0,
        cloture TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_com (commission_id),
        INDEX idx_date (date_reunion),
        INDEX idx_serie (serie_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try { $pdo->query("SELECT annule FROM commission_reunions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE commission_reunions ADD COLUMN annule TINYINT(1) NOT NULL DEFAULT 0"); }
    try { $pdo->query("SELECT debut_notifie FROM commission_reunions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE commission_reunions ADD COLUMN debut_notifie TINYINT(1) NOT NULL DEFAULT 0"); }
    try { $pdo->query("SELECT heure_fin FROM commission_reunions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE commission_reunions ADD COLUMN heure_fin VARCHAR(20) NULL DEFAULT NULL"); }
    try { $pdo->query("SELECT compte_rendu FROM commission_reunions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE commission_reunions ADD COLUMN compte_rendu MEDIUMTEXT NULL DEFAULT NULL"); }
    // Sondage de date et d'horaire d'une réunion : options proposées puis votes des membres.
    // Tant que le sondage est « ouvert », la réunion ne peut pas être publiée ; la validation
    // d'une option fixe la date et l'horaire de la réunion (sondage « clos »).
    try { $pdo->query("SELECT sondage_statut FROM commission_reunions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE commission_reunions ADD COLUMN sondage_statut VARCHAR(10) NOT NULL DEFAULT 'aucun'"); }
    $pdo->exec("CREATE TABLE IF NOT EXISTS reunion_sondage_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reunion_id INT NOT NULL,
        date_option DATE NOT NULL,
        heure VARCHAR(20) NOT NULL,
        heure_fin VARCHAR(20) NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_reunion (reunion_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reunion_sondage_votes (
        option_id INT NOT NULL,
        membre_id INT NOT NULL,
        reponse VARCHAR(5) NOT NULL DEFAULT 'oui',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (option_id, membre_id),
        INDEX idx_membre (membre_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Envois de codes de validation d'adresse e-mail (limitation des abus)
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verif_envois (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email, created_at),
        INDEX idx_ip (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Sondages des commissions : créés par les responsables, ouverts à tous les membres.
    // resultats_visibilite : 'tous' (tous les membres) ou 'bureau' (responsables de commission).
    $pdo->exec("CREATE TABLE IF NOT EXISTS sondages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        commission_id INT NOT NULL,
        auteur_id INT NOT NULL DEFAULT 0,
        question VARCHAR(255) NOT NULL,
        description TEXT NULL DEFAULT NULL,
        choix_multiple TINYINT(1) NOT NULL DEFAULT 0,
        anonyme TINYINT(1) NOT NULL DEFAULT 0,
        resultats_visibilite VARCHAR(10) NOT NULL DEFAULT 'tous',
        statut VARCHAR(10) NOT NULL DEFAULT 'brouillon',
        date_fin DATE NULL DEFAULT NULL,
        published_at DATETIME NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_statut (statut),
        INDEX idx_commission (commission_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sondage_options (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sondage_id INT NOT NULL,
        libelle VARCHAR(200) NOT NULL,
        position INT NOT NULL DEFAULT 0,
        INDEX idx_sondage (sondage_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sondage_votes (
        sondage_id INT NOT NULL,
        option_id INT NOT NULL,
        membre_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (option_id, membre_id),
        INDEX idx_sondage_membre (sondage_id, membre_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Répétitions de Kurel (créées par les responsables de Kurel, ponctuelles ou périodiques)
    $pdo->exec("CREATE TABLE IF NOT EXISTS kourel_repetitions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kourel_id INT NOT NULL,
        titre VARCHAR(200) NOT NULL,
        description TEXT NULL DEFAULT NULL,
        date_reunion DATE NOT NULL,
        heure VARCHAR(20) NOT NULL DEFAULT '20h00',
        mode VARCHAR(20) NOT NULL DEFAULT 'distance',
        lieu VARCHAR(255) NULL DEFAULT NULL,
        lien VARCHAR(500) NULL DEFAULT NULL,
        periodicite VARCHAR(20) NOT NULL DEFAULT 'ponctuelle',
        serie_id INT NULL DEFAULT NULL,
        khassida VARCHAR(255) NULL DEFAULT NULL,
        melodie VARCHAR(150) NULL DEFAULT NULL,
        publie TINYINT(1) NOT NULL DEFAULT 0,
        cloture TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_kou (kourel_id),
        INDEX idx_date (date_reunion),
        INDEX idx_serie (serie_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Migrations : colonnes khassida / melodie / annule (bases existantes)
    try { $pdo->query("SELECT khassida FROM kourel_repetitions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE kourel_repetitions ADD COLUMN khassida VARCHAR(255) NULL DEFAULT NULL"); }
    try { $pdo->query("SELECT melodie FROM kourel_repetitions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE kourel_repetitions ADD COLUMN melodie VARCHAR(150) NULL DEFAULT NULL"); }
    try { $pdo->query("SELECT annule FROM kourel_repetitions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE kourel_repetitions ADD COLUMN annule TINYINT(1) NOT NULL DEFAULT 0"); }
    try { $pdo->query("SELECT debut_notifie FROM kourel_repetitions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE kourel_repetitions ADD COLUMN debut_notifie TINYINT(1) NOT NULL DEFAULT 0"); }
    // Rattachement à une ligne d'avancement : la répétition travaille une
    // Khassida du programme du Kurel. Les colonnes khassida / melodie restent
    // renseignées, pour que les répétitions déjà saisies gardent leur libellé.
    try { $pdo->query("SELECT avancement_id FROM kourel_repetitions LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE kourel_repetitions ADD COLUMN avancement_id INT NULL DEFAULT NULL"); }

    // Liste des Khassidas de Serigne Touba (partagée entre Kurels)
    $pdo->exec("CREATE TABLE IF NOT EXISTS kourel_khassidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(200) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_khassida (nom)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Liste des types de mélodie (partagée entre Kurels)
    $pdo->exec("CREATE TABLE IF NOT EXISTS kourel_melodies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(150) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_melodie (nom)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Amorçage : Khassidas les plus récitées en Kurel
    foreach (['Matlaboul Fawzeyni', 'Mawaahibun Naafih', 'Jazaa\'u Shakuur', 'Midaadi', 'Nahju Qadaa\'il Haaj', 'Munawwarus Suduur', 'Sindidi', 'Massaalik al-Jinaan', 'Jawharun Nafiis', 'Tazawwudus Sighaar'] as $kh) {
        try { $pdo->prepare("INSERT IGNORE INTO kourel_khassidas (nom) VALUES (?)")->execute([$kh]); } catch (Exception $e) {}
    }
    // Amorçage : types de mélodie proposés (modifiables / extensibles)
    foreach (['Classique', 'Lente (recueillement)', 'Rapide (rythmée)', 'Solennelle', 'Méditative', 'Festive (Gamou / Màggal)', 'Cadence Kurel', 'Psalmodie'] as $mel) {
        try { $pdo->prepare("INSERT IGNORE INTO kourel_melodies (nom) VALUES (?)")->execute([$mel]); } catch (Exception $e) {}
    }

    // Avancement d'un Kurel : quelques Khassidas par année, chacune avec sa
    // mélodie, en attente / en cours / complétée.
    $pdo->exec("CREATE TABLE IF NOT EXISTS kourel_avancements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kourel_id INT NOT NULL,
        annee SMALLINT NOT NULL,
        khassida VARCHAR(200) NOT NULL,
        melodie VARCHAR(150) NULL DEFAULT NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'attente',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uniq_kourel_annee_khassida (kourel_id, annee, khassida),
        INDEX idx_kou_annee (kourel_id, annee)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Actualités : messages préparés par la commission Secrétariat Général,
    // diffusés aux membres (statut publié) par notification, WhatsApp et e-mail.
    $pdo->exec("CREATE TABLE IF NOT EXISTS actualites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(100) NULL DEFAULT NULL,
        titre VARCHAR(200) NOT NULL,
        contenu TEXT NOT NULL,
        mentions VARCHAR(500) NULL DEFAULT NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'brouillon',
        publie_notifie TINYINT(1) NOT NULL DEFAULT 0,
        email_envoye TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT NULL DEFAULT NULL,
        published_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_statut (statut),
        INDEX idx_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migration : colonne cloture (actualité archivée, masquée aux membres)
    try { $pdo->query("SELECT cloture FROM actualites LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE actualites ADD COLUMN cloture TINYINT(1) NOT NULL DEFAULT 0"); }
    // Migrations : pièces jointes (image à afficher, document PDF)
    try { $pdo->query("SELECT image_path FROM actualites LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE actualites ADD COLUMN image_path VARCHAR(255) NULL DEFAULT NULL"); }
    try { $pdo->query("SELECT pdf_path FROM actualites LIMIT 1"); } catch (Exception $e) { $pdo->exec("ALTER TABLE actualites ADD COLUMN pdf_path VARCHAR(255) NULL DEFAULT NULL"); }

    // Liste des types d'actualité (extensible par les responsables)
    $pdo->exec("CREATE TABLE IF NOT EXISTS actualite_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_type (nom)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Amorçage : types d'actualité proposés (modifiables / extensibles)
    foreach (['Annonce', 'Annonce mariage', 'Événement', 'Communiqué', 'Rappel', 'Décès / Condoléances', 'Félicitations', 'Appel à contribution', 'Compte-rendu', 'Visite'] as $at) {
        try { $pdo->prepare("INSERT IGNORE INTO actualite_types (nom) VALUES (?)")->execute([$at]); } catch (Exception $e) {}
    }

    // Tâches liées à une réunion de commission : après publication, tout membre
    // de la commission peut ajouter des tâches et les attribuer aux autres membres.
    $pdo->exec("CREATE TABLE IF NOT EXISTS reunion_taches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reunion_id INT NOT NULL,
        commission_id INT NOT NULL,
        titre VARCHAR(255) NOT NULL,
        description TEXT NULL DEFAULT NULL,
        assignee_id INT NULL DEFAULT NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'a_faire',
        echeance DATE NULL DEFAULT NULL,
        created_by INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_reunion (reunion_id),
        INDEX idx_commission (commission_id),
        INDEX idx_assignee (assignee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Commentaires / historique d'une tâche (dont les changements de statut)
    $pdo->exec("CREATE TABLE IF NOT EXISTS reunion_tache_commentaires (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tache_id INT NOT NULL,
        membre_id INT NULL DEFAULT NULL,
        texte TEXT NOT NULL,
        systeme TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tache (tache_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Cotisations : mode de cotisation par membre (mensuel = doit cotiser chaque
    // mois via Stripe ; virement = virement automatique ; exempt = dispensé).
    $pdo->exec("CREATE TABLE IF NOT EXISTS cotisation_config (
        membre_id INT NOT NULL PRIMARY KEY,
        mode VARCHAR(20) NOT NULL DEFAULT 'mensuel',
        updated_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Migration : colonne virement_depuis (référence AAAA-MM du passage en virement
    // automatique — le virement ne couvre que les mois postérieurs à cette date)
    try {
        $pdo->query("SELECT virement_depuis FROM cotisation_config LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE cotisation_config ADD COLUMN virement_depuis CHAR(7) NULL DEFAULT NULL");
    }

    // Migration : jour du mois prévu pour le virement automatique (1-31)
    try {
        $pdo->query("SELECT virement_jour FROM cotisation_config LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE cotisation_config ADD COLUMN virement_jour TINYINT NULL DEFAULT NULL");
    }

    // Cotisations : un enregistrement par membre et par mois (période AAAA-MM).
    $pdo->exec("CREATE TABLE IF NOT EXISTS cotisations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        membre_id INT NOT NULL,
        periode CHAR(7) NOT NULL,
        montant DECIMAL(8,2) NOT NULL DEFAULT 0,
        statut VARCHAR(20) NOT NULL DEFAULT 'attente',
        methode VARCHAR(20) NULL DEFAULT NULL,
        stripe_session VARCHAR(255) NULL DEFAULT NULL,
        paid_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uniq_membre_periode (membre_id, periode),
        INDEX idx_periode (periode),
        INDEX idx_statut (statut)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Valeur par défaut de la cotisation mensuelle (en euros), modifiable en réglages
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM app_settings WHERE cle = 'cotisation_montant'");
        $st->execute();
        if ((int) $st->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO app_settings (cle, valeur) VALUES ('cotisation_montant', '30')")->execute();
        }
    } catch (Exception $e) { /* app_settings créé plus tôt */ }

    // Wadial Magal : commissions impliquées, besoins, tâches et finances par édition (année)
    $pdo->exec("CREATE TABLE IF NOT EXISTS magal_commissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        annee SMALLINT NOT NULL,
        commission_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mc (annee, commission_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS magal_besoins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        annee SMALLINT NOT NULL,
        commission_id INT NOT NULL,
        libelle VARCHAR(255) NOT NULL,
        quantite VARCHAR(60) NULL DEFAULT NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'a_trouver',
        note TEXT NULL DEFAULT NULL,
        created_by INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_annee_com (annee, commission_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS magal_taches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        annee SMALLINT NOT NULL,
        commission_id INT NOT NULL,
        titre VARCHAR(255) NOT NULL,
        description TEXT NULL DEFAULT NULL,
        assignee_id INT NULL DEFAULT NULL,
        echeance DATE NULL DEFAULT NULL,
        statut VARCHAR(20) NOT NULL DEFAULT 'a_faire',
        created_by INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_annee_com (annee, commission_id),
        INDEX idx_assignee (assignee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS magal_finance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        annee SMALLINT NOT NULL,
        type VARCHAR(10) NOT NULL DEFAULT 'depense',
        libelle VARCHAR(255) NOT NULL,
        montant DECIMAL(10,2) NOT NULL DEFAULT 0,
        commission_id INT NULL DEFAULT NULL,
        date_op DATE NULL DEFAULT NULL,
        note TEXT NULL DEFAULT NULL,
        created_by INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_annee (annee)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
