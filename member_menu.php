<?php
/**
 * Touba Lyon 2026 - Menu latéral pour l'espace MEMBRE (rôles).
 * Analogue à admin_menu.php : n'affiche que les espaces auxquels le membre a
 * accès. Les entrées sont décrites dans member_menu_data.php (partagées avec
 * le tiroir de l'en-tête sur mobile — voir header.php).
 * À inclure comme 1er enfant de <div class="dashboard-layout"> sur les dashboards membre.
 */
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
// Session administrateur (y compris un membre administrateur qui bascule côté membre) : uniquement les menus d'administration, jamais ceux des membres
if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    include __DIR__ . '/admin_menu.php';
    return;
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
            <?php if ($__href[0] === '#'): ?><div class="admin-menu-sep"><?php echo $__label; ?></div><?php continue; endif; ?>
            <a href="<?php echo $__href; ?>"<?php echo ($__cur === $__href) ? ' class="active"' : ''; ?>><?php echo $__label; ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
<script>
    // La molette doit défiler la LISTE du menu, pas la page, tant qu'il reste
    // du contenu à faire défiler dans le menu (chaînage naturel une fois en bout de liste).
    (function () {
        var nav = document.getElementById('adminMenuLinks');
        if (!nav) { return; }
        nav.addEventListener('wheel', function (e) {
            if (nav.scrollHeight <= nav.clientHeight) { return; } // rien à défiler ici
            var atTop = nav.scrollTop <= 0;
            var atBottom = nav.scrollTop + nav.clientHeight >= nav.scrollHeight - 1;
            if ((e.deltaY < 0 && atTop) || (e.deltaY > 0 && atBottom)) { return; }
            nav.scrollTop += e.deltaY;
            e.preventDefault();
        }, { passive: false });
    })();
</script>
