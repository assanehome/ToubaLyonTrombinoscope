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
                $stmt = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
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
                    } elseif ($action === 'save_suivi') {
                        if ($m['status'] === 'approved') {
                            $error = "Ce compte est validé : le suivi n'est plus modifiable.";
                        } else {
                            $integrateur_id = intval($_POST['integrateur_id'] ?? 0);
                            $integrateur_id = $integrateur_id > 0 ? $integrateur_id : null;
                            $souhait = trim($_POST['souhait_commission'] ?? ''); $souhait = ($souhait !== '') ? $souhait : null;
                            $pres = trim($_POST['presentation_ok'] ?? ''); if (!in_array($pres, ['OK', 'Non OK'], true)) { $pres = null; }
                            $tk = trim($_POST['test_kourel'] ?? ''); if (!in_array($tk, ['Oui', 'Non'], true)) { $tk = null; }
                            $pdo->prepare("UPDATE membres SET integrateur_id = ?, souhait_commission = ?, presentation_ok = ?, test_kourel = ? WHERE id = ? AND status <> 'approved'")
                                ->execute([$integrateur_id, $souhait, $pres, $tk, $member_id]);
                            $success = "Suivi d'intégration mis à jour pour {$fullName}.";
                        }
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

// Chargement des inscriptions Dahira (avec le nom de l'intégrateur assigné)
try {
    $stmt = $pdo->query("SELECT m.*, TRIM(CONCAT(COALESCE(i.prenom,''), ' ', i.nom)) AS integrateur_nom FROM membres m LEFT JOIN membres i ON m.integrateur_id = i.id ORDER BY m.created_at DESC");
    $adhesions = $stmt->fetchAll();
    $countPending = 0;
    $countApproved = 0;
    foreach ($adhesions as $a) {
        if ($a['status'] === 'approved') { $countApproved++; } else { $countPending++; }
    }
    // Pour l'édition du suivi dans le popup
    $integrateurs = $pdo->query("SELECT id, TRIM(CONCAT(COALESCE(prenom,''), ' ', nom)) AS nom FROM membres WHERE is_integrateur = 1 ORDER BY nom ASC")->fetchAll();
    $commissions = $pdo->query("SELECT nom FROM commissions ORDER BY nom ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('Touba Lyon admin_adhesions (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

// Ensembles de valeurs distinctes pour alimenter les filtres.
$fltCommissions = []; $fltSecteurs = []; $fltTypes = []; $fltStatuts = []; $fltGenres = []; $fltAnnees = [];
foreach ($adhesions as $a) {
    if (!empty($a['souhait_commission'])) { $fltCommissions[$a['souhait_commission']] = true; }
    if (!empty($a['secteur_activite']))   { $fltSecteurs[$a['secteur_activite']] = true; }
    if (!empty($a['type_adhesion']))      { $fltTypes[$a['type_adhesion']] = true; }
    if (!empty($a['statut']))             { $fltStatuts[$a['statut']] = true; }
    if (!empty($a['genre']))              { $fltGenres[$a['genre']] = true; }
    if (!empty($a['annee_integration']))  { $fltAnnees[$a['annee_integration']] = true; }
}
$fltCommissions = array_keys($fltCommissions); sort($fltCommissions);
$fltSecteurs = array_keys($fltSecteurs); sort($fltSecteurs);
$fltTypes = array_keys($fltTypes); sort($fltTypes);
$fltStatuts = array_keys($fltStatuts); sort($fltStatuts);
$fltGenres = array_keys($fltGenres); sort($fltGenres);
$fltAnnees = array_keys($fltAnnees); rsort($fltAnnees);

/** Rendu d'un select de filtre. */
function filter_select($id, $label, $options) {
    $h = '<select id="' . $id . '" class="adh-select">';
    $h .= '<option value="">' . htmlspecialchars($label) . ' : tous</option>';
    foreach ($options as $o) {
        $h .= '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '">' . htmlspecialchars($o) . '</option>';
    }
    $h .= '</select>';
    return $h;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscriptions Dahira - Administration</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .adh-filter { display:flex; flex-wrap:wrap; gap:0.4rem; }
        .adh-filter-btn { background:rgba(255,255,255,0.05); color:var(--text-muted); border:1px solid var(--glass-border); border-radius:50px; padding:0.35rem 0.9rem; font-size:0.82rem; font-weight:600; cursor:pointer; transition:all 0.2s ease; }
        .adh-filter-btn:hover { border-color:var(--accent); color:var(--white); }
        .adh-filter-btn.active { background:var(--accent); color:var(--secondary); border-color:var(--accent); }
        .adh-filters-adv { display:flex; flex-wrap:wrap; gap:0.6rem; margin:0 0 1rem; align-items:center; }
        .adh-filters-adv input, .adh-select { background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); border-radius:10px; color:var(--white); font-size:0.85rem; padding:0.5rem 0.75rem; color-scheme:dark; }
        .adh-filters-adv input { flex:1 1 220px; min-width:180px; border-radius:50px; }
        .adh-select { flex:1 1 160px; min-width:150px; }
        .adh-select option { background-color:#0c241a; color:#fff; }
        .adh-filters-adv input:focus, .adh-select:focus { outline:none; border-color:var(--accent); }
        #adh-reset { background:transparent; border:1px solid var(--glass-border); color:var(--text-muted); border-radius:50px; padding:0.5rem 1rem; font-size:0.82rem; font-weight:600; cursor:pointer; }
        #adh-reset:hover { border-color:var(--danger); color:var(--danger); }
        .adh-count { font-size:0.82rem; color:var(--text-muted); margin-left:auto; }
        .adh-toggle { background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--white); border-radius:50px; padding:0.45rem 1rem; font-size:0.85rem; font-weight:600; cursor:pointer; margin-bottom:1rem; display:inline-flex; align-items:center; gap:0.4rem; }
        .adh-toggle:hover { border-color:var(--accent); }
        .adh-toggle .chev { transition:transform 0.2s ease; }
        .adh-toggle.open .chev { transform:rotate(180deg); }
        .adh-filters-adv.is-hidden { display:none; }
        /* ── Popup Suivi intégration (design dédié, une info par ligne, responsive) ── */
        #suivi-modal { position:fixed; inset:0; overflow:hidden; }
        .suivi2-card { position:fixed; left:50%; top:50%; width:calc(100vw - 28px); max-width:440px; max-height:88vh; display:flex; flex-direction:column;
            background:linear-gradient(180deg,#123528 0%, #0c241a 100%); border:1px solid rgba(212,175,55,0.25);
            border-radius:20px; overflow:hidden; box-shadow:0 30px 80px rgba(0,0,0,0.55); z-index:2001;
            transform:translate(-50%, -46%) scale(0.98); opacity:0; transition:transform .28s cubic-bezier(.2,.8,.2,1), opacity .28s ease; }
        #suivi-modal.active .suivi2-card { transform:translate(-50%, -50%) scale(1); opacity:1; }
        .suivi2-head { display:flex; align-items:center; gap:0.8rem; padding:1.1rem 1.25rem; background:linear-gradient(135deg,#1b4332,#2d6a4f); border-bottom:1px solid rgba(212,175,55,0.25); position:relative; flex-shrink:0; }
        .suivi2-head .ic { width:40px; height:40px; border-radius:12px; background:rgba(212,175,55,0.18); border:1px solid rgba(212,175,55,0.35); display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
        .suivi2-head .t { min-width:0; }
        .suivi2-head .t b { display:block; color:#fff; font-size:1rem; }
        .suivi2-head .t span { display:block; color:#b7d4c5; font-size:0.78rem; }
        .suivi2-close { position:absolute; top:0.7rem; right:0.85rem; background:rgba(255,255,255,0.12); color:#fff; border:0; width:28px; height:28px; border-radius:50%; font-size:1.1rem; line-height:1; cursor:pointer; }
        .suivi2-close:hover { background:rgba(255,255,255,0.25); }
        .suivi2-body { padding:1rem 1.25rem; overflow-y:auto; flex:1; }
        .suivi2-note { font-size:0.8rem; margin:0 0 0.9rem; }
        .suivi2-row { display:flex; flex-direction:column; gap:0.35rem; padding:0.7rem 0; border-bottom:1px solid rgba(255,255,255,0.07); }
        .suivi2-row:last-child { border-bottom:none; }
        .suivi2-row > label, .suivi2-row > .k { font-size:0.72rem; color:#f2d574; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; }
        .suivi2-row .v { color:#fff; font-size:0.95rem; font-weight:600; }
        .suivi2-row select { width:100%; padding:0.65rem 0.8rem; background:rgba(255,255,255,0.06); border:1px solid var(--glass-border); border-radius:12px; color:#fff; font-size:0.92rem; color-scheme:dark; }
        .suivi2-row select option { background-color:#0c241a; color:#fff; }
        .suivi2-row select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(212,175,55,0.15); }
        .suivi2-foot { display:flex; gap:0.6rem; padding:0.9rem 1.25rem; border-top:1px solid rgba(255,255,255,0.08); flex-shrink:0; }
        .suivi2-foot .btn { flex:1; justify-content:center; }
        /* Boutons d'action compacts (Voir / Modifier / Suivi / Valider / Supprimer) */
        .table-actions { display:flex; flex-wrap:wrap; gap:0.35rem; align-items:center; }
        .table-actions .btn { padding:0.32rem 0.62rem; font-size:0.72rem; border-radius:8px; line-height:1.15; white-space:nowrap; }
        @media (max-width: 600px) {
            .section-header { flex-direction:column; align-items:flex-start; gap:0.75rem; }
            .adh-filters-adv input, .adh-select { flex:1 1 100%; }
            .table-responsive { overflow:visible; }
            .admin-table thead { display:none; }
            .admin-table, .admin-table tbody, .admin-table tr, .admin-table td { display:block; width:100%; }
            .admin-table tr { border:1px solid var(--glass-border); border-radius:14px; margin-bottom:0.85rem; padding:0.85rem 1rem; background:rgba(255,255,255,0.03); }
            .admin-table td { border:none !important; padding:0.3rem 0; }
            .table-actions { display:flex; flex-direction:column; align-items:stretch; gap:0.5rem; margin-top:0.4rem; }
            .table-actions .btn, .table-actions a, .table-actions button { width:100%; justify-content:center; display:flex; }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/admin_menu.php'; ?>
            <div class="dashboard-main">
        <div class="admin-welcome-banner glass-card" style="margin-top:2rem; margin-bottom:1rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
            <span>Inscriptions au Dahira — <strong class="gold-text"><?php echo count($adhesions); ?></strong> demande(s)</span>
            <a href="admin_dashboard.php" class="btn btn-secondary btn-sm">← Tableau de bord</a>
        </div>

        <section class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">Demandes d'adhésion</h2>
                <div class="adh-filter">
                    <button type="button" class="adh-filter-btn active" data-filter="all">Tous</button>
                    <button type="button" class="adh-filter-btn" data-filter="pending">En attente</button>
                    <button type="button" class="adh-filter-btn" data-filter="approved">Validées</button>
                    <button type="button" class="adh-filter-btn" data-filter="rejected">Refusées</button>
                </div>
            </div>

            <?php if (empty($adhesions)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <p>Aucune inscription au Dahira pour le moment.</p>
                </div>
            <?php else: ?>
                <button type="button" id="adh-toggle" class="adh-toggle">🔎 Filtres <span class="chev">▾</span></button>
                <div class="adh-filters-adv is-hidden" id="adh-panel">
                    <input type="text" id="adh-search" placeholder="🔍 Nom, prénom ou email…">
                    <?php echo filter_select('f-commission', 'Commission', $fltCommissions); ?>
                    <?php echo filter_select('f-secteur', "Secteur", $fltSecteurs); ?>
                    <?php echo filter_select('f-type', 'Type', $fltTypes); ?>
                    <?php echo filter_select('f-statut', 'Statut', $fltStatuts); ?>
                    <?php echo filter_select('f-genre', 'Genre', $fltGenres); ?>
                    <?php if (!empty($fltAnnees)) echo filter_select('f-annee', "Année", $fltAnnees); ?>
                    <button type="button" id="adh-reset">✕ Réinitialiser</button>
                    <span class="adh-count" id="adh-count"></span>
                </div>
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
                                <tr class="adh-row"
                                    data-status="<?php echo htmlspecialchars($a['status'], ENT_QUOTES); ?>"
                                    data-commission="<?php echo htmlspecialchars($a['souhait_commission'] ?? '', ENT_QUOTES); ?>"
                                    data-secteur="<?php echo htmlspecialchars($a['secteur_activite'] ?? '', ENT_QUOTES); ?>"
                                    data-type="<?php echo htmlspecialchars($a['type_adhesion'] ?? '', ENT_QUOTES); ?>"
                                    data-statut="<?php echo htmlspecialchars($a['statut'] ?? '', ENT_QUOTES); ?>"
                                    data-genre="<?php echo htmlspecialchars($a['genre'] ?? '', ENT_QUOTES); ?>"
                                    data-annee="<?php echo htmlspecialchars($a['annee_integration'] ?? '', ENT_QUOTES); ?>"
                                    data-search="<?php echo htmlspecialchars(mb_strtolower($a['prenom'] . ' ' . $a['nom'] . ' ' . $a['email']), ENT_QUOTES); ?>">
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
                                        <?php
                                            $st = $a['status'];
                                            if ($st === 'approved') {
                                                $stLabel = '✓ Validée';
                                                $stStyle = 'background:rgba(45,106,79,0.22); color:#7bd8a6; border:1px solid rgba(45,106,79,0.55);';
                                            } elseif ($st === 'rejected') {
                                                $stLabel = '✕ Refusée';
                                                $stStyle = 'background:rgba(220,80,80,0.16); color:#ff9a9a; border:1px solid rgba(220,80,80,0.45);';
                                            } else {
                                                $stLabel = '⏳ En attente';
                                                $stStyle = 'background:rgba(212,175,55,0.16); color:#ffd873; border:1px solid rgba(212,175,55,0.45);';
                                            }
                                        ?>
                                        <span class="badge" style="white-space:nowrap; font-weight:700; <?php echo $stStyle; ?>"><?php echo $stLabel; ?></span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="profile.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">Voir</a>
                                            <a href="membre.php?id=<?php echo (int)$a['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--gold); color:var(--gold);">Modifier</a>
                                            <button type="button"
                                                onclick='showSuivi(<?php echo json_encode([
                                                    "id" => (int)$a["id"],
                                                    "membre" => $a["prenom"] . ' ' . $a["nom"],
                                                    "status" => $a["status"],
                                                    "integrateur_id" => (int)($a["integrateur_id"] ?? 0),
                                                    "integrateur_nom" => $a["integrateur_nom"],
                                                    "commission" => $a["souhait_commission"],
                                                    "presentation" => $a["presentation_ok"],
                                                    "test_kourel" => $a["test_kourel"],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'
                                                class="btn btn-secondary btn-sm">Suivi</button>
                                            <?php if ($a['status'] !== 'approved'): ?>
                                                <button onclick="handleAction('approve', <?php echo $a['id']; ?>, '<?php echo addslashes(htmlspecialchars($name)); ?>')" class="btn btn-primary btn-sm" style="background:var(--success); box-shadow:none;">Valider</button>
                                            <?php else: ?>
                                                <button onclick="handleAction('suspend', <?php echo $a['id']; ?>, '<?php echo addslashes(htmlspecialchars($name)); ?>')" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">Passage en attente</button>
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
            </div>
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

    <!-- Suivi intégration modal (éditable si compte non validé) -->
    <div id="suivi-modal" class="modal-overlay">
        <div class="suivi2-card">
            <div class="suivi2-head">
                <div class="ic">🧭</div>
                <div class="t"><b>Suivi intégration</b><span id="suivi-sub"></span></div>
                <button type="button" class="suivi2-close" onclick="closeSuiviModal()" aria-label="Fermer">&times;</button>
            </div>
            <form id="suivi-form" method="POST" action="admin_adhesions.php" style="display:flex; flex-direction:column; min-height:0; flex:1;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="save_suivi">
                <input type="hidden" name="member_id" id="suivi-mid" value="">
                <div class="suivi2-body" id="suivi-body"></div>
                <div class="suivi2-foot" id="suivi-footer"></div>
            </form>
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
                title = "Passage en attente";
                msg = `Remettre l'adhésion de <strong>${memberName}</strong> en attente ?`;
                confirmBtn.classList.add('btn-secondary'); confirmBtn.textContent = 'Passage en attente';
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

        function esc(s){ const d=document.createElement('div'); d.textContent=(s===null||s===undefined||s==='')?'—':s; return d.innerHTML; }
        function escAttr(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/"/g,'&quot;'); }
        const SUIVI_INTEGRATEURS = <?php echo json_encode($integrateurs ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        const SUIVI_COMMISSIONS  = <?php echo json_encode($commissions ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;

        function showSuivi(a) {
            const body = document.getElementById('suivi-body');
            const footer = document.getElementById('suivi-footer');
            const sub = document.getElementById('suivi-sub');
            document.getElementById('suivi-mid').value = a.id;
            const editable = (a.status !== 'approved');
            if (sub) sub.textContent = (a.membre || '') + (editable ? ' · à compléter' : ' · validé');

            if (editable) {
                let iopts = '<option value="">— Non assigné —</option>';
                SUIVI_INTEGRATEURS.forEach(function (it) {
                    iopts += `<option value="${it.id}" ${Number(a.integrateur_id) === Number(it.id) ? 'selected' : ''}>${esc(it.nom)}</option>`;
                });
                let copts = '<option value="">— Aucune —</option>';
                SUIVI_COMMISSIONS.forEach(function (c) {
                    copts += `<option value="${escAttr(c)}" ${a.commission === c ? 'selected' : ''}>${esc(c)}</option>`;
                });
                body.innerHTML =
                    `<p class="suivi2-note" style="color:#f2d574;">🕓 Compte en attente — complétez le suivi ci-dessous.</p>
                     <div class="suivi2-row"><label>Intégrateur en charge</label><select name="integrateur_id">${iopts}</select></div>
                     <div class="suivi2-row"><label>Souhait commission</label><select name="souhait_commission">${copts}</select></div>
                     <div class="suivi2-row"><label>Présentation Ok / non OK</label><select name="presentation_ok"><option value="">—</option><option ${a.presentation === 'OK' ? 'selected' : ''}>OK</option><option ${a.presentation === 'Non OK' ? 'selected' : ''}>Non OK</option></select></div>
                     <div class="suivi2-row"><label>Test Kourel</label><select name="test_kourel"><option value="">—</option><option ${a.test_kourel === 'Oui' ? 'selected' : ''}>Oui</option><option ${a.test_kourel === 'Non' ? 'selected' : ''}>Non</option></select></div>`;
                footer.innerHTML =
                    `<button type="button" class="btn btn-secondary btn-sm" onclick="closeSuiviModal()">Annuler</button>
                     <button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer</button>`;
            } else {
                body.innerHTML =
                    `<p class="suivi2-note" style="color:#b7e4c7;">✅ Compte validé — suivi en lecture seule.</p>
                     <div class="suivi2-row"><span class="k">Intégrateur en charge</span><span class="v">${esc(a.integrateur_nom)}</span></div>
                     <div class="suivi2-row"><span class="k">Souhait commission</span><span class="v">${esc(a.commission)}</span></div>
                     <div class="suivi2-row"><span class="k">Présentation</span><span class="v">${esc(a.presentation)}</span></div>
                     <div class="suivi2-row"><span class="k">Test Kourel</span><span class="v">${esc(a.test_kourel)}</span></div>`;
                footer.innerHTML = `<button type="button" class="btn btn-primary btn-sm" onclick="closeSuiviModal()">Fermer</button>`;
            }
            const modal = document.getElementById('suivi-modal');
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('active'), 10);
        }
        function closeSuiviModal() {
            const modal = document.getElementById('suivi-modal');
            modal.classList.remove('active');
            setTimeout(() => { modal.style.display = 'none'; }, 300);
        }
        document.getElementById('suivi-modal').addEventListener('click', function(e){ if(e.target===this) closeSuiviModal(); });

        // Filtres combinés : statut (pastilles) + commission / secteur / type / statut / genre / année + recherche
        (function () {
            const btns = document.querySelectorAll('.adh-filter-btn');
            const rows = Array.from(document.querySelectorAll('.adh-row'));
            const search = document.getElementById('adh-search');
            const selects = {
                commission: document.getElementById('f-commission'),
                secteur:    document.getElementById('f-secteur'),
                type:       document.getElementById('f-type'),
                statut:     document.getElementById('f-statut'),
                genre:      document.getElementById('f-genre'),
                annee:      document.getElementById('f-annee')
            };
            const countEl = document.getElementById('adh-count');
            let statusFilter = 'all';

            function norm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }

            function apply() {
                const term = norm(search ? search.value : '');
                let visible = 0;
                rows.forEach(function (r) {
                    let show = (statusFilter === 'all' || r.getAttribute('data-status') === statusFilter);
                    for (const key in selects) {
                        const sel = selects[key];
                        if (show && sel && sel.value) {
                            show = (r.getAttribute('data-' + key) === sel.value);
                        }
                    }
                    if (show && term) {
                        show = norm(r.getAttribute('data-search')).includes(term);
                    }
                    r.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                if (countEl) countEl.textContent = visible + ' résultat(s)';
            }

            btns.forEach(function (b) {
                b.addEventListener('click', function () {
                    btns.forEach(x => x.classList.remove('active'));
                    b.classList.add('active');
                    statusFilter = b.getAttribute('data-filter');
                    apply();
                });
            });
            const toggle = document.getElementById('adh-toggle');
            const panel = document.getElementById('adh-panel');
            if (toggle && panel) toggle.addEventListener('click', function () {
                panel.classList.toggle('is-hidden');
                toggle.classList.toggle('open');
            });

            if (search) search.addEventListener('input', apply);
            for (const key in selects) { if (selects[key]) selects[key].addEventListener('change', apply); }

            const reset = document.getElementById('adh-reset');
            if (reset) reset.addEventListener('click', function () {
                if (search) search.value = '';
                for (const key in selects) { if (selects[key]) selects[key].value = ''; }
                btns.forEach(x => x.classList.remove('active'));
                const allBtn = document.querySelector('.adh-filter-btn[data-filter="all"]');
                if (allBtn) allBtn.classList.add('active');
                statusFilter = 'all';
                apply();
            });

            apply();
        })();
    </script>
</body>
</html>
