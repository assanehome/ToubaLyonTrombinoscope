<?php
/**
 * Touba Lyon 2026 - 💶 Cotisations (administration)
 *
 * Réglages (montant, clés Stripe), mode de cotisation par membre
 * (mensuel / virement automatique / exempt), bilan mensuel et pointage.
 * Réservé aux administrateurs.
 */
require_once __DIR__ . '/cotisation_guard.php'; // admins + responsables commission Finance
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/cotisation_helper.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_params') {
                $m = str_replace(',', '.', trim($_POST['montant'] ?? ''));
                $anPar = (int) ($_POST['annee'] ?? 0);
                $anneesOkPar = cotisation_annees($pdo);
                if (!in_array($anPar, $anneesOkPar, true)) {
                    $error = "Année invalide.";
                } elseif (!is_numeric($m) || (float) $m <= 0) {
                    $error = "Le montant doit être un nombre positif.";
                } else {
                    // Montant défini pour l'année sélectionnée (chaque année a le sien)
                    dahira_set_param($pdo, 'cotisation_montant_' . $anPar, (string) (float) $m);
                    dahira_set_param($pdo, 'stripe_pk', trim($_POST['stripe_pk'] ?? ''));
                    $sk = trim($_POST['stripe_sk'] ?? '');
                    // Ne pas écraser la clé secrète si le champ est laissé vide
                    if ($sk !== '') { dahira_set_param($pdo, 'stripe_sk', $sk); }
                    $success = "Paramètres enregistrés — cotisation $anPar : " . number_format((float) $m, 2, ',', ' ') . " €.";
                }
            } elseif ($action === 'set_mode') {
                $mid = (int) ($_POST['membre_id'] ?? 0);
                $mode = $_POST['mode'] ?? 'mensuel';
                if (!in_array($mode, ['mensuel', 'virement', 'exempt'], true)) { $mode = 'mensuel'; }
                $jour = (int) ($_POST['jour'] ?? 0);
                if ($mode === 'virement' && ($jour < 1 || $jour > 31)) {
                    $error = "Indiquez le jour du mois prévu pour le virement automatique (entre 1 et 31).";
                } elseif ($mid > 0) {
                    cotisation_set_mode($pdo, $mid, $mode, $mode === 'virement' ? $jour : null);
                    $success = "Mode de cotisation mis à jour."
                        . ($mode === 'virement' ? " Virement automatique prévu le " . $jour . " de chaque mois, à partir des mois à venir." : "");
                }
            } elseif ($action === 'set_virement_jour') {
                $mid = (int) ($_POST['membre_id'] ?? 0);
                $jour = (int) ($_POST['jour'] ?? 0);
                if ($mid > 0 && cotisation_set_virement_jour($pdo, $mid, $jour)) {
                    $success = "Date du virement automatique mise à jour (le " . $jour . " de chaque mois).";
                } else {
                    $error = "Jour invalide (entre 1 et 31).";
                }
            } elseif ($action === 'virement_recu' || $action === 'virement_non_recu') {
                $mid = (int) ($_POST['membre_id'] ?? 0);
                $per = (string) ($_POST['periode'] ?? '');
                if ($mid <= 0 || !preg_match('/^\d{4}-\d{2}$/', $per)) {
                    $error = "Demande invalide.";
                } elseif ($action === 'virement_recu') {
                    $success = cotisation_virement_confirmer($pdo, $mid, $per) ? "Virement confirmé : cotisation de " . cotisation_libelle_mois($per) . " réglée." : "Ce membre n'est plus en virement automatique.";
                } else {
                    if (cotisation_virement_non_recu($pdo, $mid, $per)) {
                        $success = "Virement non reçu : le membre a été prévenu et le virement automatique est désactivé.";
                    } else {
                        $error = "Ce membre n'est plus en virement automatique.";
                    }
                }
            } elseif ($action === 'mark_paid') {
                $mid = (int) ($_POST['membre_id'] ?? 0);
                $per = (string) ($_POST['periode'] ?? '');
                $meth = $_POST['methode'] ?? 'especes';
                if (!in_array($meth, ['especes', 'virement', 'cheque', 'stripe'], true)) { $meth = 'especes'; }
                if ($mid > 0 && preg_match('/^\d{4}-\d{2}$/', $per)) {
                    cotisation_marquer_paye($pdo, $mid, $per, $meth, '');
                    $success = "Cotisation pointée comme réglée.";
                }
            } elseif ($action === 'unmark_paid') {
                $mid = (int) ($_POST['membre_id'] ?? 0);
                $per = (string) ($_POST['periode'] ?? '');
                if ($mid > 0 && preg_match('/^\d{4}-\d{2}$/', $per)) {
                    // Annulation du pointage : on retire complètement la ligne de l'historique
                    $pdo->prepare("DELETE FROM cotisations WHERE membre_id = ? AND periode = ?")->execute([$mid, $per]);
                    $success = "Pointage annulé et retiré de l'historique.";
                }
            } elseif ($action === 'send_recap_mail') {
                // E-mail de relance (récapitulatif), composé/édité par l'admin avant envoi
                $mid = (int) ($_POST['membre_id'] ?? 0);
                $sujet = trim($_POST['sujet'] ?? '');
                $corps = trim($_POST['corps'] ?? '');
                if ($mid <= 0 || $sujet === '' || $corps === '') {
                    $error = "Sujet et message sont requis pour l'envoi.";
                } else {
                    $st = $pdo->prepare("SELECT prenom, nom, email FROM membres WHERE id = ?");
                    $st->execute([$mid]);
                    $mrow = $st->fetch();
                    $nomAff = $mrow ? trim(($mrow['prenom'] ?? '') . ' ' . ($mrow['nom'] ?? '')) : '';
                    if (!$mrow || empty($mrow['email'])) {
                        $error = "Ce membre n'a pas d'adresse e-mail enregistrée.";
                    } else {
                        $inner = '<div style="font-family:Arial,sans-serif;color:#333;line-height:1.6;">' . nl2br(htmlspecialchars($corps)) . '</div>';
                        $html = dahira_email_wrap($inner, 'Cotisation');
                        if (send_smtp_mail($mrow['email'], $nomAff !== '' ? $nomAff : 'Cher membre', $sujet, $html)) {
                            $success = "E-mail envoyé à " . ($nomAff !== '' ? $nomAff : 'ce membre') . ".";
                        } else {
                            $error = "L'envoi de l'e-mail a échoué.";
                        }
                    }
                }
            } else {
                $error = "Action non reconnue.";
            }
        } catch (Exception $e) {
            error_log('Touba Lyon admin_cotisations : ' . $e->getMessage());
            $error = "Une erreur technique est survenue.";
        }
    }
}

