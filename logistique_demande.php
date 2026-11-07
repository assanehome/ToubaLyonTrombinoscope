<?php
/**
 * Touba Lyon 2026 - Demande de logistique (responsables de commission).
 *
 * Un responsable de commission demande du matériel ou des personnes à transporter (camion ou voiture normale),
 * avec la description du besoin, l'adresse d'origine (KST, Box Bron ou autre) et l'adresse de destination.
 * La demande est envoyée (notification, sans e-mail) aux responsables de la commission Logistique.
 */
require_once __DIR__ . '/commission_guard.php'; // $__isAdmin, $__managedCommissions, $pdo
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/stock_logistique_helper.php';

$error = '';
$success = $_SESSION['logi_flash'] ?? '';
unset($_SESSION['logi_flash']);
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
$moi = (int) ($_SESSION['player_id'] ?? 0);

// Commissions que l'utilisateur peut représenter
$mesCommissions = [];
try {
    if ($__isAdmin) { $mesCommissions = $pdo->query("SELECT id, nom FROM commissions ORDER BY nom ASC")->fetchAll(); }
    elseif (!empty($__managedCommissions)) {
        $in = implode(',', array_fill(0, count($__managedCommissions), '?'));
        $q = $pdo->prepare("SELECT id, nom FROM commissions WHERE id IN ($in) ORDER BY nom ASC");
        $q->execute($__managedCommissions);
        $mesCommissions = $q->fetchAll();
    }
} catch (Exception $e) { $mesCommissions = []; }
$idsAutorises = array_map(function ($c) { return (int) $c['id']; }, $mesCommissions);
$logiId = logistique_commission_id($pdo);
$respLogi = $logiId > 0 ? stock_responsables_commission($pdo, $logiId) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) { $error = "Échec de validation de sécurité. Veuillez réessayer."; }
    else {
        $cid = (int) ($_POST['commission_id'] ?? 0);
        $type = array_key_exists($_POST['type'] ?? '', logistique_types()) ? $_POST['type'] : '';
        $veh = array_key_exists($_POST['vehicule'] ?? '', logistique_vehicules()) ? $_POST['vehicule'] : '';
        $desc = trim((string) ($_POST['description'] ?? ''));
        $depart = array_key_exists($_POST['depart'] ?? '', logistique_departs()) ? $_POST['depart'] : '';
        $departAutre = trim((string) ($_POST['depart_autre'] ?? ''));
        $destCle = array_key_exists($_POST['destination'] ?? '', logistique_departs()) ? $_POST['destination'] : '';
        $destAutre = trim((string) ($_POST['destination_autre'] ?? ''));
        $dest = $destCle === 'autre' ? $destAutre : ($destCle !== '' ? logistique_departs()[$destCle] : '');
        $departTxt = $depart === 'autre' ? $departAutre : ($depart !== '' ? logistique_departs()[$depart] : '');
        $norm = function (string $s) { return mb_strtolower(preg_replace('/\s+/', ' ', trim($s))); };
        $dateB = trim((string) ($_POST['date_besoin'] ?? ''));
        $heureB = trim((string) ($_POST['heure_besoin'] ?? ''));
        if (!in_array($cid, $idsAutorises, true)) { $error = "Choisissez la commission qui fait la demande."; }
        elseif ($type === '') { $error = "Choisissez le type de demande (matériel ou personnes)."; }
        elseif ($veh === '') { $error = "Choisissez le type de véhicule."; }
        elseif ($desc === '') { $error = "Décrivez votre besoin."; }
        elseif ($depart === '') { $error = "Choisissez l'adresse d'origine."; }
        elseif ($depart === 'autre' && $departAutre === '') { $error = "Indiquez l'adresse d'origine."; }
        elseif ($destCle === '') { $error = "Choisissez l'adresse de destination."; }
        elseif ($destCle === 'autre' && $destAutre === '') { $error = "Indiquez l'adresse de destination."; }
        elseif ($norm($departTxt) === $norm($dest)) { $error = "L'adresse d'origine et l'adresse de destination doivent être différentes."; }
        elseif ($dateB !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateB)) { $error = "Date invalide."; }
        else {
            try {
                $quand = $dateB !== '' ? $dateB . ($heureB !== '' ? ' ' . mb_substr($heureB, 0, 10) : '') : null;
                $pdo->prepare("INSERT INTO logistique_demandes (commission_id, demandeur_id, type, vehicule, description, depart, depart_autre, destination, date_besoin) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$cid, $moi, $type, $veh, $desc, $depart, $depart === 'autre' ? mb_substr($departAutre, 0, 255) : null, mb_substr($dest, 0, 255), $quand]);
                $nomCom = ''; foreach ($mesCommissions as $c) { if ((int) $c['id'] === $cid) { $nomCom = $c['nom']; } }
                foreach ($respLogi as $m) {
                    troba_notify_membre($pdo, (int) $m['id'], 'commission', '🚚 Nouvelle demande de logistique', $nomCom . ' : ' . mb_substr($desc, 0, 90), 'logistique_demandes.php');
                }
                $success = "Demande envoyée aux responsables de la commission Logistique" . (empty($respLogi) ? " (aucun responsable n'est encore désigné pour cette commission : la demande est enregistrée)." : ".");
                $_SESSION['logi_flash'] = $success;
                header('Location: logistique_demande.php');
                exit;
            } catch (Exception $e) {
                error_log('Touba Lyon logistique demande : ' . $e->getMessage());
                $error = "Une erreur technique est survenue.";
            }
        }
    }
}

