<?php
/**
 * Touba Lyon 2026 - 📰 Détail d'une actualité (côté membre)
 *
 * Affiche une actualité publiée : type, titre, date, contenu (avec mentions
 * cliquables), image jointe et document PDF. Accessible aux membres connectés.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/planning_dahira_helper.php';
require_once __DIR__ . '/actualites_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__acAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$membreId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;
if ($membreId === 0 && !$__acAdmin) {
    header('Location: login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$a = null;
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM actualites WHERE id = ?");
        $st->execute([$id]);
        $a = $st->fetch();
    } catch (Exception $e) { $a = null; }
}
// Visible par les membres seulement si publiée et non clôturée ; les admins voient tout.
if (!$a || ((($a['statut'] ?? '') !== 'publie' || (int) ($a['cloture'] ?? 0) === 1) && !$__acAdmin)) {
    header('Location: index.php');
    exit;
}

$type = (string) ($a['type'] ?? '');
$titre = (string) ($a['titre'] ?? '');
$emoji = actu_type_emoji($type);
$dateAff = date('d/m/Y à H:i', strtotime((string) ($a['published_at'] ?? $a['created_at'])));
$mentionsMap = actu_mentions_map($pdo, (string) ($a['mentions'] ?? ''));
$image = trim((string) ($a['image_path'] ?? ''));
$pdf = trim((string) ($a['pdf_path'] ?? ''));
$retour = $__acAdmin ? 'actualites.php' : 'index.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $emoji; ?> <?php echo htmlspecialchars($titre); ?> — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .ad-wrap { max-width: 780px; margin: 2rem auto; }
        .ad-hero { border-radius: 20px; padding: 1.6rem 1.6rem 1.3rem; background: linear-gradient(160deg, rgba(212,175,55,0.14) 0%, rgba(255,255,255,0.03) 100%); border: 2px solid rgba(212,175,55,0.5); margin-bottom: 1.3rem; }
        .ad-type { font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--accent); font-weight: 700; }
        .ad-title { font-size: 1.55rem; font-weight: 800; color: var(--white); line-height: 1.25; margin: 0.3rem 0; }
        .ad-date { font-size: 0.82rem; color: var(--text-muted); }
        .ad-content { color: #e8e8e8; font-size: 0.98rem; line-height: 1.7; white-space: pre-wrap; word-break: break-word; }
        .ad-content a { color: var(--accent); font-weight: 700; }
        .ad-img { width: 100%; border-radius: 14px; border: 1px solid var(--glass-border); margin: 1.1rem 0; }
        .ad-pdf-btn { display: inline-flex; align-items: center; gap: 0.5rem; background: linear-gradient(135deg,#d4af37,#e8c766); color: #08150f; font-weight: 800; border-radius: 12px; padding: 0.8rem 1.3rem; text-decoration: none; }
        @media (max-width: 600px) { .ad-title { font-size: 1.3rem; } .ad-pdf-btn { width: 100%; justify-content: center; box-sizing: border-box; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__acAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="admin-content">
                <a href="<?php echo $retour; ?>" class="btn btn-secondary btn-sm" style="margin-bottom:1rem;">← Retour</a>
                <div class="ad-wrap">
                    <div class="ad-hero glass-card">
                        <div class="ad-type"><?php echo $emoji; ?> <?php echo $type !== '' ? htmlspecialchars($type) : 'Actualité'; ?></div>
                        <h1 class="ad-title"><?php echo htmlspecialchars($titre); ?></h1>
                        <div class="ad-date">📅 <?php echo $dateAff; ?><?php echo ($__acAdmin && (($a['statut'] ?? '') !== 'publie' || (int) ($a['cloture'] ?? 0) === 1)) ? ' · <span style="color:#fca5a5;">(non visible par les membres)</span>' : ''; ?></div>
                    </div>

                    <?php if ($image !== ''): ?>
                        <a href="uploads/<?php echo htmlspecialchars($image); ?>" target="_blank" rel="noopener"><img class="ad-img" src="uploads/<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($titre); ?>"></a>
                    <?php endif; ?>

                    <div class="glass-card" style="margin-bottom:1.3rem;">
                        <div class="ad-content"><?php echo actu_contenu_html((string) ($a['contenu'] ?? ''), $mentionsMap, false, 'color:var(--accent);font-weight:700;text-decoration:underline;'); ?></div>
                        <?php if ($pdf !== ''): ?>
                            <div style="margin-top:1.2rem;">
                                <a href="uploads/<?php echo htmlspecialchars($pdf); ?>" target="_blank" rel="noopener" class="ad-pdf-btn">📎 Ouvrir le document PDF</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="color:var(--text-muted); font-size:0.82rem;">— Secrétariat Général, Touba Lyon</div>
                </div>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
</body>
</html>
