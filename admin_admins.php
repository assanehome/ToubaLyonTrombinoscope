<?php
/**
 * Touba Lyon 2026 - Gestion des administrateurs (ajout / liste / suppression)
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
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';

            if ($username === '' || $email === '' || $password === '' || $password_confirm === '') {
                $error = "Tous les champs sont obligatoires.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "L'adresse email n'est pas valide.";
            } elseif (strlen($password) < 6) {
                $error = "Le mot de passe doit contenir au moins 6 caractères.";
            } elseif ($password !== $password_confirm) {
                $error = "Les mots de passe ne correspondent pas.";
            } else {
                try {
                    // Nom d'utilisateur déjà pris ?
                    $stmt = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        $error = "Ce nom d'utilisateur est déjà utilisé.";
                    } else {
                        $hashed = password_hash($password, PASSWORD_DEFAULT);
                        $ins = $pdo->prepare("INSERT INTO admins (username, email, password) VALUES (?, ?, ?)");
                        $ins->execute([$username, $email, $hashed]);
                        $success = "L'administrateur « " . htmlspecialchars($username) . " » a été créé avec succès.";
                    }
                } catch (Exception $e) {
                    error_log('Touba Lyon admin_admins (add): ' . $e->getMessage());
                    $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
                }
            }
        } elseif ($action === 'delete') {
            $targetId = intval($_POST['admin_id'] ?? 0);
            try {
                $total = (int) $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
                if ($targetId === (int) ($_SESSION['admin_id'] ?? 0)) {
                    $error = "Vous ne pouvez pas supprimer votre propre compte.";
                } elseif ($total <= 1) {
                    $error = "Impossible de supprimer le dernier administrateur.";
                } else {
                    $stmt = $pdo->prepare("SELECT username FROM admins WHERE id = ?");
                    $stmt->execute([$targetId]);
                    $target = $stmt->fetch();
                    if ($target) {
                        $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$targetId]);
                        $success = "L'administrateur « " . htmlspecialchars($target['username']) . " » a été supprimé.";
                    } else {
                        $error = "Administrateur introuvable.";
                    }
                }
            } catch (Exception $e) {
                error_log('Touba Lyon admin_admins (delete): ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
}

// Liste des administrateurs
try {
    $admins = $pdo->query("SELECT id, username, email, created_at FROM admins ORDER BY created_at ASC")->fetchAll();
} catch (Exception $e) {
    error_log('Touba Lyon admin_admins (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
$currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des administrateurs - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admins-wrap { max-width: 820px; margin: 2rem auto; }
        .admin-tag-self { font-size: 0.7rem; color: var(--secondary); background: var(--accent); padding: 0.1rem 0.5rem; border-radius: 50px; font-weight: 700; margin-left: 0.5rem; }
        .del-inline { display: none; }
        .del-inline.show { display: inline; }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="admins-wrap">

            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <span>Gestion des administrateurs — <strong class="gold-text"><?php echo count($admins); ?></strong> compte(s)</span>
                <a href="admin_dashboard.php" class="btn btn-secondary btn-sm">← Tableau de bord</a>
            </div>

            <!-- Formulaire d'ajout -->
            <div class="form-card" style="max-width:none; margin-bottom:1.5rem;">
                <h2 class="gold-text" style="font-size:1.25rem; font-weight:700; margin-bottom:1.25rem;">Ajouter un administrateur</h2>
                <form action="admin_admins.php" method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add">
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1rem;">
                        <div class="form-group">
                            <label for="username" class="form-label">Nom d'utilisateur <span style="color:var(--danger)">*</span></label>
                            <input type="text" id="username" name="username" class="form-input" placeholder="Ex: secretaire" required>
                        </div>
                        <div class="form-group">
                            <label for="email" class="form-label">Adresse email <span style="color:var(--danger)">*</span></label>
                            <input type="email" id="email" name="email" class="form-input" placeholder="pour la récupération du mot de passe" required>
                        </div>
                        <div class="form-group">
                            <label for="password" class="form-label">Mot de passe <span style="color:var(--danger)">*</span></label>
                            <input type="password" id="password" name="password" class="form-input" placeholder="Au moins 6 caractères" minlength="6" required>
                        </div>
                        <div class="form-group">
                            <label for="password_confirm" class="form-label">Confirmer <span style="color:var(--danger)">*</span></label>
                            <input type="password" id="password_confirm" name="password_confirm" class="form-input" placeholder="Répéter le mot de passe" minlength="6" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:0.5rem;">Créer l'administrateur</button>
                </form>
            </div>

            <!-- Liste des administrateurs -->
            <section class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title">Administrateurs existants</h2>
                    <span class="badge badge-approved"><?php echo count($admins); ?> compte(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="admin-table admin-table--compact">
                        <thead>
                            <tr>
                                <th>Administrateur</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $a): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:600;">
                                            <?php echo htmlspecialchars($a['username']); ?>
                                            <?php if ((int)$a['id'] === $currentAdminId): ?><span class="admin-tag-self">vous</span><?php endif; ?>
                                        </div>
                                        <div style="font-size:0.8rem; color:var(--text-muted); word-break:break-all;"><?php echo htmlspecialchars($a['email'] ?? '') ?: '— email non défini —'; ?></div>
                                        <div style="font-size:0.78rem; color:var(--text-muted);">Créé le <?php echo date('d/m/Y', strtotime($a['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <?php if ((int)$a['id'] === $currentAdminId): ?>
                                            <span style="color:var(--text-muted); font-size:0.85rem;">—</span>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="this.style.display='none'; document.getElementById('delc-<?php echo $a['id']; ?>').classList.add('show');">Supprimer</button>
                                            <span class="del-inline" id="delc-<?php echo $a['id']; ?>">
                                                <form action="admin_admins.php" method="POST" style="display:inline;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="admin_id" value="<?php echo (int)$a['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Confirmer</button>
                                                </form>
                                                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('delc-<?php echo $a['id']; ?>').classList.remove('show'); this.closest('td').querySelector('button.btn-danger').style.display='inline-flex';">Annuler</button>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($success)): ?>
                    <h3 class="gold-text">Opération réussie</h3>
                <?php else: ?>
                    <h3 style="color:var(--danger);">Erreur</h3>
                <?php endif; ?>
            </div>
            <div class="modal-body"><p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="closeNotificationModal()" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <script>
        function closeNotificationModal() {
            const modal = document.getElementById('notification-modal');
            if (modal) { modal.classList.remove('active'); setTimeout(() => { modal.style.display = 'none'; }, 300); }
        }
    </script>
</body>
</html>
