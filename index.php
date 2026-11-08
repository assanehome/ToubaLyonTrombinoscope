<?php
/**
 * Touba Lyon 2026 - Dahira - Mubawwa-A-Sidqin Homepage
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';
require_once __DIR__ . '/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

// Redirect to login if not authenticated as a member (player)
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/player_approved_guard.php';

if (isset($_SESSION['player_id'])) {
    try {
        $stmtScore = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
        $stmtScore->execute([$_SESSION['player_id']]);
        $me = $stmtScore->fetch();
        if ($me) {
            $_SESSION['player_score'] = (int)($me['score'] ?? 0);
            $meCivilite  = (string)($me['civilite'] ?? '');
            $meCommune   = (string)($me['commune'] ?? '');
            $meProf      = (string)($me['profession'] ?? '');
            $meAnnee     = (string)($me['annee_integration'] ?? '');
            $mePhoto     = (string)($me['photo_path'] ?? '');
            $mePrenom    = (string)($me['prenom'] ?? '');
            $meNom       = (string)($me['nom'] ?? '');
        } else {
            $me = null; $meCivilite = ''; $meCommune = ''; $meProf = ''; $meAnnee = ''; $mePhoto = ''; $mePrenom = ''; $meNom = '';
        }
    } catch (Exception $e) {
        $me = null; $meCivilite = ''; $meCommune = ''; $meProf = ''; $meAnnee = ''; $mePhoto = ''; $mePrenom = ''; $meNom = '';
    }
}

// Confirmation, par le demandeur, de l'exécution d'une demande de logistique : elle disparaît ensuite de son accueil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logi_confirmer') {
    if (csrf_validate() && isset($_SESSION['player_id'])) {
        try {
            $lid = (int) ($_POST['demande_id'] ?? 0);
            $upL = $pdo->prepare("UPDATE logistique_demandes SET confirme_le = NOW() WHERE id = ? AND demandeur_id = ? AND statut IN ('executee','terminee') AND confirme_le IS NULL");
            $upL->execute([$lid, (int) $_SESSION['player_id']]);
            if ($upL->rowCount() > 0) {
                $qL = $pdo->prepare("SELECT traite_par, description FROM logistique_demandes WHERE id = ?");
                $qL->execute([$lid]);
                $rL = $qL->fetch();
                if ($rL && (int) ($rL['traite_par'] ?? 0) > 0 && (int) $rL['traite_par'] !== (int) $_SESSION['player_id']) {
                    require_once __DIR__ . '/notification_helper.php';
                    troba_notify_membre($pdo, (int) $rL['traite_par'], 'commission', '👍 Exécution confirmée', 'Le demandeur a confirmé l\'exécution : ' . mb_substr((string) $rL['description'], 0, 80), 'logistique_demandes.php');
                }
            }
        } catch (Exception $e) { /* best-effort */ }
    }
    header('Location: index.php');
    exit;
}

// Validation rapide de la capacité de cotisation au montant défini par la Finance (alerte de l'accueil)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cap_valider') {
    if (csrf_validate() && isset($_SESSION['player_id'])) {
        try {
            require_once __DIR__ . '/cotisation_helper.php';
            cotisation_set_capacite($pdo, (int) $_SESSION['player_id'], null);
        } catch (Exception $e) { /* best-effort */ }
    }
    header('Location: cotisation.php?cap_ok=1#cot-virement');
    exit;
}

// Changement de statut d'une tâche depuis l'accueil (par l'assigné, indépendamment
// de la publication de la réunion). Journalise un commentaire et notifie le créateur.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'maj_tache_statut') {
    if (csrf_validate()) {
        $tid = (int) ($_POST['tache_id'] ?? 0);
        $ns = $_POST['statut'] ?? '';
        $labelsT = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'fait' => 'Fait'];
        if ($tid > 0 && in_array($ns, ['a_faire', 'en_cours', 'fait'], true)) {
            try {
                $st = $pdo->prepare("SELECT t.titre, t.statut, t.assignee_id, t.created_by, t.reunion_id, r.publie, r.cloture
                                     FROM reunion_taches t JOIN commission_reunions r ON r.id = t.reunion_id
                                     WHERE t.id = ?");
                $st->execute([$tid]);
                $t = $st->fetch();
                $reunionOk = $t && (int) ($t['publie'] ?? 0) === 1 && (int) ($t['cloture'] ?? 0) !== 1;
                if ($t && $reunionOk && (int) ($t['assignee_id'] ?? 0) === (int) $_SESSION['player_id']) {
                    $old = (string) ($t['statut'] ?? 'a_faire');
                    if ($old !== $ns) {
                        $pdo->prepare("UPDATE reunion_taches SET statut = ?, updated_at = NOW() WHERE id = ?")->execute([$ns, $tid]);
                        require_once __DIR__ . '/notification_helper.php';
                        $auteurT = trim(($me['prenom'] ?? '') . ' ' . ($me['nom'] ?? ''));
                        $txtT = '🔄 Statut : ' . ($labelsT[$old] ?? $old) . ' → ' . ($labelsT[$ns] ?? $ns) . ($auteurT !== '' ? ' (par ' . $auteurT . ')' : '');
                        $pdo->prepare("INSERT INTO reunion_tache_commentaires (tache_id, membre_id, texte, systeme) VALUES (?,?,?,1)")->execute([$tid, (int) $_SESSION['player_id'], $txtT]);
                        $cby = (int) ($t['created_by'] ?? 0);
                        if ($cby > 0 && $cby !== (int) $_SESSION['player_id']) {
                            troba_notify_membre($pdo, $cby, 'reunion', '🔄 Tâche mise à jour', 'La tâche « ' . (string) $t['titre'] . ' » est passée à « ' . ($labelsT[$ns] ?? $ns) . ' ».', 'reunion_detail.php?id=' . (int) ($t['reunion_id'] ?? 0));
                        }
                    }
                }
            } catch (Exception $e) { /* best-effort */ }
        }
    }
    header('Location: index.php');
    exit;
}

// Ajout d'un commentaire sur une tâche depuis l'accueil (par l'assigné, réunion publiée ou clôturée)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_ma_tache_commentaire') {
    if (csrf_validate()) {
        $tid = (int) ($_POST['tache_id'] ?? 0);
        $txt = trim($_POST['commentaire'] ?? '');
        if ($tid > 0 && $txt !== '') {
            try {
                $st = $pdo->prepare("SELECT t.titre, t.assignee_id, t.created_by, t.reunion_id, r.publie, r.cloture
                                     FROM reunion_taches t JOIN commission_reunions r ON r.id = t.reunion_id
                                     WHERE t.id = ?");
                $st->execute([$tid]);
                $t = $st->fetch();
                $reunionOk = $t && (int) ($t['publie'] ?? 0) === 1 && (int) ($t['cloture'] ?? 0) !== 1;
                if ($t && $reunionOk && (int) ($t['assignee_id'] ?? 0) === (int) $_SESSION['player_id']) {
                    $pdo->prepare("INSERT INTO reunion_tache_commentaires (tache_id, membre_id, texte, systeme) VALUES (?,?,?,0)")->execute([$tid, (int) $_SESSION['player_id'], mb_substr($txt, 0, 1000)]);
                    require_once __DIR__ . '/notification_helper.php';
                    $cby = (int) ($t['created_by'] ?? 0);
                    if ($cby > 0 && $cby !== (int) $_SESSION['player_id']) {
                        troba_notify_membre($pdo, $cby, 'reunion', '💬 Commentaire tâche', 'Nouveau commentaire sur la tâche « ' . (string) $t['titre'] . ' ».', 'reunion_detail.php?id=' . (int) ($t['reunion_id'] ?? 0));
                    }
                }
            } catch (Exception $e) { /* best-effort */ }
        }
    }
    header('Location: index.php');
    exit;
}

// Lecture du Coran : Juz réservés (non validés) dans une session en cours
$myWirdParts = [];
$myWirdSession = null;
$myWirdGroups = [];
if (isset($_SESSION['player_id'])) {
    try {
        $qw = $pdo->prepare("SELECT p.id, p.numero, p.session_id, s.id AS sid, s.titre, s.token, s.serie_id, s.periodicite, s.date_debut, s.date_fin FROM quran_parts p JOIN quran_sessions s ON s.id = p.session_id WHERE p.membre_id = ? AND p.statut = 'reservee' AND s.statut = 'en_cours' AND s.lance = 1 ORDER BY p.numero ASC");
        $qw->execute([(int) $_SESSION['player_id']]);
        $myWirdParts = $qw->fetchAll();
        if (!empty($myWirdParts)) {
            require_once __DIR__ . '/wird_period_helper.php';
            // Regrouper les Juz à valider par Kamil (session)
            foreach ($myWirdParts as $mp) {
                $k = (int)$mp['sid'];
                if (!isset($myWirdGroups[$k])) {
                    $myWirdGroups[$k] = [
                        'sid' => $mp['sid'], 'titre' => $mp['titre'], 'token' => $mp['token'],
                        'periode' => wird_period_label($pdo, ['id' => $mp['sid'], 'serie_id' => $mp['serie_id'], 'periodicite' => $mp['periodicite'], 'date_debut' => $mp['date_debut'], 'date_fin' => $mp['date_fin']]),
                        'parts' => [],
                    ];
                }
                $myWirdGroups[$k]['parts'][] = $mp;
            }
            $r0 = $myWirdParts[0];
            $myWirdSession = [
                'sid' => $r0['sid'], 'titre' => $r0['titre'], 'token' => $r0['token'],
                'periode' => wird_period_label($pdo, ['id' => $r0['sid'], 'serie_id' => $r0['serie_id'], 'periodicite' => $r0['periodicite'], 'date_debut' => $r0['date_debut'], 'date_fin' => $r0['date_fin']]),
            ];
        }
    } catch (Exception $e) {
        $myWirdParts = [];
    }
}
// Sessions de lecture en cours (avec des Juz encore libres)
$wirdSessions = [];
if (isset($_SESSION['player_id'])) {
    try {
        $wirdSessions = $pdo->query(
            "SELECT s.id, s.titre, s.token, s.serie_id, s.periodicite, s.date_debut, s.date_fin, SUM(p.statut='lue') AS lues, SUM(p.statut='libre') AS libres
             FROM quran_sessions s JOIN quran_parts p ON p.session_id = s.id
             WHERE s.statut = 'en_cours' AND s.lance = 1 GROUP BY s.id, s.titre, s.token, s.serie_id, s.periodicite, s.date_debut, s.date_fin HAVING libres > 0 ORDER BY s.created_at DESC"
        )->fetchAll();
        if (!empty($wirdSessions)) { require_once __DIR__ . '/wird_period_helper.php'; }
    } catch (Exception $e) {
        $wirdSessions = [];
    }
}

