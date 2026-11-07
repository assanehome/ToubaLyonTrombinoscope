<?php
/**
 * Touba Lyon 2026 - Réalisations d'une commission
 *
 * Trois onglets, pour les administrateurs et les responsables de la commission
 * (mêmes droits que le stock) :
 *   📊 Tableau de bord  indicateurs sur une période (par type, par mois)
 *   📝 Réalisations     ajouter, modifier, supprimer
 *   🏷️ Types            les types de réalisation de la commission, avec leur description
 * Le rapport PDF de la période est produit par commission_realisations_pdf.php.
 */
require_once __DIR__ . '/commission_guard.php'; // $__isAdmin, $__managedCommissions, $pdo
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/realisations_helper.php';

realisations_schema($pdo);

// Commission ciblée + droits
$cid = (int) ($_POST['commission_id'] ?? $_GET['id'] ?? 0);
$canManage = $__isAdmin || in_array($cid, $__managedCommissions, true);
$commission = null;
if ($cid > 0) {
    try {
        $st = $pdo->prepare("SELECT id, nom FROM commissions WHERE id = ?");
        $st->execute([$cid]);
        $commission = $st->fetch();
    } catch (Exception $e) {
        $commission = null;
    }
}
if (!$commission || !$canManage) {
    header('Location: commission_dashboard.php');
    exit;
}
$membre = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : null;

$onglets = ['tableau' => '📊 Tableau de bord', 'realisations' => '📝 Réalisations', 'types' => '🏷️ Types de réalisation'];
$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'tableau');
if (!isset($onglets[$tab])) { $tab = 'tableau'; }
[$du, $au] = realisations_periode($_GET + $_POST);

$error = '';
$success = $_SESSION['realisations_flash'] ?? '';
unset($_SESSION['realisations_flash']);

