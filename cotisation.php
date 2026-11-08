<?php
/**
 * Touba Lyon 2026 - 💶 Mes cotisations (espace membre)
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
$montantStandard = cotisation_montant_periode($pdo, $periode);
$montant = cotisation_montant_membre($pdo, $membreId, $periode);
$mode = cotisation_mode($pdo, $membreId);
$virementRef = ($mode === 'virement') ? cotisation_virement_ref($pdo, $membreId) : null;
// Mode effectif pour le mois courant : un virement pas encore entré en vigueur
// (mois courant <= référence de bascule) se comporte comme mensuel.
$modeEff = ($mode === 'virement' && $virementRef !== null && $periode <= $virementRef) ? 'mensuel' : $mode;

// Déclaration à la Finance d'un paiement du mois (virement ou versement en liquide)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['declarer_paiement', 'retirer_paiement'], true)) {
    $perDecl = trim((string) ($_POST['periode'] ?? ''));
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } elseif ($mode === 'exempt') {
        $error = "Vous êtes dispensé(e) de cotisation.";
    } elseif ($_POST['action'] === 'retirer_paiement') {
        cotisation_paiement_declaration_retirer($pdo, $membreId, $perDecl);
        $success = "Votre déclaration de cotisation a été retirée.";
    } elseif (!isset(cotisation_modes_declarables()[(string) ($_POST['methode'] ?? '')])) {
        $error = "Choisissez le mode de paiement : virement ou versement en liquide.";
    } elseif (($brutMontant = str_replace([' ', ','], ['', '.'], trim((string) ($_POST['montant'] ?? '')))) === '' || !is_numeric($brutMontant) || (float) $brutMontant < 1 || (float) $brutMontant > 10000) {
        $error = "Indiquez le montant de votre cotisation (entre 1 et 10 000 €).";
    } elseif (cotisation_paiement_declarer($pdo, $membreId, $perDecl, (string) $_POST['methode'], round((float) $brutMontant, 2))) {
        $success = "Merci ! Votre cotisation de " . cotisation_libelle_mois($perDecl) . " est déclarée à la Finance, qui la validera après vérification.";
    } else {
        $error = "Ce mois ne peut pas être déclaré (déjà réglé, déjà déclaré ou non dû).";
    }
}

// Déclaration à la Finance d'un virement automatique mis en place (jour du mois obligatoire)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['declarer_virement', 'annuler_declaration_virement'], true)) {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } elseif ($mode !== 'mensuel') {
        $error = $mode === 'virement' ? "Votre virement automatique est déjà activé." : "Vous êtes dispensé(e) de cotisation.";
    } elseif ($_POST['action'] === 'annuler_declaration_virement') {
        cotisation_virement_declaration_annuler($pdo, $membreId);
        $success = "Votre déclaration de virement automatique a été retirée.";
    } else {
        $dateDecl = trim((string) ($_POST['date_debut'] ?? ''));
        $dtDecl = DateTime::createFromFormat('Y-m-d', $dateDecl);
        if (!$dtDecl || $dtDecl->format('Y-m-d') !== $dateDecl) {
            $error = "Choisissez la date de début de votre virement (obligatoire).";
        } elseif (cotisation_virement_declarer($pdo, $membreId, $dateDecl)) {
            $success = "Merci ! Votre virement automatique (à partir du " . $dtDecl->format('d/m/Y') . ", le " . $dtDecl->format('j') . " de chaque mois) est déclaré à la Finance, qui l'activera après vérification.";
        } else {
            $error = "Une erreur technique est survenue. Veuillez réessayer.";
        }
    }
}

// Capacité de cotisation mensuelle : le membre indique le montant qu'il peut verser chaque mois
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_capacite') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } elseif ($mode === 'exempt') {
        $error = "Vous êtes dispensé(e) de cotisation.";
    } elseif (($_POST['effacer'] ?? '') === '1') {
        cotisation_set_capacite($pdo, $membreId, null);
        $success = "Capacité validée : votre cotisation suit le tarif défini par la Finance (" . number_format($montantStandard, 2, ',', ' ') . " € par mois)." . ($mode === 'mensuel' ? " Dernière étape : pensez au virement automatique selon votre capacité (RIB ci-dessous)." : '');
    } else {
        $brut = str_replace([' ', ','], ['', '.'], trim((string) ($_POST['capacite'] ?? '')));
        if (!is_numeric($brut) || (float) $brut < 1 || (float) $brut > 1000) {
            $error = "Indiquez un montant mensuel compris entre 1 et 1 000 €.";
        } elseif (cotisation_set_capacite($pdo, $membreId, abs(round((float) $brut, 2) - round($montantStandard, 2)) < 0.005 ? null : round((float) $brut, 2))) {
            $success = "Capacité validée : " . number_format(round((float) $brut, 2), 2, ',', ' ') . " € par mois. Elle s'applique à vos cotisations en cours et à venir." . ($mode === 'mensuel' ? " Dernière étape : pensez au virement automatique selon votre capacité (RIB ci-dessous)." : '');
        } else {
            $error = "Une erreur technique est survenue. Veuillez réessayer.";
        }
    }
    $montant = cotisation_montant_membre($pdo, $membreId, $periode);
}

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
$declareMois = $statutMois !== null && ($statutMois['statut'] ?? '') === 'declare';
$nonRecu = cotisation_virement_non_recu_info($pdo, $membreId); // virement signalé « non reçu » par la Finance

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
// Historique : mois passés, mois en cours, et mois réglés (même payés à l'avance) — pas les mois à venir non réglés
$moisCourantH = cotisation_periode_courante();
$periodesHistorique = array_values(array_filter($periodesHistorique, function ($p) use ($moisCourantH, $lignesDb) { return $p <= $moisCourantH || (($lignesDb[$p]['statut'] ?? '') === 'paye'); }));
rsort($periodesHistorique); // du plus récent au plus ancien
$periodesHistorique = array_slice($periodesHistorique, 0, 24);

$historique = [];
foreach ($periodesHistorique as $hPer) {
    $historique[] = ['periode' => $hPer] + ($lignesDb[$hPer] ?? []);
}

// Mois écoulés non réglés (retard) + total à régulariser (montant propre à l'année de chaque mois)
$moisRetard = cotisation_mois_retard($pdo, $membreId);
$nbRetard = count($moisRetard);
$totalRetard = 0.0;
foreach ($moisRetard as $mr) { $totalRetard += cotisation_montant_membre($pdo, $membreId, $mr); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💶 Mes cotisations — Touba Lyon</title>
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
        .co-choix { display:flex; gap:0.4rem; flex-wrap:wrap; }
        .co-choix input { position:absolute; opacity:0; pointer-events:none; }
        .co-choix label { display:inline-flex; align-items:center; gap:0.4rem; padding:0.5rem 0.95rem; border-radius:999px; border:1.5px solid rgba(212,175,55,0.38); background:rgba(255,255,255,0.03); color:var(--white); font-weight:700; font-size:0.85rem; cursor:pointer; user-select:none; -webkit-tap-highlight-color:transparent; transition:background .15s, border-color .15s, color .15s, box-shadow .15s; }
        .co-choix label:hover { border-color:var(--gold); background:rgba(212,175,55,0.1); }
        .co-choix input:checked + label { background:rgba(212,175,55,0.2); border-color:var(--gold); color:var(--gold); box-shadow:0 0 0 3px rgba(212,175,55,0.12); }
        .co-choix input:focus-visible + label { outline:2px solid var(--accent); outline-offset:2px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="admin-content">
                <h1 class="admin-page-title">💶 Mes cotisations</h1>

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
                                    <span style="color:#fca5a5; font-weight:700;"><?php echo number_format(cotisation_montant_membre($pdo, $membreId, $mr), 2, ',', ' '); ?> €</span>
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
                    <?php if (!empty($nonRecu)): ?>
                    <div class="co-retard-alerte glass-card" style="margin-bottom:1rem;">
                        <div style="display:flex; align-items:center; gap:0.9rem; flex-wrap:wrap;">
                            <div style="font-size:1.7rem;">🏦</div>
                            <div style="flex:1; min-width:200px;">
                                <div style="color:#fff; font-weight:800; font-size:1rem;">Votre virement automatique n'a pas été reçu</div>
                                <div style="color:var(--text-muted); font-size:0.86rem; margin-top:0.15rem; line-height:1.5;">La Finance n'a pas reçu votre virement<?php echo $nonRecu['periode'] !== '' ? ' de ' . htmlspecialchars(cotisation_libelle_mois($nonRecu['periode'])) : ''; ?> : le virement automatique a été désactivé. Réglez votre cotisation, ou remettez le virement en place et déclarez-le à nouveau à la Finance.</div>
                            </div>
                        </div>
                        <div style="margin-top:0.85rem;"><a href="#cot-virement" class="btn btn-primary btn-sm">🏦 Remettre en place le virement</a></div>
                    </div>
                    <?php endif; ?>
                    <?php $capAValider = ($mode !== 'exempt' && !cotisation_capacite_validee($pdo, $membreId)); ?>
                    <?php if ($capAValider): ?>
                    <details id="cap-alerte" class="cap-alerte" open>
                        <summary>⚠️ Validez votre capacité de cotisation mensuelle</summary>
                        <div style="color:var(--text-muted); font-size:0.88rem; line-height:1.55;">Par défaut, c'est le montant défini par la Finance : <strong style="color:#ffd873;"><?php echo number_format($montantStandard, 2, ',', ' '); ?> €</strong> par mois. Gardez-le ou indiquez ce que vous pouvez verser, puis validez.</div>
                        <div style="display:flex; flex-wrap:wrap; gap:0.6rem; margin-top:0.85rem;">
                            <form data-confirm="Valider <?php echo number_format($montantStandard, 2, ',', ' '); ?> € par mois comme votre capacité de cotisation mensuelle ?" action="cotisation.php" method="POST" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="set_capacite">
                                <input type="hidden" name="capacite" value="<?php echo htmlspecialchars((string) $montantStandard); ?>">
                                <button type="submit" class="btn btn-primary btn-sm">✅ Valider <?php echo number_format($montantStandard, 2, ',', ' '); ?> € par mois</button>
                            </form>
                            <a href="#cot-capacite" class="btn btn-secondary btn-sm">✏️ Choisir un autre montant</a>
                        </div>
                    </details>
                    <script>
                        // Alerte « capacité à valider » : on peut replier le groupe ; le choix est gardé pour la session (elle revient à la prochaine visite)
                        (function () {
                            var d = document.getElementById('cap-alerte');
                            if (!d) { return; }
                            try { if (sessionStorage.getItem('capAlerteFermee') === '1') { d.open = false; } } catch (e) {}
                            d.addEventListener('toggle', function () { try { sessionStorage.setItem('capAlerteFermee', d.open ? '0' : '1'); } catch (e) {} });
                        })();
                    </script>
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
                                <?php if (!empty($declareMois)): ?><span class="co-badge co-badge-att">⏳ Cotisation déclarée — en attente de validation</span><?php else: ?><span class="co-badge co-badge-att">⏳ À régler</span><?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($modeEff === 'mensuel' && !$estPaye && empty($declareMois)): ?>
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

                    <?php
                        $moisDeclarables = cotisation_mois_declarables($pdo, $membreId);
                        $paiementsDeclares = [];
                        try { $stPD = $pdo->prepare("SELECT periode, methode FROM cotisations WHERE membre_id = ? AND statut = 'declare' ORDER BY periode DESC"); $stPD->execute([$membreId]); $paiementsDeclares = $stPD->fetchAll(); } catch (Exception $e) { $paiementsDeclares = []; }
                        $modesDecl = cotisation_modes_declarables();
                    ?>
                    <?php if ($mode !== 'exempt' && (!empty($moisDeclarables) || !empty($paiementsDeclares))): ?>
                    <details id="cot-declaration" class="glass-card" style="margin-top:1.2rem;" open>
                        <summary>📨 <span style="display:flex; flex-direction:column; min-width:0; line-height:1.25;">J'ai cotisé : le déclarer à la Finance<small style="font-weight:600; font-size:0.78rem; color:var(--text-muted);">Virement bancaire ou versement en liquide</small></span></summary>
                        <?php foreach ($paiementsDeclares as $pd): ?>
                        <div class="dlist" style="margin-bottom:0.6rem;">
                            <div class="drow">
                                <div class="dcell dgrow"><strong style="color:#fff;"><?php echo htmlspecialchars(cotisation_libelle_mois((string) $pd['periode'])); ?></strong>
                                    <div style="color:var(--text-muted); font-size:0.8rem;"><?php echo htmlspecialchars($modesDecl[$pd['methode']] ?? (string) $pd['methode']); ?> — ⏳ en attente de validation par la Finance</div></div>
                                <div class="dcell">
                                    <form action="cotisation.php" method="POST" style="margin:0;" data-confirm="Retirer votre déclaration de cotisation de <?php echo htmlspecialchars(cotisation_libelle_mois((string) $pd['periode']), ENT_QUOTES); ?> ?">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="retirer_paiement">
                                        <input type="hidden" name="periode" value="<?php echo htmlspecialchars((string) $pd['periode']); ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm">↩️ Retirer</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!empty($moisDeclarables)): ?>
                        <div class="dlist">
                            <?php foreach ($moisDeclarables as $md): $mdMontant = cotisation_montant_membre($pdo, $membreId, $md); $mdRetard = ($md < $periode); ?>
                            <form data-confirm="Déclarer votre cotisation de <?php echo htmlspecialchars(cotisation_libelle_mois($md), ENT_QUOTES); ?> à la Finance ?" data-confirm-modele="Déclarer à la Finance votre cotisation de <?php echo htmlspecialchars(cotisation_libelle_mois($md), ENT_QUOTES); ?> : {montant} € par {mode} ?" action="cotisation.php" method="POST" class="drow" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="declarer_paiement">
                                <input type="hidden" name="periode" value="<?php echo htmlspecialchars($md); ?>">
                                <div class="dcell dgrow">
                                    <strong style="color:#fff;"><?php echo htmlspecialchars(cotisation_libelle_mois($md)); ?></strong>
                                    <div style="color:<?php echo $mdRetard ? '#fca5a5' : 'var(--text-muted)'; ?>; font-size:0.8rem;"><?php echo $mdRetard ? '🔴 En retard' : '⏳ À régler ce mois'; ?></div>
                                </div>
                                <div class="dcell">
                                    <input type="number" name="montant" class="form-input" min="1" max="10000" step="0.01" inputmode="decimal" required value="<?php echo number_format($mdMontant, 2, '.', ''); ?>" aria-label="Montant en euros pour <?php echo htmlspecialchars(cotisation_libelle_mois($md), ENT_QUOTES); ?>" style="width:7.5rem;"> €
                                </div>
                                <div class="dcell">
                                    <div class="co-choix" role="radiogroup" aria-label="Mode de paiement pour <?php echo htmlspecialchars(cotisation_libelle_mois($md), ENT_QUOTES); ?>">
                                        <?php foreach (['virement' => '🏦 Virement', 'especes' => '💵 Liquide'] as $k => $lib): $rid = 'cot-m-' . $md . '-' . $k; ?>
                                        <input type="radio" name="methode" id="<?php echo htmlspecialchars($rid); ?>" value="<?php echo htmlspecialchars($k); ?>" required><label for="<?php echo htmlspecialchars($rid); ?>"><?php echo htmlspecialchars($lib); ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="dcell"><button type="submit" class="btn btn-primary btn-sm">📨 Déclarer</button></div>
                            </form>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </details>
                    <?php endif; ?>
                    <?php if ($mode !== 'exempt'): $capacite = cotisation_capacite($pdo, $membreId); $capJusteValidee = ($mode === 'mensuel' && (!empty($_GET['cap_ok']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_capacite' && !empty($success)))); ?>
                    <details id="cot-capacite" class="glass-card" style="margin-top:1.2rem;"<?php echo ((!empty($error) && ($_POST['action'] ?? '') === 'set_capacite') || !cotisation_capacite_validee($pdo, $membreId) || $capJusteValidee || !empty($nonRecu)) ? ' open' : ''; ?>>
                        <summary>💶 Ma capacité mensuelle</summary>
                        <p style="color:var(--text-muted); font-size:0.88rem; line-height:1.55; margin:0 0 0.9rem;">Indiquez le montant que vous pouvez verser chaque mois. Par défaut, c'est le montant défini par la Finance (<?php echo number_format($montantStandard, 2, ',', ' '); ?> €) ; le montant choisi le remplace pour vos cotisations en cours, en retard et à venir ; les mois déjà réglés ne changent pas.</p>
                        <form data-confirm="Valider ce montant comme votre capacité de cotisation mensuelle ?" data-confirm-modele="Valider {montant} € par mois comme votre capacité de cotisation mensuelle ?" action="cotisation.php" method="POST" style="margin:0; display:flex; flex-wrap:wrap; gap:0.7rem; align-items:flex-end;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="set_capacite">
                            <div class="form-group" style="flex:1 1 180px; margin:0;">
                                <label class="form-label" for="cot-capacite-input">Mon montant mensuel (€)</label>
                                <input type="number" id="cot-capacite-input" name="capacite" class="form-input" min="1" max="1000" step="0.5" inputmode="decimal" required value="<?php echo htmlspecialchars((string) ($capacite !== null ? $capacite : $montantStandard)); ?>" placeholder="Ex : <?php echo htmlspecialchars((string) $montantStandard); ?>">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><?php echo cotisation_capacite_validee($pdo, $membreId) ? '💾 Enregistrer' : '✅ Valider ce montant'; ?></button>
                        </form>
                        <?php if ($capacite !== null): ?>
                        <form action="cotisation.php" method="POST" style="margin:0.7rem 0 0;" data-confirm="Revenir au tarif standard (<?php echo number_format($montantStandard, 2, ',', ' '); ?> € par mois) ?">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="set_capacite">
                            <input type="hidden" name="effacer" value="1">
                            <button type="submit" class="btn btn-secondary btn-sm">↩️ Revenir au tarif standard</button>
                        </form>
                        <?php endif; ?>
                    </details>
                    <?php if ($mode === 'mensuel'):
                        // Suggestion : virement automatique (pas encore activé par la Finance pour ce membre)
                        $rib = cotisation_rib($pdo);
                        $nomVir = '';
                        try { $stN = $pdo->prepare("SELECT prenom, nom FROM membres WHERE id = ?"); $stN->execute([$membreId]); $rN = $stN->fetch(); $nomVir = $rN ? trim(($rN['prenom'] ?? '') . ' ' . ($rN['nom'] ?? '')) : ''; } catch (Exception $e) { $nomVir = ''; }
                        $libelleVir = 'Cotisation ' . $nomVir;
                        $declaration = cotisation_virement_declaration($pdo, $membreId);
                    ?>
                    <details id="cot-virement-grp" class="glass-card" style="margin-top:1.2rem;"<?php echo ($capJusteValidee || !empty($nonRecu) || $declaration !== null || (!empty($error) && in_array(($_POST['action'] ?? ''), ['declarer_virement', 'annuler_declaration_virement'], true))) ? ' open' : ''; ?>>
                        <summary>🏦 Virement automatique<?php if ($declaration !== null): ?> <small style="font-weight:600; font-size:0.78rem; color:#ffd873;">· déclaré, en attente de la Finance</small><?php endif; ?></summary>
                    <div id="cot-virement" style="margin-top:0.8rem; border-radius:12px; padding:1.1rem 1.3rem; border:1px solid rgba(45,140,255,0.45); background:rgba(45,140,255,0.07);">
                        <?php if ($capJusteValidee && $declaration === null): ?><div style="margin-bottom:0.8rem; padding:0.6rem 0.9rem; border-radius:10px; border:1px solid rgba(37,211,102,0.45); background:rgba(37,211,102,0.1); color:#7bd8a6; font-weight:700; font-size:0.9rem;">✅ Capacité validée — dernière étape : pensez au virement automatique</div><?php endif; ?>
                        <?php if (!empty($nonRecu)): ?><div style="margin-bottom:0.8rem; padding:0.6rem 0.9rem; border-radius:10px; border:1px solid rgba(255,193,7,0.5); background:rgba(255,193,7,0.1); color:#ffd873; font-weight:700; font-size:0.9rem;">⚠️ Votre dernier virement n'a pas été reçu : mettez-le en place à nouveau, puis déclarez-le à la Finance.</div><?php endif; ?>
                        <?php if ($declaration === null): ?>
                        <div style="display:flex; align-items:center; gap:0.9rem; flex-wrap:wrap;">
                            <div style="font-size:1.7rem;">🏦</div>
                            <div style="flex:1; min-width:200px;">
                                <div style="color:#fff; font-weight:800;">Pensez au virement automatique</div>
                                <div style="color:var(--text-muted); font-size:0.88rem; margin-top:0.2rem; line-height:1.55;">Pour ne plus avoir à y penser, mettez en place un <strong style="color:#8ec5ff;">virement permanent mensuel</strong> depuis votre banque vers le compte du Dahira, <strong style="color:#fff;">selon votre capacité</strong>, avec le libellé « <?php echo htmlspecialchars($libelleVir); ?> ». Une fois le virement en place, la Finance l'active sur votre compte (« virement automatique »).</div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <details class="co-rib" style="margin-top:0.9rem;"<?php echo ($capJusteValidee || !empty($nonRecu)) ? ' open' : ''; ?>>
                            <summary>📄 Voir le RIB du Dahira</summary>
                            <div class="co-rib-liste" style="display:flex; flex-direction:column; gap:0.45rem;">
                                <?php
                                    $lignesRib = [
                                        ['Titulaire', $rib['titulaire'], false],
                                        ['IBAN', $rib['iban'], true],
                                        ['BIC / SWIFT', $rib['bic'], true],
                                        ['Libellé du virement', $libelleVir, true],
                                        ['Montant mensuel', 'Selon votre capacité', false],
                                    ];
                                    foreach ($lignesRib as $lr):
                                ?>
                                <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap; padding:0.5rem 0.75rem; border:1px solid rgba(255,255,255,0.1); border-radius:10px; background:rgba(255,255,255,0.03);">
                                    <span style="color:var(--text-muted); font-size:0.8rem; flex:0 0 150px;"><?php echo htmlspecialchars($lr[0]); ?></span>
                                    <strong style="color:#fff; flex:1 1 200px; min-width:0; word-break:break-word; font-size:0.92rem;"><?php echo htmlspecialchars($lr[1]); ?></strong>
                                    <?php if ($lr[2]): ?><button type="button" class="btn btn-secondary btn-sm" data-copier="<?php echo htmlspecialchars($lr[1], ENT_QUOTES); ?>">Copier</button><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                                <div style="color:var(--text-muted); font-size:0.8rem; line-height:1.6; padding:0.4rem 0.2rem 0;">
                                    Domiciliation : <?php echo nl2br(htmlspecialchars($rib['banque'])); ?><br>
                                    Adresse du titulaire : <?php echo nl2br(htmlspecialchars($rib['adresse'])); ?><br>
                                    Code banque <?php echo htmlspecialchars($rib['code_banque']); ?> · Code guichet <?php echo htmlspecialchars($rib['code_guichet']); ?> · N° de compte <?php echo htmlspecialchars($rib['numero_compte']); ?> · Clé RIB <?php echo htmlspecialchars($rib['cle_rib']); ?>
                                </div>
                            </div>
                        </details>
                        <div style="margin-top:0.9rem; padding-top:0.9rem; border-top:1px solid rgba(255,255,255,0.1);">
                            <?php if ($declaration !== null): ?>
                                <div style="display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap;">
                                    <span class="co-badge co-badge-att">⏳ Déclaré à la Finance : virement <?php echo $declaration['date'] !== '' ? 'à partir du ' . htmlspecialchars(date('d/m/Y', strtotime($declaration['date']))) . ' — ' : ''; ?>le <?php echo (int) $declaration['jour']; ?> de chaque mois — en attente de confirmation</span>
                                    <form action="cotisation.php" method="POST" style="margin:0;" data-confirm="Retirer votre déclaration de virement automatique ?">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="annuler_declaration_virement">
                                        <button type="submit" class="btn btn-secondary btn-sm">↩️ Retirer ma déclaration</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div style="color:#fff; font-weight:700; font-size:0.92rem; margin-bottom:0.5rem;">J'ai mis en place mon virement</div>
                                <form data-confirm="Déclarer à la Finance que votre virement automatique est mis en place ?" action="cotisation.php" method="POST" style="margin:0; display:flex; flex-wrap:wrap; gap:0.7rem; align-items:flex-end;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="declarer_virement">
                                    <div class="form-group" style="flex:1 1 200px; margin:0;">
                                        <label class="form-label" for="cot-vir-jour">Date de début du virement <span style="color:var(--danger)">*</span></label>
                                        <input type="date" id="cot-vir-jour" name="date_debut" class="form-input" required max="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm">📨 Déclarer à la Finance</button>
                                </form>
                                <div style="color:var(--text-muted); font-size:0.78rem; margin-top:0.4rem; line-height:1.5;">Le jour de cette date est repris chaque mois : la Finance est prévenue ce jour-là pour confirmer la bonne réception de votre virement.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    </details>
                    <?php endif; ?>
                    <?php endif; ?>
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
                            $hMontant = isset($h['montant']) ? (float) $h['montant'] : cotisation_montant_membre($pdo, $membreId, $hPer);
                            $hMethode = $h['methode'] ?? null;
                            // Virement : ne couvre que les mois postérieurs à la référence de bascule
                            $hCouvertVirement = $mode === 'virement' && ($virementRef === null || $hPer > $virementRef);
                            $hp = (($h['statut'] ?? '') === 'paye') || $hCouvertVirement;
                            $hDecl = !$hp && (($h['statut'] ?? '') === 'declare');
                            // Type effectif de ce mois (mensuel si virement pas encore en vigueur)
                            $hMensuelLike = $mode === 'mensuel' || ($mode === 'virement' && !$hCouvertVirement);
                            // Mois écoulé et non réglé => en retard ; mois courant non réglé => en attente
                            $hRetard = !$hp && !$hDecl && $hMensuelLike && $hPer < $periode;
                        ?>
                        <div class="glass-card co-hist-card<?php echo $hRetard ? ' co-hist-retard' : ''; ?>">
                            <div class="co-hist-main">
                                <div class="co-hist-mois"><?php echo htmlspecialchars(cotisation_libelle_mois($hPer)); ?><?php echo $hPer === $periode ? ' <span style="color:var(--text-muted); font-weight:400; font-size:0.78rem;">(en cours)</span>' : ''; ?></div>
                                <div class="co-hist-meta"><?php echo number_format($hMontant, 2, ',', ' '); ?> €<?php echo $hCouvertVirement ? ' · virement automatique' : (!empty($hMethode) ? ' · ' . htmlspecialchars((string) $hMethode) : ''); ?></div>
                            </div>
                            <span class="co-badge <?php echo $hp ? 'co-badge-ok' : ($hRetard ? 'co-badge-retard' : 'co-badge-att'); ?>"><?php echo $hp ? '✅ Réglée' : ($hDecl ? '📨 Déclarée — à valider' : ($hRetard ? '🔴 En retard' : '⏳ En attente')); ?></span>
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
    <script>
        // Bouton « Copier » du RIB
        document.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-copier]') : null;
            if (!b) { return; }
            var v = b.getAttribute('data-copier') || '';
            var fini = function () { var o = b.getAttribute('data-libelle') || b.textContent; b.setAttribute('data-libelle', o); b.textContent = 'Copié ✓'; setTimeout(function () { b.textContent = o; }, 1600); };
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(v).then(fini, fini); }
            else { var t = document.createElement('textarea'); t.value = v; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); } catch (x) {} document.body.removeChild(t); fini(); }
        });
    </script>
    <script src="modern-date.js" defer></script>
    <script>
        // Confirmations : le message reprend le montant et le mode de paiement choisis (modèle data-confirm-modele)
        (function () {
            var MODES = { virement: 'virement', especes: 'versement en liquide' };
            function maj(f) {
                var t = f.getAttribute('data-confirm-modele');
                if (!t) { return; }
                var m = f.querySelector('input[name="montant"], input[name="capacite"]');
                var c = f.querySelector('input[name="methode"]:checked');
                var v = m ? parseFloat(String(m.value).replace(',', '.')) : NaN;
                f.setAttribute('data-confirm', t.replace('{montant}', isNaN(v) ? '…' : v.toFixed(2).replace('.', ',')).replace('{mode}', c ? (MODES[c.value] || c.value) : '…'));
            }
            function tout() { Array.prototype.forEach.call(document.querySelectorAll('form[data-confirm-modele]'), maj); }
            document.addEventListener('input', function (e) { if (e.target && e.target.form) { maj(e.target.form); } });
            document.addEventListener('change', function (e) { if (e.target && e.target.form) { maj(e.target.form); } });
            document.addEventListener('submit', function (e) { if (e.target && e.target.getAttribute && e.target.getAttribute('data-confirm-modele')) { maj(e.target); } }, true);
            tout();
            document.addEventListener('soft:updated', tout);
        })();
    </script>
</body>
</html>
