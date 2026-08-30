<?php
/**
 * Touba Lyon 2026 - Vue d'ensemble du stock par commission.
 *
 * Liste les commissions (dont la gestion de stock est activée) que l'utilisateur
 * peut gérer, avec un accès au stock de chacune.
 */
require_once __DIR__ . '/commission_guard.php'; // $__isAdmin, $__managedCommissions, $pdo

try {
    if ($__isAdmin) {
        $commissions = $pdo->query("SELECT id, nom FROM commissions WHERE COALESCE(stock_enabled,0) = 1 ORDER BY nom ASC")->fetchAll();
    } elseif (!empty($__managedCommissions)) {
        $in = implode(',', array_fill(0, count($__managedCommissions), '?'));
        $stmt = $pdo->prepare("SELECT id, nom FROM commissions WHERE id IN ($in) AND COALESCE(stock_enabled,0) = 1 ORDER BY nom ASC");
        $stmt->execute($__managedCommissions);
        $commissions = $stmt->fetchAll();
    } else {
        $commissions = [];
    }
    // Statistiques stock par commission
    $counts = [];
    try {
        $rows = $pdo->query("SELECT commission_id, COUNT(*) AS nb, COALESCE(SUM(quantite),0) AS qte FROM commission_stock GROUP BY commission_id");
        foreach ($rows as $r) { $counts[(int)$r['commission_id']] = ['nb' => (int)$r['nb'], 'qte' => (int)$r['qte']]; }
    } catch (Exception $e) {
        $counts = [];
    }
} catch (Exception $e) {
    error_log('Touba Lyon stock_index (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock par commission - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .stk-wrap { max-width: 900px; margin: 2rem auto; }
        .stk-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
        .stk-card { border-radius: 16px; padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: 0.75rem; text-decoration: none; }
        .stk-card:hover { border-color: rgba(212,175,55,0.5); }
        .stk-card h3 { margin: 0; color: var(--white); font-size: 1.15rem; }
        .stk-meta { color: var(--text-muted); font-size: 0.85rem; }
        .stk-open { align-self: flex-start; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">

        <div class="stk-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <span>📦 Stock par commission — <strong class="gold-text"><?php echo count($commissions); ?></strong> commission(s)</span>
                <?php if ($__isAdmin): ?>
                    <a href="admin_dashboard.php" class="btn btn-secondary btn-sm">← Tableau de bord</a>
                <?php else: ?>
                    <a href="commission_dashboard.php" class="btn btn-secondary btn-sm">← Espaces commissions</a>
                <?php endif; ?>
            </div>

            <?php if (empty($commissions)): ?>
                <div class="empty-state"><div class="empty-state-icon">📦</div><p>Aucune commission avec gestion de stock activée<?php echo $__isAdmin ? ' (activez-la dans « Commissions »).' : '.'; ?></p></div>
            <?php else: ?>
                <div class="stk-grid">
                    <?php foreach ($commissions as $c): ?>
                        <?php $st = $counts[(int)$c['id']] ?? ['nb' => 0, 'qte' => 0]; ?>
                        <a class="glass-card stk-card" href="commission_stock.php?id=<?php echo (int)$c['id']; ?>">
                            <h3>📦 <?php echo htmlspecialchars($c['nom']); ?></h3>
                            <div class="stk-meta"><?php echo (int)$st['nb']; ?> article(s) · <?php echo (int)$st['qte']; ?> en stock</div>
                            <span class="btn btn-primary btn-sm stk-open">Voir le stock →</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

            </div>
        </div>
    </main>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
</body>
</html>