/** Retour sur la page après une action (évite le renvoi du formulaire). */
$retour = function (string $onglet, string $msg) use ($cid, $du, $au) {
    $_SESSION['realisations_flash'] = $msg;
    header('Location: commission_realisations.php?id=' . $cid . '&tab=' . $onglet . '&du=' . $du . '&au=' . $au);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Session expirée : rechargez la page.";
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            // ---- Types -------------------------------------------------------
            if ($action === 'add_type' || $action === 'update_type') {
                $nom = trim((string) ($_POST['nom'] ?? ''));
                $desc = trim((string) ($_POST['description'] ?? ''));
                if ($nom === '') { $error = "Le nom du type est obligatoire."; }
                elseif ($action === 'add_type') {
                    $pdo->prepare("INSERT INTO commission_realisation_types (commission_id, nom, description, created_by) VALUES (?,?,?,?)")
                        ->execute([$cid, mb_substr($nom, 0, 120), $desc !== '' ? $desc : null, $membre]);
                    $retour('types', "Type de réalisation ajouté.");
                } else {
                    $pdo->prepare("UPDATE commission_realisation_types SET nom = ?, description = ? WHERE id = ? AND commission_id = ?")
                        ->execute([mb_substr($nom, 0, 120), $desc !== '' ? $desc : null, (int) ($_POST['type_id'] ?? 0), $cid]);
                    $retour('types', "Type de réalisation modifié.");
                }
            } elseif ($action === 'toggle_type') {
                $pdo->prepare("UPDATE commission_realisation_types SET actif = 1 - actif WHERE id = ? AND commission_id = ?")
                    ->execute([(int) ($_POST['type_id'] ?? 0), $cid]);
                $retour('types', "Type mis à jour.");
            } elseif ($action === 'delete_type') {
                // Un type déjà utilisé ne se supprime pas : il se désactive (les réalisations gardent leur type)
                $tid = (int) ($_POST['type_id'] ?? 0);
                $n = $pdo->prepare("SELECT COUNT(*) FROM commission_realisations WHERE type_id = ? AND commission_id = ?");
                $n->execute([$tid, $cid]);
                if ((int) $n->fetchColumn() > 0) { $error = "Ce type est utilisé par des réalisations : désactivez-le plutôt."; }
                else {
                    $pdo->prepare("DELETE FROM commission_realisation_types WHERE id = ? AND commission_id = ?")->execute([$tid, $cid]);
                    $retour('types', "Type de réalisation supprimé.");
                }
            // ---- Réalisations ----------------------------------------------
            } elseif ($action === 'add_realisation' || $action === 'update_realisation') {
                $titre = trim((string) ($_POST['titre'] ?? ''));
                $date = trim((string) ($_POST['date_realisation'] ?? ''));
                $typeId = (int) ($_POST['type_id'] ?? 0);
                $lieu = trim((string) ($_POST['lieu'] ?? ''));
                $qte = trim((string) ($_POST['quantite'] ?? ''));
                $details = trim((string) ($_POST['details'] ?? ''));
                // Le type doit appartenir à la commission
                if ($typeId > 0) {
                    $ok = $pdo->prepare("SELECT 1 FROM commission_realisation_types WHERE id = ? AND commission_id = ?");
                    $ok->execute([$typeId, $cid]);
                    if ($ok->fetchColumn() === false) { $typeId = 0; }
                }
                if ($titre === '') { $error = "Le titre de la réalisation est obligatoire."; }
                elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $error = "La date de la réalisation est obligatoire."; }
                elseif ($qte !== '' && !ctype_digit($qte)) { $error = "La quantité doit être un nombre entier."; }
                else {
                    $valeurs = [$typeId > 0 ? $typeId : null, mb_substr($titre, 0, 255), $date, $lieu !== '' ? mb_substr($lieu, 0, 150) : null,
                                $qte !== '' ? (int) $qte : null, $details !== '' ? $details : null];
                    if ($action === 'add_realisation') {
                        $pdo->prepare("INSERT INTO commission_realisations (type_id, titre, date_realisation, lieu, quantite, details, commission_id, created_by) VALUES (?,?,?,?,?,?,?,?)")
                            ->execute(array_merge($valeurs, [$cid, $membre]));
                        $retour('realisations', "Réalisation ajoutée.");
                    } else {
                        $pdo->prepare("UPDATE commission_realisations SET type_id = ?, titre = ?, date_realisation = ?, lieu = ?, quantite = ?, details = ?, updated_at = NOW() WHERE id = ? AND commission_id = ?")
                            ->execute(array_merge($valeurs, [(int) ($_POST['realisation_id'] ?? 0), $cid]));
                        $retour('realisations', "Réalisation modifiée.");
                    }
                }
            } elseif ($action === 'delete_realisation') {
                $pdo->prepare("DELETE FROM commission_realisations WHERE id = ? AND commission_id = ?")
                    ->execute([(int) ($_POST['realisation_id'] ?? 0), $cid]);
                $retour('realisations', "Réalisation supprimée.");
            }
        } catch (Exception $e) {
            error_log('Touba Lyon - réalisations : ' . $e->getMessage());
            $error = "L'enregistrement n'a pas pu se faire.";
        }
    }
}

