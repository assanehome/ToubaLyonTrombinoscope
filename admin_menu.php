<?php
/**
 * Touba Lyon 2026 - Menu latéral d'administration (réutilisable)
 * Inspiré du menu du projet Daara : sidebar sticky à gauche, surlignage de la
 * page active, et bouton toggle sur mobile (affiche la section active + ☰).
 * À inclure comme 1er enfant de <div class="dashboard-layout"> sur les pages admin.
 */
require_once __DIR__ . '/admin_menu_data.php';

$__cur = basename($_SERVER['PHP_SELF']);
$__adminMenu = troba_admin_menu_items($pdo ?? null);
$__activeLabel = troba_admin_menu_active_label($__adminMenu, $__cur);
?>
<aside class="admin-sidebar">
    <div class="admin-sidebar-user">Espace Administration<br><strong class="gold-text"><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></strong></div>
    <button type="button" class="admin-menu-toggle" onclick="document.getElementById('adminMenuLinks').classList.toggle('open')">
        <span><?php echo htmlspecialchars($__activeLabel); ?></span>
        <span>☰</span>
    </button>
    <nav class="admin-menu-links" id="adminMenuLinks">
        <?php foreach ($__adminMenu as $__href => $__label): ?>
            <a href="<?php echo $__href; ?>"<?php echo ($__cur === $__href) ? ' class="active"' : ''; ?>><?php echo $__label; ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
<?php /* Sur mobile, les mêmes sections sont rendues côté serveur dans le tiroir
         de l'en-tête (header.php → #hdrNavAdmin) : plus besoin de clone JS. */ ?>
