<?php
/**
 * Touba Lyon 2026 - 📰 Actualités (commission Secrétariat Général)
 *
 * Accessible aux administrateurs et aux responsables de la commission
 * « Secrétariat Général ». Permet de préparer un message d'actualité
 * (type + titre + contenu, mentions « @Prénom Nom »), de le publier, puis
 * de le diffuser aux membres : notification in-app, WhatsApp et e-mail.
 */
require_once __DIR__ . '/actualites_guard.php'; // admins + responsables Secrétariat Général — expose $__actuAdmin
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dahira_emails.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_wa_link(), dahira_wa_button()
require_once __DIR__ . '/actualites_helper.php';
require_once __DIR__ . '/fonction_responsables_helper.php'; // responsables des actualités (comme Lecture Coran)

// Ajout / retrait d'un responsable (appels AJAX de la fenêtre de sélection)
fr_traiter_post($pdo, 'actualites', true, (int) $__actuMemberId, !empty($__actuAdmin), 'Actualités');

$error = '';
$success = '';
$membreId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;

/**
 * Traite un fichier joint uploadé. Retourne ['name'=>?, 'error'=>?].
 * $exts : extensions autorisées (minuscules).
 */
function actu_upload_fichier(string $field, string $prefix, array $exts): array
{
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['name' => null, 'error' => null];
    }
    $tmp = $_FILES[$field]['tmp_name'] ?? '';
    $nom = $_FILES[$field]['name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['name' => null, 'error' => null];
    }
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) {
        return ['name' => null, 'error' => 'Format de fichier non autorisé (' . htmlspecialchars($ext) . ').'];
    }
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    try { $rand = bin2hex(random_bytes(3)); } catch (Exception $e) { $rand = (string) mt_rand(100000, 999999); }
    $fname = $prefix . '_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
    if (move_uploaded_file($tmp, $dir . '/' . $fname)) {
        return ['name' => $fname, 'error' => null];
    }
    return ['name' => null, 'error' => 'Impossible d\'enregistrer le fichier joint.'];
}

/** Notifie les membres mentionnés (« @Prénom Nom ») d'une actualité. */
function actu_notifier_mentions(PDO $pdo, string $mentions, string $type, string $titre, int $actuId = 0): void
{
    $ids = array_filter(array_map('intval', explode(',', $mentions)));
    if (empty($ids)) { return; }
    $emoji = actu_type_emoji($type);
    $corps = 'Vous avez été mentionné(e) dans une actualité : « ' . trim($titre !== '' ? $titre : $type) . ' ».';
    $lien = $actuId > 0 ? 'actualite_detail.php?id=' . $actuId : 'index.php';
    foreach (array_unique($ids) as $mid) {
        if ($mid > 0) {
            troba_notify_membre($pdo, (int) $mid, 'actualite', $emoji . ' Vous êtes mentionné(e)', $corps, $lien);
        }
    }
}

