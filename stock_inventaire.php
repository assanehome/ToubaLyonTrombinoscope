<?php
/**
 * Touba Lyon 2026 - Inventaire d'une commission : analyse des écarts et mise à jour du stock.
 *
 * Pour chaque article : quantité théorique (stock) / quantité comptée, état (bon état, à réparer, endommagé).
 * La validation met le stock à jour (quantités et états) et envoie le rapport d'inventaire par e-mail
 * aux responsables de la commission et à l'auteur de l'inventaire.
 */
require_once __DIR__ . '/commission_guard.php'; // $__isAdmin, $__managedCommissions, $pdo
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/stock_logistique_helper.php';

$error = '';
$success = $_SESSION['inv_flash'] ?? '';
unset($_SESSION['inv_flash']);
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

$cid = (int) ($_POST['commission_id'] ?? $_GET['id'] ?? 0);
$canManage = $__isAdmin || in_array($cid, $__managedCommissions, true);
$commission = null;
if ($cid > 0) {
    try {
        $st = $pdo->prepare("SELECT id, nom, COALESCE(stock_enabled,0) AS stock_enabled FROM commissions WHERE id = ?");
        $st->execute([$cid]);
        $commission = $st->fetch();
    } catch (Exception $e) { $commission = null; }
}
if (!$commission || !$canManage) { header('Location: stock_index.php'); exit; }
$stockEnabled = ((int) $commission['stock_enabled'] === 1);
$moi = (int) ($_SESSION['player_id'] ?? 0);

/** Rapport d'inventaire (HTML d'e-mail) : synthèse des écarts et des états + détail par article. */
function inventaire_rapport_inner(array $inv, array $lignes, string $commissionNom, string $auteur): string
{
    $manque = 0; $surplus = 0; $nbEcarts = 0; $etats = ['bon' => 0, 'a_reparer' => 0, 'endommage' => 0];
    $rows = [];
    foreach ($lignes as $l) {
        $e = (int) $l['ecart'];
        if ($e < 0) { $manque += -$e; $nbEcarts++; } elseif ($e > 0) { $surplus += $e; $nbEcarts++; }
        $etats[$l['etat']] = ($etats[$l['etat']] ?? 0) + 1;
        $ecartTxt = $e === 0 ? '<span style="color:#2d6a4f;">=</span>' : ($e < 0 ? '<strong style="color:#b91c1c;">' . $e . '</strong>' : '<strong style="color:#92400e;">+' . $e . '</strong>');
        $rows[] = [htmlspecialchars((string) $l['nom']), (int) $l['qte_theorique'], (int) $l['qte_comptee'], $ecartTxt, htmlspecialchars(stock_etats()[$l['etat']] ?? 'Bon état'), htmlspecialchars((string) ($l['commentaire'] ?? ''))];
    }
    $inner = '<h1 style="margin:0 0 14px;font-size:20px;color:#1b4332;">Rapport d\'inventaire</h1>'
        . '<p style="margin:0 0 6px;">Commission : <strong>' . htmlspecialchars($commissionNom) . '</strong></p>'
        . '<p style="margin:0 0 6px;">Date : <strong>' . htmlspecialchars(date('d/m/Y H:i', strtotime((string) $inv['created_at']))) . '</strong> — réalisé par <strong>' . htmlspecialchars($auteur) . '</strong></p>'
        . (trim((string) ($inv['note'] ?? '')) !== '' ? '<p style="margin:0 0 10px;">Note : ' . nl2br(htmlspecialchars((string) $inv['note'])) . '</p>' : '')
        . '<p style="background:#f6f3e8;border:1px solid #d4af37;border-radius:10px;padding:10px 14px;margin:12px 0;">'
        . '<strong>Analyse des écarts</strong> : ' . $nbEcarts . ' article(s) avec écart — <span style="color:#b91c1c;">manquants : ' . $manque . '</span> · <span style="color:#92400e;">excédents : ' . $surplus . '</span><br>'
        . '<strong>États</strong> : ' . $etats['bon'] . ' en bon état · ' . $etats['a_reparer'] . ' à réparer · ' . $etats['endommage'] . ' endommagé(s)</p>'
        . sl_table_html(['Article', 'Théorique', 'Compté', 'Écart', 'État', 'Commentaire'], $rows)
        . '<p style="margin-top:14px;">Le stock a été mis à jour d\'après les quantités comptées. Jërëjëf !</p>';
    return $inner;
}

