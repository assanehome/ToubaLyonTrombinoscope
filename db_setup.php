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
    // Réglages du Guddi Àjjuma (heure, thème, présentateur, lien par défaut)
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_heure', '20h00')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_theme_defaut', 'Sëriñ Tuubaa ak Gammu')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_presentateur_defaut', 'Oustaz Sëriñ Mbàcke Géy')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_lien_defaut', 'https://us06web.zoom.us/j/81338485985?pwd=RDNzM3hBWkpmdTBCTE0rS1lDS2w5QT09')")->execute(); } catch (Exception $e) {}
    try { $pdo->prepare("INSERT IGNORE INTO app_settings (cle, valeur) VALUES ('guddi_mode_defaut', 'distance')")->execute(); } catch (Exception $e) {}

    // Validations de présence des membres (Dahira / Guddi Àjjuma publiés)
    $pdo->exec("CREATE TABLE IF NOT EXISTS presence_validations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        planning_type VARCHAR(20) NOT NULL,
        planning_id INT NOT NULL,
        membre_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_presence (planning_type, planning_id, membre_id)
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
