<?php
/**
 * Touba Lyon 2026 - Espace Kurels (modèle « commission complet »).
 *
 * Un Kurel = un groupe avec des MEMBRES et des RESPONSABLES (rôle par Kurel).
 *  - « Admin Kurels » (administrateur ou responsable désigné des Kurels) :
 *    crée des Kurels, gère leurs membres ET désigne les responsables de chaque Kurel.
 *  - Responsable d'un Kurel : gère les MEMBRES de ses Kurels.
 */
require_once __DIR__ . '/kourel_guard.php'; // $__isAdmin, $__isKurelAdmin, $__managedKourels, $pdo
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/fonction_responsables_helper.php'; // responsables des Kurels (comme Lecture Coran)

// Ajout / retrait d'un responsable (appels AJAX de la fenêtre de sélection)
fr_traiter_post($pdo, 'kurels', !empty($__isKurelAdmin), (int) ($_SESSION['player_id'] ?? 0), !empty($__isAdmin), 'Kurels');
require_once __DIR__ . '/dahira_emails.php';        // send_smtp_mail(), dahira_base_url()
require_once __DIR__ . '/notification_helper.php';  // cloche + Web Push
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_param()
require_once __DIR__ . '/repetition_helper.php';     // répétitions de Kurel
require_once __DIR__ . '/kourel_suivi_helper.php';   // avancement des Khassidas, message à un membre

$error = '';
$success = '';