// ---------------------------------------------------------------------------
// Actions POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_actu') {
                $id = (int) ($_POST['id'] ?? 0);
                $type = trim($_POST['type'] ?? '');
                $titre = trim($_POST['titre'] ?? '');
                $contenu = trim($_POST['contenu'] ?? '');
                $mentions = trim($_POST['mentions'] ?? '');
                // nettoyage : uniquement des identifiants numériques séparés par des virgules
                $mentions = implode(',', array_filter(array_map('intval', explode(',', $mentions))));
                // Pièces jointes (image à afficher, document PDF)
                $upImg = actu_upload_fichier('image', 'actu_img', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                $upPdf = actu_upload_fichier('pdf', 'actu_pdf', ['pdf']);
                if ($titre === '' || $contenu === '') {
                    $error = "Le titre et le contenu sont obligatoires.";
                } elseif ($upImg['error'] !== null) {
                    $error = $upImg['error'];
                } elseif ($upPdf['error'] !== null) {
                    $error = $upPdf['error'];
                } elseif ($id > 0) {
                    // Remplacement éventuel : supprimer l'ancien fichier
                    $old = $pdo->prepare("SELECT image_path, pdf_path FROM actualites WHERE id = ?");
                    $old->execute([$id]);
                    $oldRow = $old->fetch() ?: [];
                    $pdo->prepare("UPDATE actualites SET type = ?, titre = ?, contenu = ?, mentions = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([($type ?: null), $titre, $contenu, ($mentions ?: null), $id]);
                    if ($upImg['name'] !== null) {
                        if (!empty($oldRow['image_path'])) { @unlink(__DIR__ . '/uploads/' . $oldRow['image_path']); }
                        $pdo->prepare("UPDATE actualites SET image_path = ? WHERE id = ?")->execute([$upImg['name'], $id]);
                    }
                    if ($upPdf['name'] !== null) {
                        if (!empty($oldRow['pdf_path'])) { @unlink(__DIR__ . '/uploads/' . $oldRow['pdf_path']); }
                        $pdo->prepare("UPDATE actualites SET pdf_path = ? WHERE id = ?")->execute([$upPdf['name'], $id]);
                    }
                    $success = "Actualité mise à jour.";
                } else {
                    $pdo->prepare("INSERT INTO actualites (type, titre, contenu, mentions, image_path, pdf_path, statut, created_by) VALUES (?,?,?,?,?,?, 'brouillon', ?)")
                        ->execute([($type ?: null), $titre, $contenu, ($mentions ?: null), $upImg['name'], $upPdf['name'], ($membreId ?: null)]);
                    $success = "Actualité enregistrée (brouillon).";
                }
            } elseif (in_array($action, ['publish_actu', 'unpublish_actu', 'delete_actu', 'notify_actu', 'send_email_actu', 'close_actu', 'reopen_actu', 'delete_actu_image', 'delete_actu_pdf'], true)) {
                $id = (int) ($_POST['id'] ?? 0);
                $a = null;
                if ($id > 0) {
                    $st = $pdo->prepare("SELECT * FROM actualites WHERE id = ?");
                    $st->execute([$id]);
                    $a = $st->fetch();
                }
                if (!$a) {
                    $error = "Actualité introuvable.";
                } elseif ($action === 'publish_actu') {
                    $pdo->prepare("UPDATE actualites SET statut = 'publie', published_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$id]);
                    // Notification automatique à tous les membres dès la publication
                    $emoji = actu_type_emoji((string) ($a['type'] ?? ''));
                    $titreN = trim((string) ($a['titre'] ?? ''));
                    $corps = mb_substr(trim((string) ($a['contenu'] ?? '')), 0, 160);
                    $n = troba_notify_all_membres($pdo, 'actualite', $emoji . ' ' . ($titreN !== '' ? $titreN : 'Nouvelle actualité'), $corps, 'actualite_detail.php?id=' . $id);
                    actu_notifier_mentions($pdo, (string) ($a['mentions'] ?? ''), (string) ($a['type'] ?? ''), $titreN, $id);
                    $pdo->prepare("UPDATE actualites SET publie_notifie = 1 WHERE id = ?")->execute([$id]);
                    $success = "Actualité publiée et notification envoyée à $n membre(s). Vous pouvez aussi la partager (WhatsApp, e-mail).";
                } elseif ($action === 'unpublish_actu') {
                    $pdo->prepare("UPDATE actualites SET statut = 'brouillon', updated_at = NOW() WHERE id = ?")->execute([$id]);
                    $success = "Actualité repassée en brouillon (masquée).";
                } elseif ($action === 'close_actu') {
                    $pdo->prepare("UPDATE actualites SET cloture = 1, updated_at = NOW() WHERE id = ?")->execute([$id]);
                    $success = "Actualité clôturée (archivée, masquée aux membres).";
                } elseif ($action === 'reopen_actu') {
                    $pdo->prepare("UPDATE actualites SET cloture = 0, updated_at = NOW() WHERE id = ?")->execute([$id]);
                    $success = "Actualité rouverte.";
                } elseif ($action === 'delete_actu') {
                    if (!empty($a['image_path'])) { @unlink(__DIR__ . '/uploads/' . $a['image_path']); }
                    if (!empty($a['pdf_path'])) { @unlink(__DIR__ . '/uploads/' . $a['pdf_path']); }
                    $pdo->prepare("DELETE FROM actualites WHERE id = ?")->execute([$id]);
                    $success = "Actualité supprimée.";
                } elseif ($action === 'delete_actu_image') {
                    if (!empty($a['image_path'])) { @unlink(__DIR__ . '/uploads/' . $a['image_path']); }
                    $pdo->prepare("UPDATE actualites SET image_path = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
                    $success = "Image retirée.";
                } elseif ($action === 'delete_actu_pdf') {
                    if (!empty($a['pdf_path'])) { @unlink(__DIR__ . '/uploads/' . $a['pdf_path']); }
                    $pdo->prepare("UPDATE actualites SET pdf_path = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
                    $success = "Document retiré.";
                } elseif ($action === 'notify_actu') {
                    if ($a['statut'] !== 'publie') {
                        $error = "Publiez l'actualité avant de la diffuser.";
                    } else {
                        $emoji = actu_type_emoji((string) ($a['type'] ?? ''));
                        $titreN = trim((string) ($a['titre'] ?? ''));
                        $corps = mb_substr(trim((string) ($a['contenu'] ?? '')), 0, 160);
                        $n = troba_notify_all_membres($pdo, 'actualite', $emoji . ' ' . ($titreN !== '' ? $titreN : 'Nouvelle actualité'), $corps, 'actualite_detail.php?id=' . $id);
                        actu_notifier_mentions($pdo, (string) ($a['mentions'] ?? ''), (string) ($a['type'] ?? ''), $titreN, $id);
                        $pdo->prepare("UPDATE actualites SET publie_notifie = 1, updated_at = NOW() WHERE id = ?")->execute([$id]);
                        $success = "Notification envoyée à $n membre(s).";
                    }
                } elseif ($action === 'send_email_actu') {
                    if ($a['statut'] !== 'publie') {
                        $error = "Publiez l'actualité avant de l'envoyer par e-mail.";
                    } else {
                        try {
                            $destinataires = $pdo->query("SELECT nom, prenom, email FROM membres WHERE status = 'approved' AND email IS NOT NULL AND email != ''")->fetchAll();
                        } catch (Exception $e) {
                            $destinataires = [];
                        }
                        if (empty($destinataires)) {
                            $error = "Aucun membre à prévenir par e-mail.";
                        } else {
                            $sujet = actu_type_emoji((string) ($a['type'] ?? '')) . ' ' . trim((string) ($a['titre'] ?? 'Actualité')) . ' — Touba Lyon';
                            $corpsHtml = actu_email((string) ($a['titre'] ?? ''), (string) ($a['contenu'] ?? ''), (string) ($a['type'] ?? ''), actu_mentions_map($pdo, (string) ($a['mentions'] ?? '')));
                            $sent = 0;
                            foreach ($destinataires as $m) {
                                $nom = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
                                if (send_smtp_mail($m['email'], $nom !== '' ? $nom : 'Cher membre', $sujet, $corpsHtml)) { $sent++; }
                            }
                            $pdo->prepare("UPDATE actualites SET email_envoye = 1, updated_at = NOW() WHERE id = ?")->execute([$id]);
                            $success = "Actualité envoyée par e-mail à $sent membre(s).";
                        }
                    }
                }
            } elseif ($action === 'add_type') {
                $nom = trim($_POST['nom'] ?? '');
                if ($nom !== '') {
                    $pdo->prepare("INSERT IGNORE INTO actualite_types (nom) VALUES (?)")->execute([$nom]);
                    $success = "Type d'actualité ajouté.";
                } else {
                    $error = "Le nom du type est obligatoire.";
                }
            } elseif ($action === 'delete_type') {
                $tid = (int) ($_POST['type_id'] ?? 0);
                if ($tid > 0) {
                    $pdo->prepare("DELETE FROM actualite_types WHERE id = ?")->execute([$tid]);
                    $success = "Type d'actualité supprimé.";
                }
            } else {
                $error = "Action non autorisée.";
            }
        } catch (Exception $e) {
            error_log('Touba Lyon actualites: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
}

// ---------------------------------------------------------------------------
// Données
// ---------------------------------------------------------------------------
$types = actu_types($pdo);
$actualites = [];
try {
    $actualites = $pdo->query("SELECT * FROM actualites ORDER BY COALESCE(cloture,0) ASC, (statut = 'publie') DESC, COALESCE(published_at, created_at) DESC, id DESC")->fetchAll();
} catch (Exception $e) {
    $actualites = [];
}

// Membres validés (pour les mentions @ et le compteur de destinataires)
$membresListe = [];
$nbMembres = 0;
try {
    $membresListe = $pdo->query("SELECT id, prenom, nom FROM membres WHERE status = 'approved' ORDER BY prenom ASC, nom ASC")->fetchAll();
    $nbMembres = (int) $pdo->query("SELECT COUNT(*) FROM membres WHERE status = 'approved' AND email IS NOT NULL AND email != ''")->fetchColumn();
} catch (Exception $e) {
    $membresListe = [];
    $nbMembres = 0;
}
// Index id => "Prénom Nom" (pour réafficher les mentions à l'édition)
$membresIndex = [];
foreach ($membresListe as $mm) { $membresIndex[(int) $mm['id']] = trim(($mm['prenom'] ?? '') . ' ' . ($mm['nom'] ?? '')); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📰 Actualités — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .ac-wrap { max-width: 980px; margin: 2rem auto; }
        details.glass-card { padding: 0.9rem 1.1rem; }
        details.glass-card > summary { padding: 0.25rem 0; line-height: 1.3; }
        .ac-card { border-radius: 12px; padding: 1.1rem 1.2rem; display: flex; flex-direction: column; gap: 0.7rem; margin-bottom: 1rem; }
        .ac-title { font-size: 1.05rem; font-weight: 700; color: var(--white); }
        .ac-meta { color: var(--text-muted); font-size: 0.8rem; }
        .ac-body { white-space: pre-wrap; color: var(--text-muted); font-size: 0.9rem; line-height: 1.55; background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); border-radius: 8px; padding: 0.8rem; }
        .ac-badge { font-size: 0.72rem; padding: 0.2rem 0.55rem; border-radius: 6px; }
        .ac-badge-pub { background: rgba(37,211,102,0.15); color: #7bd8a6; border: 1px solid rgba(37,211,102,0.3); }
        .ac-badge-dr { background: rgba(255,255,255,0.05); color: var(--text-muted); border: 1px solid var(--glass-border); }
        .ac-badge-type { background: rgba(212,175,55,0.15); color: var(--accent); border: 1px solid rgba(212,175,55,0.4); }
        .ac-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .ac-mention { color: var(--accent); font-weight: 600; }
        /* Zone de saisie */
        .ac-form .form-group { margin-bottom: 0.8rem; }
        .ac-form textarea.form-input { min-height: 140px; resize: vertical; }
        /* Menu déroulant d'autocomplétion des mentions @ */
        .ac-mention-box { position: relative; }
        .ac-mention-list { position: absolute; z-index: 50; left: 0; right: 0; max-height: 220px; overflow-y: auto; background: #0c241a; border: 1px solid var(--glass-border); border-radius: 8px; margin-top: 2px; display: none; box-shadow: 0 10px 30px rgba(0,0,0,0.4); }
        .ac-mention-item { padding: 0.55rem 0.9rem; cursor: pointer; color: #fff; font-size: 0.9rem; }
        .ac-mention-item:hover, .ac-mention-item.active { background: rgba(212,175,55,0.18); color: var(--accent); }
        .ac-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.5rem; }
        .ac-chip { background: rgba(212,175,55,0.15); color: var(--accent); border: 1px solid rgba(212,175,55,0.4); border-radius: 12px; padding: 0.15rem 0.6rem; font-size: 0.78rem; display: inline-flex; align-items: center; gap: 0.35rem; }
        .ac-chip button { background: none; border: 0; color: var(--accent); cursor: pointer; font-size: 0.9rem; line-height: 1; padding: 0; }
        @media (max-width: 600px) {
            .ac-actions form, .ac-actions > * { width: 100%; }
            .ac-actions .btn { width: 100%; justify-content: center; }
        }
        details[open] > summary .ac-chevron { transform: rotate(90deg); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__actuAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>

            <div class="admin-content">
                <h1 class="admin-page-title">📰 Actualités</h1>
                <p class="admin-page-desc" style="color:var(--text-muted); margin-top:0.9rem; font-size:0.9rem;">
                    Préparez un message d'actualité, publiez-le, puis diffusez-le aux membres (notification, WhatsApp, e-mail). Mentionnez un membre avec <strong>@</strong>.
                </p>

                <?php if (!empty($success)): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:8px;margin-bottom:1rem;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:8px;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <?php echo fr_bloc_html($pdo, 'actualites', 'Actualités', 'actualites.php'); ?>

                <button type="button" class="btn btn-primary" id="ac-new-btn" style="margin-bottom:1.2rem;">➕ Nouvelle actualité</button>

                <div style="display:flex; flex-direction:column;">
                <!-- Formulaire de rédaction -->
                <details class="glass-card" style="margin-bottom:1.5rem;" id="ac-editor">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">✍️ Nouvelle actualité <span class="ac-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <form action="actualites.php" method="POST" class="ac-form" style="margin-top:1rem;" id="ac-form" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_actu">
                        <input type="hidden" name="id" id="ac-id" value="">
                        <input type="hidden" name="mentions" id="ac-mentions" value="">
                        <div class="form-group">
                            <label class="form-label">🏷️ Type d'actualité</label>
                            <select name="type" id="ac-type" class="form-input" style="width:100%;">
                                <option value="">— Aucun / général —</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t['nom'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($t['nom']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">📌 Titre</label>
                            <input type="text" name="titre" id="ac-titre" class="form-input" style="width:100%;" placeholder="Titre de l'actualité" maxlength="200">
                        </div>
                        <div class="form-group ac-mention-box">
                            <label class="form-label">📝 Contenu <span style="color:var(--text-muted); font-weight:400;">— tapez <strong>@</strong> pour mentionner un membre</span></label>
                            <textarea name="contenu" id="ac-contenu" class="form-input" style="width:100%;" placeholder="Rédigez votre message… Utilisez @ pour mentionner un membre."></textarea>
                            <div class="ac-mention-list" id="ac-mention-list"></div>
                            <div class="ac-chips" id="ac-chips"></div>
                        </div>
                        <div style="display:flex; gap:0.8rem; flex-wrap:wrap;">
                            <div class="form-group" style="flex:1 1 240px;">
                                <label class="form-label">🖼️ Image à afficher (facultatif)</label>
                                <input type="file" name="image" accept="image/*" class="form-input" style="width:100%;">
                            </div>
                            <div class="form-group" style="flex:1 1 240px;">
                                <label class="form-label">📎 Document PDF (facultatif)</label>
                                <input type="file" name="pdf" accept="application/pdf" class="form-input" style="width:100%;">
                            </div>
                        </div>
                        <p style="color:var(--text-muted); font-size:0.78rem; margin:0 0 0.6rem;">À l'édition, un nouveau fichier remplace l'ancien. La gestion des pièces jointes existantes se fait sur chaque actualité ci-dessous.</p>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.4rem;">
                            <button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer (brouillon)</button>
                            <button type="button" class="btn btn-secondary btn-sm" id="ac-reset">➕ Nouveau</button>
                        </div>
                    </form>
                </details>

                <!-- Types d'actualité (gestion) -->
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">🏷️ Types d'actualité (<?php echo count($types); ?>) <span class="ac-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <div style="margin-top:0.9rem;">
                        <form action="actualites.php" method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; margin-bottom:0.9rem;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="add_type">
                            <input type="text" name="nom" class="form-input" placeholder="Nouveau type…" style="flex:1 1 220px;" maxlength="100">
                            <button type="submit" class="btn btn-primary btn-sm">➕ Ajouter</button>
                        </form>
                        <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                            <?php foreach ($types as $t): ?>
                                <span class="ac-chip">🏷️ <?php echo htmlspecialchars($t['nom']); ?>
                                    <form action="actualites.php" method="POST" style="margin:0; display:inline;" data-confirm="Supprimer le type « <?php echo htmlspecialchars($t['nom'], ENT_QUOTES); ?> » ?" data-confirm-danger="1">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_type">
                                        <input type="hidden" name="type_id" value="<?php echo (int) $t['id']; ?>">
                                        <button type="submit" title="Supprimer">✕</button>
                                    </form>
                                </span>
                            <?php endforeach; ?>
                            <?php if (empty($types)): ?><span style="color:var(--text-muted); font-size:0.85rem;">Aucun type. Ajoutez-en un ci-dessus.</span><?php endif; ?>
                        </div>
                    </div>
                </details>

                <!-- Liste des actualités (placée en tête via l'ordre CSS) -->
                <div style="order:-1;">
                <h2 style="color:var(--white); font-size:1.1rem; margin:0 0 0.9rem;">🗞️ Actualités (<?php echo count($actualites); ?>)</h2>
                <?php if (empty($actualites)): ?>
                    <div class="glass-card" style="text-align:center; padding:2rem; color:var(--text-muted);">Aucune actualité pour le moment. Rédigez-en une ci-dessus.</div>
                <?php else: ?>
                    <?php foreach ($actualites as $a):
                        $pub = ($a['statut'] === 'publie');
                        $clot = ((int) ($a['cloture'] ?? 0) === 1);
                        $mentionMap = [];
                        foreach (array_filter(array_map('intval', explode(',', (string) ($a['mentions'] ?? '')))) as $mid) {
                            if (isset($membresIndex[$mid])) { $mentionMap[$mid] = $membresIndex[$mid]; }
                        }
                        $mentionNoms = array_values($mentionMap);
                        $waMsg = actu_message((string) ($a['titre'] ?? ''), (string) ($a['contenu'] ?? ''), (string) ($a['type'] ?? ''), $mentionMap, (int) $a['id'], (string) ($a['pdf_path'] ?? ''));
                    ?>
                    <div class="glass-card ac-card" style="<?php echo $clot ? 'opacity:0.7; border-left:3px solid rgba(255,255,255,0.18);' : ($pub ? 'border-left:3px solid rgba(37,211,102,0.5);' : 'border-left:3px solid rgba(255,255,255,0.2);'); ?>">
                        <div style="display:flex; justify-content:space-between; gap:0.7rem; flex-wrap:wrap; align-items:flex-start;">
                            <div>
                                <div class="ac-title"><?php echo actu_type_emoji((string) ($a['type'] ?? '')) . ' ' . htmlspecialchars((string) ($a['titre'] ?? '')); ?></div>
                                <div class="ac-meta">
                                    <?php echo $pub ? '📅 Publiée le ' . date('d/m/Y à H:i', strtotime((string) ($a['published_at'] ?? $a['created_at']))) : '📝 Brouillon · ' . date('d/m/Y', strtotime((string) $a['created_at'])); ?>
                                </div>
                            </div>
                            <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                                <?php if ($a['type']): ?><span class="ac-badge ac-badge-type">🏷️ <?php echo htmlspecialchars((string) $a['type']); ?></span><?php endif; ?>
                                <?php if ($clot): ?><span class="ac-badge ac-badge-dr">📦 Clôturée</span><?php endif; ?>
                                <span class="ac-badge <?php echo $pub ? 'ac-badge-pub' : 'ac-badge-dr'; ?>"><?php echo $pub ? '✅ Publiée' : '📝 Brouillon'; ?></span>
                            </div>
                        </div>
                        <div class="ac-body"><?php echo nl2br(htmlspecialchars((string) ($a['contenu'] ?? ''))); ?></div>
                        <?php if (!empty($mentionNoms)): ?>
                            <div style="font-size:0.8rem; color:var(--text-muted);">👥 Mentions : <?php echo implode(', ', array_map(function ($n) { return '<span class="ac-mention">@' . htmlspecialchars($n) . '</span>'; }, $mentionNoms)); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($a['image_path']) || !empty($a['pdf_path'])): ?>
                        <div style="display:flex; gap:0.7rem; flex-wrap:wrap; align-items:center;">
                            <?php if (!empty($a['image_path'])): ?>
                            <div style="display:flex; flex-direction:column; gap:0.3rem; align-items:flex-start;">
                                <a href="uploads/<?php echo htmlspecialchars((string) $a['image_path']); ?>" target="_blank" rel="noopener"><img src="uploads/<?php echo htmlspecialchars((string) $a['image_path']); ?>" alt="" style="max-height:90px; border-radius:8px; border:1px solid var(--glass-border);"></a>
                                <form action="actualites.php" method="POST" style="margin:0;" data-confirm="Retirer l'image ?" data-confirm-danger="1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_actu_image">
                                    <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(191,33,33,0.6); color:#fca5a5;">🗑️ Image</button>
                                </form>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($a['pdf_path'])): ?>
                            <div style="display:flex; flex-direction:column; gap:0.3rem; align-items:flex-start;">
                                <a href="uploads/<?php echo htmlspecialchars((string) $a['pdf_path']); ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">📎 Voir le PDF</a>
                                <form action="actualites.php" method="POST" style="margin:0;" data-confirm="Retirer le document PDF ?" data-confirm-danger="1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_actu_pdf">
                                    <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(191,33,33,0.6); color:#fca5a5;">🗑️ PDF</button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="ac-actions">
                            <?php if ($clot): ?>
                                <form action="actualites.php" method="POST" style="margin:0;" data-confirm="Rouvrir cette actualité ?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="reopen_actu">
                                    <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">↩️ Rouvrir</button>
                                </form>
                            <?php elseif (!$pub): ?>
                                <form id="pub-actu-<?php echo (int) $a['id']; ?>" action="actualites.php" method="POST" style="margin:0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="publish_actu">
                                    <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                    <button type="button" class="btn btn-sm" style="background:#1f9d55; border-color:#1f9d55; color:#fff;" onclick="publierActu(<?php echo (int) $a['id']; ?>, <?php echo htmlspecialchars(json_encode(dahira_wa_link(null, $waMsg)), ENT_QUOTES); ?>)">⚪ Publier</button>
                                </form>
                                <button type="button" class="btn btn-secondary btn-sm ac-edit"
                                    data-id="<?php echo (int) $a['id']; ?>"
                                    data-type="<?php echo htmlspecialchars((string) ($a['type'] ?? ''), ENT_QUOTES); ?>"
                                    data-titre="<?php echo htmlspecialchars((string) ($a['titre'] ?? ''), ENT_QUOTES); ?>"
                                    data-contenu="<?php echo htmlspecialchars((string) ($a['contenu'] ?? ''), ENT_QUOTES); ?>"
                                    data-mentions="<?php echo htmlspecialchars((string) ($a['mentions'] ?? ''), ENT_QUOTES); ?>"
                                    style="border-color:var(--accent); color:var(--accent);">✏️ Modifier</button>
                            <?php else: ?>
                                <?php echo dahira_wa_button(dahira_wa_link(null, $waMsg), 'Partager sur WhatsApp', true); ?>
                                <form action="actualites.php" method="POST" style="margin:0;" data-confirm="Envoyer la notification à tous les membres ?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="notify_actu">
                                    <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(45,140,255,0.6); color:#8ec5ff;">🔔 <?php echo ((int) ($a['publie_notifie'] ?? 0) === 1) ? 'Renvoyer la notification' : 'Envoyer la notification'; ?></button>
                                </form>
                                <form action="actualites.php" method="POST" style="margin:0;" data-confirm="Envoyer cette actualité par e-mail à <?php echo (int) $nbMembres; ?> membre(s) ?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="send_email_actu">
                                    <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" <?php echo $nbMembres === 0 ? 'disabled' : ''; ?>>📧 <?php echo ((int) ($a['email_envoye'] ?? 0) === 1) ? 'Renvoyer l\'e-mail' : 'Envoyer par e-mail'; ?> (<?php echo (int) $nbMembres; ?>)</button>
                                </form>
                                <form action="actualites.php" method="POST" style="margin:0;" data-confirm="Repasser cette actualité en brouillon (la masquer) ?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="unpublish_actu">
                                    <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">⛔ Dépublier</button>
                                </form>
                                <form action="actualites.php" method="POST" style="margin:0;" data-confirm="Clôturer cette actualité ? Elle sera archivée et masquée aux membres.">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="close_actu">
                                    <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(37,211,102,0.6); color:#7bd8a6;">📦 Clôturer</button>
                                </form>
                            <?php endif; ?>
                            <form action="actualites.php" method="POST" style="margin:0;" data-confirm="Supprimer définitivement cette actualité ?" data-confirm-danger="1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_actu">
                                <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(191,33,33,0.6); color:#fca5a5;">🗑️ Supprimer</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div><!-- /liste (order:-1) -->
                </div><!-- /conteneur flex -->
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        // Liste des membres pour l'autocomplétion des mentions @
        var AC_MEMBRES = <?php echo json_encode(array_map(function ($m) { return ['id' => (int) $m['id'], 'nom' => trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''))]; }, $membresListe), JSON_UNESCAPED_UNICODE); ?>;
        // Publier une actualité : confirmation avec option d'ouvrir WhatsApp
        function publierActu(id, waLink) {
            var submit = function () { var f = document.getElementById('pub-actu-' + id); if (f) { f.submit(); } };
            if (window.modernConfirm) {
                window.modernConfirm('Publier cette actualité ? Une notification sera envoyée aux membres.', submit, {
                    bouton: 'Publier',
                    extra: { label: '🟢 Publier + WhatsApp', style: 'background:#25D366;border:1px solid #25D366;color:#053b21;font-weight:700;', onOk: function () { try { window.open(waLink, '_blank'); } catch (e) {} submit(); } }
                });
            } else { submit(); }
        }
    </script>
    <script>
    (function () {
        var contenu = document.getElementById('ac-contenu');
        var list = document.getElementById('ac-mention-list');
        var chipsBox = document.getElementById('ac-chips');
        var mentionsInput = document.getElementById('ac-mentions');
        var selectedMentions = []; // {id, nom}

        function renderChips() {
            chipsBox.innerHTML = '';
            selectedMentions.forEach(function (m, i) {
                var chip = document.createElement('span');
                chip.className = 'ac-chip';
                chip.innerHTML = '@' + m.nom.replace(/</g, '&lt;');
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = '✕';
                btn.addEventListener('click', function () { selectedMentions.splice(i, 1); syncMentions(); });
                chip.appendChild(btn);
                chipsBox.appendChild(chip);
            });
            mentionsInput.value = selectedMentions.map(function (m) { return m.id; }).join(',');
        }
        function syncMentions() { renderChips(); }
        function addMention(m) {
            if (!selectedMentions.some(function (x) { return x.id === m.id; })) { selectedMentions.push(m); }
            syncMentions();
        }

        // Détection du token @ en cours de saisie
        var activeIndex = -1, matches = [];
        function currentQuery() {
            var pos = contenu.selectionStart;
            var upto = contenu.value.slice(0, pos);
            var at = upto.lastIndexOf('@');
            if (at === -1) { return null; }
            // pas d'espace/nouvelle ligne entre @ et le curseur
            var frag = upto.slice(at + 1);
            if (/[\n\r]/.test(frag) || frag.length > 30) { return null; }
            return { at: at, term: frag };
        }
        function closeList() { list.style.display = 'none'; activeIndex = -1; matches = []; }
        function showList(q) {
            var term = (q.term || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
            matches = AC_MEMBRES.filter(function (m) {
                return m.nom.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').indexOf(term) !== -1;
            }).slice(0, 8);
            if (matches.length === 0) { closeList(); return; }
            list.innerHTML = '';
            matches.forEach(function (m, i) {
                var it = document.createElement('div');
                it.className = 'ac-mention-item' + (i === 0 ? ' active' : '');
                it.textContent = '@' + m.nom;
                it.addEventListener('mousedown', function (e) { e.preventDefault(); pick(m, q); });
                list.appendChild(it);
            });
            activeIndex = 0;
            list.style.display = 'block';
        }
        function pick(m, q) {
            var pos = contenu.selectionStart;
            var before = contenu.value.slice(0, q.at);
            var after = contenu.value.slice(pos);
            var insert = '@' + m.nom + ' ';
            contenu.value = before + insert + after;
            var newPos = (before + insert).length;
            contenu.setSelectionRange(newPos, newPos);
            addMention(m);
            closeList();
            contenu.focus();
        }
        contenu.addEventListener('input', function () {
            var q = currentQuery();
            if (q === null) { closeList(); return; }
            showList(q);
        });
        contenu.addEventListener('keydown', function (e) {
            if (list.style.display !== 'block') { return; }
            var items = list.querySelectorAll('.ac-mention-item');
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(activeIndex + 1, items.length - 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0); }
            else if (e.key === 'Enter' || e.key === 'Tab') {
                if (matches[activeIndex]) { e.preventDefault(); var q = currentQuery(); if (q) { pick(matches[activeIndex], q); } }
                return;
            } else if (e.key === 'Escape') { closeList(); return; }
            items.forEach(function (it, i) { it.classList.toggle('active', i === activeIndex); });
        });
        document.addEventListener('click', function (e) { if (!list.contains(e.target) && e.target !== contenu) { closeList(); } });

        // Édition d'un brouillon : pré-remplir le formulaire
        var editor = document.getElementById('ac-editor');
        document.querySelectorAll('.ac-edit').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('ac-id').value = btn.getAttribute('data-id');
                document.getElementById('ac-type').value = btn.getAttribute('data-type') || '';
                document.getElementById('ac-titre').value = btn.getAttribute('data-titre') || '';
                contenu.value = btn.getAttribute('data-contenu') || '';
                // reconstruire les chips de mention
                selectedMentions = [];
                (btn.getAttribute('data-mentions') || '').split(',').forEach(function (idStr) {
                    var id = parseInt(idStr, 10);
                    if (!id) { return; }
                    var m = AC_MEMBRES.find(function (x) { return x.id === id; });
                    if (m) { selectedMentions.push(m); }
                });
                syncMentions();
                if (editor && !editor.open) { editor.open = true; }
                editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        // Réinitialiser le formulaire (nouveau brouillon vierge)
        function acResetForm() {
            document.getElementById('ac-id').value = '';
            document.getElementById('ac-type').value = '';
            document.getElementById('ac-titre').value = '';
            contenu.value = '';
            var frm = document.getElementById('ac-form');
            if (frm) { var fi = frm.querySelectorAll('input[type="file"]'); fi.forEach(function (x) { x.value = ''; x.dispatchEvent(new Event('change', { bubbles: true })); }); }
            selectedMentions = [];
            syncMentions();
        }
        var resetBtn = document.getElementById('ac-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () { acResetForm(); contenu.focus(); });
        }
        // Bouton « Nouvelle actualité » : ouvre l'éditeur vierge en haut
        var newBtn = document.getElementById('ac-new-btn');
        if (newBtn) {
            newBtn.addEventListener('click', function () {
                acResetForm();
                if (editor && !editor.open) { editor.open = true; }
                if (editor) { editor.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                document.getElementById('ac-titre').focus();
            });
        }
    })();
    </script>
<script src="modern-file.js" defer></script>
</body>
</html>
