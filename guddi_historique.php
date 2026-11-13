<?php
/**
 * Touba Lyon 2026 - 💎 Guddi Àjjuma : historique des séances (espace membre)
 *
 * Liste les séances passées ou clôturées, de la plus récente à la plus ancienne, regroupées par année.
 * Chaque séance affiche sa date, son thème, son présentateur et son livre ; l'enregistrement joint à la séance
 * (MP3, M4A, OGG, WAV) se lit directement dans la page avec un lecteur. Accessible aux membres validés
 * (et aux administrateurs). La gestion des séances reste dans admin_guddi.php.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/planning_dahira_helper.php';
require_once __DIR__ . '/planning_guddi_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__guAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Accès : membre connecté et validé, ou administrateur
if (empty($_SESSION['player_id']) && !$__guAdmin) {
    header('Location: login.php');
    exit;
}
if (!empty($_SESSION['player_id']) && !$__guAdmin) {
    try {
        $st = $pdo->prepare("SELECT status FROM membres WHERE id = ?");
        $st->execute([(int) $_SESSION['player_id']]);
        $stt = $st->fetchColumn();
        if ($stt !== false && $stt !== 'approved') {
            header('Location: profile.php');
            exit;
        }
    } catch (Exception $e) { /* silencieux */ }
}

$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