function inventaire_envoyer(PDO $pdo, array $inv, array $lignes, array $commission, string $auteur, int $auteurId): int
{
    $d = [];
    foreach (stock_responsables_commission($pdo, (int) $commission['id']) as $m) { sl_dest($d, $m['email'] ?? '', trim($m['prenom'] . ' ' . $m['nom'])); }
    if ($auteurId > 0) {
        try { $q = $pdo->prepare("SELECT email, prenom, nom FROM membres WHERE id = ?"); $q->execute([$auteurId]); if ($m = $q->fetch()) { sl_dest($d, $m['email'] ?? '', trim($m['prenom'] . ' ' . $m['nom'])); } } catch (Exception $e) {}
    }
    return sl_envoyer($d, 'Rapport d\'inventaire — ' . $commission['nom'], inventaire_rapport_inner($inv, $lignes, $commission['nom'], $auteur), 'Inventaire');
}

$nomAuteur = function (int $id) use ($pdo) {
    try { $q = $pdo->prepare("SELECT prenom, nom FROM membres WHERE id = ?"); $q->execute([$id]); $m = $q->fetch(); return $m ? trim($m['prenom'] . ' ' . $m['nom']) : ($id === 0 ? 'Administrateur' : '—'); } catch (Exception $e) { return '—'; }
};

$items = [];
try {
    $q = $pdo->prepare("SELECT id, nom, quantite, etat FROM commission_stock WHERE commission_id = ? ORDER BY nom ASC");
    $q->execute([$cid]);
    $items = $q->fetchAll();
} catch (Exception $e) { $items = []; }

$resultat = null; // synthèse affichée après validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stockEnabled) {
    if (!csrf_validate()) { $error = "Échec de validation de sécurité. Veuillez réessayer."; }
    else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'valider_inventaire') {
                $note = trim((string) ($_POST['note'] ?? ''));
                $comptes = (array) ($_POST['compte'] ?? []); $etatsIn = (array) ($_POST['etat'] ?? []); $coms = (array) ($_POST['commentaire'] ?? []);
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO stock_inventaires (commission_id, note, created_by) VALUES (?,?,?)")->execute([$cid, $note !== '' ? $note : null, $moi]);
                $iid = (int) $pdo->lastInsertId();
                $insL = $pdo->prepare("INSERT INTO stock_inventaire_lignes (inventaire_id, stock_id, nom, qte_theorique, qte_comptee, ecart, etat, commentaire) VALUES (?,?,?,?,?,?,?,?)");
                $upd = $pdo->prepare("UPDATE commission_stock SET quantite = ?, etat = ? WHERE id = ? AND commission_id = ?");
                $lignes = [];
                foreach ($items as $it) {
                    $id = (int) $it['id'];
                    $compte = isset($comptes[$id]) && $comptes[$id] !== '' ? max(0, (int) $comptes[$id]) : (int) $it['quantite'];
                    $etat = array_key_exists((string) ($etatsIn[$id] ?? ''), stock_etats()) ? (string) $etatsIn[$id] : (string) $it['etat'];
                    $com = mb_substr(trim((string) ($coms[$id] ?? '')), 0, 255);
                    $ecart = $compte - (int) $it['quantite'];
                    $insL->execute([$iid, $id, $it['nom'], (int) $it['quantite'], $compte, $ecart, $etat, $com !== '' ? $com : null]);
                    $upd->execute([$compte, $etat, $id, $cid]);
                    $lignes[] = ['nom' => $it['nom'], 'qte_theorique' => (int) $it['quantite'], 'qte_comptee' => $compte, 'ecart' => $ecart, 'etat' => $etat, 'commentaire' => $com];
                }
                $pdo->commit();
                $inv = ['created_at' => date('Y-m-d H:i:s'), 'note' => $note];
                $n = inventaire_envoyer($pdo, $inv, $lignes, $commission, $moi > 0 ? $nomAuteur($moi) : 'Administrateur', $moi);
                $_SESSION['inv_flash'] = "Inventaire enregistré, stock mis à jour. Rapport envoyé par e-mail ($n destinataire" . ($n > 1 ? 's' : '') . ").";
                header('Location: stock_inventaire.php?id=' . $cid . '&voir=' . $iid);
                exit;
            } elseif ($action === 'renvoyer') {
                $iid = (int) ($_POST['inventaire_id'] ?? 0);
                $q = $pdo->prepare("SELECT * FROM stock_inventaires WHERE id = ? AND commission_id = ?"); $q->execute([$iid, $cid]); $inv = $q->fetch();
                if ($inv) {
                    $q = $pdo->prepare("SELECT * FROM stock_inventaire_lignes WHERE inventaire_id = ? ORDER BY nom ASC"); $q->execute([$iid]);
                    $n = inventaire_envoyer($pdo, $inv, $q->fetchAll(), $commission, $nomAuteur((int) $inv['created_by']), $moi);
                    $success = "Rapport renvoyé par e-mail ($n destinataire" . ($n > 1 ? 's' : '') . ").";
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Touba Lyon inventaire : ' . $e->getMessage());
            $error = "Une erreur technique est survenue.";
        }
    }
}

