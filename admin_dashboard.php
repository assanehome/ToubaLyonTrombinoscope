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

// Première connexion : forcer le changement de mot de passe avant tout accès.
if (!empty($_SESSION['admin_must_change'])) {
    header('Location: admin_password.php');
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
    // Stats : "En attente" = inscriptions Dahira - Mubawwa-A-Sidqin en attente (les adhésions en attente
    // sont gérées dans admin_adhesions.php) ; "Validés" = TOUS les membres validés, y compris
    // les adhésions Dahira approuvées.
    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    $counts['pending']  = (int) $pdo->query("SELECT COUNT(*) FROM membres WHERE status = 'pending'")->fetchColumn();
    $counts['approved'] = (int) $pdo->query("SELECT COUNT(*) FROM membres WHERE status = 'approved'")->fetchColumn();
    $totalMembers = $counts['pending'] + $counts['approved'];

    // List of pending members (TOUS les membres en attente)
    $stmt = $pdo->query("SELECT * FROM membres WHERE status = 'pending' ORDER BY created_at DESC");
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

// Valeurs distinctes (sur l'ensemble des membres) pour alimenter les filtres.
$fltCommissions = []; $fltSecteurs = []; $fltTypes = []; $fltStatuts = []; $fltGenres = []; $fltAnnees = [];
foreach (array_merge($pendingMembers, $approvedMembers) as $a) {
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
    foreach ($options as $o) { $h .= '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '">' . htmlspecialchars($o) . '</option>'; }
    $h .= '</select>';
    return $h;
}

/** Attributs data-* pour le filtrage d'une ligne/carte membre. */
function member_filter_attrs($m) {
    $e = function ($v) { return htmlspecialchars($v ?? '', ENT_QUOTES); };
    return ' data-status="' . $e($m['status'] ?? '') . '"'
        . ' data-civilite="' . $e($m['civilite'] ?? '') . '"'
        . ' data-commission="' . $e($m['souhait_commission'] ?? '') . '"'
        . ' data-secteur="' . $e($m['secteur_activite'] ?? '') . '"'
        . ' data-type="' . $e($m['type_adhesion'] ?? '') . '"'
        . ' data-statut="' . $e($m['statut'] ?? '') . '"'
        . ' data-genre="' . $e($m['genre'] ?? '') . '"'
        . ' data-annee="' . $e($m['annee_integration'] ?? '') . '"'
        . ' data-search="' . $e(mb_strtolower(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '') . ' ' . ($m['email'] ?? ''))) . '"';
}

/** Détail minimal d'un membre (juste le type d'adhésion). */
function member_detail_html($m) {
    if (empty($m['type_adhesion'])) { return ''; }
    return '<span class="badge" style="background:rgba(212,175,55,0.15); color:var(--gold); border:1px solid rgba(212,175,55,0.35); font-size:0.68rem; margin-top:0.25rem; display:inline-block;">' . htmlspecialchars($m['type_adhesion']) . '</span>';
}

/** Photo d'un membre, ou pastille à initiales si aucune photo. */
function member_photo_html($m, $cls = 'table-photo', $link = true) {
    $p = $m['photo_path'] ?? '';
    if ($p !== '') {
        $u = 'uploads/' . htmlspecialchars($p, ENT_QUOTES);
        $img = '<img src="' . $u . '" class="' . $cls . '" alt="Photo">';
        // $link=false : pas de lien (ex: carte déjà cliquable → évite l'imbrication de <a>)
        return $link ? '<a href="' . $u . '" target="_blank">' . $img . '</a>' : $img;
    }
    $ini = strtoupper(mb_substr($m['prenom'] ?? '', 0, 1) . mb_substr($m['nom'] ?? '', 0, 1));
    return '<span class="' . $cls . ' photo-ph">' . htmlspecialchars($ini !== '' ? $ini : '?') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - Dahira - Mubawwa-A-Sidqin</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .adh-filters-adv { display:flex; flex-wrap:wrap; gap:0.6rem; margin:0 0 1rem; align-items:center; }
        .adh-filters-adv input, .adh-select { background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); border-radius:10px; color:var(--white); font-size:0.85rem; padding:0.5rem 0.75rem; color-scheme:dark; }
        .adh-filters-adv input { flex:1 1 220px; min-width:180px; border-radius:50px; }
        .adh-select { flex:1 1 150px; min-width:140px; }
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
        /* Indicateurs compacts (moins de place) */
        .stats-grid { gap:0.6rem !important; margin-bottom:1rem !important; grid-template-columns:repeat(3, 1fr) !important; }
        .stats-grid .stat-card { padding:0.75rem 0.9rem !important; border-radius:12px !important; display:flex; flex-direction:column; gap:0.15rem; }
        .stats-grid .stat-value { font-size:1.7rem !important; line-height:1.1 !important; }
        .stats-grid .stat-title { font-size:0.72rem !important; }
        @media (max-width:520px){ .stats-grid .stat-value { font-size:1.4rem !important; } .stats-grid .stat-title { font-size:0.62rem !important; } }
        .dash-chips { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.85rem; }
        .dash-chip { background:rgba(255,255,255,0.05); color:var(--white); border:1px solid var(--glass-border); border-radius:50px; padding:0.45rem 1.1rem; font-size:0.85rem; font-weight:600; cursor:pointer; transition:all 0.2s ease; }
        .dash-chip:hover { border-color:var(--accent); }
        .dash-chip.active { background:var(--accent); color:var(--secondary); border-color:var(--accent); }
        .photo-ph { display:inline-flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#d4af37,#b8902f); color:#0c241a; font-weight:800; text-transform:uppercase; }
        .table-photo.photo-ph { width:44px; height:44px; border-radius:50%; font-size:0.95rem; }
        .member-photo.photo-ph { width:100%; height:100%; font-size:2.5rem; border-radius:0; }
        @media (max-width: 600px) {
            .adh-filters-adv input, .adh-select { flex:1 1 100%; }
            .table-responsive { overflow:visible; }
            .admin-table thead { display:none; }
            .admin-table, .admin-table tbody, .admin-table tr, .admin-table td { display:block; width:100%; }
            .admin-table tr { border:1px solid var(--glass-border); border-radius:14px; margin-bottom:0.85rem; padding:0.85rem 1rem; background:rgba(255,255,255,0.03); }
            .admin-table td { border:none !important; padding:0.3rem 0; }
            .table-actions { display:flex; flex-direction:column; align-items:stretch; gap:0.5rem; margin-top:0.4rem; }
            .table-actions .btn, .table-actions a, .table-actions button { width:100%; justify-content:center; display:flex; }
        }
        /* ── Fiche membre (popup moderne, responsive) — identique à index ── */
        #member-modal { position:fixed; inset:0; overflow:hidden; align-items:center; justify-content:center; }
        .mi-card { position:fixed; left:50%; top:50%; width:calc(100vw - 28px); max-width:380px; max-height:86vh; display:flex; flex-direction:column;
            background:linear-gradient(180deg,#123528 0%, #0c241a 100%); border:1px solid rgba(212,175,55,0.25); border-radius:18px; overflow:hidden;
            box-shadow:0 30px 80px rgba(0,0,0,0.55); z-index:2001;
            transform:translate(-50%, -46%) scale(0.98); opacity:0; transition:transform .28s cubic-bezier(.2,.8,.2,1), opacity .28s ease; }
        #member-modal.active .mi-card { transform:translate(-50%, -50%) scale(1); opacity:1; }
        .mi-head { position:relative; padding:0.9rem 1rem 0.75rem; background:linear-gradient(135deg,#1b4332,#2d6a4f); text-align:center; flex-shrink:0; }
        .mi-photo-wrap { width:62px; height:62px; border-radius:50%; overflow:hidden; border:2px solid var(--gold); margin:0 auto 0.45rem; box-shadow:0 4px 12px rgba(0,0,0,0.4); background:#081c15; }
        .mi-photo-wrap img { width:100%; height:100%; object-fit:cover; }
        .mi-head h2 { color:#fff; font-size:1.1rem; margin:0 0 0.25rem; }
        .mi-close { position:absolute; top:0.5rem; right:0.6rem; background:rgba(255,255,255,0.15); color:#fff; border:0; width:26px; height:26px; border-radius:50%; font-size:1.05rem; line-height:1; cursor:pointer; }
        .mi-close:hover { background:rgba(255,255,255,0.3); }
        .mi-body { padding:0.35rem 1rem 0.6rem; overflow-y:auto; flex:1; scrollbar-width:none; -ms-overflow-style:none; }
        .mi-body::-webkit-scrollbar { width:0; height:0; display:none; }
        .mi-row { display:flex; align-items:baseline; justify-content:space-between; gap:0.75rem; padding:0.4rem 0; border-bottom:1px solid rgba(255,255,255,0.07); }
        .mi-row:last-child { border-bottom:none; }
        .mi-row .k { font-size:0.68rem; color:#f2d574; text-transform:uppercase; letter-spacing:0.02em; font-weight:600; flex-shrink:0; }
        .mi-row .v { color:#fff; font-size:0.9rem; font-weight:600; word-break:break-word; text-align:right; }
        .mi-foot { padding:0.6rem 1rem; border-top:1px solid rgba(255,255,255,0.08); text-align:center; flex-shrink:0; }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/admin_menu.php'; ?>
            <div class="dashboard-main">

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


        <!-- Pastilles rapides par civilité (comme sur le Trombinoscope public) -->
        <div class="dash-chips">
            <button type="button" class="dash-chip active" data-civ="all">Tous</button>
            <button type="button" class="dash-chip" data-civ="Goor Yalla">Goor Yalla</button>
            <button type="button" class="dash-chip" data-civ="Sokhna">Sokhna</button>
        </div>

        <!-- Filtres combinés (groupe replié par défaut) -->
        <button type="button" id="adh-toggle" class="adh-toggle">🔎 Filtres <span class="chev">▾</span></button>
        <div class="adh-filters-adv is-hidden" id="adh-panel">
            <input type="text" id="adh-search" placeholder="🔍 Nom, prénom ou email…">
            <?php echo filter_select('f-commission', 'Commission', $fltCommissions); ?>
            <?php echo filter_select('f-secteur', 'Secteur', $fltSecteurs); ?>
            <?php echo filter_select('f-type', 'Type', $fltTypes); ?>
            <?php echo filter_select('f-statut', 'Statut', $fltStatuts); ?>
            <?php echo filter_select('f-genre', 'Genre', $fltGenres); ?>
            <?php if (!empty($fltAnnees)) echo filter_select('f-annee', 'Année', $fltAnnees); ?>
            <button type="button" id="adh-reset">✕ Réinitialiser</button>
            <span class="adh-count" id="adh-count"></span>
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
                                <tr class="filterable"<?php echo member_filter_attrs($m); ?>>
                                    <td><?php echo member_photo_html($m); ?></td>
                                    <td>
                                        <div style="font-weight:600;"><span style="text-transform:capitalize;"><?php echo htmlspecialchars($m['prenom']); ?></span> <span style="text-transform:uppercase;"><?php echo htmlspecialchars($m['nom']); ?></span></div>
                                        <div style="font-size:0.8rem; color:var(--text-muted); word-break:break-all;"><?php echo htmlspecialchars($m['email']); ?></div>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <?php
                                                $actionFullName = $m['prenom'] . ' ' . $m['nom'];
                                            ?>
                                            <a href="membre.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-secondary btn-sm" style="border-color: var(--accent); color: var(--accent);">Voir la fiche</a>
                                            <button onclick="handleAction('approve', <?php echo $m['id']; ?>, '<?php echo addslashes(htmlspecialchars($actionFullName)); ?>', '<?php echo htmlspecialchars($m['photo_path']); ?>')" class="btn btn-primary btn-sm" style="background: var(--success); box-shadow: none;">✓ Valider</button>
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
                                <tr class="filterable"<?php echo member_filter_attrs($m); ?>>
                                    <td><?php echo member_photo_html($m); ?></td>
                                    <td>
                                        <div style="font-weight:600;"><span style="text-transform:capitalize;"><?php echo htmlspecialchars($m['prenom']); ?></span> <span style="text-transform:uppercase;"><?php echo htmlspecialchars($m['nom']); ?></span></div>
                                        <div style="font-size:0.8rem; color:var(--text-muted); word-break:break-all;"><?php echo htmlspecialchars($m['email']); ?></div>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <?php
                                                $actionFullName = $m['prenom'] . ' ' . $m['nom'];
                                            ?>
                                            <a href="membre.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-secondary btn-sm" style="border-color: var(--accent); color: var(--accent);">Voir la fiche</a>
                                            <button onclick="handleAction('suspend', <?php echo $m['id']; ?>, '<?php echo addslashes(htmlspecialchars($actionFullName)); ?>', '<?php echo htmlspecialchars($m['photo_path']); ?>')" class="btn btn-secondary btn-sm" style="color: var(--warning); border-color: var(--warning);">Passer en attente</button>
                                            <button onclick="handleAction('delete', <?php echo $m['id']; ?>, '<?php echo addslashes(htmlspecialchars($actionFullName)); ?>', '<?php echo htmlspecialchars($m['photo_path']); ?>')" class="btn btn-danger btn-sm">Supprimer</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Visual Dahira - Mubawwa-A-Sidqin Card Grid View -->
                <div id="validated-grid-view" style="display: none;">
                    <div class="trombi-grid">
                        <?php foreach ($approvedMembers as $m): ?>
                            <?php $fullName = $m['prenom'] . ' ' . $m['nom']; ?>
                            <div class="member-card filterable" style="cursor:pointer;" onclick="showMemberInfo(this)"
                                data-id="<?php echo (int)$m['id']; ?>"
                                data-photo="<?php echo htmlspecialchars($m['photo_path'] ?? '', ENT_QUOTES); ?>"
                                data-name="<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>"
                                data-commune="<?php echo htmlspecialchars($m['commune'] ?? '', ENT_QUOTES); ?>"
                                data-profession="<?php echo htmlspecialchars($m['profession'] ?? '', ENT_QUOTES); ?>"
                                data-email="<?php echo htmlspecialchars($m['email'] ?? '', ENT_QUOTES); ?>"
                                <?php echo member_filter_attrs($m); ?>>
                                <div class="member-photo-container">
                                    <?php echo member_photo_html($m, 'member-photo', false); ?>
                                </div>
                                <div class="member-info">
                                    <h3 class="member-name"><?php echo htmlspecialchars($fullName); ?></h3>
                                    <?php if (!empty($m['civilite'])): ?>
                                        <span class="member-civilite-badge"><?php echo htmlspecialchars($m['civilite']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
        </div> <!-- Closes validated-tab -->
            </div> <!-- Closes dashboard-main -->
        </div> <!-- Closes dashboard-layout -->
    </main>

    <!-- Fiche membre (au clic sur une carte du Trombinoscope) — identique à index -->
    <div id="member-modal" class="modal-overlay">
        <div class="mi-card">
            <div class="mi-head">
                <button type="button" class="mi-close" onclick="closeMemberInfo()" aria-label="Fermer">&times;</button>
                <div class="mi-photo-wrap" id="mi-photo-wrap">
                    <img id="mi-photo" src="" alt="Photo">
                </div>
                <h2 id="mi-name">Prénom Nom</h2>
                <span id="mi-civilite" class="member-civilite-badge" style="display:inline-block;"></span>
            </div>
            <div class="mi-body">
                <div id="mi-rows"></div>
            </div>
            <div class="mi-foot">
                <a id="mi-edit" href="#" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">✏️ Modifier</a>
                <button type="button" onclick="closeMemberInfo()" class="btn btn-primary btn-sm">Fermer</button>
            </div>
        </div>
    </div>

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
        // ── Fiche membre au clic sur une carte du Trombinoscope (popup identique à index) ──
        function miEsc(s){ var d=document.createElement('div'); d.textContent=(s===null||s===undefined||s==='')?'—':s; return d.innerHTML; }
        function miRow(label, value){
            if (value === '' || value === null || value === undefined) return '';
            return '<div class="mi-row"><span class="k">' + label + '</span><span class="v">' + miEsc(value) + '</span></div>';
        }
        function showMemberInfo(card) {
            var d = card.dataset;
            var modal = document.getElementById('member-modal');
            var wrap = document.getElementById('mi-photo-wrap');
            if (d.photo) {
                wrap.innerHTML = '<img id="mi-photo" src="uploads/' + encodeURIComponent(d.photo) + '" alt="Photo">';
            } else {
                var ini = ((d.name || '?').trim().charAt(0) || '?').toUpperCase();
                wrap.innerHTML = '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:700;color:var(--gold);">' + ini + '</div>';
            }
            document.getElementById('mi-name').textContent = d.name || '';
            var civ = document.getElementById('mi-civilite');
            civ.textContent = d.civilite || '';
            civ.style.display = d.civilite ? 'inline-block' : 'none';
            var html = '';
            html += miRow('Email', d.email);
            html += miRow('Genre', d.genre);
            html += miRow('Commune', d.commune);
            html += miRow('Profession', d.profession);
            html += miRow("Secteur d'activité", d.secteur);
            html += miRow("Année d'intégration", d.annee);
            html += miRow("Type d'adhésion", d.type);
            document.getElementById('mi-rows').innerHTML = html;
            var edit = document.getElementById('mi-edit');
            if (edit) { edit.href = 'membre.php?id=' + encodeURIComponent(d.id || ''); }
            modal.style.display = 'flex';
            setTimeout(function(){ modal.classList.add('active'); }, 10);
        }
        function closeMemberInfo() {
            var modal = document.getElementById('member-modal');
            modal.classList.remove('active');
            setTimeout(function(){ modal.style.display = 'none'; }, 300);
        }
        (function(){ var mm=document.getElementById('member-modal'); if(mm){ mm.addEventListener('click', function(e){ if (e.target === this) closeMemberInfo(); }); } })();

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
                title = "Passer en attente";
                msg = `Voulez-vous remettre <strong>${memberName}</strong> en attente de validation ? Le membre retournera dans la liste des demandes.`;
                confirmBtn.classList.add("btn-secondary");
                confirmBtn.style.color = "var(--accent)";
                confirmBtn.style.borderColor = "var(--accent)";
                confirmBtn.style.borderStyle = "solid";
                confirmBtn.style.borderWidth = "1px";
                confirmBtn.textContent = "Passer en attente";
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
            if (window.applyDashFilters) window.applyDashFilters();
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
            if (window.applyDashFilters) window.applyDashFilters();
        }

        // Mode "Trombinoscope seul" : n'afficher que la grille des membres validés.
        function enterTrombiOnly() {
            const vBtn = document.querySelector('button[onclick*="validated-tab"]');
            if (vBtn) vBtn.click();
            setViewMode('grid');
            var hide = function (sel) { var e = document.querySelector(sel); if (e) e.style.display = 'none'; };
            hide('.stats-grid');
            hide('.dashboard-tabs');
            var pend = document.getElementById('pending-tab'); if (pend) pend.style.display = 'none';
            var vHead = document.querySelector('#validated-tab .section-header'); if (vHead) vHead.style.display = 'none';
            // Filtres masqués par défaut en mode Trombinoscope
            var panel = document.getElementById('adh-panel'); if (panel) panel.classList.add('is-hidden');
            var tog = document.getElementById('adh-toggle'); if (tog) tog.classList.remove('open');
            // Activer le lien "Trombinoscope" du menu (comme les autres pages actives)
            document.querySelectorAll('.admin-menu-links a').forEach(function (a) {
                var hh = a.getAttribute('href');
                if (hh === 'admin_dashboard.php#trombi') { a.classList.add('active'); }
                else if (hh === 'admin_dashboard.php') { a.classList.remove('active'); }
            });
            // Fermer le menu mobile (comme les autres liens qui rechargent la page)
            var ml = document.getElementById('adminMenuLinks'); if (ml) ml.classList.remove('open');
            // Mettre à jour le libellé du bouton menu mobile (section active)
            var tglLabel = document.querySelector('.admin-menu-toggle span:first-child');
            if (tglLabel) tglLabel.textContent = '🖼️ Trombinoscope';
        }

        // On document load, restore previous tab & view mode selections
        document.addEventListener('DOMContentLoaded', () => {
            // Lien "Trombinoscope" du menu : afficher uniquement le trombinoscope.
            if (location.hash === '#trombi') {
                enterTrombiOnly();
                return;
            }

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

        // Réagir si on clique le lien Trombinoscope alors qu'on est déjà sur la page
        window.addEventListener('hashchange', function () {
            if (location.hash === '#trombi') { enterTrombiOnly(); }
            else { location.reload(); }
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

        // ── Filtres combinés (recherche + commission / secteur / type / statut / genre / année) ──
        (function () {
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
            const items = Array.from(document.querySelectorAll('.filterable'));
            const chips = Array.from(document.querySelectorAll('.dash-chip'));
            let civFilter = 'all';
            function norm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }

            window.applyDashFilters = function () {
                const term = norm(search ? search.value : '');
                items.forEach(function (el) {
                    let show = true;
                    if (civFilter !== 'all') { show = (el.getAttribute('data-civilite') === civFilter); }
                    for (const key in selects) {
                        const s = selects[key];
                        if (show && s && s.value) { show = (el.getAttribute('data-' + key) === s.value); }
                    }
                    if (show && term) { show = norm(el.getAttribute('data-search')).includes(term); }
                    el.style.display = show ? '' : 'none';
                });
                if (countEl) {
                    const visible = items.filter(function (el) { return el.style.display !== 'none' && el.offsetParent !== null; }).length;
                    countEl.textContent = visible + ' résultat(s)';
                }
            };

            chips.forEach(function (c) {
                c.addEventListener('click', function () {
                    chips.forEach(x => x.classList.remove('active'));
                    c.classList.add('active');
                    civFilter = c.getAttribute('data-civ');
                    window.applyDashFilters();
                });
            });

            const toggle = document.getElementById('adh-toggle');
            const panel = document.getElementById('adh-panel');
            if (toggle && panel) toggle.addEventListener('click', function () {
                panel.classList.toggle('is-hidden');
                toggle.classList.toggle('open');
            });

            if (search) search.addEventListener('input', window.applyDashFilters);
            for (const key in selects) { if (selects[key]) selects[key].addEventListener('change', window.applyDashFilters); }
            const reset = document.getElementById('adh-reset');
            if (reset) reset.addEventListener('click', function () {
                if (search) search.value = '';
                for (const key in selects) { if (selects[key]) selects[key].value = ''; }
                civFilter = 'all';
                chips.forEach(x => x.classList.remove('active'));
                const allChip = document.querySelector('.dash-chip[data-civ="all"]');
                if (allChip) allChip.classList.add('active');
                window.applyDashFilters();
            });
            window.applyDashFilters();
        })();
    </script>
</body>
</html>
