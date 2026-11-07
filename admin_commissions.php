<?php
/**
 * Touba Lyon 2026 - Gestion des commissions
 * Réservé aux administrateurs connectés.
 */
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $nom = trim($_POST['nom'] ?? '');
            if ($nom === '') {
                $error = "Le nom de la commission est obligatoire.";
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT id FROM commissions WHERE nom = ?");
                    $stmt->execute([$nom]);
                    if ($stmt->fetch()) {
                        $error = "Cette commission existe déjà.";
                    } else {
                        $pdo->prepare("INSERT INTO commissions (nom) VALUES (?)")->execute([$nom]);
                        $success = "Commission « " . htmlspecialchars($nom) . " » ajoutée.";
                    }
                } catch (Exception $e) {
                    error_log('Touba Lyon admin_commissions (add): ' . $e->getMessage());
                    $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
                }
            }
        } elseif ($action === 'delete') {
            $id = intval($_POST['commission_id'] ?? 0);
            try {
                // Interdire la suppression si la commission a des membres ou des responsables.
                $nbMembres = 0;
                $nbResp = 0;
                try {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM commission_membres WHERE commission_id = ?");
                    $st->execute([$id]);
                    $nbMembres = (int) $st->fetchColumn();
                    $st2 = $pdo->prepare("SELECT COUNT(*) FROM commission_gestionnaires WHERE commission_id = ?");
                    $st2->execute([$id]);
                    $nbResp = (int) $st2->fetchColumn();
                } catch (Exception $e) {
                    // tables absentes : on considère 0
                }
                if ($nbMembres > 0 || $nbResp > 0) {
                    $parts = [];
                    if ($nbResp > 0) { $parts[] = $nbResp . ' responsable(s)'; }
                    if ($nbMembres > 0) { $parts[] = $nbMembres . ' membre(s)'; }
                    $error = "Impossible de supprimer cette commission : elle contient encore " . implode(' et ', $parts) . ". Retirez-les d'abord dans « Espaces commissions ».";
                } else {
                    $pdo->prepare("DELETE FROM sous_commission_membres WHERE sous_commission_id IN (SELECT id FROM sous_commissions WHERE commission_id = ?)")->execute([$id]);
                    $pdo->prepare("DELETE FROM sous_commissions WHERE commission_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM commissions WHERE id = ?")->execute([$id]);
                    $success = "Commission supprimée.";
                }
            } catch (Exception $e) {
                error_log('Touba Lyon admin_commissions (delete): ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        } elseif ($action === 'toggle_stock') {
            $id = intval($_POST['commission_id'] ?? 0);
            $val = intval($_POST['enable'] ?? 0) === 1 ? 1 : 0;
            try {
                $pdo->prepare("UPDATE commissions SET stock_enabled = ? WHERE id = ?")->execute([$val, $id]);
                $success = $val ? "Gestion de stock activée pour cette commission." : "Gestion de stock désactivée.";
            } catch (Exception $e) {
                error_log('Touba Lyon admin_commissions (toggle stock): ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
}

try {
    $commissions = $pdo->query("SELECT id, nom, created_at, COALESCE(stock_enabled,0) AS stock_enabled FROM commissions ORDER BY nom ASC")->fetchAll();
} catch (Exception $e) {
    error_log('Touba Lyon admin_commissions (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

// Effectifs et responsables de chaque commission (pour l'affichage)
$nbMembres = []; $responsables = [];
try {
    foreach ($pdo->query("SELECT commission_id, COUNT(*) AS n FROM commission_membres GROUP BY commission_id") as $r) { $nbMembres[(int) $r['commission_id']] = (int) $r['n']; }
    foreach ($pdo->query("SELECT cg.commission_id, TRIM(CONCAT(m.prenom, ' ', m.nom)) AS nom FROM commission_gestionnaires cg JOIN membres m ON m.id = cg.membre_id ORDER BY m.prenom, m.nom") as $r) { $responsables[(int) $r['commission_id']][] = $r['nom']; }
} catch (Exception $e) { /* affichage sans effectifs */ }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des commissions - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .comm-wrap { max-width: 860px; margin: 0 auto; }
        .cm-item { display: block; border: 1px solid rgba(212,175,55,0.38); border-radius: 12px; background: rgba(255,255,255,0.03); padding: 0.9rem 1rem; margin-bottom: 0.6rem; box-shadow: 0 2px 10px rgba(0,0,0,0.16); }
        .cm-tete { display: flex; align-items: center; gap: 0.8rem; }
        .cm-ic { flex: 0 0 auto; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; background: rgba(212,175,55,0.14); border: 1px solid rgba(212,175,55,0.4); }
        .cm-nom { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; color: var(--white); font-weight: 800; font-size: 1.02rem; line-height: 1.25; }
        .cm-nom small { color: var(--text-muted); font-weight: 500; font-size: 0.78rem; margin-top: 0.15rem; }
        .cm-pied { display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap; margin-top: 0.7rem; padding-top: 0.7rem; border-top: 1px solid rgba(255,255,255,0.08); }
        .cm-stock { display: flex; align-items: center; gap: 0.7rem; margin: 0; cursor: pointer; color: var(--white); font-size: 0.9rem; }
        .cm-stock small { display: block; color: var(--text-muted); font-size: 0.76rem; }
        .cm-badges { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/admin_menu.php'; ?>
            <div class="dashboard-main">
        <div class="comm-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:1rem;">
                <span>Commissions — <strong class="gold-text"><?php echo count($commissions); ?></strong></span>
                <a href="admin_dashboard.php" class="btn btn-secondary btn-sm">← Tableau de bord</a>
            </div>

            <details class="glass-card" style="margin-bottom:1.2rem;">
                <summary>➕ Ajouter une commission</summary>
                <form action="admin_commissions.php" method="POST" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end; margin-top:0.4rem;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <div class="form-group" style="flex:1; min-width:200px; margin-bottom:0;">
                        <label for="nom" class="form-label">Nom de la commission <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="nom" name="nom" class="form-input" placeholder="Ex : Sécurité" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Ajouter</button>
                </form>
            </details>

            <section class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title">Commissions existantes</h2>
                    <span class="badge badge-approved"><?php echo count($commissions); ?></span>
                </div>
                <?php if (empty($commissions)): ?>
                    <div class="empty-state"><div class="empty-state-icon">📋</div><p>Aucune commission.</p></div>
                <?php else: ?>
                    <?php foreach ($commissions as $c): ?>
                        <?php
                            $cid = (int) $c['id'];
                            $stockOn = ((int) $c['stock_enabled'] === 1);
                            $nbM = $nbMembres[$cid] ?? 0;
                            $resp = $responsables[$cid] ?? [];
                        ?>
                        <div class="cm-item">
                            <div class="cm-tete">
                                <span class="cm-ic" aria-hidden="true">📋</span>
                                <span class="cm-nom"><?php echo htmlspecialchars($c['nom']); ?>
                                    <small><?php echo $nbM; ?> membre<?php echo $nbM > 1 ? 's' : ''; ?><?php echo !empty($resp) ? ' · 🎖️ ' . htmlspecialchars(implode(', ', $resp)) : ' · aucun responsable'; ?></small>
                                </span>
                            </div>
                            <div class="cm-pied">
                                <form action="admin_commissions.php" method="POST" class="cm-stock">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_stock">
                                    <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                                    <label style="display:flex; align-items:center; gap:0.7rem; margin:0; cursor:pointer;">
                                        <input type="checkbox" class="checkbox-switch" name="enable" value="1"<?php echo $stockOn ? ' checked' : ''; ?> onchange="this.form.submit()">
                                        <span>📦 Gestion de stock<small><?php echo $stockOn ? 'Activée pour cette commission' : 'Désactivée'; ?></small></span>
                                    </label>
                                </form>
                                <form action="admin_commissions.php" method="POST" style="margin:0;" data-confirm="Voulez-vous vraiment supprimer la commission <strong><?php echo htmlspecialchars($c['nom']); ?></strong> ? Cette action est définitive." data-confirm-danger="1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">🗑️ Supprimer</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>
            </div>
        </div>
    </main>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($success)): ?><h3 class="gold-text">Réussi</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?>
            </div>
            <div class="modal-body"><p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/modern_popup.php'; ?>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>

</body>
</html>
