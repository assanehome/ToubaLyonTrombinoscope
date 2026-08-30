<?php
/**
 * Touba Lyon 2026 - 💎 Planning « Guddi Àjjuma »
 *
 * Associé à la commission « Culte ». Permet de :
 *   - définir les paramètres (heure, thème, présentateur, lien Zoom par défaut) ;
 *   - générer les jeudis de Guddi Àjjuma sur une période ;
 *   - saisir le thème / présentateur / lien de chaque séance ;
 *   - préparer le message WhatsApp (lien wa.me) et l'e-mail aux membres ;
 *   - annuler un Guddi Àjjuma et diffuser le message d'annulation.
 */
require_once __DIR__ . '/guddi_guard.php'; // admins + responsables commission Culte
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dahira_emails.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_destinataires, wa_link, wa_button, dahira_param
require_once __DIR__ . '/planning_guddi_helper.php';

$error = '';
$success = '';

// Commission « Culte »
function guddi_commission_id(PDO $pdo): int
{
    static $cid = null;
    if ($cid !== null) {
        return $cid;
    }
    try {
        $st = $pdo->prepare("SELECT id FROM commissions WHERE LOWER(nom) LIKE '%culte%' LIMIT 1");
        $st->execute();
        $cid = (int) $st->fetchColumn();
        if ($cid > 0) {
            return $cid;
        }
    } catch (Exception $e) {
        // table absente
    }
    $cid = 0;
    return $cid;
}

$commissionId = guddi_commission_id($pdo);

