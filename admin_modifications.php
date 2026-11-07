<?php
/**
 * Touba Lyon 2026 - ✏️ Modifications de profil à valider
 *
 * Toute modification des données d'un membre validé (même un simple changement de photo) attend la validation
 * d'un administrateur. Cette page compare l'ancien et le nouveau profil, puis valide ou refuse la demande.
 * Réservé aux administrateurs connectés.
 */
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/email_unique_helper.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/profil_modification_helper.php';

$error = '';
$success = '';
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'valider') {
                $res = profil_modif_valider($pdo, $id);
            } elseif ($action === 'refuser') {
                $res = profil_modif_refuser($pdo, $id, trim((string) ($_POST['motif'] ?? '')));
            } else {
                $res = ['ok' => false, 'msg' => 'Action non reconnue.'];
            }
            if ($res['ok']) { $success = $res['msg']; } else { $error = $res['msg']; }
        } catch (Exception $e) {
            error_log('Touba Lyon admin_modifications : ' . $e->getMessage());
            $error = "Une erreur technique est survenue.";
        }
    }
}

// Demandes en attente, avec le profil actuel du membre
$demandes = [];
try {
    $q = $pdo->query("SELECT mo.*, m.civilite, m.prenom, m.nom, m.email, m.telephone, m.adresse, m.code_postal, m.commune, m.statut, m.secteur_activite,
                             m.profession, m.annee_integration, m.souhait_commission, m.commentaires, m.photo_path AS photo_actuelle
                      FROM membre_modifications mo JOIN membres m ON m.id = mo.membre_id
                      WHERE mo.statut = 'attente' ORDER BY mo.id ASC");
    $demandes = $q->fetchAll();
} catch (Exception $e) { $demandes = []; }
$champs = profil_champs();
$vignette = function (?string $f) use ($h) {
    $f = trim((string) $f);
    return $f !== ''
        ? '<img src="uploads/' . $h(rawurlencode($f)) . '" alt="" style="width:72px; height:72px; border-radius:50%; object-fit:cover; border:2px solid var(--accent);">'
        : '<span style="display:inline-flex; width:72px; height:72px; border-radius:50%; align-items:center; justify-content:center; background:rgba(212,175,55,0.12); color:var(--gold); font-size:1.6rem;">👤</span>';
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>✏️ Modifications de profil — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .mo-wrap { max-width: 900px; margin: 0 auto; }
        .mo-card { border: 1px solid rgba(212,175,55,0.38); border-radius: 12px; background: rgba(255,255,255,0.03); padding: 1rem 1.2rem; margin-bottom: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.16); }
        .mo-tete { display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap; margin-bottom: 0.8rem; }
        .mo-nom { color: var(--white); font-weight: 800; font-size: 1.05rem; }
        .mo-date { color: var(--text-muted); font-size: 0.8rem; }
        .mo-photos { display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap; margin-bottom: 0.8rem; color: var(--text-muted); font-size: 0.82rem; }
        .dlist { margin-bottom: 0.9rem; }
        .drow .dcell { font-size: 0.86rem; }
        .drow .ancien { flex: 1 1 140px; color: var(--text-muted); text-decoration: line-through; text-decoration-color: rgba(252,165,165,0.6); }
        .drow .nouveau { flex: 1 1 140px; color: #7bd8a6; font-weight: 700; }
        .mo-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: flex-end; }
        .mo-actions input[type=text] { flex: 1 1 220px; min-width: 0; }
        .mo-actions form { margin: 0; display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/admin_menu.php'; ?>
            <div class="dashboard-main">
            <div class="mo-wrap">
                <div class="admin-welcome-banner glass-card" style="margin-bottom:1.2rem; padding:1.1rem 1.5rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:0.8rem;">
                    <span>✏️ Modifications de profil à valider — <strong class="gold-text"><?php echo count($demandes); ?></strong></span>
                </div>
                <p style="color:var(--text-muted); font-size:0.88rem; margin:0 0 1rem; line-height:1.55;">Toute modification des données d'un membre validé, même un simple changement de photo, attend votre validation. Tant qu'elle n'est pas validée, le profil garde ses anciennes données et le membre conserve son accès.</p>
                <?php if ($success !== ''): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($success); ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($error); ?></div><?php endif; ?>

                <?php if (empty($demandes)): ?>
                    <div class="empty-state"><div class="empty-state-icon">✅</div><p>Aucune modification en attente.</p></div>
                <?php endif; ?>

                <?php foreach ($demandes as $d):
                    $nouv = json_decode((string) $d['donnees'], true);
                    if (!is_array($nouv)) { $nouv = []; }
                    $nomM = trim(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));
                ?>
                <div class="mo-card">
                    <div class="mo-tete">
                        <div>
                            <div class="mo-nom">👤 <?php echo $h($nomM); ?></div>
                            <div class="mo-date">Demandé le <?php echo $h(date('d/m/Y à H:i', strtotime((string) $d['created_at']))); ?> · <a href="profile.php?id=<?php echo (int) $d['membre_id']; ?>" style="color:var(--accent);">voir la fiche</a></div>
                        </div>
                    </div>
                    <?php if (!empty($d['photo_path'])): ?>
                        <div class="mo-photos">
                            <span>Photo actuelle</span><?php echo $vignette($d['photo_actuelle']); ?>
                            <span style="font-size:1.4rem;">→</span>
                            <span style="color:#7bd8a6; font-weight:700;">Nouvelle photo</span><?php echo $vignette($d['photo_path']); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($nouv)): ?>
                    <div class="dlist">
                        
                        
                        <?php foreach ($nouv as $k => $v): if (!isset($champs[$k])) { continue; } $anc = trim((string) ($d[$k] ?? '')); ?>
                            <div class="drow">
                                <div class="dcell dgrow"><?php echo $h($champs[$k]); ?></div>
                                <div class="dcell ancien"><?php echo $anc !== '' ? $h($anc) : '—'; ?></div>
                                <div class="dcell nouveau"><?php echo trim((string) $v) !== '' ? $h($v) : '—'; ?></div>
                            </div>
                        <?php endforeach; ?>
                        
                    </div>
                    <?php endif; ?>
                    <div class="mo-actions">
                        <form method="POST" action="admin_modifications.php" data-confirm="Valider ces modifications ? Elles seront appliquées au profil de <strong><?php echo $h($nomM); ?></strong>.">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="valider">
                            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                            <button type="submit" class="btn btn-primary btn-sm">✅ Valider</button>
                        </form>
                        <form method="POST" action="admin_modifications.php" data-confirm="Refuser ces modifications ? Le membre sera prévenu et ses données resteront inchangées." data-confirm-danger="1">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="refuser">
                            <input type="hidden" name="id" value="<?php echo (int) $d['id']; ?>">
                            <input type="text" name="motif" class="form-input" maxlength="255" placeholder="Motif du refus (facultatif)">
                            <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;">🚫 Refuser</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/modern_popup.php'; ?>
</body>
</html>
