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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des commissions - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>.comm-wrap { max-width: 640px; margin: 2rem auto; }</style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/admin_menu.php'; ?>
            <div class="dashboard-main">
        <div class="comm-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <span>Commissions — <strong class="gold-text"><?php echo count($commissions); ?></strong></span>
                <a href="admin_dashboard.php" class="btn btn-secondary btn-sm">← Tableau de bord</a>
            </div>

            <div class="form-card" style="max-width:none; margin-bottom:1.5rem;">
                <h2 class="gold-text" style="font-size:1.2rem; font-weight:700; margin-bottom:1rem;">Ajouter une commission</h2>
                <form action="admin_commissions.php" method="POST" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:flex-end;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <div class="form-group" style="flex:1; min-width:200px; margin-bottom:0;">
                        <label for="nom" class="form-label">Nom de la commission <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="nom" name="nom" class="form-input" placeholder="Ex: Sécurité" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Ajouter</button>
                </form>
            </div>

            <section class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title">Commissions existantes</h2>
                    <span class="badge badge-approved"><?php echo count($commissions); ?></span>
                </div>
                <?php if (empty($commissions)): ?>
                    <div class="empty-state"><div class="empty-state-icon">📋</div><p>Aucune commission.</p></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="admin-table admin-table--compact">
                            <thead><tr><th>Commission</th><th>Gestion de stock</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($commissions as $c): ?>
                                    <?php $stockOn = ((int)$c['stock_enabled'] === 1); ?>
                                    <tr>
                                        <td style="font-weight:600;"><?php echo htmlspecialchars($c['nom']); ?></td>
                                        <td>
                                            <form action="admin_commissions.php" method="POST" style="margin:0; display:inline-flex; align-items:center; gap:0.5rem;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_stock">
                                                <input type="hidden" name="commission_id" value="<?php echo (int)$c['id']; ?>">
                                                <input type="hidden" name="enable" value="<?php echo $stockOn ? 0 : 1; ?>">
                                                <?php if ($stockOn): ?>
                                                    <span class="badge badge-approved" style="white-space:nowrap;">📦 Activée</span>
                                                    <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">Désactiver</button>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted);">—</span>
                                                    <button type="submit" class="btn btn-primary btn-sm">Activer</button>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="confirmDelete(<?php echo (int)$c['id']; ?>, '<?php echo addslashes(htmlspecialchars($c['nom'])); ?>')">Supprimer</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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

    <!-- Confirmation de suppression (modale moderne) -->
    <div id="confirm-modal" class="modal-overlay">
        <div class="modal-card glass-card">
            <div class="modal-header"><h3 style="color:var(--danger);">⚠️ Suppression définitive</h3></div>
            <div class="modal-body"><p id="confirm-message">Voulez-vous supprimer cette commission ?</p></div>
            <div class="modal-footer">
                <button type="button" id="confirm-cancel" class="btn btn-secondary btn-sm">Annuler</button>
                <button type="button" id="confirm-ok" class="btn btn-danger btn-sm">Supprimer</button>
            </div>
        </div>
    </div>

    <form id="delete-form" action="admin_commissions.php" method="POST" style="display:none;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="commission_id" id="delete-id" value="">
    </form>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>

    <script>
        var confirmModal = document.getElementById('confirm-modal');
        function confirmDelete(id, nom) {
            document.getElementById('delete-id').value = id;
            document.getElementById('confirm-message').innerHTML = 'Voulez-vous vraiment supprimer la commission <strong>' + nom + '</strong> ? Cette action est définitive.';
            confirmModal.style.display = 'flex';
            setTimeout(function () { confirmModal.classList.add('active'); }, 10);
        }
        function closeConfirm() {
            confirmModal.classList.remove('active');
            setTimeout(function () { confirmModal.style.display = 'none'; }, 300);
        }
        document.getElementById('confirm-cancel').addEventListener('click', closeConfirm);
        document.getElementById('confirm-ok').addEventListener('click', function () {
            document.getElementById('delete-form').submit();
        });
        confirmModal.addEventListener('click', function (e) { if (e.target === this) closeConfirm(); });
    </script>
</body>
</html>
