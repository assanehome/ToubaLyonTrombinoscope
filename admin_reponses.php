<?php
/**
 * Touba Lyon 2026 - Suivi intégration (format cartes)
 * L'admin assigne un intégrateur à chaque inscrit. Chaque intégrateur ne voit
 * que les inscrits qui lui sont assignés et complète le souhait de commission
 * et la présentation.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/send_mail.php';
require_once __DIR__ . '/contact.php';
require_once __DIR__ . '/dahira_emails.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Accès : administrateurs OU membres ayant le rôle « intégrateur ».
$isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isIntegrateur = false;
$integId = 0;
$integrateurNom = '';
if (!$isAdmin && !empty($_SESSION['player_id'])) {
    try {
        $stmtRole = $pdo->prepare("SELECT is_integrateur, prenom, nom FROM membres WHERE id = ?");
        $stmtRole->execute([(int)$_SESSION['player_id']]);
        $me = $stmtRole->fetch();
        if ($me && !empty($me['is_integrateur'])) {
            $isIntegrateur = true;
            $integId = (int)$_SESSION['player_id'];
            $integrateurNom = trim($me['prenom'] . ' ' . $me['nom']);
        }
    } catch (Exception $e) {
        // ignore : traité comme non-intégrateur
    }
}
// Responsable de la commission « Intégration » : accès complet au suivi (comme admin).
$isSuiviManager = false;
if (!$isAdmin && !empty($_SESSION['player_id'])) {
    try {
        $isSuiviManager = ((int) $pdo->query("SELECT COUNT(*) FROM commission_gestionnaires cg JOIN commissions c ON c.id = cg.commission_id WHERE cg.membre_id = " . (int) $_SESSION['player_id'] . " AND LOWER(c.nom) LIKE '%gration%'")->fetchColumn() > 0);
    } catch (Exception $e) {
        $isSuiviManager = false;
    }
    $_SESSION['is_suivi_integration'] = $isSuiviManager;
    if ($isSuiviManager && $integrateurNom === '') {
        $integrateurNom = trim($_SESSION['player_name'] ?? '');
    }
}
$canAll = $isAdmin || $isSuiviManager;
// Accès : administrateurs, responsables de la commission « Intégration » (portée complète),
// et intégrateurs (portée limitée à leurs inscrits assignés).
if (!$isAdmin && !$isSuiviManager && !$isIntegrateur) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (($_POST['ajax'] ?? '') === '1');
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } elseif (($_POST['action'] ?? '') === 'grant_integrateur' && $canAll) {
        // Donner le rôle intégrateur à un membre (admin ou responsable Intégration).
        $rid = intval($_POST['role_member_id'] ?? 0);
        if ($rid > 0) {
            try {
                $stmtM = $pdo->prepare("SELECT prenom, nom, email FROM membres WHERE id = ? AND status = 'approved'");
                $stmtM->execute([$rid]);
                $rm = $stmtM->fetch();
                if (!$rm) {
                    $error = "Membre introuvable.";
                } else {
                    $pdo->prepare("UPDATE membres SET is_integrateur = 1 WHERE id = ?")->execute([$rid]);
                    if (!empty($rm['email'])) { @send_role_notification($rm['email'], $rm['prenom'] . ' ' . $rm['nom'], 'integrateur'); }
                    $success = "Rôle intégrateur attribué à " . htmlspecialchars($rm['prenom'] . ' ' . $rm['nom']) . ".";
                }
            } catch (Exception $e) {
                error_log('Touba Lyon admin_reponses (grant integ): ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    } elseif (($_POST['action'] ?? '') === 'revoke_integrateur' && $canAll) {
        $rid = intval($_POST['role_member_id'] ?? 0);
        if ($rid > 0) {
            try {
                $pdo->prepare("UPDATE membres SET is_integrateur = 0 WHERE id = ?")->execute([$rid]);
                $pdo->prepare("UPDATE membres SET integrateur_id = NULL WHERE integrateur_id = ?")->execute([$rid]);
                $success = "Rôle intégrateur retiré.";
            } catch (Exception $e) {
                error_log('Touba Lyon admin_reponses (revoke integ): ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    } elseif (($_POST['action'] ?? '') === 'send_mail') {
        // Envoi d'un e-mail au membre (admin = tous, intégrateur = ses assignés)
        $mid = intval($_POST['member_id'] ?? 0);
        $subject = trim($_POST['mail_subject'] ?? '');
        $body = trim($_POST['mail_body'] ?? '');
        if ($mid <= 0 || $subject === '' || $body === '') {
            $error = "Objet et message sont obligatoires.";
        } else {
            try {
                $q = "SELECT prenom, nom, email, status FROM membres WHERE id = ?";
                $params = [$mid];
                if (!$canAll) { $q .= " AND integrateur_id = ?"; $params[] = $integId; }
                $stmt = $pdo->prepare($q);
                $stmt->execute($params);
                $target = $stmt->fetch();
                if (!$target) {
                    $error = "Membre introuvable ou non autorisé.";
                } elseif ($target['status'] === 'approved') {
                    $error = "Ce compte est validé : l'envoi de message n'est pas disponible.";
                } else {
                    $senderName = $isAdmin ? 'Le Secrétariat du Dahira' : ($integrateurNom !== '' ? $integrateurNom : 'Votre intégrateur');
                    $senderRole = $isAdmin ? 'Secrétariat Général' : 'Intégrateur en charge de votre suivi';
                    $htmlBody = dahira_email_wrap(
                        '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Message concernant votre intégration</h1>'
                        . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($target['prenom'] . ' ' . $target['nom']) . '</strong>,</p>'
                        . '<p style="margin:0 0 16px;">Dans le cadre de votre <strong>intégration au Dahira Touba Lyon (MUBAWWA-A-ASIDQIN)</strong>, '
                        . htmlspecialchars($senderName) . ' souhaite vous transmettre le message suivant :</p>'
                        . '<div style="white-space:pre-wrap;background:#f6f7f6;border-left:4px solid #1b4332;border-radius:10px;padding:14px 18px;margin:0 0 18px;">' . nl2br(htmlspecialchars($body)) . '</div>'
                        . '<p style="margin:0 0 6px;">Pour toute question, vous pouvez répondre à cet e-mail ou vous rapprocher de votre intégrateur ou du secrétariat.</p>'
                        . '<p style="margin:18px 0 0;font-size:14px;color:#555;">Bien à vous,<br><strong style="color:#1b4332;">' . htmlspecialchars($senderName) . '</strong><br>' . htmlspecialchars($senderRole) . ' — Dahira Touba Lyon</p>',
                        'Suivi intégration'
                    );
                    $sent = @send_smtp_mail($target['email'], $target['prenom'] . ' ' . $target['nom'], $subject, $htmlBody);
                    $success = $sent ? "E-mail envoyé à " . htmlspecialchars($target['prenom'] . ' ' . $target['nom']) . "." : "L'e-mail n'a pas pu être envoyé. Réessayez plus tard.";
                }
            } catch (Exception $e) {
                error_log('Touba Lyon admin_reponses (mail): ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    } else {
        $id = intval($_POST['member_id'] ?? 0);
        $souhait = trim($_POST['souhait_commission'] ?? '');
        $presentation = trim($_POST['presentation_ok'] ?? '');
        if (!in_array($presentation, ['', 'OK', 'Non OK'], true)) { $presentation = ''; }
        $test_kourel = trim($_POST['test_kourel'] ?? '');
        if (!in_array($test_kourel, ['', 'Oui', 'Non'], true)) { $test_kourel = ''; }
        $commentaires = trim($_POST['commentaires'] ?? '');
        $souhaitVal = ($souhait !== '' ? $souhait : null);
        $presVal = ($presentation !== '' ? $presentation : null);
        $testVal = ($test_kourel !== '' ? $test_kourel : null);
        $commVal = ($commentaires !== '' ? $commentaires : null);
        if ($id > 0) {
            try {
                // Un compte validé (approved) n'est plus modifiable ici.
                $chk = $pdo->prepare("SELECT status FROM membres WHERE id = ?");
                $chk->execute([$id]);
                $memberStatus = $chk->fetchColumn();
                if ($memberStatus === 'approved') {
                    $error = "Ce compte est validé : le suivi n'est plus modifiable.";
                } else {
                    if ($canAll) {
                        $integrateur_id = intval($_POST['integrateur_id'] ?? 0);
                        $integrateur_id = $integrateur_id > 0 ? $integrateur_id : null;
                        $stmt = $pdo->prepare("UPDATE membres SET integrateur_id = ?, souhait_commission = ?, presentation_ok = ?, test_kourel = ?, commentaires = ? WHERE id = ? AND type_adhesion IS NOT NULL AND status <> 'approved'");
                        $stmt->execute([$integrateur_id, $souhaitVal, $presVal, $testVal, $commVal, $id]);
                    } else {
                        // Intégrateur : ne peut modifier que ses propres inscrits (pas l'assignation)
                        $stmt = $pdo->prepare("UPDATE membres SET souhait_commission = ?, presentation_ok = ?, test_kourel = ?, commentaires = ? WHERE id = ? AND type_adhesion IS NOT NULL AND status <> 'approved' AND integrateur_id = ?");
                        $stmt->execute([$souhaitVal, $presVal, $testVal, $commVal, $id, $integId]);
                    }
                    $success = "Informations enregistrées.";
                }
            } catch (Exception $e) {
                error_log('Touba Lyon admin_reponses: ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
    // Réponse légère pour les requêtes AJAX (attribution de rôle sans rechargement)
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => empty($error), 'error' => $error]);
        exit;
    }
}

try {
    $sql = "SELECT m.*, TRIM(CONCAT(COALESCE(i.prenom,''), ' ', i.nom)) AS integrateur_nom
            FROM membres m
            LEFT JOIN membres i ON m.integrateur_id = i.id
            WHERE m.type_adhesion IS NOT NULL";
    if ($isIntegrateur && !$canAll) {
        $sql .= " AND m.integrateur_id = " . $integId;
    }
    $sql .= " ORDER BY m.created_at ASC";
    $rows = $pdo->query($sql)->fetchAll();

    // Liste des membres ayant le rôle intégrateur (pour l'assignation par l'admin)
    $integrateurs = $canAll ? $pdo->query("SELECT id, TRIM(CONCAT(COALESCE(prenom,''), ' ', nom)) AS nom FROM membres WHERE is_integrateur = 1 ORDER BY nom ASC")->fetchAll() : [];
    // Liste des membres validés pour l'attribution du rôle intégrateur (responsable Intégration / admin)
    $roleMembers = $canAll ? $pdo->query("SELECT id, prenom, nom, photo_path, is_integrateur FROM membres WHERE status = 'approved' ORDER BY is_integrateur DESC, nom ASC")->fetchAll() : [];
    $commissions = $pdo->query("SELECT nom FROM commissions ORDER BY nom ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log('Touba Lyon admin_reponses (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES); }

// Ensembles de valeurs distinctes pour alimenter les filtres (dont infos de suivi).
$fCommissions=[]; $fSecteurs=[]; $fTypes=[]; $fStatuts=[]; $fGenres=[]; $fAnnees=[]; $fInteg=[]; $fPres=[]; $fTest=[];
foreach ($rows as $rr) {
    if (!empty($rr['souhait_commission'])) { $fCommissions[$rr['souhait_commission']] = true; }
    if (!empty($rr['secteur_activite']))   { $fSecteurs[$rr['secteur_activite']] = true; }
    if (!empty($rr['type_adhesion']))      { $fTypes[$rr['type_adhesion']] = true; }
    if (!empty($rr['statut']))             { $fStatuts[$rr['statut']] = true; }
    if (!empty($rr['genre']))              { $fGenres[$rr['genre']] = true; }
    if (!empty($rr['annee_integration']))  { $fAnnees[$rr['annee_integration']] = true; }
    if (!empty($rr['integrateur_nom']))    { $fInteg[$rr['integrateur_nom']] = true; }
    if (!empty($rr['presentation_ok']))    { $fPres[$rr['presentation_ok']] = true; }
    if (!empty($rr['test_kourel']))        { $fTest[$rr['test_kourel']] = true; }
}
$fCommissions=array_keys($fCommissions); sort($fCommissions);
$fSecteurs=array_keys($fSecteurs); sort($fSecteurs);
$fTypes=array_keys($fTypes); sort($fTypes);
$fStatuts=array_keys($fStatuts); sort($fStatuts);
$fGenres=array_keys($fGenres); sort($fGenres);
$fAnnees=array_keys($fAnnees); rsort($fAnnees);
$fInteg=array_keys($fInteg); sort($fInteg);
$fPres=array_keys($fPres); sort($fPres);
$fTest=array_keys($fTest); sort($fTest);

function filter_select_rep($id, $label, $options) {
    $hh = '<select id="' . $id . '" class="adh-select"><option value="">' . htmlspecialchars($label) . ' : tous</option>';
    foreach ($options as $o) { $hh .= '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '">' . htmlspecialchars($o) . '</option>'; }
    return $hh . '</select>';
}

function render_rep_card($r, $isAdmin, $integrateurs, $commissions) {
    $locked = ($r['status'] === 'approved');
    $fullName = trim($r['prenom'] . ' ' . $r['nom']);
    $wa = wa_number($r['telephone'] ?? '');
    $waLink = $wa ? 'https://wa.me/' . $wa . '?text=' . rawurlencode('Assalamu aleykum ' . $r['prenom'] . ', ') : '';
    ?>
    <div class="rep-card glass-card<?php echo $locked ? ' rep-locked' : ''; ?>"
        data-search="<?php echo h(mb_strtolower($r['prenom'] . ' ' . $r['nom'] . ' ' . $r['email'])); ?>"
        data-commission="<?php echo h($r['souhait_commission'] ?? ''); ?>"
        data-secteur="<?php echo h($r['secteur_activite'] ?? ''); ?>"
        data-type="<?php echo h($r['type_adhesion'] ?? ''); ?>"
        data-statut="<?php echo h($r['statut'] ?? ''); ?>"
        data-genre="<?php echo h($r['genre'] ?? ''); ?>"
        data-annee="<?php echo h($r['annee_integration'] ?? ''); ?>"
        data-integrateur="<?php echo h($r['integrateur_nom'] ?? ''); ?>"
        data-presentation="<?php echo h($r['presentation_ok'] ?? ''); ?>"
        data-testkourel="<?php echo h($r['test_kourel'] ?? ''); ?>">
        <div class="rep-head">
            <div class="rep-photo">
                <?php if (!empty($r['photo_path'])): ?>
                    <img src="uploads/<?php echo h($r['photo_path']); ?>" alt="">
                <?php else: ?>
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?php endif; ?>
            </div>
            <div>
                <div class="rep-name"><?php echo h($r['prenom']); ?> <span style="text-transform:uppercase;"><?php echo h($r['nom']); ?></span></div>
                <div class="rep-sub">
                    <?php echo h($r['type_adhesion']); ?> · <?php echo $r['created_at'] ? date('d/m/Y', strtotime($r['created_at'])) : ''; ?>
                    <?php if ($locked): ?><span class="rep-badge-valide">✅ Validé</span><?php endif; ?>
                </div>
            </div>
            <button type="button" class="rep-toggle" onclick="this.closest('.rep-card').classList.toggle('details-open')">Détails <span class="chev">▾</span></button>
        </div>

        <div class="rep-info">
            <div><span class="lbl">Genre</span><span class="val"><?php echo h($r['genre']) ?: '—'; ?></span></div>
            <div><span class="lbl">Test Kourel</span><span class="val"><?php echo h($r['test_kourel']) ?: '—'; ?></span></div>
            <div class="full"><span class="lbl">Email</span><span class="val"><?php echo h($r['email']); ?></span></div>
            <div><span class="lbl">Téléphone</span><span class="val"><?php echo h($r['telephone']) ?: '—'; ?></span></div>
            <div><span class="lbl">Commune</span><span class="val"><?php echo h($r['commune']) ?: '—'; ?></span></div>
            <div><span class="lbl">Statut</span><span class="val"><?php echo h($r['statut']) ?: '—'; ?></span></div>
            <div><span class="lbl">Année d'intég.</span><span class="val"><?php echo h($r['annee_integration']) ?: '—'; ?></span></div>
            <div class="full"><span class="lbl">Secteur d'activité</span><span class="val"><?php echo h($r['secteur_activite']) ?: '—'; ?></span></div>
            <div class="full"><span class="lbl">Profession</span><span class="val"><?php echo h($r['profession']) ?: '—'; ?></span></div>
        </div>

        <?php if ($locked): ?>
            <!-- Compte validé : affichage en lecture seule, sans champ modifiable -->
            <div class="rep-readonly">
                <div><span class="lbl">Intégrateur en charge</span><span class="val"><?php echo h($r['integrateur_nom']) ?: '—'; ?></span></div>
                <div><span class="lbl">Souhait commission</span><span class="val"><?php echo h($r['souhait_commission']) ?: '—'; ?></span></div>
                <div><span class="lbl">Présentation</span><span class="val"><?php echo h($r['presentation_ok']) ?: '—'; ?></span></div>
                <div><span class="lbl">Test Kourel</span><span class="val"><?php echo h($r['test_kourel']) ?: '—'; ?></span></div>
                <?php if (!empty($r['commentaires'])): ?>
                    <div class="full"><span class="lbl">Commentaires</span><span class="val"><?php echo nl2br(h($r['commentaires'])); ?></span></div>
                <?php endif; ?>
            </div>
            <div class="rep-actions-row">
                <span class="rep-lock-note">🔒 Compte validé — suivi non modifiable</span>
                <?php if ($isAdmin): ?>
                    <span class="rep-actions-btns">
                        <a href="membre.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">Fiche</a>
                    </span>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form method="POST" action="admin_reponses.php" class="rep-edit">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="member_id" value="<?php echo (int)$r['id']; ?>">
                <h4>À compléter</h4>

                <?php if ($isAdmin): ?>
                    <div class="rep-field">
                        <label>Intégrateur en charge (assignation)</label>
                        <select name="integrateur_id">
                            <option value="">— Non assigné —</option>
                            <?php foreach ($integrateurs as $it): ?>
                                <option value="<?php echo (int)$it['id']; ?>" <?php echo ((int)$r['integrateur_id'] === (int)$it['id']) ? 'selected' : ''; ?>><?php echo h($it['nom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="rep-assign">
                        <span class="lbl">Intégrateur en charge</span>
                        <?php echo h($r['integrateur_nom']) ?: 'Vous'; ?>
                    </div>
                <?php endif; ?>

                <div class="rep-field amber">
                    <label>Souhait commission</label>
                    <select name="souhait_commission">
                        <option value="">— Aucune —</option>
                        <?php
                            $current = $r['souhait_commission'];
                            $found = false;
                            foreach ($commissions as $cName) {
                                $sel = ($current === $cName) ? 'selected' : '';
                                if ($sel) { $found = true; }
                                echo '<option value="' . h($cName) . '" ' . $sel . '>' . h($cName) . '</option>';
                            }
                            if (!$found && $current !== null && $current !== '') {
                                echo '<option value="' . h($current) . '" selected>' . h($current) . ' (ancienne valeur)</option>';
                            }
                        ?>
                    </select>
                </div>

                <div class="rep-field cyan">
                    <label>Présentation Ok / non OK</label>
                    <select name="presentation_ok">
                        <option value="">—</option>
                        <option value="OK" <?php echo $r['presentation_ok'] === 'OK' ? 'selected' : ''; ?>>OK</option>
                        <option value="Non OK" <?php echo $r['presentation_ok'] === 'Non OK' ? 'selected' : ''; ?>>Non OK</option>
                    </select>
                </div>

                <div class="rep-field">
                    <label>Test Kourel</label>
                    <select name="test_kourel">
                        <option value="">—</option>
                        <option value="Oui" <?php echo $r['test_kourel'] === 'Oui' ? 'selected' : ''; ?>>Oui</option>
                        <option value="Non" <?php echo $r['test_kourel'] === 'Non' ? 'selected' : ''; ?>>Non</option>
                    </select>
                </div>

                <div class="rep-field red" style="flex:1 0 100%;">
                    <label>Commentaires</label>
                    <textarea name="commentaires" rows="2" placeholder="Commentaire…"><?php echo h($r['commentaires']); ?></textarea>
                </div>

                <div class="rep-save">
                    <button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer</button>
                    <button type="button" class="btn btn-secondary btn-sm rep-btn-mail" data-mid="<?php echo (int)$r['id']; ?>" data-name="<?php echo h($fullName); ?>" onclick="openMailModal(this)">✉️ Mail</button>
                    <?php if ($waLink): ?><a href="<?php echo h($waLink); ?>" target="_blank" rel="noopener" class="btn btn-sm rep-btn-wa">🟢 WhatsApp</a><?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <a href="membre.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">Fiche</a>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi intégration - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .rep-search { margin:1.5rem 0 0.85rem; }
        .rep-search input { width:100%; padding:0.7rem 1.1rem; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); border-radius:50px; color:var(--white); font-size:0.95rem; }
        .rep-search input:focus { outline:none; border-color:var(--accent); }
        .adh-toggle { background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--white); border-radius:50px; padding:0.45rem 1rem; font-size:0.85rem; font-weight:600; cursor:pointer; margin-bottom:0.85rem; display:inline-flex; align-items:center; gap:0.4rem; }
        .adh-toggle:hover { border-color:var(--accent); }
        .adh-toggle .chev { transition:transform 0.2s ease; }
        .adh-toggle.open .chev { transform:rotate(180deg); }
        .adh-filters-adv { display:flex; flex-wrap:wrap; gap:0.6rem; margin:0 0 1rem; align-items:center; }
        .adh-filters-adv.is-hidden { display:none; }
        .adh-select { flex:1 1 150px; min-width:140px; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); border-radius:10px; color:var(--white); font-size:0.85rem; padding:0.5rem 0.75rem; color-scheme:dark; }
        .adh-select option { background-color:#0c241a; color:#fff; }
        .adh-select:focus { outline:none; border-color:var(--accent); }
        #adh-reset { background:transparent; border:1px solid var(--glass-border); color:var(--text-muted); border-radius:50px; padding:0.5rem 1rem; font-size:0.82rem; font-weight:600; cursor:pointer; }
        #adh-reset:hover { border-color:var(--danger); color:var(--danger); }
        .adh-count { font-size:0.82rem; color:var(--text-muted); margin-left:auto; }
        @media (max-width:600px){ .adh-select { flex:1 1 100%; } }
        .rep-grid { display:grid; grid-template-columns:1fr; gap:0.85rem; }
        .rep-card { padding:0.9rem 1.1rem; display:flex; flex-direction:column; gap:0.55rem; }
        .rep-head { display:flex; align-items:center; gap:0.75rem; }
        .rep-photo { width:42px; height:42px; border-radius:50%; overflow:hidden; border:2px solid var(--accent); flex-shrink:0; background:rgba(255,255,255,0.05); display:flex; align-items:center; justify-content:center; }
        .rep-photo img { width:100%; height:100%; object-fit:cover; }
        .rep-name { font-size:1rem; font-weight:700; color:var(--white); text-transform:capitalize; line-height:1.2; }
        .rep-sub { font-size:0.76rem; color:var(--text-muted); }
        .rep-toggle { margin-left:auto; flex-shrink:0; display:flex; align-items:center; gap:0.35rem; background:rgba(255,255,255,0.06); border:1px solid var(--glass-border); color:var(--text-muted); border-radius:8px; padding:0.35rem 0.7rem; font-size:0.78rem; cursor:pointer; }
        .rep-toggle:hover { border-color:var(--accent); color:var(--white); }
        .rep-toggle .chev { transition:transform 0.2s ease; }
        .rep-card.details-open .rep-toggle .chev { transform:rotate(180deg); }
        .rep-info { display:none; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:0.25rem 1.25rem; font-size:0.8rem; }
        .rep-card.details-open .rep-info { display:grid; }
        .rep-info .lbl { color:var(--text-muted); display:block; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.02em; }
        .rep-info .val { color:var(--white); word-break:break-word; }
        .rep-info .full { grid-column:1 / -1; }
        .rep-comment { background:rgba(255,0,0,0.08); border:1px solid rgba(255,0,0,0.35); border-radius:8px; padding:0.45rem 0.7rem; font-size:0.8rem; }
        .rep-comment .lbl { color:#ff9a9a; font-size:0.68rem; text-transform:uppercase; display:block; margin-bottom:0.1rem; }
        .rep-edit { border-top:1px dashed var(--glass-border); padding-top:0.7rem; display:flex; flex-wrap:wrap; align-items:flex-end; gap:0.6rem; }
        .rep-edit h4 { flex:1 0 100%; font-size:0.72rem; color:var(--gold); text-transform:uppercase; letter-spacing:0.04em; margin:0; }
        .rep-field { flex:1 1 150px; }
        .rep-field label { display:block; font-size:0.72rem; color:var(--text-muted); margin-bottom:0.2rem; }
        .rep-field input, .rep-field select { width:100%; padding:0.5rem 0.65rem; background:rgba(255,255,255,0.06); border:1px solid var(--glass-border); border-radius:8px; color:var(--white); font-size:0.85rem; color-scheme:dark; }
        .rep-field option { background-color:#0c241a; color:#ffffff; }
        .rep-field input:focus, .rep-field select:focus { outline:none; border-color:var(--accent); }
        .rep-field.amber label { color:#FBBC04; } .rep-field.amber select { border-left:3px solid #FBBC04; }
        .rep-field.cyan label { color:#00FFFF; } .rep-field.cyan select { border-left:3px solid #00FFFF; }
        .rep-field.red label { color:#ff9a9a; } .rep-field.red textarea { border-left:3px solid #ff0000; }
        .rep-field textarea { width:100%; padding:0.5rem 0.65rem; background:rgba(255,255,255,0.06); border:1px solid var(--glass-border); border-radius:8px; color:var(--white); font-size:0.85rem; font-family:inherit; resize:vertical; }
        .rep-field textarea:focus { outline:none; border-color:var(--accent); }
        .rep-assign { flex:1 1 150px; background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.3); border-radius:8px; padding:0.4rem 0.65rem; font-size:0.82rem; }
        .rep-assign .lbl { color:var(--gold); font-size:0.68rem; text-transform:uppercase; display:block; }
        .rep-save { flex:0 0 auto; display:flex; gap:0.5rem; align-items:flex-end; }
        .rep-badge-valide { display:inline-block; margin-left:0.4rem; background:rgba(45,106,79,0.25); color:#b7e4c7; border:1px solid rgba(45,106,79,0.5); border-radius:50px; padding:0.05rem 0.55rem; font-size:0.68rem; font-weight:700; }
        .rep-lock-note { font-size:0.8rem; color:#b7e4c7; font-weight:600; display:inline-flex; align-items:center; }
        .rep-card.rep-locked .rep-edit { opacity:0.75; }
        .rep-card.rep-locked select[disabled], .rep-card.rep-locked textarea[disabled] { opacity:0.7; cursor:not-allowed; }
        .rep-group-title { display:flex; align-items:center; gap:0.6rem; margin:1.5rem 0 0.75rem; font-size:0.9rem; font-weight:700; color:var(--gold); text-transform:uppercase; letter-spacing:0.03em; }
        .rep-group-title--valide { color:#b7e4c7; }
        .rep-group-count { background:rgba(212,175,55,0.15); color:var(--gold); border:1px solid rgba(212,175,55,0.35); border-radius:50px; padding:0.05rem 0.6rem; font-size:0.75rem; }
        .rep-group-title--valide .rep-group-count { background:rgba(45,106,79,0.25); color:#b7e4c7; border-color:rgba(45,106,79,0.5); }
        .rep-group[data-group="pending"] .rep-card { border-left:3px solid rgba(251,188,4,0.5); }
        .rep-group[data-group="approved"] .rep-card { border-left:3px solid rgba(45,106,79,0.5); }
        .rep-readonly { border-top:1px dashed var(--glass-border); padding-top:0.7rem; display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:0.4rem 1.25rem; font-size:0.82rem; }
        .rep-readonly .lbl { color:var(--text-muted); display:block; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.02em; }
        .rep-readonly .val { color:var(--white); word-break:break-word; font-weight:600; }
        .rep-readonly .full { grid-column:1 / -1; }
        .rep-actions-row { display:flex; align-items:center; justify-content:space-between; gap:0.75rem; flex-wrap:wrap; margin-top:0.2rem; }
        .rep-actions-btns { display:flex; gap:0.5rem; flex-wrap:wrap; }
        .rep-btn-wa { background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700; box-shadow:none; }
        .rep-btn-wa:hover { background:#1ebe5a; }
        /* ── Optimisation mobile : un champ / un bouton par ligne ── */
        @media (max-width: 600px) {
            .rep-card { padding:0.85rem 0.9rem; }
            .rep-edit { gap:0.8rem; }
            .rep-field, .rep-assign { flex:1 0 100%; }
            .rep-info { grid-template-columns:1fr; }
            .rep-readonly { grid-template-columns:1fr; }
            .rep-save { flex:1 0 100%; flex-direction:column; align-items:stretch; gap:0.55rem; }
            .rep-save > .btn,
            .rep-save > .rep-btn-wa,
            .rep-save > a { width:100%; justify-content:center; }
            .rep-actions-row { flex-direction:column; align-items:stretch; gap:0.6rem; }
            .rep-actions-btns { flex-direction:column; gap:0.55rem; }
            .rep-actions-btns > .btn,
            .rep-actions-btns > a { width:100%; justify-content:center; }
            .rep-head { flex-wrap:wrap; }
            .rep-toggle { margin-left:auto; }
        }
        /* ── Modale e-mail (UI moderne) ── */
        .mailx-overlay { align-items:center; justify-content:center; }
        .mailx-card { width:100%; max-width:520px; background:linear-gradient(180deg,#123528 0%, #0c241a 100%); border:1px solid rgba(212,175,55,0.25); border-radius:22px; overflow:hidden; box-shadow:0 30px 80px rgba(0,0,0,0.55); transform:translateY(14px) scale(0.98); opacity:0; transition:transform .28s cubic-bezier(.2,.8,.2,1), opacity .28s ease; }
        .modal-overlay.active .mailx-card { transform:translateY(0) scale(1); opacity:1; }
        .mailx-header { display:flex; align-items:center; gap:0.9rem; padding:1.4rem 1.5rem; background:linear-gradient(135deg,#1b4332 0%, #2d6a4f 100%); border-bottom:1px solid rgba(212,175,55,0.25); position:relative; }
        .mailx-header-icon { width:44px; height:44px; border-radius:14px; background:rgba(212,175,55,0.18); color:#f2d574; display:flex; align-items:center; justify-content:center; flex-shrink:0; border:1px solid rgba(212,175,55,0.35); }
        .mailx-title { font-size:1.15rem; font-weight:700; color:#fff; margin:0; }
        .mailx-subtitle { font-size:0.8rem; color:#b7d4c5; margin:0.1rem 0 0; }
        .mailx-close { position:absolute; top:0.85rem; right:1rem; background:rgba(255,255,255,0.1); border:none; color:#fff; width:30px; height:30px; border-radius:50%; font-size:1.2rem; line-height:1; cursor:pointer; transition:background .2s ease; }
        .mailx-close:hover { background:rgba(255,255,255,0.25); }
        .mailx-body { padding:1.4rem 1.5rem 0.5rem; }
        .mailx-recipient { display:flex; align-items:center; gap:0.6rem; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); border-radius:12px; padding:0.55rem 0.75rem; margin-bottom:1.1rem; }
        .mailx-recipient-label { font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; }
        .mailx-avatar { width:30px; height:30px; border-radius:50%; background:linear-gradient(135deg,#d4af37,#b8902f); color:#0c241a; font-weight:800; font-size:0.8rem; display:flex; align-items:center; justify-content:center; text-transform:uppercase; flex-shrink:0; }
        .mailx-recipient-name { color:#fff; font-weight:600; font-size:0.95rem; }
        .mailx-field { margin-bottom:1.1rem; }
        .mailx-field label { display:block; font-size:0.75rem; color:#f2d574; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; margin-bottom:0.4rem; }
        .mailx-field input, .mailx-field textarea { width:100%; padding:0.8rem 1rem; background:rgba(255,255,255,0.06); border:1px solid var(--glass-border); border-radius:12px; color:#fff; font-size:0.95rem; font-family:inherit; transition:border-color .2s ease, box-shadow .2s ease; }
        .mailx-field textarea { resize:vertical; min-height:120px; line-height:1.5; }
        .mailx-field input:focus, .mailx-field textarea:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(212,175,55,0.15); }
        .mailx-hint { display:block; font-size:0.72rem; color:var(--text-muted); margin-top:0.4rem; }
        .mailx-footer { display:flex; justify-content:flex-end; gap:0.6rem; padding:0.6rem 1.5rem 1.4rem; }
        .mailx-btn { display:inline-flex; align-items:center; gap:0.45rem; padding:0.7rem 1.4rem; border-radius:12px; font-size:0.9rem; font-weight:700; cursor:pointer; border:1px solid transparent; transition:transform .15s ease, background .2s ease, box-shadow .2s ease; }
        .mailx-btn:active { transform:scale(0.97); }
        .mailx-btn-ghost { background:transparent; border-color:var(--glass-border); color:var(--text-muted); }
        .mailx-btn-ghost:hover { color:#fff; border-color:rgba(255,255,255,0.3); }
        .mailx-btn-send { background:linear-gradient(135deg,#d4af37,#c49a2c); color:#0c241a; box-shadow:0 8px 22px rgba(212,175,55,0.3); }
        .mailx-btn-send:hover { box-shadow:0 10px 28px rgba(212,175,55,0.45); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="dashboard-layout">
            <?php if ($isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">
        <div class="rep-search" style="margin-top:2rem;">
            <input type="text" id="rep-search-input" placeholder="🔍 Rechercher par nom, prénom ou email…">
        </div>

        <!-- Filtres de suivi (toujours affichés) -->
        <div class="adh-filters-adv" id="adh-panel">
            <?php echo filter_select_rep('f-integrateur', 'Intégrateur', $fInteg); ?>
            <?php echo filter_select_rep('f-presentation', 'Présentation', $fPres); ?>
            <?php echo filter_select_rep('f-testkourel', 'Test Kourel', $fTest); ?>
            <button type="button" id="adh-reset">✕ Réinitialiser</button>
            <span class="adh-count" id="adh-count"></span>
        </div>

        <!-- Filtres supplémentaires (repliés par défaut) -->
        <button type="button" id="adh-toggle" class="adh-toggle">🔎 Plus de filtres <span class="chev">▾</span></button>
        <div class="adh-filters-adv is-hidden" id="adh-panel2">
            <?php echo filter_select_rep('f-commission', 'Commission', $fCommissions); ?>
            <?php echo filter_select_rep('f-secteur', 'Secteur', $fSecteurs); ?>
            <?php echo filter_select_rep('f-type', 'Type', $fTypes); ?>
            <?php echo filter_select_rep('f-statut', 'Statut', $fStatuts); ?>
            <?php echo filter_select_rep('f-genre', 'Genre', $fGenres); ?>
            <?php if (!empty($fAnnees)) echo filter_select_rep('f-annee', 'Année', $fAnnees); ?>
        </div>

        <?php if (empty($rows)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📝</div>
                <p><?php echo ($isIntegrateur && !$canAll) ? "Aucun inscrit ne vous a encore été assigné." : "Aucune réponse pour le moment."; ?></p>
            </div>
        <?php else: ?>
            <?php
                $pendingRows  = array_values(array_filter($rows, function ($r) { return $r['status'] !== 'approved'; }));
                $approvedRows = array_values(array_filter($rows, function ($r) { return $r['status'] === 'approved'; }));
            ?>
            <div id="rep-grid">
                <?php if (!empty($pendingRows)): ?>
                    <div class="rep-group-title" data-group-title="pending">
                        <span>🕓 En attente de finalisation d'intégration</span>
                        <span class="rep-group-count"><?php echo count($pendingRows); ?></span>
                    </div>
                    <div class="rep-grid rep-group" data-group="pending">
                        <?php foreach ($pendingRows as $r) { render_rep_card($r, $canAll, $integrateurs, $commissions); } ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($approvedRows)): ?>
                    <div class="rep-group-title rep-group-title--valide" data-group-title="approved">
                        <span>✅ Comptes validés</span>
                        <span class="rep-group-count"><?php echo count($approvedRows); ?></span>
                    </div>
                    <div class="rep-grid rep-group" data-group="approved">
                        <?php foreach ($approvedRows as $r) { render_rep_card($r, $canAll, $integrateurs, $commissions); } ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="empty-state" id="rep-noresult" style="display:none;">
                <div class="empty-state-icon">🔍</div><p>Aucun membre ne correspond à votre recherche.</p>
            </div>
        <?php endif; ?>
            </div>
        </div>
    </main>

    <?php if ($canAll): ?>
    <!-- Gérer les intégrateurs : donner / retirer le rôle (immédiat) -->
    <div id="role-modal" style="display:none; position:fixed; inset:0; z-index:3200; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; padding:1rem;">
        <div style="width:100%; max-width:560px; max-height:88vh; display:flex; flex-direction:column; background:linear-gradient(180deg,#123528,#0c241a); border:1px solid rgba(212,175,55,0.25); border-radius:18px; overflow:hidden; box-shadow:0 30px 80px rgba(0,0,0,0.55);">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem 1.25rem; background:linear-gradient(135deg,#1b4332,#2d6a4f);">
                <h3 style="margin:0; color:#fff; font-size:1.05rem;">👤 Gérer les intégrateurs</h3>
                <button type="button" onclick="closeRoleModal()" style="background:rgba(255,255,255,0.15); color:#fff; border:0; width:28px; height:28px; border-radius:50%; font-size:1.1rem; line-height:1; cursor:pointer;">&times;</button>
            </div>
            <div style="padding:0.75rem 1rem;">
                <input type="text" id="role-search" placeholder="🔍 Rechercher un membre…" style="width:100%; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); border-radius:50px; color:#fff; font-size:0.85rem; padding:0.55rem 1rem;">
            </div>
            <div id="role-list" style="flex:1; overflow-y:auto; padding:0 0.75rem 1rem; display:flex; flex-direction:column; gap:0.35rem;"></div>
            <div style="display:flex; align-items:center; justify-content:space-between; padding:0.85rem 1.25rem; border-top:1px solid var(--glass-border);">
                <span id="role-count" style="font-size:0.8rem; color:var(--text-muted);"></span>
                <button type="button" class="btn btn-primary btn-sm" onclick="closeRoleModal()">Terminé</button>
            </div>
        </div>
    </div>
    <form id="role-form" style="display:none;"><?php echo csrf_field(); ?></form>
    <script>
        var ROLE_MEMBERS = <?php echo json_encode(array_map(function ($m) {
            $ini = strtoupper(mb_substr($m['prenom'] ?? '', 0, 1) . mb_substr($m['nom'] ?? '', 0, 1));
            return ['id' => (int) $m['id'], 'name' => trim($m['prenom'] . ' ' . $m['nom']), 'photo' => $m['photo_path'] ?? '', 'ini' => ($ini !== '' ? $ini : '?'), 'integ' => ((int) $m['is_integrateur'] === 1)];
        }, $roleMembers), JSON_UNESCAPED_UNICODE); ?>;
        var ROLE_CSRF = (document.querySelector('#role-form input[name=csrf_token]') || {}).value || '';
        var ROLE_BYID = {}; ROLE_MEMBERS.forEach(function (m) { ROLE_BYID[m.id] = m; });
        var roleChanged = false;
        function roleEsc(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
        function roleNorm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
        function roleRow(m){
            var av = m.photo
                ? '<img src="uploads/' + encodeURIComponent(m.photo) + '" alt="" style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;">'
                : '<span style="width:34px;height:34px;border-radius:50%;background:rgba(212,175,55,0.15);color:var(--accent);border:1px solid rgba(212,175,55,0.35);display:inline-flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:700;flex-shrink:0;">' + roleEsc(m.ini) + '</span>';
            var badge = m.integ ? ' <span style="font-size:0.68rem;color:var(--accent);border:1px solid rgba(212,175,55,0.4);border-radius:50px;padding:1px 7px;white-space:nowrap;">🧭 Intégrateur</span>' : '';
            var btn = m.integ
                ? '<button type="button" class="btn btn-secondary btn-sm" style="color:var(--warning);border-color:var(--warning);flex-shrink:0;" onclick="toggleRole(' + m.id + ',false)">Retirer</button>'
                : '<button type="button" class="btn btn-primary btn-sm" style="flex-shrink:0;" onclick="toggleRole(' + m.id + ',true)">Donner le rôle</button>';
            return '<div style="display:flex;align-items:center;gap:0.6rem;padding:0.4rem 0.5rem;border:1px solid var(--glass-border);border-radius:12px;background:rgba(255,255,255,0.03);">' + av + '<span style="flex:1;min-width:0;color:#fff;font-size:0.88rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + roleEsc(m.name) + badge + '</span>' + btn + '</div>';
        }
        function roleRender(){
            var term = roleNorm(document.getElementById('role-search').value);
            var list = document.getElementById('role-list');
            var html = '', n = 0;
            ROLE_MEMBERS.forEach(function (m) {
                if (term && roleNorm(m.name).indexOf(term) === -1) return;
                html += roleRow(m); n++;
            });
            list.innerHTML = html || '<div style="color:var(--text-muted);font-style:italic;padding:0.75rem;text-align:center;">Aucun membre.</div>';
            document.getElementById('role-count').textContent = n + ' membre(s)';
        }
        function toggleRole(id, grant){
            var m = ROLE_BYID[id]; if (!m) return;
            var prev = m.integ; m.integ = grant; roleRender();
            var params = new URLSearchParams();
            params.append('ajax', '1');
            params.append('action', grant ? 'grant_integrateur' : 'revoke_integrateur');
            params.append('role_member_id', id);
            params.append('csrf_token', ROLE_CSRF);
            fetch('admin_reponses.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString(), credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (!j.ok) { throw new Error(j.error || 'Erreur'); } roleChanged = true; })
                .catch(function (e) { m.integ = prev; roleRender(); modernAlert('Échec : ' + e.message, 'Erreur'); });
        }
        function openRoleModal(){ document.getElementById('role-search').value = ''; roleRender(); document.getElementById('role-modal').style.display = 'flex'; }
        function closeRoleModal(){ document.getElementById('role-modal').style.display = 'none'; if (roleChanged) { location.reload(); } }
        document.getElementById('role-search').addEventListener('input', roleRender);
        document.getElementById('role-modal').addEventListener('click', function (e) { if (e.target === this) closeRoleModal(); });
    </script>
    <?php endif; ?>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="rep-toast" style="position:fixed; left:50%; transform:translateX(-50%); bottom:1.5rem; z-index:200; background:<?php echo !empty($success) ? 'var(--primary)' : 'var(--danger)'; ?>; color:#fff; padding:0.75rem 1.5rem; border-radius:50px; box-shadow:0 6px 20px rgba(0,0,0,0.45); font-weight:600; font-size:0.9rem; border:1px solid rgba(255,255,255,0.15);">
        <?php echo htmlspecialchars(!empty($success) ? '✓ ' . $success : $error); ?>
    </div>
    <script>
        setTimeout(function () {
            var t = document.getElementById('rep-toast');
            if (t) { t.style.transition = 'opacity .5s ease'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 500); }
        }, 2500);
    </script>
    <?php endif; ?>

    <!-- Modale d'envoi d'e-mail au membre (UI moderne) -->
    <div id="mail-modal" class="modal-overlay mailx-overlay">
        <div class="mailx-card">
            <div class="mailx-header">
                <div class="mailx-header-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                </div>
                <div>
                    <h3 class="mailx-title">Nouveau message</h3>
                    <p class="mailx-subtitle">Envoi par e-mail depuis le Dahira</p>
                </div>
                <button type="button" class="mailx-close" onclick="closeMailModal()" aria-label="Fermer">&times;</button>
            </div>
            <form method="POST" action="admin_reponses.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="send_mail">
                <input type="hidden" name="member_id" id="mail-member-id" value="">
                <div class="mailx-body">
                    <div class="mailx-recipient">
                        <span class="mailx-recipient-label">À</span>
                        <span class="mailx-avatar" id="mail-avatar">?</span>
                        <span class="mailx-recipient-name" id="mail-to"></span>
                    </div>
                    <div class="mailx-field">
                        <label for="mail-subject">Objet</label>
                        <input type="text" name="mail_subject" id="mail-subject" required>
                    </div>
                    <div class="mailx-field">
                        <label for="mail-body">Message</label>
                        <textarea name="mail_body" id="mail-body" rows="6" required></textarea>
                        <span class="mailx-hint">La formule de salutation (« Assalamu aleykum … ») est ajoutée automatiquement.</span>
                    </div>
                </div>
                <div class="mailx-footer">
                    <button type="button" class="mailx-btn mailx-btn-ghost" onclick="closeMailModal()">Annuler</button>
                    <button type="submit" class="mailx-btn mailx-btn-send">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                        Envoyer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>

    <script>
        var mailModal = document.getElementById('mail-modal');
        function openMailModal(btn) {
            var name = btn.dataset.name || '';
            document.getElementById('mail-member-id').value = btn.dataset.mid;
            document.getElementById('mail-to').textContent = name;
            var parts = name.trim().split(/\s+/);
            var initials = (parts[0] ? parts[0][0] : '') + (parts[1] ? parts[1][0] : '');
            document.getElementById('mail-avatar').textContent = initials || '?';
            document.getElementById('mail-subject').value = 'Dahira Touba Lyon';
            document.getElementById('mail-body').value = '';
            mailModal.style.display = 'flex';
            setTimeout(function () { mailModal.classList.add('active'); document.getElementById('mail-body').focus(); }, 10);
        }
        function closeMailModal() {
            mailModal.classList.remove('active');
            setTimeout(function () { mailModal.style.display = 'none'; }, 300);
        }
        mailModal.addEventListener('click', function (e) { if (e.target === this) closeMailModal(); });

        function closeNotificationModal() {
            const m = document.getElementById('notification-modal');
            if (m) { m.classList.remove('active'); setTimeout(() => { m.style.display = 'none'; }, 300); }
        }
        (function () {
            const searchInput = document.getElementById('rep-search-input');
            const cards = Array.from(document.querySelectorAll('.rep-card'));
            const noResult = document.getElementById('rep-noresult');
            const groups = Array.from(document.querySelectorAll('.rep-group'));
            const countEl = document.getElementById('adh-count');
            const selects = {
                integrateur:  document.getElementById('f-integrateur'),
                commission:   document.getElementById('f-commission'),
                presentation: document.getElementById('f-presentation'),
                testkourel:   document.getElementById('f-testkourel'),
                secteur:      document.getElementById('f-secteur'),
                type:         document.getElementById('f-type'),
                statut:       document.getElementById('f-statut'),
                genre:        document.getElementById('f-genre'),
                annee:        document.getElementById('f-annee')
            };
            function norm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }

            function apply() {
                const term = norm(searchInput ? searchInput.value : '');
                let count = 0;
                cards.forEach(function (c) {
                    let show = true;
                    for (const key in selects) {
                        const sel = selects[key];
                        if (show && sel && sel.value) { show = (c.getAttribute('data-' + key) === sel.value); }
                    }
                    if (show && term) { show = norm(c.getAttribute('data-search')).includes(term); }
                    c.style.display = show ? 'flex' : 'none';
                    if (show) count++;
                });
                groups.forEach(function (g) {
                    const visible = Array.from(g.querySelectorAll('.rep-card')).some(function (c) { return c.style.display !== 'none'; });
                    g.style.display = visible ? '' : 'none';
                    const title = document.querySelector('.rep-group-title[data-group-title="' + g.getAttribute('data-group') + '"]');
                    if (title) title.style.display = visible ? '' : 'none';
                });
                if (noResult) noResult.style.display = count === 0 ? 'block' : 'none';
                if (countEl) countEl.textContent = count + ' résultat(s)';
            }

            if (searchInput) searchInput.addEventListener('input', apply);
            for (const key in selects) { if (selects[key]) selects[key].addEventListener('change', apply); }

            const toggle = document.getElementById('adh-toggle');
            const panel2 = document.getElementById('adh-panel2');
            if (toggle && panel2) toggle.addEventListener('click', function () {
                panel2.classList.toggle('is-hidden');
                toggle.classList.toggle('open');
            });
            const reset = document.getElementById('adh-reset');
            if (reset) reset.addEventListener('click', function () {
                if (searchInput) searchInput.value = '';
                for (const key in selects) { if (selects[key]) selects[key].value = ''; }
                apply();
            });
            apply();
        })();
    </script>
</body>
</html>
