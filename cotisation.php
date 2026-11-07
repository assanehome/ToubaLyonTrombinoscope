<?php
/**
 * Touba Lyon 2026 - 💶 Ma cotisation (espace membre)
 *
 * Affiche le statut de la cotisation du mois et permet de régler en ligne
 * (Stripe Checkout). Historique des cotisations réglées.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/cotisation_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/player_approved_guard.php';

$membreId = (int) $_SESSION['player_id'];
$error = '';
$success = '';

$periode = cotisation_periode_courante();
$montant = cotisation_montant_periode($pdo, $periode);
$mode = cotisation_mode($pdo, $membreId);
$virementRef = ($mode === 'virement') ? cotisation_virement_ref($pdo, $membreId) : null;
// Mode effectif pour le mois courant : un virement pas encore entré en vigueur
// (mois courant <= référence de bascule) se comporte comme mensuel.
$modeEff = ($mode === 'virement' && $virementRef !== null && $periode <= $virementRef) ? 'mensuel' : $mode;

// Paiement : création d'une session Stripe puis redirection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payer') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } elseif ($mode === 'exempt') {
        $error = "Vous êtes dispensé(e) de cotisation.";
    } elseif (cotisation_est_paye($pdo, $membreId, $periode)) {
        $success = "Votre cotisation de ce mois est déjà réglée.";
    } elseif (!cotisation_stripe_pret($pdo)) {
        $error = "Le paiement en ligne n'est pas encore configuré. Contactez le secrétariat.";
    } else {
        $emailMembre = '';
        try {
            $st = $pdo->prepare("SELECT email FROM membres WHERE id = ?");
            $st->execute([$membreId]);
            $emailMembre = (string) ($st->fetchColumn() ?: '');
        } catch (Exception $e) { $emailMembre = ''; }
        $sess = cotisation_creer_session($pdo, $membreId, $periode, $emailMembre);
        if ($sess['url']) {
            header('Location: ' . $sess['url']);
            exit;
        }
        $error = $sess['error'] ?: "Impossible d'ouvrir le paiement.";
    }
}

if (!empty($_GET['annule'])) { $error = $error ?: "Paiement annulé. Vous pouvez réessayer quand vous voulez."; }
if (!empty($_GET['ok'])) { $success = $success ?: "Merci ! Votre cotisation a bien été enregistrée."; }

$statutMois = cotisation_statut($pdo, $membreId, $periode);
$estPaye = $statutMois !== null && ($statutMois['statut'] ?? '') === 'paye';

// Historique : tous les mois dus (écoulés + mois courant), même sans ligne en
// base — pour rester cohérent avec l'indicateur de retard ci-dessus. Un membre
// mensuel doit un mois même si personne ne l'a encore pointé ; un virement
// récent doit encore les mois antérieurs à sa date de bascule.
$moisDus = [];
if ($mode === 'mensuel') {
    $moisDus = cotisation_mois_ecoules(cotisation_debut($pdo));
    $moisDus[] = cotisation_periode_courante();
} elseif ($mode === 'virement' && $virementRef !== null) {
    $moisDus = array_values(array_filter(cotisation_mois_ecoules(cotisation_debut($pdo)), function ($m) use ($virementRef) { return $m <= $virementRef; }));
    if (cotisation_periode_courante() <= $virementRef) { $moisDus[] = cotisation_periode_courante(); }
}

// Lignes déjà enregistrées (paiements passés, y compris via virement/Stripe)
$lignesDb = [];
try {
    $st = $pdo->prepare("SELECT * FROM cotisations WHERE membre_id = ? ORDER BY periode DESC");
    $st->execute([$membreId]);
    while ($r = $st->fetch()) { $lignesDb[(string) $r['periode']] = $r; }
} catch (Exception $e) { $lignesDb = []; }

// Fusion : mois dus + mois déjà enregistrés, les plus récents en premier
$periodesHistorique = array_values(array_unique(array_merge($moisDus, array_keys($lignesDb))));
rsort($periodesHistorique);
$periodesHistorique = array_slice($periodesHistorique, 0, 24);

$historique = [];
foreach ($periodesHistorique as $hPer) {
    $historique[] = ['periode' => $hPer] + ($lignesDb[$hPer] ?? []);
}

// Mois écoulés non réglés (retard) + total à régulariser (montant propre à l'année de chaque mois)
$moisRetard = cotisation_mois_retard($pdo, $membreId);
$nbRetard = count($moisRetard);
$totalRetard = 0.0;
foreach ($moisRetard as $mr) { $totalRetard += cotisation_montant_periode($pdo, $mr); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💶 Ma cotisation — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .co-wrap { max-width: 760px; margin: 2rem auto; }
        .co-hero { border-radius: 12px; padding: 1.6rem; margin-bottom: 1.3rem; border: 2px solid rgba(212,175,55,0.5); background: linear-gradient(160deg, rgba(212,175,55,0.14) 0%, rgba(255,255,255,0.03) 100%); }
        .co-montant { font-size: 2rem; font-weight: 800; color: var(--accent); line-height: 1.1; }
        .co-badge { display:inline-block; font-size: 0.78rem; font-weight: 700; border-radius: 12px; padding: 0.25rem 0.8rem; }
        .co-badge-ok { background: rgba(37,211,102,0.15); color: #7bd8a6; border: 1px solid rgba(37,211,102,0.4); }
        .co-badge-att { background: rgba(255,193,7,0.15); color: #ffd873; border: 1px solid rgba(255,193,7,0.4); }
        .co-badge-retard { background: rgba(191,33,33,0.16); color: #fca5a5; border: 1px solid rgba(191,33,33,0.5); }
        .co-retard-alerte { border-radius:12px; padding:1.1rem 1.35rem; margin-bottom:1.3rem; border:1px solid rgba(191,33,33,0.55); background:linear-gradient(160deg, rgba(191,33,33,0.16), rgba(255,255,255,0.03)); }
        .co-retard-detail > summary { cursor:pointer; list-style:none; color:#fca5a5; font-weight:700; font-size:0.86rem; display:inline-flex; align-items:center; gap:0.4rem; user-select:none; padding:0.15rem 0; }
        .co-retard-detail > summary::-webkit-details-marker { display:none; }
        .co-chevron { transition:transform 0.25s ease; display:inline-block; }
        .co-retard-detail[open] > summary .co-chevron { transform:rotate(90deg); }
        @keyframes coRetardIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
        .co-retard-detail[open] .co-retard-list > * { animation:coRetardIn 0.35s cubic-bezier(0.22,1,0.36,1) both; }
        .co-retard-ligne { display:flex; justify-content:space-between; gap:0.6rem; padding:0.5rem 0.75rem; border:1px solid rgba(191,33,33,0.35); border-radius:8px; background:rgba(191,33,33,0.06); }
        @media (prefers-reduced-motion: reduce){ .co-retard-detail[open] .co-retard-list > * { animation:none !important; } }
        .co-hist-card.co-hist-retard { border-color:rgba(191,33,33,0.5); background:rgba(191,33,33,0.06); }
        .co-badge-info { background: rgba(45,140,255,0.15); color: #8ec5ff; border: 1px solid rgba(45,140,255,0.4); }
        .co-pay-btn { display:inline-flex; align-items:center; justify-content:center; gap:0.6rem; width:100%; background: linear-gradient(135deg,#635bff,#8b5cf6); color:#fff; font-weight:800; font-size:1.05rem; border:0; border-radius:10px; padding:1rem 1.4rem; cursor:pointer; box-shadow:0 10px 26px rgba(99,91,255,0.4); }
        .co-hist { display:flex; flex-direction:column; gap:0.55rem; }
        .co-hist-card { display:flex; align-items:center; justify-content:space-between; gap:0.7rem; flex-wrap:wrap; padding:0.75rem 0.95rem; }
        .co-hist-mois { color:var(--white); font-weight:700; font-size:0.95rem; }
        .co-hist-meta { color:var(--text-muted); font-size:0.82rem; margin-top:0.15rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="admin-content">
                <h1 class="admin-page-title">💶 Ma cotisation</h1>

                <?php if (!empty($success)): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:8px;margin:1rem 0;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:8px;margin:1rem 0;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <div class="co-wrap">
                    <?php if ($nbRetard > 0): ?>
                    <div class="co-retard-alerte glass-card">
                        <div style="display:flex; align-items:center; gap:0.9rem; flex-wrap:wrap;">
                            <div style="font-size:1.7rem;">🔴</div>
                            <div style="flex:1; min-width:180px;">
                                <div style="color:#fff; font-weight:800; font-size:1rem;"><?php echo (int) $nbRetard; ?> mois de cotisation en retard</div>
                                <div style="color:var(--text-muted); font-size:0.86rem; margin-top:0.15rem;">Total à régulariser : <strong style="color:#fca5a5; font-size:1.05rem;"><?php echo number_format($totalRetard, 2, ',', ' '); ?> €</strong> <span style="opacity:0.85;">(<?php echo (int) $nbRetard; ?> mois)</span></div>
                            </div>
                        </div>
                        <details class="co-retard-detail" style="margin-top:0.85rem;">
                            <summary>🔎 Détail des mois en retard <span class="co-chevron">▸</span></summary>
                            <div class="co-retard-list" style="margin-top:0.7rem; display:flex; flex-direction:column; gap:0.4rem;">
                                <?php foreach ($moisRetard as $mr): ?>
                                <div class="co-retard-ligne">
                                    <span style="color:#fff;">🔴 <?php echo htmlspecialchars(cotisation_libelle_mois($mr)); ?></span>
                                    <span style="color:#fca5a5; font-weight:700;"><?php echo number_format(cotisation_montant_periode($pdo, $mr), 2, ',', ' '); ?> €</span>
                                </div>
                                <?php endforeach; ?>
                                <div style="display:flex; justify-content:space-between; gap:0.6rem; padding:0.55rem 0.75rem; border-top:1px dashed rgba(255,255,255,0.2); margin-top:0.2rem;">
                                    <span style="color:#fff; font-weight:800;">Total à régulariser</span>
                                    <span style="color:#fca5a5; font-weight:800;"><?php echo number_format($totalRetard, 2, ',', ' '); ?> €</span>
                                </div>
                            </div>
                        </details>
                    </div>
                    <?php endif; ?>
                    <div class="co-hero glass-card">
                        <div style="color:var(--text-muted); font-size:0.85rem;">Cotisation de <?php echo htmlspecialchars(cotisation_libelle_mois($periode)); ?></div>
                        <div class="co-montant"><?php echo number_format($montant, 2, ',', ' '); ?> €</div>
                        <div style="margin-top:0.7rem;">
                            <?php if ($modeEff === 'exempt'): ?>
                                <span class="co-badge co-badge-info">🎗️ Dispensé(e) de cotisation</span>
                            <?php elseif ($modeEff === 'virement'): ?>
                                <span class="co-badge co-badge-info">🏦 Virement automatique</span>
                            <?php elseif ($estPaye): ?>
                                <span class="co-badge co-badge-ok">✅ Réglée<?php echo !empty($statutMois['paid_at']) ? ' le ' . date('d/m/Y', strtotime((string) $statutMois['paid_at'])) : ''; ?></span>
                            <?php else: ?>
                                <span class="co-badge co-badge-att">⏳ À régler</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($modeEff === 'mensuel' && !$estPaye): ?>
                            <?php if ($mode === 'virement'): ?>
                                <div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.6rem;">🏦 Votre virement automatique prendra le relais à partir du mois prochain. Ce mois-ci reste à régler normalement.</div>
                            <?php endif; ?>
                            <div style="margin-top:1.1rem;">
                                <?php if (cotisation_stripe_pret($pdo)): ?>
                                    <form action="cotisation.php" method="POST" style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="payer">
                                        <button type="submit" class="co-pay-btn">💳 Payer <?php echo number_format($montant, 2, ',', ' '); ?> € avec Stripe</button>
                                    </form>
                                    <div style="color:var(--text-muted); font-size:0.78rem; margin-top:0.5rem; text-align:center;">Paiement sécurisé par Stripe — aucune donnée bancaire ne transite par le Dahira.</div>
                                <?php else: ?>
                                    <div style="color:var(--text-muted); font-size:0.88rem;">💤 Le paiement en ligne n'est pas encore disponible. Rapprochez-vous du secrétariat.</div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($modeEff === 'virement'): ?>
                            <?php if ((int) date('j') <= 15): ?>
                            <div style="margin-top:0.9rem; padding:0.9rem 1.1rem; border-radius:10px; border:1px solid rgba(37,211,102,0.45); background:rgba(37,211,102,0.1);">
                                <div style="color:#fff; font-weight:800;">🎉 Félicitations et merci !</div>
                                <div style="color:var(--text-muted); font-size:0.86rem; margin-top:0.2rem;">Votre cotisation est assurée par <strong style="color:#7bd8a6;">virement automatique</strong>. Jërëjëf pour votre engagement et votre régularité. 🤲</div>
                            </div>
                            <?php else: ?>
                            <div style="color:var(--text-muted); font-size:0.86rem; margin-top:0.8rem;">Votre cotisation est prélevée par virement automatique : rien à faire de votre côté. Jërëjëf 🤲</div>
                            <?php endif; ?>
                        <?php elseif ($estPaye): ?>
                            <div style="color:#7bd8a6; font-size:0.9rem; margin-top:0.8rem;">Merci, votre cotisation du mois est à jour. 🙏</div>
                        <?php endif; ?>
                    </div>

                    <h3 style="color:var(--white); font-size:1.05rem; margin:1.2rem 0 0.7rem;">🧾 Historique</h3>
                    <?php if (empty($historique)): ?>
                        <?php if ($modeEff === 'virement'): ?>
                            <div class="glass-card" style="text-align:center; color:var(--text-muted); padding:1.4rem;">🏦 Vos cotisations sont assurées par <strong style="color:#7bd8a6;">virement automatique</strong> et sont considérées comme réglées. Jërëjëf 🤲</div>
                        <?php else: ?>
                            <div class="glass-card" style="text-align:center; color:var(--text-muted); padding:1.4rem;">Aucune cotisation enregistrée pour le moment.</div>
                        <?php endif; ?>
                    <?php else: ?>
                    <div class="co-hist">
                        <?php foreach ($historique as $h):
                            $hPer = (string) $h['periode'];
                            // Montant/méthode : ceux de la ligne enregistrée, sinon le tarif du mois (aucun paiement pointé)
                            $hMontant = isset($h['montant']) ? (float) $h['montant'] : cotisation_montant_periode($pdo, $hPer);
                            $hMethode = $h['methode'] ?? null;
                            // Virement : ne couvre que les mois postérieurs à la référence de bascule
                            $hCouvertVirement = $mode === 'virement' && ($virementRef === null || $hPer > $virementRef);
                            $hp = (($h['statut'] ?? '') === 'paye') || $hCouvertVirement;
                            // Type effectif de ce mois (mensuel si virement pas encore en vigueur)
                            $hMensuelLike = $mode === 'mensuel' || ($mode === 'virement' && !$hCouvertVirement);
                            // Mois écoulé et non réglé => en retard ; mois courant non réglé => en attente
                            $hRetard = !$hp && $hMensuelLike && $hPer < $periode;
                        ?>
                        <div class="glass-card co-hist-card<?php echo $hRetard ? ' co-hist-retard' : ''; ?>">
                            <div class="co-hist-main">
                                <div class="co-hist-mois"><?php echo htmlspecialchars(cotisation_libelle_mois($hPer)); ?><?php echo $hPer === $periode ? ' <span style="color:var(--text-muted); font-weight:400; font-size:0.78rem;">(en cours)</span>' : ''; ?></div>
                                <div class="co-hist-meta"><?php echo number_format($hMontant, 2, ',', ' '); ?> €<?php echo $hCouvertVirement ? ' · virement automatique' : (!empty($hMethode) ? ' · ' . htmlspecialchars((string) $hMethode) : ''); ?></div>
                            </div>
                            <span class="co-badge <?php echo $hp ? 'co-badge-ok' : ($hRetard ? 'co-badge-retard' : 'co-badge-att'); ?>"><?php echo $hp ? '✅ Réglée' : ($hRetard ? '🔴 En retard' : '⏳ En attente'); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
</body>
</html>
