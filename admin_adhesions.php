<?php
/**
 * Touba Lyon 2026 - Administration des inscriptions Dahira (adhésions)
 * Réservé aux administrateurs connectés.
 */
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dahira_emails.php';

$error = '';
$success = '';

// Traitement des actions (valider / suspendre / supprimer)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        $member_id = intval($_POST['member_id'] ?? 0);

        if ($member_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM membres WHERE id = ? AND type_adhesion IS NOT NULL");
                $stmt->execute([$member_id]);
                $m = $stmt->fetch();

                if ($m) {
                    $fullName = htmlspecialchars($m['prenom'] . ' ' . $m['nom']);
                    if ($action === 'approve') {
                        $pdo->prepare("UPDATE membres SET status = 'approved' WHERE id = ?")->execute([$member_id]);
                        @send_validation_email($m['email'], $m['prenom'] . ' ' . $m['nom']);
                        $success = "L'adhésion de {$fullName} a été validée. Un e-mail de confirmation a été envoyé au membre.";
                    } elseif ($action === 'suspend') {
                        $pdo->prepare("UPDATE membres SET status = 'pending' WHERE id = ?")->execute([$member_id]);
                        $success = "L'adhésion de {$fullName} a été remise en attente.";
                    } elseif ($action === 'delete') {
                        $pdo->prepare("DELETE FROM membres WHERE id = ?")->execute([$member_id]);
                        $success = "L'adhésion de {$fullName} a été supprimée.";
                    }
                } else {
                    $error = "Inscription introuvable.";
                }
            } catch (Exception $e) {
                error_log('Touba Lyon admin_adhesions: ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
}

// Chargement des inscriptions Dahira
try {
    $stmt = $pdo->query("SELECT * FROM membres WHERE type_adhesion IS NOT NULL ORDER BY created_at DESC");
    $adhesions = $stmt->fetchAll();
    $countPending = 0;
    $countApproved = 0;
    foreach ($adhesions as $a) {
        if ($a['status'] === 'approved') { $countApproved++; } else { $countPending++; }
    }
} catch (Exception $e) {
    error_log('Touba Lyon admin_adhesions (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscriptions Dahira - Administration</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="admin-welcome-banner glass-card" style="margin-top:2rem; margin-bottom:1rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
            <span>Inscriptions au Dahira — <strong class="gold-text"><?php echo count($adhesions); ?></strong> demande(s)</span>
            <a href="admin_dashboard.php" class="btn btn-secondary btn-sm">← Tableau de bord</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card stat-total"><span class="stat-title">Total</span><span class="stat-value"><?php echo count($adhesions); ?></span></div>
            <div class="stat-card stat-pending"><span class="stat-title">En attente</span><span class="stat-value"><?php echo $countPending; ?></span></div>
            <div class="stat-card stat-approved"><span class="stat-title">Validées</span><span class="stat-value"><?php echo $countApproved; ?></span></div>
        </div>

        <section class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Demandes d'adhésion</h2>
            </div>

            <?php if (empty($adhesions)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <p>Aucune inscription au Dahira pour le moment.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table admin-table--compact">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Membre</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adhesions as $a): ?>
                                <?php $name = $a['prenom'] . ' ' . $a['nom']; ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($a['photo_path'])): ?>
                                            <a href="uploads/<?php echo htmlspecialchars($a['photo_path']); ?>" target="_blank">
                                                <img src="uploads/<?php echo htmlspecialchars($a['photo_path']); ?>" class="table-photo" alt="Photo de <?php echo htmlspecialchars($a['prenom']); ?>">
                                            </a>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; text-transform:capitalize;"><?php echo htmlspecialchars($a['prenom']); ?> <span style="text-transform:uppercase;"><?php echo htmlspecialchars($a['nom']); ?></span></div>
                                        <div style="font-size:0.8rem; color:var(--text-muted);"><?php echo htmlspecialchars($a['type_adhesion']); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($a['status'] === 'approved'): ?>
                                            <span class="badge badge-approved">Validée</span>
                                        <?php else: ?>
                                            <span class="badge badge-pending">En attente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="membre.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">Voir</a>
                                            <?php if ($a['status'] !== 'approved'): ?>
                                                <button onclick="handleAction('approve', <?php echo $a['id']; ?>, '<?php echo addslashes(htmlspecialchars($name)); ?>')" class="btn btn-primary btn-sm" style="background:var(--success); box-shadow:none;">Valider</button>
                                            <?php else: ?>
                                                <button onclick="handleAction('suspend', <?php echo $a['id']; ?>, '<?php echo addslashes(htmlspecialchars($name)); ?>')" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">Suspendre</button>
                                            <?php endif; ?>
                                            <button onclick="handleAction('delete', <?php echo $a['id']; ?>, '<?php echo addslashes(htmlspecialchars($name)); ?>')" class="btn btn-danger btn-sm">Supprimer</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
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

    <!-- Confirmation modal -->
    <div id="custom-modal" class="modal-overlay">
        <div class="modal-card glass-card">
            <div class="modal-header"><h3 id="modal-title" class="gold-text">Confirmation</h3></div>
            <div class="modal-body"><p id="modal-message">Voulez-vous effectuer cette action ?</p></div>
            <div class="modal-footer">
                <button id="modal-cancel-btn" class="btn btn-secondary btn-sm">Annuler</button>
                <button id="modal-confirm-btn" class="btn btn-primary btn-sm">Confirmer</button>
            </div>
        </div>
    </div>

    <!-- Details modal -->
    <div id="details-modal" class="modal-overlay">
        <div class="modal-card glass-card" style="max-width:520px;">
            <div class="modal-header"><h3 class="gold-text">Détails de l'inscription</h3></div>
            <div class="modal-body">
                <div id="detail-photo-wrap" style="width:120px; height:120px; border-radius:50%; overflow:hidden; border:3px solid var(--accent); box-shadow:0 4px 15px rgba(0,0,0,0.4); margin:0 auto 1.25rem; display:none;">
                    <img id="detail-photo" src="" alt="Photo du membre" style="width:100%; height:100%; object-fit:cover;">
                </div>
                <div id="detail-body" style="display:flex; flex-direction:column; gap:0.5rem; font-size:0.92rem; text-align:left;"></div>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button onclick="closeDetailsModal()" class="btn btn-primary btn-sm">Fermer</button>
            </div>
        </div>
    </div>

    <form id="action-form" action="admin_adhesions.php" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
        <input type="hidden" name="action" id="form-action" value="">
        <input type="hidden" name="member_id" id="form-member-id" value="">
    </form>

    <script>
        let activeAction = null, activeMemberId = null;

        function handleAction(action, memberId, memberName) {
            activeAction = action; activeMemberId = memberId;
            const modal = document.getElementById('custom-modal');
            const confirmBtn = document.getElementById('modal-confirm-btn');
            confirmBtn.className = 'btn btn-sm';
            let title = 'Confirmation', msg = '';
            if (action === 'approve') {
                title = "Valider l'adhésion";
                msg = `Valider l'adhésion de <strong>${memberName}</strong> ?`;
                confirmBtn.classList.add('btn-primary'); confirmBtn.textContent = 'Valider';
            } else if (action === 'suspend') {
                title = "Suspendre l'adhésion";
                msg = `Remettre l'adhésion de <strong>${memberName}</strong> en attente ?`;
                confirmBtn.classList.add('btn-secondary'); confirmBtn.textContent = 'Suspendre';
            } else if (action === 'delete') {
                title = "⚠️ Suppression définitive";
                msg = `Supprimer définitivement l'inscription de <strong>${memberName}</strong> ?`;
                confirmBtn.classList.add('btn-danger'); confirmBtn.textContent = 'Supprimer';
            }
            document.getElementById('modal-title').innerHTML = title;
            document.getElementById('modal-message').innerHTML = msg;
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
        }

        function closeModal() {
            const modal = document.getElementById('custom-modal');
            modal.classList.remove('active');
            setTimeout(() => { modal.style.display = 'none'; }, 300);
        }
        document.getElementById('modal-cancel-btn').addEventListener('click', closeModal);
        document.getElementById('custom-modal').addEventListener('click', function(e){ if(e.target===this) closeModal(); });
        document.getElementById('modal-confirm-btn').addEventListener('click', function(){
            if (activeAction && activeMemberId) {
                document.getElementById('form-action').value = activeAction;
                document.getElementById('form-member-id').value = activeMemberId;
                document.getElementById('action-form').submit();
            }
        });

        function closeNotificationModal() {
            const modal = document.getElementById('notification-modal');
            if (modal) { modal.classList.remove('active'); setTimeout(() => { modal.style.display = 'none'; }, 300); }
        }

        const LABELS = {
            type_adhesion: "Type d'adhésion", nom: 'Nom', prenom: 'Prénom', genre: 'Genre',
            test_kourel: 'Test Kourel', adresse: 'Adresse', code_postal: 'Code postal',
            commune: 'Commune', telephone: 'Téléphone', email: 'Email', statut: 'Statut',
            secteur_activite: "Secteur d'activité", profession: 'Profession',
            commentaires: 'Commentaires', annee_integration: "Année d'intégration", status: 'Statut du dossier'
        };
        function esc(s){ const d=document.createElement('div'); d.textContent=(s===null||s===undefined||s==='')?'—':s; return d.innerHTML; }
        function showDetails(a) {
            // Photo
            const photoWrap = document.getElementById('detail-photo-wrap');
            const photoImg = document.getElementById('detail-photo');
            if (a.photo_path) {
                photoImg.src = 'uploads/' + a.photo_path;
                photoWrap.style.display = 'block';
            } else {
                photoImg.removeAttribute('src');
                photoWrap.style.display = 'none';
            }
            let html = '';
            for (const k in LABELS) {
                html += `<div style="display:flex; gap:0.5rem; border-bottom:1px solid var(--glass-border); padding:0.35rem 0;">
                    <span style="color:var(--text-muted); min-width:150px;">${LABELS[k]}</span>
                    <strong style="color:var(--white);">${esc(a[k])}</strong></div>`;
            }
            document.getElementById('detail-body').innerHTML = html;
            const modal = document.getElementById('details-modal');
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
        }
        function closeDetailsModal() {
            const modal = document.getElementById('details-modal');
            modal.classList.remove('active');
            setTimeout(() => { modal.style.display = 'none'; }, 300);
        }
    </script>
</body>
</html>
