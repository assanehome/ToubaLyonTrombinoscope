<?php
/**
 * Touba Lyon 2026 - 📊 Gérer les sondages (responsables de commission)
 *
 * Création d'un sondage (question, choix, choix multiple, anonyme, date de fin, résultats visibles
 * par tous les membres ou par le bureau), publication (les membres sont notifiés), clôture, suppression.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/notification_helper.php'; // troba_notify_all_membres()
require_once __DIR__ . '/sondage_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
if (empty($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/player_approved_guard.php';

$membreId = (int) $_SESSION['player_id'];
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

// Commissions dont le membre est responsable
$mesCommissions = [];
try {
    $st = $pdo->prepare("SELECT c.id, c.nom FROM commissions c JOIN commission_gestionnaires cg ON cg.commission_id = c.id WHERE cg.membre_id = ? ORDER BY c.nom ASC");
    $st->execute([$membreId]);
    $mesCommissions = $st->fetchAll();
} catch (Exception $e) { $mesCommissions = []; }
if (empty($mesCommissions)) {
    header('Location: index.php');
    exit;
}
$idsCommissions = array_map(static function ($c) { return (int) $c['id']; }, $mesCommissions);

$success = '';
$error = '';

/** Charge un sondage géré par le membre, ou null. */
$chargerSondage = function (int $id) use ($pdo, $membreId): ?array {
    $st = $pdo->prepare("SELECT * FROM sondages WHERE id = ?");
    $st->execute([$id]);
    $s = $st->fetch();
    return ($s && sondage_peut_gerer($pdo, $s, $membreId)) ? $s : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité. Veuillez réessayer.";
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $cid = (int) ($_POST['commission_id'] ?? 0);
                $question = trim((string) ($_POST['question'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                $multiple = !empty($_POST['choix_multiple']) ? 1 : 0;
                $anonyme = !empty($_POST['anonyme']) ? 1 : 0;
                $visib = ($_POST['resultats_visibilite'] ?? '') === SONDAGE_VISIBILITE_BUREAU ? SONDAGE_VISIBILITE_BUREAU : SONDAGE_VISIBILITE_TOUS;
                $dateFin = trim((string) ($_POST['date_fin'] ?? ''));
                $publier = !empty($_POST['publier']);
                $options = [];
                foreach ((array) ($_POST['options'] ?? []) as $o) {
                    $o = trim((string) $o);
                    if ($o !== '' && !in_array(mb_strtolower($o), array_map('mb_strtolower', $options), true)) { $options[] = mb_substr($o, 0, 200); }
                }
                if (!in_array($cid, $idsCommissions, true)) {
                    $error = "Choisissez une commission dont vous êtes responsable.";
                } elseif ($question === '') {
                    $error = "Indiquez la question du sondage.";
                } elseif (count($options) < 2) {
                    $error = "Ajoutez au moins deux choix de réponse.";
                } elseif (count($options) > 12) {
                    $error = "12 choix au maximum.";
                } elseif ($dateFin !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin)) {
                    $error = "Date de fin invalide.";
                } else {
                    $pdo->prepare("INSERT INTO sondages (commission_id, auteur_id, question, description, choix_multiple, anonyme, resultats_visibilite, statut, date_fin, published_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$cid, $membreId, mb_substr($question, 0, 255), $description !== '' ? $description : null, $multiple, $anonyme, $visib, $publier ? 'publie' : 'brouillon', $dateFin !== '' ? $dateFin : null, $publier ? date('Y-m-d H:i:s') : null]);
                    $sid = (int) $pdo->lastInsertId();
                    $ins = $pdo->prepare("INSERT INTO sondage_options (sondage_id, libelle, position) VALUES (?,?,?)");
                    foreach ($options as $pos => $lib) { $ins->execute([$sid, $lib, $pos]); }
                    if ($publier) {
                        $n = troba_notify_all_membres($pdo, 'sondage', '📊 Nouveau sondage', mb_substr($question, 0, 150), 'sondages.php#s' . $sid);
                        $success = "Sondage publié : $n membre(s) notifié(s).";
                    } else {
                        $success = "Sondage enregistré en brouillon. Publiez-le quand il est prêt.";
                    }
                }
            } elseif (in_array($action, ['publish', 'close', 'reopen', 'delete'], true)) {
                $s = $chargerSondage((int) ($_POST['sondage_id'] ?? 0));
                if (!$s) {
                    $error = "Sondage introuvable ou non autorisé.";
                } elseif ($action === 'publish') {
                    if ($s['statut'] === 'publie') {
                        $error = "Ce sondage est déjà publié.";
                    } else {
                        $pdo->prepare("UPDATE sondages SET statut = 'publie', published_at = NOW() WHERE id = ?")->execute([(int) $s['id']]);
                        $n = troba_notify_all_membres($pdo, 'sondage', '📊 Nouveau sondage', mb_substr((string) $s['question'], 0, 150), 'sondages.php#s' . (int) $s['id']);
                        $success = "Sondage publié : $n membre(s) notifié(s).";
                    }
                } elseif ($action === 'close') {
                    $pdo->prepare("UPDATE sondages SET statut = 'cloture' WHERE id = ?")->execute([(int) $s['id']]);
                    $success = "Sondage clôturé.";
                } elseif ($action === 'reopen') {
                    // Réouverture : repasse en publié ; une date de fin dépassée est supprimée
                    $pdo->prepare("UPDATE sondages SET statut = 'publie', date_fin = IF(date_fin IS NOT NULL AND date_fin < CURDATE(), NULL, date_fin) WHERE id = ?")->execute([(int) $s['id']]);
                    $success = "Sondage rouvert.";
                } else {
                    $pdo->prepare("DELETE FROM sondage_votes WHERE sondage_id = ?")->execute([(int) $s['id']]);
                    $pdo->prepare("DELETE FROM sondage_options WHERE sondage_id = ?")->execute([(int) $s['id']]);
                    $pdo->prepare("DELETE FROM sondages WHERE id = ?")->execute([(int) $s['id']]);
                    $success = "Sondage supprimé.";
                }
            }
        } catch (Exception $e) {
            error_log('Touba Lyon sondage gestion : ' . $e->getMessage());
            $error = "Une erreur technique est survenue.";
        }
    }
}

