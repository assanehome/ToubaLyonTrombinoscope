<?php
/**
 * Touba Lyon 2026 - Demandes de logistique reçues (responsables de la commission Logistique).
 *
 * Liste les demandes des commissions, avec leur traitement : prise en compte, terminée ou refusée
 * (avec un message). Le demandeur est prévenu par notification (sans e-mail).
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/stock_logistique_helper.php';
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
$__isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$moi = (int) ($_SESSION['player_id'] ?? 0);
if (!$__isAdmin && ($moi <= 0 || !logistique_est_responsable($pdo, $moi))) {
    header('Location: ' . ($moi > 0 ? 'index.php' : 'login.php'));
    exit;
}
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
$error = '';
$success = $_SESSION['logi_flash'] ?? '';
unset($_SESSION['logi_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) { $error = "Échec de validation de sécurité. Veuillez réessayer."; }
    else {
        $id = (int) ($_POST['demande_id'] ?? 0);
        $statut = (string) ($_POST['statut'] ?? '');
        $reponse = trim((string) ($_POST['reponse'] ?? ''));
        if (!in_array($statut, ['prise_en_compte', 'executee', 'terminee', 'refusee'], true)) { $error = "Statut invalide."; }
        elseif ($statut === 'refusee' && $reponse === '') { $error = "Indiquez un motif pour refuser la demande."; }
        else {
            try {
                $q = $pdo->prepare("SELECT d.*, c.nom AS commission_nom, m.email AS dem_email, m.prenom AS dem_prenom, m.nom AS dem_nom
                                    FROM logistique_demandes d JOIN commissions c ON c.id = d.commission_id LEFT JOIN membres m ON m.id = d.demandeur_id WHERE d.id = ?");
                $q->execute([$id]);
                $d = $q->fetch();
                if (!$d) { $error = "Demande introuvable."; }
                else {
                    $pdo->prepare("UPDATE logistique_demandes SET statut = ?, reponse = ?, traite_par = ?, execute_le = IF(? = 'executee', NOW(), execute_le) WHERE id = ?")->execute([$statut, $reponse !== '' ? $reponse : null, $moi > 0 ? $moi : null, $statut, $id]);
                    $lib = logistique_statuts()[$statut];
                    if ((int) $d['demandeur_id'] > 0) {
                        troba_notify_membre($pdo, (int) $d['demandeur_id'], 'commission', '🚚 Demande de logistique : ' . strip_tags($lib), mb_substr($d['description'], 0, 80) . ($reponse !== '' ? ' — ' . mb_substr($reponse, 0, 80) : ''), 'logistique_demande.php');
                    }
                    $_SESSION['logi_flash'] = "Demande mise à jour : " . strip_tags($lib) . ". Le demandeur est prévenu.";
                    header('Location: logistique_demandes.php');
                    exit;
                }
            } catch (Exception $e) {
                error_log('Touba Lyon logistique traitement : ' . $e->getMessage());
                $error = "Une erreur technique est survenue.";
            }
        }
    }
}

$demandes = [];
try {
    $demandes = $pdo->query("SELECT d.*, c.nom AS commission_nom, m.prenom AS dem_prenom, m.nom AS dem_nom
                             FROM logistique_demandes d JOIN commissions c ON c.id = d.commission_id LEFT JOIN membres m ON m.id = d.demandeur_id
                             ORDER BY FIELD(d.statut,'nouvelle','prise_en_compte','executee','terminee','refusee'), d.id DESC LIMIT 200")->fetchAll();
} catch (Exception $e) {}
$groupes = ['nouvelle' => [], 'prise_en_compte' => [], 'cloturees' => []];
foreach ($demandes as $d) {
    if ($d['statut'] === 'nouvelle') { $groupes['nouvelle'][] = $d; }
    elseif ($d['statut'] === 'prise_en_compte') { $groupes['prise_en_compte'][] = $d; }
    else { $groupes['cloturees'][] = $d; }
}
$titres = ['nouvelle' => '🆕 Nouvelles demandes', 'prise_en_compte' => '👍 Prises en compte', 'cloturees' => '📁 Exécutées / refusées'];
$csrf = csrf_field();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📋 Demandes de logistique — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .lg-wrap { max-width: 860px; margin: 0 auto; }
        .lg-card { border: 1px solid rgba(212,175,55,0.38); border-radius: 12px; background: rgba(255,255,255,0.03); padding: 0.85rem 1rem; margin-bottom: 0.6rem; }
        .lg-top { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; }
        .lg-titre { color: var(--white); font-weight: 800; }
        .lg-meta { color: var(--text-muted); font-size: 0.83rem; margin-top: 0.3rem; line-height: 1.55; }
        .lg-act { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; margin-top: 0.6rem; }
        .lg-act input[type=text] { flex: 1 1 220px; min-width: 0; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">
            <div class="lg-wrap">
                <div class="admin-welcome-banner glass-card" style="margin-bottom:1rem; padding:1.1rem 1.5rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:0.8rem;">
                    <span>📋 Demandes de logistique — <strong class="gold-text"><?php echo count($demandes); ?></strong></span>
                </div>
                <?php if ($success !== ''): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($success); ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($error); ?></div><?php endif; ?>
                <?php if (empty($demandes)): ?><div class="empty-state"><div class="empty-state-icon">🚚</div><p>Aucune demande pour le moment.</p></div><?php endif; ?>

                <?php foreach ($titres as $k => $titre): if (empty($groupes[$k])) { continue; } ?>
                    <details class="glass-card"<?php echo $k !== 'cloturees' ? ' open' : ''; ?>>
                        <summary><?php echo $titre; ?> (<?php echo count($groupes[$k]); ?>)</summary>
                        <?php foreach ($groupes[$k] as $d): ?>
                            <div class="lg-card">
                                <div class="lg-top">
                                    <span class="lg-titre"><?php echo $h(logistique_types()[$d['type']] ?? $d['type']); ?> · <?php echo $h(logistique_vehicules()[$d['vehicule']] ?? $d['vehicule']); ?></span>
                                    <span class="badge"><?php echo $h(logistique_statuts()[$d['statut']] ?? $d['statut']); ?></span>
                                </div>
                                <div class="lg-meta">
                                    📋 <?php echo $h($d['commission_nom']); ?> · 👤 <?php echo $h(trim(($d['dem_prenom'] ?? '') . ' ' . ($d['dem_nom'] ?? '')) ?: 'Administrateur'); ?> · le <?php echo $h(date('d/m/Y', strtotime((string) $d['created_at']))); ?><br>
                                    <?php echo nl2br($h($d['description'])); ?><br>
                                    📍 <?php echo $h($d['depart'] === 'autre' ? $d['depart_autre'] : (logistique_departs()[$d['depart']] ?? $d['depart'])); ?> → <?php echo $h($d['destination']); ?>
                                    <?php if (!empty($d['date_besoin'])): ?> · 🗓️ <?php echo $h($d['date_besoin']); ?><?php endif; ?>
                                    <?php if (!empty($d['execute_le'])): ?><br>✅ Exécution validée le <?php echo $h(date('d/m/Y à H:i', strtotime((string) $d['execute_le']))); ?><?php endif; ?>
                                    <?php if (trim((string) ($d['reponse'] ?? '')) !== ''): ?><br>💬 <?php echo nl2br($h($d['reponse'])); ?><?php endif; ?>
                                </div>
                                <?php if ($d['statut'] === 'nouvelle' || $d['statut'] === 'prise_en_compte'): ?>
                                <form method="POST" action="logistique_demandes.php" class="lg-act">
                                    <?php echo $csrf; ?>
                                    <input type="hidden" name="demande_id" value="<?php echo (int) $d['id']; ?>">
                                    <input type="text" name="reponse" class="form-input" placeholder="Message au demandeur (facultatif, obligatoire pour refuser)" maxlength="500">
                                    <?php if ($d['statut'] === 'nouvelle'): ?><button type="submit" name="statut" value="prise_en_compte" class="btn btn-secondary btn-sm">👍 Prendre en compte</button><?php endif; ?>
                                    <button type="submit" name="statut" value="executee" class="btn btn-primary btn-sm">✅ Valider l'exécution</button>
                                    <button type="submit" name="statut" value="refusee" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;">🚫 Refuser</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </details>
                <?php endforeach; ?>
            </div>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/modern_popup.php'; ?>
</body>
</html>
