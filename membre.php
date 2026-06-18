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

// Chargement du membre
try {
    $stmt = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
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

                <!-- Identité -->
                <div class="info-section glass-card">
                    <h2>Identité</h2>
                    <?php
                        info_row('Nom de famille', $m['nom'], true);
                        info_row('Prénom', $m['prenom'], true);
                        info_row('Civilité', $m['civilite']);
                        info_row('Genre', $m['genre'] ?? '');
                    ?>
                </div>

                <!-- Coordonnées -->
                <div class="info-section glass-card">
                    <h2>Coordonnées</h2>
                    <?php
                        info_row('Email', $m['email'], true);
                        info_row('Téléphone', $m['telephone'] ?? '');
                        info_row('Adresse', $m['adresse'] ?? '');
                        info_row('Code postal', $m['code_postal'] ?? '');
                        info_row('Commune', $m['commune'] ?? '');
                    ?>
                </div>

                <?php if ($isAdhesion): ?>
                <!-- Adhésion Dahira -->
                <div class="info-section glass-card">
                    <h2>Adhésion au Dahira</h2>
                    <?php
                        info_row("Type d'adhésion", $m['type_adhesion']);
                        info_row('Test Kourel', $m['test_kourel'] ?? '');
                        info_row('Statut', $m['statut'] ?? '');
                        info_row("Secteur d'activité", $m['secteur_activite'] ?? '');
                        info_row('Profession', $m['profession'] ?? '');
                        info_row("Année d'intégration", $m['annee_integration'] ?? '');
                        info_row('Charte acceptée', !empty($m['charte_acceptee']) ? 'Oui' : 'Non', true);
                        info_row('Commentaires', $m['commentaires'] ?? '');
                    ?>
                </div>
                <?php endif; ?>

                <!-- Compte -->
                <div class="info-section glass-card">
                    <h2>Compte</h2>
                    <?php
                        info_row('Statut du dossier', ucfirst($m['status']), true);
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
