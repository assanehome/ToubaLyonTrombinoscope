<?php
/**
 * Touba Lyon 2026 - Suivi d'une session de lecture du Coran (Khatm).
 * Réservé à l'admin et aux responsables de la commission « Culte ».
 * Tableau de bord, rappels (e-mail / WhatsApp), libération d'une partie.
 */
require_once __DIR__ . '/wird_guard.php';   // $__isAdmin, $__isCulteManager, $pdo
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/send_mail.php';
require_once __DIR__ . '/contact.php';       // wa_number()
require_once __DIR__ . '/dahira_emails.php';

$error = '';
$success = '';

$sid = (int) ($_POST['session_id'] ?? $_GET['s'] ?? 0);
$session = null;
if ($sid > 0) {
    try { $st = $pdo->prepare("SELECT * FROM quran_sessions WHERE id = ?"); $st->execute([$sid]); $session = $st->fetch(); } catch (Exception $e) { $session = null; }
}
if (!$session) { header('Location: wird_admin.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'free_part') {
                $pid = (int) ($_POST['part_id'] ?? 0);
                if ($pid > 0) {
                    $pdo->prepare("UPDATE quran_parts SET statut='libre', membre_id=NULL, membre_nom=NULL, owner_token=NULL, reserved_at=NULL, validated_at=NULL WHERE id=? AND session_id=?")->execute([$pid, $sid]);
                    $success = "Partie libérée.";
                }
            } elseif ($action === 'remind_all') {
                // Rappel e-mail aux membres (connectés) ayant réservé mais pas validé.
                $q = $pdo->prepare("SELECT p.numero, m.prenom, m.nom, m.email FROM quran_parts p JOIN membres m ON m.id = p.membre_id WHERE p.session_id=? AND p.statut='reservee' AND m.email IS NOT NULL AND m.email <> ''");
                $q->execute([$sid]);
                $cible = $q->fetchAll();
                $sent = 0;
                foreach ($cible as $t) {
                    $inner = '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Rappel — lecture du Coran</h1>'
                        . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($t['prenom'] . ' ' . $t['nom']) . '</strong>,</p>'
                        . '<p style="margin:0 0 14px;">Vous avez réservé la <strong>partie ' . (int)$t['numero'] . ' (Juz ' . (int)$t['numero'] . ')</strong> de la lecture « ' . htmlspecialchars($session['titre']) . ' ». '
                        . 'Merci de la lire et de <strong>valider votre lecture</strong> dès que possible, afin de compléter le Khatm.</p>'
                        . '<p style="margin:0;">Jërëjëf pour votre engagement !</p>';
                    if (@send_smtp_mail($t['email'], $t['prenom'] . ' ' . $t['nom'], 'Rappel — lecture du Coran', dahira_email_wrap($inner, 'Lecture du Coran'))) { $sent++; }
                }
                $success = $sent . " rappel(s) e-mail envoyé(s).";
            }
        } catch (Exception $e) {
            error_log('Touba Lyon wird_suivi: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
}

try {
    $st = $pdo->prepare("SELECT p.*, m.telephone AS m_tel, m.email AS m_email FROM quran_parts p LEFT JOIN membres m ON m.id = p.membre_id WHERE p.session_id=? ORDER BY p.numero ASC");
    $st->execute([$sid]);
    $parts = $st->fetchAll();
} catch (Exception $e) { $parts = []; }
$nbLues = 0; $nbRes = 0; $participants = [];
foreach ($parts as $p) {
    if ($p['statut'] === 'lue') { $nbLues++; } elseif ($p['statut'] === 'reservee') { $nbRes++; }
    if ($p['statut'] !== 'libre') {
        $key = !empty($p['membre_id']) ? 'm' . (int)$p['membre_id'] : 'n' . mb_strtolower(trim($p['membre_nom'] ?? ''));
        $participants[$key] = true;
    }
}
$nbPart = count($participants);
$nbLibres = max(0, 30 - $nbLues - $nbRes);
$pct = count($parts) ? round($nbLues / count($parts) * 100) : 0;

$baseUrl = rtrim(dahira_base_url(), '/');
$waGroup = '';
try { $waGroup = (string) $pdo->query("SELECT valeur FROM app_settings WHERE cle='wa_group_link'")->fetchColumn(); } catch (Exception $e) { $waGroup = ''; }
$sessLink = $baseUrl . '/wird.php?s=' . (int) $sid . '&t=' . $session['token'];
$pendingList = [];
$doneList = [];
foreach ($parts as $p) {
    if ($p['statut'] === 'reservee') { $pendingList[] = '• Juz ' . (int) $p['numero'] . ' : ' . ($p['membre_nom'] ?? '?'); }
    elseif ($p['statut'] === 'lue') { $doneList[] = '✅ Juz ' . (int) $p['numero'] . ' : ' . ($p['membre_nom'] ?? '?'); }
}
$waReminderMsg = "📖 " . $session['titre'] . "\n🕌 Avancement : " . $nbLues . "/30 lues · " . $nbRes . " réservées · " . $nbLibres . " libres (" . $pct . "%)\n\n"
    . (!empty($doneList) ? "*Déjà lus :*\n" . implode("\n", $doneList) . "\n\n" : "")
    . (!empty($pendingList) ? "*À lire et valider :*\n" . implode("\n", $pendingList) . "\n\n" : "")
    . "👉 Lien : " . $sessLink;

function wsuivi_badge($s)
{
    if ($s === 'lue') { return '<span class="badge" style="background:rgba(45,106,79,0.25); color:#7bd8a6; border:1px solid rgba(45,106,79,0.5);">✓ Lue</span>'; }
    if ($s === 'reservee') { return '<span class="badge" style="background:rgba(212,175,55,0.16); color:#ffd873; border:1px solid rgba(212,175,55,0.45);">Réservée</span>'; }
    return '<span class="badge" style="background:rgba(255,255,255,0.05); color:var(--text-muted);">Libre</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi — <?php echo htmlspecialchars($session['titre']); ?> - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .ws-wrap { max-width: 900px; margin: 2rem auto; }
        .ws-prog { height: 10px; border-radius: 50px; background: rgba(255,255,255,0.08); overflow: hidden; margin: 0.4rem 0 0.6rem; }
        .ws-prog > i { display: block; height: 100%; background: linear-gradient(90deg,#2d6a4f,#7bd8a6); }
        .ws-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
        .ws-stat { border-radius: 14px; padding: 0.85rem 1rem; text-align: center; }
        .ws-stat .n { font-size: 1.5rem; font-weight: 800; color: var(--white); line-height: 1.1; }
        .ws-stat .l { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; margin-top: 0.2rem; }
        .ws-table { width: 100%; border-collapse: collapse; }
        .ws-table th { text-align: left; font-size: 0.72rem; text-transform: uppercase; color: var(--text-muted); padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--glass-border); }
        .ws-table td { padding: 0.5rem 0.6rem; border-bottom: 1px solid rgba(255,255,255,0.06); color: #fff; font-size: 0.86rem; }
        .ws-actions { display: flex; gap: 0.3rem; flex-wrap: wrap; }
        .ws-actions .btn { padding: 0.28rem 0.55rem; font-size: 0.7rem; }
        @media (max-width: 720px){ .ws-table thead{ display:none; } .ws-table, .ws-table tbody, .ws-table tr, .ws-table td{ display:block; width:100%; } .ws-table tr{ border:1px solid var(--glass-border); border-radius:12px; margin-bottom:0.7rem; padding:0.6rem 0.8rem; } .ws-table td{ border:none; padding:0.2rem 0; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">

        <div class="ws-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.25rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <span>📊 Suivi — <strong class="gold-text"><?php echo htmlspecialchars($session['titre']); ?></strong></span>
                <a href="wird_admin.php" class="btn btn-secondary btn-sm">← Sessions</a>
            </div>

            <!-- Indicateurs de suivi -->
            <div class="ws-stats">
                <div class="glass-card ws-stat"><div class="n" style="color:#7bd8a6;"><?php echo $nbLues; ?></div><div class="l">Lues</div></div>
                <div class="glass-card ws-stat"><div class="n" style="color:#ffd873;"><?php echo $nbRes; ?></div><div class="l">Réservées</div></div>
                <div class="glass-card ws-stat"><div class="n"><?php echo $nbLibres; ?></div><div class="l">Libres</div></div>
                <div class="glass-card ws-stat"><div class="n"><?php echo $nbPart; ?></div><div class="l">Participants</div></div>
                <div class="glass-card ws-stat"><div class="n" style="font-size:1.25rem;"><?php echo $pct; ?>%</div><div class="l">Avancement</div></div>
            </div>

            <div class="glass-card" style="border-radius:16px; padding:1.25rem 1.5rem; margin-bottom:1.25rem;">
                <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
                    <strong style="color:#fff;"><?php echo $nbLues; ?>/30 lues</strong>
                    <span style="color:var(--text-muted); font-size:0.9rem;"><?php echo $nbRes; ?> réservées · <?php echo 30 - $nbLues - $nbRes; ?> libres · <?php echo $pct; ?>%</span>
                </div>
                <div class="ws-prog"><i style="width:<?php echo $pct; ?>%;"></i></div>
                <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.85rem;">
                    <?php if ($nbRes > 0): ?>
                    <form action="wird_suivi.php" method="POST" style="margin:0;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="remind_all">
                        <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                        <button type="submit" class="btn btn-primary btn-sm">✉️ Rappel e-mail aux réservataires</button>
                    </form>
                    <?php endif; ?>
                    <a href="https://wa.me/?text=<?php echo rawurlencode($waReminderMsg); ?>" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 Rappel WhatsApp</a>
                    <?php if ($waGroup !== ''): ?>
                    <button type="button" class="btn btn-sm" style="background:#128C7E; border:1px solid #128C7E; color:#fff; font-weight:700;" onclick="shareGroup(<?php echo htmlspecialchars(json_encode($waReminderMsg), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($waGroup), ENT_QUOTES); ?>)">🟢 Groupe (copier)</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass-card" style="border-radius:16px; padding:1.25rem 1.5rem;">
                <div class="table-responsive">
                    <table class="ws-table">
                        <thead><tr><th>Juz</th><th>Participant</th><th>Statut</th><th>Réservée</th><th>Validée</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($parts as $p): ?>
                                <?php $wa = !empty($p['m_tel']) ? wa_number($p['m_tel']) : ''; ?>
                                <tr>
                                    <td><strong><?php echo (int)$p['numero']; ?></strong></td>
                                    <td><?php echo !empty($p['membre_nom']) ? htmlspecialchars($p['membre_nom']) : '<span style="color:var(--text-muted);">—</span>'; ?></td>
                                    <td><?php echo wsuivi_badge($p['statut']); ?></td>
                                    <td style="color:var(--text-muted); font-size:0.8rem;"><?php echo $p['reserved_at'] ? date('d/m H:i', strtotime($p['reserved_at'])) : '—'; ?></td>
                                    <td style="color:var(--text-muted); font-size:0.8rem;"><?php echo $p['validated_at'] ? date('d/m H:i', strtotime($p['validated_at'])) : '—'; ?></td>
                                    <td>
                                        <div class="ws-actions">
                                            <?php if ($p['statut'] === 'reservee' && $wa !== ''): ?>
                                                <a href="https://wa.me/<?php echo $wa; ?>?text=<?php echo rawurlencode('Assalamu aleykum, rappel pour la lecture du Juz ' . (int)$p['numero'] . ' (' . $session['titre'] . '). Merci de valider ta lecture.'); ?>" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 WA</a>
                                            <?php endif; ?>
                                            <?php if ($p['statut'] !== 'libre'): ?>
                                                <form action="wird_suivi.php" method="POST" style="margin:0;" data-confirm="Libérer cette partie ?">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="free_part">
                                                    <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
                                                    <input type="hidden" name="part_id" value="<?php echo (int)$p['id']; ?>">
                                                    <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">Libérer</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

            </div>
        </div>
    </main>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header"><?php if (!empty($success)): ?><h3 class="gold-text">Réussi</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?></div>
            <div class="modal-body"><p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <script>
        function shareGroup(msg, link){
            try { navigator.clipboard.writeText(msg); } catch (e) {}
            window.location.href = link;
        }
    </script>
</body>
</html>