$types = realisations_types($pdo, $cid);
$typesActifs = array_values(array_filter($types, static fn($t) => (int) $t['actif'] === 1));
$liste = realisations_liste($pdo, $cid, $du, $au);
$ind = realisations_indicateurs($liste, $du, $au);
$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$qs = 'id=' . $cid . '&du=' . $du . '&au=' . $au;
$maxType = 1;
foreach ($ind['par_type'] as $pt) { $maxType = max($maxType, $pt['nb']); }
$maxMois = max(1, max($ind['par_mois'] ?: [0]));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réalisations — <?php echo $h($commission['nom']); ?> — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .re-wrap { max-width: 980px; margin: 2rem auto; }
        .re-tabs { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 1.1rem; }
        .re-tabs a { padding: 0.5rem 1rem; border-radius: 12px; border: 1px solid var(--glass-border); color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.9rem; }
        .re-tabs a.active { background: rgba(212,175,55,0.15); border-color: rgba(212,175,55,0.6); color: var(--gold); }
        .re-card { border-radius: 12px; padding: 1.1rem 1.3rem; margin-bottom: 1rem; }
        .re-card h3 { margin: 0 0 0.8rem; font-size: 1rem; color: var(--white); }
        .re-periode { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: flex-end; }
        .re-periode .form-group { margin: 0; flex: 1 1 140px; }
        .re-periode input { width: 100%; box-sizing: border-box; }
        .re-raccourcis { display: flex; gap: 0.35rem; flex-wrap: wrap; margin-top: 0.6rem; }
        .re-raccourcis a { font-size: 0.78rem; padding: 0.25rem 0.65rem; border-radius: 999px; border: 1px solid var(--glass-border); color: var(--text-muted); text-decoration: none; }
        .re-raccourcis a:hover { border-color: var(--gold); color: var(--gold); }
        .re-stats { display: flex; flex-wrap: wrap; gap: 0.75rem; }
        .re-stats > * { flex: 1 1 140px; min-width: 0; }
        .re-stat { border-radius: 10px; padding: 0.9rem 1rem; text-align: center; }
        .re-stat .n { font-size: 1.6rem; font-weight: 800; color: var(--white); line-height: 1.1; }
        .re-stat .l { font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; margin-top: 0.25rem; }
        .re-barre-ligne { display: flex; gap: 0.6rem; align-items: center; margin-bottom: 0.45rem; font-size: 0.85rem; color: var(--white); }
        .re-barre-ligne .nom { flex: 0 0 30%; min-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .re-barre-ligne .re-barre { flex: 1 1 0; min-width: 0; }
        .re-barre { height: 10px; border-radius: 6px; background: rgba(255,255,255,0.08); overflow: hidden; }
        .re-barre > span { display: block; height: 100%; border-radius: 6px; background: linear-gradient(90deg, #d4af37, #f4dd8c); }
        .re-barre-ligne .val { color: var(--text-muted); font-size: 0.8rem; white-space: nowrap; }
        .re-mois { display: flex; align-items: flex-end; gap: 4px; height: 140px; padding-top: 0.5rem; overflow-x: auto; }
        .re-mois .col { flex: 1 0 26px; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; gap: 3px; }
        .re-mois .col .b { width: 70%; min-height: 2px; border-radius: 4px 4px 0 0; background: linear-gradient(180deg, #f4dd8c, #d4af37); }
        .re-mois .col .v { font-size: 0.68rem; color: var(--white); }
        .re-mois .col .m { font-size: 0.62rem; color: var(--text-muted); white-space: nowrap; }
        .re-row { background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.7rem 0.9rem; margin-bottom: 0.55rem; }
        .re-row-tete { display: flex; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; align-items: flex-start; }
        .re-row-tete strong { color: var(--white); }
        .re-badge { display: inline-block; font-size: 0.72rem; font-weight: 700; padding: 0.12rem 0.6rem; border-radius: 999px; background: rgba(212,175,55,0.14); color: var(--gold); border: 1px solid rgba(212,175,55,0.4); }
        .re-sub { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem; }
        .re-details { font-size: 0.85rem; color: var(--white); margin-top: 0.4rem; line-height: 1.5; }
        .re-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.55rem; }
        .re-actions details { flex-basis: 100%; }
        .re-form { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: flex-end; }
        .re-form .form-group { margin: 0; flex: 1 1 180px; min-width: 0; }
        .re-form .form-group.large { flex-basis: 100%; }
        .re-form input, .re-form select, .re-form textarea { width: 100%; box-sizing: border-box; }
        .re-form textarea { min-height: 70px; resize: vertical; }
        .re-suppr { background: transparent; border: 1px solid var(--danger); color: #fca5a5; }
        .re-inactif { opacity: 0.6; }
        .re-vide { color: var(--text-muted); font-size: 0.88rem; margin: 0; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">
        <div class="re-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.25rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:1rem;">
                <span>🏆 Réalisations — <strong class="gold-text"><?php echo $h($commission['nom']); ?></strong></span>
                <a href="commission_dashboard.php" class="btn btn-secondary btn-sm">← Espaces commissions</a>
            </div>

            <?php // La période : tableau de bord, liste et rapport PDF ?>
            <div class="glass-card re-card">
                <form method="GET" action="commission_realisations.php" class="re-periode">
                    <input type="hidden" name="id" value="<?php echo $cid; ?>">
                    <input type="hidden" name="tab" value="<?php echo $h($tab); ?>">
                    <div class="form-group"><label class="form-label">Du</label><input type="date" name="du" class="form-input" value="<?php echo $h($du); ?>"></div>
                    <div class="form-group"><label class="form-label">Au</label><input type="date" name="au" class="form-input" value="<?php echo $h($au); ?>"></div>
                    <button type="submit" class="btn btn-secondary btn-sm">Afficher</button>
                    <a href="commission_realisations_pdf.php?<?php echo $h($qs); ?>" class="btn btn-primary btn-sm" target="_blank">📄 Rapport PDF de la période</a>
                </form>
                <div class="re-raccourcis">
                    <?php
                    $an = (int) date('Y');
                    $raccourcis = [
                        'Cette année' => [$an . '-01-01', date('Y-m-d')],
                        'Année ' . ($an - 1) => [($an - 1) . '-01-01', ($an - 1) . '-12-31'],
                        'Ce mois' => [date('Y-m-01'), date('Y-m-d')],
                        '3 derniers mois' => [date('Y-m-d', strtotime('-3 months')), date('Y-m-d')],
                        '12 derniers mois' => [date('Y-m-d', strtotime('-12 months')), date('Y-m-d')],
                    ];
                    foreach ($raccourcis as $lib => [$rdu, $rau]): ?>
                        <a href="commission_realisations.php?id=<?php echo $cid; ?>&tab=<?php echo $h($tab); ?>&du=<?php echo $rdu; ?>&au=<?php echo $rau; ?>"><?php echo $h($lib); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <nav class="re-tabs">
                <?php foreach ($onglets as $k => $lib): ?>
                    <a href="commission_realisations.php?<?php echo $h($qs); ?>&tab=<?php echo $k; ?>"<?php echo $k === $tab ? ' class="active"' : ''; ?>><?php echo $lib; ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'tableau'): ?>
                <div class="re-stats" style="margin-bottom:1rem;">
                    <div class="glass-card re-stat"><div class="n"><?php echo (int) $ind['nb']; ?></div><div class="l">🏆 Réalisations</div></div>
                    <div class="glass-card re-stat"><div class="n"><?php echo number_format($ind['quantite'], 0, ',', ' '); ?></div><div class="l">👥 Quantité / bénéficiaires</div></div>
                    <div class="glass-card re-stat"><div class="n"><?php echo (int) $ind['types']; ?></div><div class="l">🏷️ Types réalisés</div></div>
                    <div class="glass-card re-stat"><div class="n" style="font-size:1.15rem;"><?php echo $ind['derniere'] ? date('d/m/Y', strtotime($ind['derniere'])) : '—'; ?></div><div class="l">📅 Dernière réalisation</div></div>
                </div>

                <div class="glass-card re-card">
                    <h3>🏷️ Par type de réalisation</h3>
                    <?php if (empty($ind['par_type'])): ?>
                        <p class="re-vide">Aucune réalisation du <?php echo date('d/m/Y', strtotime($du)); ?> au <?php echo date('d/m/Y', strtotime($au)); ?>.</p>
                    <?php endif; ?>
                    <?php foreach ($ind['par_type'] as $nomType => $pt): ?>
                        <div class="re-barre-ligne">
                            <span class="nom" title="<?php echo $h($nomType); ?>"><?php echo $h($nomType); ?></span>
                            <span class="re-barre"><span style="width:<?php echo (int) round(100 * $pt['nb'] / $maxType); ?>%;"></span></span>
                            <span class="val"><?php echo (int) $pt['nb']; ?><?php echo $pt['quantite'] > 0 ? ' · ' . number_format($pt['quantite'], 0, ',', ' ') . ' 👥' : ''; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="glass-card re-card">
                    <h3>📅 Par mois</h3>
                    <div class="re-mois">
                        <?php foreach ($ind['par_mois'] as $ym => $nbm): ?>
                            <div class="col" title="<?php echo $h(realisations_mois_court($ym)); ?> : <?php echo (int) $nbm; ?>">
                                <span class="v"><?php echo $nbm > 0 ? (int) $nbm : ''; ?></span>
                                <span class="b" style="height:<?php echo (int) round(100 * $nbm / $maxMois); ?>%;"></span>
                                <span class="m"><?php echo $h(realisations_mois_court($ym)); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!empty($liste)): ?>
                <div class="glass-card re-card">
                    <h3>🕓 Dernières réalisations</h3>
                    <?php foreach (array_slice($liste, 0, 5) as $r): ?>
                        <div class="re-row">
                            <div class="re-row-tete">
                                <strong><?php echo $h($r['titre']); ?></strong>
                                <?php if (!empty($r['type_nom'])): ?><span class="re-badge"><?php echo $h($r['type_nom']); ?></span><?php endif; ?>
                            </div>
                            <div class="re-sub">📅 <?php echo date('d/m/Y', strtotime($r['date_realisation'])); ?><?php echo !empty($r['lieu']) ? ' · 📍 ' . $h($r['lieu']) : ''; ?><?php echo $r['quantite'] !== null ? ' · 👥 ' . (int) $r['quantite'] : ''; ?></div>
                        </div>
                    <?php endforeach; ?>
                    <a href="commission_realisations.php?<?php echo $h($qs); ?>&tab=realisations" class="btn btn-secondary btn-sm">Toutes les réalisations</a>
                </div>
                <?php endif; ?>

            <?php elseif ($tab === 'realisations'): ?>
                <details class="glass-card re-card"<?php echo empty($liste) ? ' open' : ''; ?>>
                    <summary style="cursor:pointer; font-weight:700; color:var(--gold);">➕ Ajouter une réalisation</summary>
                    <?php if (empty($typesActifs)): ?>
                        <p class="re-vide" style="margin-top:0.6rem;">💡 Définissez d'abord vos <a href="commission_realisations.php?<?php echo $h($qs); ?>&tab=types" style="color:var(--gold);">types de réalisation</a> pour les classer (facultatif).</p>
                    <?php endif; ?>
                    <form method="POST" action="commission_realisations.php" class="re-form" style="margin-top:0.8rem;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_realisation">
                        <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                        <input type="hidden" name="du" value="<?php echo $h($du); ?>"><input type="hidden" name="au" value="<?php echo $h($au); ?>">
                        <div class="form-group large"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required maxlength="255" placeholder="Ex : Distribution de repas au foyer"></div>
                        <div class="form-group"><label class="form-label">Type</label>
                            <select name="type_id" class="form-input">
                                <option value="0">— Sans type —</option>
                                <?php foreach ($typesActifs as $t): ?><option value="<?php echo (int) $t['id']; ?>"><?php echo $h($t['nom']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Date *</label><input type="date" name="date_realisation" class="form-input" required value="<?php echo date('Y-m-d'); ?>"></div>
                        <div class="form-group"><label class="form-label">Lieu</label><input type="text" name="lieu" class="form-input" maxlength="150" placeholder="Ex : Lyon 8e"></div>
                        <div class="form-group"><label class="form-label">Quantité / bénéficiaires</label><input type="number" name="quantite" class="form-input" min="0" step="1" placeholder="Ex : 120"></div>
                        <div class="form-group large"><label class="form-label">Détails</label><textarea name="details" class="form-input" placeholder="Déroulement, partenaires, remarques…"></textarea></div>
                        <button type="submit" class="btn btn-primary btn-sm">➕ Ajouter</button>
                    </form>
                </details>

                <div class="glass-card re-card">
                    <h3>📝 Réalisations du <?php echo date('d/m/Y', strtotime($du)); ?> au <?php echo date('d/m/Y', strtotime($au)); ?> <span class="re-badge"><?php echo count($liste); ?></span></h3>
                    <?php if (empty($liste)): ?><p class="re-vide">Aucune réalisation sur cette période.</p><?php endif; ?>
                    <?php foreach ($liste as $r): ?>
                        <div class="re-row">
                            <div class="re-row-tete">
                                <strong><?php echo $h($r['titre']); ?></strong>
                                <?php if (!empty($r['type_nom'])): ?><span class="re-badge"><?php echo $h($r['type_nom']); ?></span><?php endif; ?>
                            </div>
                            <div class="re-sub">📅 <?php echo date('d/m/Y', strtotime($r['date_realisation'])); ?><?php echo !empty($r['lieu']) ? ' · 📍 ' . $h($r['lieu']) : ''; ?><?php echo $r['quantite'] !== null ? ' · 👥 ' . (int) $r['quantite'] : ''; ?></div>
                            <?php if (!empty($r['details'])): ?><div class="re-details"><?php echo nl2br($h($r['details'])); ?></div><?php endif; ?>
                            <div class="re-actions">
                                <form method="POST" action="commission_realisations.php" style="margin:0;" data-confirm="Supprimer la réalisation « <?php echo $h($r['titre']); ?> » ?" data-confirm-danger="1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_realisation">
                                    <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                                    <input type="hidden" name="realisation_id" value="<?php echo (int) $r['id']; ?>">
                                    <input type="hidden" name="du" value="<?php echo $h($du); ?>"><input type="hidden" name="au" value="<?php echo $h($au); ?>">
                                    <button type="submit" class="btn btn-sm re-suppr">🗑️ Supprimer</button>
                                </form>
                                <details>
                                    <summary class="btn btn-secondary btn-sm" style="display:inline-block; list-style:none;">✏️ Modifier</summary>
                                    <form method="POST" action="commission_realisations.php" class="re-form" style="margin-top:0.7rem;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_realisation">
                                        <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                                        <input type="hidden" name="realisation_id" value="<?php echo (int) $r['id']; ?>">
                                        <input type="hidden" name="du" value="<?php echo $h($du); ?>"><input type="hidden" name="au" value="<?php echo $h($au); ?>">
                                        <div class="form-group large"><label class="form-label">Titre *</label><input type="text" name="titre" class="form-input" required maxlength="255" value="<?php echo $h($r['titre']); ?>"></div>
                                        <div class="form-group"><label class="form-label">Type</label>
                                            <select name="type_id" class="form-input">
                                                <option value="0">— Sans type —</option>
                                                <?php foreach ($types as $t): if ((int) $t['actif'] !== 1 && (int) $t['id'] !== (int) $r['type_id']) { continue; } ?>
                                                    <option value="<?php echo (int) $t['id']; ?>"<?php echo (int) $t['id'] === (int) $r['type_id'] ? ' selected' : ''; ?>><?php echo $h($t['nom']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group"><label class="form-label">Date *</label><input type="date" name="date_realisation" class="form-input" required value="<?php echo $h($r['date_realisation']); ?>"></div>
                                        <div class="form-group"><label class="form-label">Lieu</label><input type="text" name="lieu" class="form-input" maxlength="150" value="<?php echo $h($r['lieu'] ?? ''); ?>"></div>
                                        <div class="form-group"><label class="form-label">Quantité / bénéficiaires</label><input type="number" name="quantite" class="form-input" min="0" step="1" value="<?php echo $r['quantite'] !== null ? (int) $r['quantite'] : ''; ?>"></div>
                                        <div class="form-group large"><label class="form-label">Détails</label><textarea name="details" class="form-input"><?php echo $h($r['details'] ?? ''); ?></textarea></div>
                                        <button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer</button>
                                    </form>
                                </details>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="glass-card re-card">
                    <h3>➕ Nouveau type de réalisation</h3>
                    <form method="POST" action="commission_realisations.php" class="re-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_type">
                        <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                        <input type="hidden" name="du" value="<?php echo $h($du); ?>"><input type="hidden" name="au" value="<?php echo $h($au); ?>">
                        <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-input" required maxlength="120" placeholder="Ex : Distribution de repas"></div>
                        <div class="form-group large"><label class="form-label">Description</label><textarea name="description" class="form-input" placeholder="Ce que recouvre ce type de réalisation, comment le compter…"></textarea></div>
                        <button type="submit" class="btn btn-primary btn-sm">➕ Ajouter le type</button>
                    </form>
                </div>

                <div class="glass-card re-card">
                    <h3>🏷️ Types de réalisation <span class="re-badge"><?php echo count($types); ?></span></h3>
                    <?php if (empty($types)): ?><p class="re-vide">Aucun type défini pour cette commission.</p><?php endif; ?>
                    <?php foreach ($types as $t): ?>
                        <div class="re-row<?php echo (int) $t['actif'] === 1 ? '' : ' re-inactif'; ?>">
                            <div class="re-row-tete">
                                <strong><?php echo $h($t['nom']); ?></strong>
                                <span class="re-badge"><?php echo (int) $t['nb']; ?> réalisation(s)<?php echo (int) $t['actif'] === 1 ? '' : ' · désactivé'; ?></span>
                            </div>
                            <?php if (!empty($t['description'])): ?><div class="re-details"><?php echo nl2br($h($t['description'])); ?></div><?php endif; ?>
                            <div class="re-actions">
                                <form method="POST" action="commission_realisations.php" style="margin:0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_type">
                                    <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                                    <input type="hidden" name="type_id" value="<?php echo (int) $t['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm"><?php echo (int) $t['actif'] === 1 ? '⏸️ Désactiver' : '▶️ Réactiver'; ?></button>
                                </form>
                                <?php if ((int) $t['nb'] === 0): ?>
                                <form method="POST" action="commission_realisations.php" style="margin:0;" data-confirm="Supprimer le type « <?php echo $h($t['nom']); ?> » ?" data-confirm-danger="1">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete_type">
                                    <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                                    <input type="hidden" name="type_id" value="<?php echo (int) $t['id']; ?>">
                                    <button type="submit" class="btn btn-sm re-suppr">🗑️ Supprimer</button>
                                </form>
                                <?php endif; ?>
                                <details>
                                    <summary class="btn btn-secondary btn-sm" style="display:inline-block; list-style:none;">✏️ Modifier</summary>
                                    <form method="POST" action="commission_realisations.php" class="re-form" style="margin-top:0.7rem;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_type">
                                        <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                                        <input type="hidden" name="type_id" value="<?php echo (int) $t['id']; ?>">
                                        <div class="form-group"><label class="form-label">Nom *</label><input type="text" name="nom" class="form-input" required maxlength="120" value="<?php echo $h($t['nom']); ?>"></div>
                                        <div class="form-group large"><label class="form-label">Description</label><textarea name="description" class="form-input"><?php echo $h($t['description'] ?? ''); ?></textarea></div>
                                        <button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer</button>
                                    </form>
                                </details>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
<script src="modern-date.js" defer></script>
</body>
</html>
