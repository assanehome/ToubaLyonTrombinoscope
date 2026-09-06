<?php
/**
 * Touba Lyon 2026 - Menu latéral pour l'espace MEMBRE (rôles).
 * Analogue à admin_menu.php : n'affiche que les espaces auxquels le membre a
 * accès. Les entrées sont décrites dans member_menu_data.php (partagées avec
 * le tiroir de l'en-tête sur mobile — voir header.php).
 * À inclure comme 1er enfant de <div class="dashboard-layout"> sur les dashboards membre.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/member_menu_data.php';

$__cur = basename($_SERVER['PHP_SELF']);
$__memberMenu = troba_member_menu_items($pdo ?? null);
$__activeLabel = troba_member_menu_active_label($__memberMenu, $__cur);
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
