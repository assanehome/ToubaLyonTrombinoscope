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
        'admin_dashboard.php'        => '🏠 Tableau de bord' . ($modifs > 0 ? ' (' . $modifs . ')' : ''),
        'admin_dashboard.php#trombi' => '🖼️ Trombinoscope',
        'actualites.php'             => '📰 Actualités',
        'admin_adhesions.php'        => '📝 Membres (' . $adhesions . ')',
        'admin_reponses.php'         => '📊 Suivi intégration',
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
    $items['admin_cotisations.php'] = '💶 Finance';
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


/**
 * Bouton « ↩ Revenir à l'espace membre » : bascule de l'espace administration vers l'espace membre (même session).
 * Affiché sur toutes les pages d'administration, uniquement pour un membre administrateur (session ouverte depuis un compte membre).
 */
function troba_admin_switch_member_html(string $classe = ''): string
{
    if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_is_member']) || empty($_SESSION['player_id'])) { return ''; }
    require_once __DIR__ . '/csrf.php';
    // Même groupe, même carte et même bouton que « Mode administration » de l'accueil membre (style.css : .adm-grp / .adm-card)
    return '<details class="adm-grp' . ($classe !== '' ? ' ' . htmlspecialchars($classe, ENT_QUOTES) : '') . '">'
        . '<summary><span class="grp-ic" aria-hidden="true">🏠</span> Espace membre</summary>'
        . '<div class="adm-card">'
        . '<div class="adm-card-ic" aria-hidden="true">🏠</div>'
        . '<div class="adm-card-txt"><strong>Espace membre</strong><span>Vous êtes aussi membre du Dahira. Votre espace d\'administration reste ici.</span></div>'
        . '<form action="basculer_admin.php" method="POST" class="adm-card-form">'
        . csrf_field()
        . '<input type="hidden" name="vers" value="membre">'
        . '<button type="submit" class="adm-card-btn adm-card-btn--retour"><span aria-hidden="true">↩</span> Revenir à l\'espace membre</button>'
        . '</form>'
        . '</div>'
        . '</details>';
}
