<?php
/**
 * Touba Lyon 2026 - Sections du menu d'administration
 *
 * Décrites ici, et pas dans le gabarit, parce que deux endroits les affichent :
 * la barre latérale (admin_menu.php) sur grand écran, et le tiroir de l'en-tête
 * (header.php) sur téléphone, où cette barre disparaît. Un seul endroit à
 * corriger, et les deux menus ne peuvent plus diverger.
 *
 * Repris du projet Daara, où les trois espaces suivent déjà cette règle.
 *
 * @return array<string,string> href => libellé, dans l'ordre d'affichage
 */
function troba_admin_menu_items(?PDO $pdo = null): array
{
    // Nombre d'adhésions pour le badge du menu
    $adhesions = 0;
    if ($pdo instanceof PDO) {
        try {
            $adhesions = (int)$pdo->query(
                "SELECT COUNT(*) FROM membres WHERE type_adhesion IS NOT NULL"
            )->fetchColumn();
        } catch (Throwable $e) {
            $adhesions = 0;   // badge indisponible : le menu reste utilisable
        }
    }

    // Modifications de profil en attente de validation (badge du menu)
    $modifs = 0;
    if ($pdo instanceof PDO) {
        try { $modifs = (int) $pdo->query("SELECT COUNT(*) FROM membre_modifications WHERE statut = 'attente'")->fetchColumn(); }
        catch (Throwable $e) { $modifs = 0; }
    }

    // Le lien Stock est proposé si au moins une commission l'a activé
    $stockOn = false;
    if ($pdo instanceof PDO) {
        try {
            $stockOn = ((int)$pdo->query("SELECT COUNT(*) FROM commissions WHERE COALESCE(stock_enabled,0) = 1")->fetchColumn()) > 0;
        } catch (Throwable $e) { $stockOn = false; }
    }

    $items = [
        'admin_dashboard.php'        => '🏠 Tableau de bord',
        'admin_dashboard.php#trombi' => '🖼️ Trombinoscope',
        'actualites.php'             => '📰 Actualités',
        'admin_adhesions.php'        => '📝 Membres (' . $adhesions . ')',
        'admin_modifications.php'    => '✏️ Modifications profil (' . $modifs . ')',
        'admin_reponses.php'         => '📊 Suivi intégration',
        'admin_integrateurs.php'     => '👤 Intégrateurs',
        'kourel_dashboard.php'       => '🎵 Kurels',
        'admin_commissions.php'      => '📋 Commissions',
        'commission_dashboard.php'   => '📋 Espaces commissions',
        'admin_planning.php'         => '📅 Planning Dahira',
    ];
    // Stock juste après le Planning
    if ($stockOn) { $items['stock_index.php'] = '📦 Stock'; }
    $items['admin_guddi.php']  = '💎 Guddi Àjjuma';
    $items['wird_admin.php']   = '📖 Lecture Coran';
    $items['magal.php']        = '🕌 Wadial Magal';
    $items['logistique_demandes.php'] = '🚚 Logistique';
    $items['admin_cotisations.php'] = '💶 Cotisations';
    $items['admin_admins.php'] = '👥 Gérer les admins';
    $items['admin_parametres.php'] = '⚙️ Paramètres';

    return $items;
}

/** Libellé de la section en cours, ou « Menu » si aucune ne correspond. */
function troba_admin_menu_active_label(array $items, string $current_page): string
{
    foreach ($items as $href => $label) {
        if ($current_page === $href) {
            return $label;
        }
    }
    return 'Menu';
}