// ---------------------------------------------------------------------------
// Actions POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';

        // Réglages généraux
        if ($action === 'save_params') {
            dahira_set_param($pdo, 'guddi_heure', trim($_POST['heure'] ?? ''));
            dahira_set_param($pdo, 'guddi_theme_defaut', trim($_POST['theme_defaut'] ?? ''));
            $presDefaut = trim($_POST['presentateur_defaut'] ?? '');
            // Si « Autre / saisie libre », prendre le champ libre
            if ($presDefaut === '__libre__') {
                $presDefaut = trim($_POST['presentateur_defaut_libre'] ?? '');
            }
            dahira_set_param($pdo, 'guddi_presentateur_defaut', $presDefaut);
            dahira_set_param($pdo, 'guddi_lien_defaut', trim($_POST['lien_defaut'] ?? ''));
            dahira_set_param($pdo, 'guddi_mode_defaut', ($_POST['mode_defaut'] ?? 'distance') === 'presentiel' ? 'presentiel' : 'distance');
            dahira_set_param($pdo, 'guddi_lieu_defaut', trim($_POST['lieu_defaut'] ?? ''));
            $success = "Paramètres par défaut du Guddi Àjjuma enregistrés.";
        }

        // Générer les jeudis sur une période
        elseif ($action === 'generate_thursdays') {
            $from = $_POST['period_start'] ?? '';
            $to = $_POST['period_end'] ?? '';
            $jeudis = guddi_jeudis($from, $to);
            if (empty($jeudis)) {
                $error = "Veuillez indiquer une période valide contenant au moins un jeudi.";
            } else {
                $added = 0;
                try {
                    $st = $pdo->prepare("INSERT IGNORE INTO guddi_plannings (date_guddi, commission_id) VALUES (?, ?)");
                    foreach ($jeudis as $d) {
                        $st->execute([$d, $commissionId > 0 ? $commissionId : null]);
                        if ($st->rowCount() > 0) { $added++; }
                    }
                    $success = count($jeudis) . " jeudi(s) de Guddi Àjjuma planifié(s) (" . $added . " nouveau(x), " . (count($jeudis) - $added) . " déjà présent(s)).";
                } catch (Exception $e) {
                    error_log('Touba Lyon guddi - génération : ' . $e->getMessage());
                    $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
                }
            }
        }

        // Enregistrer le thème / présentateur / lien / livre / PDF d'un jeudi
        elseif ($action === 'save_seance') {
            $id = (int) ($_POST['id'] ?? 0);
            $theme = trim($_POST['theme'] ?? '');
            $presentateur = trim($_POST['presentateur'] ?? '');
            // Si « Autre / saisie libre », prendre le champ libre
            if ($presentateur === '__libre__') {
                $presentateur = trim($_POST['presentateur_libre'] ?? '');
            }
            $lien = null; // Le lien Zoom n'est plus modifiable par séance : toujours celui des paramètres du Guddi Àjjuma.
            $livre = trim($_POST['livre'] ?? '');
            $mode = ($_POST['mode'] ?? 'distance') === 'presentiel' ? 'presentiel' : 'distance';
            if ($id > 0) {
                try {
                    // Upload d'un programme PDF (facultatif)
                    $pdfPath = null;
                    if (!empty($_FILES['pdf_programme']['name']) && ($_FILES['pdf_programme']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $tmp = $_FILES['pdf_programme']['tmp_name'] ?? '';
                        $nom = $_FILES['pdf_programme']['name'] ?? '';
                        if ($tmp !== '' && is_uploaded_file($tmp)) {
                            $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
                            if ($ext === 'pdf') {
                                $uploadDir = __DIR__ . '/uploads';
                                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
                                $fname = 'guddi_' . $id . '_' . date('Ymd_His') . '.pdf';
                                if (move_uploaded_file($tmp, $uploadDir . '/' . $fname)) {
                                    $pdfPath = $fname;
                                } else {
                                    $error = "Impossible de déplacer le fichier PDF.";
                                }
                            } else {
                                $error = "Le fichier doit être au format PDF.";
                            }
                        }
                    }
                    if ($error === '') {
                        if ($pdfPath !== null) {
                            $pdo->prepare("UPDATE guddi_plannings SET theme = ?, presentateur = ?, lien = ?, livre = ?, pdf_path = ?, mode = ?, actif = 1, updated_at = NOW() WHERE id = ?")
                                ->execute([$theme !== '' ? $theme : null, $presentateur !== '' ? $presentateur : null, $lien !== '' ? $lien : null, $livre !== '' ? $livre : null, $pdfPath, $mode, $id]);
                        } else {
                            $pdo->prepare("UPDATE guddi_plannings SET theme = ?, presentateur = ?, lien = ?, livre = ?, mode = ?, actif = 1, updated_at = NOW() WHERE id = ?")
                                ->execute([$theme !== '' ? $theme : null, $presentateur !== '' ? $presentateur : null, $lien !== '' ? $lien : null, $livre !== '' ? $livre : null, $mode, $id]);
                        }
                        $success = "Séance du Guddi Àjjuma enregistrée." . ($pdfPath !== null ? " PDF joint." : "");
                    }
                } catch (Exception $e) {
                    error_log('Touba Lyon guddi - séance : ' . $e->getMessage());
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Supprimer le PDF lié à une séance
        elseif ($action === 'delete_pdf') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $st = $pdo->prepare("SELECT pdf_path FROM guddi_plannings WHERE id = ?");
                    $st->execute([$id]);
                    $old = $st->fetchColumn();
                    if (!empty($old)) {
                        $oldFile = __DIR__ . '/uploads/' . basename($old);
                        if (is_file($oldFile)) { @unlink($oldFile); }
                    }
                    $pdo->prepare("UPDATE guddi_plannings SET pdf_path = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
                    $success = "Le PDF du livre étudié a été supprimé.";
                } catch (Exception $e) {
                    error_log('Touba Lyon guddi - suppression PDF : ' . $e->getMessage());
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Charger les valeurs par défaut dans la séance
        elseif ($action === 'load_default') {
            $id = (int) ($_POST['id'] ?? 0);
            $theme = dahira_param($pdo, 'guddi_theme_defaut', 'Sëriñ Tuubaa ak Gammu');
            $presentateur = dahira_param($pdo, 'guddi_presentateur_defaut', 'Oustaz Sëriñ Mbàcke Géy');
            $lien = dahira_param($pdo, 'guddi_lien_defaut', '');
            $mode = dahira_param($pdo, 'guddi_mode_defaut', 'distance');
            if ($id > 0) {
                try {
                    $pdo->prepare("UPDATE guddi_plannings SET theme = ?, presentateur = ?, lien = ?, mode = ?, actif = 1, updated_at = NOW() WHERE id = ?")
                        ->execute([$theme, $presentateur, $lien, $mode, $id]);
                    $success = "Paramètres par défaut chargés dans la séance.";
                } catch (Exception $e) {
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Annuler / réactiver un Guddi Àjjuma
        elseif ($action === 'toggle_annulation') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $pdo->prepare("UPDATE guddi_plannings SET actif = 1 - actif, updated_at = NOW() WHERE id = ?")->execute([$id]);
                    $success = "Statut du Guddi Àjjuma mis à jour.";
                } catch (Exception $e) {
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Publier / dépublier le Guddi Àjjuma sur l'accueil membre (validation de présence)
        elseif ($action === 'toggle_publie') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $st = $pdo->prepare("SELECT date_guddi, publie, theme, presentateur FROM guddi_plannings WHERE id = ?");
                    $st->execute([$id]);
                    $rowP = $st->fetch();
                    if (!$rowP) {
                        $error = "Ce jeudi n'existe plus.";
                    } else {
                        $nouveau = ((int)($rowP['publie'] ?? 0)) === 1 ? 0 : 1;
                        $pdo->prepare("UPDATE guddi_plannings SET publie = ?, updated_at = NOW() WHERE id = ?")->execute([$nouveau, $id]);
                        if ($nouveau === 1) {
                            // Notifier tous les membres validés
                            require_once __DIR__ . '/notification_helper.php';
                            $dateLongue = guddi_date_longue($rowP['date_guddi']);
                            $themeTxt = trim((string)($rowP['theme'] ?? ''));
                            $body = '💎 Guddi Àjjuma du ' . $dateLongue . ($themeTxt !== '' ? ' — ' . $themeTxt : '') . '. Validez votre présence !';
                            troba_notify_all_membres($pdo, 'guddi_publie', '💎 Guddi Àjjuma publié', $body, 'guddi_detail.php?id=' . $id);
                            $success = "Guddi Àjjuma publié : " . count($pdo->query("SELECT id FROM membres WHERE status = 'approved'")->fetchAll()) . " membre(s) notifié(s).";
                        } else {
                            $success = "Guddi Àjjuma dépublié.";
                        }
                    }
                } catch (Exception $e) {
                    error_log('Touba Lyon guddi - publication : ' . $e->getMessage());
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Clôturer une séance (passée ou du jour), nombre de participants = présences validées
        elseif ($action === 'cloture') {
            $id = (int) ($_POST['id'] ?? 0);
            $nb = trim($_POST['nb_participants'] ?? '');
            if ($id > 0) {
                try {
                    // Autoriser la clôture uniquement si la date est passée ou aujourd'hui
                    $st = $pdo->prepare("SELECT date_guddi FROM guddi_plannings WHERE id = ?");
                    $st->execute([$id]);
                    $d = $st->fetchColumn();
                    if ($d === false) {
                        $error = "Ce jeudi n'existe plus.";
                    } elseif ($d > date('Y-m-d')) {
                        $error = "Impossible de clôturer : la séance n'a pas encore eu lieu.";
                    } else {
                        // Si le champ est vide : nombre = présences validées par les membres
                        if ($nb === '') {
                            try {
                                $stP = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'guddi' AND planning_id = ?");
                                $stP->execute([$id]);
                                $nb = (string) $stP->fetchColumn();
                            } catch (Exception $e) {
                                $nb = '';
                            }
                        }
                        $pdo->prepare("UPDATE guddi_plannings SET cloture = 1, nb_participants = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$nb !== '' ? (int) $nb : null, $id]);
                        $success = "Séance clôturée." . ($nb !== '' ? " Participants : $nb." : "");
                    }
                } catch (Exception $e) {
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Envoyer l'e-mail d'annonce ou d'annulation aux membres
        elseif ($action === 'send_email') {
            $id = (int) ($_POST['id'] ?? 0);
            $type = $_POST['type'] ?? 'annonce'; // annonce | annulation
            require_once __DIR__ . '/send_mail.php';
            try {
                $st = $pdo->prepare("SELECT * FROM guddi_plannings WHERE id = ?");
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) {
                    $error = "Ce jeudi n'existe plus.";
                } else {
                    $date = $row['date_guddi'];
                    $heure = dahira_param($pdo, 'guddi_heure', '20h00');
                    $lienEff = dahira_param($pdo, 'guddi_lien_defaut', ''); // lien Zoom toujours celui des paramètres
                    $lieuGuddi = dahira_param($pdo, 'guddi_lieu_defaut', '');
                    $lieuEff = $lieuGuddi !== '' ? $lieuGuddi : dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
                    $modeEff = (string)($row['mode'] ?? '');
                    if ($modeEff === '') {
                        $modeEff = dahira_param($pdo, 'guddi_mode_defaut', 'distance');
                    }
                    // Envoi à TOUS les membres validés (avec e-mail), comme demandé.
                    try {
                        $destinataires = $pdo->query("SELECT id, nom, prenom, email, telephone FROM membres WHERE status = 'approved' AND email IS NOT NULL AND email != '' ORDER BY nom ASC, prenom ASC")->fetchAll();
                    } catch (Exception $e) {
                        $destinataires = [];
                    }
                    if (empty($destinataires)) {
                        $error = "Aucun membre à prévenir.";
                    } else {
                        if ($type === 'annulation' || (int)($row['actif'] ?? 1) === 0) {
                            $sujet = '‼️ Annulation Guddi Àjjuma — ' . guddi_date_longue($date);
                            $corps = guddi_email_annulation($date);
                        } else {
                            $sujet = '💎 Guddi Àjjuma — ' . guddi_date_longue($date);
                            $pdfUrl = !empty($row['pdf_path']) ? 'https://toubalyon.com/Dahira/uploads/' . rawurlencode($row['pdf_path']) : '';
                            $corps = guddi_email($date, $heure, (string)($row['theme'] ?? ''), (string)($row['presentateur'] ?? ''), $lienEff, (string)($row['livre'] ?? ''), $pdfUrl, $modeEff, $lieuEff);
                        }
                        $sent = 0;
                        foreach ($destinataires as $m) {
                            $nom = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
                            if (!empty($m['email'])) {
                                if (send_smtp_mail($m['email'], $nom !== '' ? $nom : 'Cher membre', $sujet, $corps)) { $sent++; }
                            }
                        }
                        $pdo->prepare("UPDATE guddi_plannings SET email_envoye = 1, updated_at = NOW() WHERE id = ?")->execute([$id]);
                        $success = ($type === 'annulation' || (int)($row['actif'] ?? 1) === 0 ? "Annulation envoyée" : "Annonce envoyée") . " par e-mail à $sent membre(s).";
                    }
                }
            } catch (Exception $e) {
                error_log('Touba Lyon guddi - email : ' . $e->getMessage());
                $error = "Une erreur technique est survenue lors de l'envoi.";
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Données
// ---------------------------------------------------------------------------
$heure = dahira_param($pdo, 'guddi_heure', '20h00');
$themeDefaut = dahira_param($pdo, 'guddi_theme_defaut', 'Sëriñ Tuubaa ak Gammu');
$presentateurDefaut = dahira_param($pdo, 'guddi_presentateur_defaut', 'Oustaz Sëriñ Mbàcke Géy');
$lienDefaut = dahira_param($pdo, 'guddi_lien_defaut', '');
$modeDefaut = dahira_param($pdo, 'guddi_mode_defaut', 'distance');
$lieuDefaut = dahira_param($pdo, 'guddi_lieu_defaut', '');
$lieuDahira = $lieuDefaut !== '' ? $lieuDefaut : dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
$groupeWa = dahira_param($pdo, 'wa_group_link', '');

$plannings = [];
try {
    $plannings = $pdo->query("SELECT * FROM guddi_plannings ORDER BY date_guddi ASC")->fetchAll();
} catch (Exception $e) {
    $plannings = [];
}

// Séparation des jeudis à venir / passés (les passés sont regroupés, repliés)
$planningsAvenir = [];
$planningsPasses = [];
$today = date('Y-m-d');
foreach ($plannings as $p) {
    if ($p['date_guddi'] >= $today) {
        $planningsAvenir[] = $p;
    } else {
        $planningsPasses[] = $p;
    }
}

// Séances passées non clôturées (à traiter) : seul ce sous-ensemble est affiché
$planningsPassesNonClotures = array_values(array_filter($planningsPasses, static function ($p) {
    return ((int)($p['cloture'] ?? 0)) !== 1;
}));

// Séances passées clôturées (historique, affiché en bas de page)
$planningsPassesCloturees = array_values(array_filter($planningsPasses, static function ($p) {
    return ((int)($p['cloture'] ?? 0)) === 1;
}));

// Nombre de présences validées par séance (source de l'indicateur Participants)
$presencesGuddi = [];
try {
    $stPres = $pdo->query("SELECT planning_id, COUNT(*) AS n FROM presence_validations WHERE planning_type = 'guddi' GROUP BY planning_id");
    while ($r = $stPres->fetch()) { $presencesGuddi[(int)$r['planning_id']] = (int)$r['n']; }
} catch (Exception $e) {
    $presencesGuddi = [];
}

// Nombre de participants d'une séance : présences validées, sinon saisie manuelle
$nbPartGuddi = static function (array $p) use ($presencesGuddi): int {
    $presence = $presencesGuddi[(int)($p['id'] ?? 0)] ?? 0;
    return $presence > 0 ? $presence : (int)($p['nb_participants'] ?? 0);
};

// Indicateurs globaux et par année (pour filtrage)
$statsGlobales = [
    'a_venir'       => count($planningsAvenir),
    'seances'       => count($planningsPasses),
    'cloturees'     => count($planningsPassesCloturees),
    'annulees'      => 0,
    'participants'  => 0,
    'livres'        => 0,
];
foreach ($plannings as $p) {
    if (((int)($p['actif'] ?? 1)) !== 1) {
        $statsGlobales['annulees']++;
    }
    if ($p['date_guddi'] < $today) {
        $statsGlobales['participants'] += $nbPartGuddi($p);
        if (!empty(trim((string)($p['livre'] ?? '')))) {
            $statsGlobales['livres']++;
        }
    }
}

// Statistiques par année : séances, clôturées, annulées, participants, livres, à venir.
$statsParAnnee = [];
foreach ($plannings as $p) {
    $annee = date('Y', strtotime($p['date_guddi']));
    if (!isset($statsParAnnee[$annee])) {
        $statsParAnnee[$annee] = ['a_venir' => 0, 'seances' => 0, 'cloturees' => 0, 'annulees' => 0, 'participants' => 0, 'livres' => 0];
    }
    if ($p['date_guddi'] >= $today) {
        $statsParAnnee[$annee]['a_venir']++;
    } else {
        $statsParAnnee[$annee]['seances']++;
        if (((int)($p['cloture'] ?? 0)) === 1) {
            $statsParAnnee[$annee]['cloturees']++;
        }
        $statsParAnnee[$annee]['participants'] += $nbPartGuddi($p);
        if (!empty(trim((string)($p['livre'] ?? '')))) {
            $statsParAnnee[$annee]['livres']++;
        }
    }
    if (((int)($p['actif'] ?? 1)) !== 1) {
        $statsParAnnee[$annee]['annulees']++;
    }
}
krsort($statsParAnnee); // année la plus récente en premier
$anneesDisponibles = array_keys($statsParAnnee);

// Prochain jeudi planifié (qu'il soit actif OU annulé) : on l'affiche pour
// pouvoir le gérer (diffuser l'annonce, l'annulation ou le réactiver).
$prochain = null;
foreach ($plannings as $p) {
    if ($p['date_guddi'] >= date('Y-m-d')) { $prochain = $p; break; }
}

// Nombre de membres VALIDÉS (avec e-mail) qui recevront les annonces
$nbMembres = 0;
try {
    $nbMembres = (int) $pdo->query("SELECT COUNT(*) FROM membres WHERE status = 'approved' AND email IS NOT NULL AND email != ''")->fetchColumn();
} catch (Exception $e) {
    $nbMembres = 0;
}

// Membres de la commission Culte (pour la liste des présentateurs)
$membresCulte = [];
if ($commissionId > 0) {
    try {
        $st = $pdo->prepare("
            SELECT m.id, m.prenom, m.nom
            FROM membres m
            JOIN commission_membres cm ON cm.membre_id = m.id
            WHERE cm.commission_id = ? AND m.status = 'approved'
            ORDER BY m.prenom ASC, m.nom ASC
        ");
        $st->execute([$commissionId]);
        $membresCulte = $st->fetchAll();
    } catch (Exception $e) {
        $membresCulte = [];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💎 Planning Guddi Àjjuma — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .pl-wrap { max-width: 980px; margin: 2rem auto; }
        /* Panneaux repliables de cette page : padding réduit, hauteur compacte */
        details.glass-card {
            padding: 0.9rem 1.1rem;
        }
        details.glass-card > summary {
            padding: 0.25rem 0;
            line-height: 1.3;
        }
        .pl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
        .pl-card { border-radius: 16px; padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: 0.6rem; }
        .pl-date { font-size: 1.05rem; font-weight: 700; color: var(--white); }
        .pl-date small { color: var(--text-muted); font-weight: 400; font-size: 0.78rem; }
        .pl-prog { white-space: pre-wrap; color: var(--text-muted); font-size: 0.82rem; line-height: 1.5; background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.7rem; max-height: 220px; overflow-y: auto; }
        .pl-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .pl-badge { font-size: 0.7rem; padding: 0.15rem 0.45rem; border-radius: 6px; }
        .pl-badge-ok { background: rgba(37,211,102,0.15); color: #7bd8a6; border: 1px solid rgba(37,211,102,0.3); }
        .pl-badge-no { background: rgba(255,255,255,0.05); color: var(--text-muted); border: 1px solid var(--glass-border); }
        .pl-badge-annul { background: rgba(191,33,33,0.15); color: #fca5a5; border: 1px solid rgba(191,33,33,0.4); }
        details.pl-details summary { cursor: pointer; color: var(--text-muted); font-size: 0.8rem; }
        details.pl-details pre { white-space: pre-wrap; font-family: inherit; font-size: 0.83rem; color: var(--white); background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.9rem; margin-top: 0.5rem; }
        .pl-params { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.8rem; }
        .pl-params .form-group { margin: 0; }
        /* Paramètres : une ligne par information (champs empilés en pleine largeur) */
        .pl-params-stack { display: flex; flex-direction: column; }
        .pl-params-stack .form-group { width: 100%; }
        .pl-params-stack .form-input { width: 100%; box-sizing: border-box; }
        /* Sélecteurs de dates : centrés dans la glass-card, sans dépasser */
        .pl-params {
            min-width: 0;
            width: 100%;
            max-width: 100%;
        }
        .pl-params .form-group {
            min-width: 0;
            max-width: 100%;
        }
        .pl-params .form-group input[type="date"] {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }
        #frm-generate {
            width: 100%;
            min-width: 0;
            max-width: 100%;
        }
        #frm-generate .pl-params {
            grid-template-columns: repeat(auto-fit, minmax(0, 1fr));
            max-width: 560px;
            margin-left: auto;
            margin-right: auto;
        }
        @media (max-width: 640px) {
            .pl-params { grid-template-columns: 1fr !important; }
            .pl-params .form-group { width: 100%; max-width: 100%; min-width: 0; }
            .pl-params input[type="date"] { width: 100%; max-width: 100%; min-width: 0; box-sizing: border-box; }
            input[name="period_start"], input[name="period_end"] {
                width: 100%;
                max-width: 240px;
                min-width: 0;
                box-sizing: border-box;
                margin-left: auto;
                margin-right: auto;
                display: block;
            }
            #frm-generate .pl-params { max-width: 100%; }
            #frm-generate .pl-params .form-group { min-width: 0; max-width: 100%; }
            #frm-generate .form-label { text-align: center; }
        }
        /* Case à cocher « En présentiel » : grande et moderne (bouton-toggle) */
        .guddi-presentiel-toggle {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            padding: 1rem 1.1rem;
            border: 1.5px solid var(--glass-border);
            border-radius: 14px;
            background: rgba(255,255,255,0.03);
            cursor: pointer;
            transition: var(--transition);
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }
        .guddi-presentiel-toggle:hover { border-color: var(--accent); background: rgba(212,175,55,0.07); }
        .guddi-presentiel-toggle.checked {
            border-color: rgba(37,211,102,0.6);
            background: rgba(37,211,102,0.08);
        }
        .guddi-presentiel-toggle input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 28px;
            height: 28px;
            min-width: 28px;
            border: 2px solid rgba(255,255,255,0.35);
            border-radius: 9px;
            background: rgba(255,255,255,0.05);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            margin: 0;
        }
        .guddi-presentiel-toggle input[type="checkbox"]:checked {
            background: var(--accent);
            border-color: var(--accent);
        }
        .guddi-presentiel-toggle input[type="checkbox"]:checked::after {
            content: '✓';
            color: #08150f;
            font-size: 1.35rem;
            font-weight: 900;
            line-height: 1;
        }
        .guddi-presentiel-toggle .guddi-toggle-ico { font-size: 1.5rem; }
        .guddi-presentiel-toggle .guddi-toggle-txt { font-size: 1rem; font-weight: 600; color: var(--white); line-height: 1.35; }
        .guddi-presentiel-toggle .guddi-toggle-txt small { display: block; font-weight: 400; font-size: 0.78rem; color: var(--text-muted); }
        @media (max-width: 520px) {
            .guddi-presentiel-toggle { padding: 1.1rem; }
            .guddi-presentiel-toggle .guddi-toggle-txt { font-size: 1.05rem; }
            .guddi-presentiel-toggle input[type="checkbox"] { width: 32px; height: 32px; min-width: 32px; }
        }
        /* Boutons de filtre par année (indicateurs) : pilules modernes */
        .guddi-annee-btn {
            border-radius: 50px;
            padding: 0.45rem 1rem;
            font-size: 0.85rem;
            border: 1px solid var(--glass-border);
            background: rgba(255,255,255,0.04);
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition);
        }
        .guddi-annee-btn:hover { border-color: var(--accent); color: var(--accent); background: rgba(212,175,55,0.08); }
        .guddi-annee-btn.active {
            background: linear-gradient(135deg, #d4af37, #e8c766);
            color: #08150f;
            border-color: transparent;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(212,175,55,0.35);
        }
        /* Flèche du panneau dépliable : rotation à l'ouverture */
        details[open] > summary .pl-chevron { transform: rotate(90deg); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__guAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>

            <div class="admin-content">
                <h1 class="admin-page-title">💎 Planning Guddi Àjjuma</h1>
                <p class="admin-page-desc" style="color:var(--text-muted); margin-top:0.9rem; font-size:0.9rem;">
                    <?php echo $commissionId > 0 ? '' : '<span style="color:#ffd873;">(Aucune commission « Culte » trouvée : les annonces iront à tous les membres validés.)</span>'; ?>
                </p>

                <!-- Indicateurs (groupe replié fermé par défaut, filtrables par année) -->
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">📊 Indicateurs <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <div style="margin-top:0.9rem;">
                        <!-- Boutons de filtre par année -->
                        <?php
                        $anneeCourante = (int) date('Y');
                        $anneeCouranteDispo = in_array($anneeCourante, array_map('intval', $anneesDisponibles), true);
                        $filtreDefaut = $anneeCouranteDispo ? $anneeCourante : 'all';
                        ?>
                        <?php if (count($anneesDisponibles) > 0): ?>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem;">
                            <button type="button" class="btn btn-sm guddi-annee-btn <?php echo $filtreDefaut === 'all' ? 'active' : ''; ?>" data-annee="all">Toutes</button>
                            <?php foreach ($anneesDisponibles as $an): ?>
                                <button type="button" class="btn btn-sm guddi-annee-btn <?php echo ((int)$an === $filtreDefaut) ? 'active' : ''; ?>" data-annee="<?php echo (int)$an; ?>"><?php echo (int)$an; ?></button>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="pl-stats" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.75rem;">
                            <?php
                            // Une carte par indicateur, valeurs stockées en data-annee
                            $cartesIndicateurs = [
                                ['key' => 'a_venir', 'label' => '💎 À venir', 'color' => 'var(--accent)'],
                                ['key' => 'seances', 'label' => '🕰️ Séances passées', 'color' => 'var(--white)'],
                                ['key' => 'cloturees', 'label' => '✅ Clôturées', 'color' => '#7bd8a6'],
                                ['key' => 'annulees', 'label' => '‼️ Annulées', 'color' => '#fca5a5'],
                                ['key' => 'participants', 'label' => '👥 Participants', 'color' => 'var(--white)'],
                                ['key' => 'livres', 'label' => '📖 Livres étudiés', 'color' => 'var(--white)'],
                            ];
                            foreach ($cartesIndicateurs as $carte):
                                $valAffichee = $filtreDefaut === 'all' ? $statsGlobales[$carte['key']] : ($statsParAnnee[$filtreDefaut][$carte['key']] ?? 0);
                                $dataAttrs = 'data-global="' . (int)$statsGlobales[$carte['key']] . '"';
                                foreach ($anneesDisponibles as $an) {
                                    $v = $statsParAnnee[$an][$carte['key']] ?? 0;
                                    $dataAttrs .= ' data-' . (int)$an . '="' . (int)$v . '"';
                                }
                            ?>
                            <div class="glass-card" style="padding:1rem; text-align:center;">
                                <div class="guddi-stat-valeur" style="font-size:1.6rem; font-weight:700; color:<?php echo $carte['color']; ?>;" <?php echo $dataAttrs; ?>><?php echo (int)$valAffichee; ?></div>
                                <div style="color:var(--text-muted); font-size:0.82rem;"><?php echo $carte['label']; ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>

                <?php if (!empty($success)): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <!-- Passés non clôturés (groupe replié, en haut) -->
                <?php if (!empty($planningsPassesNonClotures)): ?>
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">🕰️ Séances passées à clôturer (<?php echo count($planningsPassesNonClotures); ?>) <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <div class="pl-grid" style="margin-top:0.9rem;">
                        <?php foreach ($planningsPassesNonClotures as $p):
                            $d = $p['date_guddi'];
                            $actif = ((int)($p['actif'] ?? 1)) === 1;
                        ?>
                        <div class="glass-card pl-card" style="opacity:0.8; border-left:3px solid rgba(255,255,255,0.18);">
                            <div class="pl-date">
                                <?php echo ucfirst(guddi_jour_fr($d)); ?> <?php echo date('d/m/Y', strtotime($d)); ?>
                                <small>· passé</small>
                            </div>
                            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                                <span>
                                <?php if ($actif): ?>
                                    <span class="pl-badge pl-badge-no" style="font-size:0.75rem; padding:0.25rem 0.6rem;">💎 Terminé</span>
                                <?php else: ?>
                                    <span class="pl-badge pl-badge-annul" style="font-size:0.75rem; padding:0.25rem 0.6rem;">‼️ Annulé</span>
                                <?php endif; ?>
                                </span>
                                <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                                    <a href="guddi_detail.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-secondary btn-sm" style="font-size:0.7rem; padding:0.2rem 0.5rem; border-color:var(--accent); color:var(--accent);">👁️ Détail</a>
                                    <button type="button" class="btn btn-secondary btn-sm guddi-cloture" data-id="<?php echo (int)$p['id']; ?>" data-date="<?php echo date('d/m/Y', strtotime($d)); ?>" data-presence="<?php echo (int)($presencesGuddi[(int)$p['id']] ?? 0); ?>" style="font-size:0.7rem; padding:0.2rem 0.5rem; border-color:rgba(37,211,102,0.6); color:#7bd8a6;">✅ Clôturer</button>
                                    <form action="admin_guddi.php" method="POST" style="margin:0;" data-confirm="<?php echo $actif ? 'Marquer ce Guddi Àjjuma comme annulé ?' : 'Réactiver ce Guddi Àjjuma ?'; ?>">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_annulation">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" style="font-size:0.7rem; padding:0.2rem 0.5rem;"><?php echo $actif ? '‼️ Annuler' : '↩️ Réactiver'; ?></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>

                <!-- Prochain Guddi Àjjuma -->
                <div class="glass-card" style="margin-bottom:1.5rem; border:2px solid rgba(212,175,55,0.55); background:linear-gradient(160deg, rgba(212,175,55,0.09) 0%, rgba(255,255,255,0.02) 100%);">
                    <?php if ($prochain):
                        $pdate = $prochain['date_guddi'];
                        $theme = (string)($prochain['theme'] ?? '');
                        $presentateur = (string)($prochain['presentateur'] ?? '');
                        // Le lien de participation n'est plus modifiable par séance :
                        // on utilise toujours celui défini dans les paramètres.
                        $lienAffiche = $lienDefaut;
                        $prochainActif = ((int)($prochain['actif'] ?? 1)) === 1;
                        $prochainMode = (string)($prochain['mode'] ?? '');
                        if ($prochainMode === '') { $prochainMode = $modeDefaut; }
                        $waMsg = $prochainActif
                            ? guddi_message($pdate, $heure, $theme, $presentateur, $lienAffiche, (string)($prochain['livre'] ?? ''), !empty($prochain['pdf_path']) ? 'https://toubalyon.com/Dahira/uploads/' . rawurlencode($prochain['pdf_path']) : '', $prochainMode, $lieuDahira)
                            : guddi_message_annulation($pdate);
                        $waAnnul = guddi_message_annulation($pdate);
                    ?>
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1.5rem; flex-wrap:wrap;">
                            <div>
                                <h3 style="color:var(--white); font-size:1.1rem; margin-bottom:0.4rem;">💎 Prochain Guddi Àjjuma
                                    <?php if (!$prochainActif): ?>
                                        <span class="pl-badge pl-badge-annul" style="font-size:0.75rem; padding:0.25rem 0.6rem; vertical-align:middle;">‼️ Annulé</span>
                                    <?php endif; ?>
                                </h3>
                                <div style="font-size:1.6rem; font-weight:700; color:<?php echo $prochainActif ? 'var(--accent)' : 'var(--danger)'; ?>; line-height:1.2;">
                                    <?php echo ucfirst(guddi_jour_fr($pdate)) . ' ' . date('d/m/Y', strtotime($pdate)); ?>
                                </div>
                                <div style="font-size:0.95rem; color:var(--white); margin-top:0.3rem;">
                                    🕐 à partir de <strong><?php echo htmlspecialchars($heure); ?></strong> — <?php echo $prochainMode === 'presentiel' ? '🏛️ en présentiel (<span style="white-space:pre-line;">' . htmlspecialchars($lieuDahira) . '</span>) + 💻 à distance' : '💻 participation à distance'; ?>
                                </div>
                                <?php if ($theme !== ''): ?><div style="font-size:0.85rem; color:var(--text-muted); margin-top:0.25rem;">🎯 Thème : <?php echo htmlspecialchars($theme); ?></div><?php endif; ?>
                                <?php if ($presentateur !== ''): ?><div style="font-size:0.85rem; color:var(--text-muted);">🎤 Présentateur : <?php echo htmlspecialchars($presentateur); ?></div><?php endif; ?>
                                <?php if ($lienAffiche !== '' && $prochainActif): ?><div style="font-size:0.82rem; color:var(--text-muted); margin-top:0.25rem;">🔗 <a href="<?php echo htmlspecialchars($lienAffiche); ?>" target="_blank" rel="noopener" style="color:var(--accent);">Lien de participation Zoom</a></div><?php endif; ?>
                                <?php if (!empty($prochain['pdf_path'])): ?><div style="font-size:0.82rem; color:var(--text-muted); margin-top:0.25rem;">📄 <a href="uploads/<?php echo htmlspecialchars($prochain['pdf_path']); ?>" target="_blank" rel="noopener" style="color:var(--accent);">Livre étudié PDF joint</a></div><?php endif; ?>
                                <form action="admin_guddi.php" method="POST" style="margin-top:0.5rem;" data-confirm="<?php echo $prochainActif ? 'Marquer ce Guddi Àjjuma comme annulé ?' : 'Réactiver ce Guddi Àjjuma ?'; ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_annulation">
                                    <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="<?php echo $prochainActif ? 'border-color:rgba(191,33,33,0.6); color:#fca5a5;' : ''; ?>"><?php echo $prochainActif ? '‼️ Marquer comme annulé' : '↩️ Réactiver'; ?></button>
                                </form>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:0.55rem; min-width:240px;">
                                <?php echo dahira_wa_button(dahira_wa_link(null, $waMsg), $prochainActif ? 'Partager sur WhatsApp' : 'Annulation WhatsApp'); ?>
                                <?php if ($groupeWa !== ''): ?>
                                    <button type="button" class="btn btn-sm" style="background:#128C7E; border:1px solid #128C7E; color:#fff; font-weight:700;" onclick="shareGroup(<?php echo htmlspecialchars(json_encode($waMsg), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($groupeWa), ENT_QUOTES); ?>)">🟢 Groupe (copier)</button>
                                <?php endif; ?>
                                <?php if ($prochainActif): ?>
                                <form action="admin_guddi.php" method="POST" style="margin:0;" data-confirm="Envoyer l'annonce du Guddi Àjjuma du <?php echo date('d/m/Y', strtotime($pdate)); ?> par e-mail à <?php echo $nbMembres; ?> membre(s) ?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="send_email">
                                    <input type="hidden" name="type" value="annonce">
                                    <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;" <?php echo $nbMembres === 0 ? 'disabled' : ''; ?>>
                                        📧 Envoyer l'annonce (<?php echo $nbMembres; ?>)
                                    </button>
                                </form>
                                <?php else: ?>
                                <form action="admin_guddi.php" method="POST" style="margin:0;" data-confirm="Envoyer l'annulation du Guddi Àjjuma du <?php echo date('d/m/Y', strtotime($pdate)); ?> par e-mail à <?php echo $nbMembres; ?> membre(s) ?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="send_email">
                                    <input type="hidden" name="type" value="annulation">
                                    <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;" <?php echo $nbMembres === 0 ? 'disabled' : ''; ?>>
                                        📧 Envoyer l'annulation (<?php echo $nbMembres; ?>)
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Zone de saisie de la séance -->
                        <form id="frm-save-seance" action="admin_guddi.php" method="POST" enctype="multipart/form-data" style="margin-top:1.1rem; padding-top:1rem; border-top:1px solid rgba(255,255,255,0.07);">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="save_seance">
                            <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                            <?php $prochainMode = (string)($prochain['mode'] ?? ''); if ($prochainMode === '') { $prochainMode = $modeDefaut; } ?>
                            <div class="form-group" style="margin-bottom:0.7rem;">
                                <label class="guddi-presentiel-toggle" id="guddi-mode-toggle" for="guddi-mode" style="width:100%; box-sizing:border-box;">
                                    <input type="checkbox" name="mode" value="presentiel" id="guddi-mode" <?php echo $prochainMode === 'presentiel' ? 'checked' : ''; ?>>
                                    <span class="guddi-toggle-ico">🏛️</span>
                                    <span class="guddi-toggle-txt">En présentiel
                                        <small>Le lien Zoom reste utilisé pour la participation à distance.</small>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group" style="margin-bottom:0.7rem;"><label class="form-label">🎯 Thème</label><input type="text" name="theme" class="form-input" value="<?php echo htmlspecialchars($theme); ?>" style="width:100%;"></div>
                            <div class="form-group" style="margin-bottom:0.7rem;">
                                <label class="form-label">🎤 Présentateur (membre de la commission Culte)</label>
                                <select name="presentateur" id="guddi-presentateur" class="form-input" style="width:100%;">
                                    <option value="">— Choisir un membre —</option>
                                    <?php foreach ($membresCulte as $mc): ?>
                                        <?php $mcNom = trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? '')); ?>
                                        <option value="<?php echo htmlspecialchars($mcNom, ENT_QUOTES); ?>" <?php echo $mcNom === $presentateur ? 'selected' : ''; ?>><?php echo htmlspecialchars($mcNom); ?></option>
                                    <?php endforeach; ?>
                                    <option value="__libre__" <?php echo $presentateur !== '' && !in_array($presentateur, array_map(static function ($mc) { return trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? '')); }, $membresCulte), true) ? 'selected' : ''; ?>>✏️ Autre / saisie libre…</option>
                                </select>
                                <input type="text" name="presentateur_libre" id="guddi-presentateur-libre" class="form-input" placeholder="Nom du présentateur (saisie libre)" value="<?php echo $presentateur !== '' && !in_array($presentateur, array_map(static function ($mc) { return trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? '')); }, $membresCulte), true) ? htmlspecialchars($presentateur) : ''; ?>" style="width:100%; margin-top:0.45rem; <?php echo $presentateur !== '' && !in_array($presentateur, array_map(static function ($mc) { return trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? '')); }, $membresCulte), true) ? '' : 'display:none;'; ?>">
                            </div>
                            <div class="form-group" style="margin-bottom:0.7rem;"><label class="form-label">📖 Livre à étudier (facultatif)</label><input type="text" name="livre" class="form-input" value="<?php echo htmlspecialchars((string)($prochain['livre'] ?? '')); ?>" placeholder="Ex : Tafsir, Khassaïd, Fiqh…" style="width:100%;"></div>
                            <div class="form-group" style="margin-bottom:0.7rem;">
                                <?php if (!empty($prochain['pdf_path'])): ?>
                                    <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-bottom:0.4rem;">
                                        <a href="uploads/<?php echo htmlspecialchars($prochain['pdf_path']); ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">📄 Voir le PDF actuel</a>
                                        <form action="admin_guddi.php" method="POST" style="margin:0;" data-confirm="Supprimer le PDF du livre étudié de cette séance ?">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_pdf">
                                            <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(191,33,33,0.6); color:#fca5a5; cursor:pointer;">🗑️ Supprimer</button>
                                        </form>
                                        <span style="color:var(--text-muted); font-size:0.8rem;">Remplacer :</span>
                                    </div>
                                <?php endif; ?>
                                <div class="mpp-file" style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-secondary btn-sm" id="mpp-file-btn" style="border-color:var(--accent); color:var(--accent); cursor:pointer;">📎 Choisir un fichier</button>
                                    <input type="file" name="pdf_programme" id="mpp-file-input" accept="application/pdf" style="display:none;">
                                    <span id="mpp-file-name" style="color:var(--text-muted); font-size:0.82rem; word-break:break-all;">Aucun fichier choisi</span>
                                </div>
                            </div>
                        </form>
                            <div style="margin-top:0.5rem; display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                                <button type="submit" form="frm-save-seance" class="btn btn-primary btn-sm">💾 Enregistrer la séance</button>
                                <form action="admin_guddi.php" method="POST" style="margin:0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="load_default">
                                    <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">📋 Charger les valeurs par défaut</button>
                                </form>
                                <a href="guddi_detail.php?id=<?php echo (int)$prochain['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">👁️ Détail</a>
                                <form action="admin_guddi.php" method="POST" style="margin:0;" data-confirm="<?php echo ((int)($prochain['publie'] ?? 0)) === 1 ? 'Dépublier ce Guddi Àjjuma de l\'accueil membre ?' : 'Publier ce Guddi Àjjuma sur l\'accueil membre (validation de présence) ?'; ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_publie">
                                    <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="<?php echo ((int)($prochain['publie'] ?? 0)) === 1 ? 'border-color:rgba(37,211,102,0.6); color:#7bd8a6;' : 'border-color:var(--accent); color:var(--accent);'; ?>"><?php echo ((int)($prochain['publie'] ?? 0)) === 1 ? '🟢 Publié' : '⚪ Publier'; ?></button>
                                </form>
                            </div>
                        <details style="margin-top:0.9rem;" class="pl-details">
                            <summary><?php echo $prochainActif ? 'Voir / copier le message d\'annonce' : 'Voir / copier le message d\'annulation'; ?></summary>
                            <pre><?php echo htmlspecialchars($waMsg); ?></pre>
                        </details>
                        <?php if ($prochainActif): ?>
                        <details style="margin-top:0.6rem;" class="pl-details">
                            <summary>Voir / copier le message d'annulation</summary>
                            <pre><?php echo htmlspecialchars($waAnnul); ?></pre>
                        </details>
                        <?php endif; ?>
                    <?php else: ?>
                        <h3 style="color:var(--white); font-size:1.1rem; margin-bottom:0.3rem;">💎 Prochain Guddi Àjjuma</h3>
                        <p style="color:var(--text-muted); margin:0;">Aucun jeudi avec séance à venir. Générez les jeudis ci-dessous.</p>
                    <?php endif; ?>
                </div>

                <!-- Paramètres -->
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">⚙️ Paramètres par défaut <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <form action="admin_guddi.php" method="POST" style="margin-top:1rem;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_params">
                        <div class="pl-params pl-params-stack">
                            <div class="form-group"><label class="form-label">🕐 Heure</label><input type="text" name="heure" class="form-input" value="<?php echo htmlspecialchars($heure); ?>" placeholder="20h00"></div>
                            <div class="form-group"><label class="form-label">🎯 Thème par défaut</label><input type="text" name="theme_defaut" class="form-input" value="<?php echo htmlspecialchars($themeDefaut); ?>"></div>
                            <div class="form-group">
                                <label class="form-label">🎤 Présentateur par défaut (membre de la commission Culte)</label>
                                <select name="presentateur_defaut" id="guddi-presentateur-defaut" class="form-input" style="width:100%;">
                                    <option value="">— Choisir un membre —</option>
                                    <?php foreach ($membresCulte as $mc): ?>
                                        <?php $mcNom = trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? '')); ?>
                                        <option value="<?php echo htmlspecialchars($mcNom, ENT_QUOTES); ?>" <?php echo $mcNom === $presentateurDefaut ? 'selected' : ''; ?>><?php echo htmlspecialchars($mcNom); ?></option>
                                    <?php endforeach; ?>
                                    <option value="__libre__" <?php echo $presentateurDefaut !== '' && !in_array($presentateurDefaut, array_map(static function ($mc) { return trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? '')); }, $membresCulte), true) ? 'selected' : ''; ?>>✏️ Autre / saisie libre…</option>
                                </select>
                                <input type="text" name="presentateur_defaut_libre" id="guddi-presentateur-defaut-libre" class="form-input" placeholder="Nom du présentateur (saisie libre)" value="<?php echo $presentateurDefaut !== '' && !in_array($presentateurDefaut, array_map(static function ($mc) { return trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? '')); }, $membresCulte), true) ? htmlspecialchars($presentateurDefaut) : ''; ?>" style="width:100%; margin-top:0.45rem; <?php echo $presentateurDefaut !== '' && !in_array($presentateurDefaut, array_map(static function ($mc) { return trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? '')); }, $membresCulte), true) ? '' : 'display:none;'; ?>">
                            </div>
                            <div class="form-group">
                                <label class="guddi-presentiel-toggle" id="guddi-mode-defaut-toggle" for="guddi-mode-defaut" style="width:100%; box-sizing:border-box;">
                                    <input type="checkbox" name="mode_defaut" value="presentiel" id="guddi-mode-defaut" <?php echo $modeDefaut === 'presentiel' ? 'checked' : ''; ?>>
                                    <span class="guddi-toggle-ico">🏛️</span>
                                    <span class="guddi-toggle-txt">En présentiel par défaut
                                        <small>Adresse ci-dessous</small>
                                    </span>
                                </label>
                            </div>
                            <div class="form-group"><label class="form-label">📍 Adresse (présentiel)</label><textarea name="lieu_defaut" class="form-input" rows="3" placeholder="1 rue du 35 régiment d'aviation,&#10;69500 Bron"><?php echo htmlspecialchars($lieuDahira); ?></textarea><p style="color:var(--text-muted); font-size:0.78rem; margin-top:0.35rem;">Vous pouvez saisir l'adresse sur plusieurs lignes.</p></div>
                            <div class="form-group"><label class="form-label">🔗 Lien de participation par défaut</label><input type="url" name="lien_defaut" class="form-input" value="<?php echo htmlspecialchars($lienDefaut); ?>" placeholder="https://us06web.zoom.us/..."></div>
                        </div>
                        <div style="margin-top:0.9rem;"><button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer</button></div>
                    </form>
                </details>

                <!-- Séances clôturées (historique) -->
                <?php if (!empty($planningsPassesCloturees)): ?>
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">✅ Séances clôturées (<?php echo count($planningsPassesCloturees); ?>) <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <div class="pl-grid" style="margin-top:0.9rem;">
                        <?php foreach ($planningsPassesCloturees as $p):
                            $d = $p['date_guddi'];
                            $actif = ((int)($p['actif'] ?? 1)) === 1;
                            $nbCloture = $nbPartGuddi($p);
                        ?>
                        <div class="glass-card pl-card" style="opacity:0.8; border-left:3px solid rgba(37,211,102,0.4);">
                            <div class="pl-date">
                                <?php echo ucfirst(guddi_jour_fr($d)); ?> <?php echo date('d/m/Y', strtotime($d)); ?>
                                <small>· passé</small>
                            </div>
                            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                                <span>
                                    <span class="pl-badge pl-badge-ok" style="font-size:0.75rem; padding:0.25rem 0.6rem;">✅ Clôturée<?php echo $nbCloture > 0 ? ' · ' . $nbCloture . ' pers.' : ''; ?></span>
                                    <?php if (!empty(trim((string)($p['livre'] ?? '')))): ?>
                                        <span class="pl-badge pl-badge-no" style="font-size:0.75rem; padding:0.25rem 0.6rem;">📖 <?php echo htmlspecialchars((string)$p['livre']); ?></span>
                                    <?php endif; ?>
                                </span>
                                <a href="guddi_detail.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-secondary btn-sm" style="font-size:0.7rem; padding:0.2rem 0.5rem; border-color:var(--accent); color:var(--accent);">👁️ Détail</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>

                <!-- Générer les jeudis -->
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">🗓️ Générer les jeudis <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <form id="frm-generate" action="admin_guddi.php" method="POST" style="margin-top:1rem;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="generate_thursdays">
                        <div class="pl-params">
                            <div class="form-group"><label class="form-label">Du</label><input type="date" name="period_start" class="form-input" value="<?php echo date('Y-m-d'); ?>" required></div>
                            <div class="form-group"><label class="form-label">Au</label><input type="date" name="period_end" class="form-input" value="<?php echo date('Y-m-d', strtotime('+6 months')); ?>" required></div>
                        </div>
                        <div style="margin-top:0.9rem;"><button type="submit" class="btn btn-primary btn-sm">➕ Générer les jeudis</button></div>
                    </form>
                </details>

                <!-- Liste des jeudis -->
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">📅 Jeudis planifiés (<?php echo count($plannings); ?>) <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <div style="margin-top:0.9rem;">
                    <!-- À venir -->
                    <?php if (empty($planningsAvenir)): ?>
                        <p style="color:var(--text-muted); text-align:center; padding:1rem 0;">Aucun jeudi à venir. Utilisez le générateur ci-dessus.</p>
                    <?php else: ?>
                    <h4 style="color:var(--accent); font-size:0.9rem; margin:0.5rem 0 0.75rem;">💎 À venir (<?php echo count($planningsAvenir); ?>)</h4>
                    <div class="pl-grid">
                        <?php foreach ($planningsAvenir as $p):
                            $d = $p['date_guddi'];
                            $actif = ((int)($p['actif'] ?? 1)) === 1;
                        ?>
                        <div class="glass-card pl-card" style="<?php echo $actif ? 'border-left:3px solid var(--accent);' : 'opacity:0.85; border-left:3px solid rgba(255,255,255,0.25);'; ?>">
                            <div class="pl-date">
                                <?php echo ucfirst(guddi_jour_fr($d)); ?> <?php echo date('d/m/Y', strtotime($d)); ?>
                            </div>
                            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                                <span>
                                <?php if ($actif): ?>
                                    <span class="pl-badge pl-badge-ok" style="font-size:0.75rem; padding:0.25rem 0.6rem;">💎 Prévu</span>
                                <?php else: ?>
                                    <span class="pl-badge pl-badge-annul" style="font-size:0.75rem; padding:0.25rem 0.6rem;">‼️ Annulé</span>
                                <?php endif; ?>
                                </span>
                                <form action="admin_guddi.php" method="POST" style="margin:0;" data-confirm="<?php echo $actif ? 'Marquer ce Guddi Àjjuma comme annulé ?' : 'Réactiver ce Guddi Àjjuma ?'; ?>">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_annulation">
                                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="font-size:0.7rem; padding:0.2rem 0.5rem;"><?php echo $actif ? '‼️ Annuler' : '↩️ Réactiver'; ?></button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    </div>
                </details>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <!-- Modale de clôture d'une séance passée -->
    <div id="cloture-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card" style="max-width:430px; width:calc(100vw - 28px);">
            <div class="modal-header"><h3 style="color:var(--accent);">✅ Clôturer la séance</h3></div>
            <div class="modal-body" style="display:block; text-align:left;">
                <p id="cloture-msg" style="margin:0 0 1rem; line-height:1.5; text-align:left;">Clôturer cette séance du Guddi Àjjuma ?</p>
                <form id="cloture-form" action="admin_guddi.php" method="POST" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cloture">
                    <input type="hidden" name="id" id="cloture-id">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="display:block; margin-bottom:0.4rem;">👥 Nombre de participants (facultatif)</label>
                        <input type="number" name="nb_participants" id="cloture-nb" class="form-input" min="0" placeholder="Ex : 25" style="width:100%;">
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; justify-content:flex-end;">
                <button type="button" id="cloture-cancel" class="btn btn-secondary btn-sm">Annuler</button>
                <button type="button" id="cloture-ok" class="btn btn-primary btn-sm">✅ Clôturer</button>
            </div>
        </div>
    </div>
    <!-- Indicateur de tirage pour actualiser -->
    <div id="ptr" style="position:fixed; top:0; left:0; right:0; height:60px; display:flex; align-items:center; justify-content:center; gap:0.6rem; color:var(--white); font-size:0.9rem; background:rgba(15,17,24,0.95); transform:translateY(-100%); transition:transform 0.25s ease; z-index:9999; pointer-events:none;">
        <span id="ptr-arrow" style="display:inline-block; font-size:1.1rem;">⬇️</span>
        <span id="ptr-text">Tirez pour actualiser</span>
    </div>
    <script>
        // Partager dans le groupe WhatsApp : copie le message puis ouvre le groupe
        function shareGroup(msg, link) {
            try { navigator.clipboard.writeText(msg); } catch (e) {}
            window.location.href = link;
        }
        // Bouton « Choisir un fichier » moderne : ouvre l'input masqué et affiche le nom du fichier
        (function () {
            var btn = document.getElementById('mpp-file-btn');
            var input = document.getElementById('mpp-file-input');
            var name = document.getElementById('mpp-file-name');
            if (!btn || !input || !name) { return; }
            btn.addEventListener('click', function () { input.click(); });
            input.addEventListener('change', function () {
                name.textContent = input.files && input.files.length ? input.files[0].name : 'Aucun fichier choisi';
            });
        })();
        // Toggle « En présentiel » : ajoute la classe .checked selon la case à cocher
        (function () {
            function initToggle(checkboxId, labelId) {
                var cb = document.getElementById(checkboxId);
                var label = document.getElementById(labelId);
                if (!cb || !label) { return; }
                function maj() { label.classList.toggle('checked', cb.checked); }
                maj();
                cb.addEventListener('change', maj);
            }
            initToggle('guddi-mode', 'guddi-mode-toggle');
            initToggle('guddi-mode-defaut', 'guddi-mode-defaut-toggle');
        })();
        // Filtrage des indicateurs par année
        (function () {
            var btns = document.querySelectorAll('.guddi-annee-btn');
            var valeurs = document.querySelectorAll('.guddi-stat-valeur');
            if (!btns.length || !valeurs.length) { return; }
            btns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    btns.forEach(function (b) {
                        b.classList.remove('active');
                    });
                    btn.classList.add('active');
                    var annee = btn.getAttribute('data-annee');
                    valeurs.forEach(function (v) {
                        var val = v.getAttribute('data-' + annee);
                        if (val === null) { val = v.getAttribute('data-global'); }
                        v.textContent = val;
                    });
                });
            });
        })();
        // Présentateur : affiche/masque le champ de saisie libre selon le select
        (function () {
            var sel = document.getElementById('guddi-presentateur');
            var libre = document.getElementById('guddi-presentateur-libre');
            if (!sel || !libre) { return; }
            function maj() {
                libre.style.display = (sel.value === '__libre__') ? '' : 'none';
                if (sel.value === '__libre__') { libre.focus(); }
            }
            sel.addEventListener('change', maj);
        })();
        // Présentateur par défaut : affiche/masque le champ de saisie libre selon le select
        (function () {
            var selD = document.getElementById('guddi-presentateur-defaut');
            var libreD = document.getElementById('guddi-presentateur-defaut-libre');
            if (!selD || !libreD) { return; }
            function majD() {
                libreD.style.display = (selD.value === '__libre__') ? '' : 'none';
                if (selD.value === '__libre__') { libreD.focus(); }
            }
            selD.addEventListener('change', majD);
        })();
        // Actualisation par glissement vers le bas en haut de page (pull-to-refresh)
        (function () {
            var bar = document.getElementById('ptr');
            var arrow = document.getElementById('ptr-arrow');
            var text = document.getElementById('ptr-text');
            if (!bar || !arrow || !text) { return; }
            var startY = 0, pulling = false, threshold = 90;
            function reset() {
                bar.style.transform = 'translateY(-100%)';
                pulling = false;
                arrow.textContent = '⬇️';
                text.textContent = 'Tirez pour actualiser';
            }
            document.addEventListener('touchstart', function (e) {
                if (window.scrollY > 0) { return; }
                startY = e.touches[0].clientY;
                pulling = true;
            }, { passive: true });
            document.addEventListener('touchmove', function (e) {
                if (!pulling || window.scrollY > 0) { return; }
                var dy = e.touches[0].clientY - startY;
                if (dy <= 0) { reset(); return; }
                dy = Math.min(dy, 140);
                bar.style.transform = 'translateY(' + (dy - 60) + 'px)';
                if (dy >= threshold) {
                    arrow.textContent = '🔄';
                    text.textContent = 'Relâchez pour actualiser';
                } else {
                    arrow.textContent = '⬇️';
                    text.textContent = 'Tirez pour actualiser';
                }
            }, { passive: true });
            document.addEventListener('touchend', function () {
                if (!pulling) { return; }
                var dy = 0;
                var m = bar.style.transform.match(/(-?\d+(?:\.\d+)?)px/);
                if (m) { dy = parseFloat(m[1]) + 60; }
                if (dy >= threshold) {
                    arrow.textContent = '⏳';
                    text.textContent = 'Actualisation…';
                    window.location.reload();
                    return;
                }
                reset();
            });
        })();
        // Modale de clôture d'une séance passée
        (function () {
            var modal = document.getElementById('cloture-modal');
            var form = document.getElementById('cloture-form');
            var idInput = document.getElementById('cloture-id');
            var msg = document.getElementById('cloture-msg');
            var okBtn = document.getElementById('cloture-ok');
            var cancelBtn = document.getElementById('cloture-cancel');
            if (!modal || !form || !idInput) { return; }
            function openModal(m) { m.style.display = 'flex'; setTimeout(function () { m.classList.add('active'); }, 10); }
            function closeModal(m) { m.classList.remove('active'); setTimeout(function () { m.style.display = 'none'; }, 280); }
            // Aujourd'hui au format jj/mm/aaaa
            var now = new Date();
            var todayStr = String(now.getDate()).padStart(2, '0') + '/' + String(now.getMonth() + 1).padStart(2, '0') + '/' + now.getFullYear();
            document.querySelectorAll('.guddi-cloture').forEach(function (btn) {
                var date = btn.getAttribute('data-date') || '';
                if (date > todayStr) { btn.style.display = 'none'; } // date future : pas de clôture
                btn.addEventListener('click', function () {
                    idInput.value = btn.getAttribute('data-id');
                    msg.textContent = 'Clôturer la séance du ' + date + ' ?';
                    var nb = document.getElementById('cloture-nb');
                    var presence = parseInt(btn.getAttribute('data-presence') || '0', 10) || 0;
                    if (nb) { nb.value = presence > 0 ? String(presence) : ''; }
                    openModal(modal);
                });
            });
            if (okBtn) { okBtn.addEventListener('click', function () { form.submit(); }); }
            if (cancelBtn) { cancelBtn.addEventListener('click', function () { closeModal(modal); }); }
            modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(modal); } });
        })();
    </script>
</body>
</html>
