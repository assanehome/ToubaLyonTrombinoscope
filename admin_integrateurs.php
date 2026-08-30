<?php
/**
 * Touba Lyon 2026 - Rôle « intégrateur » porté par les membres
 * On donne (ou retire) le rôle d'intégrateur à un membre.
 * Réservé aux administrateurs ET aux responsables de la commission « Intégration ».
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dahira_emails.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Accès : administrateurs OU responsables de la commission « Intégration ».
$isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSuiviManager = false;
if (!$isAdmin && !empty($_SESSION['player_id'])) {
    try {
        $isSuiviManager = ((int) $pdo->query("SELECT COUNT(*) FROM commission_gestionnaires cg JOIN commissions c ON c.id = cg.commission_id WHERE cg.membre_id = " . (int) $_SESSION['player_id'] . " AND LOWER(c.nom) LIKE '%gration%'")->fetchColumn() > 0);
    } catch (Exception $e) {
        $isSuiviManager = false;
    }
    $_SESSION['is_suivi_integration'] = $isSuiviManager;
}
if (!$isAdmin && !$isSuiviManager) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        $member_id = intval($_POST['member_id'] ?? 0);
        if ($member_id > 0 && in_array($action, ['grant', 'revoke'], true)) {
            try {
                $stmt = $pdo->prepare("SELECT prenom, nom, email FROM membres WHERE id = ?");
                $stmt->execute([$member_id]);
                $m = $stmt->fetch();
                if (!$m) {
                    $error = "Membre introuvable.";
                } else {
                    $fullName = htmlspecialchars($m['prenom'] . ' ' . $m['nom']);
                    if ($action === 'grant') {
                        $pdo->prepare("UPDATE membres SET is_integrateur = 1 WHERE id = ?")->execute([$member_id]);
                        $sent = !empty($m['email']) ? @send_role_notification($m['email'], $m['prenom'] . ' ' . $m['nom'], 'integrateur') : false;
                        $success = "Le rôle d'intégrateur a été attribué à {$fullName}." . ($sent ? " Un e-mail de notification lui a été envoyé." : "");
                    } else {
                        $pdo->prepare("UPDATE membres SET is_integrateur = 0 WHERE id = ?")->execute([$member_id]);
                        // On retire aussi ce membre des assignations (il n'est plus intégrateur)
                        $pdo->prepare("UPDATE membres SET integrateur_id = NULL WHERE integrateur_id = ?")->execute([$member_id]);
                        $success = "Le rôle d'intégrateur a été retiré à {$fullName}.";
                    }
                }
            } catch (Exception $e) {
                error_log('Touba Lyon admin_integrateurs (role): ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
}

try {
    $membres = $pdo->query("SELECT id, prenom, nom, email, is_integrateur, souhait_commission FROM membres WHERE status = 'approved' ORDER BY is_integrateur DESC, nom ASC")->fetchAll();
    $nbInteg = 0; $comSet = [];
    foreach ($membres as $mm) {
        if ((int)$mm['is_integrateur'] === 1) { $nbInteg++; }
        if (!empty($mm['souhait_commission'])) { $comSet[$mm['souhait_commission']] = true; }
    }
    $commissionsFilter = array_keys($comSet); sort($commissionsFilter);
    // Valeur par défaut : commission « Intégration » si présente
    $defaultCom = '';
    foreach ($commissionsFilter as $cv) {
        $nn = mb_strtolower($cv);
        if ($nn === 'intégration' || $nn === 'integration' || mb_strpos($nn, 'gration') !== false) { $defaultCom = $cv; break; }
    }
} catch (Exception $e) {
    error_log('Touba Lyon admin_integrateurs (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rôles intégrateur - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .integ-wrap { max-width: 900px; margin: 2rem auto; }
        .role-search { margin-bottom: 1rem; }
        .role-search input { width:100%; padding:0.7rem 1.1rem; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); border-radius:50px; color:var(--white); font-size:0.95rem; }
        .role-search input:focus { outline:none; border-color:var(--accent); }
        .role-filters { display:flex; gap:0.6rem; flex-wrap:wrap; margin-bottom:1rem; align-items:center; }
        .role-filters input, .role-select { background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--white); font-size:0.9rem; padding:0.6rem 1rem; color-scheme:dark; }
        .role-filters input { flex:1 1 240px; border-radius:50px; }
        .role-select { flex:1 1 180px; border-radius:10px; }
        .role-select option { background-color:#0c241a; color:#fff; }
        .role-filters input:focus, .role-select:focus { outline:none; border-color:var(--accent); }
        .role-count { font-size:0.82rem; color:var(--text-muted); margin-left:auto; }
        @media (max-width:600px){ .role-filters input, .role-select { flex:1 1 100%; } }
        .role-badge { display:inline-block; background:rgba(212,175,55,0.15); color:var(--gold); border:1px solid rgba(212,175,55,0.4); border-radius:50px; padding:0.05rem 0.6rem; font-size:0.72rem; font-weight:700; }
        /* ── Optimisation mobile : lignes en cartes, bouton pleine largeur ── */
        @media (max-width: 600px) {
            .table-responsive { overflow:visible; }
            .admin-table thead { display:none; }
            .admin-table, .admin-table tbody, .admin-table tr, .admin-table td { display:block; width:100%; }
            .admin-table tr { border:1px solid var(--glass-border); border-radius:14px; margin-bottom:0.75rem; padding:0.85rem 1rem; background:rgba(255,255,255,0.03); }
            .admin-table td { border:none !important; padding:0.25rem 0; }
            .admin-table td form { display:block; width:100%; margin-top:0.5rem; }
            .admin-table td .btn { display:flex; width:100%; justify-content:center; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">
        <div class="integ-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <span>Intégrateurs — <strong class="gold-text"><?php echo $nbInteg; ?></strong> membre(s) avec le rôle</span>
                <a href="<?php echo $isAdmin ? 'admin_dashboard.php' : 'admin_reponses.php'; ?>" class="btn btn-secondary btn-sm">← <?php echo $isAdmin ? 'Tableau de bord' : 'Suivi intégration'; ?></a>
            </div>

            <div class="form-card" style="max-width:none; margin-bottom:1.5rem;">
                <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">
                    Le rôle d'<strong>intégrateur</strong> permet à un membre d'accéder au <strong>suivi des intégrations</strong> (depuis son espace membre)
                    et de compléter les informations des inscrits qui lui sont assignés. Donnez ou retirez ce rôle ci-dessous.
                </p>
            </div>

            <div class="role-filters">
                <input type="text" id="role-search-input" placeholder="🔍 Rechercher un membre par nom, prénom ou email…">
                <select id="role-commission" class="role-select">
                    <option value="">Commission : toutes</option>
                    <?php foreach ($commissionsFilter as $cv): ?>
                        <option value="<?php echo htmlspecialchars($cv, ENT_QUOTES); ?>" <?php echo ($cv === $defaultCom) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cv); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="role-count" id="role-count"></span>
            </div>

            <section class="dashboard-section">
                <div class="section-header">
                    <h2 class="section-title">Membres</h2>
                    <span class="badge badge-approved"><?php echo count($membres); ?> membre(s)</span>
                </div>
                <?php if (empty($membres)): ?>
                    <div class="empty-state"><div class="empty-state-icon">👤</div><p>Aucun membre validé pour le moment.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table admin-table--compact">
                        <thead><tr><th>Membre</th><th>Rôle</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($membres as $mm): ?>
                                <?php $isInteg = ((int)$mm['is_integrateur'] === 1); ?>
                                <tr data-search="<?php echo htmlspecialchars(mb_strtolower($mm['prenom'] . ' ' . $mm['nom'] . ' ' . $mm['email']), ENT_QUOTES); ?>" data-commission="<?php echo htmlspecialchars($mm['souhait_commission'] ?? '', ENT_QUOTES); ?>">
                                    <td>
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></div>
                                        <div style="font-size:0.8rem; color:var(--text-muted); word-break:break-all;"><?php echo htmlspecialchars($mm['email']); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($isInteg): ?>
                                            <span class="role-badge">🧭 Intégrateur</span>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form action="admin_integrateurs.php" method="POST" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="member_id" value="<?php echo (int)$mm['id']; ?>">
                                            <?php if ($isInteg): ?>
                                                <input type="hidden" name="action" value="revoke">
                                                <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">Retirer le rôle</button>
                                            <?php else: ?>
                                                <input type="hidden" name="action" value="grant">
                                                <button type="submit" class="btn btn-primary btn-sm">Donner le rôle</button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="empty-state" id="role-noresult" style="display:none;">
                    <div class="empty-state-icon">🔍</div><p>Aucun membre ne correspond à votre recherche.</p>
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
                <?php if (!empty($success)): ?><h3 class="gold-text">Opération réussie</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?>
            </div>
            <div class="modal-body"><p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>

    <script>
        (function () {
            var roleInput = document.getElementById('role-search-input');
            var comSel = document.getElementById('role-commission');
            var rows = Array.from(document.querySelectorAll('tr[data-search]'));
            var noResult = document.getElementById('role-noresult');
            var countEl = document.getElementById('role-count');
            function norm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
            function apply() {
                var term = norm(roleInput ? roleInput.value : '');
                var com = comSel ? comSel.value : '';
                var count = 0;
                rows.forEach(function (r) {
                    var show = true;
                    if (com) { show = (r.getAttribute('data-commission') === com); }
                    if (show && term) { show = norm(r.getAttribute('data-search')).indexOf(term) !== -1; }
                    r.style.display = show ? '' : 'none';
                    if (show) count++;
                });
                if (noResult) noResult.style.display = count === 0 ? 'block' : 'none';
                if (countEl) countEl.textContent = count + ' membre(s)';
            }
            if (roleInput) roleInput.addEventListener('input', apply);
            if (comSel) comSel.addEventListener('change', apply);
            apply();
        })();
    </script>
</body>
</html>
