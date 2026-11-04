<?php
/**
 * Touba Lyon 2026 - 📋 Mes commissions (espace membre)
 *
 * Pour chaque commission dont le membre fait partie (membre ou responsable) :
 * ses responsables, ses membres (par sous-commission), ses réunions et leurs
 * comptes rendus, et le lien vers le Wadial Magal et les disponibilités.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_event_etat()

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/player_approved_guard.php';

$membreId = (int) $_SESSION['player_id'];
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

/** Compte rendu HTML : ne garde que les balises de mise en forme, sans attributs dangereux. */
function mc_cr_html(string $html): string
{
    $clean = strip_tags($html, '<p><br><strong><b><em><i><u><s><ul><ol><li><h3><h4><h5><a><blockquote><span><div>');
    $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
    $clean = preg_replace('/(href\s*=\s*)("|\')\s*javascript:[^"\']*(\2)/i', '$1$2#$3', $clean);
    $clean = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $clean);
    return (string) $clean;
}

// Commissions du membre, avec son rôle
$commissions = [];
try {
    $q = $pdo->prepare("SELECT c.id, c.nom,
                               EXISTS(SELECT 1 FROM commission_gestionnaires g WHERE g.commission_id = c.id AND g.membre_id = :m1) AS est_resp
                        FROM commissions c
                        WHERE c.id IN (SELECT commission_id FROM commission_membres WHERE membre_id = :m2
                                       UNION SELECT commission_id FROM commission_gestionnaires WHERE membre_id = :m3)
                        ORDER BY c.nom ASC");
    $q->execute([':m1' => $membreId, ':m2' => $membreId, ':m3' => $membreId]);
    $commissions = $q->fetchAll();
} catch (Exception $e) { $commissions = []; }
$ids = array_map(function ($c) { return (int) $c['id']; }, $commissions);

// Sous-commissions où le membre est « membre support » (sans appartenir à la commission)
$mesSupports = [];
try {
    $q = $pdo->prepare("SELECT sc.id, sc.nom, c.nom AS commission_nom, CONCAT(r.prenom, ' ', r.nom) AS resp_nom
                        FROM sous_commission_membres scm
                        JOIN sous_commissions sc ON sc.id = scm.sous_commission_id
                        JOIN commissions c ON c.id = sc.commission_id
                        LEFT JOIN membres r ON r.id = sc.responsable_id
                        WHERE scm.membre_id = ? AND scm.support = 1 ORDER BY c.nom, sc.nom");
    $q->execute([$membreId]);
    $mesSupports = $q->fetchAll();
} catch (Exception $e) { $mesSupports = []; }

$responsables = [];   // [cid] => [noms]
$membres = [];        // [cid] => [id => nom]
$sousComs = [];       // [cid] => [ [nom, [noms]] ]
$reunions = [];       // [cid] => [rows]
$magalAnnee = (int) date('Y');
$magalCommissions = [];

if (!empty($ids)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $q = $pdo->prepare("SELECT g.commission_id, m.prenom, m.nom FROM commission_gestionnaires g JOIN membres m ON m.id = g.membre_id WHERE g.commission_id IN ($ph) AND m.status = 'approved' ORDER BY m.prenom, m.nom");
        $q->execute($ids);
        foreach ($q as $r) { $responsables[(int) $r['commission_id']][] = trim($r['prenom'] . ' ' . $r['nom']); }

        $q = $pdo->prepare("SELECT x.commission_id, m.id, m.prenom, m.nom FROM (
                                SELECT commission_id, membre_id FROM commission_membres WHERE commission_id IN ($ph)
                                UNION SELECT commission_id, membre_id FROM commission_gestionnaires WHERE commission_id IN ($ph)
                            ) x JOIN membres m ON m.id = x.membre_id WHERE m.status = 'approved' ORDER BY m.prenom, m.nom");
        $q->execute(array_merge($ids, $ids));
        foreach ($q as $r) { $membres[(int) $r['commission_id']][(int) $r['id']] = trim($r['prenom'] . ' ' . $r['nom']); }

        $q = $pdo->prepare("SELECT sc.commission_id, sc.id, sc.nom, sc.responsable_id, scm.membre_id, scm.support, ms.prenom AS ms_prenom, ms.nom AS ms_nom FROM sous_commissions sc LEFT JOIN sous_commission_membres scm ON scm.sous_commission_id = sc.id LEFT JOIN membres ms ON ms.id = scm.membre_id WHERE sc.commission_id IN ($ph) ORDER BY sc.nom");
        $q->execute($ids);
        $tmp = [];
        foreach ($q as $r) {
            $cid = (int) $r['commission_id'];
            if (!isset($tmp[$cid][(int) $r['id']])) { $tmp[$cid][(int) $r['id']] = ['nom' => (string) $r['nom'], 'resp' => (isset($membres[$cid][(int) ($r['responsable_id'] ?? 0)]) ? $membres[$cid][(int) $r['responsable_id']] : ''), 'membres' => [], 'supports' => []]; }
            if ($r['membre_id'] !== null && (int) ($r['support'] ?? 0) === 1) { $tmp[$cid][(int) $r['id']]['supports'][] = trim($r['ms_prenom'] . ' ' . $r['ms_nom']); }
            elseif ($r['membre_id'] !== null && isset($membres[$cid][(int) $r['membre_id']])) { $tmp[$cid][(int) $r['id']]['membres'][] = $membres[$cid][(int) $r['membre_id']]; }
        }
        foreach ($tmp as $cid => $liste) { $sousComs[$cid] = array_values($liste); }

        $q = $pdo->prepare("SELECT id, commission_id, titre, date_reunion, heure, heure_fin, mode, publie, cloture, annule, compte_rendu, sondage_statut
                            FROM commission_reunions
                            WHERE commission_id IN ($ph) AND COALESCE(annule,0) = 0 AND (publie = 1 OR cloture = 1 OR sondage_statut = 'ouvert')
                            ORDER BY date_reunion DESC, id DESC");
        $q->execute($ids);
        foreach ($q as $r) { $reunions[(int) $r['commission_id']][] = $r; }

        $q = $pdo->prepare("SELECT commission_id FROM magal_commissions WHERE annee = ?");
        $q->execute([$magalAnnee]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mc) { $magalCommissions[(int) $mc] = true; }
    } catch (Exception $e) { error_log('Touba Lyon mes_commissions : ' . $e->getMessage()); }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📋 Mes commissions — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .mc-wrap { max-width: 860px; margin: 1.5rem auto; }
        .mc-card { border-radius: 18px; padding: 1.2rem 1.4rem; margin-bottom: 1.2rem; }
        .mc-head { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.4rem; }
        .mc-head h2 { margin: 0; color: var(--white); font-size: 1.2rem; }
        .mc-sub { color: var(--text-muted); font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin: 0.9rem 0 0.4rem; }
        .mc-chips { display: flex; flex-wrap: wrap; gap: 0.35rem; }
        .mc-chip { background: rgba(255,255,255,0.06); border: 1px solid var(--glass-border); border-radius: 50px; padding: 0.2rem 0.7rem; font-size: 0.82rem; color: var(--white); }
        .mc-chip.resp { border-color: rgba(212,175,55,0.5); color: var(--gold); }
        .mc-grp { border: 1px solid var(--glass-border); border-radius: 12px; padding: 0.55rem 0.9rem; background: rgba(255,255,255,0.03); margin-bottom: 0.5rem; }
        .mc-grp > summary { cursor: pointer; list-style: none; font-weight: 700; color: var(--white); font-size: 0.9rem; display: flex; align-items: center; gap: 0.4rem; }
        .mc-grp > summary::-webkit-details-marker { display: none; }
        .mc-reu { border: 1px solid var(--glass-border); border-radius: 12px; padding: 0.55rem 0.8rem; margin-top: 0.5rem; background: rgba(255,255,255,0.04); }
        .mc-reu-top { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .mc-reu-titre { color: var(--white); font-weight: 700; }
        .mc-reu-meta { color: var(--text-muted); font-size: 0.8rem; margin-top: 2px; }
        .mc-cr { margin-top: 0.6rem; padding: 0.7rem 0.9rem; background: rgba(255,255,255,0.05); border-radius: 10px; color: var(--white); font-size: 0.9rem; line-height: 1.55; }
        .mc-cr h3, .mc-cr h4 { color: var(--gold); margin: 0.6rem 0 0.2rem; }
        .mc-cr ul, .mc-cr ol { padding-left: 1.3rem; }
        .mc-cr blockquote { border-left: 3px solid var(--gold); margin: 0.4rem 0; padding-left: 0.7rem; color: var(--text-muted); }
        .mc-cr a { color: var(--accent); }
        .mc-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.8rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="admin-content">
                <h1 class="admin-page-title">📋 Mes commissions</h1>
                <div class="mc-wrap">

                <?php if (empty($commissions) && empty($mesSupports)): ?>
                    <div class="glass-card mc-card"><p style="margin:0; color:var(--text-muted);">Vous n'êtes membre d'aucune commission pour le moment.</p></div>
                <?php endif; ?>

                <?php if (!empty($mesSupports)): ?>
                <div class="glass-card mc-card">
                    <div class="mc-head"><h2>🤝 Mes sous-commissions (membre support)</h2></div>
                    <p style="margin:0 0 0.5rem; color:var(--text-muted); font-size:0.85rem;">Vous aidez ces sous-commissions sans faire partie de la commission.</p>
                    <?php foreach ($mesSupports as $ms): ?>
                        <div class="mc-reu">
                            <div class="mc-reu-top"><span class="mc-reu-titre">🧩 <?php echo $h($ms['nom']); ?></span><span class="badge">📋 <?php echo $h($ms['commission_nom']); ?></span></div>
                            <?php if (!empty(trim((string) $ms['resp_nom']))): ?><div class="mc-reu-meta">🎖️ Responsable : <?php echo $h($ms['resp_nom']); ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php foreach ($commissions as $c):
                    $cid = (int) $c['id'];
                    $estResp = !empty($c['est_resp']);
                    $liste = $reunions[$cid] ?? [];
                    $aVenir = []; $closes = []; $sondages = [];
                    foreach ($liste as $r) {
                        if ((int) $r['cloture'] === 1) { $closes[] = $r; }
                        elseif ($r['sondage_statut'] === 'ouvert' && (int) $r['publie'] !== 1) { $sondages[] = $r; }
                        else { $aVenir[] = $r; }
                    }
                    $aVenir = array_reverse($aVenir); // prochaines d'abord
                    $nbMembres = count($membres[$cid] ?? []);
                ?>
                <div class="glass-card mc-card" id="commission-<?php echo $cid; ?>">
                    <div class="mc-head">
                        <h2>📋 <?php echo $h($c['nom']); ?></h2>
                        <span>
                            <?php if ($estResp): ?><span class="badge badge-approved">🎖️ Responsable</span><?php else: ?><span class="badge">Membre</span><?php endif; ?>
                            <span class="badge"><?php echo $nbMembres; ?> membre(s)</span>
                        </span>
                    </div>

                    <div class="mc-sub">🎖️ Responsable(s)</div>
                    <?php if (empty($responsables[$cid])): ?>
                        <p style="margin:0; color:var(--text-muted); font-size:0.88rem;">Aucun responsable désigné.</p>
                    <?php else: ?>
                        <div class="mc-chips"><?php foreach ($responsables[$cid] as $n): ?><span class="mc-chip resp"><?php echo $h($n); ?></span><?php endforeach; ?></div>
                    <?php endif; ?>

                    <details class="mc-grp" style="margin-top:0.8rem;">
                        <summary>👥 Membres de la commission (<?php echo $nbMembres; ?>)</summary>
                        <div class="mc-chips" style="margin-top:0.6rem;">
                            <?php foreach (($membres[$cid] ?? []) as $mid => $nom): ?>
                                <span class="mc-chip<?php echo $mid === $membreId ? ' resp' : ''; ?>"><?php echo $h($nom); ?><?php echo $mid === $membreId ? ' (moi)' : ''; ?></span>
                            <?php endforeach; ?>
                        </div>
                    </details>

                    <?php if (!empty($sousComs[$cid])): ?>
                    <details class="mc-grp">
                        <summary>🧩 Sous-commissions (<?php echo count($sousComs[$cid]); ?>)</summary>
                        <?php foreach ($sousComs[$cid] as $sc): ?>
                            <div style="margin-top:0.6rem;">
                                <strong style="color:var(--white); font-size:0.88rem;">🧩 <?php echo $h($sc['nom']); ?></strong>
                                <span style="color:var(--text-muted); font-size:0.78rem;">(<?php echo count($sc['membres']); ?>)</span>
                                <?php if (!empty($sc['resp'])): ?>
                                    <span style="color:var(--gold); font-size:0.8rem; font-weight:700;">🎖️ Responsable : <?php echo $h($sc['resp']); ?></span>
                                <?php endif; ?>
                                <div class="mc-chips" style="margin-top:0.3rem;">
                                    <?php if (empty($sc['membres'])): ?><span style="color:var(--text-muted); font-size:0.82rem;">Aucun membre affecté.</span><?php endif; ?>
                                    <?php foreach ($sc['membres'] as $n): ?><span class="mc-chip"><?php echo $h($n); ?></span><?php endforeach; ?>
                                    <?php foreach (($sc['supports'] ?? []) as $n): ?><span class="mc-chip" title="Membre support">🤝 <?php echo $h($n); ?></span><?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </details>
                    <?php endif; ?>

                    <div class="mc-sub">📅 Réunions</div>
                    <?php if (empty($liste)): ?>
                        <p style="margin:0; color:var(--text-muted); font-size:0.88rem;">Aucune réunion pour le moment.</p>
                    <?php endif; ?>

                    <?php if (!empty($sondages)): ?>
                    <details class="mc-grp" open>
                        <summary>📊 Sondages de date en cours (<?php echo count($sondages); ?>)</summary>
                        <?php foreach ($sondages as $r): ?>
                            <div class="mc-reu">
                                <div class="mc-reu-top">
                                    <span class="mc-reu-titre">📌 <?php echo $h($r['titre']); ?></span>
                                    <a href="reunion_sondage.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-primary btn-sm">📊 Voter pour la date</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </details>
                    <?php endif; ?>

                    <?php if (!empty($aVenir)): ?>
                    <details class="mc-grp" open>
                        <summary>🚀 En cours ou à venir (<?php echo count($aVenir); ?>)</summary>
                        <?php foreach ($aVenir as $r):
                            $hf = trim((string) ($r['heure_fin'] ?? ''));
                            $etat = dahira_event_etat((string) $r['date_reunion'], (string) $r['heure'], ($hf !== '' ? $hf : null));
                        ?>
                            <div class="mc-reu">
                                <div class="mc-reu-top">
                                    <span class="mc-reu-titre">📌 <?php echo $h($r['titre']); ?></span>
                                    <a href="reunion_detail.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-secondary btn-sm">📊 Détail</a>
                                </div>
                                <div class="mc-reu-meta">🗓️ <?php echo date('d/m/Y', strtotime($r['date_reunion'])); ?> · 🕐 <?php echo $h($r['heure']); ?><?php echo $hf !== '' ? ' – ' . $h($hf) : ''; ?> · <?php echo $r['mode'] === 'presentiel' ? '🏛️ Présentiel' : '💻 À distance'; ?><?php echo $etat === 'encours' ? ' · <strong style="color:#7bd8a6;">En cours</strong>' : ''; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </details>
                    <?php endif; ?>

                    <?php if (!empty($closes)): ?>
                    <details class="mc-grp">
                        <summary>✅ Réunions clôturées et comptes rendus (<?php echo count($closes); ?>)</summary>
                        <?php foreach ($closes as $r):
                            $hf = trim((string) ($r['heure_fin'] ?? ''));
                            $cr = trim((string) ($r['compte_rendu'] ?? ''));
                            $aCr = trim(strip_tags($cr)) !== '';
                        ?>
                            <div class="mc-reu">
                                <div class="mc-reu-top">
                                    <span class="mc-reu-titre">📌 <?php echo $h($r['titre']); ?></span>
                                    <a href="reunion_detail.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-secondary btn-sm">📊 Détail</a>
                                </div>
                                <div class="mc-reu-meta">🗓️ <?php echo date('d/m/Y', strtotime($r['date_reunion'])); ?> · 🕐 <?php echo $h($r['heure']); ?><?php echo $hf !== '' ? ' – ' . $h($hf) : ''; ?></div>
                                <?php if ($aCr): ?>
                                <details style="margin-top:0.5rem;">
                                    <summary style="cursor:pointer; color:var(--gold); font-weight:600; font-size:0.85rem;">📝 Lire le compte rendu</summary>
                                    <div class="mc-cr"><?php echo mc_cr_html($cr); ?></div>
                                </details>
                                <?php else: ?>
                                    <div class="mc-reu-meta">📝 Pas de compte rendu pour cette réunion.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </details>
                    <?php endif; ?>

                    <div class="mc-actions">
                        <a href="disponibilites.php" class="btn btn-secondary btn-sm">🗓️ Mes disponibilités</a>
                        <?php if (isset($magalCommissions[$cid])): ?>
                            <a href="magal.php?annee=<?php echo (int) $magalAnnee; ?>&tab=taches" class="btn btn-secondary btn-sm" style="border-color:var(--gold); color:var(--gold);">🕌 Wadial Magal <?php echo (int) $magalAnnee; ?></a>
                        <?php endif; ?>
                        <?php if ($estResp): ?>
                            <a href="commission_dashboard.php#com-card-<?php echo $cid; ?>" class="btn btn-secondary btn-sm">🛠️ Gérer la commission</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                </div>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
</body>
</html>
