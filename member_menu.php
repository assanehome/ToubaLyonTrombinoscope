<?php
/**
 * Touba Lyon 2026 - Menu latéral pour l'espace MEMBRE (rôles).
 * Analogue à admin_menu.php mais pour un membre connecté : n'affiche que les
 * espaces auxquels il a accès (profil, suivi intégration, Kurels, commissions).
 * À inclure comme 1er enfant de <div class="dashboard-layout"> sur les dashboards membre.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$__cur = basename($_SERVER['PHP_SELF']);
$__isInteg  = !empty($_SESSION['is_integrateur']);
$__isKourel = !empty($_SESSION['is_gestion_kourel']);
$__isCom    = !empty($_SESSION['is_gestion_commission']);

$__memberMenu = [
    'index.php'   => '🖼️ Trombinoscope',
    'profile.php' => '👤 Mon profil',
];
if (!empty($_SESSION['is_suivi_integration'])) {
    // Responsable de la commission Intégration : suivi complet (portée admin).
    $__memberMenu['admin_reponses.php'] = '📊 Suivi intégration Admin';
    $__memberMenu['admin_integrateurs.php'] = '👤 Gérer les intégrateurs';
} elseif ($__isInteg) {
    // Intégrateur : suivi limité à ses inscrits assignés.
    $__memberMenu['admin_reponses.php'] = '📊 Suivi intégration';
}
if ($__isKourel) {
    $__memberMenu['kourel_dashboard.php'] = '🎵 Kurels';
}
if ($__isCom) {
    $__memberMenu['commission_dashboard.php'] = '📋 Mes commissions';
    // Vue d'ensemble du stock par commission (si au moins une commission gérée a le stock activé)
    if (isset($pdo) && !empty($_SESSION['player_id'])) {
        try {
            $__stN = (int) $pdo->query("SELECT COUNT(*) FROM commissions c JOIN commission_gestionnaires cg ON cg.commission_id = c.id WHERE cg.membre_id = " . (int) $_SESSION['player_id'] . " AND COALESCE(c.stock_enabled,0) = 1")->fetchColumn();
        } catch (Exception $e) {
            $__stN = 0;
        }
        if ($__stN > 0) { $__memberMenu['stock_index.php'] = '📦 Stock'; }
        // Lecture du Coran (responsables de la commission « Culte »)
        try {
            $__culteN = (int) $pdo->query("SELECT COUNT(*) FROM commissions c JOIN commission_gestionnaires cg ON cg.commission_id = c.id WHERE cg.membre_id = " . (int) $_SESSION['player_id'] . " AND LOWER(c.nom) LIKE '%culte%'")->fetchColumn();
        } catch (Exception $e) {
            $__culteN = 0;
        }
        if ($__culteN > 0) {
            $__memberMenu['wird_admin.php'] = '📖 Lecture Coran';
            $__memberMenu['admin_guddi.php'] = '💎 Guddi Àjjuma';
        }
        // Planning hebdomadaire du Dahira (responsables de la commission « Secrétariat Général »)
        try {
            $__secreN = (int) $pdo->query("SELECT COUNT(*) FROM commissions c JOIN commission_gestionnaires cg ON cg.commission_id = c.id WHERE cg.membre_id = " . (int) $_SESSION['player_id'] . " AND (LOWER(c.nom) LIKE '%secrétariat général%' OR LOWER(c.nom) LIKE '%secretariat general%')")->fetchColumn();
        } catch (Exception $e) {
            $__secreN = 0;
        }
        if ($__secreN > 0) { $__memberMenu['admin_planning.php'] = '📅 Planning Dahira'; }
    }
}

$__activeLabel = 'Menu';
foreach ($__memberMenu as $__href => $__label) {
    if ($__cur === $__href) { $__activeLabel = $__label; break; }
}
?>
<aside class="admin-sidebar">
    <div class="admin-sidebar-user">Espace Membre<br><strong class="gold-text"><?php echo htmlspecialchars($_SESSION['player_name'] ?? 'Membre'); ?></strong></div>
    <button type="button" class="admin-menu-toggle" onclick="document.getElementById('adminMenuLinks').classList.toggle('open')">
        <span><?php echo htmlspecialchars($__activeLabel); ?></span>
        <span>☰</span>
    </button>
    <nav class="admin-menu-links" id="adminMenuLinks">
        <?php foreach ($__memberMenu as $__href => $__label): ?>
            <a href="<?php echo $__href; ?>"<?php echo ($__cur === $__href) ? ' class="active"' : ''; ?>><?php echo $__label; ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
