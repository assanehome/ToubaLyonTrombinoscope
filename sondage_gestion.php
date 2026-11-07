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
            if ($action === 'create' || $action === 'update') {
                $cid = (int) ($_POST['commission_id'] ?? 0);
                $question = trim((string) ($_POST['question'] ?? ''));
                $description = trim((string) ($_POST['description'] ?? ''));
                $multiple = !empty($_POST['choix_multiple']) ? 1 : 0;
                $anonyme = !empty($_POST['anonyme']) ? 1 : 0;
                $visib = ($_POST['resultats_visibilite'] ?? '') === SONDAGE_VISIBILITE_BUREAU ? SONDAGE_VISIBILITE_BUREAU : SONDAGE_VISIBILITE_TOUS;
                $dateFin = trim((string) ($_POST['date_fin'] ?? ''));
                $publier = !empty($_POST['publier']);
                $sEdit = null;
                if ($action === 'update') { $sEdit = $chargerSondage((int) ($_POST['sondage_id'] ?? 0)); }
                $options = [];
                foreach ((array) ($_POST['options'] ?? []) as $o) {
                    $o = trim((string) $o);
                    if ($o !== '' && !in_array(mb_strtolower($o), array_map('mb_strtolower', $options), true)) { $options[] = mb_substr($o, 0, 200); }
                }
                if ($action === 'update' && (!$sEdit || $sEdit['statut'] !== 'brouillon')) {
                    $error = "Seul un brouillon peut être modifié.";
                } elseif (!in_array($cid, $idsCommissions, true)) {
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
                    if ($action === 'update') {
                        $sid = (int) $sEdit['id'];
                        $pdo->prepare("UPDATE sondages SET commission_id = ?, question = ?, description = ?, choix_multiple = ?, anonyme = ?, resultats_visibilite = ?, statut = ?, date_fin = ?, published_at = ? WHERE id = ?")
                            ->execute([$cid, mb_substr($question, 0, 255), $description !== '' ? $description : null, $multiple, $anonyme, $visib, $publier ? 'publie' : 'brouillon', $dateFin !== '' ? $dateFin : null, $publier ? date('Y-m-d H:i:s') : null, $sid]);
                        $pdo->prepare("DELETE FROM sondage_votes WHERE sondage_id = ?")->execute([$sid]);
                        $pdo->prepare("DELETE FROM sondage_options WHERE sondage_id = ?")->execute([$sid]);
                    } else {
                        $pdo->prepare("INSERT INTO sondages (commission_id, auteur_id, question, description, choix_multiple, anonyme, resultats_visibilite, statut, date_fin, published_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
                            ->execute([$cid, $membreId, mb_substr($question, 0, 255), $description !== '' ? $description : null, $multiple, $anonyme, $visib, $publier ? 'publie' : 'brouillon', $dateFin !== '' ? $dateFin : null, $publier ? date('Y-m-d H:i:s') : null]);
                        $sid = (int) $pdo->lastInsertId();
                    }
                    $ins = $pdo->prepare("INSERT INTO sondage_options (sondage_id, libelle, position) VALUES (?,?,?)");
                    foreach ($options as $pos => $lib) { $ins->execute([$sid, $lib, $pos]); }
                    if ($publier) {
                        $n = troba_notify_all_membres($pdo, 'sondage', '📊 Nouveau sondage', mb_substr($question, 0, 150), 'sondages.php#s' . $sid);
                        $success = "Sondage publié : $n membre(s) notifié(s).";
                    } else {
                        $success = $action === 'update' ? "Brouillon mis à jour." : "Sondage enregistré en brouillon. Publiez-le quand il est prêt.";
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

// Formulaire : préremplissage (erreur de saisie, ou modification d'un brouillon demandée par ?edit=ID)
$prefillForm = [];
$formOuvert = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create', 'update'], true) && $error !== '') {
    $prefillForm = $_POST;
    $prefillForm['edit_id'] = ($_POST['action'] ?? '') === 'update' ? (int) ($_POST['sondage_id'] ?? 0) : 0;
    $formOuvert = true;
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST' && (int) ($_GET['edit'] ?? 0) > 0) {
    $sE = $chargerSondage((int) $_GET['edit']);
    if ($sE && $sE['statut'] === 'brouillon') {
        $prefillForm = [
            'edit_id' => (int) $sE['id'], 'commission_id' => (int) $sE['commission_id'], 'question' => $sE['question'], 'description' => $sE['description'],
            'choix_multiple' => $sE['choix_multiple'], 'anonyme' => $sE['anonyme'], 'resultats_visibilite' => $sE['resultats_visibilite'], 'date_fin' => (string) ($sE['date_fin'] ?? ''),
            'options' => array_column(sondage_options($pdo, (int) $sE['id']), 'libelle'),
        ];
        $formOuvert = true;
    }
}

// Création demandée depuis la page « Sondages » : retour là-bas avec le message de réussite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['retour'] ?? '') === 'sondages' && $error === '' && $success !== '') {
    $_SESSION['sondage_flash'] = $success;
    header('Location: sondages.php');
    exit;
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
    if ($s['statut'] === 'brouillon') { $o .= '<a href="sondage_gestion.php?edit=' . $sid . '" class="btn btn-secondary btn-sm">✏️ Modifier</a>'; $o .= $btn('publish', '📣 Publier', 'btn-primary', 'Publier ce sondage et notifier tous les membres ?'); }
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
    <?php echo sondage_form_css(); ?>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="admin-content">
                <h1 class="admin-page-title">📊 Gérer les sondages</h1>
                <div class="sd-wrap">
                    <?php if ($success !== ''): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:8px;margin-bottom:1rem;"><?php echo $h($success); ?></div><?php endif; ?>
                    <?php if ($error !== ''): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:8px;margin-bottom:1rem;"><?php echo $h($error); ?></div><?php endif; ?>

                    <?php echo sondage_formulaire_creation($mesCommissions, $csrf, 'sondage_gestion.php', 'gestion', $prefillForm, $formOuvert); ?>

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
<script src="modern-date.js" defer></script>
</body>
</html>