// Mes demandes (celles de mes commissions)
$demandes = [];
try {
    if (!empty($idsAutorises)) {
        $in = implode(',', array_fill(0, count($idsAutorises), '?'));
        $q = $pdo->prepare("SELECT d.*, c.nom AS commission_nom FROM logistique_demandes d JOIN commissions c ON c.id = d.commission_id WHERE d.commission_id IN ($in) ORDER BY d.id DESC LIMIT 50");
        $q->execute($idsAutorises);
        $demandes = $q->fetchAll();
    }
} catch (Exception $e) {}
$csrf = csrf_field();
$post = $error !== '' ? $_POST : [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚚 Demande de logistique — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .lg-wrap { max-width: 860px; margin: 0 auto; }
        .lg-card { border: 1px solid rgba(212,175,55,0.38); border-radius: 12px; background: rgba(255,255,255,0.03); padding: 0.85rem 1rem; margin-bottom: 0.6rem; }
        .lg-top { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; }
        .lg-titre { color: var(--white); font-weight: 800; }
        .lg-meta { color: var(--text-muted); font-size: 0.83rem; margin-top: 0.3rem; line-height: 1.55; }
        .lg-sub { color: var(--text-muted); font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin: 1rem 0 0.4rem; }
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
                    <span>🚚 Demande de logistique</span>
                    <?php if ($__isAdmin || ($moi > 0 && logistique_est_responsable($pdo, $moi))): ?><a href="logistique_demandes.php" class="btn btn-secondary btn-sm">📋 Demandes reçues</a><?php endif; ?>
                </div>
                <?php if ($success !== ''): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($success); ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($error); ?></div><?php endif; ?>

                <?php
                // Demandes en cours en haut ; exécutées / clôturées dans un groupe fermé en bas
                $demandesActives = []; $demandesCloturees = [];
                foreach ($demandes as $dd) { if (in_array($dd['statut'], ['nouvelle', 'prise_en_compte'], true)) { $demandesActives[] = $dd; } else { $demandesCloturees[] = $dd; } }
                ?>
                <div class="lg-sub" style="margin-top:0;">📜 Demandes en cours (<?php echo count($demandesActives); ?>)</div>
                <?php if (empty($demandesActives)): ?><p style="color:var(--text-muted);"><?php echo empty($demandes) ? 'Aucune demande pour le moment.' : 'Aucune demande en cours.'; ?></p><?php endif; ?>
                <?php $carteDemande = function (array $d) use ($h) { ?>
                    <div class="lg-card">
                        <div class="lg-top">
                            <span class="lg-titre"><?php echo $h(logistique_types()[$d['type']] ?? $d['type']); ?> · <?php echo $h(logistique_vehicules()[$d['vehicule']] ?? $d['vehicule']); ?></span>
                            <span class="badge"><?php echo $h(logistique_statuts()[$d['statut']] ?? $d['statut']); ?></span>
                        </div>
                        <div class="lg-meta">
                            📋 <?php echo $h($d['commission_nom']); ?> · envoyée le <?php echo $h(date('d/m/Y', strtotime((string) $d['created_at']))); ?><br>
                            <?php echo nl2br($h($d['description'])); ?><br>
                            📍 <?php echo $h($d['depart'] === 'autre' ? $d['depart_autre'] : (logistique_departs()[$d['depart']] ?? $d['depart'])); ?> → <?php echo $h($d['destination']); ?>
                            <?php if (!empty($d['date_besoin'])): ?> · 🗓️ <?php echo $h($d['date_besoin']); ?><?php endif; ?>
                            <?php if (!empty($d['execute_le'])): ?><br>✅ Exécutée le <?php echo $h(date('d/m/Y à H:i', strtotime((string) $d['execute_le']))); ?><?php endif; ?>
                            <?php if (!empty($d['confirme_le'])): ?> · 👍 Confirmée par vous<?php endif; ?>
                            <?php if (trim((string) ($d['reponse'] ?? '')) !== ''): ?><br>💬 Réponse : <?php echo nl2br($h($d['reponse'])); ?><?php endif; ?>
                        </div>
                    </div>
                <?php }; ?>
                <?php foreach ($demandesActives as $d) { $carteDemande($d); } ?>

                <details class="glass-card" style="margin-top:1rem;"<?php echo ($error !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ? ' open' : ''; ?>>
                    <summary>➕ Nouvelle demande</summary>
                    <p style="color:var(--text-muted); font-size:0.85rem; margin:0.4rem 0 0.8rem;">Votre demande est envoyée aux responsables de la commission <strong>Logistique</strong> (notification).</p>
                    <form method="POST" action="logistique_demande.php">
                        <?php echo $csrf; ?>
                        <?php if (count($mesCommissions) > 1): ?>
                            <div class="form-group"><label class="form-label">Commission <span style="color:var(--danger)">*</span></label>
                                <select name="commission_id" class="form-input" required>
                                    <option value="">— Choisir une commission —</option>
                                    <?php foreach ($mesCommissions as $c): ?><option value="<?php echo (int) $c['id']; ?>"<?php echo (int) ($post['commission_id'] ?? 0) === (int) $c['id'] ? ' selected' : ''; ?>><?php echo $h($c['nom']); ?></option><?php endforeach; ?>
                                </select></div>
                        <?php elseif (count($mesCommissions) === 1): ?>
                            <input type="hidden" name="commission_id" value="<?php echo (int) $mesCommissions[0]['id']; ?>">
                            <p style="color:var(--text-muted); margin:0 0 0.8rem;">Commission : <strong style="color:var(--white);"><?php echo $h($mesCommissions[0]['nom']); ?></strong></p>
                        <?php endif; ?>
                        <div class="form-group"><label class="form-label">Type de demande <span style="color:var(--danger)">*</span></label>
                            <select name="type" class="form-input" required>
                                <option value="">— Choisir —</option>
                                <?php foreach (logistique_types() as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo ($post['type'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h($l); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="form-group"><label class="form-label">Type de véhicule <span style="color:var(--danger)">*</span></label>
                            <select name="vehicule" class="form-input" required>
                                <option value="">— Choisir —</option>
                                <?php foreach (logistique_vehicules() as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo ($post['vehicule'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h($l); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="form-group"><label class="form-label">Description du besoin <span style="color:var(--danger)">*</span></label>
                            <textarea name="description" class="form-input" rows="3" required placeholder="Ex : transport de 20 tables et 60 chaises pour le Gamou"><?php echo $h($post['description'] ?? ''); ?></textarea></div>
                        <div class="form-group"><label class="form-label">Adresse d'origine <span style="color:var(--danger)">*</span></label>
                            <select name="depart" id="lg-depart" class="form-input" required>
                                <option value="">— Choisir —</option>
                                <?php foreach (logistique_departs() as $k => $l): ?><option value="<?php echo $h($k); ?>"<?php echo ($post['depart'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h($l); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="form-group" id="lg-autre" style="display:none;"><label class="form-label">Adresse d'origine (à définir) <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="depart_autre" class="form-input" maxlength="255" value="<?php echo $h($post['depart_autre'] ?? ''); ?>" placeholder="Adresse complète"></div>
                        <div class="form-group"><label class="form-label">Adresse de destination <span style="color:var(--danger)">*</span></label>
                            <select name="destination" id="lg-dest" class="form-input" required>
                                <option value="">— Choisir —</option>
                                <?php foreach (logistique_departs() as $k => $l): ?><option value="<?php echo $h($k); ?>"<?php echo ($post['destination'] ?? '') === $k ? ' selected' : ''; ?>><?php echo $h($l); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="form-group" id="lg-dest-autre" style="display:none;"><label class="form-label">Adresse de destination (à définir) <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="destination_autre" class="form-input" maxlength="255" value="<?php echo $h($post['destination_autre'] ?? ''); ?>" placeholder="Adresse complète"></div>
                        <div class="form-group"><label class="form-label">Date souhaitée (facultatif)</label><input type="date" name="date_besoin" class="form-input" value="<?php echo $h($post['date_besoin'] ?? ''); ?>"></div>
                        <div class="form-group"><label class="form-label">Heure souhaitée (facultatif)</label><select name="heure_besoin" class="form-input"><option value="">— Heure —</option><?php $hb = (string) ($post['heure_besoin'] ?? ''); for ($hh = 0; $hh < 24; $hh++) { foreach (['00', '30'] as $mm) { $hv = sprintf('%02dh%s', $hh, $mm); echo '<option value="' . $hv . '"' . ($hb === $hv ? ' selected' : '') . '>' . $hv . '</option>'; } } ?></select></div>
                        <button type="submit" class="btn btn-primary">🚚 Envoyer la demande</button>
                    </form>
                </details>

                <?php if (!empty($demandesCloturees)): ?>
                <details class="glass-card" style="margin-top:1rem;">
                    <summary>✅ Demandes exécutées / clôturées (<?php echo count($demandesCloturees); ?>)</summary>
                    <?php foreach ($demandesCloturees as $d) { $carteDemande($d); } ?>
                </details>
                <?php endif; ?>
            </div>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        (function () {
            function $(id) { return document.getElementById(id); }
            function autre(sel, box) { if (!sel || !box) { return; } box.style.display = sel.value === 'autre' ? '' : 'none'; box.querySelector('input').required = (sel.value === 'autre'); }
            // une adresse prédéfinie choisie au début n'est plus proposée pour la destination (et inversement)
            function exclure(src, dst) {
                if (!src || !dst) { return; }
                var change = false;
                Array.prototype.forEach.call(dst.options, function (o) { o.disabled = (o.value !== '' && o.value !== 'autre' && o.value === src.value); });
                if (dst.value !== '' && dst.options[dst.selectedIndex] && dst.options[dst.selectedIndex].disabled) { dst.value = ''; change = true; }
                if (change) { dst.dispatchEvent(new Event('change', { bubbles: true })); }
            }
            function maj() { var s = $('lg-depart'), d = $('lg-dest'); autre(s, $('lg-autre')); autre(d, $('lg-dest-autre')); exclure(s, d); exclure(d, s); }
            document.addEventListener('change', function (e) { if (e.target.id === 'lg-depart' || e.target.id === 'lg-dest') { maj(); } });
            document.addEventListener('soft:updated', maj);
            maj();
        })();
    </script>
    <script src="modern-date.js" defer></script>
</body>
</html>