// Séances tenues : jeudis passés ou clôturés (hors séances annulées), de la plus récente à la plus ancienne
$seances = [];
try {
    $seances = $pdo->query("SELECT id, date_guddi, theme, presentateur, livre, mode, nb_participants, audio_path
                            FROM guddi_plannings
                            WHERE COALESCE(actif, 1) = 1 AND (date_guddi < CURDATE() OR COALESCE(cloture, 0) = 1)
                            ORDER BY date_guddi DESC")->fetchAll();
} catch (Exception $e) { $seances = []; }

$parAnnee = [];
$nbAudios = 0;
foreach ($seances as $sg) {
    $parAnnee[substr((string) $sg['date_guddi'], 0, 4)][] = $sg;
    if (trim((string) ($sg['audio_path'] ?? '')) !== '') { $nbAudios++; }
}
$anneeOuverte = array_key_first($parAnnee);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💎 Guddi Àjjuma — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .gh-wrap { max-width: 900px; margin: 0 auto; }
        .gh-search { width: 100%; box-sizing: border-box; margin: 0 0 1rem; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); border-radius: 12px; padding: 0.6rem 1rem; font-size: 0.92rem; }
        .gh-search:focus { outline: none; border-color: var(--accent); }
        .gh-date { color: var(--gold); font-weight: 800; font-size: 0.92rem; }
        .gh-theme { color: #fff; font-weight: 700; }
        .gh-meta { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.15rem; line-height: 1.5; }
        .gh-audio { flex: 1 1 100%; min-width: 0; }
        .gh-audio audio { width: 100%; height: 40px; display: block; }
        .gh-noaudio { color: var(--text-muted); font-size: 0.78rem; font-style: italic; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__guAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">
            <div class="gh-wrap">
                <h1 class="admin-page-title" style="margin-top:2rem;">💎 Guddi Àjjuma — Historique</h1>
                <p style="color:var(--text-muted); font-size:0.88rem; margin:0 0 1rem; line-height:1.55;">
                    Retrouvez les séances passées du Guddi Àjjuma<?php echo $nbAudios > 0 ? ' : <strong style="color:#ffd873;">' . (int) $nbAudios . ' enregistrement(s)</strong> à écouter directement ici' : ''; ?>.
                </p>

                <?php if (empty($seances)): ?>
                    <div class="empty-state"><div class="empty-state-icon">💎</div><p>Aucune séance dans l'historique pour le moment.</p></div>
                <?php else: ?>
                    <input type="text" id="gh-search" class="gh-search" autocomplete="off" placeholder="🔍 Rechercher un thème, un présentateur, un livre, une date…">
                    <div id="gh-vide" class="empty-state" style="display:none;"><div class="empty-state-icon">🔍</div><p>Aucune séance ne correspond.</p></div>

                    <?php foreach ($parAnnee as $an => $liste): ?>
                    <details class="glass-card gh-annee"<?php echo (string) $an === (string) $anneeOuverte ? ' open' : ''; ?>>
                        <summary>📅 <?php echo $h($an); ?> <span style="color:var(--text-muted); font-weight:600; font-size:0.82rem;">(<span class="gh-n"><?php echo count($liste); ?></span>)</span></summary>
                        <div class="dlist" style="margin-top:0.8rem;">
                            <?php foreach ($liste as $sg):
                                $gId = (int) $sg['id'];
                                $gDate = (string) $sg['date_guddi'];
                                $gTheme = trim((string) ($sg['theme'] ?? ''));
                                $gPres = trim((string) ($sg['presentateur'] ?? ''));
                                $gLivre = trim((string) ($sg['livre'] ?? ''));
                                $gAudioBase = trim((string) ($sg['audio_path'] ?? ''));
                                $gAudioFichier = $gAudioBase !== '' ? guddi_audio_fichier_abs($gAudioBase) : null;
                                $gDateLongue = guddi_date_longue($gDate);
                                $gRecherche = mb_strtolower($gDateLongue . ' ' . $gDate . ' ' . $gTheme . ' ' . $gPres . ' ' . $gLivre);
                            ?>
                            <div class="drow gh-row" data-search="<?php echo $h($gRecherche); ?>">
                                <div class="dcell dgrow">
                                    <div class="gh-date">📅 <?php echo $h($gDateLongue); ?></div>
                                    <div class="gh-theme"><?php echo $gTheme !== '' ? $h($gTheme) : 'Séance du Guddi Àjjuma'; ?></div>
                                    <?php if ($gPres !== '' || $gLivre !== ''): ?>
                                    <div class="gh-meta"><?php echo $gPres !== '' ? '🎤 ' . $h($gPres) : ''; ?><?php echo ($gPres !== '' && $gLivre !== '') ? ' · ' : ''; ?><?php echo $gLivre !== '' ? '📖 ' . $h($gLivre) : ''; ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="dcell"><a href="guddi_detail.php?id=<?php echo $gId; ?>" class="btn btn-secondary btn-sm">📄 Détails</a></div>
                                <div class="dcell gh-audio">
                                    <?php if ($gAudioFichier !== null && is_file($gAudioFichier)): ?>
                                        <audio controls preload="none" controlsList="nodownload noplaybackrate" oncontextmenu="return false;" src="<?php echo $h(guddi_audio_lecture_url($gId)); ?>"></audio>
                                    <?php else: ?>
                                        <span class="gh-noaudio">🎧 Pas d'enregistrement pour cette séance.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            </div>
        </div>
    </main>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <script>
        (function () {
            // Un seul enregistrement à la fois : lancer une lecture met les autres en pause
            document.addEventListener('play', function (e) {
                var cible = e.target;
                if (!cible || cible.tagName !== 'AUDIO') { return; }
                document.querySelectorAll('.gh-audio audio').forEach(function (a) { if (a !== cible) { a.pause(); } });
            }, true);
            // Recherche : masque les séances qui ne correspondent pas, ouvre les années concernées
            var champ = document.getElementById('gh-search');
            if (!champ) { return; }
            function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
            champ.addEventListener('input', function () {
                var q = norm(champ.value), total = 0;
                document.querySelectorAll('.gh-annee').forEach(function (g) {
                    var vus = 0;
                    g.querySelectorAll('.gh-row').forEach(function (r) {
                        var ok = (q === '' || norm(r.getAttribute('data-search')).indexOf(q) !== -1);
                        r.style.display = ok ? '' : 'none';
                        if (ok) { vus++; }
                    });
                    g.style.display = vus === 0 ? 'none' : '';
                    var n = g.querySelector('.gh-n'); if (n) { n.textContent = vus; }
                    if (q !== '' && vus > 0) { g.open = true; }
                    total += vus;
                });
                var vide = document.getElementById('gh-vide'); if (vide) { vide.style.display = total === 0 ? '' : 'none'; }
            });
        })();
    </script>
</body>
</html>