// Année sélectionnée (filtre au-dessus du mois : borne les mois du sélecteur)
$anneesDispo = cotisation_annees($pdo);
$annee = (int) ($_GET['annee'] ?? 0);
if (!in_array($annee, $anneesDispo, true)) {
    $annee = (int) date('Y');
    if (!in_array($annee, $anneesDispo, true)) { $annee = $anneesDispo[0] ?? (int) date('Y'); }
}

// Mois disponibles pour le sélecteur : tous les mois de l'année choisie
// (année complète), bornés seulement au début de saison — pas de mois avant
// août 2026, mais tous les mois à venir sont listés (préparation à l'avance).
$debutMois = cotisation_debut($pdo);
$moisCourantGlobal = cotisation_periode_courante();
$moisOptions = [];
for ($m = 1; $m <= 12; $m++) {
    $per = sprintf('%04d-%02d', $annee, $m);
    if ($per >= $debutMois) { $moisOptions[] = $per; }
}

// Période sélectionnée : doit appartenir aux mois de l'année choisie, sinon
// on retombe sur le mois courant (s'il y est) ou le plus récent de l'année.
$periode = (string) ($_GET['periode'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $periode) || !in_array($periode, $moisOptions, true)) {
    $periode = in_array($moisCourantGlobal, $moisOptions, true) ? $moisCourantGlobal : ($moisOptions[0] ?? $moisCourantGlobal);
}
$montant = cotisation_montant_periode($pdo, $periode);
$montantAnnee = cotisation_montant_annee($pdo, $annee);
$stripePret = cotisation_stripe_pret($pdo);
$bilan = cotisation_bilan($pdo, $periode);
$bilanAnnee = cotisation_bilan_annee($pdo, $annee);
$totauxMethode = cotisation_totaux_methode($pdo, $periode);
$totauxMethodeAnnee = cotisation_totaux_methode_annee($pdo, $annee);

// Un mois déjà écoulé (antérieur au mois en cours) : les cotisations mensuelles
// non réglées y sont « en retard », et non plus simplement « en attente ».
$periodePassee = $periode < cotisation_periode_courante();
$attenteCount = $periodePassee ? 0 : (int) $bilan['attente'];
$retardCount = $periodePassee ? (int) $bilan['attente'] : 0;

// Liste des membres avec mode et statut de la période
$membres = [];
try {
    $membres = $pdo->query("SELECT id, prenom, nom, photo_path, telephone, email FROM membres WHERE status = 'approved' ORDER BY nom ASC, prenom ASC")->fetchAll();
} catch (Exception $e) { $membres = []; }
$modes = [];
try {
    $st = $pdo->query("SELECT membre_id, mode FROM cotisation_config");
    while ($r = $st->fetch()) { $modes[(int) $r['membre_id']] = (string) $r['mode']; }
} catch (Exception $e) { $modes = []; }
$payes = [];
try {
    $st = $pdo->prepare("SELECT membre_id, statut, methode, paid_at FROM cotisations WHERE periode = ?");
    $st->execute([$periode]);
    while ($r = $st->fetch()) { $payes[(int) $r['membre_id']] = $r; }
} catch (Exception $e) { $payes = []; }

// Virements automatiques attendus pour la période (à confirmer par la Finance)
$virements = cotisation_virements_attendus($pdo, $periode);
$virementsAConfirmer = count(array_filter($virements, function ($v) { return !$v['recu']; }));

