<?php
/**
 * Touba Lyon 2026 - 📊 Sondages (espace membre)
 *
 * Les sondages publiés par les commissions : le membre répond (et peut modifier sa réponse
 * tant que le sondage est ouvert). Les résultats sont visibles par tous les membres ou
 * seulement par le bureau, selon le choix fait à la création.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/sondage_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/player_approved_guard.php';

$membreId = (int) $_SESSION['player_id'];
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
$estBureau = sondage_est_bureau($pdo, $membreId);
// Commissions dont le membre est responsable : il peut créer des sondages
$mesCommissions = [];
try {
    $stc = $pdo->prepare("SELECT c.id, c.nom FROM commissions c JOIN commission_gestionnaires cg ON cg.commission_id = c.id WHERE cg.membre_id = ? ORDER BY c.nom ASC");
    $stc->execute([$membreId]);
    $mesCommissions = $stc->fetchAll();
} catch (Exception $e) { $mesCommissions = []; }
$success = '';
if (!empty($_SESSION['sondage_flash'])) { $success = (string) $_SESSION['sondage_flash']; unset($_SESSION['sondage_flash']); }
$error = '';
$sondageRepondu = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'voter') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité. Veuillez réessayer.";
    } else {
        try {
            $sid = (int) ($_POST['sondage_id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM sondages WHERE id = ?");
            $st->execute([$sid]);
            $s = $st->fetch();
            if (!$s || !sondage_est_ouvert($s)) {
                $error = "Ce sondage n'est plus ouvert aux réponses.";
            } else {
                $valides = array_map('intval', $pdo->query("SELECT id FROM sondage_options WHERE sondage_id = " . (int) $sid)->fetchAll(PDO::FETCH_COLUMN));
                $choix = !empty($s['choix_multiple']) ? array_map('intval', (array) ($_POST['options'] ?? [])) : [(int) ($_POST['option'] ?? 0)];
                $choix = array_values(array_unique(array_filter($choix, static function ($c) use ($valides) { return in_array($c, $valides, true); })));
                if (empty($choix)) {
                    $error = "Choisissez au moins une réponse.";
                    $sondageRepondu = $sid;
                } else {
                    // Remplace la réponse précédente
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM sondage_votes WHERE sondage_id = ? AND membre_id = ?")->execute([$sid, $membreId]);
                    $ins = $pdo->prepare("INSERT INTO sondage_votes (sondage_id, option_id, membre_id) VALUES (?,?,?)");
                    foreach ($choix as $c) { $ins->execute([$sid, $c, $membreId]); }
                    $pdo->commit();
                    $success = "Merci, votre réponse est enregistrée.";
                    $sondageRepondu = $sid;
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Touba Lyon sondage vote : ' . $e->getMessage());
            $error = "Une erreur technique est survenue.";
        }
    }
}

// Sondages publiés
$tous = $pdo->query("SELECT s.*, c.nom AS commission_nom FROM sondages s LEFT JOIN commissions c ON c.id = s.commission_id WHERE s.statut IN ('publie','cloture') ORDER BY s.published_at DESC, s.id DESC")->fetchAll();
$aRepondre = []; $repondus = []; $clotures = [];
foreach ($tous as $s) {
    $mes = sondage_mes_choix($pdo, (int) $s['id'], $membreId);
    $s['_mes'] = $mes;
    if (!sondage_est_ouvert($s)) { $clotures[] = $s; }
    elseif (empty($mes)) { $aRepondre[] = $s; }
    else { $repondus[] = $s; }
}
$csrf = csrf_field();

$carte = function (array $s) use ($pdo, $h, $membreId, $estBureau, $csrf): string {
    $sid = (int) $s['id'];
    $ouvert = sondage_est_ouvert($s);
    $mes = $s['_mes'];
    $opts = sondage_options($pdo, $sid);
    $voirRes = sondage_peut_voir_resultats($pdo, $s, $membreId, $estBureau);
    $multiple = !empty($s['choix_multiple']);
    $o = '<div class="glass-card sd-card" id="s' . $sid . '">';
    $o .= '<div class="sd-q">' . $h($s['question']) . '</div><div class="sd-meta">';
    $o .= '<span>📋 ' . $h($s['commission_nom'] ?? '') . '</span>';
    $o .= $ouvert ? '<span class="sd-badge ok">🟢 Ouvert</span>' : '<span class="sd-badge off">🔒 Clôturé</span>';
    if (!empty($mes)) { $o .= '<span class="sd-badge ok">✓ Vous avez répondu</span>'; }
    if (!empty($s['date_fin']) && $ouvert) { $o .= '<span>⏳ Jusqu\'au ' . $h(date('d/m/Y', strtotime((string) $s['date_fin']))) . '</span>'; }
    if (!empty($s['anonyme'])) { $o .= '<span class="sd-badge">🕶️ Anonyme</span>'; }
    $o .= '</div>';
    if (trim((string) ($s['description'] ?? '')) !== '') { $o .= '<div class="sd-desc">' . $h($s['description']) . '</div>'; }

    if ($ouvert) {
        $o .= '<form method="POST">' . $csrf . '<input type="hidden" name="action" value="voter"><input type="hidden" name="sondage_id" value="' . $sid . '">';
        $o .= '<div style="color:var(--text-muted); font-size:0.8rem; margin-bottom:0.5rem;">' . ($multiple ? 'Plusieurs réponses possibles' : 'Une seule réponse') . '</div>';
        foreach ($opts as $op) {
            $oid = (int) $op['id'];
            $chk = in_array($oid, $mes, true) ? ' checked' : '';
            $o .= '<label class="sd-opt">' . ($multiple
                ? '<input type="checkbox" name="options[]" value="' . $oid . '"' . $chk . '>'
                : '<input type="radio" name="option" value="' . $oid . '"' . $chk . ' required>')
                . '<span>' . $h($op['libelle']) . '</span></label>';
        }
        $o .= '<div class="sd-actions"><button type="submit" class="btn btn-primary">' . (empty($mes) ? '✅ Envoyer ma réponse' : '🔄 Modifier ma réponse') . '</button></div></form>';
        if ($voirRes && !empty($mes)) {
            $o .= '<div style="margin-top:1rem;">' . sondage_resultats_html($opts, sondage_nb_participants($pdo, $sid)) . '</div>';
        } elseif (!$voirRes) {
            $o .= '<div class="sd-note" style="margin-top:0.9rem;">🔐 Les résultats de ce sondage sont réservés au bureau.</div>';
        } else {
            $o .= '<div class="sd-note" style="margin-top:0.9rem;">👁️ Les résultats s\'affichent après votre réponse.</div>';
        }
    } else {
        if ($voirRes) {
            $o .= sondage_resultats_html($opts, sondage_nb_participants($pdo, $sid));
            if (!empty($mes)) {
                $lib = [];
                foreach ($opts as $op) { if (in_array((int) $op['id'], $mes, true)) { $lib[] = $op['libelle']; } }
                $o .= '<div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.5rem;">Votre réponse : ' . $h(implode(', ', $lib)) . '</div>';
            }
        } else {
            $o .= '<div class="sd-note">🔐 Les résultats de ce sondage sont réservés au bureau.</div>';
        }
    }
    $o .= '</div>';
    return $o;
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Sondages — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <?php echo sondage_css(); ?>
    <?php if (!empty($mesCommissions)) { echo sondage_form_css(); } ?>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="admin-content">
                <h1 class="admin-page-title">📊 Sondages</h1>
                <div class="sd-wrap">
                    <?php if ($success !== ''): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($success); ?></div><?php endif; ?>
                    <?php if ($error !== ''): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($error); ?></div><?php endif; ?>

                    <?php if (!empty($mesCommissions)): ?>
                        <?php echo sondage_formulaire_creation($mesCommissions, csrf_field(), 'sondage_gestion.php', 'sondages'); ?>
                        <p style="margin:0 0 1rem;"><a href="sondage_gestion.php" class="btn btn-secondary btn-sm">🛠️ Gérer mes sondages (brouillons, clôture, suppression)</a></p>
                    <?php endif; ?>

                    <?php if (empty($tous)): ?>
                        <div class="glass-card sd-card"><p style="margin:0; color:var(--text-muted);">Aucun sondage pour le moment.</p></div>
                    <?php endif; ?>

                    <?php
                    $blocs = ['⏳ À répondre' => $aRepondre, '✅ Mes réponses' => $repondus, '🔒 Clôturés' => $clotures];
                    foreach ($blocs as $titre => $liste):
                        if (empty($liste)) { continue; } ?>
                        <details class="sd-grp"<?php echo $titre !== '🔒 Clôturés' ? ' open' : ''; ?>>
                            <summary><?php echo $titre; ?> <span class="sd-badge"><?php echo count($liste); ?></span></summary>
                            <?php foreach ($liste as $s) { echo $carte($s); } ?>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>
    <script>
        // Ouvre le groupe contenant le sondage ciblé (lien d'une notification ou réponse envoyée)
        (function () {
            var cible = window.location.hash || <?php echo $sondageRepondu > 0 ? json_encode('#s' . $sondageRepondu) : "''"; ?>;
            if (!cible) { return; }
            var el = document.getElementById(cible.slice(1));
            if (!el) { return; }
            var n = el;
            while (n) { if (n.tagName === 'DETAILS') { n.open = true; } n = n.parentElement; }
            el.scrollIntoView({ block: 'center' });
        })();
    </script>
<script src="modern-date.js" defer></script>
</body>
</html>