/** Le membre courant peut-il gérer les membres de ce Kurel ? */
function kurel_can_manage($kid, $isKurelAdmin, $managed)
{
    return $isKurelAdmin || in_array((int) $kid, $managed, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (($_POST['ajax'] ?? '') === '1');
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        $kid = (int) ($_POST['kourel_id'] ?? 0);
        $mid = (int) ($_POST['membre_id'] ?? 0);
        try {
            if ($action === 'create_kourel' && $__isKurelAdmin) {
                $nom = trim($_POST['nom'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                if ($nom === '') {
                    $error = "Le nom du Kurel est obligatoire.";
                } else {
                    $pdo->prepare("INSERT INTO kourels (nom, description) VALUES (?, ?)")->execute([$nom, $desc !== '' ? $desc : null]);
                    $success = "Le Kurel « " . $nom . " » a été créé.";
                }
            } elseif ($action === 'delete_kourel' && $kid > 0 && $__isKurelAdmin) {
                $pdo->prepare("DELETE FROM kourel_membres WHERE kourel_id = ?")->execute([$kid]);
                $pdo->prepare("DELETE FROM kourel_gestionnaires WHERE kourel_id = ?")->execute([$kid]);
                $pdo->prepare("DELETE FROM kourels WHERE id = ?")->execute([$kid]);
                $success = "Le Kurel a été supprimé.";
            } elseif ($action === 'add_kourel_member' && $kid > 0 && $mid > 0 && kurel_can_manage($kid, $__isKurelAdmin, $__managedKourels)) {
                $pdo->prepare("INSERT IGNORE INTO kourel_membres (kourel_id, membre_id) VALUES (?, ?)")->execute([$kid, $mid]);
                $success = "Membre ajouté.";
            } elseif ($action === 'remove_kourel_member' && $kid > 0 && $mid > 0 && kurel_can_manage($kid, $__isKurelAdmin, $__managedKourels)) {
                $pdo->prepare("DELETE FROM kourel_membres WHERE kourel_id = ? AND membre_id = ?")->execute([$kid, $mid]);
                $success = "Membre retiré.";
            } elseif ($action === 'add_kourel_manager' && $kid > 0 && $mid > 0 && $__isKurelAdmin) {
                $pdo->prepare("INSERT IGNORE INTO kourel_gestionnaires (kourel_id, membre_id) VALUES (?, ?)")->execute([$kid, $mid]);
                $success = "Responsable ajouté.";
            } elseif ($action === 'remove_kourel_manager' && $kid > 0 && $mid > 0 && $__isKurelAdmin) {
                $pdo->prepare("DELETE FROM kourel_gestionnaires WHERE kourel_id = ? AND membre_id = ?")->execute([$kid, $mid]);
                $success = "Responsable retiré.";
            } elseif ($action === 'add_khassida' && ($__isKurelAdmin || !empty($__managedKourels))) {
                $nom = trim($_POST['nom'] ?? '');
                if ($nom === '') { $error = "Le nom de la Khassida est obligatoire."; }
                else { $pdo->prepare("INSERT IGNORE INTO kourel_khassidas (nom) VALUES (?)")->execute([$nom]); $success = "Khassida ajoutée."; }
            } elseif ($action === 'delete_khassida' && ($__isKurelAdmin || !empty($__managedKourels))) {
                $xid = (int) ($_POST['item_id'] ?? 0);
                if ($xid > 0) { $pdo->prepare("DELETE FROM kourel_khassidas WHERE id = ?")->execute([$xid]); $success = "Khassida supprimée."; }
            } elseif ($action === 'add_melodie' && ($__isKurelAdmin || !empty($__managedKourels))) {
                $nom = trim($_POST['nom'] ?? '');
                if ($nom === '') { $error = "Le nom de la mélodie est obligatoire."; }
                else { $pdo->prepare("INSERT IGNORE INTO kourel_melodies (nom) VALUES (?)")->execute([$nom]); $success = "Mélodie ajoutée."; }
            } elseif ($action === 'delete_melodie' && ($__isKurelAdmin || !empty($__managedKourels))) {
                $xid = (int) ($_POST['item_id'] ?? 0);
                if ($xid > 0) { $pdo->prepare("DELETE FROM kourel_melodies WHERE id = ?")->execute([$xid]); $success = "Mélodie supprimée."; }
            } elseif ($action === 'create_repetition' && $kid > 0 && kurel_can_manage($kid, $__isKurelAdmin, $__managedKourels)) {
                $titre = trim($_POST['titre'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $dateDebut = trim($_POST['date_debut'] ?? '');
                $heure = trim($_POST['heure'] ?? '');
                $modeRaw = trim($_POST['mode'] ?? '');
                $mode = ($modeRaw === 'presentiel') ? 'presentiel' : (($modeRaw === 'distance') ? 'distance' : '');
                $lieu = trim($_POST['lieu'] ?? '');
                $lien = trim($_POST['lien'] ?? '');
                // La répétition se rattache à une Khassida du programme : ses
                // libellés sont recopiés depuis la ligne, jamais saisis à part.
                $avId = (int) ($_POST['avancement_id'] ?? 0);
                $avLigne = $avId > 0 ? kourel_avancement_du_kourel($pdo, $kid, $avId) : null;
                $khassida = $avLigne ? (string) $avLigne['khassida'] : '';
                $melodie = $avLigne ? trim((string) $avLigne['melodie']) : '';
                $estPeriodique = !empty($_POST['periodique']);
                $per = ($estPeriodique && in_array(($_POST['periodicite'] ?? ''), ['hebdomadaire', 'quinzaine', 'mensuelle'], true)) ? $_POST['periodicite'] : 'ponctuelle';
                $dateFin = trim($_POST['date_fin'] ?? '');
                if ($avId > 0 && $avLigne === null) {
                    $error = "Cette Khassida n'est plus au programme du Kurel.";
                } elseif ($titre === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut)) {
                    $error = "Le titre et une date valide sont obligatoires.";
                } elseif ($dateDebut < date('Y-m-d')) {
                    $error = "La date de la répétition ne peut pas être dans le passé.";
                } elseif ($heure === '') {
                    $error = "L'heure est obligatoire.";
                } elseif ($mode === '') {
                    $error = "Le mode (à distance ou présentiel) est obligatoire.";
                } elseif ($mode === 'distance' && $lien === '') {
                    $error = "Le lien Zoom est obligatoire pour une répétition à distance.";
                } elseif ($mode === 'presentiel' && $lieu === '') {
                    $error = "L'adresse est obligatoire pour une répétition en présentiel.";
                } else {
                    $dates = reunion_generer_dates($dateDebut, ($dateFin !== '' ? $dateFin : $dateDebut), $per);
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare("INSERT INTO kourel_repetitions (kourel_id, titre, description, date_reunion, heure, mode, lieu, lien, periodicite, avancement_id, khassida, melodie, publie, cloture, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,0,?)");
                    $createdIds = [];
                    foreach ($dates as $d) {
                        $ins->execute([$kid, $titre, ($desc ?: null), $d, $heure, $mode, ($lieu ?: null), ($lien ?: null), $per, ($avLigne ? (int) $avLigne['id'] : null), ($khassida ?: null), ($melodie ?: null), (!empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : null)]);
                        $createdIds[] = (int) $pdo->lastInsertId();
                    }
                    if (!empty($createdIds)) {
                        $serieId = $createdIds[0];
                        $inPh = implode(',', array_fill(0, count($createdIds), '?'));
                        $pdo->prepare("UPDATE kourel_repetitions SET serie_id = ? WHERE id IN ($inPh)")->execute(array_merge([$serieId], $createdIds));
                    }
                    $pdo->commit();
                    $success = count($dates) > 1 ? (count($dates) . " répétitions créées (série périodique).") : "Répétition créée.";
                }
            } elseif (in_array($action, ['publish_repetition', 'unpublish_repetition', 'close_repetition', 'reopen_repetition', 'delete_repetition', 'delete_repetition_serie', 'edit_repetition', 'repetition_avancement', 'cancel_repetition', 'duplicate_repetition'], true)) {
                $rid = (int) ($_POST['repetition_id'] ?? 0);
                $r = null;
                if ($rid > 0) {
                    $rq = $pdo->prepare("SELECT * FROM kourel_repetitions WHERE id = ?");
                    $rq->execute([$rid]);
                    $r = $rq->fetch();
                }
                if (!$r || !kurel_can_manage((int) $r['kourel_id'], $__isKurelAdmin, $__managedKourels)) {
                    $error = "Répétition introuvable ou accès refusé.";
                } elseif ($action === 'publish_repetition') {
                    if (repetition_precedente_non_cloturee($pdo, $r)) {
                        $error = "Impossible de publier : clôturez d'abord la répétition précédente de la série.";
                    } else {
                        $pdo->prepare("UPDATE kourel_repetitions SET publie = 1, updated_at = NOW() WHERE id = ?")->execute([$rid]);
                        $r['publie'] = 1;
                        $n = repetition_notifier_membres($pdo, $r);
                        $success = "Répétition publiée. $n membre(s) du Kurel notifié(s).";
                    }
                } elseif ($action === 'unpublish_repetition') {
                    $pdo->prepare("UPDATE kourel_repetitions SET publie = 0, updated_at = NOW() WHERE id = ?")->execute([$rid]);
                    $success = "Répétition dépubliée (masquée aux membres).";
                } elseif ($action === 'close_repetition') {
                    $pdo->prepare("UPDATE kourel_repetitions SET cloture = 1, updated_at = NOW() WHERE id = ?")->execute([$rid]);
                    $success = "Répétition clôturée.";
                    if (!empty($_POST['publish_next'])) {
                        $next = repetition_prochaine_a_publier($pdo, $r);
                        if ($next) {
                            $pdo->prepare("UPDATE kourel_repetitions SET publie = 1, updated_at = NOW() WHERE id = ?")->execute([(int) $next['id']]);
                            $next['publie'] = 1;
                            $nn = repetition_notifier_membres($pdo, $next);
                            $success = "Répétition clôturée et période suivante publiée ($nn membre(s) notifié(s)).";
                        }
                    }
                } elseif ($action === 'repetition_avancement') {
                    // Le rattachement se corrige à tout moment : une répétition
                    // déjà publiée, ou clôturée, peut avoir été rangée sous la
                    // mauvaise Khassida. Les libellés suivent la ligne choisie.
                    $avId = (int) ($_POST['avancement_id'] ?? 0);
                    $ligne = $avId > 0 ? kourel_avancement_du_kourel($pdo, (int) $r['kourel_id'], $avId) : null;
                    if ($avId > 0 && $ligne === null) {
                        $error = "Cette Khassida n'est plus au programme du Kurel.";
                    } else {
                        $pdo->prepare("UPDATE kourel_repetitions SET avancement_id = ?, khassida = ?, melodie = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([
                                $ligne ? (int) $ligne['id'] : null,
                                $ligne ? (string) $ligne['khassida'] : null,
                                ($ligne && trim((string) $ligne['melodie']) !== '') ? trim((string) $ligne['melodie']) : null,
                                $rid,
                            ]);
                        $success = $ligne
                            ? 'Répétition rattachée à « ' . $ligne['khassida'] . ' ».'
                            : 'Répétition détachée du programme.';
                    }
                } elseif ($action === 'edit_repetition') {
                    if ((int) $r['publie'] === 1) {
                        $error = "Dépubliez la répétition avant de la modifier.";
                    } else {
                        $eTitre = trim($_POST['titre'] ?? '');
                        $eDesc = trim($_POST['description'] ?? '');
                        $eDate = trim($_POST['date_reunion'] ?? '');
                        $eHeure = trim($_POST['heure'] ?? '');
                        $eModeRaw = trim($_POST['mode'] ?? '');
                        $eMode = ($eModeRaw === 'presentiel') ? 'presentiel' : (($eModeRaw === 'distance') ? 'distance' : '');
                        $eLieu = trim($_POST['lieu'] ?? '');
                        $eLien = trim($_POST['lien'] ?? '');
                        if ($eTitre === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eDate)) {
                            $error = "Le titre et une date valide sont obligatoires.";
                        } elseif ($eDate < date('Y-m-d')) {
                            $error = "La date ne peut pas être dans le passé.";
                        } elseif ($eHeure === '') {
                            $error = "L'heure est obligatoire.";
                        } elseif ($eMode === '') {
                            $error = "Le mode est obligatoire.";
                        } elseif ($eMode === 'distance' && $eLien === '') {
                            $error = "Le lien Zoom est obligatoire pour une répétition à distance.";
                        } elseif ($eMode === 'presentiel' && $eLieu === '') {
                            $error = "L'adresse est obligatoire pour une répétition en présentiel.";
                        } else {
                            // La Khassida travaillée n'est pas ici : elle se change
                            // sur la répétition, même publiée (repetition_avancement).
                            $pdo->prepare("UPDATE kourel_repetitions SET titre = ?, description = ?, date_reunion = ?, heure = ?, mode = ?, lieu = ?, lien = ?, updated_at = NOW() WHERE id = ?")
                                ->execute([$eTitre, ($eDesc ?: null), $eDate, $eHeure, $eMode, ($eLieu ?: null), ($eLien ?: null), $rid]);
                            $success = "Répétition modifiée.";
                        }
                    }
                } elseif ($action === 'reopen_repetition') {
                    $pdo->prepare("UPDATE kourel_repetitions SET cloture = 0, updated_at = NOW() WHERE id = ?")->execute([$rid]);
                    $success = "Répétition rouverte.";
                } elseif ($action === 'cancel_repetition') {
                    $pdo->prepare("UPDATE kourel_repetitions SET annule = 1, updated_at = NOW() WHERE id = ?")->execute([$rid]);
                    $r['annule'] = 1;
                    $n = repetition_notifier_annulation($pdo, $r);
                    $success = "Répétition annulée. $n membre(s) du Kurel notifié(s).";
                } elseif ($action === 'duplicate_repetition') {
                    $ins = $pdo->prepare("INSERT INTO kourel_repetitions (kourel_id, titre, description, date_reunion, heure, mode, lieu, lien, periodicite, khassida, melodie, publie, cloture, annule, created_by) VALUES (?,?,?,?,?,?,?,?, 'ponctuelle', ?, ?, 0, 0, 0, ?)");
                    $ins->execute([(int) $r['kourel_id'], (string) $r['titre'], ($r['description'] ?? null), (string) $r['date_reunion'], (string) $r['heure'], (string) $r['mode'], ($r['lieu'] ?? null), ($r['lien'] ?? null), ($r['khassida'] ?? null), ($r['melodie'] ?? null), (!empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : null)]);
                    $newId = (int) $pdo->lastInsertId();
                    $pdo->prepare("UPDATE kourel_repetitions SET serie_id = ? WHERE id = ?")->execute([$newId, $newId]);
                    $success = "Répétition dupliquée (nouveau brouillon en attente de publication).";
                } elseif ($action === 'delete_repetition') {
                    $pdo->prepare("DELETE FROM kourel_repetitions WHERE id = ?")->execute([$rid]);
                    $pdo->prepare("DELETE FROM presence_validations WHERE planning_type = 'repetition' AND planning_id = ?")->execute([$rid]);
                    $success = "Répétition supprimée.";
                } elseif ($action === 'delete_repetition_serie') {
                    $serie = (int) ($r['serie_id'] ?: $r['id']);
                    $idq = $pdo->prepare("SELECT id FROM kourel_repetitions WHERE serie_id = ?");
                    $idq->execute([$serie]);
                    $ids = array_map('intval', $idq->fetchAll(PDO::FETCH_COLUMN));
                    if (empty($ids)) { $ids = [$rid]; }
                    $inPh = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM kourel_repetitions WHERE id IN ($inPh)")->execute($ids);
                    $pdo->prepare("DELETE FROM presence_validations WHERE planning_type = 'repetition' AND planning_id IN ($inPh)")->execute($ids);
                    $success = count($ids) . " répétition(s) de la série supprimée(s).";
                }
            } elseif ($action === 'avancement_ajouter' && $kid > 0 && kurel_can_manage($kid, $__isKurelAdmin, $__managedKourels)) {
                $err = kourel_avancement_ajouter(
                    $pdo, $kid, (int) ($_POST['annee'] ?? 0),
                    (string) ($_POST['khassida'] ?? ''), (string) ($_POST['melodie'] ?? ''),
                    (string) ($_POST['statut'] ?? '')
                );
                if ($err !== '') { $error = $err; }
                else { $success = "Khassida ajoutée au programme du Kurel."; }
            } elseif ($action === 'avancement_statut' && $kid > 0 && kurel_can_manage($kid, $__isKurelAdmin, $__managedKourels)) {
                $aid = (int) ($_POST['avancement_id'] ?? 0);
                $st = (string) ($_POST['statut'] ?? '');
                if ($aid <= 0 || !kourel_avancement_statut($pdo, $aid, $kid, $st)) {
                    $error = "L'avancement n'a pas pu être enregistré.";
                } else {
                    $success = "Avancement : " . kourel_statut_libelle($st) . ".";
                }
            } elseif ($action === 'avancement_supprimer' && $kid > 0 && kurel_can_manage($kid, $__isKurelAdmin, $__managedKourels)) {
                $aid = (int) ($_POST['avancement_id'] ?? 0);
                if ($aid <= 0 || !kourel_avancement_supprimer($pdo, $aid, $kid)) {
                    $error = "La Khassida n'a pas pu être retirée du programme.";
                } else {
                    $success = "Khassida retirée du programme.";
                }
            } elseif ($action === 'message_membre' && $kid > 0 && $mid > 0 && kurel_can_manage($kid, $__isKurelAdmin, $__managedKourels)) {
                $envoi = kourel_message_membre(
                    $pdo, $kid, $mid,
                    (string) ($_POST['message'] ?? ''),
                    (int) ($_POST['avancement_id'] ?? 0),
                    (string) ($_POST['niveau'] ?? '')
                );
                if ($envoi['erreur'] !== '') { $error = $envoi['erreur']; }
                else { $success = $envoi['message']; }
            } else {
                $error = "Action non autorisée.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Touba Lyon kourel_dashboard: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => empty($error), 'error' => $error, 'success' => $success]);
        exit;
    }
}

try {
    if ($__isKurelAdmin) {
        $kourels = $pdo->query("SELECT id, nom, description FROM kourels ORDER BY nom ASC")->fetchAll();
    } elseif (!empty($__managedKourels)) {
        $in = implode(',', array_fill(0, count($__managedKourels), '?'));
        $stmt = $pdo->prepare("SELECT id, nom, description FROM kourels WHERE id IN ($in) ORDER BY nom ASC");
        $stmt->execute($__managedKourels);
        $kourels = $stmt->fetchAll();
    } else {
        $kourels = [];
    }

    $allMembers = $pdo->query("SELECT id, prenom, nom, photo_path FROM membres WHERE status = 'approved' ORDER BY nom ASC, prenom ASC")->fetchAll();

    $kMembers = [];
    $kManagers = [];
    $rows = $pdo->query("SELECT km.kourel_id, m.id, m.prenom, m.nom, m.photo_path
                         FROM kourel_membres km JOIN membres m ON m.id = km.membre_id
                         ORDER BY m.nom ASC, m.prenom ASC");
    foreach ($rows as $r) { $kMembers[$r['kourel_id']][] = $r; }
    $rows2 = $pdo->query("SELECT kg.kourel_id, m.id, m.prenom, m.nom, m.photo_path
                          FROM kourel_gestionnaires kg JOIN membres m ON m.id = kg.membre_id
                          ORDER BY m.nom ASC, m.prenom ASC");
    foreach ($rows2 as $r) { $kManagers[$r['kourel_id']][] = $r; }

    // Répétitions par Kurel
    $kRepetitions = [];
    try {
        $rr = $pdo->query("SELECT * FROM kourel_repetitions ORDER BY date_reunion DESC, id DESC");
        foreach ($rr as $r) { $kRepetitions[(int) $r['kourel_id']][] = $r; }
    } catch (Exception $e) { $kRepetitions = []; }
    $repHeureDefaut = dahira_param($pdo, 'guddi_heure', '20h00');
    $repZoomLien = dahira_param($pdo, 'guddi_lien_defaut', '');
    $repLieuDefaut = dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
    // Listes partagées : Khassidas et types de mélodie
    try { $khassidasList = $pdo->query("SELECT id, nom FROM kourel_khassidas ORDER BY nom ASC")->fetchAll(); } catch (Exception $e) { $khassidasList = []; }
    try { $melodiesList = $pdo->query("SELECT id, nom FROM kourel_melodies ORDER BY nom ASC")->fetchAll(); } catch (Exception $e) { $melodiesList = []; }
} catch (Exception $e) {
    error_log('Touba Lyon kourel_dashboard (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

function kourel_member_avatar($m)
{
    $ini = strtoupper(mb_substr($m['prenom'] ?? '', 0, 1) . mb_substr($m['nom'] ?? '', 0, 1));
    if (!empty($m['photo_path'])) {
        return '<img src="uploads/' . htmlspecialchars($m['photo_path'], ENT_QUOTES) . '" alt="" class="km-avatar">';
    }
    return '<span class="km-avatar km-avatar--ph">' . htmlspecialchars($ini !== '' ? $ini : '?') . '</span>';
}

// Rendu HTML d'une répétition (carte). $showTitle=false pour une période au sein d'une série.
$renderRepetitionItem = function ($r, $showTitle = true) use ($pdo) {
    $rPub = ((int) $r['publie'] === 1);
    $rClo = ((int) $r['cloture'] === 1);
    $rid = (int) $r['id'];
    $rDateFr = date('d/m/Y', strtotime($r['date_reunion']));
    $csrf = csrf_field();
    $rAnn = ((int) ($r['annule'] ?? 0) === 1);
    if ($rAnn) {
        $knom = repetition_kourel_nom($pdo, (int) $r['kourel_id']);
        $waLink = 'https://wa.me/?text=' . rawurlencode(repetition_message_annulation($r, $knom));
        $h = '<div style="border:1px solid rgba(191,33,33,0.4); border-radius:10px; padding:0.7rem 0.9rem; background:rgba(191,33,33,0.06);">';
        $h .= '<div style="display:flex; justify-content:space-between; gap:0.5rem; flex-wrap:wrap; align-items:center;">';
        $h .= '<div style="color:#fff; font-weight:700;">🎼 ' . htmlspecialchars($r['titre']) . '</div>';
        $h .= '<span class="badge" style="background:rgba(191,33,33,0.18); color:#fca5a5; border:1px solid rgba(191,33,33,0.45);">🚫 Annulée</span>';
        $h .= '</div>';
        $h .= '<div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.3rem;">🗓️ ' . $rDateFr . ' · 🕐 ' . htmlspecialchars($r['heure']) . '</div>';
        $h .= '<div class="km-actions" style="border-top:0; padding-top:0.6rem; margin-top:0.4rem;">';
        $h .= '<a href="' . htmlspecialchars($waLink, ENT_QUOTES) . '" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 WhatsApp annulation</a>';
        $h .= '<form method="POST" style="margin:0;" data-confirm="Dupliquer cette répétition en un nouveau brouillon ?">' . $csrf . '<input type="hidden" name="action" value="duplicate_repetition"><input type="hidden" name="repetition_id" value="' . $rid . '"><button type="submit" class="btn btn-secondary btn-sm">📄 Dupliquer</button></form>';
        $h .= '<form method="POST" style="margin:0;" data-confirm="Supprimer définitivement cette répétition ?" data-confirm-danger="1">' . $csrf . '<input type="hidden" name="action" value="delete_repetition"><input type="hidden" name="repetition_id" value="' . $rid . '"><button type="submit" class="btn btn-danger btn-sm">Supprimer</button></form>';
        $h .= '</div></div>';
        return $h;
    }
    $estSerie = ($r['periodicite'] ?? 'ponctuelle') !== 'ponctuelle';
    $canPublish = $estSerie ? !repetition_precedente_non_cloturee($pdo, $r) : true;
    $nextToPublish = $estSerie ? repetition_prochaine_a_publier($pdo, $r) : null;
    $h = '<div style="border:1px solid var(--glass-border); border-radius:10px; padding:0.7rem 0.9rem; background:rgba(255,255,255,0.03);">';
    $h .= '<div style="display:flex; justify-content:space-between; gap:0.5rem; flex-wrap:wrap; align-items:center;">';
    $h .= '<div style="color:#fff; font-weight:700;">' . ($showTitle ? '🎼 ' . htmlspecialchars($r['titre']) : '🗓️ ' . $rDateFr) . '</div>';
    $h .= '<div style="display:flex; gap:0.3rem; flex-wrap:wrap;">';
    if ($rClo) { $h .= '<span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-muted);">Clôturée</span>'; }
    elseif ($rPub) { $h .= '<span class="badge badge-approved">🚀 Publiée</span>'; }
    else { $h .= '<span class="badge badge-pending">⏳ En attente</span>'; }
    $h .= '</div></div>';
    $h .= '<div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.3rem;">🗓️ ' . $rDateFr . ' · 🕐 ' . htmlspecialchars($r['heure']) . ' · ' . ((string) $r['mode'] === 'presentiel' ? '🏛️ présentiel' : '💻 à distance') . '</div>';
    // La Khassida travaillée : choisie dans le programme du Kurel, et
    // modifiable même après publication — c'est un rangement, pas un
    // changement de programme de la séance.
    $programme = kourel_avancements_plats($pdo, (int) $r['kourel_id']);
    $curAv = (int) ($r['avancement_id'] ?? 0);
    $khTexte = trim((string) ($r['khassida'] ?? ''));
    if (!empty($programme)) {
        $optsAv = '<option value="">— Aucune Khassida —</option>';
        foreach ($programme as $av) {
            $optsAv .= '<option value="' . (int) $av['id'] . '"' . ($curAv === (int) $av['id'] ? ' selected' : '') . '>'
                . htmlspecialchars(kourel_avancement_libelle($av, true)) . '</option>';
        }
        $h .= '<form method="POST" style="margin:0.4rem 0 0; display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">' . $csrf
            . '<input type="hidden" name="action" value="repetition_avancement">'
            . '<input type="hidden" name="repetition_id" value="' . $rid . '">'
            . '<span style="color:var(--gold); font-size:0.8rem;">📜 Khassida travaillée</span>'
            . '<select name="avancement_id" class="krep-av" onchange="envoyerChangement(this)">' . $optsAv . '</select>';
        // Une Khassida retirée du programme laisse son nom sur la répétition
        if ($curAv === 0 && $khTexte !== '') {
            $h .= '<span style="color:var(--text-muted); font-size:0.76rem;">(anciennement 📜 ' . htmlspecialchars($khTexte) . ')</span>';
        }
        $h .= '</form>';
    } elseif ($khTexte !== '') {
        $melTexte = trim((string) ($r['melodie'] ?? ''));
        $h .= '<div style="color:var(--gold); font-size:0.82rem; margin-top:0.2rem;">📜 ' . htmlspecialchars($khTexte)
            . ($melTexte !== '' ? ' · 🎶 ' . htmlspecialchars($melTexte) : '') . '</div>';
    }
    $h .= '<div class="km-actions" style="border-top:0; padding-top:0.6rem; margin-top:0.4rem;">';
    // Partage WhatsApp uniquement si la répétition est publiée
    if ($rPub) {
        $waShare = 'https://wa.me/?text=' . rawurlencode(repetition_message_partage($r, repetition_kourel_nom($pdo, (int) $r['kourel_id']), dahira_base_url() . '/repetition_detail.php?id=' . $rid));
        $h .= '<a href="' . htmlspecialchars($waShare, ENT_QUOTES) . '" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 WhatsApp</a>';
    }
    // Publier / Dépublier juste après WhatsApp
    if (!$rClo) {
        if (!$rPub) {
            if ($canPublish) {
                $h .= '<form method="POST" style="margin:0;" data-confirm="Publier cette répétition et notifier les membres du Kurel ?">' . $csrf . '<input type="hidden" name="action" value="publish_repetition"><input type="hidden" name="repetition_id" value="' . $rid . '"><button type="submit" class="btn btn-primary btn-sm">🚀 Publier</button></form>';
            } else {
                $h .= '<span class="btn btn-sm" style="background:rgba(255,255,255,0.06); color:rgba(255,255,255,0.4); cursor:not-allowed;" title="Clôturez d\'abord la répétition précédente de la série">🚀 Publier</span>';
            }
        } else {
            $h .= '<form method="POST" style="margin:0;" data-confirm="Dépublier cette répétition ? Elle sera masquée aux membres." data-confirm-danger="1">' . $csrf . '<input type="hidden" name="action" value="unpublish_repetition"><input type="hidden" name="repetition_id" value="' . $rid . '"><button type="submit" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">⏳ Dépublier</button></form>';
        }
    }
    $h .= '<a href="repetition_detail.php?id=' . $rid . '" class="btn btn-secondary btn-sm">📊 Détail</a>';
    if (!$rPub && !$rClo) {
        $h .= '<button type="button" class="btn btn-secondary btn-sm" style="border-color:#7dd3fc; color:#7dd3fc;" onclick="toggleRepEdit(' . $rid . ')">✏️ Modifier</button>';
    }
    if (!$rClo) {
        $h .= '<form method="POST" style="margin:0;" data-confirm="Clôturer cette répétition ?">' . $csrf . '<input type="hidden" name="action" value="close_repetition"><input type="hidden" name="repetition_id" value="' . $rid . '"><input type="hidden" name="publish_next" value="0"><button type="submit" class="btn btn-secondary btn-sm">Clôturer</button></form>';
        if ($nextToPublish) {
            $nextDateFr = date('d/m/Y', strtotime($nextToPublish['date_reunion']));
            $h .= '<form method="POST" style="margin:0;" data-confirm="Clôturer cette répétition et publier la suivante (' . $nextDateFr . ') en notifiant les membres ?">' . $csrf . '<input type="hidden" name="action" value="close_repetition"><input type="hidden" name="repetition_id" value="' . $rid . '"><input type="hidden" name="publish_next" value="1"><button type="submit" class="btn btn-primary btn-sm" style="background:rgba(125,211,252,0.15); border:1px solid rgba(125,211,252,0.5); color:#7dd3fc;">Clôturer + publier la suivante</button></form>';
        }
        $waAnn = 'https://wa.me/?text=' . rawurlencode(repetition_message_annulation($r, repetition_kourel_nom($pdo, (int) $r['kourel_id'])));
        $h .= '<form id="cancel-rep-' . $rid . '" method="POST" style="margin:0;">' . $csrf . '<input type="hidden" name="action" value="cancel_repetition"><input type="hidden" name="repetition_id" value="' . $rid . '"><button type="button" class="btn btn-danger btn-sm" onclick="annulerRepetition(' . $rid . ', ' . htmlspecialchars(json_encode($waAnn), ENT_QUOTES) . ')">🚫 Annuler</button></form>';
    } else {
        $h .= '<form method="POST" style="margin:0;">' . $csrf . '<input type="hidden" name="action" value="reopen_repetition"><input type="hidden" name="repetition_id" value="' . $rid . '"><button type="submit" class="btn btn-secondary btn-sm">Rouvrir</button></form>';
    }
    $h .= '<form method="POST" style="margin:0;" data-confirm="Supprimer définitivement cette répétition ?" data-confirm-danger="1">' . $csrf . '<input type="hidden" name="action" value="delete_repetition"><input type="hidden" name="repetition_id" value="' . $rid . '"><button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger); border-color:var(--danger);">🗑️ Supprimer</button></form>';
    $h .= '</div>'; // fin actions
    if (!$rPub && !$rClo) {
        $mv = (string) $r['mode'];
        $optDist = $mv === 'distance' ? ' selected' : '';
        $optPres = $mv === 'presentiel' ? ' selected' : '';
        $h .= '<div id="rep-edit-' . $rid . '" style="display:none; margin-top:0.5rem;">';
        $h .= '<form method="POST" class="repetition-create-form" style="display:flex; flex-wrap:wrap; --fx-min:150px; gap:0.6rem;">' . $csrf;
        $h .= '<input type="hidden" name="action" value="edit_repetition"><input type="hidden" name="repetition_id" value="' . $rid . '">';
        $h .= '<div class="form-group" style="flex: 1 1 100%; margin:0;"><label class="form-label">Titre</label><input type="text" name="titre" class="form-input" required value="' . htmlspecialchars((string) $r['titre'], ENT_QUOTES) . '"></div>';
        $h .= '<div class="form-group" style="flex: 1 1 100%; margin:0;"><label class="form-label">Ordre du jour</label><textarea name="description" class="form-input" rows="2">' . htmlspecialchars((string) ($r['description'] ?? '')) . '</textarea></div>';
        $h .= '<div class="form-group" style="margin:0;"><label class="form-label">Date</label><input type="date" name="date_reunion" class="form-input" required min="' . date('Y-m-d') . '" value="' . htmlspecialchars((string) $r['date_reunion'], ENT_QUOTES) . '" style="width:100%; max-width:100%; box-sizing:border-box;"></div>';
        $curH = dahira_heure_hm((string) $r['heure']);
        $optsH = '<option value="">— Heure —</option>';
        for ($hh = 7; $hh <= 23; $hh++) { foreach (['00', '30'] as $mm) { $hv = sprintf('%02d:%s', $hh, $mm); $optsH .= '<option value="' . $hv . '"' . ($curH === $hv ? ' selected' : '') . '>' . $hv . '</option>'; } }
        $h .= '<div class="form-group" style="margin:0;"><label class="form-label">Heure</label><select name="heure" class="form-input modern-select" required>' . $optsH . '</select></div>';
        $h .= '<div class="form-group" style="margin:0;"><label class="form-label">Mode</label><select name="mode" class="form-input modern-select r-mode" required><option value="distance"' . $optDist . '>💻 À distance (Zoom)</option><option value="presentiel"' . $optPres . '>🏛️ Présentiel</option></select></div>';
        $h .= '<div class="form-group r-lien" style="flex: 1 1 100%; margin:0; display:none;"><label class="form-label">🔗 Lien Zoom</label><input type="url" name="lien" class="form-input r-lien-input" value="' . htmlspecialchars((string) ($r['lien'] ?? ''), ENT_QUOTES) . '"></div>';
        $h .= '<div class="form-group r-lieu" style="flex: 1 1 100%; margin:0; display:none;"><label class="form-label">📍 Adresse</label><input type="text" name="lieu" class="form-input r-lieu-input" value="' . htmlspecialchars((string) ($r['lieu'] ?? ''), ENT_QUOTES) . '"></div>';
        $h .= '<div style="flex: 1 1 100%;"><button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer</button></div>';
        $h .= '</form></div>';
    }
    $h .= '</div>'; // fin carte
    return $h;
};

// Données JS
$idsOf = function ($arr) { return array_map(function ($r) { return (int) $r['id']; }, $arr); };
$kMembersMap = [];
foreach ($kMembers as $kid => $arr) { $kMembersMap[$kid] = $idsOf($arr); }
$kManagersMap = [];
foreach ($kManagers as $kid => $arr) { $kManagersMap[$kid] = $idsOf($arr); }
$membersJs = array_map(function ($m) {
    $ini = strtoupper(mb_substr($m['prenom'] ?? '', 0, 1) . mb_substr($m['nom'] ?? '', 0, 1));
    return [
        'id' => (int) $m['id'],
        'name' => trim($m['prenom'] . ' ' . $m['nom']),
        'photo' => $m['photo_path'] ?? '',
        'ini' => ($ini !== '' ? $ini : '?'),
    ];
}, $allMembers);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace Kurels - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .kourel-wrap { max-width: 960px; margin: 2rem auto; }
        .kourel-card { margin-bottom: 1.25rem; border-radius: 12px; padding: 1.25rem 1.5rem; }
        .kourel-card-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .kourel-card-head h3 { margin: 0; color: var(--white); font-size: 1.2rem; }
        .kourel-card-head .k-desc { color: var(--text-muted); font-size: 0.85rem; margin: 0.2rem 0 0; }
        .com-sub { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gold); font-weight: 700; margin: 0.5rem 0 0.5rem; }
        .km-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem; }
        .km-chip { display: inline-flex; align-items: center; gap: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 12px; padding: 0.25rem 0.7rem 0.25rem 0.3rem; }
        .km-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .km-avatar--ph { background: rgba(212,175,55,0.15); color: var(--gold); font-size: 0.72rem; font-weight: 700; border: 1px solid rgba(212,175,55,0.35); }
        .km-chip .km-name { font-size: 0.85rem; color: var(--white); font-weight: 600; }
        .km-empty { color: var(--text-muted); font-size: 0.86rem; font-style: italic; margin-bottom: 0.75rem; }
        .km-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; border-top: 1px solid var(--glass-border); padding-top: 1rem; margin-top: 0.5rem; }
        @media (max-width: 600px) {
            .km-actions { flex-direction: column; align-items: stretch; }
            .km-actions > *, .km-actions form { width: 100%; }
            .km-actions .btn, .km-actions span.btn { width: 100%; justify-content: center; text-align: center; }
        }
        .com-filters { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
        .com-filters input { flex: 1 1 240px; border-radius: 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); font-size: 0.9rem; padding: 0.6rem 1rem; }
        .com-filters input:focus { outline: none; border-color: var(--accent); }
        .com-dd { flex: 1 1 200px; border-radius: 8px; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); font-size: 0.9rem; padding: 0.6rem 0.9rem; color-scheme: dark; }
        .com-dd option { background-color: #0c241a; color: #fff; }
        .com-dd:focus { outline: none; border-color: var(--accent); }
        .com-count { font-size: 0.82rem; color: var(--text-muted); margin-left: auto; }
        .com-reu-group summary::-webkit-details-marker { display: none; }
        .com-reu-group[open] .com-reu-chevron { transform: rotate(90deg); }
        @media (max-width: 600px) { .com-filters input, .com-dd { flex: 1 1 100%; } }
        /* ── Sélecteur à deux listes ── */
        #members-modal { position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; padding: 1rem; }
        #members-modal.active { display: flex; }
        .tl-card { width: 100%; max-width: 820px; max-height: 90vh; display: flex; flex-direction: column; background: linear-gradient(180deg,#123528,#0c241a); border: 1px solid rgba(212,175,55,0.25); border-radius: 12px; overflow: hidden; box-shadow: 0 30px 80px rgba(0,0,0,0.55); }
        .tl-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; background: linear-gradient(135deg,#1b4332,#2d6a4f); flex-shrink: 0; }
        .tl-head h3 { margin: 0; color: #fff; font-size: 1.05rem; }
        .tl-x { background: rgba(255,255,255,0.15); color: #fff; border: 0; width: 28px; height: 28px; border-radius: 50%; font-size: 1.1rem; line-height: 1; cursor: pointer; flex-shrink: 0; }
        .tl-cols { display: flex; flex: 1; min-height: 0; }
        .tl-col { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .tl-col + .tl-col { border-left: 1px solid var(--glass-border); }
        .tl-col-head { padding: 0.75rem 1rem 0.5rem; flex-shrink: 0; }
        .tl-col-head .tl-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gold); font-weight: 700; }
        .tl-col-head input { width: 100%; margin-top: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 12px; color: #fff; font-size: 0.85rem; padding: 0.45rem 0.9rem; }
        .tl-col-head input:focus { outline: none; border-color: var(--accent); }
        .tl-list { flex: 1; overflow-y: auto; padding: 0.5rem 0.75rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
        .tl-item { display: flex; align-items: center; gap: 0.6rem; padding: 0.4rem 0.5rem; border: 1px solid var(--glass-border); border-radius: 10px; background: rgba(255,255,255,0.03); }
        .tl-av { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .tl-av--ph { background: rgba(212,175,55,0.15); color: var(--gold); font-size: 0.72rem; font-weight: 700; border: 1px solid rgba(212,175,55,0.35); }
        .tl-name { flex: 1; color: #fff; font-size: 0.88rem; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tl-item button { flex-shrink: 0; border: 0; border-radius: 8px; width: 30px; height: 30px; font-size: 1.1rem; line-height: 1; cursor: pointer; }
        .tl-add-btn { background: rgba(212,175,55,0.2); color: var(--gold); }
        .tl-add-btn:hover { background: rgba(212,175,55,0.35); }
        .tl-rem-btn { background: rgba(255,80,80,0.18); color: #ff8a8a; }
        .tl-rem-btn:hover { background: rgba(255,80,80,0.32); }
        .tl-empty { color: var(--text-muted); font-size: 0.82rem; font-style: italic; padding: 0.75rem 0.5rem; text-align: center; }
        .tl-foot { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.85rem 1.25rem; border-top: 1px solid var(--glass-border); flex-wrap: wrap; flex-shrink: 0; }
        .tl-count { font-size: 0.8rem; color: var(--text-muted); }
        @media (max-width: 600px) { .tl-cols { flex-direction: column; } .tl-col + .tl-col { border-left: 0; border-top: 1px solid var(--glass-border); } .tl-list { max-height: 30vh; } }
        /* ── Avancement des Khassidas ── */
        .kav-ligne { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.55rem 0.75rem; }
        .kav-nom { flex: 1 1 11rem; min-width: 0; }
        .kav-nom strong { color: var(--white); font-size: 0.88rem; }
        .kav-melodie { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.1rem; }
        .kav-statut { border-radius: 12px; border: 1px solid; font-size: 0.78rem; font-weight: 700; padding: 0.3rem 0.6rem; cursor: pointer; color-scheme: dark; }
        .kav-message { flex: 1 1 100%; border-top: 1px dashed var(--glass-border); padding-top: 0.5rem; }
        .kav-message > summary { cursor: pointer; color: var(--gold); font-weight: 700; font-size: 0.8rem; list-style: none; }
        .kav-message > summary::-webkit-details-marker { display: none; }
        /* Réglages du Kurel : un groupe replié, discret */
        .kourel-params { margin-top: 0.5rem; }
        /* Un cadre autour des formulaires de saisie */
        .kourel-cadre { border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.9rem 1rem; background: rgba(255,255,255,0.02); }
        /* La Khassida travaillée, sur la répétition */
        .krep-av { max-width: 100%; border-radius: 12px; border: 1px solid rgba(212,175,55,0.4); background: rgba(212,175,55,0.10); color: var(--gold); font-size: 0.78rem; font-weight: 600; padding: 0.22rem 0.5rem; cursor: pointer; color-scheme: dark; }
        .krep-av option { background-color: #0c241a; color: #fff; font-weight: 600; }
        .krep-av:focus { outline: none; border-color: var(--accent); }
        .kav-statut option { background-color: #0c241a; color: #fff; font-weight: 600; }
        .kav-statut:focus { outline: none; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">

        <div class="kourel-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:1rem;">
                <span>🎵 Espace Kurels — <strong class="gold-text"><?php echo count($kourels); ?></strong> Kurel(s)</span>
                <?php if ($__isAdmin): ?>
                    <?php if (empty($_SESSION['admin_is_member'])): ?><a href="admin_dashboard.php" class="btn btn-secondary btn-sm">← Tableau de bord</a><?php endif; ?>
                <?php else: ?>
                    <a href="trombinoscope.php" class="btn btn-secondary btn-sm">← Trombinoscope</a>
                <?php endif; ?>
            </div>

            <?php if (!empty($__isKurelAdmin)) { echo fr_bloc_html($pdo, 'kurels', 'Kurels', 'kourel_dashboard.php'); } ?>

            <!-- Accès rapide aux deux listes de référence, qui vivent en bas de page -->
            <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-bottom:1.5rem;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="ouvrirListeReference('liste-khassidas')">
                    📜 Khassidas <span data-compteur="khassidas" style="color:var(--text-muted);">(<?php echo count($khassidasList); ?>)</span>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="ouvrirListeReference('liste-melodies')">
                    🎶 Types de mélodie <span data-compteur="melodies" style="color:var(--text-muted);">(<?php echo count($melodiesList); ?>)</span>
                </button>
            </div>

            <?php if (!$__isKurelAdmin): ?>
            <div class="form-card" style="max-width:none; margin-bottom:1.5rem; padding:1.25rem 1.5rem;">
                <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">
                    Vous êtes <strong>responsable</strong> des Kurels ci-dessous. Vous pouvez y ajouter ou retirer des membres.
                </p>
            </div>
            <?php endif; ?>

            <?php if (empty($kourels)): ?>
                <div class="empty-state"><div class="empty-state-icon">🎵</div><p>Aucun Kurel<?php echo $__isKurelAdmin ? ' pour le moment. Créez-en un ci-dessous.' : ' à gérer pour le moment.'; ?></p></div>
            <?php else: ?>
                <div class="com-filters">
                    <input type="text" id="com-search" placeholder="🔍 Rechercher un Kurel…">
                    <select id="com-select" class="com-dd">
                        <option value="">Tous les Kurels</option>
                        <?php foreach ($kourels as $k): ?>
                            <option value="<?php echo htmlspecialchars(mb_strtolower($k['nom']), ENT_QUOTES); ?>"><?php echo htmlspecialchars($k['nom']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="com-count" id="com-count"></span>
                </div>
                <?php foreach ($kourels as $k): ?>
                    <?php
                        $membersOfK = $kMembers[$k['id']] ?? [];
                        $managersOfK = $kManagers[$k['id']] ?? [];
                    ?>
                    <div class="glass-card kourel-card com-filterable" id="com-card-<?php echo (int) $k['id']; ?>" data-search="<?php echo htmlspecialchars(mb_strtolower($k['nom']), ENT_QUOTES); ?>">
                        <div class="kourel-card-head">
                            <div>
                                <h3>🎵 <?php echo htmlspecialchars($k['nom']); ?>
                                    <span class="badge badge-approved com-count-badge" style="margin-left:0.4rem;"><?php echo count($membersOfK); ?> membre(s)</span>
                                </h3>
                                <?php if (!empty($k['description'])): ?><p class="k-desc"><?php echo htmlspecialchars($k['description']); ?></p><?php endif; ?>
                            </div>
                        </div>

                        <?php // Réglages du Kurel : consultés de loin en loin, donc repliés ?>
                        <details class="kourel-params">
                            <summary>⚙️ Paramètres du Kurel</summary>
                        <div class="com-sub">Responsable(s) du Kurel</div>
                        <div class="km-list com-managers-list" id="com-managers-<?php echo (int) $k['id']; ?>">
                            <?php if (empty($managersOfK)): ?>
                                <p class="km-empty">Aucun responsable désigné.</p>
                            <?php else: foreach ($managersOfK as $mm): ?>
                                <span class="km-chip"><?php echo kourel_member_avatar($mm); ?><span class="km-name">🎖️ <?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></span></span>
                            <?php endforeach; endif; ?>
                        </div>

                        <div class="com-sub">Membres du Kurel</div>
                        <div class="km-list com-members-list" id="com-members-<?php echo (int) $k['id']; ?>">
                            <?php if (empty($membersOfK)): ?>
                                <p class="km-empty">Aucun membre dans ce Kurel.</p>
                            <?php else: foreach ($membersOfK as $mm): ?>
                                <span class="km-chip"><?php echo kourel_member_avatar($mm); ?><span class="km-name"><?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></span></span>
                            <?php endforeach; endif; ?>
                        </div>

                        <div class="km-actions">
                            <button type="button" class="btn btn-primary btn-sm" onclick="openComModal('members', <?php echo (int) $k['id']; ?>, <?php echo htmlspecialchars(json_encode($k['nom']), ENT_QUOTES); ?>)">👥 Gérer les membres</button>
                            <?php if ($__isKurelAdmin): ?>
                            <button type="button" class="btn btn-secondary btn-sm" style="border-color:var(--gold); color:var(--gold);" onclick="openComModal('managers', <?php echo (int) $k['id']; ?>, <?php echo htmlspecialchars(json_encode($k['nom']), ENT_QUOTES); ?>)">🎖️ Gérer les responsables</button>
                            <form action="kourel_dashboard.php" method="POST" id="kdel-form-<?php echo (int) $k['id']; ?>" style="margin:0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_kourel">
                                <input type="hidden" name="kourel_id" value="<?php echo (int) $k['id']; ?>">
                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteKurel(<?php echo (int) $k['id']; ?>, <?php echo htmlspecialchars(json_encode($k['nom']), ENT_QUOTES); ?>)">🗑️ Supprimer le Kurel</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        </details>

                        <?php
                            $repsAll = $kRepetitions[$k['id']] ?? [];
                            $reps = array_values(array_filter($repsAll, function ($x) { return (int) ($x['annule'] ?? 0) !== 1; }));
                            $repsAnn = array_values(array_filter($repsAll, function ($x) { return (int) ($x['annule'] ?? 0) === 1; }));
                        ?>
                        <div data-zone="reps-<?php echo (int) $k['id']; ?>" data-zone-liee="avancement-<?php echo (int) $k['id']; ?>" style="border-top:1px solid var(--glass-border); padding-top:1rem; margin-top:1rem;">
                            <div class="com-sub" style="margin-top:0;">🎵 Répétitions du Kurel</div>
                            <?php if (empty($reps)): ?>
                                <p class="km-empty">Aucune répétition pour le moment.</p>
                            <?php else: ?>
                                <?php
                                    $groupes = [];
                                    foreach ($reps as $r) { $gk = (int) ($r['serie_id'] ?: $r['id']); $groupes[$gk][] = $r; }
                                ?>
                                <div style="display:flex; flex-direction:column; gap:0.75rem; margin-bottom:0.75rem;">
                                <?php foreach ($groupes as $grp): ?>
                                    <?php if (count($grp) <= 1): ?>
                                        <?php echo $renderRepetitionItem($grp[0], true); ?>
                                    <?php else: ?>
                                        <?php
                                            $periodes = $grp;
                                            usort($periodes, function ($a, $b) { return strcmp($a['date_reunion'], $b['date_reunion']); });
                                            $first = $periodes[0];
                                            $dmin = date('d/m/Y', strtotime($periodes[0]['date_reunion']));
                                            $dmax = date('d/m/Y', strtotime($periodes[count($periodes) - 1]['date_reunion']));
                                            $idxProchain = null;
                                            foreach ($periodes as $i => $p) { if ((int) $p['cloture'] !== 1) { $idxProchain = $i; break; } }
                                            if ($idxProchain === null) { $idxProchain = count($periodes) - 1; }
                                            $prochain = $periodes[$idxProchain];
                                            $autres = [];
                                            foreach ($periodes as $i => $p) { if ($i !== $idxProchain) { $autres[] = $p; } }
                                        ?>
                                        <div style="border:1px solid rgba(212,175,55,0.35); border-radius:10px; padding:0.8rem 0.9rem; background:rgba(212,175,55,0.05);">
                                            <div style="display:flex; justify-content:space-between; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                                                <div style="color:var(--gold); font-weight:800;">🔁 <?php echo htmlspecialchars($first['titre']); ?></div>
                                                <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                                                    <span class="badge" style="background:rgba(212,175,55,0.15); color:var(--gold); border:1px solid rgba(212,175,55,0.4);"><?php echo count($periodes); ?> répétitions · <?php echo htmlspecialchars(reunion_periodicite_label($first['periodicite'])); ?></span>
                                                    <form method="POST" style="margin:0;" data-confirm="Supprimer TOUTE la série « <?php echo htmlspecialchars(addslashes($first['titre']), ENT_QUOTES); ?> » (<?php echo count($periodes); ?> répétitions) et tout leur suivi ? Action irréversible." data-confirm-danger="1">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete_repetition_serie">
                                                        <input type="hidden" name="repetition_id" value="<?php echo (int) $first['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">🗑️ Supprimer la série</button>
                                                    </form>
                                                </div>
                                            </div>
                                            <div style="color:var(--text-muted); font-size:0.8rem; margin:0.3rem 0 0.6rem;">🗓️ du <?php echo $dmin; ?> au <?php echo $dmax; ?></div>
                                            <div style="color:var(--text-muted); font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.35rem;">⏭️ Prochaine répétition</div>
                                            <?php echo $renderRepetitionItem($prochain, false); ?>
                                            <?php if (!empty($autres)): ?>
                                            <details class="com-reu-group" style="margin-top:0.6rem;">
                                                <summary style="cursor:pointer; color:var(--gold); font-weight:700; font-size:0.85rem; list-style:none; display:flex; align-items:center; gap:0.4rem;">📂 Voir les <?php echo count($autres); ?> autre(s) répétition(s) <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                                                <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:0.5rem;">
                                                <?php foreach ($autres as $r): ?>
                                                    <?php echo $renderRepetitionItem($r, false); ?>
                                                <?php endforeach; ?>
                                                </div>
                                            </details>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($repsAnn)): ?>
                            <details class="com-reu-group" style="margin-bottom:0.75rem;">
                                <summary style="cursor:pointer; color:#fca5a5; font-weight:700; font-size:0.85rem; list-style:none; display:flex; align-items:center; gap:0.4rem;">🚫 Répétitions annulées <span style="color:var(--text-muted); font-weight:600;">(<?php echo count($repsAnn); ?>)</span> <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                                <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:0.5rem;">
                                    <?php foreach ($repsAnn as $r): ?>
                                        <?php echo $renderRepetitionItem($r, true); ?>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <?php endif; ?>
                            <details style="margin-top:0.5rem;">
                                <summary style="cursor:pointer; color:var(--gold); font-weight:700; font-size:0.9rem;">➕ Créer une répétition</summary>
                                <form method="POST" class="repetition-create-form kourel-cadre" style="margin-top:0.8rem; display:flex; flex-wrap:wrap; --fx-min:160px; gap:0.7rem;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="create_repetition">
                                    <input type="hidden" name="kourel_id" value="<?php echo (int) $k['id']; ?>">
                                    <div class="form-group" style="flex: 1 1 100%; margin:0;"><label class="form-label">Titre <span style="color:var(--danger)">*</span></label><input type="text" name="titre" class="form-input" required placeholder="Ex: Répétition hebdomadaire du Kurel"></div>
                                    <div class="form-group" style="flex: 1 1 100%; margin:0;"><label class="form-label">Ordre du jour</label><textarea name="description" class="form-input" rows="2"></textarea></div>
                                    <div class="form-group" style="margin:0;"><label class="form-label">Date <span style="color:var(--danger)">*</span></label><input type="date" name="date_debut" class="form-input" required min="<?php echo date('Y-m-d'); ?>" style="width:100%; max-width:100%; box-sizing:border-box;"></div>
                                    <div class="form-group" style="margin:0;"><label class="form-label">Heure <span style="color:var(--danger)">*</span></label><select name="heure" class="form-input modern-select" required><option value="">— Heure —</option><?php for ($hh = 7; $hh <= 23; $hh++): foreach (['00', '30'] as $mm): $hv = sprintf('%02d:%s', $hh, $mm); ?><option value="<?php echo $hv; ?>"><?php echo $hv; ?></option><?php endforeach; endfor; ?></select></div>
                                    <div class="form-group" style="margin:0;"><label class="form-label">Mode <span style="color:var(--danger)">*</span></label><select name="mode" class="form-input modern-select r-mode" required><option value="distance">💻 À distance (Zoom)</option><option value="presentiel" selected>🏛️ Présentiel</option></select></div>
                                    <?php // La Khassida travaillée vient du programme du Kurel : la mélodie suit. ?>
                                    <div class="form-group" style="flex: 1 1 100%; margin:0; min-width:0;">
                                        <label class="form-label">📜 Khassida travaillée <span style="color:var(--text-muted); font-size:0.8rem;">(facultatif)</span></label>
                                        <select name="avancement_id" class="form-input modern-select">
                                            <option value="">— Aucune —</option>
                                            <?php foreach (kourel_avancements_plats($pdo, (int) $k['id']) as $av): ?>
                                                <option value="<?php echo (int) $av['id']; ?>"><?php echo htmlspecialchars(kourel_avancement_libelle($av, true)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (empty(kourel_avancements_plats($pdo, (int) $k['id']))): ?>
                                            <p style="color:var(--text-muted); font-size:0.75rem; margin:0.3rem 0 0;">Inscrivez d'abord une Khassida au programme, ci-dessous.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-group r-lien" style="flex: 1 1 100%; margin:0; display:none;"><label class="form-label">🔗 Lien Zoom (répétition à distance) <span style="color:var(--danger)">*</span></label><input type="url" name="lien" class="form-input r-lien-input" value="<?php echo htmlspecialchars($repZoomLien); ?>" placeholder="https://us06web.zoom.us/..."><p style="color:var(--text-muted); font-size:0.75rem; margin:0.3rem 0 0;">Pré-rempli avec le lien du Guddi Àjjuma — modifiable.</p></div>
                                    <div class="form-group r-lieu" style="flex: 1 1 100%; margin:0; display:none;"><label class="form-label">📍 Adresse (présentiel) <span style="color:var(--danger)">*</span></label><input type="text" name="lieu" class="form-input r-lieu-input" value="<?php echo htmlspecialchars($repLieuDefaut); ?>"><p style="color:var(--text-muted); font-size:0.75rem; margin:0.3rem 0 0;">Pré-rempli avec l'adresse du Dahira — modifiable.</p></div>
                                    <div class="form-group" style="flex: 1 1 100%; margin:0;">
                                        <label class="form-label" style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;"><input type="checkbox" name="periodique" value="1" class="r-periodique"> 🔁 Répétition périodique</label>
                                    </div>
                                    <div class="form-group r-per-fields" style="margin:0; display:none;"><label class="form-label">Périodicité</label><select name="periodicite" class="form-input modern-select"><option value="hebdomadaire">Hebdomadaire</option><option value="quinzaine">Une semaine sur deux</option><option value="mensuelle">Mensuelle</option></select></div>
                                    <div class="form-group r-per-fields" style="margin:0; display:none;"><label class="form-label">Date de fin</label><input type="date" name="date_fin" class="form-input" min="<?php echo date('Y-m-d'); ?>" style="width:100%; max-width:100%; box-sizing:border-box;"></div>
                                    <div style="flex: 1 1 100%;"><button type="submit" class="btn btn-primary btn-sm">💾 Créer la répétition</button></div>
                                </form>
                            </details>
                        </div>

                        <?php
                            // Le tableau de marche du Kurel : quelques Khassidas par
                            // année, chacune avec sa mélodie et son avancement.
                            $avancements = kourel_avancements($pdo, (int) $k['id']);
                            $statuts = kourel_statuts();
                            // Combien de répétitions travaillent chaque Khassida.
                            $repsParAv = kourel_repetitions_par_avancement($pdo, (int) $k['id']);
                        ?>
                        <div data-zone="avancement-<?php echo (int) $k['id']; ?>" data-zone-liee="reps-<?php echo (int) $k['id']; ?>" style="border-top:1px solid var(--glass-border); padding-top:1rem; margin-top:1rem;">
                            <div class="com-sub" style="margin-top:0;">📈 Avancement des Khassidas</div>
                            <?php if (empty($avancements)): ?>
                                <p class="km-empty">Aucune Khassida au programme pour le moment.</p>
                            <?php else: foreach ($avancements as $annee => $lignesAnnee): ?>
                                <div style="margin-bottom:0.9rem;">
                                    <div style="color:var(--gold); font-weight:700; font-size:0.82rem; margin-bottom:0.4rem;">📅 <?php echo (int) $annee; ?></div>
                                    <div style="display:flex; flex-direction:column; gap:0.45rem;">
                                        <?php foreach ($lignesAnnee as $av): $etat = $statuts[kourel_statut_valide($av['statut'])]; ?>
                                            <div class="kav-ligne">
                                                <div class="kav-nom">
                                                    <strong>📜 <?php echo htmlspecialchars($av['khassida']); ?></strong>
                                                    <div class="kav-melodie">
                                                        🎶 <?php echo htmlspecialchars(trim((string) $av['melodie']) !== '' ? $av['melodie'] : 'mélodie non précisée'); ?>
                                                        <?php $nbReps = (int) ($repsParAv[(int) $av['id']] ?? 0); ?>
                                                        <?php if ($nbReps > 0): ?>
                                                            · 🎵 <?php echo $nbReps; ?> répétition<?php echo $nbReps > 1 ? 's' : ''; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <form method="POST" style="margin:0;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="avancement_statut">
                                                    <input type="hidden" name="kourel_id" value="<?php echo (int) $k['id']; ?>">
                                                    <input type="hidden" name="avancement_id" value="<?php echo (int) $av['id']; ?>">
                                                    <select name="statut" class="kav-statut" onchange="envoyerChangement(this)"
                                                            style="border-color:<?php echo $etat['couleur']; ?>; background:<?php echo $etat['fond']; ?>; color:<?php echo $etat['couleur']; ?>;">
                                                        <?php foreach ($statuts as $cle => $s): ?>
                                                            <option value="<?php echo $cle; ?>"<?php echo kourel_statut_valide($av['statut']) === $cle ? ' selected' : ''; ?>><?php echo $s['icone'] . ' ' . $s['libelle']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </form>
                                                <form method="POST" style="margin:0;"
                                                      data-confirm="Retirer « <?php echo htmlspecialchars(addslashes($av['khassida']), ENT_QUOTES); ?> » du programme <?php echo (int) $annee; ?>&nbsp;?"
                                                      data-confirm-danger="1">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="avancement_supprimer">
                                                    <input type="hidden" name="kourel_id" value="<?php echo (int) $k['id']; ?>">
                                                    <input type="hidden" name="avancement_id" value="<?php echo (int) $av['id']; ?>">
                                                    <button type="submit" title="Retirer du programme" style="background:none; border:0; color:#ff8a8a; cursor:pointer; font-size:1rem; line-height:1;">🗑️</button>
                                                </form>

                                                <?php // Le mot à un membre part de la Khassida travaillée en ce moment ?>
                                                <?php if (kourel_statut_valide($av['statut']) === 'en_cours' && !empty($membersOfK)): ?>
                                                    <details class="kav-message">
                                                        <summary>✉️ Écrire à un membre</summary>
                                                        <p style="color:var(--text-muted); font-size:0.78rem; margin:0.6rem 0 0.5rem; line-height:1.5;">
                                                            Le mot porte sur cette Khassida et sur le niveau atteint par le membre :
                                                            il reçoit une notification dans l'application, et un e-mail s'il a une adresse.
                                                        </p>
                                                        <form method="POST" style="display:flex; flex-wrap:wrap; --fx-min:min(100%, 11rem); gap:0.6rem;"
                                                              data-confirm="Envoyer ce message au membre choisi, par notification et par e-mail&nbsp;?">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="action" value="message_membre">
                                                            <input type="hidden" name="kourel_id" value="<?php echo (int) $k['id']; ?>">
                                                            <input type="hidden" name="avancement_id" value="<?php echo (int) $av['id']; ?>">
                                                            <div class="form-group" style="margin:0; min-width:0;"><label class="form-label">👤 Membre <span style="color:var(--danger)">*</span></label><select name="membre_id" class="form-input modern-select" required><?php foreach ($membersOfK as $mm): ?><option value="<?php echo (int) $mm['id']; ?>"><?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></option><?php endforeach; ?></select></div>
                                                            <div class="form-group" style="margin:0; min-width:0;"><label class="form-label">📈 Niveau du membre</label><select name="niveau" class="form-input modern-select"><option value="">— Sans niveau —</option><?php foreach (kourel_niveaux() as $cle => $lib): ?><option value="<?php echo $cle; ?>"><?php echo $lib; ?></option><?php endforeach; ?></select></div>
                                                            <div class="form-group" style="flex: 1 1 100%; margin:0;"><label class="form-label">Message <span style="color:var(--danger)">*</span></label><textarea name="message" class="form-input" rows="3" required placeholder="Ex : Assalamu aleykum, continue à travailler le début de la Khassida, tu y es presque."></textarea></div>
                                                            <div style="flex: 1 1 100%;"><button type="submit" class="btn btn-primary btn-sm">📨 Envoyer le message</button></div>
                                                        </form>
                                                    </details>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>

                            <details>
                                <summary style="cursor:pointer; color:var(--gold); font-weight:700; font-size:0.9rem;">➕ Ajouter une Khassida au programme</summary>
                                <form method="POST" class="kourel-cadre" style="margin-top:0.8rem; display:flex; flex-wrap:wrap; --fx-min:150px; gap:0.7rem;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="avancement_ajouter">
                                    <input type="hidden" name="kourel_id" value="<?php echo (int) $k['id']; ?>">
                                    <div class="form-group" style="margin:0; min-width:0;"><label class="form-label">📜 Khassida <span style="color:var(--danger)">*</span></label><select name="khassida" class="form-input modern-select" required><?php foreach ($khassidasList as $kx): ?><option value="<?php echo htmlspecialchars($kx['nom'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($kx['nom']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group" style="margin:0; min-width:0;"><label class="form-label">🎶 Mélodie</label><select name="melodie" class="form-input modern-select"><option value="">— Aucune —</option><?php foreach ($melodiesList as $ml): ?><option value="<?php echo htmlspecialchars($ml['nom'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($ml['nom']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group" style="margin:0; min-width:0;"><label class="form-label">📅 Année</label><select name="annee" class="form-input modern-select"><?php foreach (kourel_annees() as $an): ?><option value="<?php echo (int) $an; ?>"<?php echo $an === (int) date('Y') ? ' selected' : ''; ?>><?php echo (int) $an; ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group" style="margin:0; min-width:0;"><label class="form-label">Avancement</label><select name="statut" class="form-input modern-select"><?php foreach ($statuts as $cle => $s): ?><option value="<?php echo $cle; ?>"><?php echo $s['icone'] . ' ' . $s['libelle']; ?></option><?php endforeach; ?></select></div>
                                    <div style="flex: 1 1 100%;"><button type="submit" class="btn btn-primary btn-sm">💾 Ajouter au programme</button></div>
                                </form>
                            </details>
                        </div>

                    </div>
                <?php endforeach; ?>
                <div class="empty-state" id="com-noresult" style="display:none;"><div class="empty-state-icon">🔍</div><p>Aucun Kurel ne correspond à votre recherche.</p></div>
            <?php endif; ?>

            <?php if ($__isKurelAdmin): ?>
            <!-- Créer un Kurel (en bas de la liste) -->
            <div class="form-card" style="max-width:none; margin-top:1.5rem; padding:1.5rem;">
                <h3 class="gold-text" style="font-size:1.15rem; font-weight:700; margin:0 0 1rem;">➕ Créer un Kurel</h3>
                <form action="kourel_dashboard.php" method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_kourel">
                    <div class="form-group">
                        <label class="form-label">Nom du Kurel <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="nom" class="form-input" placeholder="Ex : Kurel Hizbut, Kurel des jeunes…" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description (facultatif)</label>
                        <input type="text" name="description" class="form-input" placeholder="Quelques mots sur ce Kurel">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Créer le Kurel</button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Listes de référence, en bas de page : on les consulte rarement.
                 Les deux boutons du haut y mènent et les ouvrent. -->
            <details id="liste-khassidas" data-zone="khassidas" class="glass-card com-reu-group" style="margin-bottom:1rem; padding:1rem 1.25rem; border-radius:12px;">
                <summary style="cursor:pointer; color:var(--gold); font-weight:800; font-size:1rem; list-style:none; display:flex; align-items:center; gap:0.5rem;">📜 Khassidas <span data-compteur="khassidas-titre" style="color:var(--text-muted); font-weight:600;">(<?php echo count($khassidasList); ?>)</span> <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.9rem;">
                    <?php if (empty($khassidasList)): ?><p class="km-empty">Aucune Khassida pour le moment.</p><?php else: foreach ($khassidasList as $kx): ?>
                        <span class="km-chip"><span class="km-name">📜 <?php echo htmlspecialchars($kx['nom']); ?></span>
                            <form method="POST" style="margin:0; display:inline;" data-confirm="Supprimer « <?php echo htmlspecialchars(addslashes($kx['nom']), ENT_QUOTES); ?> » de la liste ?" data-confirm-danger="1">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_khassida"><input type="hidden" name="item_id" value="<?php echo (int) $kx['id']; ?>">
                                <button type="submit" title="Supprimer" style="background:none; border:0; color:#ff8a8a; cursor:pointer; font-size:1rem; line-height:1;">&times;</button>
                            </form>
                        </span>
                    <?php endforeach; endif; ?>
                </div>
                <form method="POST" style="margin-top:0.9rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="add_khassida">
                    <input type="text" name="nom" class="form-input" placeholder="Ajouter une Khassida…" style="flex:1 1 220px;" required>
                    <button type="submit" class="btn btn-primary btn-sm">➕ Ajouter</button>
                </form>
            </details>

            <details id="liste-melodies" data-zone="melodies" class="glass-card com-reu-group" style="margin-bottom:1.5rem; padding:1rem 1.25rem; border-radius:12px;">
                <summary style="cursor:pointer; color:var(--gold); font-weight:800; font-size:1rem; list-style:none; display:flex; align-items:center; gap:0.5rem;">🎶 Types de mélodie <span data-compteur="melodies-titre" style="color:var(--text-muted); font-weight:600;">(<?php echo count($melodiesList); ?>)</span> <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.9rem;">
                    <?php if (empty($melodiesList)): ?><p class="km-empty">Aucune mélodie pour le moment.</p><?php else: foreach ($melodiesList as $ml): ?>
                        <span class="km-chip"><span class="km-name">🎶 <?php echo htmlspecialchars($ml['nom']); ?></span>
                            <form method="POST" style="margin:0; display:inline;" data-confirm="Supprimer « <?php echo htmlspecialchars(addslashes($ml['nom']), ENT_QUOTES); ?> » de la liste ?" data-confirm-danger="1">
                                <?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_melodie"><input type="hidden" name="item_id" value="<?php echo (int) $ml['id']; ?>">
                                <button type="submit" title="Supprimer" style="background:none; border:0; color:#ff8a8a; cursor:pointer; font-size:1rem; line-height:1;">&times;</button>
                            </form>
                        </span>
                    <?php endforeach; endif; ?>
                </div>
                <form method="POST" style="margin-top:0.9rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                    <?php echo csrf_field(); ?><input type="hidden" name="action" value="add_melodie">
                    <input type="text" name="nom" class="form-input" placeholder="Ajouter un type de mélodie…" style="flex:1 1 220px;" required>
                    <button type="submit" class="btn btn-primary btn-sm">➕ Ajouter</button>
                </form>
            </details>

        </div>

            </div>
        </div>
    </main>

    <!-- Sélecteur à deux listes : membres ou responsables d'un Kurel -->
    <div id="members-modal">
        <div class="tl-card">
            <div class="tl-head">
                <h3 id="tl-modal-title">Membres</h3>
                <button type="button" class="tl-x" onclick="closeComModal()" aria-label="Fermer">&times;</button>
            </div>
            <div class="tl-cols">
                <div class="tl-col">
                    <div class="tl-col-head">
                        <div class="tl-title">Membres disponibles</div>
                        <input type="text" id="tl-search" placeholder="🔍 Rechercher un membre…">
                    </div>
                    <div class="tl-list" id="tl-available"></div>
                </div>
                <div class="tl-col">
                    <div class="tl-col-head">
                        <div class="tl-title" id="tl-right-title">Sélectionnés (<span id="tl-sel-count">0</span>)</div>
                    </div>
                    <div class="tl-list" id="tl-selected"></div>
                </div>
            </div>
            <div class="tl-foot">
                <span class="tl-count" id="tl-avail-count"></span>
                <button type="button" class="btn btn-primary btn-sm" onclick="closeComModal()">Terminé</button>
            </div>
        </div>
    </div>

    <form id="tl-form" style="display:none;"><?php echo csrf_field(); ?></form>

    <script>
        // ---------------------------------------------------------------
        // Actions sans rechargement : seule la zone concernée est refaite
        // ---------------------------------------------------------------
        // Un message qui passe, plutôt qu'une fenêtre à refermer : l'action
        // vient d'être confirmée, la personne sait ce qu'elle a demandé.
        function messageDiscret(texte, estErreur) {
            var t = document.getElementById('zone-message');
            if (!t) {
                t = document.createElement('div');
                t.id = 'zone-message';
                t.style.cssText = 'position:fixed; left:50%; bottom:1.25rem; transform:translateX(-50%);'
                    + ' z-index:4000; max-width:min(92vw, 30rem); padding:0.7rem 1.1rem; border-radius:10px;'
                    + ' font-size:0.88rem; line-height:1.45; text-align:center; box-shadow:0 8px 24px rgba(0,0,0,0.35);'
                    + ' opacity:0; transition:opacity 0.2s;';
                document.body.appendChild(t);
            }
            t.style.background = estErreur ? 'rgba(120, 22, 22, 0.96)' : 'rgba(18, 46, 34, 0.96)';
            t.style.border = '1px solid ' + (estErreur ? 'rgba(255, 138, 138, 0.55)' : 'rgba(212, 175, 55, 0.5)');
            t.style.color = estErreur ? '#ffd7d7' : '#f4dd8c';
            t.textContent = texte;
            t.style.opacity = '1';
            clearTimeout(t._minuteur);
            t._minuteur = setTimeout(function () { t.style.opacity = '0'; }, 4200);
        }

        // Les sections ouvertes doivent le rester : on note leur état avant
        // l'échange, on le repose après — sauf si la structure a changé.
        function etatsDesSections(zone) {
            return Array.prototype.map.call(zone.querySelectorAll('details'), function (d) { return d.open; });
        }
        function reposerEtatsDesSections(zone, etats) {
            var ds = zone.querySelectorAll('details');
            if (ds.length !== etats.length) { return; }
            for (var i = 0; i < ds.length; i++) { ds[i].open = etats[i]; }
        }

        // Remet une zone en place à partir de la page fraîchement lue.
        function appliquerZone(zone, doc) {
            var frais = doc.querySelector('[data-zone="' + zone.getAttribute('data-zone') + '"]');
            if (!frais) { return false; }
            var etats = etatsDesSections(zone);
            zone.innerHTML = frais.innerHTML;
            reposerEtatsDesSections(zone, etats);
            initFormulairesRepetition(zone);
            return true;
        }

        // Redemande la page au serveur, mais n'en garde que la zone visée — et
        // celles qui en dépendent : marquer une Khassida « en cours » change la
        // liste proposée pour écrire à un membre.
        function rafraichirZone(zone) {
            return fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    if (!appliquerZone(zone, doc)) { window.location.reload(); return; }
                    var liees = (zone.getAttribute('data-zone-liee') || '').split(/\s+/);
                    liees.forEach(function (cle) {
                        if (!cle) { return; }
                        var autre = document.querySelector('[data-zone="' + cle + '"]');
                        if (autre) { appliquerZone(autre, doc); }
                    });
                    // Les compteurs vivent hors des zones : ils les suivent.
                    doc.querySelectorAll('[data-compteur]').forEach(function (src) {
                        var cible = document.querySelector('[data-compteur="' + src.getAttribute('data-compteur') + '"]');
                        if (cible) { cible.textContent = src.textContent; }
                    });
                });
        }
        // Ce que la personne avait écrit, pour le retrouver après un échec.
        function texteSaisi(form) {
            var garde = {};
            form.querySelectorAll('textarea, input[type=text], input[type=url]').forEach(function (c) {
                if (c.name && c.value !== '') { garde[c.name] = c.value; }
            });
            return garde;
        }
        function reposerTexteSaisi(zone, garde) {
            Object.keys(garde).forEach(function (nom) {
                var c = zone.querySelector('[name="' + nom + '"]');
                if (c && c.value === '') { c.value = garde[nom]; }
            });
        }

        // Envoie le formulaire en arrière-plan, puis refait sa zone.
        function envoyerFormulaireZone(form, zone) {
            var corps = new URLSearchParams(new FormData(form));
            corps.append('ajax', '1');
            var boutons = form.querySelectorAll('button, input[type=submit]');
            boutons.forEach(function (b) { b.disabled = true; });
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: corps.toString(),
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) { throw new Error(j.error || 'Erreur'); }
                    return rafraichirZone(zone).then(function () {
                        messageDiscret(j.success || 'Enregistré.', false);
                    });
                })
                .catch(function (e) {
                    // L'échec vient souvent d'une page qui ne montrait plus l'état
                    // réel : on la remet à jour, en reposant ce qui était écrit.
                    var saisie = texteSaisi(form);
                    messageDiscret(e.message || "L'action n'a pas abouti.", true);
                    rafraichirZone(zone)
                        .then(function () { reposerTexteSaisi(zone, saisie); })
                        .catch(function () { boutons.forEach(function (b) { b.disabled = false; }); });
                });
        }

        // Interception à la capture, donc avant l'écouteur du popup partagé :
        // la confirmation est demandée ici, et le formulaire ne part jamais en
        // rechargement de page.
        document.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (!form || form.tagName !== 'FORM') { return; }
            var zone = form.closest('[data-zone]');
            if (!zone) { return; }
            // On prend la main dans tous les cas : laisser filer l'événement
            // rendrait la main au popup partagé, qui recharge la page.
            ev.preventDefault();
            ev.stopPropagation();
            if (form.reportValidity && !form.reportValidity()) { return; }
            var msg = form.getAttribute('data-confirm');
            if (!msg) {
                envoyerFormulaireZone(form, zone);
                return;
            }
            var suite = function () { envoyerFormulaireZone(form, zone); };
            if (form.getAttribute('data-confirm-danger') === '1') {
                window.modernDanger(msg, suite);
            } else {
                window.modernConfirm(msg, suite);
            }
        }, true);

        // Un choix dans une liste déroulante vaut validation : on passe par un
        // vrai événement de soumission, seul chemin que l'interception connaît
        // (form.submit() rechargerait la page).
        function envoyerChangement(champ) {
            var f = champ.form;
            if (!f) { return; }
            if (f.requestSubmit) { f.requestSubmit(); }
            else { f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); }
        }

        // Les listes de référence sont en bas de page et repliées : le raccourci
        // les déplie avant d'y descendre, sinon on arrive sur un simple titre.
        function ouvrirListeReference(id) {
            var d = document.getElementById(id);
            if (!d) { return; }
            d.open = true;
            var doux = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var avant = window.scrollY;
            d.scrollIntoView(doux ? { behavior: 'smooth', block: 'center' } : { block: 'center' });
            // Le défilement animé n'aboutit pas partout (onglet en arrière-plan,
            // animations bridées) : si rien n'a bougé, on y va d'un coup.
            if (doux) {
                setTimeout(function () {
                    if (Math.abs(window.scrollY - avant) < 4) { d.scrollIntoView({ block: 'center' }); }
                }, 400);
            }
        }

        var TL_ALL = <?php echo json_encode($membersJs, JSON_UNESCAPED_UNICODE); ?>;
        var COM_MEMBERS = <?php echo json_encode((object) $kMembersMap, JSON_UNESCAPED_UNICODE); ?>;
        var COM_MANAGERS = <?php echo json_encode((object) $kManagersMap, JSON_UNESCAPED_UNICODE); ?>;
        var tlCid = null, tlMode = 'members';
        var tlSelected = new Set();
        var TL_CSRF = (document.querySelector('#tl-form input[name=csrf_token]') || {}).value || '';
        var TL_BYID = {};
        TL_ALL.forEach(function (m) { TL_BYID[m.id] = m; });
        function getMap(mode){ return (mode === 'managers') ? COM_MANAGERS : COM_MEMBERS; }
        function tlNorm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
        function tlEsc(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
        function tlAvatar(m){ return m.photo ? '<img class="tl-av" src="uploads/' + encodeURIComponent(m.photo) + '" alt="">' : '<span class="tl-av tl-av--ph">' + tlEsc(m.ini) + '</span>'; }
        function comChip(m, mode){
            var av = m.photo ? '<img class="km-avatar" src="uploads/' + encodeURIComponent(m.photo) + '" alt="">' : '<span class="km-avatar km-avatar--ph">' + tlEsc(m.ini) + '</span>';
            var pre = (mode === 'managers') ? '🎖️ ' : '';
            return '<span class="km-chip">' + av + '<span class="km-name">' + pre + tlEsc(m.name) + '</span></span>';
        }
        function updateCard(cid, mode){
            var el = document.getElementById((mode === 'managers' ? 'com-managers-' : 'com-members-') + cid);
            if (!el) return;
            var ids = (getMap(mode)[cid] || []).map(Number);
            if (!ids.length) {
                el.innerHTML = '<p class="km-empty">' + (mode === 'managers' ? 'Aucun responsable désigné.' : 'Aucun membre dans ce Kurel.') + '</p>';
            } else {
                el.innerHTML = ids.map(function (id) { var m = TL_BYID[id]; return m ? comChip(m, mode) : ''; }).join('');
            }
            if (mode === 'members') {
                var badge = document.querySelector('#com-card-' + cid + ' .com-count-badge');
                if (badge) badge.textContent = ids.length + ' membre(s)';
            }
        }
        function comPersist(mode, cid, mid, add){
            var action = (mode === 'managers')
                ? (add ? 'add_kourel_manager' : 'remove_kourel_manager')
                : (add ? 'add_kourel_member' : 'remove_kourel_member');
            var params = new URLSearchParams();
            params.append('ajax', '1');
            params.append('action', action);
            params.append('kourel_id', cid);
            params.append('membre_id', mid);
            params.append('csrf_token', TL_CSRF);
            return fetch('kourel_dashboard.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString(),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) { throw new Error(j.error || 'Erreur'); } });
        }
        function tlRender(){
            var term = tlNorm(document.getElementById('tl-search').value);
            var av = document.getElementById('tl-available');
            var se = document.getElementById('tl-selected');
            av.innerHTML = ''; se.innerHTML = '';
            var na = 0, ns = 0;
            TL_ALL.forEach(function (m) {
                if (tlSelected.has(m.id)) {
                    se.insertAdjacentHTML('beforeend', '<div class="tl-item"><span>' + tlAvatar(m) + '</span><span class="tl-name">' + tlEsc(m.name) + '</span><button type="button" class="tl-rem-btn" onclick="tlRemove(' + m.id + ')" title="Retirer">&times;</button></div>');
                    ns++;
                } else {
                    if (term && tlNorm(m.name).indexOf(term) === -1) return;
                    av.insertAdjacentHTML('beforeend', '<div class="tl-item"><span>' + tlAvatar(m) + '</span><span class="tl-name">' + tlEsc(m.name) + '</span><button type="button" class="tl-add-btn" onclick="tlAdd(' + m.id + ')" title="Ajouter">+</button></div>');
                    na++;
                }
            });
            if (!na) av.innerHTML = '<div class="tl-empty">Aucun membre disponible.</div>';
            if (!ns) se.innerHTML = '<div class="tl-empty">Aucun membre sélectionné.</div>';
            document.getElementById('tl-sel-count').textContent = ns;
            document.getElementById('tl-avail-count').textContent = na + ' disponible(s)';
        }
        function tlAdd(id){
            var map = getMap(tlMode);
            if (!map[tlCid]) { map[tlCid] = []; }
            if (map[tlCid].map(Number).indexOf(id) === -1) { map[tlCid].push(id); }
            tlSelected.add(id); tlRender(); updateCard(tlCid, tlMode);
            comPersist(tlMode, tlCid, id, true).catch(function (e) {
                var arr = map[tlCid]; var i = arr.map(Number).indexOf(id); if (i > -1) { arr.splice(i, 1); }
                tlSelected.delete(id); tlRender(); updateCard(tlCid, tlMode);
                modernAlert("Échec de l'ajout : " + e.message, 'Erreur');
            });
        }
        function tlRemove(id){
            var map = getMap(tlMode);
            var arr = map[tlCid] || []; var i = arr.map(Number).indexOf(id); if (i > -1) { arr.splice(i, 1); }
            tlSelected.delete(id); tlRender(); updateCard(tlCid, tlMode);
            comPersist(tlMode, tlCid, id, false).catch(function (e) {
                if (!map[tlCid]) { map[tlCid] = []; }
                if (map[tlCid].map(Number).indexOf(id) === -1) { map[tlCid].push(id); }
                tlSelected.add(id); tlRender(); updateCard(tlCid, tlMode);
                modernAlert("Échec du retrait : " + e.message, 'Erreur');
            });
        }
        function openComModal(mode, cid, name){
            tlMode = mode; tlCid = cid;
            var src = (mode === 'managers') ? COM_MANAGERS : COM_MEMBERS;
            tlSelected = new Set((src[cid] || []).map(Number));
            var label = (mode === 'managers') ? 'Responsables — ' : 'Membres — ';
            document.getElementById('tl-modal-title').textContent = label + name;
            document.getElementById('tl-right-title').innerHTML = (mode === 'managers' ? 'Responsables' : 'Membres') + ' (<span id="tl-sel-count">0</span>)';
            document.getElementById('tl-search').value = '';
            tlRender();
            document.getElementById('members-modal').classList.add('active');
        }
        function closeComModal(){ document.getElementById('members-modal').classList.remove('active'); }
        document.getElementById('tl-search').addEventListener('input', tlRender);
        document.getElementById('members-modal').addEventListener('click', function (e) { if (e.target === this) closeComModal(); });

        // Filtre des Kurels par nom
        (function () {
            var input = document.getElementById('com-search');
            if (!input) return;
            var sel = document.getElementById('com-select');
            var cards = Array.prototype.slice.call(document.querySelectorAll('.com-filterable'));
            var noRes = document.getElementById('com-noresult');
            var countEl = document.getElementById('com-count');
            function norm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
            function apply() {
                var term = norm(input.value);
                var selVal = sel ? sel.value : '';
                var n = 0;
                cards.forEach(function (c) {
                    var ds = c.getAttribute('data-search');
                    var show = (!term || norm(ds).indexOf(term) !== -1) && (!selVal || ds === selVal);
                    c.style.display = show ? '' : 'none';
                    if (show) n++;
                });
                if (noRes) noRes.style.display = n === 0 ? 'block' : 'none';
                if (countEl) countEl.textContent = n + ' Kurel(s)';
            }
            input.addEventListener('input', apply);
            if (sel) sel.addEventListener('change', apply);
            apply();
        })();
    </script>

    <!-- Confirmation de suppression (UI moderne) -->
    <div id="kdel-modal" class="modal-overlay" style="display:none; align-items:center; justify-content:center; z-index:3500;">
        <div class="modal-card glass-card" style="max-width:420px; width:calc(100vw - 28px);">
            <div class="modal-header" style="display:flex; align-items:center; gap:0.6rem;">
                <span style="font-size:1.4rem;">🗑️</span>
                <h3 style="color:var(--danger); margin:0;">Supprimer le Kurel</h3>
            </div>
            <div class="modal-body">
                <p style="line-height:1.55;">Voulez-vous vraiment supprimer le Kurel « <strong id="kdel-name" class="gold-text"></strong> » ?<br>
                <span style="color:var(--text-muted); font-size:0.9rem;">Les membres ne sont pas supprimés, seulement le groupe.</span></p>
            </div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; justify-content:flex-end;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeDelModal()">Annuler</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="doDeleteKurel()">Supprimer définitivement</button>
            </div>
        </div>
    </div>
    <script>
        var kdelId = null;
        function confirmDeleteKurel(id, name){
            kdelId = id;
            document.getElementById('kdel-name').textContent = name;
            var m = document.getElementById('kdel-modal');
            m.style.display = 'flex';
            m.classList.add('active');
        }
        function closeDelModal(){ var m = document.getElementById('kdel-modal'); m.classList.remove('active'); m.style.display = 'none'; kdelId = null; }
        function doDeleteKurel(){ if (kdelId != null) { var f = document.getElementById('kdel-form-' + kdelId); if (f) f.submit(); } }
        document.getElementById('kdel-modal').addEventListener('click', function (e) { if (e.target === this) closeDelModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDelModal(); });
    </script>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($success)): ?><h3 class="gold-text">Opération réussie</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?>
            </div>
            <div class="modal-body"><p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Répétitions : formulaire de modification + affichage dynamique création
        function toggleRepEdit(id) {
            var el = document.getElementById('rep-edit-' + id);
            if (el) { el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none'; }
        }
        // Annulation : confirmation avec option d'ouvrir WhatsApp (message d'annulation)
        function annulerRepetition(id, waLink) {
            var submit = function () { var f = document.getElementById('cancel-rep-' + id); if (f) { f.submit(); } };
            if (window.modernConfirm) {
                window.modernConfirm('Annuler cette répétition et notifier les membres du Kurel ?', submit, {
                    danger: true, bouton: 'Annuler',
                    extra: { label: '🟢 Annuler + WhatsApp', style: 'background:#25D366;border:1px solid #25D366;color:#053b21;font-weight:700;', onOk: function () { try { window.open(waLink, '_blank'); } catch (e) {} submit(); } }
                });
            } else { submit(); }
        }
        // Appelée au chargement, puis sur chaque zone remise en place : les
        // écouteurs d'un formulaire remplacé sont partis avec l'ancien noeud.
        function initFormulairesRepetition(racine) {
            (racine || document).querySelectorAll('.repetition-create-form').forEach(function (f) {
                var per = f.querySelector('.r-periodique');
                var perFields = f.querySelectorAll('.r-per-fields');
                var mode = f.querySelector('.r-mode');
                var lien = f.querySelector('.r-lien');
                var lieu = f.querySelector('.r-lieu');
                var lienInput = f.querySelector('.r-lien-input');
                var lieuInput = f.querySelector('.r-lieu-input');
                function syncPer() { perFields.forEach(function (el) { el.style.display = (per && per.checked) ? '' : 'none'; }); }
                function syncMode() {
                    var v = mode ? mode.value : '';
                    if (lien) { lien.style.display = (v === 'distance') ? '' : 'none'; }
                    if (lieu) { lieu.style.display = (v === 'presentiel') ? '' : 'none'; }
                    if (lienInput) { lienInput.required = (v === 'distance'); }
                    if (lieuInput) { lieuInput.required = (v === 'presentiel'); }
                }
                if (per) { per.addEventListener('change', syncPer); }
                if (mode) { mode.addEventListener('change', syncMode); }
                syncPer(); syncMode();
            });
        }
        initFormulairesRepetition(document);
    </script>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
<script src="modern-date.js" defer></script>
</body>
</html>
