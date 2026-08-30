<?php
/**
 * Touba Lyon 2026 - Lecture collective du Coran : page de participation (lien partagé).
 * Accès hybride : membre connecté (identité auto) ou visiteur (saisie du nom).
 * Chaque participant réserve une partie libre (1 par personne), la valide, ou la libère.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Réservé aux membres connectés. On mémorise le lien pour y revenir après connexion.
if (empty($_SESSION['player_id'])) {
    $__ret = 'wird.php?s=' . (int) ($_GET['s'] ?? 0) . '&t=' . urlencode((string) ($_GET['t'] ?? ''));
    $_SESSION['after_login'] = $__ret;
    header('Location: login.php');
    exit;
}

$sid = (int) ($_POST['session_id'] ?? $_GET['s'] ?? 0);
$token = (string) ($_POST['token'] ?? $_GET['t'] ?? '');

$session = null;
if ($sid > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM quran_sessions WHERE id = ?");
        $st->execute([$sid]);
        $session = $st->fetch();
    } catch (Exception $e) {
        $session = null;
    }
}
$validLink = ($session && hash_equals((string) $session['token'], $token));

$isPlayer = !empty($_SESSION['player_id']);
$playerId = $isPlayer ? (int) $_SESSION['player_id'] : 0;
$playerName = $isPlayer ? (string) ($_SESSION['player_name'] ?? '') : '';

function wird_redirect($sid, $token)
{
    header('Location: wird.php?s=' . (int) $sid . '&t=' . urlencode($token));
    exit;
}

if ($validLink && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = '';
    if (!csrf_validate()) {
        $flash = "err:Échec de sécurité (CSRF).";
    } elseif ($session['statut'] !== 'en_cours') {
        $flash = "err:Cette session est clôturée.";
    } else {
        $action = $_POST['action'] ?? '';
        $numero = (int) ($_POST['numero'] ?? 0);
        $pid = (int) ($_POST['part_id'] ?? 0);
        $ptoken = (string) ($_POST['ptoken'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        try {
            if ($action === 'reserve' && $numero >= 1 && $numero <= 30) {
                // Identité
                $mid = $isPlayer ? $playerId : null;
                $mnom = $isPlayer ? $playerName : $nom;
                if (!$isPlayer && $mnom === '') {
                    $flash = "err:Veuillez saisir votre nom avant de choisir une partie.";
                } else {
                    // Un participant peut réserver plusieurs Juz. Réservation atomique si la partie est libre.
                    $upd = $pdo->prepare("UPDATE quran_parts SET statut='reservee', membre_id=?, membre_nom=?, owner_token=?, reserved_at=NOW() WHERE session_id=? AND numero=? AND statut='libre'");
                    $ownTok = $isPlayer ? null : ($ptoken !== '' ? $ptoken : bin2hex(random_bytes(12)));
                    $upd->execute([$mid, $mnom, $ownTok, $sid, $numero]);
                    $flash = $upd->rowCount() > 0 ? "ok:Juz " . $numero . " réservé. Bonne lecture !" : "err:Ce Juz vient d'être pris.";
                }
            } elseif ($action === 'validate' && $pid > 0) {
                $part = $pdo->prepare("SELECT * FROM quran_parts WHERE id=? AND session_id=?");
                $part->execute([$pid, $sid]);
                $pr = $part->fetch();
                if ($pr && $pr['statut'] === 'reservee' && wird_owner_ok($pr, $isPlayer, $playerId, $ptoken)) {
                    $pdo->prepare("UPDATE quran_parts SET statut='lue', validated_at=NOW() WHERE id=?")->execute([$pid]);
                    $flash = "ok:Lecture du Juz " . (int)$pr['numero'] . " validée. Jërëjëf !";
                } else {
                    $flash = "err:Validation impossible.";
                }
            } elseif ($action === 'free' && $pid > 0) {
                $part = $pdo->prepare("SELECT * FROM quran_parts WHERE id=? AND session_id=?");
                $part->execute([$pid, $sid]);
                $pr = $part->fetch();
                if ($pr && $pr['statut'] !== 'libre' && wird_owner_ok($pr, $isPlayer, $playerId, $ptoken)) {
                    $pdo->prepare("UPDATE quran_parts SET statut='libre', membre_id=NULL, membre_nom=NULL, owner_token=NULL, reserved_at=NULL, validated_at=NULL WHERE id=?")->execute([$pid]);
                    $flash = "ok:Le Juz " . (int)$pr['numero'] . " a bien été libéré. Il est de nouveau disponible pour les autres membres.";
                } else {
                    $flash = "err:Libération impossible.";
                }
            }
        } catch (Exception $e) {
            error_log('Touba Lyon wird: ' . $e->getMessage());
            $flash = "err:Une erreur technique est survenue.";
        }
    }
    $_SESSION['wird_flash'] = $flash;
    wird_redirect($sid, $token);
}

function wird_owner_ok($pr, $isPlayer, $playerId, $ptoken)
{
    if ($isPlayer && (int) $pr['membre_id'] === (int) $playerId) { return true; }
    if (!empty($pr['owner_token']) && $ptoken !== '' && hash_equals((string) $pr['owner_token'], (string) $ptoken)) { return true; }
    return false;
}

$parts = [];
if ($validLink) {
    try {
        $st = $pdo->prepare("SELECT * FROM quran_parts WHERE session_id=? ORDER BY numero ASC");
        $st->execute([$sid]);
        $parts = $st->fetchAll();
    } catch (Exception $e) { $parts = []; }
}
$nbLues = 0; $nbRes = 0;
foreach ($parts as $p) { if ($p['statut'] === 'lue') { $nbLues++; } elseif ($p['statut'] === 'reservee') { $nbRes++; } }
$pct = count($parts) ? round($nbLues / count($parts) * 100) : 0;
// Juz réservés (non validés) du membre connecté → mis en évidence en haut (plusieurs possibles)
$myParts = [];
if ($isPlayer && $validLink) {
    foreach ($parts as $p) { if ((int) $p['membre_id'] === $playerId && $p['statut'] === 'reservee') { $myParts[] = $p; } }
}
$flash = $_SESSION['wird_flash'] ?? '';
unset($_SESSION['wird_flash']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $validLink ? htmlspecialchars($session['titre']) : 'Lecture du Coran'; ?> - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .w-wrap { max-width: 760px; margin: 1.5rem auto; padding: 0 1rem; }
        .w-hero { text-align: center; margin-bottom: 1.25rem; }
        .w-hero h1 { color: var(--white); font-size: 1.5rem; margin: 0 0 0.35rem; }
        .w-hero p { color: var(--text-muted); font-size: 0.92rem; margin: 0; }
        .w-prog { height: 10px; border-radius: 50px; background: rgba(255,255,255,0.08); overflow: hidden; margin: 1rem 0 0.4rem; }
        .w-prog > i { display: block; height: 100%; background: linear-gradient(90deg,#2d6a4f,#7bd8a6); transition: width .4s ease; }
        .w-stats { color: var(--text-muted); font-size: 0.85rem; text-align: center; }
        .w-name { display: flex; gap: 0.5rem; max-width: 420px; margin: 1rem auto 0; }
        .w-name input { flex: 1; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 50px; color: #fff; padding: 0.55rem 1rem; font-size: 0.9rem; }
        .w-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 0.7rem; margin-top: 1.25rem; }
        .w-part { border-radius: 14px; padding: 0.85rem; text-align: center; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.03); }
        .w-part.libre { border-style: dashed; }
        .w-part.lue { border-color: rgba(45,106,79,0.6); background: rgba(45,106,79,0.14); }
        .w-part.reservee { border-color: rgba(212,175,55,0.5); background: rgba(212,175,55,0.08); }
        .w-part.mine { border-color: var(--gold) !important; box-shadow: 0 0 0 2px rgba(212,175,55,0.5), 0 10px 26px rgba(212,175,55,0.2); animation: minePulse 1.8s ease-in-out infinite; }
        @keyframes minePulse { 0%,100% { box-shadow: 0 0 0 2px rgba(212,175,55,0.5), 0 10px 26px rgba(212,175,55,0.2); } 50% { box-shadow: 0 0 0 3px rgba(212,175,55,0.8), 0 12px 30px rgba(212,175,55,0.3); } }
        .w-tovalidate { max-width: 760px; margin: 0 auto 1.4rem; padding: 1.15rem 1.3rem; border-radius: 18px; background: linear-gradient(155deg, rgba(27,67,50,0.75), rgba(8,28,21,0.85)); border: 1px solid rgba(212,175,55,0.28); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); box-shadow: 0 14px 40px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.06); color: #ffd873; overflow: hidden; }
        .w-tovalidate::before { content: ''; position: absolute; top: 0; left: -20%; right: -20%; height: 2px; background: linear-gradient(90deg, transparent, rgba(212,175,55,0.9), rgba(241,210,121,0.95), transparent); background-size: 200% 100%; animation: wtvFlow 4.5s linear infinite; }
        @keyframes wtvFlow { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .w-tovalidate { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 0.85rem; flex-wrap: wrap; }
        .wtv-head { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; min-width: 200px; }
        .wtv-head .wtv-badge { width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, rgba(212,175,55,0.32), rgba(212,175,55,0.08)); border: 1px solid rgba(212,175,55,0.4); display: flex; align-items: center; justify-content: center; font-size: 1.25rem; box-shadow: 0 6px 16px rgba(212,175,55,0.18); }
        .wtv-head .wtv-ttl { font-weight: 800; font-size: 0.98rem; color: #fff; letter-spacing: -0.01em; }
        .wtv-head .wtv-sub { font-size: 0.78rem; color: #f1d279; font-weight: 600; }
        .wtv-count { font-size: 0.74rem; font-weight: 800; color: #0c241a; background: linear-gradient(135deg, #d4af37, #f1d279); border-radius: 50px; padding: 3px 11px; box-shadow: 0 4px 12px rgba(212,175,55,0.35); }
        .wtv-item { display: inline-flex; align-items: center; gap: 0.5rem; background: rgba(212,175,55,0.09); border: 1px solid rgba(212,175,55,0.24); border-radius: 12px; padding: 5px 6px; margin: 4px 4px 4px 0; flex-wrap: wrap; justify-content: center; animation: wtvChip 0.4s cubic-bezier(0.22,1,0.36,1) both; transition: transform 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease; }
        @keyframes wtvChip { from { opacity: 0; transform: translateY(8px) scale(0.94); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .wtv-item:hover { transform: translateY(-2px); border-color: rgba(212,175,55,0.55); box-shadow: 0 8px 18px rgba(0,0,0,0.3); }
        .wtv-item .lbl { font-weight: 800; font-size: 0.88rem; color: #f1d279; cursor: pointer; padding: 4px 4px 4px 10px; border-radius: 8px; transition: background 0.2s; }
        .wtv-item .lbl:hover { background: rgba(212,175,55,0.14); }
        .wtv-item .wtv-btn { background: linear-gradient(135deg, #d4af37, #e9c766); color: #0c241a; border: 0; border-radius: 9px; font-weight: 800; font-size: 0.82rem; padding: 7px 13px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; transition: transform 0.12s ease, box-shadow 0.2s ease, filter 0.2s ease; box-shadow: 0 4px 0 rgba(133,105,18,0.9); }
        .wtv-item .wtv-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 rgba(133,105,18,0.9); }
        .wtv-item .wtv-btn:hover { filter: brightness(1.12); }
        .wtv-item .wtv-btn--pdf { background: rgba(212,175,55,0.14); color: #f1d279; border: 1px solid rgba(212,175,55,0.3); box-shadow: none; }
        .wtv-item .wtv-btn--pdf:hover { background: rgba(212,175,55,0.26); color: #fff; box-shadow: none; }
        .w-num { font-size: 1.35rem; font-weight: 800; color: var(--white); }
        .w-juz { font-size: 0.68rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
        .w-who { font-size: 0.8rem; color: #fff; margin: 0.35rem 0; word-break: break-word; min-height: 1em; }
        .w-tag { display: inline-block; font-size: 0.66rem; font-weight: 700; border-radius: 50px; padding: 1px 8px; }
        .w-part .btn { width: 100%; justify-content: center; padding: 0.35rem; font-size: 0.75rem; margin-top: 0.25rem; }
        .w-msg { max-width: 520px; margin: 0 auto 1rem; padding: 0.7rem 1rem; border-radius: 12px; font-size: 0.9rem; text-align: center; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="dashboard-main">
        <div class="w-wrap">
        <?php if (!$validLink): ?>
            <div class="empty-state"><div class="empty-state-icon">🔒</div><p>Lien de lecture invalide ou expiré.</p></div>
        <?php else: ?>
            <?php if ($flash !== ''): $isErr = strpos($flash, 'err:') === 0; $msg = $isErr ? substr($flash, 4) : substr($flash, 3); ?>
                <div class="w-msg" style="background:<?php echo $isErr ? 'rgba(220,80,80,0.15)' : 'rgba(45,106,79,0.2)'; ?>; border:1px solid <?php echo $isErr ? 'rgba(220,80,80,0.45)' : 'rgba(45,106,79,0.5)'; ?>; color:<?php echo $isErr ? '#ff9a9a' : '#7bd8a6'; ?>;"><?php echo htmlspecialchars($msg); ?></div>
            <?php endif; ?>

            <div class="w-hero">
                <h1>📖 <?php echo htmlspecialchars($session['titre']); ?></h1>
                <?php if (!empty($session['description'])): ?><p><?php echo htmlspecialchars($session['description']); ?></p><?php endif; ?>
                <p style="margin-top:0.4rem;">Lecture des 30 Juz du Coran — choisissez une partie, lisez-la, puis validez.</p>
                <div class="w-prog"><i style="width:<?php echo $pct; ?>%;"></i></div>
                <div class="w-stats"><strong class="gold-text"><?php echo $nbLues; ?>/30</strong> lues · <?php echo $nbRes; ?> réservées · <?php echo 30 - $nbLues - $nbRes; ?> libres</div>
                <?php if ($session['statut'] !== 'en_cours'): ?>
                    <div class="w-stats" style="margin-top:0.5rem; color:#ffd873;">Session clôturée — lecture terminée.</div>
                <?php elseif (!$isPlayer): ?>
                    <div class="w-name">
                        <input type="text" id="w-nom" placeholder="Votre nom (pour choisir une partie)" maxlength="120">
                    </div>
                <?php else: ?>
                    <div class="w-stats" style="margin-top:0.5rem;">Connecté : <strong class="gold-text"><?php echo htmlspecialchars($playerName); ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if ($session['statut'] === 'en_cours'): ?>
            <div id="w-tovalidate" class="w-tovalidate" style="<?php echo !empty($myParts) ? '' : 'display:none;'; ?>">
                <div class="wtv-head">
                    <span class="wtv-badge">📖</span>
                    <span style="display:flex; flex-direction:column; gap:0.1rem;">
                        <span class="wtv-ttl">Vos Juz à valider</span>
                        <span class="wtv-sub"><?php echo count($myParts); ?> partie<?php echo count($myParts) > 1 ? 's' : ''; ?> en attente</span>
                    </span>
                    <span class="wtv-count"><?php echo count($myParts); ?></span>
                </div>
                <span id="wtv-list" style="display:inline-flex; flex-wrap:wrap; gap:0.25rem;"><?php foreach ($myParts as $mp): ?>
<?php
    $n = (int)$mp['numero'];
    $n2 = sprintf('%02d', $n);
    $v1 = "pdf_viewer.php?file=" . urlencode("Coran_pdf/Version_1/{$n2}.pdf");
    $v2 = "pdf_viewer.php?file=" . urlencode("Coran_pdf/Version_2/{$n2}-quran{$n}-ar.pdf");
?>
<span class="wtv-item"><span class="lbl" onclick="scrollJuz(<?php echo $n; ?>)">Juz <?php echo $n; ?></span><button type="button" class="wtv-btn" onclick="confirmValidation(<?php echo (int)$mp['id']; ?>, '')">✓ Lu</button><a href="<?php echo htmlspecialchars($v1); ?>" target="_blank" class="wtv-btn wtv-btn--pdf">V1</a><a href="<?php echo htmlspecialchars($v2); ?>" target="_blank" class="wtv-btn wtv-btn--pdf">V2</a></span><?php endforeach; ?></span>
            </div>
            <?php endif; ?>

            <div class="w-grid">
                <?php foreach ($parts as $p): ?>
                    <?php
                        $stt = $p['statut'];
                        $ownedByPlayer = ($isPlayer && (int)$p['membre_id'] === $playerId);
                        $mineActive = ($ownedByPlayer && $stt === 'reservee');
                    ?>
                    <div class="w-part <?php echo $stt; ?><?php echo $mineActive ? ' mine' : ''; ?>" id="wp-<?php echo (int)$p['numero']; ?>" data-num="<?php echo (int)$p['numero']; ?>" data-pid="<?php echo (int)$p['id']; ?>" data-statut="<?php echo $stt; ?>">
                        <div class="w-num"><?php echo (int)$p['numero']; ?></div>
                        <div class="w-juz">Juz <?php echo (int)$p['numero']; ?></div>
                        <div class="w-who">
                            <?php if ($stt === 'lue'): ?><span class="w-tag" style="background:rgba(45,106,79,0.3); color:#7bd8a6;">✓ Lue</span><br><?php echo htmlspecialchars($p['membre_nom'] ?? ''); ?>
                            <?php elseif ($stt === 'reservee'): ?><span class="w-tag" style="background:rgba(212,175,55,0.2); color:#ffd873;">Réservée</span><br><?php echo htmlspecialchars($p['membre_nom'] ?? ''); ?>
                            <?php else: ?><span class="w-tag" style="background:rgba(255,255,255,0.06); color:var(--text-muted);">Libre</span><?php endif; ?>
                        </div>
                        <?php if ($session['statut'] === 'en_cours'): ?>
                            <?php if ($stt === 'libre'): ?>
                                <button type="button" class="btn btn-primary" onclick="confirmReserve(<?php echo (int)$p['numero']; ?>)">Choisir</button>
                            <?php elseif ($ownedByPlayer): ?>
                                <?php
                                $n = (int)$p['numero'];
                                $n2 = sprintf('%02d', $n);
                                $v1 = "pdf_viewer.php?file=" . urlencode("Coran_pdf/Version_1/{$n2}.pdf");
                                $v2 = "pdf_viewer.php?file=" . urlencode("Coran_pdf/Version_2/{$n2}-quran{$n}-ar.pdf");
                                ?>
                                <?php if ($stt === 'reservee'): ?><button type="button" class="btn btn-primary" onclick="confirmValidation(<?php echo (int)$p['id']; ?>, '')">✓ J'ai lu</button><?php endif; ?>
                                <button type="button" class="btn btn-secondary" style="color:var(--warning); border-color:var(--warning);" onclick="confirmFree(<?php echo (int)$p['id']; ?>, '')">Libérer</button>
                                <div style="display:flex; gap:0.25rem; margin-top:0.25rem;">
                                    <a href="<?php echo htmlspecialchars($v1); ?>" target="_blank" class="btn btn-secondary btn-sm" style="flex:1; padding:0.2rem; font-size:0.7rem;">📖 V1</a>
                                    <a href="<?php echo htmlspecialchars($v2); ?>" target="_blank" class="btn btn-secondary btn-sm" style="flex:1; padding:0.2rem; font-size:0.7rem;">📖 V2</a>
                                </div>
                            <?php else: ?>
                                <span class="w-mine" data-num="<?php echo (int)$p['numero']; ?>" data-pid="<?php echo (int)$p['id']; ?>" data-statut="<?php echo $stt; ?>"></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
            </div>
        </div>
    </main>

    <?php if ($validLink): ?>
    <form id="w-form" method="POST" action="wird.php" style="display:none;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="session_id" value="<?php echo $sid; ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($session['token'], ENT_QUOTES); ?>">
        <input type="hidden" name="action" id="wf-action" value="">
        <input type="hidden" name="numero" id="wf-numero" value="">
        <input type="hidden" name="part_id" id="wf-pid" value="">
        <input type="hidden" name="ptoken" id="wf-ptoken" value="">
        <input type="hidden" name="nom" id="wf-nom" value="">
    </form>
    
    <!-- Modal de confirmation Réservation -->
    <div id="reserve-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card">
            <div class="modal-header"><h3 class="gold-text">Confirmer votre choix</h3></div>
            <div class="modal-body"><p id="reserve-message" style="color:var(--text-muted);">Voulez-vous réserver le Juz <strong id="reserve-juz-num" class="gold-text"></strong> ?</p></div>
            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                <button type="button" onclick="closeReserveModal()" class="btn btn-secondary btn-sm">Annuler</button>
                <button type="button" id="reserve-btn" class="btn btn-primary btn-sm">Oui, choisir ce Juz</button>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation Lecture -->
    <div id="confirm-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card">
            <div class="modal-header"><h3 class="gold-text" id="confirm-title">Confirmation</h3></div>
            <div class="modal-body"><p id="confirm-message" style="color:var(--text-muted);">Avez-vous bien terminé la lecture de ce Juz ?</p></div>
            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                <button type="button" onclick="closeConfirmModal()" class="btn btn-secondary btn-sm">Non, annuler</button>
                <button type="button" id="confirm-btn" class="btn btn-primary btn-sm">Oui, j'ai lu</button>
            </div>
        </div>
    </div>
    
    <!-- Modal de confirmation Libération -->
    <div id="free-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card">
            <div class="modal-header"><h3 class="gold-text">Libérer ce Juz</h3></div>
            <div class="modal-body"><p style="color:var(--text-muted);">Êtes-vous sûr de vouloir libérer ce Juz ? Il redeviendra disponible pour les autres participants.</p></div>
            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                <button type="button" onclick="closeFreeModal()" class="btn btn-secondary btn-sm">Annuler</button>
                <button type="button" id="free-btn" class="btn btn-primary btn-sm" style="background:#dc3545; color:#fff; border-color:#dc3545;">Oui, libérer</button>
            </div>
        </div>
    </div>
    
    <script>
        var freeActionPid = 0;
        var freeActionTok = '';
        function confirmFree(pid, tok) {
            freeActionPid = pid;
            freeActionTok = tok;
            var modal = document.getElementById('free-modal');
            modal.style.display = 'flex';
            setTimeout(function() { modal.classList.add('active'); }, 10);
        }
        function closeFreeModal() {
            var modal = document.getElementById('free-modal');
            modal.classList.remove('active');
            setTimeout(function() { modal.style.display = 'none'; }, 400);
        }
        document.getElementById('free-btn').addEventListener('click', function() {
            if (freeActionPid) {
                wAct('free', freeActionPid, freeActionTok);
                closeFreeModal();
            }
        });
        var confirmActionPid = 0;
        var confirmActionTok = '';
        function confirmValidation(pid, tok) {
            confirmActionPid = pid;
            confirmActionTok = tok;
            var modal = document.getElementById('confirm-modal');
            modal.style.display = 'flex';
            setTimeout(function() { modal.classList.add('active'); }, 10);
        }
        function closeConfirmModal() {
            var modal = document.getElementById('confirm-modal');
            modal.classList.remove('active');
            setTimeout(function() { modal.style.display = 'none'; }, 400);
        }
        document.getElementById('confirm-btn').addEventListener('click', function() {
            if (confirmActionPid) {
                wAct('validate', confirmActionPid, confirmActionTok);
                closeConfirmModal();
            }
        });
        var reserveNumero = 0;
        function confirmReserve(numero) {
            reserveNumero = numero;
            document.getElementById('reserve-juz-num').textContent = numero;
            var modal = document.getElementById('reserve-modal');
            modal.style.display = 'flex';
            setTimeout(function() { modal.classList.add('active'); }, 10);
        }
        function closeReserveModal() {
            var modal = document.getElementById('reserve-modal');
            modal.classList.remove('active');
            setTimeout(function() { modal.style.display = 'none'; }, 400);
        }
        document.getElementById('reserve-btn').addEventListener('click', function() {
            if (reserveNumero) {
                wReserve(reserveNumero);
                closeReserveModal();
            }
        });
        var W_SID = <?php echo $sid; ?>;
        var W_PLAYER = <?php echo $isPlayer ? 'true' : 'false'; ?>;
        var W_KEY = 'wird_' + W_SID;
        function wMap(){ try { return JSON.parse(localStorage.getItem(W_KEY) || '{}'); } catch(e){ return {}; } }
        function wSaveMap(m){ try { localStorage.setItem(W_KEY, JSON.stringify(m)); } catch(e){} }
        function wRand(){ return (Date.now().toString(36) + Math.random().toString(36).slice(2, 12)); }
        function wSubmit(action, numero, pid, ptoken, nom){
            document.getElementById('wf-action').value = action;
            document.getElementById('wf-numero').value = numero || '';
            document.getElementById('wf-pid').value = pid || '';
            document.getElementById('wf-ptoken').value = ptoken || '';
            document.getElementById('wf-nom').value = nom || '';
            document.getElementById('w-form').submit();
        }
        function wReserve(numero){
            var nom = '';
            if (!W_PLAYER) {
                var el = document.getElementById('w-nom');
                nom = el ? el.value.trim() : '';
                if (!nom) { modernAlert('Veuillez saisir votre nom.', 'Information'); if (el) el.focus(); return; }
                try { localStorage.setItem('wird_nom', nom); } catch(e){}
                var tok = wRand();
                var m = wMap(); m[numero] = tok; wSaveMap(m);
                wSubmit('reserve', numero, '', tok, nom);
            } else {
                wSubmit('reserve', numero, '', '', '');
            }
        }
        function wAct(action, pid, ptoken){ wSubmit(action, '', pid, ptoken, ''); }
        function scrollJuz(n){ var el = document.getElementById('wp-' + n); if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } }
        // Visiteur : restaurer le nom + boutons des parties réservées via ce navigateur + chips en haut
        (function(){
            if (!W_PLAYER) {
                try { var n = localStorage.getItem('wird_nom'); if (n) { var el = document.getElementById('w-nom'); if (el) el.value = n; } } catch(e){}
                var m = wMap(); var changed = false; var myItems = [];
                document.querySelectorAll('.w-mine').forEach(function(sp){
                    var num = sp.getAttribute('data-num');
                    var pid = sp.getAttribute('data-pid');
                    var stt = sp.getAttribute('data-statut');
                    if (m[num]) {
                        if (stt === 'libre') { delete m[num]; changed = true; return; }
                        var tok = m[num];
                        var part = sp.closest('.w-part');
                        var html = '';
                        var n = parseInt(num, 10);
                        var n2 = n < 10 ? '0' + n : n;
                        var v1 = 'pdf_viewer.php?file=' + encodeURIComponent('Coran_pdf/Version_1/' + n2 + '.pdf');
                        var v2 = 'pdf_viewer.php?file=' + encodeURIComponent('Coran_pdf/Version_2/' + n2 + '-quran' + n + '-ar.pdf');
                        if (stt === 'reservee') {
                            if (part) { part.classList.add('mine'); }
                            myItems.push({ num: parseInt(num, 10), pid: pid, tok: tok });
                            html += '<button type="button" class="btn btn-primary" onclick="confirmValidation(' + pid + ',\'' + tok + '\')">✓ J\'ai lu</button>';
                        }
                        html += '<button type="button" class="btn btn-secondary" style="color:var(--warning); border-color:var(--warning);" onclick="confirmFree(' + pid + ',\'' + tok + '\')">Libérer</button>';
                        html += '<div style="display:flex; gap:0.25rem; margin-top:0.25rem;"><a href="' + v1 + '" target="_blank" class="btn btn-secondary btn-sm" style="flex:1; padding:0.2rem; font-size:0.7rem;">📖 V1</a><a href="' + v2 + '" target="_blank" class="btn btn-secondary btn-sm" style="flex:1; padding:0.2rem; font-size:0.7rem;">📖 V2</a></div>';
                        sp.outerHTML = html;
                    }
                });
                if (changed) wSaveMap(m);
                // Construire la liste (avec bouton de validation) des Juz à valider en haut
                var box = document.getElementById('w-tovalidate'); var list = document.getElementById('wtv-list');
                if (box && list && myItems.length) {
                    myItems.sort(function(a,b){ return a.num - b.num; });
                    list.innerHTML = myItems.map(function(it){ 
                        var n2 = it.num < 10 ? '0' + it.num : it.num;
                        var v1 = 'pdf_viewer.php?file=' + encodeURIComponent('Coran_pdf/Version_1/' + n2 + '.pdf');
                        var v2 = 'pdf_viewer.php?file=' + encodeURIComponent('Coran_pdf/Version_2/' + n2 + '-quran' + it.num + '-ar.pdf');
                        return '<span class="wtv-item"><span class="lbl" onclick="scrollJuz(' + it.num + ')">Juz ' + it.num + '</span><button type="button" class="wtv-btn" onclick="confirmValidation(' + it.pid + ',\'' + it.tok + '\')">✓ Lu</button><a href="' + v1 + '" target="_blank" class="wtv-btn wtv-btn--pdf">V1</a><a href="' + v2 + '" target="_blank" class="wtv-btn wtv-btn--pdf">V2</a></span>'; 
                    }).join('');
                    box.style.display = 'flex';
                    var cnt = box.querySelector('.wtv-count');
                    if (cnt) { cnt.textContent = myItems.length; var sub = box.querySelector('.wtv-sub'); if (sub) sub.textContent = myItems.length + ' partie' + (myItems.length > 1 ? 's' : '') + ' en attente'; }
                }
            }
        })();
    </script>
    <?php endif; ?>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
</body>
</html>
