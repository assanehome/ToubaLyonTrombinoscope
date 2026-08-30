<?php
/**
 * Touba Lyon 2026 - Espace Kurels (modèle « commission complet »).
 *
 * Un Kurel = un groupe avec des MEMBRES et des RESPONSABLES (rôle par Kurel).
 *  - « Admin Kurels » (administrateur ou responsable de la commission « Kurels ») :
 *    crée des Kurels, gère leurs membres ET désigne les responsables de chaque Kurel.
 *  - Responsable d'un Kurel : gère les MEMBRES de ses Kurels.
 */
require_once __DIR__ . '/kourel_guard.php'; // $__isAdmin, $__isKurelAdmin, $__managedKourels, $pdo
require_once __DIR__ . '/csrf.php';

$error = '';
$success = '';

/** Le membre courant peut-il gérer les membres de ce Kurel ? */
function kurel_can_manage($kid, $isKurelAdmin, $managed)
{
    return $isKurelAdmin || in_array((int) $kid, $managed, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (($_POST['ajax'] ?? '') === '1');
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        $kid = (int) ($_POST['kourel_id'] ?? 0);
        $mid = (int) ($_POST['membre_id'] ?? 0);
        try {
            if ($action === 'create_kourel' && $__isKurelAdmin) {
                $nom = trim($_POST['nom'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                if ($nom === '') {
                    $error = "Le nom du Kurel est obligatoire.";
                } else {
                    $pdo->prepare("INSERT INTO kourels (nom, description) VALUES (?, ?)")->execute([$nom, $desc !== '' ? $desc : null]);
                    $success = "Le Kurel « " . $nom . " » a été créé.";
                }
            } elseif ($action === 'delete_kourel' && $kid > 0 && $__isKurelAdmin) {
                $pdo->prepare("DELETE FROM kourel_membres WHERE kourel_id = ?")->execute([$kid]);
                $pdo->prepare("DELETE FROM kourel_gestionnaires WHERE kourel_id = ?")->execute([$kid]);
                $pdo->prepare("DELETE FROM kourels WHERE id = ?")->execute([$kid]);
                $success = "Le Kurel a été supprimé.";
            } elseif ($action === 'add_kourel_member' && $kid > 0 && $mid > 0 && kurel_can_manage($kid, $__isKurelAdmin, $__managedKourels)) {
                $pdo->prepare("INSERT IGNORE INTO kourel_membres (kourel_id, membre_id) VALUES (?, ?)")->execute([$kid, $mid]);
                $success = "Membre ajouté.";
            } elseif ($action === 'remove_kourel_member' && $kid > 0 && $mid > 0 && kurel_can_manage($kid, $__isKurelAdmin, $__managedKourels)) {
                $pdo->prepare("DELETE FROM kourel_membres WHERE kourel_id = ? AND membre_id = ?")->execute([$kid, $mid]);
                $success = "Membre retiré.";
            } elseif ($action === 'add_kourel_manager' && $kid > 0 && $mid > 0 && $__isKurelAdmin) {
                $pdo->prepare("INSERT IGNORE INTO kourel_gestionnaires (kourel_id, membre_id) VALUES (?, ?)")->execute([$kid, $mid]);
                $success = "Responsable ajouté.";
            } elseif ($action === 'remove_kourel_manager' && $kid > 0 && $mid > 0 && $__isKurelAdmin) {
                $pdo->prepare("DELETE FROM kourel_gestionnaires WHERE kourel_id = ? AND membre_id = ?")->execute([$kid, $mid]);
                $success = "Responsable retiré.";
            } else {
                $error = "Action non autorisée.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Touba Lyon kourel_dashboard: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => empty($error), 'error' => $error]);
        exit;
    }
}

try {
    if ($__isKurelAdmin) {
        $kourels = $pdo->query("SELECT id, nom, description FROM kourels ORDER BY nom ASC")->fetchAll();
    } elseif (!empty($__managedKourels)) {
        $in = implode(',', array_fill(0, count($__managedKourels), '?'));
        $stmt = $pdo->prepare("SELECT id, nom, description FROM kourels WHERE id IN ($in) ORDER BY nom ASC");
        $stmt->execute($__managedKourels);
        $kourels = $stmt->fetchAll();
    } else {
        $kourels = [];
    }

    $allMembers = $pdo->query("SELECT id, prenom, nom, photo_path FROM membres WHERE status = 'approved' ORDER BY nom ASC, prenom ASC")->fetchAll();

    $kMembers = [];
    $kManagers = [];
    $rows = $pdo->query("SELECT km.kourel_id, m.id, m.prenom, m.nom, m.photo_path
                         FROM kourel_membres km JOIN membres m ON m.id = km.membre_id
                         ORDER BY m.nom ASC, m.prenom ASC");
    foreach ($rows as $r) { $kMembers[$r['kourel_id']][] = $r; }
    $rows2 = $pdo->query("SELECT kg.kourel_id, m.id, m.prenom, m.nom, m.photo_path
                          FROM kourel_gestionnaires kg JOIN membres m ON m.id = kg.membre_id
                          ORDER BY m.nom ASC, m.prenom ASC");
    foreach ($rows2 as $r) { $kManagers[$r['kourel_id']][] = $r; }
} catch (Exception $e) {
    error_log('Touba Lyon kourel_dashboard (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

function kourel_member_avatar($m)
{
    $ini = strtoupper(mb_substr($m['prenom'] ?? '', 0, 1) . mb_substr($m['nom'] ?? '', 0, 1));
    if (!empty($m['photo_path'])) {
        return '<img src="uploads/' . htmlspecialchars($m['photo_path'], ENT_QUOTES) . '" alt="" class="km-avatar">';
    }
    return '<span class="km-avatar km-avatar--ph">' . htmlspecialchars($ini !== '' ? $ini : '?') . '</span>';
}

// Données JS
$idsOf = function ($arr) { return array_map(function ($r) { return (int) $r['id']; }, $arr); };
$kMembersMap = [];
foreach ($kMembers as $kid => $arr) { $kMembersMap[$kid] = $idsOf($arr); }
$kManagersMap = [];
foreach ($kManagers as $kid => $arr) { $kManagersMap[$kid] = $idsOf($arr); }
$membersJs = array_map(function ($m) {
    $ini = strtoupper(mb_substr($m['prenom'] ?? '', 0, 1) . mb_substr($m['nom'] ?? '', 0, 1));
    return [
        'id' => (int) $m['id'],
        'name' => trim($m['prenom'] . ' ' . $m['nom']),
        'photo' => $m['photo_path'] ?? '',
        'ini' => ($ini !== '' ? $ini : '?'),
    ];
}, $allMembers);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace Kurels - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .kourel-wrap { max-width: 960px; margin: 2rem auto; }
        .kourel-card { margin-bottom: 1.25rem; border-radius: 18px; padding: 1.25rem 1.5rem; }
        .kourel-card-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .kourel-card-head h3 { margin: 0; color: var(--white); font-size: 1.2rem; }
        .kourel-card-head .k-desc { color: var(--text-muted); font-size: 0.85rem; margin: 0.2rem 0 0; }
        .com-sub { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gold); font-weight: 700; margin: 0.5rem 0 0.5rem; }
        .km-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem; }
        .km-chip { display: inline-flex; align-items: center; gap: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 50px; padding: 0.25rem 0.7rem 0.25rem 0.3rem; }
        .km-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .km-avatar--ph { background: rgba(212,175,55,0.15); color: var(--gold); font-size: 0.72rem; font-weight: 700; border: 1px solid rgba(212,175,55,0.35); }
        .km-chip .km-name { font-size: 0.85rem; color: var(--white); font-weight: 600; }
        .km-empty { color: var(--text-muted); font-size: 0.86rem; font-style: italic; margin-bottom: 0.75rem; }
        .km-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; border-top: 1px solid var(--glass-border); padding-top: 1rem; margin-top: 0.5rem; }
        @media (max-width: 600px) { .km-actions .btn { flex: 1 1 100%; width: 100%; justify-content: center; } }
        .com-filters { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
        .com-filters input { flex: 1 1 240px; border-radius: 50px; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); font-size: 0.9rem; padding: 0.6rem 1rem; }
        .com-filters input:focus { outline: none; border-color: var(--accent); }
        .com-dd { flex: 1 1 200px; border-radius: 10px; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); font-size: 0.9rem; padding: 0.6rem 0.9rem; color-scheme: dark; }
        .com-dd option { background-color: #0c241a; color: #fff; }
        .com-dd:focus { outline: none; border-color: var(--accent); }
        .com-count { font-size: 0.82rem; color: var(--text-muted); margin-left: auto; }
        @media (max-width: 600px) { .com-filters input, .com-dd { flex: 1 1 100%; } }
        /* ── Sélecteur à deux listes ── */
        #members-modal { position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; padding: 1rem; }
        #members-modal.active { display: flex; }
        .tl-card { width: 100%; max-width: 820px; max-height: 90vh; display: flex; flex-direction: column; background: linear-gradient(180deg,#123528,#0c241a); border: 1px solid rgba(212,175,55,0.25); border-radius: 18px; overflow: hidden; box-shadow: 0 30px 80px rgba(0,0,0,0.55); }
        .tl-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; background: linear-gradient(135deg,#1b4332,#2d6a4f); flex-shrink: 0; }
        .tl-head h3 { margin: 0; color: #fff; font-size: 1.05rem; }
        .tl-x { background: rgba(255,255,255,0.15); color: #fff; border: 0; width: 28px; height: 28px; border-radius: 50%; font-size: 1.1rem; line-height: 1; cursor: pointer; flex-shrink: 0; }
        .tl-cols { display: flex; flex: 1; min-height: 0; }
        .tl-col { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .tl-col + .tl-col { border-left: 1px solid var(--glass-border); }
        .tl-col-head { padding: 0.75rem 1rem 0.5rem; flex-shrink: 0; }
        .tl-col-head .tl-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gold); font-weight: 700; }
        .tl-col-head input { width: 100%; margin-top: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 50px; color: #fff; font-size: 0.85rem; padding: 0.45rem 0.9rem; }
        .tl-col-head input:focus { outline: none; border-color: var(--accent); }
        .tl-list { flex: 1; overflow-y: auto; padding: 0.5rem 0.75rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
        .tl-item { display: flex; align-items: center; gap: 0.6rem; padding: 0.4rem 0.5rem; border: 1px solid var(--glass-border); border-radius: 12px; background: rgba(255,255,255,0.03); }
        .tl-av { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .tl-av--ph { background: rgba(212,175,55,0.15); color: var(--gold); font-size: 0.72rem; font-weight: 700; border: 1px solid rgba(212,175,55,0.35); }
        .tl-name { flex: 1; color: #fff; font-size: 0.88rem; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tl-item button { flex-shrink: 0; border: 0; border-radius: 8px; width: 30px; height: 30px; font-size: 1.1rem; line-height: 1; cursor: pointer; }
        .tl-add-btn { background: rgba(212,175,55,0.2); color: var(--gold); }
        .tl-add-btn:hover { background: rgba(212,175,55,0.35); }
        .tl-rem-btn { background: rgba(255,80,80,0.18); color: #ff8a8a; }
        .tl-rem-btn:hover { background: rgba(255,80,80,0.32); }
        .tl-empty { color: var(--text-muted); font-size: 0.82rem; font-style: italic; padding: 0.75rem 0.5rem; text-align: center; }
        .tl-foot { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.85rem 1.25rem; border-top: 1px solid var(--glass-border); flex-wrap: wrap; flex-shrink: 0; }
        .tl-count { font-size: 0.8rem; color: var(--text-muted); }
        @media (max-width: 600px) { .tl-cols { flex-direction: column; } .tl-col + .tl-col { border-left: 0; border-top: 1px solid var(--glass-border); } .tl-list { max-height: 30vh; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">

        <div class="kourel-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <span>🎵 Espace Kurels — <strong class="gold-text"><?php echo count($kourels); ?></strong> Kurel(s)</span>
                <?php if ($__isAdmin): ?>
                    <a href="admin_dashboard.php" class="btn btn-secondary btn-sm">← Tableau de bord</a>
                <?php else: ?>
                    <a href="index.php" class="btn btn-secondary btn-sm">← Trombinoscope</a>
                <?php endif; ?>
            </div>

            <?php if (!$__isKurelAdmin): ?>
            <div class="form-card" style="max-width:none; margin-bottom:1.5rem; padding:1.25rem 1.5rem;">
                <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">
                    Vous êtes <strong>responsable</strong> des Kurels ci-dessous. Vous pouvez y ajouter ou retirer des membres.
                </p>
            </div>
            <?php endif; ?>

            <?php if (empty($kourels)): ?>
                <div class="empty-state"><div class="empty-state-icon">🎵</div><p>Aucun Kurel<?php echo $__isKurelAdmin ? ' pour le moment. Créez-en un ci-dessous.' : ' à gérer pour le moment.'; ?></p></div>
            <?php else: ?>
                <div class="com-filters">
                    <input type="text" id="com-search" placeholder="🔍 Rechercher un Kurel…">
                    <select id="com-select" class="com-dd">
                        <option value="">Tous les Kurels</option>
                        <?php foreach ($kourels as $k): ?>
                            <option value="<?php echo htmlspecialchars(mb_strtolower($k['nom']), ENT_QUOTES); ?>"><?php echo htmlspecialchars($k['nom']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="com-count" id="com-count"></span>
                </div>
                <?php foreach ($kourels as $k): ?>
                    <?php
                        $membersOfK = $kMembers[$k['id']] ?? [];
                        $managersOfK = $kManagers[$k['id']] ?? [];
                    ?>
                    <div class="glass-card kourel-card com-filterable" id="com-card-<?php echo (int) $k['id']; ?>" data-search="<?php echo htmlspecialchars(mb_strtolower($k['nom']), ENT_QUOTES); ?>">
                        <div class="kourel-card-head">
                            <div>
                                <h3>🎵 <?php echo htmlspecialchars($k['nom']); ?>
                                    <span class="badge badge-approved com-count-badge" style="margin-left:0.4rem;"><?php echo count($membersOfK); ?> membre(s)</span>
                                </h3>
                                <?php if (!empty($k['description'])): ?><p class="k-desc"><?php echo htmlspecialchars($k['description']); ?></p><?php endif; ?>
                            </div>
                            <?php if ($__isKurelAdmin): ?>
                            <form action="kourel_dashboard.php" method="POST" id="kdel-form-<?php echo (int) $k['id']; ?>" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_kourel">
                                <input type="hidden" name="kourel_id" value="<?php echo (int) $k['id']; ?>">
                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteKurel(<?php echo (int) $k['id']; ?>, <?php echo htmlspecialchars(json_encode($k['nom']), ENT_QUOTES); ?>)">Supprimer</button>
                            </form>
                            <?php endif; ?>
                        </div>

                        <div class="com-sub">Responsable(s) du Kurel</div>
                        <div class="km-list com-managers-list" id="com-managers-<?php echo (int) $k['id']; ?>">
                            <?php if (empty($managersOfK)): ?>
                                <p class="km-empty">Aucun responsable désigné.</p>
                            <?php else: foreach ($managersOfK as $mm): ?>
                                <span class="km-chip"><?php echo kourel_member_avatar($mm); ?><span class="km-name">🎖️ <?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></span></span>
                            <?php endforeach; endif; ?>
                        </div>

                        <div class="com-sub">Membres du Kurel</div>
                        <div class="km-list com-members-list" id="com-members-<?php echo (int) $k['id']; ?>">
                            <?php if (empty($membersOfK)): ?>
                                <p class="km-empty">Aucun membre dans ce Kurel.</p>
                            <?php else: foreach ($membersOfK as $mm): ?>
                                <span class="km-chip"><?php echo kourel_member_avatar($mm); ?><span class="km-name"><?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></span></span>
                            <?php endforeach; endif; ?>
                        </div>

                        <div class="km-actions">
                            <button type="button" class="btn btn-primary btn-sm" onclick="openComModal('members', <?php echo (int) $k['id']; ?>, <?php echo htmlspecialchars(json_encode($k['nom']), ENT_QUOTES); ?>)">👥 Gérer les membres</button>
                            <?php if ($__isKurelAdmin): ?>
                            <button type="button" class="btn btn-secondary btn-sm" style="border-color:var(--gold); color:var(--gold);" onclick="openComModal('managers', <?php echo (int) $k['id']; ?>, <?php echo htmlspecialchars(json_encode($k['nom']), ENT_QUOTES); ?>)">🎖️ Gérer les responsables</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="empty-state" id="com-noresult" style="display:none;"><div class="empty-state-icon">🔍</div><p>Aucun Kurel ne correspond à votre recherche.</p></div>
            <?php endif; ?>

            <?php if ($__isKurelAdmin): ?>
            <!-- Créer un Kurel (en bas de la liste) -->
            <div class="form-card" style="max-width:none; margin-top:1.5rem; padding:1.5rem;">
                <h3 class="gold-text" style="font-size:1.15rem; font-weight:700; margin:0 0 1rem;">➕ Créer un Kurel</h3>
                <form action="kourel_dashboard.php" method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_kourel">
                    <div class="form-group">
                        <label class="form-label">Nom du Kurel <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="nom" class="form-input" placeholder="Ex : Kurel Hizbut, Kurel des jeunes…" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description (facultatif)</label>
                        <input type="text" name="description" class="form-input" placeholder="Quelques mots sur ce Kurel">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Créer le Kurel</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

            </div>
        </div>
    </main>

    <!-- Sélecteur à deux listes : membres ou responsables d'un Kurel -->
    <div id="members-modal">
        <div class="tl-card">
            <div class="tl-head">
                <h3 id="tl-modal-title">Membres</h3>
                <button type="button" class="tl-x" onclick="closeComModal()" aria-label="Fermer">&times;</button>
            </div>
            <div class="tl-cols">
                <div class="tl-col">
                    <div class="tl-col-head">
                        <div class="tl-title">Membres disponibles</div>
                        <input type="text" id="tl-search" placeholder="🔍 Rechercher un membre…">
                    </div>
                    <div class="tl-list" id="tl-available"></div>
                </div>
                <div class="tl-col">
                    <div class="tl-col-head">
                        <div class="tl-title" id="tl-right-title">Sélectionnés (<span id="tl-sel-count">0</span>)</div>
                    </div>
                    <div class="tl-list" id="tl-selected"></div>
                </div>
            </div>
            <div class="tl-foot">
                <span class="tl-count" id="tl-avail-count"></span>
                <button type="button" class="btn btn-primary btn-sm" onclick="closeComModal()">Terminé</button>
            </div>
        </div>
    </div>

    <form id="tl-form" style="display:none;"><?php echo csrf_field(); ?></form>

    <script>
        var TL_ALL = <?php echo json_encode($membersJs, JSON_UNESCAPED_UNICODE); ?>;
        var COM_MEMBERS = <?php echo json_encode((object) $kMembersMap, JSON_UNESCAPED_UNICODE); ?>;
        var COM_MANAGERS = <?php echo json_encode((object) $kManagersMap, JSON_UNESCAPED_UNICODE); ?>;
        var tlCid = null, tlMode = 'members';
        var tlSelected = new Set();
        var TL_CSRF = (document.querySelector('#tl-form input[name=csrf_token]') || {}).value || '';
        var TL_BYID = {};
        TL_ALL.forEach(function (m) { TL_BYID[m.id] = m; });
        function getMap(mode){ return (mode === 'managers') ? COM_MANAGERS : COM_MEMBERS; }
        function tlNorm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
        function tlEsc(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
        function tlAvatar(m){ return m.photo ? '<img class="tl-av" src="uploads/' + encodeURIComponent(m.photo) + '" alt="">' : '<span class="tl-av tl-av--ph">' + tlEsc(m.ini) + '</span>'; }
        function comChip(m, mode){
            var av = m.photo ? '<img class="km-avatar" src="uploads/' + encodeURIComponent(m.photo) + '" alt="">' : '<span class="km-avatar km-avatar--ph">' + tlEsc(m.ini) + '</span>';
            var pre = (mode === 'managers') ? '🎖️ ' : '';
            return '<span class="km-chip">' + av + '<span class="km-name">' + pre + tlEsc(m.name) + '</span></span>';
        }
        function updateCard(cid, mode){
            var el = document.getElementById((mode === 'managers' ? 'com-managers-' : 'com-members-') + cid);
            if (!el) return;
            var ids = (getMap(mode)[cid] || []).map(Number);
            if (!ids.length) {
                el.innerHTML = '<p class="km-empty">' + (mode === 'managers' ? 'Aucun responsable désigné.' : 'Aucun membre dans ce Kurel.') + '</p>';
            } else {
                el.innerHTML = ids.map(function (id) { var m = TL_BYID[id]; return m ? comChip(m, mode) : ''; }).join('');
            }
            if (mode === 'members') {
                var badge = document.querySelector('#com-card-' + cid + ' .com-count-badge');
                if (badge) badge.textContent = ids.length + ' membre(s)';
            }
        }
        function comPersist(mode, cid, mid, add){
            var action = (mode === 'managers')
                ? (add ? 'add_kourel_manager' : 'remove_kourel_manager')
                : (add ? 'add_kourel_member' : 'remove_kourel_member');
            var params = new URLSearchParams();
            params.append('ajax', '1');
            params.append('action', action);
            params.append('kourel_id', cid);
            params.append('membre_id', mid);
            params.append('csrf_token', TL_CSRF);
            return fetch('kourel_dashboard.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) { throw new Error(j.error || 'Erreur'); } });
        }
        function tlRender(){
            var term = tlNorm(document.getElementById('tl-search').value);
            var av = document.getElementById('tl-available');
            var se = document.getElementById('tl-selected');
            av.innerHTML = ''; se.innerHTML = '';
            var na = 0, ns = 0;
            TL_ALL.forEach(function (m) {
                if (tlSelected.has(m.id)) {
                    se.insertAdjacentHTML('beforeend', '<div class="tl-item"><span>' + tlAvatar(m) + '</span><span class="tl-name">' + tlEsc(m.name) + '</span><button type="button" class="tl-rem-btn" onclick="tlRemove(' + m.id + ')" title="Retirer">&times;</button></div>');
                    ns++;
                } else {
                    if (term && tlNorm(m.name).indexOf(term) === -1) return;
                    av.insertAdjacentHTML('beforeend', '<div class="tl-item"><span>' + tlAvatar(m) + '</span><span class="tl-name">' + tlEsc(m.name) + '</span><button type="button" class="tl-add-btn" onclick="tlAdd(' + m.id + ')" title="Ajouter">+</button></div>');
                    na++;
                }
            });
            if (!na) av.innerHTML = '<div class="tl-empty">Aucun membre disponible.</div>';
            if (!ns) se.innerHTML = '<div class="tl-empty">Aucun membre sélectionné.</div>';
            document.getElementById('tl-sel-count').textContent = ns;
            document.getElementById('tl-avail-count').textContent = na + ' disponible(s)';
        }
        function tlAdd(id){
            var map = getMap(tlMode);
            if (!map[tlCid]) { map[tlCid] = []; }
            if (map[tlCid].map(Number).indexOf(id) === -1) { map[tlCid].push(id); }
            tlSelected.add(id); tlRender(); updateCard(tlCid, tlMode);
            comPersist(tlMode, tlCid, id, true).catch(function (e) {
                var arr = map[tlCid]; var i = arr.map(Number).indexOf(id); if (i > -1) { arr.splice(i, 1); }
                tlSelected.delete(id); tlRender(); updateCard(tlCid, tlMode);
                modernAlert("Échec de l'ajout : " + e.message, 'Erreur');
            });
        }
        function tlRemove(id){
            var map = getMap(tlMode);
            var arr = map[tlCid] || []; var i = arr.map(Number).indexOf(id); if (i > -1) { arr.splice(i, 1); }
            tlSelected.delete(id); tlRender(); updateCard(tlCid, tlMode);
            comPersist(tlMode, tlCid, id, false).catch(function (e) {
                if (!map[tlCid]) { map[tlCid] = []; }
                if (map[tlCid].map(Number).indexOf(id) === -1) { map[tlCid].push(id); }
                tlSelected.add(id); tlRender(); updateCard(tlCid, tlMode);
                modernAlert("Échec du retrait : " + e.message, 'Erreur');
            });
        }
        function openComModal(mode, cid, name){
            tlMode = mode; tlCid = cid;
            var src = (mode === 'managers') ? COM_MANAGERS : COM_MEMBERS;
            tlSelected = new Set((src[cid] || []).map(Number));
            var label = (mode === 'managers') ? 'Responsables — ' : 'Membres — ';
            document.getElementById('tl-modal-title').textContent = label + name;
            document.getElementById('tl-right-title').innerHTML = (mode === 'managers' ? 'Responsables' : 'Membres') + ' (<span id="tl-sel-count">0</span>)';
            document.getElementById('tl-search').value = '';
            tlRender();
            document.getElementById('members-modal').classList.add('active');
        }
        function closeComModal(){ document.getElementById('members-modal').classList.remove('active'); }
        document.getElementById('tl-search').addEventListener('input', tlRender);
        document.getElementById('members-modal').addEventListener('click', function (e) { if (e.target === this) closeComModal(); });

        // Filtre des Kurels par nom
        (function () {
            var input = document.getElementById('com-search');
            if (!input) return;
            var sel = document.getElementById('com-select');
            var cards = Array.prototype.slice.call(document.querySelectorAll('.com-filterable'));
            var noRes = document.getElementById('com-noresult');
            var countEl = document.getElementById('com-count');
            function norm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
            function apply() {
                var term = norm(input.value);
                var selVal = sel ? sel.value : '';
                var n = 0;
                cards.forEach(function (c) {
                    var ds = c.getAttribute('data-search');
                    var show = (!term || norm(ds).indexOf(term) !== -1) && (!selVal || ds === selVal);
                    c.style.display = show ? '' : 'none';
                    if (show) n++;
                });
                if (noRes) noRes.style.display = n === 0 ? 'block' : 'none';
                if (countEl) countEl.textContent = n + ' Kurel(s)';
            }
            input.addEventListener('input', apply);
            if (sel) sel.addEventListener('change', apply);
            apply();
        })();
    </script>

    <!-- Confirmation de suppression (UI moderne) -->
    <div id="kdel-modal" class="modal-overlay" style="display:none; align-items:center; justify-content:center; z-index:3500;">
        <div class="modal-card glass-card" style="max-width:420px; width:calc(100vw - 28px);">
            <div class="modal-header" style="display:flex; align-items:center; gap:0.6rem;">
                <span style="font-size:1.4rem;">🗑️</span>
                <h3 style="color:var(--danger); margin:0;">Supprimer le Kurel</h3>
            </div>
            <div class="modal-body">
                <p style="line-height:1.55;">Voulez-vous vraiment supprimer le Kurel « <strong id="kdel-name" class="gold-text"></strong> » ?<br>
                <span style="color:var(--text-muted); font-size:0.9rem;">Les membres ne sont pas supprimés, seulement le groupe.</span></p>
            </div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; justify-content:flex-end;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeDelModal()">Annuler</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="doDeleteKurel()">Supprimer définitivement</button>
            </div>
        </div>
    </div>
    <script>
        var kdelId = null;
        function confirmDeleteKurel(id, name){
            kdelId = id;
            document.getElementById('kdel-name').textContent = name;
            var m = document.getElementById('kdel-modal');
            m.style.display = 'flex';
            m.classList.add('active');
        }
        function closeDelModal(){ var m = document.getElementById('kdel-modal'); m.classList.remove('active'); m.style.display = 'none'; kdelId = null; }
        function doDeleteKurel(){ if (kdelId != null) { var f = document.getElementById('kdel-form-' + kdelId); if (f) f.submit(); } }
        document.getElementById('kdel-modal').addEventListener('click', function (e) { if (e.target === this) closeDelModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDelModal(); });
    </script>

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
</body>
</html>
