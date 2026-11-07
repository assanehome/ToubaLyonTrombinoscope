<?php
/**
 * Touba Lyon 2026 - 📬 Page des notifications du membre
 *
 * Affiche la liste complète des notifications du membre connecté, avec
 * marquage automatique des non-lues comme lues, « tout marquer comme lu »
 * et suppression individuelle.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/player_approved_guard.php';

$membre_id = (int)$_SESSION['player_id'];

// Actions POST (AJAX ou rechargement) : tout marquer lu / supprimer une
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validate()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'tout_lu') {
        troba_notifications_mark_all_read($pdo, $membre_id);
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        header('Location: notifications_page.php');
        exit;
    }
    if ($action === 'tout_supprimer') {
        troba_notifications_delete_all($pdo, $membre_id);
        header('Location: notifications_page.php');
        exit;
    }
    if ($action === 'supprimer') {
        $id = (int)($_POST['id'] ?? 0);
        troba_notifications_delete($pdo, $membre_id, $id);
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
        header('Location: notifications_page.php');
        exit;
    }
}

// Purge + liste des notifications (la plus récente d'abord)
troba_purge_notifications($pdo);
$notifs = troba_notifications_recent($pdo, $membre_id, 50);
$nonLues = troba_notifications_unread_count($pdo, $membre_id);

// Marquage automatique des non-lues comme lues à la consultation de la page
if ($nonLues > 0) {
    troba_notifications_mark_all_read($pdo, $membre_id);
    $nonLues = 0;
}

// Petite aide au formatage de la date
function notif_date_fr($iso)
{
    if (!$iso) { return ''; }
    $ts = strtotime($iso);
    if ($ts === false) { return htmlspecialchars((string)$iso); }
    return date('d/m/Y H:i', $ts);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📬 Mes notifications - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .nt-wrap { max-width: 720px; margin: 1.5rem auto; }
        .nt-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.2rem; }
        .nt-title { margin: 0; font-size: 1.4rem; }
        .nt-empty { text-align: center; padding: 3rem 1.5rem; }
        .nt-empty .ic { font-size: 2.6rem; }
        .nt-list { display: flex; flex-direction: column; gap: 0.6rem; }
        .nt-item {
            display: flex; align-items: flex-start; gap: 0.8rem;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            padding: 0.95rem 1rem;
            transition: border-color 0.2s ease, background 0.2s ease;
        }
        .nt-item:hover { border-color: rgba(212,175,55,0.4); background: rgba(255,255,255,0.05); }
        .nt-item .nt-ic { font-size: 1.2rem; line-height: 1.3; flex-shrink: 0; }
        .nt-item .nt-body { flex: 1; min-width: 0; }
        .nt-item .nt-titre { color: var(--white); font-weight: 700; font-size: 0.92rem; }
        .nt-item .nt-texte { color: var(--text-muted); font-size: 0.85rem; line-height: 1.45; margin-top: 0.2rem; white-space: pre-line; word-break: break-word; }
        .nt-item .nt-date { color: rgba(255,255,255,0.32); font-size: 0.72rem; margin-top: 0.35rem; }
        .nt-item .nt-actions { display: flex; flex-direction: column; gap: 0.35rem; flex-shrink: 0; }
        .nt-item .nt-actions button {
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--glass-border);
            color: var(--text-muted);
            border-radius: 8px;
            font-size: 0.72rem;
            padding: 0.3rem 0.55rem;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .nt-item .nt-actions button:hover { border-color: var(--accent); color: var(--white); }
        .nt-item .nt-actions button.nt-del:hover { border-color: rgba(191,33,33,0.6); color: #ffb3b3; }
        @media (max-width: 520px) {
            .nt-item { flex-wrap: wrap; }
            .nt-item .nt-actions { flex-direction: row; width: 100%; justify-content: flex-end; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="dashboard-main">

                <div class="nt-wrap">
                    <div class="nt-head">
                        <h1 class="admin-page-title nt-title">📬 Mes notifications</h1>
                        <?php if (!empty($notifs)): ?>
                            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                            <form method="POST" action="notifications_page.php" style="margin:0;" data-confirm="Marquer toutes les notifications comme lues ?">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="tout_lu">
                                <button type="submit" class="btn btn-secondary btn-sm">✓ Tout marquer comme lu</button>
                            </form>
                            <form method="POST" action="notifications_page.php" style="margin:0;" data-confirm="Supprimer toutes vos notifications ? Cette action est définitive." data-confirm-danger="1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="tout_supprimer">
                                <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(191,33,33,0.5); color:#fca5a5;">🗑️ Tout supprimer</button>
                            </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($notifs)): ?>
                        <div class="glass-card nt-empty">
                            <div class="ic">🔕</div>
                            <h2 style="color:var(--white); margin:0.6rem 0 0.3rem;">Aucune notification</h2>
                            <p style="color:var(--text-muted); margin:0;">Vous n'avez pas encore de notification. Les annonces du Dahira, les validations de présence et vos résultats Ki Kan La apparaîtront ici.</p>
                        </div>
                    <?php else: ?>
                        <div class="nt-list">
                            <?php foreach ($notifs as $n): ?>
                                <?php
                                    $nId    = (int)$n['id'];
                                    $nTitre = (string)($n['title'] ?? 'Notification');
                                    $nTexte = (string)($n['body'] ?? '');
                                    $nUrl   = (string)($n['url'] ?? '');
                                    $nDate  = notif_date_fr((string)($n['created_at'] ?? ''));
                                    $nKind  = (string)($n['kind'] ?? '');
                                    // Icône selon le type
                                    $nIcon  = '🔔';
                                    if (stripos($nKind, 'guddi') !== false || stripos($nTitre, 'guddi') !== false) { $nIcon = '💎'; }
                                    elseif (stripos($nKind, 'dahira') !== false || stripos($nTitre, 'dahira') !== false) { $nIcon = '🕌'; }
                                    elseif (stripos($nKind, 'kikanla') !== false || stripos($nTitre, 'ki kan la') !== false) { $nIcon = '🎮'; }
                                    elseif (stripos($nKind, 'wird') !== false || stripos($nTitre, 'coran') !== false) { $nIcon = '📖'; }
                                    elseif (stripos($nKind, 'presence') !== false) { $nIcon = '✅'; }
                                ?>
                                <div class="nt-item">
                                    <div class="nt-ic"><?php echo $nIcon; ?></div>
                                    <div class="nt-body">
                                        <?php if ($nUrl !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($nUrl); ?>" style="text-decoration:none; color:inherit;"><div class="nt-titre"><?php echo htmlspecialchars($nTitre); ?></div></a>
                                        <?php else: ?>
                                            <div class="nt-titre"><?php echo htmlspecialchars($nTitre); ?></div>
                                        <?php endif; ?>
                                        <?php if ($nTexte !== ''): ?><div class="nt-texte"><?php echo nl2br(htmlspecialchars($nTexte)); ?></div><?php endif; ?>
                                        <div class="nt-date">🕐 <?php echo $nDate; ?></div>
                                    </div>
                                    <div class="nt-actions">
                                        <?php if ($nUrl !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($nUrl); ?>" style="text-decoration:none;"><button type="button">Ouvrir</button></a>
                                        <?php endif; ?>
                                        <button type="button" class="nt-del" data-id="<?php echo $nId; ?>" onclick="ntSupprimer(this)">🗑️ Supprimer</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        // Jeton CSRF exposé au JS (pour la suppression AJAX)
        var ntToken = <?php echo json_encode(csrf_token()); ?>;
        // Suppression AJAX d'une notification (sans confirmation)
        function ntSupprimer(btn) {
            if (!btn || !btn.getAttribute('data-id')) { return; }
            var id = btn.getAttribute('data-id');
            var item = btn.closest('.nt-item');
            var token = ntToken;
            var corps = new URLSearchParams();
            corps.set('action', 'supprimer');
            corps.set('id', id);
            corps.set('csrf_token', token);
            corps.set('ajax', '1');
            fetch('notifications_page.php', { method: 'POST', body: corps })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok && item && item.parentNode) {
                        item.parentNode.removeChild(item);
                        var restants = document.querySelectorAll('.nt-item').length;
                        var wrap = document.querySelector('.nt-list');
                        if (wrap && restants === 0) {
                            wrap.outerHTML = '<div class="glass-card nt-empty"><div class="ic">🔕</div><h2 style="color:var(--white); margin:0.6rem 0 0.3rem;">Aucune notification</h2><p style="color:var(--text-muted); margin:0;">Vous n\'avez pas encore de notification.</p></div>';
                        }
                    } else if (window.modernAlert) {
                        window.modernAlert('Erreur lors de la suppression.', 'Erreur');
                    }
                })
                .catch(function () {
                    if (window.modernAlert) { window.modernAlert('Une erreur est survenue. Réessayez.', 'Erreur'); }
                });
        }
    </script>
</body>
</html>
