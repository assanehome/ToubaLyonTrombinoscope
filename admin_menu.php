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
<?php
// Membre administrateur : groupe « Espace membre » (retour à l'espace membre) placé tout en haut du contenu,
// à la même position que « Mode administration » sur l'accueil membre.
$__switchMembre = troba_admin_switch_member_html();
if ($__switchMembre !== ''): ?>
<template id="adm-switch-tpl"><?php echo $__switchMembre; ?></template>
<script>
    (function () {
        function placer() {
            var t = document.getElementById('adm-switch-tpl');
            if (!t || document.getElementById('adm-switch-groupe')) { return; }
            var asd = document.querySelector('.admin-sidebar');
            var hote = asd && asd.parentElement ? asd.parentElement.querySelector(':scope > .dashboard-main, :scope > .admin-content') : null;
            if (!hote) { hote = document.querySelector('main .dashboard-main, main .admin-content'); }
            if (!hote) { return; }
            var frag = t.content.cloneNode(true);
            var det = frag.querySelector('details');
            if (det) { det.id = 'adm-switch-groupe'; }
            hote.insertBefore(frag, hote.firstChild);
        }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', placer); } else { placer(); }
    })();
</script>
<?php endif; ?>
<?php /* Sur mobile, les mêmes sections sont rendues côté serveur dans le tiroir
         de l'en-tête (header.php → #hdrNavAdmin) : plus besoin de clone JS. */ ?>
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