// Sondages gérés : créés par moi ou rattachés à l'une de mes commissions
$in = implode(',', $idsCommissions);
$tous = $pdo->query("SELECT s.*, c.nom AS commission_nom FROM sondages s LEFT JOIN commissions c ON c.id = s.commission_id WHERE s.auteur_id = $membreId OR s.commission_id IN ($in) ORDER BY s.id DESC")->fetchAll();
$groupes = ['ouverts' => [], 'brouillons' => [], 'clotures' => []];
foreach ($tous as $s) {
    if ($s['statut'] === 'brouillon') { $groupes['brouillons'][] = $s; }
    elseif (sondage_est_ouvert($s)) { $groupes['ouverts'][] = $s; }
    else { $groupes['clotures'][] = $s; }
}
$csrf = csrf_field();

/** Carte d'un sondage côté responsable. */
$carte = function (array $s) use ($pdo, $h, $csrf): string {
    $sid = (int) $s['id'];
    $opts = sondage_options($pdo, $sid);
    $nbPart = sondage_nb_participants($pdo, $sid);
    $ouvert = sondage_est_ouvert($s);
    $o = '<div class="glass-card sd-card" id="s' . $sid . '">';
    $o .= '<div class="sd-q">' . $h($s['question']) . '</div><div class="sd-meta">';
    $o .= '<span>📋 ' . $h($s['commission_nom'] ?? '') . '</span>';
    if ($s['statut'] === 'brouillon') { $o .= '<span class="sd-badge">📝 Brouillon</span>'; }
    elseif ($ouvert) { $o .= '<span class="sd-badge ok">🟢 Ouvert</span>'; }
    else { $o .= '<span class="sd-badge off">🔒 Clôturé</span>'; }
    $o .= '<span class="sd-badge gold">' . ($s['resultats_visibilite'] === SONDAGE_VISIBILITE_BUREAU ? '🔐 Résultats : bureau' : '👁️ Résultats : tous les membres') . '</span>';
    $o .= '<span class="sd-badge">' . (!empty($s['choix_multiple']) ? '☑️ Choix multiple' : '🔘 Un seul choix') . '</span>';
    if (!empty($s['anonyme'])) { $o .= '<span class="sd-badge">🕶️ Anonyme</span>'; }
    if (!empty($s['date_fin'])) { $o .= '<span>⏳ Jusqu\'au ' . $h(date('d/m/Y', strtotime((string) $s['date_fin']))) . '</span>'; }
    $o .= '</div>';
    if (trim((string) ($s['description'] ?? '')) !== '') { $o .= '<div class="sd-desc">' . $h($s['description']) . '</div>'; }
    if ($s['statut'] === 'brouillon') {
        $o .= '<ul style="margin:0 0 0.4rem; padding-left:1.2rem; color:var(--white);">';
        foreach ($opts as $op) { $o .= '<li>' . $h($op['libelle']) . '</li>'; }
        $o .= '</ul>';
    } else {
        $o .= sondage_resultats_html($opts, $nbPart);
        if (empty($s['anonyme']) && $nbPart > 0) {
            $vot = sondage_votants($pdo, $sid);
            $o .= '<details class="sd-votants"><summary>👥 Qui a répondu quoi</summary>';
            foreach ($opts as $op) {
                $o .= '<p><strong>' . $h($op['libelle']) . '</strong> : ' . (empty($vot[(int) $op['id']]) ? '—' : $h(implode(', ', $vot[(int) $op['id']]))) . '</p>';
            }
            $o .= '</details>';
        }
    }
    $o .= '<div class="sd-actions">';
    $btn = function (string $action, string $label, string $cls, string $confirm = '', bool $danger = false) use ($sid, $csrf): string {
        return '<form method="POST" style="margin:0;"' . ($confirm !== '' ? ' data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"' . ($danger ? ' data-confirm-danger="1"' : '') : '') . '>'
            . $csrf . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="sondage_id" value="' . $sid . '">'
            . '<button type="submit" class="btn ' . $cls . ' btn-sm">' . $label . '</button></form>';
    };
    if ($s['statut'] === 'brouillon') { $o .= $btn('publish', '📣 Publier', 'btn-primary', 'Publier ce sondage et notifier tous les membres ?'); }
    elseif ($ouvert) { $o .= $btn('close', '🔒 Clôturer', 'btn-secondary', 'Clôturer ce sondage ? Les membres ne pourront plus répondre.'); }
    else { $o .= $btn('reopen', '🔓 Rouvrir', 'btn-secondary', 'Rouvrir ce sondage aux réponses ?'); }
    $o .= $btn('delete', '🗑️ Supprimer', 'btn-secondary', 'Supprimer définitivement ce sondage et ses réponses ?', true);
    $o .= '</div></div>';
    return $o;
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Gérer les sondages — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <?php echo sondage_css(); ?>
    <style>
        .sd-vis { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }
        .sd-vis .sd-opt { margin: 0; align-items: flex-start; }
        .sd-vis small { display: block; color: var(--text-muted); font-size: 0.78rem; margin-top: 0.15rem; }
        .sd-optrow { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center; }
        .sd-optrow input { flex: 1; min-width: 0; }
        .sd-optrow button { flex: 0 0 auto; width: 42px; height: 42px; border-radius: 12px; border: 1px solid rgba(191,33,33,0.4); background: rgba(191,33,33,0.12); color: #fca5a5; cursor: pointer; font-size: 1rem; }
        .sd-checks { display: flex; flex-wrap: wrap; gap: 0.6rem; }
        .sd-checks .sd-opt { margin: 0; flex: 1 1 220px; }
        @media (max-width: 600px) { .sd-vis { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="admin-content">
                <h1 class="admin-page-title">📊 Gérer les sondages</h1>
                <div class="sd-wrap">
                    <?php if ($success !== ''): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($success); ?></div><?php endif; ?>
                    <?php if ($error !== ''): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($error); ?></div><?php endif; ?>

                    <details class="glass-card sd-card" <?php echo $error !== '' && ($_POST['action'] ?? '') === 'create' ? 'open' : ''; ?>>
                        <summary style="cursor:pointer; list-style:none; color:var(--white); font-size:1.15rem; font-weight:800;">➕ Nouveau sondage</summary>
                        <form method="POST" style="margin-top:1rem;" id="sd-form">
                            <?php echo $csrf; ?>
                            <input type="hidden" name="action" value="create">
                            <?php if (count($mesCommissions) > 1): ?>
                                <div class="form-group"><label class="form-label">Commission</label>
                                    <select name="commission_id" class="form-input" required>
                                        <?php foreach ($mesCommissions as $c): ?><option value="<?php echo (int) $c['id']; ?>"<?php echo (int) ($_POST['commission_id'] ?? 0) === (int) $c['id'] ? ' selected' : ''; ?>><?php echo $h($c['nom']); ?></option><?php endforeach; ?>
                                    </select></div>
                            <?php else: ?>
                                <input type="hidden" name="commission_id" value="<?php echo (int) $mesCommissions[0]['id']; ?>">
                            <?php endif; ?>
                            <div class="form-group"><label class="form-label">Question <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="question" class="form-input" maxlength="255" required placeholder="Ex : Quel jour pour la sortie annuelle ?" value="<?php echo $h($error !== '' ? ($_POST['question'] ?? '') : ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Précisions (facultatif)</label>
                                <textarea name="description" class="form-input" rows="2" placeholder="Quelques mots pour expliquer le sondage…"><?php echo $h($error !== '' ? ($_POST['description'] ?? '') : ''); ?></textarea></div>
                            <div class="form-group"><label class="form-label">Choix de réponse <span style="color:var(--danger)">*</span> <span style="color:var(--text-muted); font-weight:400;">(2 minimum)</span></label>
                                <div id="sd-options"></div>
                                <button type="button" class="btn btn-secondary btn-sm" id="sd-add">➕ Ajouter un choix</button></div>
                            <div class="form-group"><label class="form-label">Options</label>
                                <div class="sd-checks">
                                    <label class="sd-opt"><input type="checkbox" name="choix_multiple" value="1"> <span>☑️ Plusieurs réponses possibles</span></label>
                                    <label class="sd-opt"><input type="checkbox" name="anonyme" value="1"> <span>🕶️ Réponses anonymes</span></label>
                                </div></div>
                            <div class="form-group"><label class="form-label">👁️ Qui voit les résultats ?</label>
                                <div class="sd-vis">
                                    <label class="sd-opt"><input type="radio" name="resultats_visibilite" value="tous" checked><span>Tous les membres<small>Chacun voit les résultats</small></span></label>
                                    <label class="sd-opt"><input type="radio" name="resultats_visibilite" value="bureau"><span>Le bureau seulement<small>Responsables de commission</small></span></label>
                                </div>
                                <p style="color:var(--text-muted); font-size:0.78rem; margin:0.4rem 0 0;">Vous-même et les responsables de la commission voyez toujours les résultats.</p></div>
                            <div class="form-group"><label class="form-label">⏳ Date de fin (facultatif)</label>
                                <input type="date" name="date_fin" class="form-input" min="<?php echo date('Y-m-d'); ?>"></div>
                            <div class="sd-actions">
                                <button type="submit" name="publier" value="1" class="btn btn-primary">📣 Publier maintenant</button>
                                <button type="submit" class="btn btn-secondary">📝 Enregistrer en brouillon</button>
                            </div>
                        </form>
                    </details>

                    <?php if (empty($tous)): ?>
                        <div class="glass-card sd-card"><p style="margin:0; color:var(--text-muted);">Aucun sondage pour le moment. Créez le premier ci-dessus.</p></div>
                    <?php endif; ?>

                    <?php
                    $titres = ['ouverts' => '🟢 En cours', 'brouillons' => '📝 Brouillons', 'clotures' => '🔒 Clôturés'];
                    foreach ($titres as $k => $t):
                        if (empty($groupes[$k])) { continue; } ?>
                        <details class="sd-grp"<?php echo $k !== 'clotures' ? ' open' : ''; ?>>
                            <summary><?php echo $t; ?> <span class="sd-badge"><?php echo count($groupes[$k]); ?></span></summary>
                            <?php foreach ($groupes[$k] as $s) { echo $carte($s); } ?>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        (function () {
            var box = document.getElementById('sd-options');
            var add = document.getElementById('sd-add');
            var prefill = <?php $pre = $error !== '' ? array_map('trim', array_map('strval', (array) ($_POST['options'] ?? []))) : []; echo json_encode(array_values(array_filter($pre, 'strlen')), JSON_UNESCAPED_UNICODE); ?>;
            function row(val) {
                var d = document.createElement('div');
                d.className = 'sd-optrow';
                var i = document.createElement('input');
                i.type = 'text'; i.name = 'options[]'; i.className = 'form-input'; i.maxLength = 200; i.value = val || '';
                i.placeholder = 'Choix ' + (box.children.length + 1);
                var b = document.createElement('button');
                b.type = 'button'; b.textContent = '✕'; b.setAttribute('aria-label', 'Retirer ce choix');
                b.addEventListener('click', function () { if (box.children.length > 2) { d.remove(); renum(); } });
                d.appendChild(i); d.appendChild(b); box.appendChild(d);
            }
            function renum() { Array.prototype.forEach.call(box.querySelectorAll('input'), function (x, n) { if (!x.value) { x.placeholder = 'Choix ' + (n + 1); } }); }
            (prefill.length >= 2 ? prefill : ['', '']).forEach(row);
            add.addEventListener('click', function () {
                if (box.children.length >= 12) { return; }
                row('');
                box.lastChild.querySelector('input').focus();
            });
        })();
    </script>
</body>
</html>
