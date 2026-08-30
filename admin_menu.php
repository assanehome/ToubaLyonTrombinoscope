<?php
/**
 * Touba Lyon 2026 - Menu latéral d'administration (réutilisable)
 * Inspiré du menu du projet Daara : sidebar sticky à gauche, surlignage de la
 * page active, et bouton toggle sur mobile (affiche la section active + ☰).
 * À inclure comme 1er enfant de <div class="dashboard-layout"> sur les pages admin.
 */
$__cur = basename($_SERVER['PHP_SELF']);
if (!isset($adhesionsCount)) {
    try {
        $adhesionsCount = (int) $pdo->query("SELECT COUNT(*) FROM membres WHERE type_adhesion IS NOT NULL")->fetchColumn();
    } catch (Exception $e) {
        $adhesionsCount = 0;
    }
}
$__adminMenu = [
    'admin_dashboard.php'        => '🏠 Tableau de bord',
    'admin_dashboard.php#trombi' => '🖼️ Trombinoscope',
    'admin_adhesions.php'        => '📝 Membres (' . $adhesionsCount . ')',
    'admin_reponses.php'         => '📊 Suivi intégration',
    'admin_integrateurs.php' => '👤 Intégrateurs',
    'kourel_dashboard.php'   => '🎵 Kurels',
    'admin_commissions.php'  => '📋 Commissions',
    'commission_dashboard.php' => '📋 Espaces commissions',
    'admin_planning.php'     => '📅 Planning Dahira',
    'admin_guddi.php'        => '💎 Guddi Àjjuma',
    'wird_admin.php'         => '📖 Lecture Coran',
    'admin_admins.php'       => '👥 Gérer les admins',
];
// Vue d'ensemble du stock par commission (si au moins une commission a le stock activé)
try {
    $__stN = (int) $pdo->query("SELECT COUNT(*) FROM commissions WHERE COALESCE(stock_enabled,0) = 1")->fetchColumn();
    if ($__stN > 0) { $__adminMenu['stock_index.php'] = '📦 Stock'; }
} catch (Exception $e) {
    // colonne/table absente : pas de lien stock
}
$__activeLabel = 'Menu';
foreach ($__adminMenu as $__href => $__label) {
    if ($__cur === $__href) { $__activeLabel = $__label; break; }
}
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