// Prochain Guddi Àjjuma (séance publiée, à venir ou du jour) — affiché sur l'accueil membre
require_once __DIR__ . '/planning_guddi_helper.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // pour dahira_param()
$prochainGuddi = null;
$guddiHeure = '20h00';
$guddiPresenceFaite = false;
try {
    // On inclut les séances annulées (actif = 0) pour pouvoir afficher l'annulation aux membres.
    $stG = $pdo->query("SELECT * FROM guddi_plannings WHERE publie = 1 AND COALESCE(cloture,0) = 0 ORDER BY (date_guddi >= CURDATE()) DESC, ABS(DATEDIFF(date_guddi, CURDATE())) ASC LIMIT 1");
    $prochainGuddi = $stG->fetch();
    $guddiHeure = dahira_param($pdo, 'guddi_heure', '20h00');
    if ($prochainGuddi && isset($_SESSION['player_id'])) {
        $stP = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'guddi' AND planning_id = ? AND membre_id = ?");
        $stP->execute([(int)$prochainGuddi['id'], (int)$_SESSION['player_id']]);
        $guddiPresenceFaite = ((int)$stP->fetchColumn()) > 0;
    }
} catch (Exception $e) {
    $prochainGuddi = null;
}

// Demandes de logistique : à traiter (responsables de la commission Logistique) et suivi (demandeur)
$logiATraiter = []; $logiNbATraiter = 0; $logiMesDemandes = [];
if (isset($_SESSION['player_id'])) {
    try {
        require_once __DIR__ . '/stock_logistique_helper.php';
        $logiPid = (int) $_SESSION['player_id'];
        if (logistique_est_responsable($pdo, $logiPid)) {
            $logiNbATraiter = (int) $pdo->query("SELECT COUNT(*) FROM logistique_demandes WHERE statut IN ('nouvelle','prise_en_compte')")->fetchColumn();
            $logiATraiter = $pdo->query("SELECT d.*, c.nom AS commission_nom, TRIM(CONCAT(m.prenom, ' ', m.nom)) AS dem_nom
                                         FROM logistique_demandes d JOIN commissions c ON c.id = d.commission_id LEFT JOIN membres m ON m.id = d.demandeur_id
                                         WHERE d.statut IN ('nouvelle','prise_en_compte')
                                         ORDER BY FIELD(d.statut,'nouvelle','prise_en_compte'), d.id DESC LIMIT 6")->fetchAll();
        }
        // Mes demandes : en cours, exécutées à confirmer (elles disparaissent après confirmation) et refusées depuis moins de 7 jours
        $stL = $pdo->prepare("SELECT d.*, c.nom AS commission_nom FROM logistique_demandes d JOIN commissions c ON c.id = d.commission_id
                              WHERE d.demandeur_id = ? AND (d.statut IN ('nouvelle','prise_en_compte') OR (d.statut IN ('executee','terminee') AND d.confirme_le IS NULL) OR (d.statut = 'refusee' AND d.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)))
                              ORDER BY FIELD(d.statut,'nouvelle','prise_en_compte'), d.id DESC LIMIT 6");
        $stL->execute([$logiPid]);
        $logiMesDemandes = $stL->fetchAll();
    } catch (Exception $e) {
        $logiATraiter = []; $logiMesDemandes = []; $logiNbATraiter = 0;
    }
}

// Sondages en cours (publiés, non clôturés) — affichés sur l'accueil membre
$sondagesEnCours = [];
if (isset($_SESSION['player_id'])) {
    try {
        require_once __DIR__ . '/sondage_helper.php';
        $stS = $pdo->query("SELECT s.*, c.nom AS commission_nom FROM sondages s LEFT JOIN commissions c ON c.id = s.commission_id WHERE s.statut = 'publie' AND (s.date_fin IS NULL OR s.date_fin >= CURDATE()) ORDER BY s.published_at DESC, s.id DESC LIMIT 6");
        foreach ($stS->fetchAll() as $sRow) {
            $sRow['_repondu'] = !empty(sondage_mes_choix($pdo, (int) $sRow['id'], (int) $_SESSION['player_id']));
            $sondagesEnCours[] = $sRow;
        }
        // Les sondages auxquels le membre n'a pas encore répondu d'abord
        usort($sondagesEnCours, static function ($x, $y) { return (int) $x['_repondu'] <=> (int) $y['_repondu']; });
    } catch (Exception $e) {
        $sondagesEnCours = [];
    }
}

// Prochain Dahira publié (validation de présence)
$prochainDahira = null;
$dahiraHeure = '';
$dahiraPresenceFaite = false;
try {
    $stD = $pdo->query("SELECT * FROM dahira_plannings WHERE publie = 1 AND a_dahira = 1 AND COALESCE(cloture,0) = 0 ORDER BY (date_dahira >= CURDATE()) DESC, ABS(DATEDIFF(date_dahira, CURDATE())) ASC LIMIT 1");
    $prochainDahira = $stD->fetch();
    $dahiraHeure = dahira_param($pdo, 'dahira_debut', '17h00');
    $dahiraFin = dahira_param($pdo, 'dahira_fin', '20h30');
    // Horaires spécifiques au prochain Dahira s'ils sont définis
    if ($prochainDahira) {
        if (trim((string)($prochainDahira['heure_debut'] ?? '')) !== '') { $dahiraHeure = trim((string)$prochainDahira['heure_debut']); }
        if (trim((string)($prochainDahira['heure_fin'] ?? '')) !== '') { $dahiraFin = trim((string)$prochainDahira['heure_fin']); }
    }
    $dahiraLieu = dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
    if ($prochainDahira && isset($_SESSION['player_id'])) {
        $stP = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'dahira' AND planning_id = ? AND membre_id = ?");
        $stP->execute([(int)$prochainDahira['id'], (int)$_SESSION['player_id']]);
        $dahiraPresenceFaite = ((int)$stP->fetchColumn()) > 0;
    }
} catch (Exception $e) {
    $prochainDahira = null;
}

// Réunions de commission publiées (à venir) des commissions du membre
$mesReunions = [];
if (isset($_SESSION['player_id'])) {
    try {
        $rqR = $pdo->prepare(
            "SELECT r.*, c.nom AS commission_nom
             FROM commission_reunions r
             LEFT JOIN commissions c ON c.id = r.commission_id
             WHERE r.commission_id IN (SELECT commission_id FROM commission_membres WHERE membre_id = ?
                                       UNION SELECT commission_id FROM commission_gestionnaires WHERE membre_id = ?)
               AND r.publie = 1 AND r.cloture = 0 AND COALESCE(r.annule,0) = 0
             ORDER BY (r.date_reunion >= CURDATE()) DESC, r.date_reunion ASC, r.id ASC"
        );
        $rqR->execute([(int) $_SESSION['player_id'], (int) $_SESSION['player_id']]);
        $mesReunions = $rqR->fetchAll();
    } catch (Exception $e) {
        $mesReunions = [];
    }
}

// Répétitions de Kurel publiées (à venir) des Kurels du membre
$mesRepetitions = [];
if (isset($_SESSION['player_id'])) {
    try {
        $rqP = $pdo->prepare(
            "SELECT r.*, k.nom AS kourel_nom
             FROM kourel_repetitions r
             JOIN kourel_membres km ON km.kourel_id = r.kourel_id
             LEFT JOIN kourels k ON k.id = r.kourel_id
             WHERE km.membre_id = ? AND r.publie = 1 AND r.cloture = 0 AND COALESCE(r.annule,0) = 0
             ORDER BY (r.date_reunion >= CURDATE()) DESC, r.date_reunion ASC, r.id ASC"
        );
        $rqP->execute([(int) $_SESSION['player_id']]);
        $mesRepetitions = $rqP->fetchAll();
    } catch (Exception $e) {
        $mesRepetitions = [];
    }
}

// Tâches attribuées au membre (suite aux réunions), non terminées
$mesTaches = [];
if (isset($_SESSION['player_id'])) {
    try {
        $rqT = $pdo->prepare(
            "SELECT t.id, t.titre, t.statut, t.echeance, t.reunion_id, r.titre AS reunion_titre, c.nom AS commission_nom
             FROM reunion_taches t
             JOIN commission_reunions r ON r.id = t.reunion_id
             LEFT JOIN commissions c ON c.id = t.commission_id
             WHERE t.assignee_id = ? AND t.statut <> 'fait' AND r.publie = 1 AND r.cloture = 0
             ORDER BY FIELD(t.statut,'en_cours','a_faire'), (t.echeance IS NULL) ASC, t.echeance ASC, t.created_at ASC"
        );
        $rqT->execute([(int) $_SESSION['player_id']]);
        $mesTaches = $rqT->fetchAll();
    } catch (Exception $e) {
        $mesTaches = [];
    }
}

// Actualités publiées (commission Secrétariat Général) — visibles par tous les membres
$actualitesPubliees = [];
try {
    require_once __DIR__ . '/actualites_helper.php';
    $rqA = $pdo->query("SELECT id, type, titre, contenu, mentions, image_path, pdf_path, published_at, created_at FROM actualites WHERE statut = 'publie' AND COALESCE(cloture,0) = 0 ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 6");
    $actualitesPubliees = $rqA->fetchAll();
} catch (Exception $e) {
    $actualitesPubliees = [];
}

// Complétion du profil membre (bannière « compléter vos informations »)
$profilCompletion = 100;
$profilManque = false;
if (isset($_SESSION['player_id'])) {
    try {
        $pm = $pdo->prepare("SELECT photo_path, telephone, adresse, code_postal, commune, statut, secteur_activite, profession, annee_integration FROM membres WHERE id = ?");
        $pm->execute([(int) $_SESSION['player_id']]);
        $prof = $pm->fetch();
        if ($prof) {
            $champsProfil = ['photo_path', 'telephone', 'code_postal', 'commune', 'statut', 'secteur_activite', 'profession', 'annee_integration'];
            $totalProfil = count($champsProfil);
            $okProfil = 0;
            foreach ($champsProfil as $ch) { if (trim((string) ($prof[$ch] ?? '')) !== '') { $okProfil++; } }
            $profilCompletion = $totalProfil > 0 ? (int) round($okProfil * 100 / $totalProfil) : 100;
            $profilManque = ($okProfil < $totalProfil);
        }
    } catch (Exception $e) {
        $profilManque = false;
    }
}

require_once __DIR__ . '/member_menu_data.php'; // menu membre (points de gestion selon les droits)

// Notifications « début » des réunions / répétitions (cloche + Web Push, sans e-mail)
try {
    require_once __DIR__ . '/reunion_helper.php';
    require_once __DIR__ . '/repetition_helper.php';
    reunion_check_debut($pdo);
    repetition_check_debut($pdo);
} catch (Throwable $e) { /* best-effort */ }

// Cotisation : notification mensuelle (une fois par mois) + alerte pour le membre
$cotisationAlerte = false;
$cotisationMerciVirement = false;
$cotisationMontantAff = 0.0;
$cotisationMoisAff = '';
$cotisationRetard = 0;
$cotisationRetardMois = [];
try {
    require_once __DIR__ . '/cotisation_helper.php';
    cotisation_check_mois($pdo);
    cotisation_check_virements_jour($pdo);
    if (isset($_SESSION['player_id'])) {
        $mid = (int) $_SESSION['player_id'];
        $per = cotisation_periode_courante();
        $modeCot = cotisation_mode($pdo, $mid);
        // Mode effectif pour le mois courant : un virement pas encore entré en
        // vigueur (mois courant <= référence de bascule) se comporte comme mensuel.
        $modeCotEff = ($modeCot === 'virement' && !cotisation_virement_couvre($pdo, $mid, $per)) ? 'mensuel' : $modeCot;
        $cotisationMoisAff = cotisation_libelle_mois($per);
        // Capacité mensuelle non validée : alerte affichée jusqu'à validation (par défaut : montant défini par la Finance)
        $capaciteAValider = ($modeCot !== 'exempt' && !cotisation_capacite_validee($pdo, $mid));
        $capaciteDefaut = cotisation_montant_periode($pdo, $per);
        // Mois écoulés non réglés (retard) + liste des mois concernés
        $cotisationRetardMois = cotisation_mois_retard($pdo, $mid);
        $cotisationRetard = count($cotisationRetardMois);
        if ($modeCotEff === 'mensuel' && !cotisation_est_paye($pdo, $mid, $per)) {
            $cotisationAlerte = true;
            $cotisationMontantAff = cotisation_montant_membre($pdo, $mid, $per);
        } elseif ($modeCotEff === 'virement' && (int) date('j') <= 15) {
            // Remerciement aux membres en virement automatique, jusqu'au 15 du mois
            $cotisationMerciVirement = true;
        }
    }
} catch (Throwable $e) { $cotisationAlerte = false; }

// Liste des notifications du membre (affichée sur le tableau de bord)
$dashNotifs = [];
try {
    require_once __DIR__ . '/notification_helper.php';
    if (isset($pdo) && isset($_SESSION['player_id'])) {
        troba_purge_notifications($pdo);
        $dashNotifs = troba_notifications_recent($pdo, (int)$_SESSION['player_id'], 5);
    }
} catch (Throwable $e) {
    $dashNotifs = [];
}

// Le trombinoscope (grille des membres, recherche, filtres) vit désormais dans trombinoscope.php
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dahira - Mubawwa-A-Sidqin - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── w-tovalidate-idx : carte moderne "Juz à valider" (couleurs du site) ── */
        .w-tovalidate-idx {
            position: relative;
            max-width: 820px;
            margin: 0 auto 1.5rem;
            padding: 1.5rem 1.5rem 1.25rem;
            border-radius: 12px;
            background: linear-gradient(160deg, rgba(255,255,255,0.08), rgba(255,255,255,0.03));
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(255,255,255,0.14);
            box-shadow: 0 12px 30px rgba(0,0,0,0.25);
            overflow: hidden;
            color: #fff;
        }
        @keyframes wtvFlowIdxs { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .wtv-idx-top { display: flex; align-items: center; gap: 0.9rem; flex-wrap: wrap; }
        .wtv-idx-icon {
            width: 46px; height: 46px; border-radius: 10px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 1.4rem;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
        }
        .wtv-idx-titles { display: flex; flex-direction: column; gap: 0.1rem; flex: 1; min-width: 170px; }
        .wtv-idx-title { font-size: 1.05rem; font-weight: 800; color: #fff; letter-spacing: -0.01em; }
        .wtv-idx-session { font-size: 0.82rem; color: rgba(255,255,255,0.7); font-weight: 600; }
        .wtv-idx-cta { margin-left: auto; white-space: nowrap; }
        .wtv-idx-count { font-size: 0.78rem; font-weight: 800; color: #0c241a; background: linear-gradient(135deg, #d4af37, #f1d279); border-radius: 12px; padding: 4px 13px; box-shadow: 0 4px 12px rgba(212,175,55,0.35); margin-left: auto; }
        .wtv-idx-list { display: flex; flex-wrap: wrap; gap: 0.55rem; margin-top: 1.1rem; }
        /* Regroupement des Juz à valider par Kamil (session) */
        .wtv-idx-groups { display: flex; flex-direction: column; gap: 1rem; margin-top: 1.1rem; }
        .wtv-idx-group + .wtv-idx-group { border-top: 1px solid rgba(255,255,255,0.08); padding-top: 0.9rem; }
        .wtv-idx-grouphead { color: #fff; font-size: 0.85rem; font-weight: 700; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
        .wtv-item-idx {
            display: inline-flex; align-items: center; gap: 0.45rem;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            padding: 5px 6px;
            animation: wtvChipIdx 0.45s cubic-bezier(0.22,1,0.36,1) both;
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }
        .wtv-item-idx:hover { transform: translateY(-2px); border-color: rgba(255,255,255,0.28); box-shadow: 0 8px 20px rgba(0,0,0,0.25); }
        @keyframes wtvChipIdx { from { opacity: 0; transform: translateY(10px) scale(0.94); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .wtv-item-idx .wtv-num { font-weight: 800; font-size: 0.88rem; color: #fff; padding: 4px 6px 4px 12px; white-space: nowrap; cursor: pointer; border-radius: 8px; transition: background 0.2s; }
        .wtv-item-idx .wtv-num:hover { background: rgba(255,255,255,0.1); }
        .wtv-item-idx .wtv-btn {
            background: rgba(45,106,79,0.9); color: #fff; border: 0; border-radius: 8px;
            font-weight: 700; font-size: 0.82rem; padding: 7px 13px; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
            transition: transform 0.12s ease, filter 0.2s ease;
        }
        .wtv-item-idx .wtv-btn:active { transform: translateY(1px); }
        .wtv-item-idx .wtv-btn:hover { filter: brightness(1.12); }
        .wtv-item-idx .wtv-btn--pdf { background: rgba(255,255,255,0.08); color: #fff; border: 1px solid rgba(255,255,255,0.16); }
        .wtv-item-idx .wtv-btn--pdf:hover { background: rgba(255,255,255,0.16); color: #fff; }
        .wtv-idx-hint { margin-top: 0.85rem; font-size: 0.78rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.4rem; }
        .wtv-idx-hint::before { content: '💡'; }
        /* ── Prochaines rencontres : animations comme le suivi Juz ── */
        .renc-card {
            position: relative;
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1rem 1.1rem;
            background: rgba(255,255,255,0.03);
            overflow: hidden;
            animation: rencCardIn 0.45s cubic-bezier(0.22,1,0.36,1) both;
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }
        .renc-card:hover { transform: translateY(-3px); border-color: rgba(255,255,255,0.28); box-shadow: 0 10px 24px rgba(0,0,0,0.3); }
        .renc-card:nth-child(1) { animation-delay: 0.08s; }
        .renc-card:nth-child(2) { animation-delay: 0.16s; }
        @keyframes rencCardIn { from { opacity: 0; transform: translateY(18px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .renc-wrap { position: relative; overflow: hidden; }
        @keyframes rencFlow { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        /* Animation d'apparition des sections « Prochaines rencontres » et « Lecture du Coran » */
        @keyframes idxReveal { from { opacity: 0; transform: translateY(22px); } to { opacity: 1; transform: translateY(0); } }
        .idx-reveal { position: relative; overflow: hidden; animation: idxReveal 0.6s cubic-bezier(0.22,1,0.36,1) both; transition: box-shadow 0.25s ease, transform 0.2s ease; }
        .idx-reveal:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(0,0,0,0.3); }
        .idx-reveal.idx-delay { animation-delay: 0.12s; }
        .idx-reveal::after { content: ''; position: absolute; top: 0; left: -20%; right: -20%; height: 3px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.95), rgba(212,175,55,0.95), transparent); background-size: 200% 100%; animation: rencFlow 4.5s linear infinite; pointer-events: none; }
        @media (prefers-reduced-motion: reduce) { .idx-reveal, .idx-reveal::after { animation: none !important; } }
        /* Boutons d'action des cartes : survol animé */
        .renc-card .btn { transition: transform 0.12s ease, box-shadow 0.2s ease, filter 0.2s ease; }
        .renc-card .btn:hover { filter: brightness(1.08); }
        .renc-card .btn-primary:active { transform: translateY(2px); }
        /* Centrage du contenu des cartes de rencontre sur mobile */
        @media (max-width: 480px) {
            .renc-card { text-align: center; }
            .renc-card .btn { width: 100%; }
            .renc-card > div:first-child { justify-content: center; }
        }
        /* ── Badges d'état d'une rencontre (en cours / annulé) ── */
        .renc-badge { font-size: 0.68rem; font-weight: 700; padding: 0.18rem 0.5rem; border-radius: 12px; white-space: nowrap; line-height: 1.2; }
        .renc-badge-live { background: rgba(37,211,102,0.15); color: #7bd8a6; border: 1px solid rgba(37,211,102,0.45); }
        .renc-badge-annule { background: rgba(191,33,33,0.15); color: #fca5a5; border: 1px solid rgba(191,33,33,0.45); }
        /* Carte « en cours » : liseré vert pulsant */
        .renc-card-live { border-color: rgba(37,211,102,0.55); box-shadow: 0 0 0 1px rgba(37,211,102,0.25) inset; }
        .renc-badge-live { animation: rencLivePulse 1.8s ease-in-out infinite; }
        @keyframes rencLivePulse { 0%,100% { opacity: 1; } 50% { opacity: 0.55; } }
        /* Carte annulée : grisée, liseré rouge */
        .renc-card-annule { border-color: rgba(191,33,33,0.5); opacity: 0.9; }
        @media (prefers-reduced-motion: reduce) { .renc-badge-live { animation: none !important; } }
        /* ── Liste des notifications sur le tableau de bord ── */
        .dash-notif-wrap { position: relative; overflow: hidden; margin-bottom: 1.5rem; padding: 1.4rem 1.5rem; }
        .dash-notif-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.9rem; }
        .dash-notif-list { display: flex; flex-direction: column; gap: 0.55rem; }
        .dash-notif-item {
            display: flex; align-items: flex-start; gap: 0.7rem;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            padding: 0.7rem 0.9rem;
            text-decoration: none;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }
        .dash-notif-item:hover { border-color: rgba(212,175,55,0.4); background: rgba(255,255,255,0.05); }
        .dash-notif-item .ni-ic { font-size: 1.05rem; line-height: 1.3; flex-shrink: 0; }
        .dash-notif-item .ni-body { flex: 1; min-width: 0; }
        .dash-notif-item .ni-titre { color: var(--white); font-weight: 700; font-size: 0.88rem; }
        .dash-notif-item .ni-texte { color: var(--text-muted); font-size: 0.82rem; line-height: 1.4; margin-top: 0.15rem; white-space: pre-line; word-break: break-word; }
        .dash-notif-item .ni-date { color: rgba(255,255,255,0.32); font-size: 0.7rem; margin-top: 0.25rem; }
        .dash-notif-item.non-lu { border-left: 3px solid var(--accent); }
        /* ── Groupe dépliable « Mes notifications » ── */
        .dash-notif-toggle {
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            width: 100%;
            background: none; border: 0; padding: 0;
            color: #fff; font-weight: 800; font-size: 1.05rem;
            cursor: pointer;
            text-align: left;
        }
        .dash-notif-toggle .nt-chev { transition: transform 0.3s cubic-bezier(0.22,1,0.36,1); color: var(--accent); font-size: 0.85rem; }
        .dash-notif-toggle.open .nt-chev { transform: rotate(180deg); }
        .dash-notif-body { overflow: hidden; max-height: 0; transition: max-height 0.45s cubic-bezier(0.22,1,0.36,1), opacity 0.35s ease; opacity: 0; }
        .dash-notif-body.open { max-height: 1200px; opacity: 1; }
        .dash-notif-count {
            background: linear-gradient(135deg, #d4af37, #f1d279); color: #0c241a;
            border-radius: 12px; padding: 0.15rem 0.65rem; font-size: 0.75rem; font-weight: 800;
            margin-left: 0.4rem; box-shadow: 0 2px 8px rgba(212,175,55,0.3);
        }
        /* Boutons d'action du banner : pleine largeur + centrés sur mobile */
        .dash-actions { display: flex; align-items: center; justify-content: center; gap: 0.6rem; flex-wrap: wrap; margin-top: 1.1rem; border-top: 1px solid var(--glass-border); padding-top: 1rem; }
        .dash-actions .btn { flex: 1 1 auto; text-align: center; justify-content: center; }
        @media (max-width: 520px) {
            .dash-actions { flex-direction: column; align-items: stretch; }
            .dash-actions .btn { width: 100%; }
        }
        /* Bascule vers le mode administration */
        /* Groupe : CSS unique dans style.css (référence « Mode admin ») ; la carte n'a pas son propre cadre à l'intérieur */
        details.adm-grp .adm-card { margin: 0 !important; border: 0; border-radius: 0; box-shadow: none; background: transparent; padding: 1rem 1.1rem; }
        details.adm-grp .adm-card::before { display: none; }
        .adm-card { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; padding: 1rem 1.2rem; border-radius: 12px; border: 1px solid rgba(212,175,55,0.5);
            background: linear-gradient(135deg, rgba(212,175,55,0.16) 0%, rgba(212,175,55,0.04) 55%, rgba(255,255,255,0.02) 100%); box-shadow: 0 6px 22px rgba(0,0,0,0.22); position: relative; overflow: hidden; }
        .adm-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: linear-gradient(180deg, #f2d675, #b8921f); }
        .adm-card-ic { flex: 0 0 auto; width: 46px; height: 46px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;
            background: linear-gradient(135deg, rgba(242,214,117,0.32), rgba(184,146,31,0.2)); border: 1px solid rgba(212,175,55,0.55); }
        .adm-card-txt { flex: 1 1 220px; min-width: 0; display: flex; flex-direction: column; gap: 0.15rem; }
        .adm-card-txt strong { color: var(--gold); font-size: 1rem; letter-spacing: 0.01em; }
        .adm-card-txt span { color: var(--text-muted); font-size: 0.84rem; line-height: 1.4; }
        .adm-card-form { margin: 0; flex: 0 0 auto; }
        .adm-card-btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; cursor: pointer; border: 0; padding: 0.75rem 1.4rem; font-weight: 800; font-size: 0.9rem; font-family: inherit;
            color: #1b1b1b; background: linear-gradient(135deg, #f2d675, #d4af37 55%, #b8921f); box-shadow: 0 4px 14px rgba(212,175,55,0.35); transition: transform .15s, box-shadow .15s, filter .15s; white-space: nowrap; }
        .adm-card-btn span { transition: transform .15s; }
        .adm-card-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(212,175,55,0.45); filter: brightness(1.05); }
        .adm-card-btn:hover span { transform: translateX(4px); }
        .adm-card-btn:active { transform: translateY(0) scale(0.98); }
        @media (max-width: 600px) { .adm-card { flex-direction: column; align-items: stretch; text-align: center; } .adm-card-ic { align-self: center; } .adm-card-txt { flex: 0 0 auto; } .adm-card-form, .adm-card-btn { width: 100%; } }
        .adm-switch summary::-webkit-details-marker { display: none; }
        .adm-switch[open] .adm-switch-chevron { transform: rotate(90deg); }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="dashboard-main">
        <?php // Rôle d'administrateur accordé à ce membre : il ouvre l'autre espace
              // dans la même session, avec le même compte et le même mot de passe. ?>
        <?php if (!empty($me['is_admin'])): ?>
            <details class="adm-grp" style="margin-top: 2rem;">
                <summary>🛡️ Mode administration</summary>
            <div class="adm-card">
                <div class="adm-card-ic" aria-hidden="true">🛡️</div>
                <div class="adm-card-txt">
                    <strong>Mode administration</strong>
                    <span>Vous êtes aussi administrateur du Dahira. Votre espace membre reste ici.</span>
                </div>
                <form action="basculer_admin.php" method="POST" class="adm-card-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="vers" value="admin">
                    <button type="submit" class="adm-card-btn">Passer en mode admin <span aria-hidden="true">→</span></button>
                </form>
            </div>
            </details>
        <?php endif; ?>


        <!-- User Welcome Banner -->
        <?php if (isset($_SESSION['player_id'])): ?>
            <div class="user-welcome-banner glass-card" style="margin-top: 2rem; margin-bottom: 1rem; padding: 1.4rem 1.6rem; border-radius: 12px; position: relative; overflow: hidden;">
                <div style="display: flex; align-items: center; gap: 1.1rem; flex-wrap: wrap;">
                    <!-- Photo / avatar -->
                    <div style="width: 64px; height: 64px; border-radius: 50%; overflow: hidden; border: 2px solid var(--accent); box-shadow: 0 4px 14px rgba(0,0,0,0.4); flex-shrink: 0; background: var(--primary); display:flex; align-items:center; justify-content:center;">
                        <?php if ($mePhoto !== ''): ?>
                            <img src="uploads/<?php echo htmlspecialchars($mePhoto); ?>" alt="Photo de profil" style="width:100%; height:100%; object-fit:cover;">
                        <?php else: ?>
                            <span style="font-size:1.6rem; font-weight:800; color:var(--gold); text-transform:uppercase;"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim($mePrenom . ' ' . $meNom), 0, 1))); ?></span>
                        <?php endif; ?>
                    </div>
                    <!-- Identité -->
                    <div style="flex: 1; min-width: 200px;">
                        <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                            <span style="font-size: 1.2rem; font-weight: 600;">Bienvenue, <strong class="gold-text"><?php echo htmlspecialchars($_SESSION['player_name']); ?></strong> !</span>
                            <?php if ($meCivilite !== ''): ?>
                                <span class="member-civilite-badge" style="opacity:1; transform:none;"><?php echo htmlspecialchars($meCivilite); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Score + notifications -->
                    <div style="display:flex; flex-direction:column; align-items:flex-end; gap:0.5rem;">
                        <span class="player-score-badge" style="background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: var(--white); font-weight: 800; padding: 0.5rem 1.25rem; border-radius: 12px; font-size: 1rem; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 4px 12px rgba(27,67,50,0.3); white-space: nowrap;">
                            🏆 <?php echo (int)($_SESSION['player_score'] ?? 0); ?> pts
                        </span>
                        <a href="notifications_page.php" style="color: var(--text-muted); text-decoration: none; font-size: 0.82rem; font-weight:600; display:inline-flex; align-items:center; gap:0.35rem;">
                            🔔 Notifications <?php if (isset($notifNonLues) && $notifNonLues > 0): ?><span style="background: var(--accent); color: var(--secondary); border-radius: 12px; padding: 0.05rem 0.5rem; font-size: 0.75rem; font-weight: 800;"><?php echo (int)$notifNonLues > 99 ? '99+' : (int)$notifNonLues; ?></span><?php endif; ?>
                        </a>
                    </div>
                </div>
                <div class="dash-actions">
                    <a href="trombinoscope.php" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">🖼️ Voir le trombinoscope</a>
                    <a href="kikanla.php" class="btn btn-primary btn-sm">🎮 Jouer à Ki Kan La</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Complétion du profil : inciter à compléter les informations manquantes -->
        <?php if (isset($_SESSION['player_id']) && $profilManque): ?>
        <a href="profile.php" class="glass-card" style="display:block; text-decoration:none; margin-bottom:1.25rem; padding:1.1rem 1.35rem; border-radius:12px; border:1px solid rgba(212,175,55,0.4); background:linear-gradient(160deg, rgba(212,175,55,0.12), rgba(255,255,255,0.03));">
            <div style="display:flex; align-items:center; gap:0.9rem; flex-wrap:wrap;">
                <div style="font-size:1.6rem;">📝</div>
                <div style="flex:1; min-width:180px;">
                    <div style="color:#fff; font-weight:800; font-size:1rem;">Complétez vos informations</div>
                    <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.15rem;">Votre profil est rempli à <strong style="color:var(--accent);"><?php echo (int) $profilCompletion; ?>%</strong> — quelques champs manquent encore.</div>
                    <div style="height:8px; border-radius:12px; background:rgba(255,255,255,0.08); margin-top:0.6rem; overflow:hidden;">
                        <div style="height:100%; width:<?php echo (int) $profilCompletion; ?>%; background:linear-gradient(90deg, #d4af37, #f1d279); border-radius:12px;"></div>
                    </div>
                </div>
                <span class="btn btn-primary btn-sm" style="white-space:nowrap;">Compléter →</span>
            </div>
        </a>
        <?php endif; ?>

        <!-- Alerte : capacité de cotisation à valider (affichée jusqu'à validation) -->
        <?php if (!empty($capaciteAValider)): ?>
        <details id="cap-alerte" class="cap-alerte" open>
            <summary>⚠️ Validez votre capacité de cotisation mensuelle</summary>
            <div style="color:var(--text-muted); font-size:0.88rem; line-height:1.55;">Par défaut : <strong style="color:#ffd873;"><?php echo number_format($capaciteDefaut, 2, ',', ' '); ?> €</strong> par mois, le montant défini par la Finance. Gardez-le ou indiquez ce que vous pouvez verser.</div>
            <?php if ($modeCot === 'mensuel'): ?><div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.4rem;">💡 Pensez au virement automatique mensuel, selon votre capacité : plus rien à penser.</div><?php endif; ?>
            <div style="display:flex; flex-wrap:wrap; gap:0.6rem; margin-top:0.85rem;">
                <form method="POST" action="index.php" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cap_valider">
                    <button type="submit" class="btn btn-primary btn-sm">✅ Valider <?php echo number_format($capaciteDefaut, 2, ',', ' '); ?> € par mois</button>
                </form>
                <a href="cotisation.php#cot-capacite" class="btn btn-secondary btn-sm">✏️ Choisir un autre montant</a>
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

        <!-- Informations de paiement (regroupées, repliées par défaut, animées) -->
        <?php
            $paieRetard = !empty($cotisationRetard);
            $paieAlerte = !empty($cotisationAlerte);
            $paieVirement = !empty($cotisationMerciVirement);
            if ($paieRetard || $paieAlerte || $paieVirement):
        ?>
        <style>
            .paie-group > summary { list-style:none; cursor:pointer; display:flex; align-items:center; gap:0.6rem; user-select:none; }
            .paie-group > summary::-webkit-details-marker { display:none; }
            .paie-chip { font-size:0.72rem; font-weight:700; padding:0.2rem 0.6rem; border-radius:12px; white-space:nowrap; }
            .paie-chip-retard { animation:paieChipPulse 1.8s ease-in-out infinite; }
            @keyframes paieChipPulse { 0%,100%{opacity:1;} 50%{opacity:0.55;} }
            .paie-chevron { transition:transform 0.25s ease; display:inline-block; }
            .paie-group[open] > summary .paie-chevron { transform:rotate(90deg); }
            @keyframes paieIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
            .paie-group[open] .paie-inner > * { animation:paieIn 0.42s cubic-bezier(0.22,1,0.36,1) both; }
            .paie-group[open] .paie-inner > *:nth-child(2){ animation-delay:0.07s; }
            .paie-group[open] .paie-inner > *:nth-child(3){ animation-delay:0.14s; }
            @media (prefers-reduced-motion: reduce){ .paie-group[open] .paie-inner > *, .paie-chip-retard { animation:none !important; } }
        </style>
        <details class="glass-card paie-group" style="margin-bottom:1.25rem;">
            <summary>
                <span style="font-size:1.25rem;">💶</span>
                <span style="color:#fff; font-weight:800; font-size:1rem;">Mes cotisations</span>
                <span style="display:flex; align-items:center; gap:0.5rem; margin-left:auto;">
                    <?php if ($paieRetard): ?>
                        <span class="paie-chip paie-chip-retard" style="background:rgba(191,33,33,0.18); color:#fca5a5; border:1px solid rgba(191,33,33,0.5);">🔴 <?php echo (int) $cotisationRetard; ?> mois en retard</span>
                    <?php elseif ($paieAlerte): ?>
                        <span class="paie-chip" style="background:rgba(99,91,255,0.18); color:#c4b5fd; border:1px solid rgba(99,91,255,0.5);">💳 À régler</span>
                    <?php elseif ($paieVirement): ?>
                        <span class="paie-chip" style="background:rgba(37,211,102,0.15); color:#7bd8a6; border:1px solid rgba(37,211,102,0.4);">✅ Virement automatique</span>
                    <?php endif; ?>
                    <span class="paie-chevron" style="color:var(--text-muted);">▸</span>
                </span>
            </summary>
            <div class="paie-inner" style="margin-top:0.9rem; display:flex; flex-direction:column; gap:0.9rem;">
                <?php if ($paieRetard): ?>
                <a href="cotisation.php" class="glass-card" style="display:block; text-decoration:none; padding:1.1rem 1.35rem; border-radius:12px; border:1px solid rgba(191,33,33,0.55); background:linear-gradient(160deg, rgba(191,33,33,0.16), rgba(255,255,255,0.03));">
                    <div style="display:flex; align-items:center; gap:0.9rem; flex-wrap:wrap;">
                        <div style="font-size:1.7rem;">🔴</div>
                        <div style="flex:1; min-width:180px;">
                            <div style="color:#fff; font-weight:800; font-size:1rem;"><?php echo (int) $cotisationRetard; ?> mois de cotisation en retard</div>
                            <?php if (!empty($cotisationRetardMois)): ?>
                            <div style="display:flex; flex-wrap:wrap; gap:0.3rem; margin-top:0.4rem;">
                                <?php foreach ($cotisationRetardMois as $mr): ?>
                                    <span style="font-size:0.72rem; font-weight:700; padding:0.15rem 0.5rem; border-radius:12px; background:rgba(191,33,33,0.18); color:#fca5a5; border:1px solid rgba(191,33,33,0.45);">🔴 <?php echo htmlspecialchars(cotisation_libelle_mois($mr)); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.4rem;">Merci de régulariser votre situation. 🤲</div>
                        </div>
                        <span class="btn btn-sm" style="white-space:nowrap; background:linear-gradient(135deg,#c62828,#e04343); border-color:transparent; color:#fff;">💳 Régulariser →</span>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($paieAlerte): ?>
                <a href="cotisation.php" class="glass-card" style="display:block; text-decoration:none; padding:1.1rem 1.35rem; border-radius:12px; border:1px solid rgba(99,91,255,0.5); background:linear-gradient(160deg, rgba(99,91,255,0.15), rgba(255,255,255,0.03));">
                    <div style="display:flex; align-items:center; gap:0.9rem; flex-wrap:wrap;">
                        <div style="font-size:1.7rem;">💶</div>
                        <div style="flex:1; min-width:180px;">
                            <div style="color:#fff; font-weight:800; font-size:1rem;">Cotisation de <?php echo htmlspecialchars($cotisationMoisAff); ?> à régler</div>
                            <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.15rem;">Votre cotisation du mois (<strong style="color:#c4b5fd;"><?php echo number_format((float) $cotisationMontantAff, 2, ',', ' '); ?> €</strong>) n'est pas encore réglée.</div>
                        </div>
                        <span class="btn btn-primary btn-sm" style="white-space:nowrap; background:linear-gradient(135deg,#635bff,#8b5cf6); border-color:transparent;">💳 Cotiser →</span>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($paieVirement): ?>
                <div class="glass-card" style="padding:1.1rem 1.35rem; border-radius:12px; border:1px solid rgba(37,211,102,0.45); background:linear-gradient(160deg, rgba(37,211,102,0.14), rgba(255,255,255,0.03));">
                    <div style="display:flex; align-items:center; gap:0.9rem; flex-wrap:wrap;">
                        <div style="font-size:1.7rem;">🎉</div>
                        <div style="flex:1; min-width:180px;">
                            <div style="color:#fff; font-weight:800; font-size:1rem;">Félicitations et merci ! 🙏</div>
                            <div style="color:var(--text-muted); font-size:0.86rem; margin-top:0.15rem;">Votre cotisation de <?php echo htmlspecialchars($cotisationMoisAff); ?> est assurée par <strong style="color:#7bd8a6;">virement automatique</strong>. Jërëjëf pour votre engagement et votre régularité. 🤲</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>

        <!-- Actualités publiées (Secrétariat Général) -->
        <?php if (!empty($actualitesPubliees)): ?>
        <style>
            @keyframes actuIn { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }
            .actu-item { animation: actuIn 0.5s cubic-bezier(0.22,1,0.36,1) both; transition: transform 0.15s ease, box-shadow 0.2s ease, border-color 0.2s ease; }
            .actu-item:hover { transform: translateY(-2px); border-color: rgba(212,175,55,0.5); box-shadow: 0 8px 22px rgba(0,0,0,0.28); }
            .actu-item[open] { border-color: rgba(212,175,55,0.5); }
            .actu-item .actu-chevron { transition: transform 0.25s ease; }
            .actu-item[open] .actu-chevron { transform: rotate(90deg); }
            .actu-item .actu-body { animation: actuIn 0.35s ease both; }
            @media (prefers-reduced-motion: reduce) { .actu-item, .actu-item .actu-body { animation: none !important; } }
        </style>
        <div class="glass-card renc-wrap idx-reveal" style="margin-bottom: 1.5rem; padding: 1.4rem 1.5rem;">
            <div style="color: #fff; font-weight: 800; font-size: 1.05rem; margin-bottom: 0.9rem;">📰 Actualités</div>
            <div style="display:flex; flex-direction:column; gap:0.7rem;">
                <?php $acI = 0; foreach ($actualitesPubliees as $ac):
                    $acEmoji = function_exists('actu_type_emoji') ? actu_type_emoji((string) ($ac['type'] ?? '')) : '📰';
                    $acDelay = 0.06 * $acI + 0.05; $acI++;
                ?>
                <details class="glass-card actu-item" style="animation-delay:<?php echo number_format($acDelay, 2); ?>s;">
                    <summary style="cursor:pointer; list-style:none; display:flex; align-items:center; gap:0.5rem; color:#fff; font-weight:700;">
                        <span><?php echo $acEmoji; ?></span>
                        <span style="flex:1; min-width:0;"><?php echo htmlspecialchars((string) ($ac['titre'] ?? '')); ?></span>
                        <?php if (!empty($ac['type'])): ?><span style="font-size:0.7rem; color:var(--accent); border:1px solid rgba(212,175,55,0.4); border-radius:12px; padding:0.1rem 0.55rem; white-space:nowrap;"><?php echo htmlspecialchars((string) $ac['type']); ?></span><?php endif; ?>
                        <span class="actu-chevron" style="color:var(--text-muted);">▸</span>
                    </summary>
                    <div class="actu-body">
                        <div style="color:var(--text-muted); font-size:0.78rem; margin-top:0.5rem;">📅 <?php echo date('d/m/Y', strtotime((string) ($ac['published_at'] ?? $ac['created_at']))); ?></div>
                        <div style="color:#e8e8e8; font-size:0.9rem; line-height:1.55; white-space:pre-wrap; margin-top:0.5rem;"><?php echo function_exists('actu_contenu_html') ? actu_contenu_html((string) ($ac['contenu'] ?? ''), actu_mentions_map($pdo, (string) ($ac['mentions'] ?? '')), false, 'color:var(--accent);font-weight:700;text-decoration:underline;') : nl2br(htmlspecialchars((string) ($ac['contenu'] ?? ''))); ?></div>
                        <?php if (!empty($ac['image_path']) || !empty($ac['pdf_path'])): ?>
                        <div style="margin-top:0.7rem;">
                            <a href="actualite_detail.php?id=<?php echo (int) $ac['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);"><?php echo !empty($ac['image_path']) ? '🖼️' : '📎'; ?> Voir<?php echo !empty($ac['pdf_path']) && empty($ac['image_path']) ? ' le document' : (!empty($ac['image_path']) ? ' l\'actualité' : ''); ?></a>
                        </div>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Mes tâches (attribuées suite aux réunions) — juste après les Actualités -->
        <?php if (!empty($mesTaches)): ?>
        <style>
            .mt-actions form { margin: 0; }
            @media (max-width: 600px) {
                .mt-actions { flex-direction: column; align-items: stretch !important; }
                .mt-actions form, .mt-actions select, .mt-actions .btn { width: 100%; box-sizing: border-box; }
                .mt-actions select { width: 100%; }
                .mt-actions .btn { justify-content: center; }
                .mt-voir .btn { width: 100%; justify-content: center; box-sizing: border-box; }
            }
        </style>
        <div class="glass-card renc-wrap idx-reveal" style="margin-bottom: 1.5rem; padding: 1.4rem 1.5rem;">
            <div style="color: #fff; font-weight: 800; font-size: 1.05rem; margin-bottom: 0.9rem;">✅ Mes tâches (<?php echo count($mesTaches); ?>)</div>
            <div style="display:flex; flex-direction:column; gap:0.6rem;">
                <?php foreach ($mesTaches as $mt):
                    $stt = (string) ($mt['statut'] ?? 'a_faire');
                    $badgeTxt = $stt === 'en_cours' ? '🔧 En cours' : '⏳ À faire';
                    $badgeStyle = $stt === 'en_cours'
                        ? 'background:rgba(45,140,255,0.15); color:#8ec5ff; border:1px solid rgba(45,140,255,0.4);'
                        : 'background:rgba(255,255,255,0.06); color:var(--text-muted); border:1px solid var(--glass-border);';
                ?>
                <div class="renc-card">
                    <div style="display:flex; justify-content:space-between; gap:0.6rem; align-items:flex-start; flex-wrap:wrap;">
                        <div style="font-weight:800; color:#fff;">📌 <?php echo htmlspecialchars((string) $mt['titre']); ?></div>
                        <span style="font-size:0.72rem; font-weight:700; border-radius:12px; padding:0.15rem 0.6rem; white-space:nowrap; <?php echo $badgeStyle; ?>"><?php echo $badgeTxt; ?></span>
                    </div>
                    <div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.35rem;">
                        👥 <?php echo htmlspecialchars((string) ($mt['commission_nom'] ?? '')); ?> · 📅 <?php echo htmlspecialchars((string) $mt['reunion_titre']); ?>
                        <?php if (!empty($mt['echeance'])): ?> · ⏰ <?php echo date('d/m/Y', strtotime((string) $mt['echeance'])); ?><?php endif; ?>
                    </div>
                    <div class="mt-actions" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-top:0.6rem;">
                        <form method="POST" action="index.php" style="margin:0;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="maj_tache_statut">
                            <input type="hidden" name="tache_id" value="<?php echo (int) $mt['id']; ?>">
                            <select name="statut" onchange="this.form.submit()" title="Changer le statut" style="background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--white); border-radius:8px; padding:0.35rem 0.6rem; font-size:0.82rem; color-scheme:dark;">
                                <option value="a_faire" <?php echo $stt === 'a_faire' ? 'selected' : ''; ?>>⏳ À faire</option>
                                <option value="en_cours" <?php echo $stt === 'en_cours' ? 'selected' : ''; ?>>🔧 En cours</option>
                                <option value="fait" <?php echo $stt === 'fait' ? 'selected' : ''; ?>>✅ Fait</option>
                            </select>
                        </form>
                    </div>
                    <form method="POST" action="index.php" style="margin:0.6rem 0 0; display:flex; flex-direction:column; gap:0.4rem;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_ma_tache_commentaire">
                        <input type="hidden" name="tache_id" value="<?php echo (int) $mt['id']; ?>">
                        <input type="text" name="commentaire" placeholder="💬 Ajouter un commentaire…" maxlength="1000" style="width:100%; box-sizing:border-box; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); color:var(--white); border-radius:8px; padding:0.4rem 0.6rem; font-size:0.83rem;">
                        <button type="submit" class="btn btn-secondary btn-sm" style="align-self:flex-start;">Envoyer</button>
                    </form>
                    <div class="mt-voir" style="margin-top:0.6rem;">
                        <a href="reunion_detail.php?id=<?php echo (int) $mt['reunion_id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">👁️ Voir</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Séances publiées : Dahira & Guddi Àjjuma (validation de présence) — avant le Coran -->
        <?php if (!empty($prochainDahira) || !empty($prochainGuddi)): ?>
        <div class="glass-card renc-wrap idx-reveal" style="margin-bottom: 1.5rem; padding: 1.4rem 1.5rem;">
            <div style="color: #fff; font-weight: 800; font-size: 1.05rem; margin-bottom: 0.9rem;">🕌 Prochaines rencontres — validez votre présence</div>
            <?php
                // Ordre par date : la plus lointaine d'abord, la date la plus ancienne (proche) en dernier
                $dOrd = 0; $gOrd = 1;
                if (!empty($prochainDahira) && !empty($prochainGuddi)) {
                    // Celui dont la date est la plus grande (la plus lointaine) passe en premier
                    if ((string) $prochainGuddi['date_guddi'] > (string) $prochainDahira['date_dahira']) {
                        $gOrd = 0; $dOrd = 1; // Guddi plus lointain → en premier
                    } else {
                        $dOrd = 0; $gOrd = 1; // Dahira plus lointain (ou égal) → en premier
                    }
                }
            ?>
            <div style="display:flex; flex-wrap:wrap; --fx-min:260px; gap: 1rem;">

                <?php if (!empty($prochainDahira)): ?>
                <?php
                    $dDate = $prochainDahira['date_dahira'];
                    $dPassed = dahira_presence_etat($dDate, $dahiraHeure, $dahiraFin !== '' ? $dahiraFin : null) === 'ouverte';
                    $dDebutAtteint = dahira_presence_ouverte($dDate, $dahiraHeure);
                    $dEtat = dahira_event_etat($dDate, $dahiraHeure, $dahiraFin !== '' ? $dahiraFin : null);
                ?>
                <div class="renc-card<?php echo $dEtat === 'encours' ? ' renc-card-live' : ''; ?>" style="order:<?php echo (int) $dOrd; ?>;">
                    <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                        <span style="font-weight:800; color:#fff;"><?php echo dahira_type_emoji($prochainDahira['type_evenement'] ?? 'dahira') . ' ' . htmlspecialchars(dahira_type_libelle($prochainDahira['type_evenement'] ?? 'dahira')); ?> — <?php echo ucfirst(dahira_jour_fr($dDate)) . ' ' . date('d/m/Y', strtotime($dDate)); ?></span>
                        <?php if ($dEtat === 'encours'): ?><span class="renc-badge renc-badge-live">🔴 En cours</span><?php endif; ?>
                    </div>
                    <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.35rem; white-space:pre-line;">🕐 <?php echo htmlspecialchars($dahiraHeure); ?> à <?php echo htmlspecialchars($dahiraFin); ?><br>📍 <?php echo htmlspecialchars($dahiraLieu); ?></div>
                    <div style="margin-top:0.6rem;"><a href="dahira_detail.php?id=<?php echo (int)$prochainDahira['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent); width:100%; text-align:center;">👁️ Voir le détail</a></div>
                    <?php if (isset($_SESSION['player_id'])): ?>
                        <?php if ($dDebutAtteint && $dahiraPresenceFaite): ?>
                            <div style="margin-top:0.8rem; color:#7bd8a6; font-weight:700;">✅ Présence confirmée — Diadieuf</div>
                            <button type="button" class="btn btn-secondary btn-sm" style="margin-top:0.6rem; width:100%; text-align:center;" onclick="annulerPresence('dahira', <?php echo (int)$prochainDahira['id']; ?>, this)">↩️ Annuler ma présence</button>
                        <?php elseif ($dPassed): ?>
                            <button type="button" class="btn btn-primary btn-sm" style="margin-top:0.8rem; width:100%;" onclick="validerPresence('dahira', <?php echo (int)$prochainDahira['id']; ?>, this)">✅ J'étais présent(e)</button>
                        <?php else: ?>
                            <div style="margin-top:0.8rem; color:var(--text-muted); font-size:0.82rem;"><?php echo htmlspecialchars(dahira_presence_message($dDate, $dahiraHeure, $dahiraFin !== '' ? $dahiraFin : null)); ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="margin-top:0.8rem; color:var(--text-muted); font-size:0.82rem;">🔐 <a href="login.php" style="color:var(--accent);">Connectez-vous</a> pour valider votre présence.</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($prochainGuddi)): ?>
                <?php
                    $gDate = $prochainGuddi['date_guddi'];
                    $gMode = (string)($prochainGuddi['mode'] ?? '');
                    if ($gMode === '') { $gMode = dahira_param($pdo, 'guddi_mode_defaut', 'distance'); }
                    // Heures propres à la séance si renseignées, sinon valeurs par défaut
                    $gHeure = trim((string)($prochainGuddi['heure'] ?? '')) !== '' ? trim((string)$prochainGuddi['heure']) : $guddiHeure;
                    $gHeureFin = trim((string)($prochainGuddi['heure_fin'] ?? '')) !== '' ? trim((string)$prochainGuddi['heure_fin']) : dahira_param($pdo, 'guddi_heure_fin', '');
                    $gActif = ((int)($prochainGuddi['actif'] ?? 1)) === 1;
                    $gEtat = dahira_event_etat($gDate, $gHeure, $gHeureFin !== '' ? $gHeureFin : null);
                    $gPassed = dahira_presence_etat($gDate, $gHeure, $gHeureFin !== '' ? $gHeureFin : null) === 'ouverte';
                    $gDebutAtteint = dahira_presence_ouverte($gDate, $gHeure);
                ?>
                <div class="renc-card<?php echo !$gActif ? ' renc-card-annule' : ($gEtat === 'encours' ? ' renc-card-live' : ''); ?>" style="order:<?php echo (int) $gOrd; ?>;">
                    <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                        <span style="font-weight:800; color:#fff;">💎 Guddi Àjjuma — <?php echo ucfirst(guddi_jour_fr($gDate)) . ' ' . date('d/m/Y', strtotime($gDate)); ?></span>
                        <?php if (!$gActif): ?>
                            <span class="renc-badge renc-badge-annule">‼️ Annulé</span>
                        <?php elseif ($gEtat === 'encours'): ?>
                            <span class="renc-badge renc-badge-live">🔴 En cours</span>
                        <?php endif; ?>
                    </div>
                    <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.35rem;">🕐 <?php echo $gHeureFin !== '' ? 'de ' . htmlspecialchars($gHeure) . ' à ' . htmlspecialchars($gHeureFin) : 'à partir de ' . htmlspecialchars($gHeure); ?> · <?php echo $gMode === 'presentiel' ? '🏛️ présentiel' : '💻 à distance'; ?></div>
                    <?php if (!empty($prochainGuddi['theme'])): ?><div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.2rem;">🎯 <?php echo htmlspecialchars((string)$prochainGuddi['theme']); ?></div><?php endif; ?>
                    <div style="margin-top:0.6rem;"><a href="guddi_detail.php?id=<?php echo (int)$prochainGuddi['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent); width:100%; text-align:center;">👁️ Voir le détail</a></div>
                    <?php if (!$gActif): ?>
                        <div style="margin-top:0.8rem; color:#fca5a5; font-weight:700;">‼️ Ce Guddi Àjjuma est annulé.</div>
                    <?php elseif (isset($_SESSION['player_id'])): ?>
                        <?php if ($gDebutAtteint && $guddiPresenceFaite): ?>
                            <div style="margin-top:0.8rem; color:#7bd8a6; font-weight:700;">✅ Présence confirmée — Diadieuf</div>
                            <button type="button" class="btn btn-secondary btn-sm" style="margin-top:0.6rem; width:100%; text-align:center;" onclick="annulerPresence('guddi', <?php echo (int)$prochainGuddi['id']; ?>, this)">↩️ Annuler ma présence</button>
                        <?php elseif ($gPassed): ?>
                            <button type="button" class="btn btn-primary btn-sm" style="margin-top:0.8rem; width:100%;" onclick="validerPresence('guddi', <?php echo (int)$prochainGuddi['id']; ?>, this)">✅ J'étais présent(e)</button>
                        <?php else: ?>
                            <div style="margin-top:0.8rem; color:var(--text-muted); font-size:0.82rem;"><?php echo htmlspecialchars(dahira_presence_message($gDate, $gHeure, $gHeureFin !== '' ? $gHeureFin : null)); ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="margin-top:0.8rem; color:var(--text-muted); font-size:0.82rem;">🔐 <a href="login.php" style="color:var(--accent);">Connectez-vous</a> pour valider votre présence.</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>

        <?php
        // Carte d'une demande de logistique (accueil)
        $logiCarte = function (array $d, bool $avecDemandeur, bool $confirmable = false) {
            $types = logistique_types(); $vehs = logistique_vehicules(); $departs = logistique_departs(); $sts = logistique_statuts();
            $origine = ($d['depart'] ?? '') === 'autre' ? (string) ($d['depart_autre'] ?? '') : (string) ($departs[$d['depart'] ?? ''] ?? ($d['depart'] ?? ''));
            $desc = (string) ($d['description'] ?? '');
            $desc = mb_strlen($desc) > 110 ? mb_substr($desc, 0, 110) . '…' : $desc;
            $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
            $h = '<div class="renc-card">';
            $h .= '<div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem; flex-wrap:wrap;"><span style="font-weight:800; color:#fff;">' . $e(($types[$d['type']] ?? $d['type']) . ' · ' . ($vehs[$d['vehicule']] ?? $d['vehicule'])) . '</span><span class="badge">' . $e($sts[$d['statut']] ?? $d['statut']) . '</span></div>';
            $h .= '<div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.35rem; line-height:1.5;">📋 ' . $e($d['commission_nom'] ?? '');
            if ($avecDemandeur && trim((string) ($d['dem_nom'] ?? '')) !== '') { $h .= ' · 👤 ' . $e($d['dem_nom']); }
            $h .= '<br>' . $e($desc) . '<br>📍 ' . $e($origine) . ' → ' . $e($d['destination'] ?? '');
            if (!empty($d['date_besoin'])) { $h .= ' · 🗓️ ' . $e($d['date_besoin']); }
            if (!empty($d['execute_le'])) { $h .= '<br>✅ Exécutée le ' . $e(date('d/m/Y à H:i', strtotime((string) $d['execute_le']))); }
            if (trim((string) ($d['reponse'] ?? '')) !== '') { $h .= '<br>💬 ' . $e($d['reponse']); }
            $h .= '</div>';
            if ($confirmable) {
                $h .= '<form method="POST" action="index.php" style="margin:0.7rem 0 0;" data-confirm="Confirmer que cette demande a bien été exécutée ? Elle n\'apparaîtra plus sur votre accueil.">'
                    . csrf_field() . '<input type="hidden" name="action" value="logi_confirmer"><input type="hidden" name="demande_id" value="' . (int) $d['id'] . '">'
                    . '<button type="submit" class="btn btn-primary btn-sm" style="width:100%; text-align:center;">✅ Confirmer l\'exécution</button></form>';
            }
            $h .= '</div>';
            return $h;
        };
        ?>
        <!-- Demandes de logistique à traiter (responsables Logistique) -->
        <?php if (!empty($logiATraiter)): ?>
        <div class="glass-card renc-wrap idx-reveal" style="margin-bottom: 1.5rem; padding: 1.4rem 1.5rem;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.9rem;">
                <div style="color: #fff; font-weight: 800; font-size: 1.05rem;">🚚 Demandes de logistique à traiter <span class="badge badge-approved"><?php echo (int) $logiNbATraiter; ?></span></div>
                <a href="logistique_demandes.php" style="color:var(--accent); font-size:0.85rem; font-weight:700; text-decoration:none;">Traiter les demandes →</a>
            </div>
            <div style="display:flex; flex-wrap:wrap; --fx-min:260px; gap: 1rem;">
                <?php foreach ($logiATraiter as $ld) { echo $logiCarte($ld, true); } ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Mes demandes de logistique (demandeur) -->
        <?php if (!empty($logiMesDemandes)): ?>
        <div class="glass-card renc-wrap idx-reveal" style="margin-bottom: 1.5rem; padding: 1.4rem 1.5rem;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.9rem;">
                <div style="color: #fff; font-weight: 800; font-size: 1.05rem;">🚚 Mes demandes de logistique</div>
                <a href="logistique_demande.php" style="color:var(--accent); font-size:0.85rem; font-weight:700; text-decoration:none;">Voir / nouvelle demande →</a>
            </div>
            <div style="display:flex; flex-wrap:wrap; --fx-min:260px; gap: 1rem;">
                <?php foreach ($logiMesDemandes as $ld) { echo $logiCarte($ld, false, in_array($ld['statut'], ['executee', 'terminee'], true)); } ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Sondages en cours -->
        <?php if (!empty($sondagesEnCours)): ?>
        <div class="glass-card renc-wrap idx-reveal" style="margin-bottom: 1.5rem; padding: 1.4rem 1.5rem;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.9rem;">
                <div style="color: #fff; font-weight: 800; font-size: 1.05rem;">📊 Sondages en cours</div>
                <a href="sondages.php" style="color:var(--accent); font-size:0.85rem; font-weight:700; text-decoration:none;">Voir tous les sondages →</a>
            </div>
            <div style="display:flex; flex-wrap:wrap; --fx-min:260px; gap: 1rem;">
                <?php foreach ($sondagesEnCours as $sd): ?>
                <div class="renc-card">
                    <div style="font-weight:800; color:#fff; line-height:1.35;">📊 <?php echo htmlspecialchars((string) $sd['question']); ?></div>
                    <div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.35rem;">📋 <?php echo htmlspecialchars((string) ($sd['commission_nom'] ?? '')); ?><?php if (!empty($sd['date_fin'])): ?> · ⏳ jusqu'au <?php echo htmlspecialchars(date('d/m/Y', strtotime((string) $sd['date_fin']))); ?><?php endif; ?></div>
                    <?php if ($sd['_repondu']): ?>
                        <div style="margin-top:0.6rem; color:#7bd8a6; font-weight:700; font-size:0.85rem;">✅ Vous avez répondu</div>
                        <a href="sondages.php#s<?php echo (int) $sd['id']; ?>" class="btn btn-secondary btn-sm" style="margin-top:0.5rem; width:100%; text-align:center;">Voir / modifier ma réponse</a>
                    <?php else: ?>
                        <a href="sondages.php#s<?php echo (int) $sd['id']; ?>" class="btn btn-primary btn-sm" style="margin-top:0.8rem; width:100%; text-align:center;">🗳️ Répondre au sondage</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Réunions de commission publiées (à venir) -->
        <?php if (!empty($mesReunions)): ?>
        <div class="glass-card renc-wrap idx-reveal" style="margin-bottom: 1.5rem; padding: 1.4rem 1.5rem;">
            <div style="color: #fff; font-weight: 800; font-size: 1.05rem; margin-bottom: 0.9rem;">👥 Réunions de vos commissions</div>
            <div style="display:flex; flex-wrap:wrap; --fx-min:260px; gap: 1rem;">
                <?php foreach ($mesReunions as $mr): ?>
                <div class="renc-card">
                    <div style="font-weight:800; color:#fff;">📌 <?php echo htmlspecialchars($mr['titre']); ?></div>
                    <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.35rem;">📋 <?php echo htmlspecialchars((string)($mr['commission_nom'] ?? '')); ?><br>🗓️ <?php echo date('d/m/Y', strtotime($mr['date_reunion'])); ?> · 🕐 <?php echo htmlspecialchars($mr['heure']); ?> · <?php echo ((string)$mr['mode'] === 'presentiel') ? '🏛️ présentiel' : '💻 à distance'; ?></div>
                    <div style="margin-top:0.6rem;"><a href="reunion_detail.php?id=<?php echo (int)$mr['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent); width:100%; text-align:center;">👁️ Voir le détail</a></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Répétitions de Kurel publiées (à venir) -->
        <?php if (!empty($mesRepetitions)): ?>
        <div class="glass-card renc-wrap" style="margin-bottom: 1.5rem; padding: 1.4rem 1.5rem;">
            <div style="color: #fff; font-weight: 800; font-size: 1.05rem; margin-bottom: 0.9rem;">🎵 Répétitions de vos Kurels</div>
            <div style="display:flex; flex-wrap:wrap; --fx-min:260px; gap: 1rem;">
                <?php foreach ($mesRepetitions as $mp): ?>
                <div class="renc-card">
                    <div style="font-weight:800; color:#fff;">🎼 <?php echo htmlspecialchars($mp['titre']); ?></div>
                    <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.35rem;">🎵 <?php echo htmlspecialchars((string)($mp['kourel_nom'] ?? '')); ?><br>🗓️ <?php echo date('d/m/Y', strtotime($mp['date_reunion'])); ?> · 🕐 <?php echo htmlspecialchars($mp['heure']); ?> · <?php echo ((string)$mp['mode'] === 'presentiel') ? '🏛️ présentiel' : '💻 à distance'; ?></div>
                    <div style="margin-top:0.6rem;"><a href="repetition_detail.php?id=<?php echo (int)$mp['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent); width:100%; text-align:center;">👁️ Voir le détail</a></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($myWirdParts) || !empty($wirdSessions)): ?>
            <form id="idx-w-form" method="POST" action="wird.php" style="display:none;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="session_id" id="idx-wf-sid" value="<?php echo (int)$myWirdSession['sid']; ?>">
                <input type="hidden" name="token" id="idx-wf-token" value="<?php echo htmlspecialchars($myWirdSession['token'], ENT_QUOTES); ?>">
                <input type="hidden" name="action" id="idx-wf-action" value="validate">
                <input type="hidden" name="part_id" id="idx-wf-pid" value="">
                <input type="hidden" name="ptoken" value="">
            </form>
            <div class="w-tovalidate-idx idx-reveal idx-delay">
                <div class="wtv-idx-top">
                    <div class="wtv-idx-icon">📖</div>
                    <div class="wtv-idx-titles">
                        <span class="wtv-idx-title">Lecture du Coran</span>
                        <span class="wtv-idx-session">Sessions en cours · vos Juz à valider</span>
                    </div>
                    <?php if (!empty($myWirdParts)): ?>
                        <span class="wtv-idx-count"><?php echo count($myWirdParts); ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($myWirdGroups)): ?>
                <div class="wtv-idx-groups">
                    <?php foreach ($myWirdGroups as $g): ?>
                    <div class="wtv-idx-group">
                        <div class="wtv-idx-grouphead">📖 <?php echo htmlspecialchars($g['titre']); ?><?php if (!empty($g['periode'])): ?> <span style="color:#7dd3fc; font-weight:600;">🗓️ <?php echo htmlspecialchars($g['periode']); ?></span><?php endif; ?> <span style="color:var(--text-muted); font-weight:600;">· <?php echo count($g['parts']); ?> Juz</span></div>
                        <div class="wtv-idx-list" style="margin-top:0.5rem;">
                            <?php foreach ($g['parts'] as $mp): ?>
                            <?php
                                $n = (int)$mp['numero'];
                                $n2 = sprintf('%02d', $n);
                                $v1 = "pdf_viewer.php?file=" . urlencode("Coran_pdf/Version_1/{$n2}.pdf");
                                $v2 = "pdf_viewer.php?file=" . urlencode("Coran_pdf/Version_2/{$n2}-quran{$n}-ar.pdf");
                            ?>
                            <span class="wtv-item-idx">
                                <span class="wtv-num">Juz <?php echo $n; ?></span>
                                <button type="button" class="wtv-btn" onclick="idxConfirmValidation(<?php echo (int)$mp['id']; ?>, <?php echo (int)$g['sid']; ?>, <?php echo htmlspecialchars(json_encode($g['token']), ENT_QUOTES); ?>)">✓ Lu</button>
                                <a href="<?php echo htmlspecialchars($v1); ?>" target="_blank" class="wtv-btn wtv-btn--pdf">V1</a>
                                <a href="<?php echo htmlspecialchars($v2); ?>" target="_blank" class="wtv-btn wtv-btn--pdf">V2</a>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="wtv-idx-hint" style="margin-top:0.7rem;">Cliquez sur ✓ Lu après chaque lecture, ou ouvrez directement le PDF souhaité.</div>
                <?php endif; ?>

                <?php if (!empty($wirdSessions)): ?>
                <div style="margin-top:0.9rem; padding-top:0.8rem; border-top:1px solid rgba(255,255,255,0.08);">
                    <div style="color:#fff; font-size:0.85rem; font-weight:700; margin-bottom:0.4rem;">📚 Autres sessions en cours — choisissez un Juz</div>
                    <?php foreach ($wirdSessions as $w): ?>
                        <?php $wPer = wird_period_label($pdo, $w); ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; padding: 0.55rem 0; border-top: 1px solid rgba(255,255,255,0.06);">
                            <span style="color: #fff;"><strong><?php echo htmlspecialchars($w['titre']); ?></strong><?php if ($wPer !== ''): ?> <span style="color:#7dd3fc; font-size:0.85rem; font-weight:600;">🗓️ <?php echo htmlspecialchars($wPer); ?></span><?php endif; ?> <span style="color: var(--text-muted); font-size: 0.85rem;">· <?php echo (int)$w['lues']; ?>/30 lues · <strong style="color:#ffd873;"><?php echo (int)$w['libres']; ?></strong> Juz libres</span></span>
                            <a href="wird.php?s=<?php echo (int)$w['id']; ?>&t=<?php echo htmlspecialchars($w['token'], ENT_QUOTES); ?>" class="btn btn-primary btn-sm" style="white-space:nowrap;">Choisir un Juz</a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Notifications actives du membre (groupe dépliable) -->
        <?php if (!empty($dashNotifs)): ?>
        <?php $dashNonLues = count(array_filter($dashNotifs, function ($n) { return ($n['read_at'] ?? null) === null; })); ?>
        <div class="glass-card dash-notif-wrap">
            <button type="button" class="dash-notif-toggle open" id="dash-notif-toggle" aria-expanded="true" onclick="dashToggleNotifs(this)">
                <span>🔔 Mes notifications<?php if ($dashNonLues > 0): ?><span class="dash-notif-count"><?php echo $dashNonLues; ?> non lue<?php echo $dashNonLues > 1 ? 's' : ''; ?></span><?php endif; ?></span>
                <span class="nt-chev">▾</span>
            </button>
            <div class="dash-notif-body open" id="dash-notif-body">
                <div class="dash-notif-list" id="dash-notif-list" style="margin-top:0.9rem;">
                <?php foreach ($dashNotifs as $n): ?>
                    <?php
                        $nId    = (int)$n['id'];
                        $nTitre = (string)($n['title'] ?? 'Notification');
                        $nTexte = (string)($n['body'] ?? '');
                        $nUrl   = (string)($n['url'] ?? '');
                        $nNonLu = ($n['read_at'] ?? null) === null;
                        $nDate  = !empty($n['created_at']) ? date('d/m/Y H:i', strtotime((string)$n['created_at'])) : '';
                        $nKind  = (string)($n['kind'] ?? '');
                        $nIcon  = '🔔';
                        if (stripos($nKind, 'guddi') !== false || stripos($nTitre, 'guddi') !== false) { $nIcon = '💎'; }
                        elseif (stripos($nKind, 'dahira') !== false || stripos($nTitre, 'dahira') !== false) { $nIcon = '🕌'; }
                        elseif (stripos($nKind, 'kikanla') !== false || stripos($nTitre, 'ki kan la') !== false) { $nIcon = '🎮'; }
                        elseif (stripos($nKind, 'wird') !== false || stripos($nTitre, 'coran') !== false) { $nIcon = '📖'; }
                        elseif (stripos($nKind, 'presence') !== false) { $nIcon = '✅'; }
                    ?>
                    <a class="dash-notif-item<?php echo $nNonLu ? ' non-lu' : ''; ?>" href="<?php echo $nUrl !== '' ? htmlspecialchars($nUrl) : 'javascript:void(0)'; ?>" data-id="<?php echo $nId; ?>" data-url="<?php echo $nUrl !== '' ? htmlspecialchars($nUrl) : ''; ?>"<?php echo $nUrl === '' ? ' onclick="dashSupprNotif(this); return false;"' : ''; ?>>
                        <div class="ni-ic"><?php echo $nIcon; ?></div>
                        <div class="ni-body">
                            <div class="ni-titre"><?php echo htmlspecialchars($nTitre); ?></div>
                            <?php if ($nTexte !== ''): ?><div class="ni-texte"><?php echo nl2br(htmlspecialchars($nTexte)); ?></div><?php endif; ?>
                            <?php if ($nDate !== ''): ?><div class="ni-date">🕐 <?php echo htmlspecialchars($nDate); ?></div><?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Le trombinoscope (grille, recherche, filtres) vit désormais dans trombinoscope.php -->
            </div>
        </div>
    </main>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <?php include __DIR__ . '/modern_popup.php'; ?>

    <!-- Modal de confirmation validation lecture (index.php) -->
    <div id="idx-confirm-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card">
            <div class="modal-header"><h3 class="gold-text">Confirmation</h3></div>
            <div class="modal-body"><p style="color:var(--text-muted);">Avez-vous bien terminé la lecture de ce Juz ?</p></div>
            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                <button type="button" onclick="idxCloseConfirmModal()" class="btn btn-secondary btn-sm">Non, annuler</button>
                <button type="button" id="idx-confirm-btn" class="btn btn-primary btn-sm">Oui, j'ai lu</button>
            </div>
        </div>
    </div>

    <script>
        var idxConfirmPid = 0, idxConfirmSid = 0, idxConfirmToken = '';
        function idxConfirmValidation(pid, sid, token) {
            idxConfirmPid = pid;
            idxConfirmSid = sid || 0;
            idxConfirmToken = token || '';
            var modal = document.getElementById('idx-confirm-modal');
            modal.style.display = 'flex';
            setTimeout(function() { modal.classList.add('active'); }, 10);
        }
        function idxCloseConfirmModal() {
            var modal = document.getElementById('idx-confirm-modal');
            modal.classList.remove('active');
            setTimeout(function() { modal.style.display = 'none'; }, 400);
        }
        var idxConfirmBtn = document.getElementById('idx-confirm-btn');
        if (idxConfirmBtn) {
            idxConfirmBtn.addEventListener('click', function() {
                if (idxConfirmPid) {
                    document.getElementById('idx-wf-pid').value = idxConfirmPid;
                    if (idxConfirmSid) { document.getElementById('idx-wf-sid').value = idxConfirmSid; }
                    if (idxConfirmToken) { document.getElementById('idx-wf-token').value = idxConfirmToken; }
                    document.getElementById('idx-w-form').submit();
                }
            });
        }
    </script>

    <script>
        // Validation de présence (Dahira / Guddi Àjjuma publiés)
        var presenceToken = <?php echo json_encode(isset($trobaCsrf) ? $trobaCsrf : ''); ?>;
        // Ouverture / fermeture du groupe « Mes notifications »
        function dashToggleNotifs(btn) {
            var body = document.getElementById('dash-notif-body');
            if (!body) { return; }
            var open = body.classList.toggle('open');
            btn.classList.toggle('open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        // Suppression d'une notification (clic sur la carte)
        function dashSupprNotif(el) {
            if (!el || !el.getAttribute('data-id')) { return; }
            var id = el.getAttribute('data-id');
            var token = presenceToken;
            if (token === '') {
                try { token = document.querySelector('meta[name="csrf-token"]').getAttribute('content'); } catch (e) {}
            }
            var corps = new URLSearchParams();
            corps.set('action', 'supprimer');
            corps.set('id', id);
            corps.set('csrf_token', token);
            fetch('notifications.php', { method: 'POST', body: corps })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok && el.parentNode) {
                        el.parentNode.removeChild(el);
                        var restants = document.querySelectorAll('.dash-notif-item').length;
                        var wrap = document.getElementById('dash-notif-list');
                        if (wrap && restants === 0) {
                            var p = document.createElement('p');
                            p.style.cssText = 'color:var(--text-muted); font-size:0.85rem; margin:0; text-align:center;';
                            p.textContent = 'Aucune notification pour le moment.';
                            wrap.appendChild(p);
                        }
                    } else if (window.modernAlert) {
                        window.modernAlert('Erreur lors de la suppression.', 'Erreur');
                    }
                })
                .catch(function () {
                    if (window.modernAlert) { window.modernAlert('Une erreur est survenue. Réessayez.', 'Erreur'); }
                });
        }
        function validerPresence(type, id, btn) {
            if (presenceToken === '') {
                try { presenceToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content'); } catch (e) {}
            }
            var corps = new URLSearchParams();
            corps.set('action', 'validate');
            corps.set('type', type);
            corps.set('id', String(id));
            corps.set('csrf_token', presenceToken);
            fetch('presence_validate.php', { method: 'POST', body: corps })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { window.location.reload(); }
                    else if (window.modernAlert) { window.modernAlert(d.msg || 'Erreur.', 'Erreur'); }
                    else { alert(d.msg || 'Erreur.'); }
                })
                .catch(function () {
                    if (window.modernAlert) { window.modernAlert('Une erreur est survenue. Réessayez.', 'Erreur'); }
                    else { alert('Une erreur est survenue. Réessayez.'); }
                });
        }
        function annulerPresence(type, id, btn) {
            var label = type === 'dahira' ? 'le Dahira' : 'le Guddi Àjjuma';
            if (window.modernConfirm) {
                window.modernConfirm(
                    'Voulez-vous vraiment <strong>annuler votre présence</strong> à ' + label + ' ?<br><br><span style="color:var(--text-muted); font-size:0.85rem;">Vous ne serez plus compté(e) comme présent(e) pour cet événement.</span>',
                    function () { annulerPresenceOk(type, id); },
                    { danger: true, bouton: 'Oui, annuler', titre: '↩️ Annuler ma présence' }
                );
            } else {
                annulerPresenceOk(type, id);
            }
        }
        function annulerPresenceOk(type, id) {
            var corps = new URLSearchParams();
            corps.set('action', 'cancel');
            corps.set('type', type);
            corps.set('id', String(id));
            corps.set('csrf_token', presenceToken);
            fetch('presence_validate.php', { method: 'POST', body: corps })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { window.location.reload(); }
                    else if (window.modernAlert) { window.modernAlert(d.msg || 'Erreur.'); }
                    else { alert(d.msg || 'Erreur.'); }
                })
                .catch(function () {
                    if (window.modernAlert) { window.modernAlert('Une erreur est survenue. Réessayez.'); }
                    else { alert('Une erreur est survenue. Réessayez.'); }
                });
        }
    </script>

    <!-- Le pull-to-refresh est désormais inclus globalement via header.php (pull_refresh.php) -->

</body>
</html>