$skDefini = trim(dahira_param($pdo, 'stripe_sk', '')) !== '';
$pkVal = trim(dahira_param($pdo, 'stripe_pk', ''));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💶 Cotisations — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <script src="password-toggle.js" defer></script>
    <style>
        .cot-wrap { max-width: 1000px; margin: 2rem auto; }
        .cot-stats { display:grid; grid-template-columns:repeat(5,1fr); gap:0.75rem; margin-bottom:1.4rem; }
        @media (max-width:900px){ .cot-stats { grid-template-columns:repeat(3,1fr); } }
        @media (max-width:600px){ .cot-stats { grid-template-columns:repeat(2,1fr); } }
        .cot-stats-4 { grid-template-columns:repeat(4,1fr); }
        @media (max-width:600px){ .cot-stats-4 { grid-template-columns:repeat(2,1fr); } }
        /* Filtre Année / Mois : une ligne pleine largeur chacun sur mobile */
        .cot-filtre-mois-form { display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-bottom:1rem; border:1.5px solid rgba(212,175,55,0.3); border-radius:14px; padding:0.9rem 1.1rem; background:rgba(255,255,255,0.02); }
        .cot-filtre-groupe { display:flex; align-items:center; gap:0.5rem; }
        @media (max-width:600px){
            .cot-filtre-groupe { flex-basis:100%; width:100%; }
            .cot-filtre-groupe .cot-filter-select { flex:1 1 auto; width:100%; }
        }
        .cot-stat { text-align:center; padding:1rem; border:1.5px solid rgba(212,175,55,0.3); }
        /* Sélecteurs de filtre (année, mois) : bordure bien visible */
        .cot-filter-select { background:rgba(255,255,255,0.05); border:1.5px solid rgba(212,175,55,0.55); color:var(--white); border-radius:8px; padding:0.4rem 0.7rem; color-scheme:dark; }
        .cot-filter-select:hover, .cot-filter-select:focus { border-color:rgba(212,175,55,0.7); }
        .cot-stat .v { font-size:1.6rem; font-weight:800; line-height:1.1; }
        .cot-stat .l { color:var(--text-muted); font-size:0.8rem; margin-top:0.2rem; }
        details.glass-card { padding:0.9rem 1.1rem; }
        table.cot-table { width:100%; border-collapse:collapse; font-size:0.86rem; }
        table.cot-table th, table.cot-table td { border:1px solid var(--glass-border); padding:0.5rem 0.6rem; text-align:left; vertical-align:middle; }
        table.cot-table th { background:rgba(255,255,255,0.04); color:var(--white); position:sticky; top:0; }
        table.cot-table td { color:var(--text-muted); }
        table.cot-table select { background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--white); border-radius:8px; padding:0.3rem 0.4rem; font-size:0.8rem; color-scheme:dark; }
        .cot-badge { font-size:0.72rem; padding:0.15rem 0.5rem; border-radius:50px; white-space:nowrap; }
        .cot-ok { background:rgba(37,211,102,0.15); color:#7bd8a6; border:1px solid rgba(37,211,102,0.4); }
        .cot-att { background:rgba(255,193,7,0.15); color:#ffd873; border:1px solid rgba(255,193,7,0.4); }
        .cot-retard { background:rgba(191,33,33,0.18); color:#fca5a5; border:1px solid rgba(191,33,33,0.5); }
        .cot-info { background:rgba(45,140,255,0.15); color:#8ec5ff; border:1px solid rgba(45,140,255,0.4); }
        /* Carte membre en retard : liseré rouge pour attirer l'œil */
        .cot-card.cot-row-retard { border-color:rgba(191,33,33,0.5); background:rgba(191,33,33,0.06); }
        /* Boutons de contact (appeler, WhatsApp rappel/récap, e-mail récap) */
        .cot-contact { display:flex; gap:0.35rem; }
        .cot-contact-btn {
            display:inline-flex; align-items:center; justify-content:center;
            width:34px; height:34px; border-radius:10px; font-size:1.05rem; line-height:1;
            background:rgba(255,255,255,0.05); border:1px solid var(--glass-border);
            color:var(--white); text-decoration:none; cursor:pointer; transition:var(--transition);
        }
        .cot-contact-btn:hover { border-color:var(--accent); background:rgba(212,175,55,0.12); transform:translateY(-1px); }
        .pl-chevron { transition:transform 0.2s; } details[open] > summary .pl-chevron { transform:rotate(90deg); }
        /* Bilan annuel : apparition animée du contenu à l'ouverture */
        @keyframes cotBilanIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        .cot-bilan-annee[open] .cot-stats .cot-stat { animation:cotBilanIn 0.4s cubic-bezier(0.22,1,0.36,1) both; }
        .cot-bilan-annee[open] .cot-stats .cot-stat:nth-child(2){ animation-delay:0.05s; }
        .cot-bilan-annee[open] .cot-stats .cot-stat:nth-child(3){ animation-delay:0.10s; }
        .cot-bilan-annee[open] .cot-stats .cot-stat:nth-child(4){ animation-delay:0.15s; }
        .cot-bilan-annee[open] .cot-stats .cot-stat:nth-child(5){ animation-delay:0.20s; }
        @media (prefers-reduced-motion: reduce){ .cot-bilan-annee[open] .cot-stats .cot-stat { animation:none !important; } }
        /* Liste des membres en cartes (compatible mobile) */
        .cot-list { display:flex; flex-direction:column; gap:0.6rem; }
        .cot-card { display:flex; align-items:center; gap:0.7rem; flex-wrap:wrap; border:1px solid var(--glass-border); border-radius:12px; padding:0.7rem 0.85rem; background:rgba(255,255,255,0.02); }
        .cot-card .cot-name { display:flex; align-items:center; gap:0.55rem; flex:1 1 200px; min-width:0; color:var(--white); font-weight:700; font-size:0.9rem; }
        .cot-card .cot-name span { min-width:0; word-break:break-word; }
        .cot-av { width:38px; height:38px; border-radius:50%; object-fit:cover; border:2px solid var(--accent); flex-shrink:0; }
        .cot-av-ph { display:inline-flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#d4af37,#b8902f); color:#0c241a; font-weight:800; font-size:0.85rem; text-transform:uppercase; }
        .cot-cell { display:flex; align-items:center; gap:0.4rem; }
        .cot-card select { background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--white); border-radius:8px; padding:0.35rem 0.5rem; font-size:0.82rem; color-scheme:dark; }
        .cot-card select option { background-color:#0c241a; color:#fff; }
        .cot-card .btn { font-size:0.76rem; padding:0.3rem 0.6rem; }
        @media (max-width:640px){
            .cot-card { flex-direction:column; align-items:stretch; }
            .cot-card .cot-name { flex-basis:auto; }
            .cot-card .cot-cell, .cot-card .cot-act { width:100%; }
            .cot-card .cot-cell form, .cot-card select, .cot-card .cot-act form, .cot-card .btn { width:100%; box-sizing:border-box; justify-content:center; }
            .cot-card .cot-contact { justify-content:center; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__cotAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="admin-content">
                <h1 class="admin-page-title">💶 Cotisations</h1>

                <?php if (!empty($success)): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin:1rem 0;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin:1rem 0;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <div class="cot-wrap">
                    <!-- Bilan annuel (indicateur année, agrégé sur tous les mois écoulés de l'année) -->
                    <details id="cot-details-bilan-annee" class="glass-card cot-bilan-annee" style="margin-bottom:1.4rem;">
                        <summary style="color:var(--white); font-size:1.05rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">📅 Bilan annuel <?php echo (int) $annee; ?> <span class="pl-chevron" style="color:var(--text-muted);">▸</span></summary>
                        <form method="GET" action="admin_cotisations.php" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-top:0.9rem;" onclick="event.stopPropagation()">
                            <input type="hidden" name="periode" value="<?php echo htmlspecialchars($periode); ?>">
                            <label style="color:var(--text-muted); font-size:0.85rem;">Filtrer par année :</label>
                            <select name="annee" onchange="this.form.submit()" class="cot-filter-select">
                                <?php foreach ($anneesDispo as $an): ?>
                                    <option value="<?php echo (int) $an; ?>" <?php echo $an === $annee ? "selected" : ""; ?>><?php echo (int) $an; ?><?php echo $an > (int) date("Y") ? " (à venir)" : ""; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <div class="cot-stats" style="margin-top:0.9rem;">
                            <div class="glass-card cot-stat"><div class="v" style="color:#7bd8a6;"><?php echo (int) $bilanAnnee['payes']; ?></div><div class="l">✅ Mois réglés</div></div>
                            <div class="glass-card cot-stat" style="<?php echo (int) $bilanAnnee['retard'] > 0 ? 'border-color:rgba(191,33,33,0.5);' : ''; ?>"><div class="v" style="color:#fca5a5;"><?php echo (int) $bilanAnnee['retard']; ?></div><div class="l">🔴 Mois en retard</div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:#ffd873;"><?php echo (int) $bilanAnnee['attente']; ?></div><div class="l">⏳ En attente (mois en cours)</div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:#8ec5ff;"><?php echo (int) $bilanAnnee['virement']; ?></div><div class="l">🏦 Virement automatique<?php if (!empty($bilanAnnee['virement_attente'])): ?> · <strong style="color:#8ec5ff;"><?php echo (int) $bilanAnnee['virement_attente']; ?> à confirmer</strong><?php endif; ?></div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:var(--accent);"><?php echo number_format($bilanAnnee['total'], 2, ',', ' '); ?> €</div><div class="l">💰 Encaissé (année)</div></div>
                        </div>
                        <div style="color:var(--text-muted); font-size:0.78rem; margin:0.9rem 0 0.5rem;">💳 Total par mode de paiement (année)</div>
                        <div class="cot-stats cot-stats-4">
                            <div class="glass-card cot-stat"><div class="v" style="color:var(--white); font-size:1.2rem;"><?php echo number_format($totauxMethodeAnnee['especes'], 2, ',', ' '); ?> €</div><div class="l">💵 Espèces</div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:var(--white); font-size:1.2rem;"><?php echo number_format($totauxMethodeAnnee['virement'], 2, ',', ' '); ?> €</div><div class="l">🏦 Virement</div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:var(--white); font-size:1.2rem;"><?php echo number_format($totauxMethodeAnnee['cheque'], 2, ',', ' '); ?> €</div><div class="l">📝 Chèque</div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:var(--white); font-size:1.2rem;"><?php echo number_format($totauxMethodeAnnee['stripe'], 2, ',', ' '); ?> €</div><div class="l">💳 Stripe</div></div>
                        </div>
                    </details>

                    <!-- Sélecteurs Année + Mois, puis bilan du mois -->
                    <form method="GET" action="admin_cotisations.php" class="cot-filtre-mois-form">
                        <div class="cot-filtre-groupe">
                            <label style="color:var(--white); font-weight:700;">📆 Année :</label>
                            <select name="annee" onchange="this.form.submit()" class="cot-filter-select">
                                <?php foreach ($anneesDispo as $an): ?>
                                    <option value="<?php echo (int) $an; ?>" <?php echo $an === $annee ? "selected" : ""; ?>><?php echo (int) $an; ?><?php echo $an > (int) date("Y") ? " (à venir)" : ""; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cot-filtre-groupe">
                            <label style="color:var(--white); font-weight:700;">📅 Mois :</label>
                            <select name="periode" onchange="this.form.submit()" class="cot-filter-select">
                                <?php foreach ($moisOptions as $mo): ?>
                                    <option value="<?php echo htmlspecialchars($mo); ?>" <?php echo $mo === $periode ? 'selected' : ''; ?>><?php echo htmlspecialchars(cotisation_libelle_mois($mo)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex-basis:100%; color:var(--text-muted); font-size:0.85rem;">Cotisation : <strong style="color:var(--accent);"><?php echo number_format($montant, 2, ',', ' '); ?> €</strong></div>
                    </form>

                    <details id="cot-details-indicateurs-mois" class="glass-card cot-bilan-annee" style="margin-bottom:1.4rem;" open>
                        <summary style="color:var(--white); font-size:1.05rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">📊 Indicateurs — <?php echo htmlspecialchars(cotisation_libelle_mois($periode)); ?> <span class="pl-chevron" style="color:var(--text-muted);">▸</span></summary>
                        <div class="cot-stats" style="margin-top:0.9rem;">
                            <div class="glass-card cot-stat"><div class="v" style="color:#7bd8a6;"><?php echo (int) $bilan['payes']; ?></div><div class="l">✅ Réglées</div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:#ffd873;"><?php echo $attenteCount; ?></div><div class="l">⏳ En attente</div></div>
                            <div class="glass-card cot-stat" style="<?php echo $retardCount > 0 ? 'border-color:rgba(191,33,33,0.5);' : ''; ?>"><div class="v" style="color:#fca5a5;"><?php echo $retardCount; ?></div><div class="l">🔴 En retard</div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:#8ec5ff;"><?php echo (int) $bilan['virement']; ?></div><div class="l">🏦 Virement automatique<?php if (!empty($bilan['virement_attente'])): ?> · <strong style="color:#8ec5ff;"><?php echo (int) $bilan['virement_attente']; ?> à confirmer</strong><?php endif; ?></div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:var(--accent);"><?php echo number_format($bilan['total'], 2, ',', ' '); ?> €</div><div class="l">💰 Encaissé</div></div>
                        </div>
                        <div style="color:var(--text-muted); font-size:0.78rem; margin:0.9rem 0 0.5rem;">💳 Total par mode de paiement (<?php echo htmlspecialchars(cotisation_libelle_mois($periode)); ?>)</div>
                        <div class="cot-stats cot-stats-4">
                            <div class="glass-card cot-stat"><div class="v" style="color:var(--white); font-size:1.2rem;"><?php echo number_format($totauxMethode['especes'], 2, ',', ' '); ?> €</div><div class="l">💵 Espèces</div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:var(--white); font-size:1.2rem;"><?php echo number_format($totauxMethode['virement'], 2, ',', ' '); ?> €</div><div class="l">🏦 Virement</div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:var(--white); font-size:1.2rem;"><?php echo number_format($totauxMethode['cheque'], 2, ',', ' '); ?> €</div><div class="l">📝 Chèque</div></div>
                            <div class="glass-card cot-stat"><div class="v" style="color:var(--white); font-size:1.2rem;"><?php echo number_format($totauxMethode['stripe'], 2, ',', ' '); ?> €</div><div class="l">💳 Stripe</div></div>
                        </div>
                    </details>

                    <!-- Virements automatiques : confirmation de réception -->
                    <?php if (!empty($virements)): ?>
                    <div class="glass-card" style="margin-bottom:1.4rem;<?php echo $virementsAConfirmer > 0 ? ' border-color:rgba(45,140,255,0.5);' : ''; ?>">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.4rem;">
                            <div style="color:var(--white); font-weight:700;">🏦 Virements automatiques — <?php echo htmlspecialchars(cotisation_libelle_mois($periode)); ?></div>
                            <span class="cot-badge <?php echo $virementsAConfirmer > 0 ? 'cot-info' : 'cot-ok'; ?>"><?php echo $virementsAConfirmer > 0 ? $virementsAConfirmer . ' à confirmer' : '✅ Tout est confirmé'; ?></span>
                        </div>
                        <p style="color:var(--text-muted); font-size:0.82rem; margin:0 0 0.8rem;">Début de mois : confirmez la réception du virement de chaque membre. Si un virement n'est pas reçu, le membre est prévenu et l'option « virement automatique » est désactivée.</p>
                        <div style="display:flex; flex-direction:column; gap:0.5rem;">
                        <?php foreach ($virements as $v):
                            $vNom = trim($v['prenom'] . ' ' . $v['nom']);
                            $vDatePrevue = $v['jour'] !== null ? sprintf('%02d/%s', $v['jour'], substr($periode, 5, 2)) : null;
                            $vActionUrl = 'admin_cotisations.php?periode=' . htmlspecialchars($periode);
                        ?>
                            <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap; background:rgba(255,255,255,0.04); border:1px solid var(--glass-border); border-radius:12px; padding:0.55rem 0.8rem;">
                                <div style="flex:1; min-width:150px;">
                                    <div style="color:var(--white); font-weight:600;"><?php echo htmlspecialchars($vNom); ?></div>
                                    <div style="color:var(--text-muted); font-size:0.78rem;"><?php echo $vDatePrevue !== null ? '📅 Prévu le ' . htmlspecialchars($vDatePrevue) : '📅 Date non définie'; ?></div>
                                </div>
                                <form action="<?php echo $vActionUrl; ?>" method="POST" style="margin:0; display:flex; align-items:center; gap:0.3rem;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="set_virement_jour">
                                    <input type="hidden" name="membre_id" value="<?php echo (int) $v['id']; ?>">
                                    <input type="number" name="jour" min="1" max="31" value="<?php echo $v['jour'] !== null ? (int) $v['jour'] : ''; ?>" placeholder="Jour" title="Jour du mois du virement" style="width:62px; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--white); border-radius:8px; padding:0.3rem 0.4rem;" required>
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Enregistrer la date">💾</button>
                                </form>
                                <?php if ($v['recu']): ?>
                                    <span class="cot-badge cot-ok">✅ Reçu</span>
                                <?php else: ?>
                                    <form action="<?php echo $vActionUrl; ?>" method="POST" style="margin:0;" data-confirm="Confirmer que le virement de <?php echo htmlspecialchars($vNom, ENT_QUOTES); ?> pour <?php echo htmlspecialchars(cotisation_libelle_mois($periode), ENT_QUOTES); ?> a bien été reçu ?">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="virement_recu">
                                        <input type="hidden" name="membre_id" value="<?php echo (int) $v['id']; ?>">
                                        <input type="hidden" name="periode" value="<?php echo htmlspecialchars($periode); ?>">
                                        <button type="submit" class="btn btn-sm" style="background:#1f9d55; border-color:#1f9d55; color:#fff;">✅ Bien reçu</button>
                                    </form>
                                    <form action="<?php echo $vActionUrl; ?>" method="POST" style="margin:0;" data-confirm="Virement de <?php echo htmlspecialchars($vNom, ENT_QUOTES); ?> non reçu ? Le membre sera prévenu et le virement automatique sera désactivé." data-confirm-danger="1">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="virement_non_recu">
                                        <input type="hidden" name="membre_id" value="<?php echo (int) $v['id']; ?>">
                                        <input type="hidden" name="periode" value="<?php echo htmlspecialchars($periode); ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;">❌ Non reçu</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Membres : mode + pointage -->
                    <div class="glass-card" style="overflow-x:auto;">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.7rem;">
                            <div style="color:var(--white); font-weight:700;">👥 Membres — <?php echo htmlspecialchars(cotisation_libelle_mois($periode)); ?></div>
                            <span id="cot-count" style="color:var(--text-muted); font-size:0.82rem;"></span>
                        </div>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.8rem;">
                            <input type="text" id="cot-search" placeholder="🔍 Rechercher un membre…" style="flex:1 1 240px; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--white); border-radius:50px; padding:0.5rem 1rem; font-size:0.9rem;">
                            <select id="cot-filtre" style="flex:0 1 220px; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--white); border-radius:10px; padding:0.5rem 0.8rem; font-size:0.9rem; color-scheme:dark;">
                                <option value="all">Tous les statuts</option>
                                <option value="paye">✅ Réglées</option>
                                <option value="attente">⏳ En attente</option>
                                <option value="retard">🔴 En retard</option>
                                <option value="virement">🏦 Virement à confirmer</option>
                                <option value="exempt">🎗️ Exempt</option>
                            </select>
                        </div>
                        <details id="cot-membres-details" class="cot-membres-details">
                            <summary style="color:var(--white); font-weight:600; font-size:0.9rem; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem; padding:0.3rem 0;">👁️ Afficher la liste des membres <span class="pl-chevron" style="color:var(--text-muted);">▸</span></summary>
                        <div class="cot-list" style="margin-top:0.7rem;">
                            <?php foreach ($membres as $m):
                                $mid = (int) $m['id'];
                                $mmode = $modes[$mid] ?? 'mensuel'; // mode réellement configuré (affiché dans le sélecteur)
                                // Mode effectif pour CE mois : un virement dont la référence n'est pas encore
                                // atteinte se comporte comme un mois mensuel normal (à régulariser).
                                $mmodeEff = ($mmode === 'virement' && !cotisation_virement_couvre($pdo, $mid, $periode)) ? 'mensuel' : $mmode;
                                $row = $payes[$mid] ?? null;
                                $paye = $row && ($row['statut'] ?? '') === 'paye';
                                // Non réglée sur un mois écoulé => « retard » ; sur le mois courant => « attente »
                                $enRetard = !$paye && $mmodeEff === 'mensuel' && $periodePassee;
                                $cat = $mmodeEff === 'exempt' ? 'exempt' : ($paye ? 'paye' : ($mmodeEff === 'virement' ? 'virement' : ($enRetard ? 'retard' : 'attente')));
                                $nomRow = mb_strtolower(trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '')));
                            ?>
                            <div class="cot-card cot-row<?php echo $enRetard ? ' cot-row-retard' : ''; ?>" data-cat="<?php echo $cat; ?>" data-search="<?php echo htmlspecialchars($nomRow, ENT_QUOTES); ?>">
                                <?php
                                    $ph = trim((string) ($m['photo_path'] ?? ''));
                                    $ini = mb_strtoupper(mb_substr((string) ($m['prenom'] ?? ''), 0, 1) . mb_substr((string) ($m['nom'] ?? ''), 0, 1));
                                ?>
                                <div class="cot-name">
                                    <?php if ($ph !== ''): ?><img class="cot-av" src="uploads/<?php echo htmlspecialchars($ph); ?>" alt=""><?php else: ?><span class="cot-av cot-av-ph"><?php echo htmlspecialchars($ini); ?></span><?php endif; ?>
                                    <span><?php echo htmlspecialchars(trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''))); ?></span>
                                </div>
                                <div class="cot-cell">
                                    <form action="admin_cotisations.php?periode=<?php echo htmlspecialchars($periode); ?>" method="POST" style="margin:0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="set_mode">
                                        <input type="hidden" name="membre_id" value="<?php echo $mid; ?>">
                                        <select name="mode" class="cot-mode-select" data-prev="<?php echo htmlspecialchars($mmode, ENT_QUOTES); ?>" data-nom="<?php echo htmlspecialchars(trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '')), ENT_QUOTES); ?>" title="Mode de cotisation">
                                            <option value="mensuel" <?php echo $mmode === 'mensuel' ? 'selected' : ''; ?>>Mensuel</option>
                                            <option value="virement" <?php echo $mmode === 'virement' ? 'selected' : ''; ?>>Virement automatique</option>
                                            <option value="exempt" <?php echo $mmode === 'exempt' ? 'selected' : ''; ?>>Exempt</option>
                                        </select>
                                    </form>
                                </div>
                                <div class="cot-cell">
                                    <?php if ($mmodeEff === 'exempt'): ?>
                                        <span class="cot-badge cot-info">🎗️ Dispensé</span>
                                    <?php elseif ($mmodeEff === 'virement'): ?>
                                        <?php if ($paye): ?>
                                            <span class="cot-badge cot-ok">✅ Réglée (virement reçu)</span>
                                        <?php else: ?>
                                            <span class="cot-badge cot-info">🏦 Virement à confirmer</span>
                                        <?php endif; ?>
                                    <?php elseif ($paye): ?>
                                        <span class="cot-badge cot-ok">✅ Réglée<?php echo !empty($row['methode']) ? ' (' . htmlspecialchars((string) $row['methode']) . ')' : ''; ?></span>
                                    <?php elseif ($enRetard): ?>
                                        <span class="cot-badge cot-retard">🔴 En retard</span>
                                    <?php else: ?>
                                        <span class="cot-badge cot-att">⏳ En attente<?php echo $mmode === 'virement' ? ' (avant virement)' : ''; ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="cot-cell cot-act">
                                    <?php if ($mmodeEff === 'virement'): ?>
                                        <span style="color:#7bd8a6; font-size:0.78rem;">🏦 Automatique</span>
                                    <?php elseif ($mmodeEff !== 'exempt'): ?>
                                        <?php if ($paye): ?>
                                        <form action="admin_cotisations.php?periode=<?php echo htmlspecialchars($periode); ?>" method="POST" style="margin:0;" data-confirm="Annuler ce pointage et le retirer de l'historique ?" data-confirm-danger="1">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="unmark_paid">
                                            <input type="hidden" name="membre_id" value="<?php echo $mid; ?>">
                                            <input type="hidden" name="periode" value="<?php echo htmlspecialchars($periode); ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(191,33,33,0.5); color:#fca5a5;">↩️ Annuler</button>
                                        </form>
                                        <?php else: ?>
                                        <form action="admin_cotisations.php?periode=<?php echo htmlspecialchars($periode); ?>" method="POST" style="margin:0; display:flex; gap:0.35rem; align-items:center; flex-wrap:wrap;" data-confirm="Valider le paiement de la cotisation de <?php echo htmlspecialchars(trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '')), ENT_QUOTES); ?> pour <?php echo htmlspecialchars(cotisation_libelle_mois($periode), ENT_QUOTES); ?> ?">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="mark_paid">
                                            <input type="hidden" name="membre_id" value="<?php echo $mid; ?>">
                                            <input type="hidden" name="periode" value="<?php echo htmlspecialchars($periode); ?>">
                                            <select name="methode" title="Méthode">
                                                <option value="especes">Espèces</option>
                                                <option value="virement">Virement</option>
                                                <option value="cheque">Chèque</option>
                                            </select>
                                            <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(37,211,102,0.5); color:#7bd8a6;">✅ Valider paiement</button>
                                        </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted); font-size:0.8rem;">—</span>
                                    <?php endif; ?>
                                </div>
                                <?php
                                    // Contact : appeler, rappel WhatsApp, WhatsApp récap, e-mail récap (popup d'écriture)
                                    $telBrut = trim((string) ($m['telephone'] ?? ''));
                                    $emailM = trim((string) ($m['email'] ?? ''));
                                    $telE164 = $telBrut !== '' ? dahira_phone_to_e164($telBrut) : null;
                                    $prenomM = (string) ($m['prenom'] ?? '');
                                    $nomComplet = trim($prenomM . ' ' . ($m['nom'] ?? ''));
                                ?>
                                <div class="cot-cell cot-contact">
                                    <?php if ($telE164 !== null): ?>
                                        <a href="tel:+<?php echo htmlspecialchars($telE164); ?>" class="cot-contact-btn" title="Appeler <?php echo htmlspecialchars($nomComplet, ENT_QUOTES); ?>">📞</a>
                                        <a href="<?php echo htmlspecialchars(dahira_wa_link($telBrut, cotisation_wa_rappel($prenomM))); ?>" target="_blank" rel="noopener" class="cot-contact-btn" title="Rappel WhatsApp">🟢</a>
                                        <a href="<?php echo htmlspecialchars(dahira_wa_link($telBrut, cotisation_recap_texte($pdo, $mid, $prenomM))); ?>" target="_blank" rel="noopener" class="cot-contact-btn" title="Récap WhatsApp (mois en retard, total à régulariser)">📊</a>
                                    <?php endif; ?>
                                    <?php if ($emailM !== ''): ?>
                                        <button type="button" class="cot-contact-btn cot-recap-mail-btn" title="Récap e-mail (popup d'écriture)"
                                            data-mid="<?php echo $mid; ?>" data-nom="<?php echo htmlspecialchars($nomComplet, ENT_QUOTES); ?>"
                                            data-sujet="<?php echo htmlspecialchars('💶 Récapitulatif de votre cotisation — Touba Lyon', ENT_QUOTES); ?>"
                                            data-corps="<?php echo htmlspecialchars(cotisation_recap_texte($pdo, $mid, $prenomM), ENT_QUOTES); ?>">📧</button>
                                    <?php endif; ?>
                                    <?php if ($telE164 === null && $emailM === ''): ?>
                                        <span style="color:var(--text-muted); font-size:0.78rem;">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        </details>
                    </div>

                    <!-- Réglages (en bas) -->
                    <?php $paramsOuvert = (($_GET['panel'] ?? '') === 'params'); ?>
                    <details id="cot-details-parametres" class="glass-card" style="margin-top:1.5rem;" <?php echo $paramsOuvert ? 'open' : ''; ?>>
                        <summary style="color:var(--white); font-size:1.1rem; font-weight:700; cursor:pointer; list-style:none; display:flex; align-items:center; gap:0.5rem;">⚙️ Paramètres <span class="pl-chevron" style="color:var(--text-muted);">▸</span></summary>
                        <form method="GET" action="admin_cotisations.php" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-top:1rem;">
                            <input type="hidden" name="periode" value="<?php echo htmlspecialchars($periode); ?>">
                            <input type="hidden" name="panel" value="params">
                            <label style="color:var(--text-muted); font-size:0.85rem;">Année à paramétrer :</label>
                            <select name="annee" onchange="this.form.submit()" class="cot-filter-select">
                                <?php foreach ($anneesDispo as $an): ?>
                                    <option value="<?php echo (int) $an; ?>" <?php echo $an === $annee ? "selected" : ""; ?>><?php echo (int) $an; ?><?php echo $an > (int) date("Y") ? " (à venir)" : ""; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <form action="admin_cotisations.php?annee=<?php echo (int) $annee; ?>&periode=<?php echo htmlspecialchars($periode); ?>&panel=params" method="POST" style="margin-top:1rem; display:flex; flex-direction:column; gap:0.8rem; max-width:520px;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="save_params">
                            <input type="hidden" name="annee" value="<?php echo (int) $annee; ?>">
                            <div class="form-group"><label class="form-label">💶 Montant de la cotisation mensuelle pour <?php echo (int) $annee; ?> (€)</label><input type="text" name="montant" class="form-input" value="<?php echo htmlspecialchars(number_format($montantAnnee, 2, '.', '')); ?>" style="width:100%;"><p style="color:var(--text-muted); font-size:0.78rem; margin-top:0.3rem;">Propre à l'année <?php echo (int) $annee; ?> — choisissez une autre année ci-dessus pour définir son propre montant.</p></div>
                            <div class="form-group"><label class="form-label">🔑 Clé publique Stripe (pk_...)</label><input type="text" name="stripe_pk" class="form-input" value="<?php echo htmlspecialchars($pkVal); ?>" placeholder="pk_live_… ou pk_test_…" style="width:100%;"></div>
                            <div class="form-group"><label class="form-label">🔒 Clé secrète Stripe (sk_...) <?php echo $skDefini ? '<span style="color:#7bd8a6;">— définie</span>' : '<span style="color:#fca5a5;">— non définie</span>'; ?></label><input type="password" name="stripe_sk" class="form-input" value="" placeholder="<?php echo $skDefini ? '•••••••• (laisser vide pour conserver)' : 'sk_live_… ou sk_test_…'; ?>" autocomplete="new-password" style="width:100%;"><p style="color:var(--text-muted); font-size:0.78rem; margin-top:0.3rem;">La clé secrète reste stockée côté serveur et n'est jamais réaffichée. Laissez vide pour conserver l'actuelle.</p></div>
                            <div><button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer</button></div>
                            <div style="color:<?php echo $stripePret ? '#7bd8a6' : '#ffd873'; ?>; font-size:0.82rem;"><?php echo $stripePret ? '✅ Paiement en ligne configuré.' : '⚠️ Paiement en ligne non configuré (clés Stripe manquantes).'; ?></div>
                        </form>
                    </details>
                </div>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <!-- Modale de composition de l'e-mail de relance (récapitulatif éditable) -->
    <div id="recap-mail-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card" style="max-width:560px; width:calc(100vw - 28px);">
            <div class="modal-header"><h3 id="recap-mail-title" style="color:var(--accent);">📧 E-mail de relance</h3></div>
            <div class="modal-body" style="display:block; text-align:left;">
                <form id="recap-mail-form" action="admin_cotisations.php?periode=<?php echo htmlspecialchars($periode); ?>" method="POST" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="send_recap_mail">
                    <input type="hidden" name="membre_id" id="recap-mail-mid">
                    <div class="form-group" style="margin-bottom:0.7rem;">
                        <label class="form-label">Sujet</label>
                        <input type="text" name="sujet" id="recap-mail-sujet" class="form-input" style="width:100%;">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Message</label>
                        <textarea name="corps" id="recap-mail-corps" class="form-input" rows="12" style="width:100%; font-family:inherit;"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; justify-content:flex-end;">
                <button type="button" id="recap-mail-cancel" class="btn btn-secondary btn-sm">Annuler</button>
                <button type="submit" form="recap-mail-form" id="recap-mail-send" class="btn btn-primary btn-sm">📧 Envoyer</button>
            </div>
        </div>
    </div>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        (function () {
            var search = document.getElementById('cot-search');
            var filtre = document.getElementById('cot-filtre');
            var details = document.getElementById('cot-membres-details');
            var rows = Array.prototype.slice.call(document.querySelectorAll('.cot-row'));
            var countEl = document.getElementById('cot-count');
            if (!rows.length) { return; }
            // Taper une recherche ou choisir un statut réouvre automatiquement la liste
            function reouvrir() { if (details && !details.open) { details.open = true; } }
            function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
            function apply() {
                var term = norm(search ? search.value : '');
                var cat = filtre ? filtre.value : 'all';
                var n = 0;
                rows.forEach(function (r) {
                    var rc = r.getAttribute('data-cat');
                    // Le virement automatique est considéré comme réglé
                    var okCat = (cat === 'all') || (rc === cat);
                    var okTerm = !term || norm(r.getAttribute('data-search')).indexOf(term) !== -1;
                    var show = okCat && okTerm;
                    r.style.display = show ? '' : 'none';
                    if (show) { n++; }
                });
                if (countEl) { countEl.textContent = n + ' membre(s)'; }
            }
            if (search) { search.addEventListener('input', function () { if (search.value.trim() !== '') { reouvrir(); } apply(); }); }
            if (filtre) { filtre.addEventListener('change', function () { if (filtre.value !== 'all') { reouvrir(); } apply(); }); }
            apply();
        })();
    </script>
    <script>
        // Mémorise l'état ouvert/fermé de chaque groupe repliable, pour qu'un
        // changement de filtre (qui recharge la page) ne les referme pas.
        (function () {
            try {
                document.querySelectorAll('details[id]').forEach(function (d) {
                    var key = 'cotAdminOpen_' + d.id;
                    var stored = sessionStorage.getItem(key);
                    // Un choix déjà mémorisé (ouvert ou fermé) prévaut sur l'état par défaut du HTML ;
                    // sans historique, l'état tel qu'écrit dans la page est conservé.
                    if (stored === '1') { d.open = true; }
                    else if (stored === '0') { d.open = false; }
                    d.addEventListener('toggle', function () {
                        try { sessionStorage.setItem(key, d.open ? '1' : '0'); } catch (e) {}
                    });
                });
            } catch (e) { /* stockage indisponible : comportement par défaut */ }
        })();
        // Confirmation avant changement de mode de cotisation (mensuel / virement / exempt)
        (function () {
            var labels = { mensuel: 'Mensuel', virement: 'Virement automatique', exempt: 'Exempt' };
            document.querySelectorAll('.cot-mode-select').forEach(function (sel) {
                sel.addEventListener('change', function () {
                    var nouveau = sel.value;
                    var ancien = sel.getAttribute('data-prev') || 'mensuel';
                    var nom = sel.getAttribute('data-nom') || 'ce membre';
                    if (nouveau === ancien) { return; }
                    // On revient visuellement à l'ancienne valeur tant que ce n'est pas confirmé
                    sel.value = ancien;
                    var msg = 'Changer le mode de cotisation de <strong>' + nom + '</strong> de <strong>' + (labels[ancien] || ancien) + '</strong> à <strong>' + (labels[nouveau] || nouveau) + '</strong> ?';
                    if (nouveau === 'virement') {
                        msg += '<br><br><label style="display:block; font-size:0.9rem;">📅 Jour du mois du virement automatique :<br><input type="number" id="cot-vj-input" min="1" max="31" value="5" style="margin-top:0.35rem; width:90px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.25); color:inherit; border-radius:8px; padding:0.35rem 0.5rem;"></label>';
                        msg += '<br><span style="color:var(--text-muted); font-size:0.85rem;">Le virement automatique ne sera pris en compte qu\'à partir des mois à venir : les mois déjà écoulés resteront à régulariser normalement.</span>';
                    }
                    var go = function () {
                        if (nouveau === 'virement') {
                            var vj = document.getElementById('cot-vj-input');
                            var jourVal = vj ? parseInt(vj.value, 10) : 0;
                            if (!(jourVal >= 1 && jourVal <= 31)) {
                                if (window.modernAlert) { window.modernAlert('Indiquez un jour du mois entre 1 et 31.'); }
                                return;
                            }
                            var hid = sel.form.querySelector('input[name="jour"]');
                            if (!hid) { hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'jour'; sel.form.appendChild(hid); }
                            hid.value = String(jourVal);
                        }
                        sel.value = nouveau;
                        sel.setAttribute('data-prev', nouveau);
                        sel.form.submit();
                    };
                    if (window.modernConfirm) {
                        window.modernConfirm(msg, go, { titre: '💶 Changer le mode', bouton: 'Confirmer' });
                    } else {
                        go();
                    }
                });
            });
        })();
    </script>
    <script>
        // Popup de composition de l'e-mail de relance (récapitulatif éditable)
        (function () {
            var modal = document.getElementById('recap-mail-modal');
            var midInput = document.getElementById('recap-mail-mid');
            var sujetInput = document.getElementById('recap-mail-sujet');
            var corpsInput = document.getElementById('recap-mail-corps');
            var title = document.getElementById('recap-mail-title');
            var cancelBtn = document.getElementById('recap-mail-cancel');
            var sendBtn = document.getElementById('recap-mail-send');
            if (!modal) { return; }
            function openModal(m) { m.style.display = 'flex'; setTimeout(function () { m.classList.add('active'); }, 10); }
            function closeModal(m) { m.classList.remove('active'); setTimeout(function () { m.style.display = 'none'; }, 280); }
            document.querySelectorAll('.cot-recap-mail-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    midInput.value = btn.getAttribute('data-mid') || '';
                    sujetInput.value = btn.getAttribute('data-sujet') || '';
                    corpsInput.value = btn.getAttribute('data-corps') || '';
                    if (title) { title.textContent = '📧 E-mail à ' + (btn.getAttribute('data-nom') || 'ce membre'); }
                    if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = '📧 Envoyer'; }
                    openModal(modal);
                });
            });
            document.getElementById('recap-mail-form').addEventListener('submit', function () {
                if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = '⏳ Envoi…'; }
            });
            if (cancelBtn) { cancelBtn.addEventListener('click', function () { closeModal(modal); }); }
            modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(modal); } });
        })();
    </script>
</body>
</html>
