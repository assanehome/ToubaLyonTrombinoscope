<?php
/**
 * Touba Lyon 2026 - 📊 Sondage de date et d'horaire d'une réunion
 *
 * Les membres de la commission votent pour les dates proposées. Un responsable
 * (ou un admin) valide une date : elle devient la date de la réunion, qui peut
 * alors être publiée. Le lien de cette page est partagé par WhatsApp.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_date_longue()
require_once __DIR__ . '/reunion_helper.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/dahira_emails.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$estAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$membreId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;

// Connexion requise : retour automatique sur le sondage après la connexion
if ($membreId === 0 && !$estAdmin) {
    $_SESSION['after_login'] = 'reunion_sondage.php?id=' . $id;
    header('Location: login.php');
    exit;
}

$reunion = null;
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM commission_reunions WHERE id = ?");
        $st->execute([$id]);
        $reunion = $st->fetch();
    } catch (Exception $e) { $reunion = null; }
}
if (!$reunion) {
    header('Location: ' . ($estAdmin ? 'commission_dashboard.php' : 'index.php'));
    exit;
}
$commissionId = (int) $reunion['commission_id'];

// Accès : admin, responsables et membres de la commission
$estResp = $estAdmin;
$estMembre = $estAdmin;
if ($membreId > 0) {
    try {
        $q = $pdo->prepare("SELECT 1 FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ? LIMIT 1");
        $q->execute([$commissionId, $membreId]);
        if ($q->fetchColumn() !== false) { $estResp = true; $estMembre = true; }
        $q = $pdo->prepare("SELECT 1 FROM commission_membres WHERE commission_id = ? AND membre_id = ? LIMIT 1");
        $q->execute([$commissionId, $membreId]);
        if ($q->fetchColumn() !== false) { $estMembre = true; }
    } catch (Exception $e) {}
}
if (!$estMembre) {
    header('Location: index.php');
    exit;
}

$commissionNom = reunion_commission_nom($pdo, $commissionId);
$statut = (string) ($reunion['sondage_statut'] ?? 'aucun');
$error = '';
$success = $_SESSION['sond_flash'] ?? '';
unset($_SESSION['sond_flash']);

$options = [];
try {
    $q = $pdo->prepare("SELECT * FROM reunion_sondage_options WHERE reunion_id = ? ORDER BY date_option ASC, heure ASC, id ASC");
    $q->execute([$id]);
    $options = $q->fetchAll();
} catch (Exception $e) {}
$idsOptions = array_map(function ($o) { return (int) $o['id']; }, $options);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($statut !== 'ouvert') {
                $error = "Ce sondage est clos.";
            } elseif ($action === 'vote') {
                if ($membreId <= 0) {
                    $error = "Connectez-vous avec votre compte membre pour voter.";
                } else {
                    $choix = (array) ($_POST['vote'] ?? []);
                    $ins = $pdo->prepare("INSERT INTO reunion_sondage_votes (option_id, membre_id, reponse) VALUES (?,?,?) ON DUPLICATE KEY UPDATE reponse = VALUES(reponse), updated_at = NOW()");
                    $del = $pdo->prepare("DELETE FROM reunion_sondage_votes WHERE option_id = ? AND membre_id = ?");
                    foreach ($idsOptions as $oid) {
                        $v = (string) ($choix[$oid] ?? '');
                        if ($v === 'oui' || $v === 'non') { $ins->execute([$oid, $membreId, $v]); }
                        else { $del->execute([$oid, $membreId]); }
                    }
                    $_SESSION['sond_flash'] = "Votre vote a bien été enregistré. Jërëjëf !";
                    header('Location: reunion_sondage.php?id=' . $id);
                    exit;
                }
            } elseif ($action === 'valider') {
                $oid = (int) ($_POST['option_id'] ?? 0);
                if (!$estResp) {
                    $error = "Seul un responsable de la commission peut valider une date.";
                } elseif (!in_array($oid, $idsOptions, true)) {
                    $error = "Option introuvable.";
                } else {
                    $opt = null;
                    foreach ($options as $o) { if ((int) $o['id'] === $oid) { $opt = $o; break; } }
                    $pdo->prepare("UPDATE commission_reunions SET date_reunion = ?, heure = ?, heure_fin = ?, sondage_statut = 'clos', updated_at = NOW() WHERE id = ?")
                        ->execute([$opt['date_option'], $opt['heure'], ($opt['heure_fin'] ?: null), $id]);
                    // Prévenir les membres de la date retenue (la réunion reste à publier)
                    $dest = $pdo->prepare("SELECT DISTINCT m.id FROM membres m WHERE m.status = 'approved' AND m.id IN (SELECT membre_id FROM commission_membres WHERE commission_id = :c UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = :c2)");
                    $dest->execute([':c' => $commissionId, ':c2' => $commissionId]);
                    $dateFr = date('d/m/Y', strtotime((string) $opt['date_option']));
                    foreach ($dest->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                        troba_notify_membre($pdo, (int) $mid, 'reunion_sondage', '📅 Date retenue — ' . $commissionNom, 'La réunion « ' . (string) $reunion['titre'] . ' » aura lieu le ' . $dateFr . ' à ' . (string) $opt['heure'] . '.', 'reunion_sondage.php?id=' . $id);
                    }
                    $_SESSION['sond_flash'] = "Date validée : " . $dateFr . " à " . (string) $opt['heure'] . ". Vous pouvez maintenant publier la réunion.";
                    header('Location: reunion_sondage.php?id=' . $id);
                    exit;
                }
            } else {
                $error = "Action non reconnue.";
            }
        } catch (Exception $e) {
            error_log('Touba Lyon sondage : ' . $e->getMessage());
            $error = "Une erreur technique est survenue.";
        }
    }
}

// Votes : décompte, liste des votants et vote de l'utilisateur
$votes = [];      // [option_id] => ['oui' => [noms], 'non' => [noms]]
$mesVotes = [];
if (!empty($idsOptions)) {
    try {
        $ph = implode(',', array_fill(0, count($idsOptions), '?'));
        $q = $pdo->prepare("SELECT v.option_id, v.membre_id, v.reponse, m.prenom, m.nom FROM reunion_sondage_votes v LEFT JOIN membres m ON m.id = v.membre_id WHERE v.option_id IN ($ph) ORDER BY m.prenom, m.nom");
        $q->execute($idsOptions);
        foreach ($q as $r) {
            $nom = trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? ''));
            $votes[(int) $r['option_id']][$r['reponse'] === 'non' ? 'non' : 'oui'][] = $nom !== '' ? $nom : 'Membre';
            if ((int) $r['membre_id'] === $membreId) { $mesVotes[(int) $r['option_id']] = $r['reponse']; }
        }
    } catch (Exception $e) {}
}
$maxOui = 0;
foreach ($options as $o) { $maxOui = max($maxOui, count($votes[(int) $o['id']]['oui'] ?? [])); }

$lienSondage = dahira_base_url() . '/reunion_sondage.php?id=' . $id;
$waSondage = 'https://wa.me/?text=' . rawurlencode(reunion_message_sondage($reunion, $commissionNom, $options, $lienSondage));
$retenu = null;
if ($statut === 'clos') {
    foreach ($options as $o) {
        if ($o['date_option'] === $reunion['date_reunion'] && $o['heure'] === $reunion['heure']) { $retenu = (int) $o['id']; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📊 Sondage de date — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .sd-wrap { max-width: 760px; margin: 1.5rem auto; }
        .sd-card { border-radius: 16px; padding: 1.1rem 1.3rem; margin-bottom: 1rem; }
        .sd-opt { border: 1px solid var(--glass-border); border-radius: 14px; padding: 0.8rem 1rem; margin-bottom: 0.7rem; background: rgba(255,255,255,0.04); }
        .sd-opt.top { border-color: rgba(212,175,55,0.6); background: rgba(212,175,55,0.07); }
        .sd-opt.retenue { border-color: rgba(37,211,102,0.6); background: rgba(37,211,102,0.08); }
        .sd-date { color: var(--white); font-weight: 700; }
        .sd-date small { color: var(--text-muted); font-weight: 500; margin-left: 0.4rem; }
        .sd-count { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.25rem; }
        .sd-choix { display: flex; gap: 0.35rem; margin-top: 0.55rem; flex-wrap: wrap; }
        .sd-choix input { position: absolute; opacity: 0; pointer-events: none; }
        .sd-choix label { padding: 0.4rem 0.9rem; border-radius: 50px; border: 1px solid var(--glass-border); color: var(--text-muted); font-size: 0.85rem; font-weight: 600; cursor: pointer; user-select: none; }
        .sd-choix input:checked + label.l-oui { background: rgba(37,211,102,0.2); border-color: rgba(37,211,102,0.6); color: #7bd8a6; }
        .sd-choix input:checked + label.l-non { background: rgba(191,33,33,0.2); border-color: rgba(191,33,33,0.6); color: #fca5a5; }
        .sd-choix input:checked + label.l-nr { background: rgba(255,255,255,0.1); color: var(--white); }
        .sd-voters { color: var(--text-muted); font-size: 0.8rem; margin-top: 0.4rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($estAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="admin-content dashboard-main">
                <h1 class="admin-page-title">📊 Sondage de date</h1>
                <div class="sd-wrap">

                <div class="glass-card sd-card">
                    <h2 style="margin:0 0 0.3rem; color:var(--white); font-size:1.15rem;">📌 <?php echo $h($reunion['titre']); ?></h2>
                    <div style="color:var(--text-muted); font-size:0.88rem;">📋 <?php echo $h($commissionNom); ?> · <?php echo ($reunion['mode'] ?? '') === 'presentiel' ? '🏛️ Présentiel' : '💻 À distance'; ?></div>
                    <?php if (trim((string) ($reunion['description'] ?? '')) !== ''): ?>
                        <div style="margin-top:0.6rem; color:var(--white); font-size:0.9rem; white-space:pre-line;"><?php echo $h($reunion['description']); ?></div>
                    <?php endif; ?>
                    <div style="margin-top:0.7rem;">
                        <?php if ($statut === 'ouvert'): ?>
                            <span class="badge badge-pending">📊 Sondage en cours</span>
                        <?php elseif ($statut === 'clos'): ?>
                            <span class="badge badge-approved">✅ Date validée : <?php echo date('d/m/Y', strtotime($reunion['date_reunion'])); ?> à <?php echo $h($reunion['heure']); ?></span>
                        <?php else: ?>
                            <span class="badge">Pas de sondage pour cette réunion</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($statut === 'aucun' || empty($options)): ?>
                    <div class="glass-card sd-card"><p style="margin:0; color:var(--text-muted);">Aucun sondage n'est proposé pour cette réunion.</p></div>
                <?php else: ?>
                <form method="POST" action="reunion_sondage.php?id=<?php echo (int) $id; ?>" id="sd-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                    <input type="hidden" name="action" value="vote">
                    <div class="glass-card sd-card">
                        <h3 style="margin:0 0 0.8rem; color:var(--white); font-size:1rem;"><?php echo $statut === 'ouvert' ? 'Dates proposées — votez pour celles qui vous conviennent' : 'Résultats du sondage'; ?></h3>
                        <?php foreach ($options as $o):
                            $oid = (int) $o['id'];
                            $nbOui = count($votes[$oid]['oui'] ?? []);
                            $nbNon = count($votes[$oid]['non'] ?? []);
                            $classe = ($retenu === $oid) ? ' retenue' : (($statut === 'ouvert' && $maxOui > 0 && $nbOui === $maxOui) ? ' top' : '');
                            $hf = trim((string) ($o['heure_fin'] ?? ''));
                            $mon = $mesVotes[$oid] ?? '';
                        ?>
                        <div class="sd-opt<?php echo $classe; ?>">
                            <div class="sd-date">
                                📅 <?php echo $h(ucfirst(dahira_date_longue($o['date_option']))); ?>
                                <small>🕐 <?php echo $h($o['heure']); ?><?php echo $hf !== '' ? ' – ' . $h($hf) : ''; ?></small>
                                <?php if ($retenu === $oid): ?><span class="badge badge-approved" style="margin-left:0.4rem;">✅ Date retenue</span><?php endif; ?>
                            </div>
                            <div class="sd-count">✅ <?php echo $nbOui; ?> disponible(s) · ❌ <?php echo $nbNon; ?> indisponible(s)</div>
                            <?php if ($statut === 'ouvert'): ?>
                            <div class="sd-choix">
                                <input type="radio" id="v<?php echo $oid; ?>-o" name="vote[<?php echo $oid; ?>]" value="oui"<?php echo $mon === 'oui' ? ' checked' : ''; ?>><label class="l-oui" for="v<?php echo $oid; ?>-o">✅ Je suis disponible</label>
                                <input type="radio" id="v<?php echo $oid; ?>-n" name="vote[<?php echo $oid; ?>]" value="non"<?php echo $mon === 'non' ? ' checked' : ''; ?>><label class="l-non" for="v<?php echo $oid; ?>-n">❌ Indisponible</label>
                                <input type="radio" id="v<?php echo $oid; ?>-x" name="vote[<?php echo $oid; ?>]" value=""<?php echo $mon === '' ? ' checked' : ''; ?>><label class="l-nr" for="v<?php echo $oid; ?>-x">—</label>
                            </div>
                            <?php endif; ?>
                            <?php if ($estResp && ($nbOui + $nbNon) > 0): ?>
                            <details style="margin-top:0.5rem;">
                                <summary style="cursor:pointer; color:var(--gold); font-size:0.82rem; font-weight:600;">👥 Voir qui a voté</summary>
                                <?php if ($nbOui > 0): ?><div class="sd-voters">✅ <?php echo $h(implode(', ', $votes[$oid]['oui'])); ?></div><?php endif; ?>
                                <?php if ($nbNon > 0): ?><div class="sd-voters">❌ <?php echo $h(implode(', ', $votes[$oid]['non'])); ?></div><?php endif; ?>
                            </details>
                            <?php endif; ?>
                            <?php if ($estResp && $statut === 'ouvert'): ?>
                            <button type="button" class="btn btn-sm" style="margin-top:0.6rem; background:#1f9d55; border-color:#1f9d55; color:#fff;"
                                onclick="validerDate(<?php echo $oid; ?>, <?php echo $h(json_encode(date('d/m/Y', strtotime($o['date_option'])) . ' à ' . $o['heure'])); ?>)">✅ Valider cette date</button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($statut === 'ouvert' && $membreId > 0): ?>
                            <button type="submit" class="btn btn-primary" style="width:100%; margin-top:0.4rem;">💾 Enregistrer mon vote</button>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($estResp && $statut === 'ouvert'): ?>
                <form method="POST" action="reunion_sondage.php?id=<?php echo (int) $id; ?>" id="sd-valider" style="display:none;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                    <input type="hidden" name="action" value="valider">
                    <input type="hidden" name="option_id" id="sd-option-id" value="">
                </form>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($estResp): ?>
                <div class="glass-card sd-card" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                    <?php if ($statut === 'ouvert'): ?>
                        <a href="<?php echo $h($waSondage); ?>" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 Partager le sondage sur WhatsApp</a>
                    <?php endif; ?>
                    <a href="<?php echo $estAdmin ? 'commission_dashboard.php' : 'commission_dashboard.php'; ?>" class="btn btn-secondary btn-sm">← Espace commission</a>
                    <a href="reunion_detail.php?id=<?php echo (int) $id; ?>" class="btn btn-secondary btn-sm">📊 Détail de la réunion</a>
                </div>
                <?php endif; ?>

                </div>
            </div>
        </div>
    </main>

    <?php if ($success !== '' || $error !== ''): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header"><?php if ($success !== ''): ?><h3 class="gold-text">Réussi</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?></div>
            <div class="modal-body"><p><?php echo $h($success !== '' ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        // Valider une date : confirmation, puis envoi du formulaire caché
        function validerDate(optionId, libelle) {
            var go = function () {
                document.getElementById('sd-option-id').value = optionId;
                document.getElementById('sd-valider').submit();
            };
            if (window.modernConfirm) {
                window.modernConfirm('Valider le <strong>' + libelle + '</strong> comme date de la réunion ? Le sondage sera clos et les membres seront prévenus.', go, { bouton: 'Valider la date' });
            } else { go(); }
        }
    </script>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
</body>
</html>
