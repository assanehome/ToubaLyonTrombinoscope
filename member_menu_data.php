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
    $menu = [
        'index.php'              => '🏠 Espace membre',
        'trombinoscope.php'      => '🖼️ Trombinoscope',
        'profile.php'            => '👤 Mon profil',
        'cotisation.php'         => '💶 Mes cotisations',
        'guddi_historique.php'   => '💎 Guddi Àjjuma',
        'notifications_page.php' => '🔔 Notifications',
        'kikanla.php'            => '🎮 Jouer à Ki Kan La',
    ];
    // « Sondages » : seulement s'il y a au moins un sondage en cours (publié et non échu)
    $sondageEnCours = false;
    if ($pdo instanceof PDO) {
        try {
            $sondageEnCours = ((int) $pdo->query("SELECT COUNT(*) FROM sondages WHERE statut = 'publie' AND (date_fin IS NULL OR date_fin >= CURDATE())")->fetchColumn()) > 0;
        } catch (Throwable $e) { $sondageEnCours = false; }
    }
    if ($sondageEnCours) {
        $menu = array_slice($menu, 0, 4, true) + ['sondages.php' => '📊 Sondages'] + array_slice($menu, 4, null, true);
    }
    // « Mes commissions » : réservé aux membres d'au moins une commission (membre, responsable ou sous-commission).
    // « Mes disponibilités » : en plus, la commission doit avoir activé les disponibilités.
    if ($pdo instanceof PDO && !empty($_SESSION['player_id'])) {
        $pid = (int) $_SESSION['player_id'];
        $dansCommission = "(c.id IN (SELECT commission_id FROM commission_membres WHERE membre_id = $pid) OR c.id IN (SELECT commission_id FROM commission_gestionnaires WHERE membre_id = $pid) OR c.id IN (SELECT sc.commission_id FROM sous_commission_membres scm JOIN sous_commissions sc ON sc.id = scm.sous_commission_id WHERE scm.membre_id = $pid))";
        $apres = [];
        try {
            if ((int) $pdo->query("SELECT COUNT(*) FROM commissions c WHERE $dansCommission")->fetchColumn() > 0) {
                $apres['mes_commissions.php'] = '📋 Mes commissions';
            }
        } catch (Throwable $e) {}
        try {
            if ((int) $pdo->query("SELECT COUNT(*) FROM commissions c WHERE COALESCE(c.dispo_active, 1) = 1 AND $dansCommission")->fetchColumn() > 0) {
                $apres['disponibilites.php'] = '🗓️ Mes disponibilités';
            }
        } catch (Throwable $e) {}
        if (!empty($apres)) {
            $menu = array_slice($menu, 0, 4, true) + $apres + array_slice($menu, 4, null, true);
        }
    }
    // Points de gestion selon les droits (responsable de commission, Kurel, Culte, Secrétariat, Finance…)
    try {
        $gestion = troba_member_gestion_items($pdo);
        if (!empty($gestion)) {
            $menu['#gestion'] = '🛠️ Gestion'; // intitulé de section (non cliquable)
            foreach ($gestion as $gHref => $gLabel) { $menu[$gHref] = $gLabel; }
        }
    } catch (Throwable $e) { /* menu sans gestion */ }
    return $menu;
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
    if ($pdo instanceof PDO && !empty($_SESSION['player_id'])) {
        try {
            require_once __DIR__ . '/kourel_access.php';
            $isKourel = member_is_kourel_manager($pdo, (int) $_SESSION['player_id']); // responsable désigné des Kurels ou d'un Kurel
        } catch (Throwable $e) { /* repli sur la session */ }
    }
    $isCom    = !empty($_SESSION['is_gestion_commission']);
    // Rôles relus en base (jamais le simple statut d'administrateur, ni un drapeau de session périmé)
    if ($pdo instanceof PDO && !empty($_SESSION['player_id'])) {
        $pidRoles = (int) $_SESSION['player_id'];
        try {
            $isCom = ((int) $pdo->query("SELECT COUNT(*) FROM commission_gestionnaires WHERE membre_id = $pidRoles")->fetchColumn()) > 0;
        } catch (Throwable $e) { /* repli sur la session */ }
        try {
            $isInteg = ((int) $pdo->query("SELECT COUNT(*) FROM membres WHERE id = $pidRoles AND is_integrateur = 1")->fetchColumn()) > 0;
        } catch (Throwable $e) { /* repli sur la session */ }
    }
    $items = [];

    // Responsable désigné du Suivi intégration (sans lien avec une commission)
    $isSuivi = false;
    if ($pdo instanceof PDO && !empty($_SESSION['player_id'])) {
        try {
            require_once __DIR__ . '/fonction_responsables_helper.php';
            $isSuivi = fr_est_responsable($pdo, 'integration', (int) $_SESSION['player_id']);
        } catch (Throwable $e) { $isSuivi = false; }
    }
    if ($isSuivi) {
        // Suivi complet (portée admin) : définition des intégrateurs et des responsables dans la page
        $items['admin_reponses.php'] = '📊 Suivi intégration Admin';
    } elseif ($isInteg) {
        // Intégrateur : suivi limité à ses inscrits assignés.
        $items['admin_reponses.php'] = '📊 Suivi intégration';
    }
    if ($isKourel) {
        $items['kourel_dashboard.php'] = '🎵 Kurels';
    }
    if ($isCom) {
        $items['commission_dashboard.php'] = '🛠️ Gérer mes commissions';
        $items['sondage_gestion.php'] = '📊 Gérer les sondages';
        $items['logistique_demande.php'] = '🚚 Demande de logistique';
        if ($pdo instanceof PDO && !empty($_SESSION['player_id'])) {
            $pid = (int) $_SESSION['player_id'];
            $stockOn = false;
            try {
                $stockOn = ((int) $pdo->query("SELECT COUNT(*) FROM commissions c JOIN commission_gestionnaires cg ON cg.commission_id = c.id WHERE cg.membre_id = $pid AND COALESCE(c.stock_enabled,0) = 1")->fetchColumn()) > 0;
            } catch (Throwable $e) { $stockOn = false; }
            if ($stockOn) { $items['stock_index.php'] = '📦 Stock'; }
        }
    }
    // Fonctions à responsables désignés (Lecture Coran, Guddi, Actualités, Planning, Finance) : plus de lien avec les commissions
    if ($pdo instanceof PDO && !empty($_SESSION['player_id'])) {
        $pidFr = (int) $_SESSION['player_id'];
        try {
            require_once __DIR__ . '/fonction_responsables_helper.php';
            if (fr_est_responsable($pdo, 'coran', $pidFr)) { $items['wird_admin.php'] = '📖 Lecture Coran'; }
            if (fr_est_responsable($pdo, 'guddi', $pidFr)) { $items['admin_guddi.php'] = '💎 Gestion Guddi Àjjuma'; }
            if (fr_est_responsable($pdo, 'planning', $pidFr)) { $items['admin_planning.php'] = '📅 Planning Dahira'; }
            if (fr_est_responsable($pdo, 'actualites', $pidFr)) { $items['actualites.php'] = '📰 Actualités'; }
            if (fr_est_responsable($pdo, 'cotisation', $pidFr)) { $items['admin_cotisations.php'] = '💶 Finance'; }
        } catch (Throwable $e) {}
    }
    // Responsables de la commission Logistique : demandes reçues
    if ($pdo instanceof PDO && !empty($_SESSION['player_id'])) {
        try {
            require_once __DIR__ . '/stock_logistique_helper.php';
            if (logistique_est_responsable($pdo, (int) $_SESSION['player_id'])) { $items['logistique_demandes.php'] = '📋 Demandes de logistique'; }
        } catch (Throwable $e) {}
    }
    // Wadial Magal : organisateurs et membres des commissions impliquées
    if ($pdo instanceof PDO && !empty($_SESSION['player_id'])) {
        try {
            require_once __DIR__ . '/magal_helper.php';
            if (magal_membre_a_acces($pdo, (int) $_SESSION['player_id'])) { $items['magal.php'] = '🕌 Wadial Magal'; }
        } catch (Throwable $e) {}
    }
    // Délégué (création de sessions) ou gestionnaire d'un Kamil : accès à « Lecture Coran »
    // même sans être responsable de la commission Culte.
    if (!isset($items['wird_admin.php']) && $pdo instanceof PDO && !empty($_SESSION['player_id'])) {
        $pid = (int) $_SESSION['player_id'];
        try {
            $kamilN = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM culte_delegues WHERE membre_id = $pid) + (SELECT COUNT(*) FROM quran_session_gestionnaires WHERE membre_id = $pid)")->fetchColumn();
            if ($kamilN > 0) { $items['wird_admin.php'] = '📖 Lecture Coran'; }
        } catch (Throwable $e) {}
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
