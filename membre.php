<?php
/**
 * Touba Lyon 2026 - Fiche membre (visualisation complète, sans popup)
 * Réservé aux administrateurs connectés.
 */
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dahira_emails.php';

$id = intval($_GET['id'] ?? 0);
$error = '';
$success = '';

// Traitement des actions (valider / suspendre / supprimer)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        $pid = intval($_POST['member_id'] ?? 0);
        if ($pid > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
                $stmt->execute([$pid]);
                $m = $stmt->fetch();
                if ($m) {
                    if ($action === 'approve') {
                        $pdo->prepare("UPDATE membres SET status = 'approved' WHERE id = ?")->execute([$pid]);
                        @send_validation_email($m['email'], $m['prenom'] . ' ' . $m['nom']);
                        $success = "Le membre a été validé. Un e-mail de confirmation lui a été envoyé.";
                    } elseif ($action === 'suspend') {
                        $pdo->prepare("UPDATE membres SET status = 'pending' WHERE id = ?")->execute([$pid]);
                        $success = "Le membre a été remis en attente.";
                    } elseif ($action === 'delete') {
                        if (!empty($m['photo_path'])) {
                            $photoFile = __DIR__ . '/uploads/' . $m['photo_path'];
                            if (is_file($photoFile)) { @unlink($photoFile); }
                        }
                        $pdo->prepare("DELETE FROM membres WHERE id = ?")->execute([$pid]);
                        // Retour à la liste appropriée après suppression
                        $back = !empty($m['type_adhesion']) ? 'admin_adhesions.php' : 'admin_dashboard.php';
                        header('Location: ' . $back . '?deleted=1');
                        exit;
                    } elseif ($action === 'update') {
                        // Modification des informations : le membre repasse EN ATTENTE.
                        $nom = trim($_POST['nom'] ?? '');
                        $prenom = trim($_POST['prenom'] ?? '');
                        $email = trim($_POST['email'] ?? '');
                        $civilite = (trim($_POST['civilite'] ?? '') === 'Sokhna') ? 'Sokhna' : 'Goor Yalla';
                        if ($nom === '' || $prenom === '' || $email === '') {
                            $error = "Nom, prénom et e-mail sont obligatoires.";
                        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $error = "L'adresse e-mail n'est pas valide.";
                        } else {
                            $chk = $pdo->prepare("SELECT id FROM membres WHERE email = ? AND id <> ?");
                            $chk->execute([$email, $pid]);
                            if ($chk->fetch()) {
                                $error = "Cette adresse e-mail est déjà utilisée par un autre membre.";
                            } else {
                                $sql = "UPDATE membres SET civilite=?, nom=?, prenom=?, genre=?, email=?, telephone=?, adresse=?, code_postal=?, commune=?, type_adhesion=?, statut=?, secteur_activite=?, profession=?, test_kourel=?, annee_integration=?, commentaires=?, charte_acceptee=?, status='pending' WHERE id=?";
                                $pdo->prepare($sql)->execute([
                                    $civilite, $nom, $prenom, (trim($_POST['genre'] ?? '') ?: null), $email,
                                    (trim($_POST['telephone'] ?? '') ?: null), (trim($_POST['adresse'] ?? '') ?: null),
                                    (trim($_POST['code_postal'] ?? '') ?: null), (trim($_POST['commune'] ?? '') ?: null),
                                    (trim($_POST['type_adhesion'] ?? '') ?: null), (trim($_POST['statut'] ?? '') ?: null),
                                    (trim($_POST['secteur_activite'] ?? '') ?: null), (trim($_POST['profession'] ?? '') ?: null),
                                    (trim($_POST['test_kourel'] ?? '') ?: null), (trim($_POST['annee_integration'] ?? '') ?: null),
                                    (trim($_POST['commentaires'] ?? '') ?: null), (isset($_POST['charte_acceptee']) ? 1 : 0), $pid
                                ]);
                                $success = "Modifications enregistrées. Le membre est repassé en attente de validation.";
                            }
                        }
                    }
                } else {
                    $error = "Membre introuvable.";
                }
            } catch (Exception $e) {
                error_log('Touba Lyon membre: ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
}

// Chargement du membre (avec le nom de l'intégrateur assigné)
try {
    $stmt = $pdo->prepare("SELECT m.*, TRIM(CONCAT(COALESCE(i.prenom,''), ' ', i.nom)) AS integrateur_nom FROM membres m LEFT JOIN membres i ON m.integrateur_id = i.id WHERE m.id = ?");
    $stmt->execute([$id]);
    $m = $stmt->fetch();
} catch (Exception $e) {
    error_log('Touba Lyon membre (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

$isAdhesion = $m && !empty($m['type_adhesion']);
$backLink = $isAdhesion ? 'admin_adhesions.php' : 'admin_dashboard.php';
$backLabel = $isAdhesion ? 'Inscriptions Dahira' : 'Tableau de bord';
$STATUTS = ['Professionnel', 'Etudiant', 'Alternant'];
// Mode lecture seule (bouton "Voir") : profil non editable.
$readonly = isset($_GET['view']) && $_GET['view'] === '1';
try { $secteursList = $pdo->query("SELECT nom FROM secteurs ORDER BY nom ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $secteursList = []; }

/** Ligne d'information (n'affiche rien si la valeur est vide). */
function info_row($label, $value, $alwaysShow = false) {
    if (!$alwaysShow && ($value === null || $value === '')) return;
    $display = ($value === null || $value === '') ? '—' : nl2br(htmlspecialchars($value));
    echo '<div class="info-row"><span class="info-label">' . htmlspecialchars($label) . '</span><span class="info-value">' . $display . '</span></div>';
}

function status_badge($status) {
    if ($status === 'approved') return '<span class="badge badge-approved">Validé</span>';
    if ($status === 'rejected') return '<span class="badge badge-danger" style="background:rgba(191,33,33,0.15); color:var(--danger); border:1px solid var(--danger);">Refusé</span>';
    return '<span class="badge badge-pending">En attente</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiche membre - Administration</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .membre-wrap { max-width: 760px; margin: 2rem auto; }
        .membre-head {
            display: flex; align-items: center; gap: 1.75rem;
            padding: 2rem; margin-bottom: 1.5rem; flex-wrap: wrap;
        }
        .membre-photo {
            width: 130px; height: 130px; border-radius: 50%;
            overflow: hidden; border: 3px solid var(--accent);
            box-shadow: 0 4px 15px rgba(0,0,0,0.4); flex-shrink: 0;
            background: rgba(255,255,255,0.05);
            display: flex; align-items: center; justify-content: center;
        }
        .membre-photo img { width: 100%; height: 100%; object-fit: cover; }
        .membre-head-info h1 { font-size: 1.8rem; color: var(--white); margin-bottom: 0.4rem; }
        .membre-head-meta { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .info-section { padding: 1.75rem 2rem; margin-bottom: 1.5rem; }
        .info-section h2 {
            font-size: 1.1rem; color: var(--gold); margin-bottom: 1rem;
            padding-bottom: 0.5rem; border-bottom: 1px solid var(--glass-border);
        }
        .info-row {
            display: flex; gap: 1rem; padding: 0.6rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.95rem;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: var(--text-muted); min-width: 200px; flex-shrink: 0; }
        .info-value { color: var(--white); font-weight: 500; word-break: break-word; }
        .membre-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .del-confirm { display: none; align-items: center; gap: 0.5rem; }
        .del-confirm.show { display: inline-flex; }
        @media (max-width: 560px) {
            .membre-head { flex-direction: column; text-align: center; }
            .info-row { flex-direction: column; gap: 0.15rem; }
            .info-label { min-width: 0; }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/admin_menu.php'; ?>
            <div class="dashboard-main">
        <div class="membre-wrap">

            <div style="margin-bottom: 1.25rem;">
                <a href="<?php echo $backLink; ?>" style="color: var(--text-muted); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"></path></svg>
                    Retour : <?php echo htmlspecialchars($backLabel); ?>
                </a>
            </div>

            <?php if (!$m): ?>
                <div class="form-card" style="text-align:center;">
                    <div class="empty-state">
                        <div class="empty-state-icon">🔍</div>
                        <h2>Membre introuvable</h2>
                        <p style="color: var(--text-muted); margin-top: 0.5rem;">Ce membre n'existe pas ou a été supprimé.</p>
                    </div>
                </div>
            <?php else: ?>

                <!-- En-tête : photo + nom + statut -->
                <div class="membre-head glass-card">
                    <div class="membre-photo">
                        <?php if (!empty($m['photo_path'])): ?>
                            <img src="uploads/<?php echo htmlspecialchars($m['photo_path']); ?>" alt="Photo de <?php echo htmlspecialchars($m['prenom']); ?>">
                        <?php else: ?>
                            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="membre-head-info">
                        <h1><?php echo htmlspecialchars($m['prenom'] . ' ' . $m['nom']); ?></h1>
                        <p style="color: var(--text-muted);"><?php echo htmlspecialchars($m['email']); ?></p>
                        <div class="membre-head-meta">
                            <?php echo status_badge($m['status']); ?>
                            <?php if (!empty($m['type_adhesion'])): ?>
                                <span class="badge" style="background:rgba(212,175,55,0.15); color:var(--gold); border:1px solid var(--gold);"><?php echo htmlspecialchars($m['type_adhesion']); ?></span>
                            <?php endif; ?>
                            <span class="badge badge-approved" style="background:rgba(27,67,50,0.25);">🏆 <?php echo (int)$m['score']; ?> pts</span>
                        </div>
                    </div>
                </div>

                <?php if ($readonly): ?>
                    <style>
                        fieldset:disabled .form-input,
                        fieldset:disabled select.form-input,
                        fieldset:disabled textarea.form-input { opacity:1; color:var(--white); -webkit-text-fill-color:var(--white); cursor:default; background:rgba(255,255,255,0.04); }
                        fieldset:disabled input[type="checkbox"] { opacity:0.85; }
                    </style>
                    <div class="glass-card" style="display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:0.7rem 1rem; margin-bottom:1rem; border-left:3px solid var(--gold);">
                        <span style="color:var(--text-muted); font-size:0.9rem;">👁️ Profil en lecture seule</span>
                        <a href="membre.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-primary btn-sm">✏️ Modifier</a>
                    </div>
                <?php endif; ?>

                <!-- Formulaire d'édition : toute modification repasse le membre EN ATTENTE de validation -->
                <form method="POST" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                    <fieldset <?php echo $readonly ? 'disabled' : ''; ?> style="border:0; padding:0; margin:0; min-width:0;">

                    <!-- Identité -->
                    <div class="info-section glass-card">
                        <h2>Identité</h2>
                        <div class="form-group"><label class="form-label">Nom de famille</label><input class="form-input" name="nom" value="<?php echo htmlspecialchars($m['nom']); ?>" required></div>
                        <div class="form-group"><label class="form-label">Prénom</label><input class="form-input" name="prenom" value="<?php echo htmlspecialchars($m['prenom']); ?>" required></div>
                        <div class="form-group"><label class="form-label">Civilité</label>
                            <select class="form-input" name="civilite">
                                <option value="Goor Yalla" <?php echo ($m['civilite'] !== 'Sokhna') ? 'selected' : ''; ?>>Goor Yalla (Homme)</option>
                                <option value="Sokhna" <?php echo ($m['civilite'] === 'Sokhna') ? 'selected' : ''; ?>>Sokhna (Femme)</option>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Genre</label>
                            <select class="form-input" name="genre">
                                <option value="">—</option>
                                <option value="Homme" <?php echo (($m['genre'] ?? '') === 'Homme') ? 'selected' : ''; ?>>Homme</option>
                                <option value="Femme" <?php echo (($m['genre'] ?? '') === 'Femme') ? 'selected' : ''; ?>>Femme</option>
                            </select>
                        </div>
                    </div>

                    <!-- Coordonnées -->
                    <div class="info-section glass-card">
                        <h2>Coordonnées</h2>
                        <div class="form-group"><label class="form-label">Email</label><input class="form-input" type="email" name="email" value="<?php echo htmlspecialchars($m['email']); ?>" required></div>
                        <div class="form-group"><label class="form-label">Téléphone</label><input class="form-input" name="telephone" value="<?php echo htmlspecialchars($m['telephone'] ?? ''); ?>"></div>
                        <div class="form-group"><label class="form-label">Adresse</label><input class="form-input" name="adresse" value="<?php echo htmlspecialchars($m['adresse'] ?? ''); ?>"></div>
                        <div class="form-group"><label class="form-label">Code postal</label><input class="form-input" name="code_postal" value="<?php echo htmlspecialchars($m['code_postal'] ?? ''); ?>"></div>
                        <div class="form-group"><label class="form-label">Commune</label><input class="form-input" name="commune" value="<?php echo htmlspecialchars($m['commune'] ?? ''); ?>"></div>
                    </div>

                    <!-- Adhésion & profil (hors suivi intégration) -->
                    <div class="info-section glass-card">
                        <h2>Adhésion au Dahira</h2>
                        <div class="form-group"><label class="form-label">Type d'adhésion</label>
                            <select class="form-input" name="type_adhesion">
                                <option value="">—</option>
                                <?php foreach (['Membre actif', 'Membre sympathisant'] as $tt): ?>
                                    <option value="<?php echo $tt; ?>" <?php echo (($m['type_adhesion'] ?? '') === $tt) ? 'selected' : ''; ?>><?php echo $tt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Statut (activité)</label>
                            <select class="form-input" name="statut">
                                <option value="">—</option>
                                <?php foreach ($STATUTS as $st): ?>
                                    <option value="<?php echo $st; ?>" <?php echo (($m['statut'] ?? '') === $st) ? 'selected' : ''; ?>><?php echo $st; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Secteur d'activité</label>
                            <select class="form-input" name="secteur_activite">
                                <option value="">—</option>
                                <?php foreach ($secteursList as $sName): ?>
                                    <option value="<?php echo htmlspecialchars($sName); ?>" <?php echo (($m['secteur_activite'] ?? '') === $sName) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sName); ?></option>
                                <?php endforeach; ?>
                                <?php if (!empty($m['secteur_activite']) && !in_array($m['secteur_activite'], $secteursList, true)): ?>
                                    <option value="<?php echo htmlspecialchars($m['secteur_activite']); ?>" selected><?php echo htmlspecialchars($m['secteur_activite']); ?> (ancienne valeur)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Profession</label><input class="form-input" name="profession" value="<?php echo htmlspecialchars($m['profession'] ?? ''); ?>"></div>
                        <div class="form-group"><label class="form-label">Test Kourel</label>
                            <select class="form-input" name="test_kourel">
                                <option value="">—</option>
                                <option value="Oui" <?php echo (($m['test_kourel'] ?? '') === 'Oui') ? 'selected' : ''; ?>>Oui</option>
                                <option value="Non" <?php echo (($m['test_kourel'] ?? '') === 'Non') ? 'selected' : ''; ?>>Non</option>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Année d'intégration</label><input class="form-input" name="annee_integration" value="<?php echo htmlspecialchars($m['annee_integration'] ?? ''); ?>"></div>
                        <div class="form-group"><label class="form-label">Commentaires</label><textarea class="form-input" name="commentaires" rows="2"><?php echo htmlspecialchars($m['commentaires'] ?? ''); ?></textarea></div>
                        <div class="form-group" style="display:flex; align-items:center; gap:0.5rem;">
                            <input type="checkbox" id="charte" name="charte_acceptee" value="1" <?php echo !empty($m['charte_acceptee']) ? 'checked' : ''; ?>>
                            <label for="charte" class="form-label" style="margin:0;">Charte acceptée</label>
                        </div>
                        <?php if (!$readonly): ?>
                        <button type="submit" class="btn btn-primary" style="width:100%; margin-top:0.5rem;">💾 Enregistrer (repasse en attente)</button>
                        <?php endif; ?>
                    </div>
                    </fieldset>
                </form>

                <!-- Compte -->
                <div class="info-section glass-card">
                    <h2>Compte</h2>
                    <?php
                        $statutFr = ['approved' => 'Validé', 'pending' => 'En attente', 'rejected' => 'Refusé'];
                        info_row('Statut du dossier', $statutFr[$m['status']] ?? ucfirst($m['status']), true);
                        info_row('Score Ki Kan La', $m['score'] . ' pts', true);
                        info_row("Date d'inscription", date('d/m/Y à H:i', strtotime($m['created_at'])), true);
                    ?>
                </div>

                <!-- Actions -->
                <div class="info-section glass-card">
                    <h2>Actions</h2>
                    <div class="membre-actions">
                        <?php if ($m['status'] !== 'approved'): ?>
                            <form method="POST" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                                <button type="submit" class="btn btn-primary btn-sm" style="background:var(--success); box-shadow:none;">✓ Valider</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="suspend">
                                <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">Suspendre</button>
                            </form>
                        <?php endif; ?>

                        <!-- Suppression avec confirmation INLINE (pas de popup) -->
                        <button type="button" class="btn btn-danger btn-sm" id="del-trigger" onclick="document.getElementById('del-trigger').style.display='none'; document.getElementById('del-confirm').classList.add('show');">Supprimer</button>
                        <span class="del-confirm" id="del-confirm">
                            <span style="color: var(--danger); font-size: 0.9rem;">Confirmer la suppression définitive ?</span>
                            <form method="POST" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Oui, supprimer</button>
                            </form>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('del-confirm').classList.remove('show'); document.getElementById('del-trigger').style.display='inline-flex';">Annuler</button>
                        </span>
                    </div>
                </div>

            <?php endif; ?>
        </div>
            </div>
        </div>
    </main>

    <?php if (!empty($success) || !empty($error)): ?>
    <div style="position:fixed; left:50%; transform:translateX(-50%); bottom:2rem; z-index:200; max-width:90%;">
        <div class="glass-card" style="padding:1rem 1.5rem; border:1px solid <?php echo !empty($success) ? 'var(--accent)' : 'var(--danger)'; ?>;">
            <span style="color: <?php echo !empty($success) ? 'var(--accent)' : 'var(--danger)'; ?>; font-weight:600;">
                <?php echo htmlspecialchars(!empty($success) ? $success : $error); ?>
            </span>
        </div>
    </div>
    <?php endif; ?>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>
</body>
</html>
