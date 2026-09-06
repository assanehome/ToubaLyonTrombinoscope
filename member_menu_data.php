<?php
/**
 * Touba Lyon 2026 - Sections du menu de l'espace MEMBRE
 *
 * Comme admin_menu_data.php : décrites ici parce que deux endroits les
 * affichent — la barre latérale (member_menu.php) sur grand écran, et le
 * tiroir de l'en-tête (header.php) sur téléphone, où cette barre disparaît.
 * Un seul endroit à corriger, les deux menus ne peuvent plus diverger.
 *
 * @return array<string,string> href => libellé, dans l'ordre d'affichage
 */
function troba_member_menu_items(?PDO $pdo = null): array
{
    // Menu de base d'un membre simple. Les points liés à un rôle / une
    // responsabilité sont désormais regroupés dans le « Mode gestion » de
    // l'accueil (voir troba_member_gestion_items).
    return [
        'index.php'              => '🏠 Espace membre',
        'trombinoscope.php'      => '🖼️ Trombinoscope',
        'profile.php'            => '👤 Mon profil',
        'notifications_page.php' => '🔔 Notifications',
        'kikanla.php'            => '🎮 Jouer à Ki Kan La',
    ];
}

/**
 * Points de menu « gestion » selon les rôles / responsabilités du membre
 * (intégrateur, responsable de commission, de Kurel, Culte, Secrétariat…).
 * Vides pour un membre simple. Utilisés par le bloc « Mode gestion » de l'accueil.
 *
 * @return array<string,string> href => libellé
 */
function troba_member_gestion_items(?PDO $pdo = null): array
{
    $isInteg  = !empty($_SESSION['is_integrateur']);
    $isKourel = !empty($_SESSION['is_gestion_kourel']);
    $isCom    = !empty($_SESSION['is_gestion_commission']);
    $items = [];

    if (!empty($_SESSION['is_suivi_integration'])) {
        // Responsable de la commission Intégration : suivi complet (portée admin).
        $items['admin_reponses.php']     = '📊 Suivi intégration Admin';
        $items['admin_integrateurs.php'] = '👤 Gérer les intégrateurs';
    } elseif ($isInteg) {
        // Intégrateur : suivi limité à ses inscrits assignés.
        $items['admin_reponses.php'] = '📊 Suivi intégration';
    }
    if ($isKourel) {
        $items['kourel_dashboard.php'] = '🎵 Kurels';
    }
    if ($isCom) {
        $items['commission_dashboard.php'] = '📋 Mes commissions';
        if ($pdo instanceof PDO && !empty($_SESSION['player_id'])) {
            $pid = (int) $_SESSION['player_id'];
            $stockOn = false;
            try {
                $stockOn = ((int) $pdo->query("SELECT COUNT(*) FROM commissions c JOIN commission_gestionnaires cg ON cg.commission_id = c.id WHERE cg.membre_id = $pid AND COALESCE(c.stock_enabled,0) = 1")->fetchColumn()) > 0;
            } catch (Throwable $e) { $stockOn = false; }
            try {
                $culteN = (int) $pdo->query("SELECT COUNT(*) FROM commissions c JOIN commission_gestionnaires cg ON cg.commission_id = c.id WHERE cg.membre_id = $pid AND LOWER(c.nom) LIKE '%culte%'")->fetchColumn();
                if ($culteN > 0) {
                    $items['wird_admin.php'] = '📖 Lecture Coran';
                    $items['admin_guddi.php'] = '💎 Guddi Àjjuma';
                }
            } catch (Throwable $e) {}
            try {
                $secreN = (int) $pdo->query("SELECT COUNT(*) FROM commissions c JOIN commission_gestionnaires cg ON cg.commission_id = c.id WHERE cg.membre_id = $pid AND (LOWER(c.nom) LIKE '%secrétariat général%' OR LOWER(c.nom) LIKE '%secretariat general%')")->fetchColumn();
                if ($secreN > 0) { $items['admin_planning.php'] = '📅 Planning Dahira'; }
            } catch (Throwable $e) {}
            if ($stockOn) { $items['stock_index.php'] = '📦 Stock'; }
        }
    }
    return $items;
}

/** Libellé de la section en cours, ou « Menu » si aucune ne correspond. */
function troba_member_menu_active_label(array $items, string $current_page): string
{
    foreach ($items as $href => $label) {
        if ($current_page === $href) {
            return $label;
        }
    }
    return 'Menu';
}
