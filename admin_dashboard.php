<?php
/**
 * Touba Lyon 2026 - Admin Dashboard
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/dahira_emails.php';

session_start();

// Redirect to login page if admin is not authenticated
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

// Generate CSRF token for secure actions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// Handle actions (Approve, Suspend, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        $member_id = intval($_POST['member_id'] ?? 0);

        if ($member_id > 0) {
            try {
                // Fetch member to confirm identity and get photo filename
                $stmt = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
                $stmt->execute([$member_id]);
                $member = $stmt->fetch();

                if ($member) {
                    if ($action === 'approve') {
                        $upd = $pdo->prepare("UPDATE membres SET status = 'approved' WHERE id = ?");
                        $upd->execute([$member_id]);
                        @send_validation_email($member['email'], $member['prenom'] . ' ' . $member['nom']);
                        $success = "L'inscription de " . htmlspecialchars($member['prenom'] . ' ' . $member['nom']) . " a été approuvée. Un e-mail de confirmation a été envoyé au membre.";
                    } elseif ($action === 'suspend') {
                        $upd = $pdo->prepare("UPDATE membres SET status = 'pending' WHERE id = ?");
                        $upd->execute([$member_id]);
                        $success = "Le membre " . htmlspecialchars($member['prenom'] . ' ' . $member['nom']) . " a été suspendu (remis en attente).";
                    } elseif ($action === 'delete') {
                        // Delete photo file from disk if it exists
                        $photoPath = __DIR__ . '/uploads/' . $member['photo_path'];
                        if (!empty($member['photo_path']) && file_exists($photoPath)) {
                            unlink($photoPath);
                        }
                        // Delete member record from DB
                        $del = $pdo->prepare("DELETE FROM membres WHERE id = ?");
                        $del->execute([$member_id]);
                        $success = "L'inscription de " . htmlspecialchars($member['prenom'] . ' ' . $member['nom']) . " a été définitivement supprimée.";
                    }
                } else {
                    $error = "Membre introuvable.";
                }
            } catch (Exception $e) {
                error_log('Touba Lyon admin_dashboard (action): ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
}

// Fetch stats and lists
try {
    // Stats : "En attente" = inscriptions trombinoscope en attente (les adhésions en attente
    // sont gérées dans admin_adhesions.php) ; "Validés" = TOUS les membres validés, y compris
    // les adhésions Dahira approuvées.
    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    $counts['pending']  = (int) $pdo->query("SELECT COUNT(*) FROM membres WHERE status = 'pending' AND type_adhesion IS NULL")->fetchColumn();
    $counts['approved'] = (int) $pdo->query("SELECT COUNT(*) FROM membres WHERE status = 'approved'")->fetchColumn();
    $totalMembers = $counts['pending'] + $counts['approved'];

    // List of pending members (inscriptions trombinoscope uniquement)
    $stmt = $pdo->query("SELECT * FROM membres WHERE status = 'pending' AND type_adhesion IS NULL ORDER BY created_at DESC");
    $pendingMembers = $stmt->fetchAll();

    // List of approved members (TOUS les validés, y compris les adhésions Dahira approuvées)
    $stmt = $pdo->query("SELECT * FROM membres WHERE status = 'approved' ORDER BY created_at DESC");
    $approvedMembers = $stmt->fetchAll();

    // Nombre d'inscriptions Dahira (adhésions) pour le lien dédié
    $adhesionsCount = (int) $pdo->query("SELECT COUNT(*) FROM membres WHERE type_adhesion IS NOT NULL")->fetchColumn();

} catch (Exception $e) {
    error_log('Touba Lyon admin_dashboard (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - Trombinoscope</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <!-- Admin Welcome Banner -->
        <div class="admin-welcome-banner glass-card" style="margin-top: 2rem; margin-bottom: 1rem; padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center; border-radius: 20px;">
            <span>Espace Administration — Connecté en tant que : <strong class="gold-text"><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></strong></span>
            <div style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                <a href="admin_adhesions.php" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">📝 Inscriptions Dahira (<?php echo $adhesionsCount; ?>)</a>
                <a href="admin_admins.php" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">👥 Gérer les admins</a>
                <span class="badge badge-approved" style="font-size: 0.85rem; padding: 0.4rem 1rem;">Administrateur</span>
            </div>
        </div>

        <!-- Stats Section -->
        <div class="stats-grid">
            <div class="stat-card stat-total">
                <span class="stat-title">Total Inscriptions</span>
                <span class="stat-value"><?php echo $totalMembers; ?></span>
            </div>
            <div class="stat-card stat-pending">
                <span class="stat-title">En attente</span>
                <span class="stat-value"><?php echo $counts['pending']; ?></span>
            </div>
            <div class="stat-card stat-approved">
                <span class="stat-title">Validés</span>
                <span class="stat-value"><?php echo $counts['approved']; ?></span>
            </div>
        </div>


        <!-- Tabs Navigation -->
        <div class="dashboard-tabs">
            <button class="tab-btn active" onclick="switchTab(event, 'pending-tab')">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span>Demandes en attente</span>
                <span class="sidebar-badge"><?php echo count($pendingMembers); ?></span>
            </button>
            <button class="tab-btn" onclick="switchTab(event, 'validated-tab')">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                <span>Membres validés</span>
                <span class="sidebar-badge"><?php echo count($approvedMembers); ?></span>
            </button>
        </div>

        <div id="pending-tab" class="tab-content">
            <!-- Pending Registrations Section -->
            <section class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Inscriptions en attente de validation</h2>
                <span class="badge badge-pending"><?php echo count($pendingMembers); ?> Demande(s)</span>
            </div>

            <?php if (empty($pendingMembers)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🎉</div>
                    <p>Aucune inscription en attente de validation pour le moment.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table admin-table--compact">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Membre</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingMembers as $m): ?>
                                <tr>
                                    <td>
                                        <a href="uploads/<?php echo htmlspecialchars($m['photo_path']); ?>" target="_blank">
                                            <img src="uploads/<?php echo htmlspecialchars($m['photo_path']); ?>" class="table-photo" alt="Photo de <?php echo htmlspecialchars($m['prenom']); ?>">
                                        </a>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;"><span style="text-transform:capitalize;"><?php echo htmlspecialchars($m['prenom']); ?></span> <span style="text-transform:uppercase;"><?php echo htmlspecialchars($m['nom']); ?></span></div>
                                        <div style="font-size:0.8rem; color:var(--text-muted); word-break:break-all;"><?php echo htmlspecialchars($m['email']); ?></div>
                                        <div style="font-size:0.78rem; color:var(--text-muted);"><?php echo date('d/m/Y H:i', strtotime($m['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <?php
                                                $actionFullName = $m['prenom'] . ' ' . $m['nom'];
                                            ?>
                                            <a href="membre.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-secondary btn-sm" style="border-color: var(--accent); color: var(--accent);">Voir</a>
                                            <button onclick="handleAction('approve', <?php echo $m['id']; ?>, '<?php echo addslashes(htmlspecialchars($actionFullName)); ?>', '<?php echo htmlspecialchars($m['photo_path']); ?>')" class="btn btn-primary btn-sm" style="background: var(--success); box-shadow: none;">Valider</button>
                                            <button onclick="handleAction('delete', <?php echo $m['id']; ?>, '<?php echo addslashes(htmlspecialchars($actionFullName)); ?>', '<?php echo htmlspecialchars($m['photo_path']); ?>')" class="btn btn-danger btn-sm">Rejeter</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        </div>

        <div id="validated-tab" class="tab-content" style="display: none;">
        <!-- Approved Members Section -->
        <section class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Membres validés</h2>
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <!-- View Switcher -->
                    <div style="background: rgba(255,255,255,0.05); padding: 0.25rem; border-radius: 8px; border: 1px solid var(--glass-border); display: flex; gap: 0.25rem;">
                        <button class="btn btn-sm" id="view-mode-table" onclick="setViewMode('table')" style="background: var(--accent); color: var(--secondary); border-radius: 6px; padding: 0.35rem 0.75rem; font-size: 0.8rem; font-weight: 600;">
                            Liste
                        </button>
                        <button class="btn btn-sm" id="view-mode-grid" onclick="setViewMode('grid')" style="background: transparent; color: var(--text-muted); border-radius: 6px; padding: 0.35rem 0.75rem; font-size: 0.8rem; font-weight: 600;">
                            Trombinoscope
                        </button>
                    </div>
                    <span class="badge badge-approved"><?php echo count($approvedMembers); ?> Membre(s)</span>
                </div>
            </div>

            <?php if (empty($approvedMembers)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">👤</div>
                    <p>Aucun membre n'a encore été validé.</p>
                </div>
            <?php else: ?>
                <!-- Table List View -->
                <div id="validated-table-view" class="table-responsive">
                    <table class="admin-table admin-table--compact">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Membre</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approvedMembers as $m): ?>
                                <tr>
                                    <td>
                                        <a href="uploads/<?php echo htmlspecialchars($m['photo_path']); ?>" target="_blank">
                                            <img src="uploads/<?php echo htmlspecialchars($m['photo_path']); ?>" class="table-photo" alt="Photo de <?php echo htmlspecialchars($m['prenom']); ?>">
                                        </a>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;"><span style="text-transform:capitalize;"><?php echo htmlspecialchars($m['prenom']); ?></span> <span style="text-transform:uppercase;"><?php echo htmlspecialchars($m['nom']); ?></span></div>
                                        <div style="font-size:0.8rem; color:var(--text-muted); word-break:break-all;"><?php echo htmlspecialchars($m['email']); ?></div>
                                        <div style="font-size:0.78rem; color:var(--text-muted);"><?php echo date('d/m/Y H:i', strtotime($m['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <?php
                                                $actionFullName = $m['prenom'] . ' ' . $m['nom'];
                                            ?>
                                            <a href="membre.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-secondary btn-sm" style="border-color: var(--accent); color: var(--accent);">Voir</a>
                                            <button onclick="handleAction('suspend', <?php echo $m['id']; ?>, '<?php echo addslashes(htmlspecialchars($actionFullName)); ?>', '<?php echo htmlspecialchars($m['photo_path']); ?>')" class="btn btn-secondary btn-sm" style="color: var(--warning); border-color: var(--warning);">Suspendre</button>
                                            <button onclick="handleAction('delete', <?php echo $m['id']; ?>, '<?php echo addslashes(htmlspecialchars($actionFullName)); ?>', '<?php echo htmlspecialchars($m['photo_path']); ?>')" class="btn btn-danger btn-sm">Supprimer</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Visual Trombinoscope Card Grid View -->
                <div id="validated-grid-view" style="display: none;">
                    <div class="trombi-grid">
                        <?php foreach ($approvedMembers as $m): ?>
                            <?php $fullName = $m['prenom'] . ' ' . $m['nom']; ?>
                            <div class="member-card">
                                <div class="member-photo-container">
                                    <img src="uploads/<?php echo htmlspecialchars($m['photo_path']); ?>" class="member-photo" alt="Photo de <?php echo htmlspecialchars($fullName); ?>" loading="lazy">
                                </div>
                                <div class="member-info">
                                    <h3 class="member-name"><?php echo htmlspecialchars($fullName); ?></h3>
                                    <a href="mailto:<?php echo htmlspecialchars($m['email']); ?>" class="member-email">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                                        <?php echo htmlspecialchars($m['email']); ?>
                                    </a>
                                    
                                    <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 1.25rem; width: 100%;">
                                        <a href="membre.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-primary btn-sm" style="width: 100%; font-size: 0.8rem; padding: 0.45rem; background: var(--accent); color: var(--secondary); text-align:center;">Voir Profil</a>
                                        <div style="display: flex; gap: 0.5rem; width: 100%;">
                                            <button onclick="handleAction('suspend', <?php echo $m['id']; ?>, '<?php echo addslashes(htmlspecialchars($fullName)); ?>', '<?php echo htmlspecialchars($m['photo_path']); ?>')" class="btn btn-secondary btn-sm" style="flex: 1; color: var(--accent); border-color: var(--accent); font-size: 0.8rem; padding: 0.45rem;">Suspendre</button>
                                            <button onclick="handleAction('delete', <?php echo $m['id']; ?>, '<?php echo addslashes(htmlspecialchars($fullName)); ?>', '<?php echo htmlspecialchars($m['photo_path']); ?>')" class="btn btn-danger btn-sm" style="flex: 1; font-size: 0.8rem; padding: 0.45rem;">Supprimer</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
        </div> <!-- Closes validated-tab -->
    </main>

    <!-- Modern Notification Modal (alert responses) -->
    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display: flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($success)): ?>
                    <h3 class="gold-text">Opération Réussie</h3>
                <?php else: ?>
                    <h3 style="color: var(--danger);">Erreur</h3>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <p>
                    <?php 
                        if (!empty($success)) {
                            echo htmlspecialchars($success);
                        } else {
                            echo htmlspecialchars($error);
                        }
                    ?>
                </p>
            </div>
            <div class="modal-footer">
                <button onclick="closeNotificationModal()" class="btn btn-primary btn-sm">OK</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <!-- Modern Confirmation Modal -->
    <div id="custom-modal" class="modal-overlay">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <h3 id="modal-title" class="gold-text">Confirmation</h3>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; align-items: center; gap: 1rem;">
                <img id="modal-photo" src="" alt="" style="width: 90px; height: 90px; border-radius: 50%; object-fit: cover; border: 2.5px solid var(--accent); display: none; box-shadow: 0 4px 15px rgba(0,0,0,0.4);">
                <p id="modal-message">Voulez-vous effectuer cette action ?</p>
            </div>
            <div class="modal-footer">
                <button id="modal-cancel-btn" class="btn btn-secondary btn-sm">Annuler</button>
                <button id="modal-confirm-btn" class="btn btn-primary btn-sm">Confirmer</button>
            </div>
        </div>
    </div>

    <!-- Member Details Modal -->
    <div id="details-modal" class="modal-overlay">
        <div class="modal-card glass-card" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="gold-text">Détails du Membre</h3>
            </div>
            <div class="modal-body" style="display: flex; flex-direction: column; align-items: center; gap: 1rem; text-align: center;">
                <div style="width: 120px; height: 120px; border-radius: 50%; overflow: hidden; border: 3px solid var(--accent); box-shadow: 0 4px 15px rgba(0,0,0,0.4); margin-bottom: 0.5rem;">
                    <img id="detail-photo" src="" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
                <h2 id="detail-fullname" style="font-size: 1.6rem; color: var(--white); margin-bottom: 0.25rem;">Prenom Nom</h2>
                <span id="detail-civilite" class="badge badge-approved" style="font-size: 0.8rem; margin-bottom: 0.5rem; display: inline-block;">Goor Yalla</span>
                
                <div style="width: 100%; background: rgba(255, 255, 255, 0.02); border: 1px solid var(--glass-border); border-radius: 12px; padding: 1rem; text-align: left; display: flex; flex-direction: column; gap: 0.75rem;">
                    <div>
                        <span style="color: var(--text-muted); font-size: 0.8rem; display: block;">Adresse Email</span>
                        <strong id="detail-email" style="color: var(--white); font-size: 0.95rem;">email@example.com</strong>
                    </div>
                    <div>
                        <span style="color: var(--text-muted); font-size: 0.8rem; display: block;">Score Ki Kan La</span>
                        <strong id="detail-score" class="gold-text" style="font-size: 1.1rem;">0 pts</strong>
                    </div>
                    <div>
                        <span style="color: var(--text-muted); font-size: 0.8rem; display: block;">Date d'inscription</span>
                        <strong id="detail-date" style="color: var(--white); font-size: 0.95rem;">01/01/2026</strong>
                    </div>
                    <div>
                        <span style="color: var(--text-muted); font-size: 0.8rem; display: block;">Statut actuel</span>
                        <strong id="detail-status" style="font-size: 0.95rem;">En attente / Validé</strong>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: center;">
                <button onclick="closeDetailsModal()" class="btn btn-primary btn-sm">Fermer</button>
            </div>
        </div>
    </div>

    <!-- Hidden form for secure POST actions -->
    <form id="action-form" action="admin_dashboard.php" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" id="form-action" value="">
        <input type="hidden" name="member_id" id="form-member-id" value="">
    </form>

    <script>
        let activeAction = null;
        let activeMemberId = null;

        function handleAction(action, memberId, memberName, photoPath = '') {
            activeAction = action;
            activeMemberId = memberId;

            const modal = document.getElementById('custom-modal');
            const modalTitle = document.getElementById('modal-title');
            const modalMessage = document.getElementById('modal-message');
            const confirmBtn = document.getElementById('modal-confirm-btn');
            const modalPhoto = document.getElementById('modal-photo');

            let title = "Confirmation";
            let msg = "";

            // Reset button classes
            confirmBtn.className = "btn btn-sm";
            confirmBtn.style.color = "";
            confirmBtn.style.borderColor = "";
            confirmBtn.style.borderStyle = "";
            confirmBtn.style.borderWidth = "";

            // Display photo if available
            if (photoPath) {
                modalPhoto.src = 'uploads/' + photoPath;
                modalPhoto.alt = 'Photo de ' + memberName;
                modalPhoto.style.display = 'block';
            } else {
                modalPhoto.style.display = 'none';
            }

            if (action === 'approve') {
                title = "Approuver l'inscription";
                msg = `Voulez-vous valider l'inscription de <strong>${memberName}</strong> ? Ce profil sera visible publiquement.`;
                confirmBtn.classList.add("btn-primary");
                confirmBtn.textContent = "Valider";
            } else if (action === 'suspend') {
                title = "Suspendre le membre";
                msg = `Voulez-vous suspendre l'inscription de <strong>${memberName}</strong> ? Elle retournera dans la liste d'attente.`;
                confirmBtn.classList.add("btn-secondary");
                confirmBtn.style.color = "var(--accent)";
                confirmBtn.style.borderColor = "var(--accent)";
                confirmBtn.style.borderStyle = "solid";
                confirmBtn.style.borderWidth = "1px";
                confirmBtn.textContent = "Suspendre";
            } else if (action === 'delete') {
                title = "⚠️ Suppression Définitive";
                msg = `Êtes-vous sûr de vouloir supprimer définitivement <strong>${memberName}</strong> ? Son compte et sa photo seront effacés sans possibilité de retour.`;
                confirmBtn.classList.add("btn-danger");
                confirmBtn.textContent = "Supprimer";
            }

            modalTitle.innerHTML = title;
            modalMessage.innerHTML = msg;

            // Show modal with animation
            modal.style.display = 'flex';
            setTimeout(() => {
                modal.classList.add('active');
            }, 10);
        }

        // Close functions
        function closeNotificationModal() {
            const modal = document.getElementById('notification-modal');
            if (modal) {
                modal.classList.remove('active');
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 300);
            }
        }

        function closeModal() {
            const modal = document.getElementById('custom-modal');
            modal.classList.remove('active');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }

        document.getElementById('modal-cancel-btn').addEventListener('click', closeModal);
        document.getElementById('custom-modal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // Submit action
        document.getElementById('modal-confirm-btn').addEventListener('click', function() {
            if (activeAction && activeMemberId) {
                document.getElementById('form-action').value = activeAction;
                document.getElementById('form-member-id').value = activeMemberId;
                document.getElementById('action-form').submit();
            }
        });

        // Tabs switcher function
        function switchTab(event, tabId) {
            // Hide all tab content elements
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });

            // Deactivate all tab buttons
            document.querySelectorAll('.tab-btn, .sidebar-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show target tab content
            document.getElementById(tabId).style.display = 'block';

            // Mark clicked button active
            event.currentTarget.classList.add('active');

            // Save active tab in local storage to keep state on reload
            localStorage.setItem('active_admin_tab', tabId);
        }

        // View mode switcher (table list / visual cards grid)
        function setViewMode(mode) {
            const tableView = document.getElementById('validated-table-view');
            const gridView = document.getElementById('validated-grid-view');
            const btnTable = document.getElementById('view-mode-table');
            const btnGrid = document.getElementById('view-mode-grid');

            if (mode === 'table') {
                if(tableView) tableView.style.display = 'block';
                if(gridView) gridView.style.display = 'none';
                
                btnTable.style.background = 'var(--accent)';
                btnTable.style.color = 'var(--secondary)';
                btnGrid.style.background = 'transparent';
                btnGrid.style.color = 'var(--text-muted)';
            } else {
                if(tableView) tableView.style.display = 'none';
                if(gridView) gridView.style.display = 'block';
                
                btnTable.style.background = 'transparent';
                btnTable.style.color = 'var(--text-muted)';
                btnGrid.style.background = 'var(--accent)';
                btnGrid.style.color = 'var(--secondary)';
            }
            
            // Save active view mode in local storage
            localStorage.setItem('active_view_mode', mode);
        }

        // On document load, restore previous tab & view mode selections
        document.addEventListener('DOMContentLoaded', () => {
            const savedTab = localStorage.getItem('active_admin_tab');
            if (savedTab) {
                const tabBtn = document.querySelector(`button[onclick*="${savedTab}"]`);
                if (tabBtn) {
                    // Trigger click to restore tab
                    tabBtn.click();
                }
            }

            const savedMode = localStorage.getItem('active_view_mode');
            if (savedMode) {
                setViewMode(savedMode);
            }
        });

        function showDetails(m) {
            document.getElementById('detail-photo').src = 'uploads/' + m.photo_path;
            document.getElementById('detail-fullname').textContent = m.prenom + ' ' + m.nom;
            document.getElementById('detail-civilite').textContent = m.civilite;
            
            // Adjust badge for civility
            const civBadge = document.getElementById('detail-civilite');
            if (m.civilite === 'Sokhna') {
                civBadge.className = 'badge';
                civBadge.style.background = 'rgba(212, 175, 55, 0.15)';
                civBadge.style.borderColor = 'var(--gold)';
                civBadge.style.color = 'var(--gold)';
                civBadge.style.borderStyle = 'solid';
                civBadge.style.borderWidth = '1px';
            } else {
                civBadge.className = 'badge badge-approved';
                civBadge.style.background = '';
                civBadge.style.borderColor = '';
                civBadge.style.color = '';
                civBadge.style.borderStyle = '';
                civBadge.style.borderWidth = '';
            }

            document.getElementById('detail-email').textContent = m.email;
            document.getElementById('detail-score').textContent = m.score + ' pts';
            
            // Format date
            const d = new Date(m.created_at);
            const formattedDate = String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear() + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
            document.getElementById('detail-date').textContent = formattedDate;

            const statusEl = document.getElementById('detail-status');
            if (m.status === 'approved') {
                statusEl.textContent = 'Validé';
                statusEl.style.color = '#52b788';
            } else {
                statusEl.textContent = 'En attente de validation';
                statusEl.style.color = '#d4af37';
            }

            const modal = document.getElementById('details-modal');
            modal.style.display = 'flex';
            setTimeout(() => {
                modal.classList.add('active');
            }, 10);
        }

        function closeDetailsModal() {
            const modal = document.getElementById('details-modal');
            modal.classList.remove('active');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }
    </script>
</body>
</html>