// Consultation d'un inventaire
$voir = (int) ($_GET['voir'] ?? 0);
$invVu = null; $lignesVu = [];
if ($voir > 0) {
    try {
        $q = $pdo->prepare("SELECT * FROM stock_inventaires WHERE id = ? AND commission_id = ?"); $q->execute([$voir, $cid]); $invVu = $q->fetch() ?: null;
        if ($invVu) { $q = $pdo->prepare("SELECT * FROM stock_inventaire_lignes WHERE inventaire_id = ? ORDER BY nom ASC"); $q->execute([$voir]); $lignesVu = $q->fetchAll(); }
    } catch (Exception $e) { $invVu = null; }
}
$historique = [];
try {
    $q = $pdo->prepare("SELECT i.*, (SELECT COUNT(*) FROM stock_inventaire_lignes l WHERE l.inventaire_id = i.id AND l.ecart <> 0) AS nb_ecarts, (SELECT COUNT(*) FROM stock_inventaire_lignes l WHERE l.inventaire_id = i.id) AS nb_lignes FROM stock_inventaires i WHERE i.commission_id = ? ORDER BY i.id DESC LIMIT 30");
    $q->execute([$cid]);
    $historique = $q->fetchAll();
} catch (Exception $e) {}
$csrf = csrf_field();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📋 Inventaire — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .iv-wrap { max-width: 900px; margin: 0 auto; }
        .iv-card { border: 1px solid rgba(212,175,55,0.38); border-radius: 12px; background: rgba(255,255,255,0.03); padding: 0.8rem 1rem; margin-bottom: 0.5rem; }
        .iv-nom { color: var(--white); font-weight: 800; }
        .iv-th { color: var(--text-muted); font-size: 0.8rem; }
        .iv-champs { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-top: 0.5rem; }
        .iv-champs input[type=number] { width: 6.5rem; }
        .iv-champs select { width: 10rem; }
        .iv-champs input[type=text] { flex: 1 1 160px; min-width: 0; }
        .iv-ecart-m { color: #fca5a5; font-weight: 800; } .iv-ecart-p { color: #ffd873; font-weight: 800; } .iv-ecart-0 { color: #7bd8a6; font-weight: 800; }
        .iv-sub { color: var(--text-muted); font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin: 1rem 0 0.4rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">
            <div class="iv-wrap">
                <div class="admin-welcome-banner glass-card" style="margin-bottom:1rem; padding:1.1rem 1.5rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:0.8rem;">
                    <span>📋 Inventaire — <strong class="gold-text"><?php echo $h($commission['nom']); ?></strong></span>
                    <a href="commission_stock.php?id=<?php echo $cid; ?>" class="btn btn-secondary btn-sm">← Stock</a>
                </div>
                <?php if ($success !== ''): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($success); ?></div><?php endif; ?>
                <?php if ($error !== ''): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo $h($error); ?></div><?php endif; ?>

                <?php if (!$stockEnabled): ?>
                    <div class="empty-state"><div class="empty-state-icon">📦</div><p>La gestion de stock n'est pas activée pour cette commission.</p></div>
                <?php else: ?>

                <?php if ($invVu): ?>
                    <div class="iv-sub">📄 Rapport d'inventaire du <?php echo $h(date('d/m/Y H:i', strtotime((string) $invVu['created_at']))); ?> — <?php echo $h($nomAuteur((int) $invVu['created_by'])); ?></div>
                    <?php
                        $manque = 0; $surplus = 0; $nEc = 0;
                        foreach ($lignesVu as $l) { if ((int) $l['ecart'] < 0) { $manque += -(int) $l['ecart']; $nEc++; } elseif ((int) $l['ecart'] > 0) { $surplus += (int) $l['ecart']; $nEc++; } }
                    ?>
                    <div class="iv-card" style="border-color:rgba(212,175,55,0.6);">
                        <strong>Analyse des écarts</strong> : <?php echo $nEc; ?> article(s) avec écart —
                        <span class="iv-ecart-m">manquants : <?php echo $manque; ?></span> · <span class="iv-ecart-p">excédents : <?php echo $surplus; ?></span>
                        <?php if (trim((string) $invVu['note']) !== ''): ?><div class="iv-th" style="margin-top:0.3rem;">Note : <?php echo nl2br($h($invVu['note'])); ?></div><?php endif; ?>
                        <form method="POST" action="stock_inventaire.php?id=<?php echo $cid; ?>&voir=<?php echo (int) $invVu['id']; ?>" style="margin-top:0.6rem;">
                            <?php echo $csrf; ?><input type="hidden" name="action" value="renvoyer"><input type="hidden" name="commission_id" value="<?php echo $cid; ?>"><input type="hidden" name="inventaire_id" value="<?php echo (int) $invVu['id']; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">📧 Renvoyer le rapport par e-mail</button>
                        </form>
                    </div>
                    <?php foreach ($lignesVu as $l): $e = (int) $l['ecart']; ?>
                        <div class="iv-card">
                            <div class="iv-nom"><?php echo $h($l['nom']); ?> <?php echo stock_etat_badge((string) $l['etat']); ?></div>
                            <div class="iv-th">Théorique : <?php echo (int) $l['qte_theorique']; ?> · Compté : <?php echo (int) $l['qte_comptee']; ?> ·
                                Écart : <span class="<?php echo $e < 0 ? 'iv-ecart-m' : ($e > 0 ? 'iv-ecart-p' : 'iv-ecart-0'); ?>"><?php echo $e > 0 ? '+' . $e : $e; ?></span>
                                <?php if (trim((string) ($l['commentaire'] ?? '')) !== ''): ?> · <?php echo $h($l['commentaire']); ?><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <details class="glass-card"<?php echo $invVu ? '' : ' open'; ?>>
                    <summary>➕ Nouvel inventaire</summary>
                    <?php if (empty($items)): ?>
                        <p style="color:var(--text-muted);">Aucun article en stock : rien à inventorier.</p>
                    <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.85rem; margin:0.4rem 0 0.8rem;">Saisissez la quantité réellement comptée et l'état de chaque article. À la validation, le stock est mis à jour et le rapport d'inventaire (avec l'analyse des écarts) est envoyé par e-mail.</p>
                    <form method="POST" action="stock_inventaire.php?id=<?php echo $cid; ?>" data-confirm="Valider l'inventaire ? Le stock sera mis à jour d'après les quantités comptées.">
                        <?php echo $csrf; ?>
                        <input type="hidden" name="action" value="valider_inventaire">
                        <input type="hidden" name="commission_id" value="<?php echo $cid; ?>">
                        <?php foreach ($items as $it): $id = (int) $it['id']; ?>
                            <div class="iv-card">
                                <div class="iv-nom"><?php echo $h($it['nom']); ?> <span class="iv-th">· théorique : <?php echo (int) $it['quantite']; ?></span></div>
                                <div class="iv-champs">
                                    <input type="number" class="form-input" name="compte[<?php echo $id; ?>]" min="0" value="<?php echo (int) $it['quantite']; ?>" title="Quantité comptée">
                                    <select class="form-input" name="etat[<?php echo $id; ?>]">
                                        <?php foreach (stock_etats() as $ek => $el): ?><option value="<?php echo $ek; ?>"<?php echo ((string) $it['etat'] === $ek) ? ' selected' : ''; ?>><?php echo $h($el); ?></option><?php endforeach; ?>
                                    </select>
                                    <input type="text" class="form-input" name="commentaire[<?php echo $id; ?>]" maxlength="255" placeholder="Commentaire (facultatif)">
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="form-group" style="margin-top:0.8rem;"><label class="form-label">Note générale (facultatif)</label><textarea name="note" class="form-input" rows="2"></textarea></div>
                        <button type="submit" class="btn btn-primary">✅ Valider l'inventaire et mettre à jour le stock</button>
                    </form>
                    <?php endif; ?>
                </details>

                <?php if (!empty($historique)): ?>
                <details class="glass-card" style="margin-top:0.6rem;">
                    <summary>🗂️ Inventaires précédents (<?php echo count($historique); ?>)</summary>
                    <?php foreach ($historique as $iv): ?>
                        <a href="stock_inventaire.php?id=<?php echo $cid; ?>&voir=<?php echo (int) $iv['id']; ?>" class="iv-card" style="display:block; text-decoration:none;">
                            <div class="iv-nom">📋 <?php echo $h(date('d/m/Y H:i', strtotime((string) $iv['created_at']))); ?> <span class="iv-th">· <?php echo $h($nomAuteur((int) $iv['created_by'])); ?></span></div>
                            <div class="iv-th"><?php echo (int) $iv['nb_lignes']; ?> article(s) — <?php echo (int) $iv['nb_ecarts']; ?> écart(s)</div>
                        </a>
                    <?php endforeach; ?>
                </details>
                <?php endif; ?>

                <?php endif; ?>
            </div>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/modern_popup.php'; ?>
</body>
</html>
