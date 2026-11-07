<?php
/**
 * Touba Lyon 2026 - Espaces commissions.
 *
 * Chaque commission a des MEMBRES et des RESPONSABLES (rôle de gestion par commission).
 * - Un administrateur voit toutes les commissions et peut gérer membres + responsables.
 * - Un membre RESPONSABLE d'une (ou plusieurs) commission(s) ne voit que les siennes
 *   et peut gérer leurs MEMBRES (pas les responsables).
 */
require_once __DIR__ . '/commission_guard.php'; // $__isAdmin, $__managedCommissions, $pdo
$embed = !empty($_GET['embed']); // affichage intégré dans « Mes commissions » (iframe)
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/magal_helper.php';        // magal_annee_defaut() (liste des années à partir de 2027)
require_once __DIR__ . '/sous_commission_helper.php'; // un membre = une seule sous-commission par commission
require_once __DIR__ . '/dahira_emails.php';       // send_smtp_mail(), dahira_base_url(), dahira_email_button()
require_once __DIR__ . '/notification_helper.php'; // cloche + Web Push
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_param()
require_once __DIR__ . '/reunion_helper.php';       // réunions de commission (périodicité, notifications)

$error = '';
$success = '';

/** Le membre courant peut-il gérer cette commission ? */
function com_can_manage($cid, $isAdmin, $managed)
{
    return $isAdmin || in_array((int) $cid, $managed, true);
}

/** Retire des sous-commissions les membres qui ne font plus partie de la commission parente. */
function com_clean_sous_membres(PDO $pdo, int $cid): void
{
    try {
        $pdo->prepare("DELETE scm FROM sous_commission_membres scm JOIN sous_commissions sc ON sc.id = scm.sous_commission_id
                       WHERE sc.commission_id = ? AND scm.support = 0 AND scm.membre_id NOT IN (SELECT membre_id FROM commission_membres WHERE commission_id = ?)")
            ->execute([$cid, $cid]);
    } catch (Exception $e) { /* best-effort */ }
}

/**
 * Ajoute des membres à une sous-commission : ceux qui font partie de la commission
 * y entrent comme membres, les autres comme « membres supports » (hors commission).
 * Renvoie ['membres' => n, 'supports' => n] (membres effectivement ajoutés).
 */
function com_ajouter_a_sous_commission(PDO $pdo, int $cid, int $scId, array $ids, string $scNom): array
{
    $res = ['membres' => 0, 'supports' => 0];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; })));
    if ($scId <= 0 || empty($ids)) { return $res; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ok = $pdo->prepare("SELECT id FROM membres WHERE status = 'approved' AND id IN ($ph)");
    $ok->execute($ids);
    $valides = array_map('intval', $ok->fetchAll(PDO::FETCH_COLUMN));
    $inCom = $pdo->prepare("SELECT membre_id FROM commission_membres WHERE commission_id = ? UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?");
    $inCom->execute([$cid, $cid]);
    $dansCom = array_map('intval', $inCom->fetchAll(PDO::FETCH_COLUMN));
    $ins = $pdo->prepare("INSERT IGNORE INTO sous_commission_membres (sous_commission_id, membre_id, support) VALUES (?, ?, ?)");
    $cnom = com_commission_nom($pdo, $cid);
    foreach ($valides as $mid) {
        $support = in_array($mid, $dansCom, true) ? 0 : 1;
        if ($support === 0 && sc_autre_sous_commission($pdo, $cid, $scId, $mid) !== null) { continue; } // déjà dans une autre sous-commission
        $ins->execute([$scId, $mid, $support]);
        if ($ins->rowCount() > 0) {
            if ($support === 1) {
                $res['supports']++;
                troba_notify_membre($pdo, $mid, 'commission', '🤝 Membre support', 'Vous aidez la sous-commission « ' . $scNom . ' » de la commission ' . $cnom . ' en tant que membre support.', 'mes_commissions.php');
            } else {
                $res['membres']++;
                troba_notify_membre($pdo, $mid, 'commission', '🧩 Sous-commission', 'Vous avez été affecté(e) à la sous-commission « ' . $scNom . ' » de la commission ' . $cnom . '.', 'mes_commissions.php');
            }
        }
    }
    return $res;
}
/** Nom d'une commission (pour les notifications / e-mails). */
function com_commission_nom(PDO $pdo, int $cid): string
{
    try {
        $s = $pdo->prepare("SELECT nom FROM commissions WHERE id = ?");
        $s->execute([$cid]);
        $n = $s->fetchColumn();
        return $n !== false ? (string) $n : '';
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Notifie un membre (cloche + e-mail) de son ajout à une commission, ou de sa
 * nomination comme responsable. Best-effort, jamais bloquant.
 */
function com_notify_membre_ajout(PDO $pdo, int $mid, string $commissionNom, bool $estResponsable): void
{
    if ($mid <= 0) { return; }
    try {
        $st = $pdo->prepare("SELECT prenom, nom, email FROM membres WHERE id = ?");
        $st->execute([$mid]);
        $m = $st->fetch();
        if (!$m) { return; }
        $destinataire = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
        if ($destinataire === '') { $destinataire = 'Cher membre'; }

        $titre = $estResponsable
            ? '👥 Responsable de commission'
            : '👥 Ajout à une commission';
        $corpsNotif = $estResponsable
            ? 'Vous êtes désormais responsable de la commission « ' . $commissionNom . ' ».'
            : 'Vous avez été ajouté(e) à la commission « ' . $commissionNom . ' ».';

        // 1) Cloche navigateur + Web Push (jamais bloquant)
        // Un responsable peut ouvrir l'espace commissions ; un membre simple est
        // renvoyé vers son accueil (il n'a pas accès à commission_dashboard.php).
        $lienNotif = $estResponsable ? 'commission_dashboard.php' : 'index.php';
        troba_notify_membre($pdo, $mid, 'commission', $titre, $corpsNotif, $lienNotif);

        // 2) E-mail (voie principale)
        $email = trim((string) ($m['email'] ?? ''));
        if ($email !== '') {
            $sujet = $estResponsable
                ? 'Touba Lyon — Vous êtes responsable de la commission ' . $commissionNom
                : 'Touba Lyon — Ajout à la commission ' . $commissionNom;
            $intro = $estResponsable
                ? 'Vous venez d\'être nommé(e) <strong>responsable</strong> de la commission <strong>' . htmlspecialchars($commissionNom) . '</strong>.'
                : 'Vous venez d\'être ajouté(e) comme <strong>membre</strong> de la commission <strong>' . htmlspecialchars($commissionNom) . '</strong>.';
            $lien = dahira_base_url() . '/' . ($estResponsable ? 'commission_dashboard.php' : 'index.php');
            $corpsHtml =
                '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
                . '<h2 style="color:#1b4332;">Assalamu aleykum ' . htmlspecialchars($destinataire) . ' 🙏</h2>'
                . '<p style="color:#333;">' . $intro . '</p>'
                . ($estResponsable
                    ? '<p style="color:#333;">Vous pouvez désormais gérer les membres de cette commission depuis votre espace.</p>'
                    : '<p style="color:#333;">Vous pouvez retrouver les informations de cette commission depuis votre espace.</p>')
                . dahira_email_button($lien, 'Ouvrir mon espace')
                . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p>'
                . '</div>';
            send_smtp_mail($email, $destinataire, $sujet, $corpsHtml);
        }
    } catch (Exception $e) {
        error_log('Touba Lyon commission notify: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (($_POST['ajax'] ?? '') === '1');
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        $cid = (int) ($_POST['commission_id'] ?? 0);
        $ids = $_POST['membre_ids'] ?? [];
        $mid = (int) ($_POST['membre_id'] ?? 0);
        try {
            if ($action === 'set_commission_members' && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                // Membres déjà présents avant, pour ne notifier que les nouveaux
                $bq = $pdo->prepare("SELECT membre_id FROM commission_membres WHERE commission_id = ?");
                $bq->execute([$cid]);
                $avant = array_map('intval', $bq->fetchAll(PDO::FETCH_COLUMN));
                $demandes = [];
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM commission_membres WHERE commission_id = ?")->execute([$cid]);
                if (is_array($ids) && !empty($ids)) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO commission_membres (commission_id, membre_id) VALUES (?, ?)");
                    foreach ($ids as $one) { $one = (int) $one; if ($one > 0) { $ins->execute([$cid, $one]); $demandes[] = $one; } }
                }
                $pdo->commit();
                // Un responsable doit être membre : ceux qui ne le sont plus perdent le rôle.
                $pdo->prepare("DELETE FROM commission_gestionnaires WHERE commission_id = ? AND membre_id NOT IN (SELECT membre_id FROM commission_membres WHERE commission_id = ?)")->execute([$cid, $cid]);
                com_clean_sous_membres($pdo, $cid);
                $success = "Les membres de la commission ont été mis à jour.";
                // Notifier uniquement les membres nouvellement ajoutés
                $nouveaux = array_diff(array_unique($demandes), $avant);
                if (!empty($nouveaux)) {
                    $cnom = com_commission_nom($pdo, $cid);
                    foreach ($nouveaux as $addMid) { com_notify_membre_ajout($pdo, (int) $addMid, $cnom, false); }
                }
            } elseif ($action === 'set_commission_managers' && $cid > 0 && $__isAdmin) {
                // Réservé à l'administrateur : définit les responsables (rôle) de la commission.
                $bq = $pdo->prepare("SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?");
                $bq->execute([$cid]);
                $avant = array_map('intval', $bq->fetchAll(PDO::FETCH_COLUMN));
                $demandes = [];
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM commission_gestionnaires WHERE commission_id = ?")->execute([$cid]);
                if (is_array($ids) && !empty($ids)) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO commission_gestionnaires (commission_id, membre_id) VALUES (?, ?)");
                    // Un responsable doit être membre de la commission : les autres sont refusés.
                    $mq = $pdo->prepare("SELECT membre_id FROM commission_membres WHERE commission_id = ?");
                    $mq->execute([$cid]);
                    $deLaCom = array_map('intval', $mq->fetchAll(PDO::FETCH_COLUMN));
                    $refuses = 0;
                    foreach ($ids as $one) {
                        $one = (int) $one;
                        if ($one <= 0) { continue; }
                        if (!in_array($one, $deLaCom, true)) { $refuses++; continue; }
                        $ins->execute([$cid, $one]); $demandes[] = $one;
                    }
                }
                $pdo->commit();
                $success = "Les responsables de la commission ont été mis à jour." . (!empty($refuses) ? " $refuses membre(s) ignoré(s) : un responsable doit d'abord être membre de la commission." : '');
                // Notifier uniquement les responsables nouvellement nommés
                $nouveaux = array_diff(array_unique($demandes), $avant);
                if (!empty($nouveaux)) {
                    $cnom = com_commission_nom($pdo, $cid);
                    foreach ($nouveaux as $addMid) { com_notify_membre_ajout($pdo, (int) $addMid, $cnom, true); }
                }
            } elseif ($action === 'add_commission_member' && $cid > 0 && $mid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                $st = $pdo->prepare("INSERT IGNORE INTO commission_membres (commission_id, membre_id) VALUES (?, ?)");
                $st->execute([$cid, $mid]);
                $success = "Membre ajouté.";
                if ($st->rowCount() > 0) { com_notify_membre_ajout($pdo, $mid, com_commission_nom($pdo, $cid), false); }
            } elseif ($action === 'remove_commission_member' && $cid > 0 && $mid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                $pdo->prepare("DELETE FROM commission_membres WHERE commission_id = ? AND membre_id = ?")->execute([$cid, $mid]);
                // Un responsable doit être membre : retiré de la commission, il n'en est plus responsable.
                $pdo->prepare("DELETE FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ?")->execute([$cid, $mid]);
                com_clean_sous_membres($pdo, $cid);
                $success = "Membre retiré.";
            } elseif ($action === 'add_commission_manager' && $cid > 0 && $mid > 0 && $__isAdmin) {
                // Un responsable doit être membre de la commission.
                $ok = $pdo->prepare("SELECT 1 FROM commission_membres WHERE commission_id = ? AND membre_id = ?");
                $ok->execute([$cid, $mid]);
                if ($ok->fetchColumn() === false) {
                    $error = "Ce membre ne fait pas partie de la commission : ajoutez-le d'abord comme membre, puis nommez-le responsable.";
                } else {
                    $st = $pdo->prepare("INSERT IGNORE INTO commission_gestionnaires (commission_id, membre_id) VALUES (?, ?)");
                    $st->execute([$cid, $mid]);
                    $success = "Responsable ajouté.";
                    if ($st->rowCount() > 0) { com_notify_membre_ajout($pdo, $mid, com_commission_nom($pdo, $cid), true); }
                }
            } elseif ($action === 'create_reunion' && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                $titre = trim($_POST['titre'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $dateDebut = trim($_POST['date_debut'] ?? '');
                $heure = trim($_POST['heure'] ?? '');
                $heureFin = trim($_POST['heure_fin'] ?? '');
                $modeRaw = trim($_POST['mode'] ?? '');
                $mode = ($modeRaw === 'presentiel') ? 'presentiel' : (($modeRaw === 'distance') ? 'distance' : '');
                $lieu = trim($_POST['lieu'] ?? '');
                $lien = trim($_POST['lien'] ?? '');
                $estPeriodique = !empty($_POST['periodique']);
                $per = ($estPeriodique && in_array(($_POST['periodicite'] ?? ''), ['hebdomadaire', 'quinzaine', 'mensuelle'], true)) ? $_POST['periodicite'] : 'ponctuelle';
                $dateFin = trim($_POST['date_fin'] ?? '');
                // Sondage de date et d'horaire : plusieurs options proposées, la réunion n'est publiable qu'après validation d'une date
                $sondage = !empty($_POST['sondage']);
                $optDates = (array) ($_POST['opt_date'] ?? []);
                $optHeures = (array) ($_POST['opt_heure'] ?? []);
                $optFins = (array) ($_POST['opt_fin'] ?? []);
                $optionsSondage = [];
                $optErr = false;
                if ($sondage) {
                    foreach ($optDates as $ok => $od) {
                        $od = trim((string) $od);
                        $oh = trim((string) ($optHeures[$ok] ?? ''));
                        $of = trim((string) ($optFins[$ok] ?? ''));
                        if ($od === '' && $oh === '') { continue; }
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $od) || $od < date('Y-m-d') || $oh === '' || $of === '' || $of <= $oh) { $optErr = true; continue; }
                        $optionsSondage[$od . '|' . $oh] = [$od, $oh, $of];
                    }
                    $optionsSondage = array_values($optionsSondage);
                    usort($optionsSondage, function ($a, $b) { return strcmp($a[0] . $a[1], $b[0] . $b[1]); });
                    if (count($optionsSondage) >= 1) { $dateDebut = $optionsSondage[0][0]; $heure = $optionsSondage[0][1]; $heureFin = $optionsSondage[0][2]; }
                    $per = 'ponctuelle';
                }
                if ($sondage && ($optErr || count($optionsSondage) < 2)) {
                    $error = "Sondage : proposez au moins deux dates valides (date, heure de début et heure de fin après le début, pas dans le passé).";
                } elseif ($titre === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut)) {
                    $error = "Le titre et une date valide sont obligatoires.";
                } elseif ($dateDebut < date('Y-m-d')) {
                    $error = "La date de la réunion ne peut pas être dans le passé.";
                } elseif ($heure === '') {
                    $error = "L'heure est obligatoire.";
                } elseif ($heureFin === '') {
                    $error = "L'heure de fin est obligatoire.";
                } elseif ($heureFin <= $heure) {
                    $error = "L'heure de fin doit être après l'heure de début.";
                } elseif ($mode === '') {
                    $error = "Le mode (à distance ou présentiel) est obligatoire.";
                } elseif ($mode === 'distance' && $lien === '') {
                    $error = "Le lien Zoom est obligatoire pour une réunion à distance.";
                } elseif ($mode === 'presentiel' && $lieu === '') {
                    $error = "L'adresse est obligatoire pour une réunion en présentiel.";
                } else {
                    $dates = reunion_generer_dates($dateDebut, ($dateFin !== '' ? $dateFin : $dateDebut), $per);
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare("INSERT INTO commission_reunions (commission_id, titre, description, date_reunion, heure, heure_fin, mode, lieu, lien, periodicite, publie, cloture, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,0,0,?)");
                    $createdIds = [];
                    foreach ($dates as $d) {
                        $ins->execute([$cid, $titre, ($desc ?: null), $d, $heure, ($heureFin ?: null), $mode, ($lieu ?: null), ($lien ?: null), $per, (!empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : null)]);
                        $createdIds[] = (int) $pdo->lastInsertId();
                    }
                    if (!empty($createdIds)) {
                        $serieId = $createdIds[0];
                        $inPh = implode(',', array_fill(0, count($createdIds), '?'));
                        $pdo->prepare("UPDATE commission_reunions SET serie_id = ? WHERE id IN ($inPh)")->execute(array_merge([$serieId], $createdIds));
                    }
                    $pdo->commit();
                    if ($sondage && !empty($createdIds)) {
                        $pdo->prepare("UPDATE commission_reunions SET sondage_statut = 'ouvert' WHERE id = ?")->execute([$createdIds[0]]);
                        $insO = $pdo->prepare("INSERT INTO reunion_sondage_options (reunion_id, date_option, heure, heure_fin) VALUES (?,?,?,?)");
                        foreach ($optionsSondage as $so) { $insO->execute([$createdIds[0], $so[0], $so[1], ($so[2] !== '' ? $so[2] : null)]); }
                    }
                    $success = $sondage ? "Réunion créée avec un sondage de date : partagez le lien par WhatsApp, puis validez une date avant de publier." : (count($dates) > 1 ? (count($dates) . " réunions créées (série périodique).") : "Réunion créée.");
                }
            } elseif (in_array($action, ['publish_reunion', 'unpublish_reunion', 'close_reunion', 'reopen_reunion', 'delete_reunion', 'delete_reunion_serie', 'edit_reunion', 'cancel_reunion', 'duplicate_reunion'], true)) {
                $rid = (int) ($_POST['reunion_id'] ?? 0);
                $r = null;
                if ($rid > 0) {
                    $rq = $pdo->prepare("SELECT * FROM commission_reunions WHERE id = ?");
                    $rq->execute([$rid]);
                    $r = $rq->fetch();
                }
                if (!$r || !com_can_manage((int) $r['commission_id'], $__isAdmin, $__managedCommissions)) {
                    $error = "Réunion introuvable ou accès refusé.";
                } elseif ($action === 'publish_reunion') {
                    if (($r['sondage_statut'] ?? 'aucun') === 'ouvert') {
                        $error = "Impossible de publier : validez d'abord une date du sondage.";
                    } elseif (reunion_precedente_non_cloturee($pdo, $r)) {
                        $error = "Impossible de publier : clôturez d'abord la réunion précédente de la série.";
                    } else {
                        $pdo->prepare("UPDATE commission_reunions SET publie = 1, updated_at = NOW() WHERE id = ?")->execute([$rid]);
                        $r['publie'] = 1;
                        $n = reunion_notifier_membres($pdo, $r);
                        $success = "Réunion publiée. $n membre(s) de la commission notifié(s).";
                    }
                } elseif ($action === 'unpublish_reunion') {
                    $pdo->prepare("UPDATE commission_reunions SET publie = 0, updated_at = NOW() WHERE id = ?")->execute([$rid]);
                    $success = "Réunion dépubliée (masquée aux membres).";
                } elseif ($action === 'close_reunion') {
                    $pdo->prepare("UPDATE commission_reunions SET cloture = 1, updated_at = NOW() WHERE id = ?")->execute([$rid]);
                    $success = "Réunion clôturée.";
                    // Option : publier la période suivante de la série
                    if (!empty($_POST['publish_next'])) {
                        $next = reunion_prochaine_a_publier($pdo, $r);
                        if ($next) {
                            $pdo->prepare("UPDATE commission_reunions SET publie = 1, updated_at = NOW() WHERE id = ?")->execute([(int) $next['id']]);
                            $next['publie'] = 1;
                            $nn = reunion_notifier_membres($pdo, $next);
                            $success = "Réunion clôturée et période suivante publiée ($nn membre(s) notifié(s)).";
                        }
                    }
                } elseif ($action === 'edit_reunion') {
                    if ((int) $r['publie'] === 1) {
                        $error = "Dépubliez la réunion avant de la modifier.";
                    } else {
                        $eTitre = trim($_POST['titre'] ?? '');
                        $eDesc = trim($_POST['description'] ?? '');
                        $eDate = trim($_POST['date_reunion'] ?? '');
                        $eHeure = trim($_POST['heure'] ?? '');
                        $eHeureFin = trim($_POST['heure_fin'] ?? '');
                        $eModeRaw = trim($_POST['mode'] ?? '');
                        $eMode = ($eModeRaw === 'presentiel') ? 'presentiel' : (($eModeRaw === 'distance') ? 'distance' : '');
                        $eLieu = trim($_POST['lieu'] ?? '');
                        $eLien = trim($_POST['lien'] ?? '');
                        if ($eTitre === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eDate)) {
                            $error = "Le titre et une date valide sont obligatoires.";
                        } elseif ($eDate < date('Y-m-d')) {
                            $error = "La date de la réunion ne peut pas être dans le passé.";
                        } elseif ($eHeure === '') {
                            $error = "L'heure est obligatoire.";
                        } elseif ($eHeureFin === '') {
                            $error = "L'heure de fin est obligatoire.";
                        } elseif ($eHeureFin <= $eHeure) {
                            $error = "L'heure de fin doit être après l'heure de début.";
                        } elseif ($eMode === '') {
                            $error = "Le mode est obligatoire.";
                        } elseif ($eMode === 'distance' && $eLien === '') {
                            $error = "Le lien Zoom est obligatoire pour une réunion à distance.";
                        } elseif ($eMode === 'presentiel' && $eLieu === '') {
                            $error = "L'adresse est obligatoire pour une réunion en présentiel.";
                        } else {
                            $pdo->prepare("UPDATE commission_reunions SET titre = ?, description = ?, date_reunion = ?, heure = ?, heure_fin = ?, mode = ?, lieu = ?, lien = ?, updated_at = NOW() WHERE id = ?")
                                ->execute([$eTitre, ($eDesc ?: null), $eDate, $eHeure, ($eHeureFin ?: null), $eMode, ($eLieu ?: null), ($eLien ?: null), $rid]);
                            $success = "Réunion modifiée.";
                        }
                    }
                } elseif ($action === 'reopen_reunion') {
                    $pdo->prepare("UPDATE commission_reunions SET cloture = 0, updated_at = NOW() WHERE id = ?")->execute([$rid]);
                    $success = "Réunion rouverte.";
                } elseif ($action === 'cancel_reunion') {
                    $pdo->prepare("UPDATE commission_reunions SET annule = 1, updated_at = NOW() WHERE id = ?")->execute([$rid]);
                    $r['annule'] = 1;
                    $n = reunion_notifier_annulation($pdo, $r);
                    $success = "Réunion annulée. $n membre(s) de la commission notifié(s).";
                } elseif ($action === 'duplicate_reunion') {
                    $ins = $pdo->prepare("INSERT INTO commission_reunions (commission_id, titre, description, date_reunion, heure, heure_fin, mode, lieu, lien, periodicite, publie, cloture, annule, created_by) VALUES (?,?,?,?,?,?,?,?,?, 'ponctuelle', 0, 0, 0, ?)");
                    $ins->execute([(int) $r['commission_id'], (string) $r['titre'], ($r['description'] ?? null), (string) $r['date_reunion'], (string) $r['heure'], ($r['heure_fin'] ?? null), (string) $r['mode'], ($r['lieu'] ?? null), ($r['lien'] ?? null), (!empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : null)]);
                    $newId = (int) $pdo->lastInsertId();
                    $pdo->prepare("UPDATE commission_reunions SET serie_id = ? WHERE id = ?")->execute([$newId, $newId]);
                    $success = "Réunion dupliquée (nouveau brouillon en attente de publication).";
                } elseif ($action === 'delete_reunion') {
                    $pdo->prepare("DELETE FROM commission_reunions WHERE id = ?")->execute([$rid]);
                    $pdo->prepare("DELETE FROM presence_validations WHERE planning_type = 'reunion' AND planning_id = ?")->execute([$rid]);
                    $success = "Réunion supprimée.";
                } elseif ($action === 'delete_reunion_serie') {
                    $serie = (int) ($r['serie_id'] ?: $r['id']);
                    // Supprimer toutes les réunions de la série + leurs présences
                    $idq = $pdo->prepare("SELECT id FROM commission_reunions WHERE serie_id = ?");
                    $idq->execute([$serie]);
                    $ids = array_map('intval', $idq->fetchAll(PDO::FETCH_COLUMN));
                    if (empty($ids)) { $ids = [$rid]; }
                    $inPh = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM commission_reunions WHERE id IN ($inPh)")->execute($ids);
                    $pdo->prepare("DELETE FROM presence_validations WHERE planning_type = 'reunion' AND planning_id IN ($inPh)")->execute($ids);
                    $success = count($ids) . " réunion(s) de la série supprimée(s).";
                }
            } elseif ($action === 'create_sous_commission' && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                $nomSc = trim($_POST['nom'] ?? '');
                if ($nomSc === '') {
                    $error = "Le nom de la sous-commission est obligatoire.";
                } else {
                    $insSc = $pdo->prepare("INSERT IGNORE INTO sous_commissions (commission_id, nom, created_by, accepte_supports) VALUES (?, ?, ?, ?)");
                    $insSc->execute([$cid, mb_substr($nomSc, 0, 150), !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : null, !empty($_POST['accepte_supports']) ? 1 : 0]);
                    if ($insSc->rowCount() > 0) {
                        $bilanAjout = com_ajouter_a_sous_commission($pdo, $cid, (int) $pdo->lastInsertId(), (!empty($_POST['accepte_supports']) ? (array) ($_POST['membre_ids'] ?? []) : []), $nomSc);
                        $success = "Sous-commission « " . $nomSc . " » créée"
                            . (($bilanAjout['membres'] + $bilanAjout['supports']) > 0 ? " — " . $bilanAjout['membres'] . " membre(s) de la commission et " . $bilanAjout['supports'] . " membre(s) support ajouté(s)" : '') . ".";
                    } else {
                        $success = "Cette sous-commission existe déjà.";
                    }
                }
            } elseif (($action === 'add_sous_commission_support' || $action === 'remove_sous_commission_support') && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                // Membre support : membre quelconque rattaché à la sous-commission, hors commission
                $scIdS = (int) ($_POST['sous_commission_id'] ?? 0);
                $chkS = $pdo->prepare("SELECT nom, accepte_supports FROM sous_commissions WHERE id = ? AND commission_id = ?");
                $chkS->execute([$scIdS, $cid]);
                $scRowS = $chkS->fetch();
                $scNomS = $scRowS ? $scRowS['nom'] : false;
                if ($scNomS === false) {
                    $error = "Sous-commission introuvable.";
                } elseif ($mid <= 0) {
                    $error = "Choisissez un membre.";
                } elseif ($action === 'remove_sous_commission_support') {
                    $pdo->prepare("DELETE FROM sous_commission_membres WHERE sous_commission_id = ? AND membre_id = ? AND support = 1")->execute([$scIdS, $mid]);
                    $success = "Membre support retiré de la sous-commission « " . $scNomS . " ».";
                } elseif (empty($scRowS['accepte_supports'])) {
                    $error = "Cette sous-commission n'accepte pas de membres supports : activez d'abord l'option.";
                } else {
                    $okM = $pdo->prepare("SELECT 1 FROM membres WHERE id = ? AND status = 'approved'");
                    $okM->execute([$mid]);
                    $dejaS = $pdo->prepare("SELECT 1 FROM sous_commission_membres WHERE sous_commission_id = ? AND membre_id = ?");
                    $dejaS->execute([$scIdS, $mid]);
                    $inComS = $pdo->prepare("SELECT 1 FROM (SELECT membre_id FROM commission_membres WHERE commission_id = ? UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?) x WHERE membre_id = ?");
                    $inComS->execute([$cid, $cid, $mid]);
                    if ($okM->fetchColumn() === false) {
                        $error = "Membre introuvable ou non validé.";
                    } elseif ($dejaS->fetchColumn() !== false) {
                        $error = "Ce membre fait déjà partie de la sous-commission.";
                    } elseif ($inComS->fetchColumn() !== false) {
                        $error = "Ce membre fait déjà partie de la commission : affectez-le avec les membres de la sous-commission.";
                    } else {
                        $pdo->prepare("INSERT INTO sous_commission_membres (sous_commission_id, membre_id, support) VALUES (?, ?, 1)")->execute([$scIdS, $mid]);
                        troba_notify_membre($pdo, $mid, 'commission', '🤝 Membre support', 'Vous aidez la sous-commission « ' . $scNomS . ' » de la commission ' . com_commission_nom($pdo, $cid) . ' en tant que membre support.', 'mes_commissions.php');
                        $success = "Membre support ajouté à la sous-commission « " . $scNomS . " ».";
                    }
                }
            } elseif (($action === 'add_sous_commission_member' || $action === 'remove_sous_commission_member') && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                // Membre d'une sous-commission (sélecteur à deux listes) : membre de la commission, ou membre support si l'option est active
                $scIdM = (int) ($_POST['sous_commission_id'] ?? 0);
                $chkM = $pdo->prepare("SELECT nom, accepte_supports, responsable_id FROM sous_commissions WHERE id = ? AND commission_id = ?");
                $chkM->execute([$scIdM, $cid]);
                $scRowM = $chkM->fetch();
                if (!$scRowM) {
                    $error = "Sous-commission introuvable.";
                } elseif ($mid <= 0) {
                    $error = "Choisissez un membre.";
                } elseif ($action === 'remove_sous_commission_member') {
                    $pdo->prepare("DELETE FROM sous_commission_membres WHERE sous_commission_id = ? AND membre_id = ?")->execute([$scIdM, $mid]);
                    if ((int) ($scRowM['responsable_id'] ?? 0) === $mid) {
                        $pdo->prepare("UPDATE sous_commissions SET responsable_id = NULL WHERE id = ?")->execute([$scIdM]);
                    }
                    $success = "Membre retiré de la sous-commission.";
                } else {
                    $inComM = $pdo->prepare("SELECT 1 FROM (SELECT membre_id FROM commission_membres WHERE commission_id = ? UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?) x WHERE membre_id = ?");
                    $inComM->execute([$cid, $cid, $mid]);
                    $dansComM = ($inComM->fetchColumn() !== false);
                    $autreM = $dansComM ? sc_autre_sous_commission($pdo, $cid, $scIdM, $mid) : null;
                    if (!$dansComM && empty($scRowM['accepte_supports'])) {
                        $error = "Ce membre ne fait pas partie de la commission : activez l'option membres supports.";
                    } elseif ($autreM !== null) {
                        $error = sc_message_deja_dans($autreM);
                    } else {
                        com_ajouter_a_sous_commission($pdo, $cid, $scIdM, [$mid], (string) $scRowM['nom']);
                        $success = "Membre ajouté à la sous-commission.";
                    }
                }
            } elseif ($action === 'set_sous_commission_option' && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                $scIdO = (int) ($_POST['sous_commission_id'] ?? 0);
                $accepteO = !empty($_POST['accepte_supports']) ? 1 : 0;
                $chkO = $pdo->prepare("SELECT nom FROM sous_commissions WHERE id = ? AND commission_id = ?");
                $chkO->execute([$scIdO, $cid]);
                $scNomO = $chkO->fetchColumn();
                if ($scNomO === false) {
                    $error = "Sous-commission introuvable.";
                } else {
                    $pdo->prepare("UPDATE sous_commissions SET accepte_supports = ? WHERE id = ?")->execute([$accepteO, $scIdO]);
                    $success = $accepteO ? "Les membres supports sont autorisés dans « " . $scNomO . " »." : "Les membres supports ne sont plus acceptés dans « " . $scNomO . " » (les supports déjà ajoutés restent).";
                }
            } elseif ($action === 'set_sous_commission_responsable' && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                // Responsable de sous-commission : un membre de la commission (0 = aucun)
                $scIdR = (int) ($_POST['sous_commission_id'] ?? 0);
                $chkR = $pdo->prepare("SELECT nom, responsable_id FROM sous_commissions WHERE id = ? AND commission_id = ?");
                $chkR->execute([$scIdR, $cid]);
                $scR = $chkR->fetch();
                if (!$scR) {
                    $error = "Sous-commission introuvable.";
                } else {
                    $okR = true;
                    if ($mid > 0) {
                        $inCom = $pdo->prepare("SELECT 1 FROM (SELECT membre_id FROM commission_membres WHERE commission_id = ? UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?) x WHERE membre_id = ?");
                        $inCom->execute([$cid, $cid, $mid]);
                        $okR = ($inCom->fetchColumn() !== false);
                    }
                    $autreR = ($okR && $mid > 0) ? sc_autre_sous_commission($pdo, $cid, $scIdR, $mid) : null;
                    if (!$okR) {
                        $error = "Le responsable doit être membre de la commission.";
                    } elseif ($autreR !== null) {
                        $error = sc_message_deja_dans($autreR);
                    } else {
                        $pdo->prepare("UPDATE sous_commissions SET responsable_id = ? WHERE id = ?")->execute([($mid > 0 ? $mid : null), $scIdR]);
                        if ($mid > 0) {
                            // Le responsable fait partie de la sous-commission
                            $pdo->prepare("INSERT IGNORE INTO sous_commission_membres (sous_commission_id, membre_id) VALUES (?, ?)")->execute([$scIdR, $mid]);
                            if ((int) ($scR['responsable_id'] ?? 0) !== $mid) {
                                troba_notify_membre($pdo, $mid, 'commission', '🎖️ Responsable de sous-commission', 'Vous êtes responsable de la sous-commission « ' . $scR['nom'] . ' » de la commission ' . com_commission_nom($pdo, $cid) . '.', 'mes_commissions.php');
                            }
                            $success = "Responsable de la sous-commission « " . $scR['nom'] . " » enregistré.";
                        } else {
                            $success = "Responsable retiré de la sous-commission « " . $scR['nom'] . " ».";
                        }
                    }
                }
            } elseif (($action === 'delete_sous_commission' || $action === 'set_sous_commission_members') && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                $scId = (int) ($_POST['sous_commission_id'] ?? 0);
                $chkSc = $pdo->prepare("SELECT nom FROM sous_commissions WHERE id = ? AND commission_id = ?");
                $chkSc->execute([$scId, $cid]);
                $scNom = $chkSc->fetchColumn();
                if ($scNom === false) {
                    $error = "Sous-commission introuvable.";
                } elseif ($action === 'delete_sous_commission') {
                    $pdo->prepare("DELETE FROM sous_commission_membres WHERE sous_commission_id = ?")->execute([$scId]);
                    $pdo->prepare("DELETE FROM sous_commissions WHERE id = ?")->execute([$scId]);
                    $success = "Sous-commission « " . $scNom . " » supprimée.";
                } else {
                    // Seuls les membres de la commission parente peuvent être affectés
                    $parentIds = array_map('intval', $pdo->query("SELECT membre_id FROM commission_membres WHERE commission_id = " . (int) $cid)->fetchAll(PDO::FETCH_COLUMN));
                    $voulus = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['membre_ids'] ?? [])), function ($v) use ($parentIds) { return $v > 0 && in_array($v, $parentIds, true); })));
                    // Un membre ne peut être que dans une seule sous-commission de la commission
                    $refusesSc = 0;
                    $voulus = array_values(array_filter($voulus, function ($v) use ($pdo, $cid, $scId, &$refusesSc) { if (sc_autre_sous_commission($pdo, $cid, $scId, $v) !== null) { $refusesSc++; return false; } return true; }));
                    $bq = $pdo->prepare("SELECT membre_id FROM sous_commission_membres WHERE sous_commission_id = ? AND support = 0");
                    $bq->execute([$scId]);
                    $avantSc = array_map('intval', $bq->fetchAll(PDO::FETCH_COLUMN));
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM sous_commission_membres WHERE sous_commission_id = ? AND support = 0")->execute([$scId]);
                    $insM = $pdo->prepare("INSERT INTO sous_commission_membres (sous_commission_id, membre_id, support) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE support = 0");
                    foreach ($voulus as $vm) { $insM->execute([$scId, $vm]); }
                    $pdo->commit();
                    $cnomSc = com_commission_nom($pdo, $cid);
                    foreach (array_diff($voulus, $avantSc) as $vm) {
                        troba_notify_membre($pdo, (int) $vm, 'commission', '🧩 Sous-commission', 'Vous avez été affecté(e) à la sous-commission « ' . $scNom . ' » de la commission ' . $cnomSc . '.', 'index.php');
                    }
                    $success = "Membres de la sous-commission « " . $scNom . " » mis à jour." . ($refusesSc > 0 ? " $refusesSc membre(s) ignoré(s) : déjà dans une autre sous-commission." : '');
                }
            } elseif ($action === 'magal_add_tache' && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                // Tâche du Wadial Magal pour cette commission (la commission doit être impliquée)
                $mgAnnee = magal_annee_defaut();
                $mgTitre = trim($_POST['titre'] ?? '');
                $mgDesc = trim($_POST['description'] ?? '');
                $mgAss = (int) ($_POST['assignee_id'] ?? 0);
                $mgEch = trim($_POST['echeance'] ?? '');
                $mgChk = $pdo->prepare("SELECT 1 FROM magal_commissions WHERE annee = ? AND commission_id = ?");
                $mgChk->execute([$mgAnnee, $cid]);
                if ($mgChk->fetchColumn() === false) {
                    // Première contribution : la commission est inscrite automatiquement au Wadial Magal
                    $pdo->prepare("INSERT IGNORE INTO magal_commissions (annee, commission_id) VALUES (?, ?)")->execute([$mgAnnee, $cid]);
                }
                if ($mgTitre === '') {
                    $error = "Le titre de la tâche est obligatoire.";
                } else {
                    if ($mgEch !== '' && !DateTime::createFromFormat('Y-m-d', $mgEch)) { $mgEch = ''; }
                    if ($mgAss > 0) {
                        $mgOk = $pdo->prepare("SELECT 1 FROM (SELECT membre_id FROM commission_membres WHERE commission_id = ? UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?) x WHERE membre_id = ?");
                        $mgOk->execute([$cid, $cid, $mgAss]);
                        if ($mgOk->fetchColumn() === false) { $mgAss = 0; }
                    }
                    $pdo->prepare("INSERT INTO magal_taches (annee, commission_id, titre, description, assignee_id, echeance, created_by) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$mgAnnee, $cid, mb_substr($mgTitre, 0, 255), ($mgDesc !== '' ? $mgDesc : null), ($mgAss > 0 ? $mgAss : null), ($mgEch !== '' ? $mgEch : null), !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : null]);
                    if ($mgAss > 0 && (empty($_SESSION['player_id']) || $mgAss !== (int) $_SESSION['player_id'])) {
                        troba_notify_membre($pdo, $mgAss, 'magal', '🕌 Wadial Magal : nouvelle tâche', 'Une tâche vous est confiée : ' . $mgTitre, 'magal.php?annee=' . $mgAnnee . '&tab=taches');
                    }
                    $success = "Tâche ajoutée au Wadial Magal " . $mgAnnee . ".";
                }
            } elseif ($action === 'magal_add_besoin' && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                // Liste de besoins du Wadial Magal pour cette commission (la commission doit être impliquée)
                $mgAnneeB = magal_annee_defaut();
                $mgLib = trim($_POST['libelle'] ?? '');
                $mgQte = trim($_POST['quantite'] ?? '');
                $mgChkB = $pdo->prepare("SELECT 1 FROM magal_commissions WHERE annee = ? AND commission_id = ?");
                $mgChkB->execute([$mgAnneeB, $cid]);
                if ($mgChkB->fetchColumn() === false) {
                    // Première contribution : la commission est inscrite automatiquement au Wadial Magal
                    $pdo->prepare("INSERT IGNORE INTO magal_commissions (annee, commission_id) VALUES (?, ?)")->execute([$mgAnneeB, $cid]);
                }
                if ($mgLib === '') {
                    $error = "Le besoin est obligatoire.";
                } else {
                    $pdo->prepare("INSERT INTO magal_besoins (annee, commission_id, libelle, quantite, created_by) VALUES (?,?,?,?,?)")
                        ->execute([$mgAnneeB, $cid, mb_substr($mgLib, 0, 255), ($mgQte !== '' ? mb_substr($mgQte, 0, 60) : null), !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : null]);
                    $success = "Besoin ajouté à la liste du Wadial Magal " . $mgAnneeB . ".";
                }
            } elseif ($action === 'magal_set_besoin_statut' || $action === 'magal_delete_besoin') {
                $mgBid = (int) ($_POST['besoin_id'] ?? 0);
                $mgRowB = $pdo->prepare("SELECT commission_id FROM magal_besoins WHERE id = ?");
                $mgRowB->execute([$mgBid]);
                $mgComB = (int) $mgRowB->fetchColumn();
                if ($mgComB <= 0 || !com_can_manage($mgComB, $__isAdmin, $__managedCommissions)) {
                    $error = "Besoin introuvable ou accès refusé.";
                } elseif ($action === 'magal_delete_besoin') {
                    $pdo->prepare("DELETE FROM magal_besoins WHERE id = ?")->execute([$mgBid]);
                    $success = "Besoin supprimé.";
                } else {
                    $mgStB = (string) ($_POST['statut'] ?? '');
                    if (!in_array($mgStB, ['a_trouver', 'en_cours', 'obtenu'], true)) {
                        $error = "Statut invalide.";
                    } else {
                        $pdo->prepare("UPDATE magal_besoins SET statut = ? WHERE id = ?")->execute([$mgStB, $mgBid]);
                        $success = "Statut du besoin mis à jour.";
                    }
                }
            } elseif ($action === 'magal_set_tache_statut') {
                $mgTid = (int) ($_POST['tache_id'] ?? 0);
                $mgSt = (string) ($_POST['statut'] ?? '');
                $mgRow = $pdo->prepare("SELECT commission_id FROM magal_taches WHERE id = ?");
                $mgRow->execute([$mgTid]);
                $mgCom = (int) $mgRow->fetchColumn();
                if ($mgCom <= 0 || !com_can_manage($mgCom, $__isAdmin, $__managedCommissions)) {
                    $error = "Tâche introuvable ou accès refusé.";
                } elseif (!in_array($mgSt, ['a_faire', 'en_cours', 'fait'], true)) {
                    $error = "Statut invalide.";
                } else {
                    $pdo->prepare("UPDATE magal_taches SET statut = ?, updated_at = NOW() WHERE id = ?")->execute([$mgSt, $mgTid]);
                    $success = "Statut de la tâche mis à jour.";
                }
            } elseif ($action === 'set_commission_dispo' && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                // Définition des disponibilités des membres : activée ou non pour cette commission
                $actif = !empty($_POST['dispo_active']) ? 1 : 0;
                $pdo->prepare("UPDATE commissions SET dispo_active = ? WHERE id = ?")->execute([$actif, $cid]);
                $success = $actif ? "Disponibilités des membres activées pour cette commission." : "Disponibilités des membres désactivées pour cette commission.";
            } elseif ($action === 'set_commission_zoom' && $cid > 0 && com_can_manage($cid, $__isAdmin, $__managedCommissions)) {
                // Lien Zoom par défaut de la commission (pré-rempli pour ses réunions à distance)
                $zoom = trim($_POST['lien_zoom'] ?? '');
                if ($zoom !== '' && !preg_match('#^https?://#i', $zoom)) {
                    $error = "Le lien Zoom doit commencer par https://";
                } else {
                    $pdo->prepare("UPDATE commissions SET lien_zoom = ? WHERE id = ?")->execute([($zoom !== '' ? $zoom : null), $cid]);
                    $success = $zoom !== '' ? "Lien Zoom par défaut de la commission enregistré." : "Lien Zoom par défaut supprimé.";
                }
            } elseif ($action === 'remove_commission_manager' && $cid > 0 && $mid > 0 && $__isAdmin) {
                $pdo->prepare("DELETE FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ?")->execute([$cid, $mid]);
                $success = "Responsable retiré.";
            } else {
                $error = "Action non autorisée.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Touba Lyon commission_dashboard: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
    // Action lancée depuis la page détail d'une réunion : retour sur cette page avec le message
    $retourDetail = (int) ($_POST['retour_detail'] ?? 0);
    if ($retourDetail > 0 && ($error !== '' || $success !== '') && ($_POST['action'] ?? '') !== 'delete_reunion') {
        $_SESSION['reu_flash'] = ['type' => $error !== '' ? 'err' : 'ok', 'msg' => $error !== '' ? $error : $success];
        header('Location: reunion_detail.php?id=' . $retourDetail);
        exit;
    }
    // Réponse légère pour les requêtes AJAX (ajout/retrait immédiat, sans rechargement)
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => empty($error), 'error' => $error]);
        exit;
    }
}

try {
    if ($__isAdmin) {
        $commissions = $pdo->query("SELECT id, nom, COALESCE(stock_enabled,0) AS stock_enabled FROM commissions ORDER BY nom ASC")->fetchAll();
    } elseif (!empty($__managedCommissions)) {
        $in = implode(',', array_fill(0, count($__managedCommissions), '?'));
        $stmt = $pdo->prepare("SELECT id, nom, COALESCE(stock_enabled,0) AS stock_enabled FROM commissions WHERE id IN ($in) ORDER BY nom ASC");
        $stmt->execute($__managedCommissions);
        $commissions = $stmt->fetchAll();
    } else {
        $commissions = [];
    }

    // Mode intégré dans « Mes commissions » (iframe) : une seule commission, sans en-tête ni menu
    if ($embed) {
        $embedCid = (int) ($_GET['commission'] ?? 0);
        $commissions = array_values(array_filter($commissions, function ($c) use ($embedCid) { return (int) $c['id'] === $embedCid; }));
    }

    $allMembers = $pdo->query("SELECT id, prenom, nom, photo_path FROM membres WHERE status = 'approved' ORDER BY nom ASC, prenom ASC")->fetchAll();

    // Membres et responsables par commission
    $comMembers = [];
    $comManagers = [];
    $rows = $pdo->query("SELECT cm.commission_id, m.id, m.prenom, m.nom, m.photo_path
                         FROM commission_membres cm JOIN membres m ON m.id = cm.membre_id
                         ORDER BY m.nom ASC, m.prenom ASC");
    foreach ($rows as $r) { $comMembers[$r['commission_id']][] = $r; }
    $rows2 = $pdo->query("SELECT cg.commission_id, m.id, m.prenom, m.nom, m.photo_path
                          FROM commission_gestionnaires cg JOIN membres m ON m.id = cg.membre_id
                          ORDER BY m.nom ASC, m.prenom ASC");
    foreach ($rows2 as $r) { $comManagers[$r['commission_id']][] = $r; }

    // Réunions par commission
    $comReunions = [];
    try {
        $rr = $pdo->query("SELECT * FROM commission_reunions ORDER BY date_reunion DESC, id DESC");
        foreach ($rr as $r) { $comReunions[(int) $r['commission_id']][] = $r; }
    } catch (Exception $e) { $comReunions = []; }
    $reunionHeureDefaut = dahira_param($pdo, 'guddi_heure', '20h00');
    $reunionZoomLien = dahira_param($pdo, 'guddi_lien_defaut', '');
    // Sous-commissions par commission, avec leurs membres affectés
    $comSous = [];
    try {
        foreach ($pdo->query("SELECT id, commission_id, nom, responsable_id, accepte_supports FROM sous_commissions ORDER BY nom ASC") as $sr) {
            $comSous[(int) $sr['commission_id']][(int) $sr['id']] = ['id' => (int) $sr['id'], 'nom' => (string) $sr['nom'], 'resp' => (int) ($sr['responsable_id'] ?? 0), 'accepte' => (int) ($sr['accepte_supports'] ?? 0), 'membres' => [], 'supports' => []];
        }
        foreach ($pdo->query("SELECT sc.id, sc.commission_id, scm.membre_id, scm.support FROM sous_commission_membres scm JOIN sous_commissions sc ON sc.id = scm.sous_commission_id") as $sm) {
            $comSous[(int) $sm['commission_id']][(int) $sm['id']][((int) ($sm['support'] ?? 0)) === 1 ? 'supports' : 'membres'][] = (int) $sm['membre_id'];
        }
    } catch (Exception $e) { $comSous = []; }
    // Lien Zoom par défaut propre à chaque commission (prioritaire sur celui du Guddi)
    $comZoom = [];
    try {
        foreach ($pdo->query("SELECT id, lien_zoom FROM commissions WHERE lien_zoom IS NOT NULL AND lien_zoom <> ''") as $zr) { $comZoom[(int) $zr['id']] = (string) $zr['lien_zoom']; }
    } catch (Exception $e) { $comZoom = []; }
    // Option « disponibilités des membres » par commission (activée par défaut)
    $comDispo = [];
    try {
        foreach ($pdo->query("SELECT id, COALESCE(dispo_active, 1) AS a FROM commissions") as $dr) { $comDispo[(int) $dr['id']] = ((int) $dr['a'] === 1); }
    } catch (Exception $e) { $comDispo = []; }
    // Wadial Magal (année en cours) : commissions impliquées, leurs tâches et leur partie finance
    require_once __DIR__ . '/magal_helper.php';
    $mgAnneeCourante = magal_annee_defaut();
    $mgInvolved = [];
    $mgTachesParCom = [];
    $mgFinParCom = [];
    try {
        $q = $pdo->prepare("SELECT commission_id FROM magal_commissions WHERE annee = ?");
        $q->execute([$mgAnneeCourante]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mgc) { $mgInvolved[(int) $mgc] = true; }
        $q = $pdo->prepare("SELECT t.*, CONCAT(m.prenom, ' ', m.nom) AS assignee_nom FROM magal_taches t LEFT JOIN membres m ON m.id = t.assignee_id WHERE t.annee = ? ORDER BY FIELD(t.statut,'en_cours','a_faire','fait'), t.echeance IS NULL, t.echeance ASC, t.id ASC");
        $q->execute([$mgAnneeCourante]);
        foreach ($q as $mgt) { $mgTachesParCom[(int) $mgt['commission_id']][] = $mgt; }
        $mgBesoinsParCom = [];
        $q = $pdo->prepare("SELECT * FROM magal_besoins WHERE annee = ? ORDER BY FIELD(statut,'a_trouver','en_cours','obtenu'), id ASC");
        $q->execute([$mgAnneeCourante]);
        foreach ($q as $mgb) { $mgBesoinsParCom[(int) $mgb['commission_id']][] = $mgb; }
        $q = $pdo->prepare("SELECT * FROM magal_finance WHERE annee = ? AND commission_id IS NOT NULL ORDER BY COALESCE(date_op, DATE(created_at)) DESC, id DESC");
        $q->execute([$mgAnneeCourante]);
        foreach ($q as $mgf) {
            $k = (int) $mgf['commission_id'];
            if (!isset($mgFinParCom[$k])) { $mgFinParCom[$k] = ['lignes' => [], 'rec' => 0.0, 'dep' => 0.0]; }
            $mgFinParCom[$k]['lignes'][] = $mgf;
            if ($mgf['type'] === 'recette') { $mgFinParCom[$k]['rec'] += (float) $mgf['montant']; } else { $mgFinParCom[$k]['dep'] += (float) $mgf['montant']; }
        }
    } catch (Exception $e) { $mgInvolved = []; $mgTachesParCom = []; $mgFinParCom = []; }
    $reunionLieuDefaut = dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');

    // Rendu HTML d'une réunion (carte). $showTitle=false pour une période au sein d'une série.
    $renderReunionItem = function ($r, $showTitle = true) use ($pdo) {
        $rPub = ((int) $r['publie'] === 1);
        $rClo = ((int) $r['cloture'] === 1);
        $rid = (int) $r['id'];
        $rDateFr = date('d/m/Y', strtotime($r['date_reunion']));
        $csrf = csrf_field();
        $rAnn = ((int) ($r['annule'] ?? 0) === 1);
        // Carte « Annulée » : WhatsApp d'annulation, Dupliquer, Supprimer
        if ($rAnn) {
            $cnom = reunion_commission_nom($pdo, (int) $r['commission_id']);
            $waLink = 'https://wa.me/?text=' . rawurlencode(reunion_message_annulation($r, $cnom));
            $h = '<div style="border:1px solid rgba(191,33,33,0.4); border-radius:10px; padding:0.7rem 0.9rem; background:rgba(191,33,33,0.06);">';
            $h .= '<div style="display:flex; justify-content:space-between; gap:0.5rem; flex-wrap:wrap; align-items:center;">';
            $h .= '<div style="color:#fff; font-weight:700;">📌 ' . htmlspecialchars($r['titre']) . '</div>';
            $h .= '<span class="badge" style="background:rgba(191,33,33,0.18); color:#fca5a5; border:1px solid rgba(191,33,33,0.45);">🚫 Annulée</span>';
            $h .= '</div>';
            $h .= '<div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.3rem;">🗓️ ' . $rDateFr . ' · 🕐 ' . htmlspecialchars($r['heure']) . '</div>';
            $h .= '<div class="km-actions" style="border-top:0; padding-top:0.6rem; margin-top:0.4rem;">';
            $h .= '<a href="' . htmlspecialchars($waLink, ENT_QUOTES) . '" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 WhatsApp annulation</a>';
            $h .= '<form method="POST" style="margin:0;" data-confirm="Dupliquer cette réunion en un nouveau brouillon ?">' . $csrf . '<input type="hidden" name="action" value="duplicate_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><button type="submit" class="btn btn-secondary btn-sm">📄 Dupliquer</button></form>';
            $h .= '<form method="POST" style="margin:0;" data-confirm="Supprimer définitivement cette réunion ?" data-confirm-danger="1">' . $csrf . '<input type="hidden" name="action" value="delete_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><button type="submit" class="btn btn-danger btn-sm">Supprimer</button></form>';
            $h .= '</div></div>';
            return $h;
        }
        $estSerie = ($r['periodicite'] ?? 'ponctuelle') !== 'ponctuelle';
        $canPublish = $estSerie ? !reunion_precedente_non_cloturee($pdo, $r) : true;
        $nextToPublish = $estSerie ? reunion_prochaine_a_publier($pdo, $r) : null;
        $h = '<div style="border:1px solid var(--glass-border); border-radius:10px; padding:0.7rem 0.9rem; background:rgba(255,255,255,0.03);">';
        $h .= '<div style="display:flex; justify-content:space-between; gap:0.5rem; flex-wrap:wrap; align-items:center;">';
        $h .= '<div style="color:#fff; font-weight:700;">' . ($showTitle ? '📌 ' . htmlspecialchars($r['titre']) : '🗓️ ' . $rDateFr) . '</div>';
        $h .= '<div style="display:flex; gap:0.3rem; flex-wrap:wrap;">';
        if ($rClo) { $h .= '<span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-muted);">Clôturée</span>'; }
        elseif ($rPub) { $h .= '<span class="badge badge-approved">🚀 Publiée</span>'; }
        else { $h .= '<span class="badge badge-pending">' . (($r['sondage_statut'] ?? 'aucun') === 'ouvert' ? '📊 Sondage en cours' : '⏳ En attente') . '</span>'; }
        $h .= '</div></div>';
        $hFinTxt = trim((string) ($r['heure_fin'] ?? '')) !== '' ? ' – ' . htmlspecialchars($r['heure_fin']) : '';
        $h .= '<div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.3rem;">🗓️ ' . $rDateFr . ' · 🕐 ' . htmlspecialchars($r['heure']) . $hFinTxt . ' · ' . ((string) $r['mode'] === 'presentiel' ? '🏛️ présentiel' : '💻 à distance') . '</div>';
        $h .= '<div class="km-actions" style="border-top:0; padding-top:0.6rem; margin-top:0.4rem;">';
        // Sondage de date : lien de vote, partage WhatsApp du sondage tant qu'il est ouvert
        $sondageStatut = (string) ($r['sondage_statut'] ?? 'aucun');
        $sondageOuvert = ($sondageStatut === 'ouvert');
        if ($sondageStatut !== 'aucun') {
            if ($sondageOuvert) {
                $optsS = [];
                try { $qo = $pdo->prepare("SELECT * FROM reunion_sondage_options WHERE reunion_id = ? ORDER BY date_option ASC, heure ASC, id ASC"); $qo->execute([$rid]); $optsS = $qo->fetchAll(); } catch (Exception $e) {}
                $waSond = 'https://wa.me/?text=' . rawurlencode(reunion_message_sondage($r, reunion_commission_nom($pdo, (int) $r['commission_id']), $optsS, dahira_base_url() . '/reunion_sondage.php?id=' . $rid));
                $h .= '<a href="' . htmlspecialchars($waSond, ENT_QUOTES) . '" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 Sondage WhatsApp</a>';
            }
            $h .= '<a href="reunion_sondage.php?id=' . $rid . '" class="btn btn-secondary btn-sm" style="border-color:var(--gold); color:var(--gold);">' . ($sondageOuvert ? '📊 Sondage / valider la date' : '📊 Résultats du sondage') . '</a>';
        }
        // Partage WhatsApp uniquement si la réunion est publiée
        if ($rPub) {
            $waNomCom = reunion_commission_nom($pdo, (int) $r['commission_id']);
            $waLien = dahira_base_url() . '/reunion_detail.php?id=' . $rid;
            // Réunion clôturée avec compte rendu : le message contient les détails du compte rendu
            $waCr = $rClo && trim(strip_tags((string) ($r['compte_rendu'] ?? ''))) !== '';
            $waShare = 'https://wa.me/?text=' . rawurlencode($waCr ? reunion_message_compte_rendu($r, $waNomCom, $waLien) : reunion_message_partage($r, $waNomCom, $waLien));
            $h .= '<a href="' . htmlspecialchars($waShare, ENT_QUOTES) . '" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">' . ($waCr ? '🟢 Partager le compte rendu' : '🟢 WhatsApp') . '</a>';
        }
        // Publier / Dépublier juste après WhatsApp
        if (!$rClo) {
            if (!$rPub) {
                if ($sondageOuvert) {
                    $h .= '<span class="btn btn-sm" style="background:rgba(255,255,255,0.06); color:rgba(255,255,255,0.4); cursor:not-allowed;" title="Validez d\'abord une date du sondage">🚀 Publier</span>';
                } elseif ($canPublish) {
                    $h .= '<form method="POST" style="margin:0;" data-confirm="Publier cette réunion et notifier les membres de la commission ?">' . $csrf . '<input type="hidden" name="action" value="publish_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><button type="submit" class="btn btn-primary btn-sm">🚀 Publier</button></form>';
                } else {
                    $h .= '<span class="btn btn-sm" style="background:rgba(255,255,255,0.06); color:rgba(255,255,255,0.4); cursor:not-allowed;" title="Clôturez d\'abord la réunion précédente de la série">🚀 Publier</span>';
                }
            } else {
                $h .= '<form method="POST" style="margin:0;" data-confirm="Dépublier cette réunion ? Elle sera masquée aux membres." data-confirm-danger="1">' . $csrf . '<input type="hidden" name="action" value="unpublish_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><button type="submit" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">⏳ Dépublier</button></form>';
            }
        }
        $h .= '<a href="reunion_detail.php?id=' . $rid . '" class="btn btn-secondary btn-sm">📊 Détail</a>';
        $h .= '<button type="button" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);" onclick="openCrPdfDash(' . $rid . ')">📄 Compte rendu PDF</button>';
        if (!$rPub && !$rClo) {
            $h .= '<button type="button" class="btn btn-secondary btn-sm" style="border-color:#7dd3fc; color:#7dd3fc;" onclick="toggleReuEdit(' . $rid . ')">✏️ Modifier</button>';
        }
        if (!$rClo) {
            $h .= '<form method="POST" style="margin:0;" data-confirm="Clôturer cette réunion ?">' . $csrf . '<input type="hidden" name="action" value="close_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><input type="hidden" name="publish_next" value="0"><button type="submit" class="btn btn-secondary btn-sm">Clôturer</button></form>';
            if ($nextToPublish) {
                $nextDateFr = date('d/m/Y', strtotime($nextToPublish['date_reunion']));
                $h .= '<form method="POST" style="margin:0;" data-confirm="Clôturer cette réunion et publier la suivante (' . $nextDateFr . ') en notifiant les membres ?">' . $csrf . '<input type="hidden" name="action" value="close_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><input type="hidden" name="publish_next" value="1"><button type="submit" class="btn btn-primary btn-sm" style="background:rgba(125,211,252,0.15); border:1px solid rgba(125,211,252,0.5); color:#7dd3fc;">Clôturer + publier la suivante</button></form>';
            }
        } else {
            $h .= '<form method="POST" style="margin:0;">' . $csrf . '<input type="hidden" name="action" value="reopen_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><button type="submit" class="btn btn-secondary btn-sm">Rouvrir</button></form>';
        }
        if (!$rClo) {
            $waAnn = 'https://wa.me/?text=' . rawurlencode(reunion_message_annulation($r, reunion_commission_nom($pdo, (int) $r['commission_id'])));
            $h .= '<form id="cancel-reu-' . $rid . '" method="POST" style="margin:0;">' . $csrf . '<input type="hidden" name="action" value="cancel_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><button type="button" class="btn btn-danger btn-sm" onclick="annulerReunion(' . $rid . ', ' . htmlspecialchars(json_encode($waAnn), ENT_QUOTES) . ')">🚫 Annuler</button></form>';
        }
        $h .= '<form method="POST" style="margin:0;" data-confirm="Supprimer définitivement cette réunion ?" data-confirm-danger="1">' . $csrf . '<input type="hidden" name="action" value="delete_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger); border-color:var(--danger);">🗑️ Supprimer</button></form>';
        $h .= '</div>'; // fin des actions
        // Modification possible uniquement si non publiée et non clôturée
        if (!$rPub && !$rClo) {
            $mv = (string) $r['mode'];
            $optDist = $mv === 'distance' ? ' selected' : '';
            $optPres = $mv === 'presentiel' ? ' selected' : '';
            $h .= '<div id="reu-edit-' . $rid . '" style="display:none; margin-top:0.5rem;">';
            $h .= '<form method="POST" class="reunion-create-form" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:0.6rem;">' . $csrf;
            $h .= '<input type="hidden" name="action" value="edit_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '">';
            $h .= '<div class="form-group" style="grid-column:1/-1; margin:0;"><label class="form-label">Titre</label><input type="text" name="titre" class="form-input" required value="' . htmlspecialchars((string) $r['titre'], ENT_QUOTES) . '"></div>';
            $h .= '<div class="form-group" style="grid-column:1/-1; margin:0;"><label class="form-label">Ordre du jour</label><textarea name="description" class="form-input" rows="2">' . htmlspecialchars((string) ($r['description'] ?? '')) . '</textarea></div>';
            $h .= '<div class="form-group" style="margin:0;"><label class="form-label">Date</label><input type="date" name="date_reunion" class="form-input" required min="' . date('Y-m-d') . '" value="' . htmlspecialchars((string) $r['date_reunion'], ENT_QUOTES) . '" style="width:100%; max-width:100%; box-sizing:border-box;"></div>';
            $curH = dahira_heure_hm((string) $r['heure']);
            $optsH = '<option value="">— Heure —</option>';
            for ($hh = 7; $hh <= 23; $hh++) { foreach (['00', '30'] as $mm) { $hv = sprintf('%02d:%s', $hh, $mm); $optsH .= '<option value="' . $hv . '"' . ($curH === $hv ? ' selected' : '') . '>' . $hv . '</option>'; } }
            $h .= '<div class="form-group" style="margin:0;"><label class="form-label">Heure de début</label><select name="heure" class="form-input modern-select" required>' . $optsH . '</select></div>';
            $curHF = dahira_heure_hm((string) ($r['heure_fin'] ?? ''));
            $optsHF = '<option value="">— Heure —</option>';
            for ($hh = 7; $hh <= 23; $hh++) { foreach (['00', '30'] as $mm) { $hv = sprintf('%02d:%s', $hh, $mm); $optsHF .= '<option value="' . $hv . '"' . (($r['heure_fin'] ?? '') !== '' && $curHF === $hv ? ' selected' : '') . '>' . $hv . '</option>'; } }
            $h .= '<div class="form-group" style="margin:0;"><label class="form-label">Heure de fin <span style="color:var(--danger)">*</span></label><select name="heure_fin" class="form-input modern-select" required>' . $optsHF . '</select></div>';
            $h .= '<div class="form-group" style="margin:0;"><label class="form-label">Mode</label><select name="mode" class="form-input modern-select r-mode" required><option value="distance"' . $optDist . '>💻 À distance (Zoom)</option><option value="presentiel"' . $optPres . '>🏛️ Présentiel</option></select></div>';
            $h .= '<div class="form-group r-lien" style="grid-column:1/-1; margin:0; display:none;"><label class="form-label">🔗 Lien Zoom</label><input type="url" name="lien" class="form-input r-lien-input" value="' . htmlspecialchars((string) ($r['lien'] ?? ''), ENT_QUOTES) . '"></div>';
            $h .= '<div class="form-group r-lieu" style="grid-column:1/-1; margin:0; display:none;"><label class="form-label">📍 Adresse</label><input type="text" name="lieu" class="form-input r-lieu-input" value="' . htmlspecialchars((string) ($r['lieu'] ?? ''), ENT_QUOTES) . '"></div>';
            $h .= '<div style="grid-column:1/-1;"><button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer</button></div>';
            $h .= '</form></div>';
        }
        $h .= '</div>'; // fin de la carte réunion
        return $h;
    };
} catch (Exception $e) {
    error_log('Touba Lyon commission_dashboard (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

function com_member_avatar($m)
{
    $ini = strtoupper(mb_substr($m['prenom'] ?? '', 0, 1) . mb_substr($m['nom'] ?? '', 0, 1));
    if (!empty($m['photo_path'])) {
        return '<img src="uploads/' . htmlspecialchars($m['photo_path'], ENT_QUOTES) . '" alt="" class="km-avatar">';
    }
    return '<span class="km-avatar km-avatar--ph">' . htmlspecialchars($ini !== '' ? $ini : '?') . '</span>';
}

// Données JS
$idsOf = function ($arr) { return array_map(function ($r) { return (int) $r['id']; }, $arr); };
$comMembersMap = [];
foreach ($comMembers as $cid => $arr) { $comMembersMap[$cid] = $idsOf($arr); }
$comManagersMap = [];
foreach ($comManagers as $cid => $arr) { $comManagersMap[$cid] = $idsOf($arr); }
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
    <title>Espaces commissions - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .kourel-wrap { max-width: 960px; margin: 2rem auto; }
        .kourel-card { margin-bottom: 1.25rem; border-radius: 12px; padding: 1.25rem 1.5rem; }
        .kourel-card-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .kourel-card-head h3 { margin: 0; color: var(--white); font-size: 1.2rem; }
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
        .com-members-group summary { user-select: none; }
        .com-members-group summary::-webkit-details-marker { display: none; }
        .com-members-group[open] .com-mem-chevron { transform: rotate(90deg); }
        .com-reu-group summary::-webkit-details-marker { display: none; }
        .com-reu-group[open] .com-reu-chevron { transform: rotate(90deg); }
        @media (max-width: 600px) { .com-filters input, .com-dd { flex: 1 1 100%; } }
        /* ── Sélecteur à deux listes ── */
        #members-modal { position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; padding: 1rem; }
        #members-modal.active { display: flex; }
        #sub-members-modal { position: fixed; inset: 0; z-index: 3000; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; padding: 1rem; }
        #sub-members-modal.active { display: flex; }
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
        /* Wadial Magal dans une commission : groupes repliables et cartes bordées */
        .mg-grp { border: 1px solid var(--glass-border); border-radius: 12px; background: rgba(255,255,255,0.025); margin: 0.7rem 0; overflow: hidden; }
        .mg-grp > summary { cursor: pointer; list-style: none; display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1rem; color: var(--white); font-weight: 800; font-size: 0.92rem; }
        .mg-grp > summary::-webkit-details-marker { display: none; }
        .mg-grp > summary::after { content: '▸'; margin-left: auto; color: var(--gold); transition: transform .2s; }
        .mg-grp[open] > summary::after { transform: rotate(90deg); }
        .mg-grp[open] > summary { border-bottom: 1px solid var(--glass-border); background: rgba(212,175,55,0.07); }
        .mg-n { color: var(--text-muted); font-weight: 600; font-size: 0.8rem; }
        .mg-body { padding: 0.8rem 1rem 1rem; }
        .mg-item { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; background: rgba(255,255,255,0.045); border: 1px solid rgba(212,175,55,0.35); border-left: 4px solid var(--text-muted); border-radius: 10px; padding: 0.65rem 0.9rem; margin-bottom: 0.55rem; box-shadow: 0 2px 8px rgba(0,0,0,0.18); }
        .mg-item.mg-st-fait, .mg-item.mg-st-obtenu { border-left-color: #25d366; }
        .mg-item.mg-st-en_cours { border-left-color: var(--gold); }
        .mg-item.mg-st-a_trouver, .mg-item.mg-st-a_faire { border-left-color: #bf2121; }
        .mg-item.mg-late { border-color: rgba(191,33,33,0.6); }
        .mg-add { padding: 0.9rem 1rem; border: 1px dashed rgba(212,175,55,0.55); border-radius: 10px; background: rgba(212,175,55,0.05); }
        /* Gestion de la commission : groupes modernes avec bordure */
        .com-members-group, .com-reu-group { border: 1px solid rgba(212,175,55,0.38); border-radius: 12px; background: rgba(255,255,255,0.03); overflow: hidden; margin: 0.8rem 0 !important; padding: 0 !important; box-shadow: 0 2px 10px rgba(0,0,0,0.16); }
        .com-members-group > summary, .com-reu-group > summary { padding: 0.8rem 1rem !important; margin: 0 !important; font-size: 0.92rem !important; font-weight: 800 !important; text-transform: none !important; letter-spacing: 0 !important; color: var(--white); transition: background .15s; }
        .com-members-group > summary:hover, .com-reu-group > summary:hover { background: rgba(212,175,55,0.08); }
        .com-members-group[open] > summary, .com-reu-group[open] > summary { background: rgba(212,175,55,0.1); border-bottom: 1px solid rgba(212,175,55,0.3); color: var(--gold); }
        .com-members-group > :not(summary), .com-reu-group > :not(summary) { margin-left: 1rem !important; margin-right: 1rem !important; }
        .com-members-group > :not(summary):first-of-type, .com-reu-group > summary + * { margin-top: 0.8rem !important; }
        /* Groupe « Gestion de la commission » : peu d'espace avant le bouton « Gérer les membres » */
        .com-reu-group[data-section="gestion"] > summary + * { margin-top: 0.25rem !important; }
        .com-reu-group[data-section="gestion"] > .km-actions { padding-top: 0.4rem !important; border-top: 0 !important; margin-bottom: 0.8rem !important; }
        /* Peu d'espace avant le groupe « Membres de la commission » */
        .com-members-group { margin-top: 0.2rem !important; }
        .com-managers-list { margin-bottom: 0.2rem !important; }
        .kourel-card-head { margin-bottom: 0.4rem !important; }
        /* Pas d'espace superflu avant la carte de la commission */
        .kourel-wrap { margin-top: 0.5rem !important; margin-bottom: 0.5rem !important; }
        .kourel-wrap > .admin-welcome-banner, .kourel-wrap > .form-card, .com-filters { margin-bottom: 0.5rem !important; }
        .kourel-card.com-filterable { margin-top: 0 !important; }
        body.embed .kourel-wrap { margin: 0 auto !important; }
        .com-members-group > :last-child, .com-reu-group > :last-child { margin-bottom: 1rem !important; }
        /* groupes imbriqués (ex. réunions passées dans le groupe Réunions) : cadre plus discret */
        .com-reu-group .com-reu-group { border-color: var(--glass-border); background: rgba(255,255,255,0.02); box-shadow: none; }
        /* Mode intégré (iframe de « Mes commissions ») : uniquement la gestion de la commission */
        body.embed .admin-welcome-banner, body.embed .kourel-wrap > .form-card, body.embed .com-filters, body.embed .app-footer, body.embed footer { display: none !important; }
        body.embed main.container { max-width: none !important; padding: 0 !important; margin: 0 !important; }
        body.embed .dashboard-layout { display: block !important; margin-top: 0 !important; }
        body.embed .kourel-card-head { display: none !important; }
        body.embed .kourel-card.glass-card { border: 0 !important; border-radius: 0 !important; box-shadow: none !important; background: transparent !important; margin: 0 !important; }
        body.embed .dashboard-main { width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; }
        body.embed, body.embed html { min-height: 0 !important; height: auto !important; }
        body.embed { overflow: hidden; background: transparent !important; }
        body.embed #members-modal, body.embed #sub-members-modal, body.embed .modal-overlay, body.embed .mdp-overlay { position: absolute !important; inset: var(--vt, 0px) 0 auto 0 !important; width: 100% !important; height: var(--vh, 100vh) !important; }
    </style>
</head>
<body class="<?php echo $embed ? 'embed' : ''; ?>">
    <?php if (!$embed) { include __DIR__ . '/header.php'; } else { echo '<script src="soft-submit.js" defer></script>'; } ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if (!$embed) { if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } } ?>
            <div class="dashboard-main">

        <div class="kourel-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:1rem;">
                <span>📋 Espaces commissions — <strong class="gold-text"><?php echo count($commissions); ?></strong> commission(s)</span>
                <?php if ($__isAdmin): ?>
                    <a href="admin_dashboard.php" class="btn btn-secondary btn-sm">← Tableau de bord</a>
                <?php else: ?>
                    <span style="display:flex; gap:0.5rem; flex-wrap:wrap;"><a href="sondage_gestion.php" class="btn btn-primary btn-sm">📊 Sondages</a><a href="trombinoscope.php" class="btn btn-secondary btn-sm">← Trombinoscope</a></span>
                <?php endif; ?>
            </div>

            <?php if (!$__isAdmin): ?>
            <div class="form-card" style="max-width:none; margin-bottom:1.5rem; padding:1.25rem 1.5rem;">
                <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">
                    Vous êtes <strong>responsable</strong> des commissions ci-dessous. Vous pouvez y ajouter ou retirer des membres.
                </p>
            </div>
            <?php endif; ?>

            <?php if (empty($commissions)): ?>
                <div class="empty-state"><div class="empty-state-icon">📋</div><p>Aucune commission<?php echo $__isAdmin ? ' pour le moment. Créez-en dans « Commissions ».' : ' à gérer pour le moment.'; ?></p></div>
            <?php else: ?>
                <div class="com-filters">
                    <input type="text" id="com-search" placeholder="🔍 Rechercher une commission…">
                    <select id="com-select" class="com-dd">
                        <option value="">Toutes les commissions</option>
                        <?php foreach ($commissions as $c): ?>
                            <option value="<?php echo htmlspecialchars(mb_strtolower($c['nom']), ENT_QUOTES); ?>"><?php echo htmlspecialchars($c['nom']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="com-count" id="com-count"></span>
                </div>
                <?php foreach ($commissions as $c): ?>
                    <?php
                        $membersOfC = $comMembers[$c['id']] ?? [];
                        $managersOfC = $comManagers[$c['id']] ?? [];
                    ?>
                    <div class="glass-card kourel-card com-filterable" id="com-card-<?php echo (int) $c['id']; ?>" data-search="<?php echo htmlspecialchars(mb_strtolower($c['nom']), ENT_QUOTES); ?>">
                        <div class="kourel-card-head">
                            <h3>📋 <?php echo htmlspecialchars($c['nom']); ?>
                                <span class="badge badge-approved com-count-badge" style="margin-left:0.4rem;"><?php echo count($membersOfC); ?> membre(s)</span>
                            </h3>
                        </div>

                        <?php if ($__isAdmin): ?>
                        <div class="com-sub">Responsable(s) de la commission</div>
                        <div class="km-list com-managers-list" id="com-managers-<?php echo (int) $c['id']; ?>">
                            <?php if (empty($managersOfC)): ?>
                                <p class="km-empty">Aucun responsable désigné.</p>
                            <?php else: foreach ($managersOfC as $mm): ?>
                                <span class="km-chip"><?php echo com_member_avatar($mm); ?><span class="km-name">🎖️ <?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></span></span>
                            <?php endforeach; endif; ?>
                        </div>
                        <?php endif; ?>

                        <details data-section="membres" class="com-members-group">
                            <summary class="com-sub" style="cursor:pointer; list-style:none; display:flex; align-items:center; gap:0.4rem;">👥 Membres de la commission <span style="color:var(--text-muted); font-weight:600;">(<?php echo count($membersOfC); ?>)</span> <span class="com-mem-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                            <div class="km-list com-members-list" id="com-members-<?php echo (int) $c['id']; ?>" style="margin-top:0.5rem;">
                                <?php if (empty($membersOfC)): ?>
                                    <p class="km-empty">Aucun membre dans cette commission.</p>
                                <?php else: foreach ($membersOfC as $mm): ?>
                                    <span class="km-chip"><?php echo com_member_avatar($mm); ?><span class="km-name"><?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></span></span>
                                <?php endforeach; endif; ?>
                            </div>
                        </details>

                        <details data-section="gestion" class="com-reu-group" style="margin:0.6rem 0;">
                        <summary style="cursor:pointer; color:var(--gold); font-weight:700; font-size:0.9rem; list-style:none; display:flex; align-items:center; gap:0.4rem;">⚙️ Gestion de la commission <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                        <div class="km-actions" style="margin-top:0.6rem;">
                            <button type="button" class="btn btn-primary btn-sm" onclick="openComModal('members', <?php echo (int) $c['id']; ?>, <?php echo htmlspecialchars(json_encode($c['nom']), ENT_QUOTES); ?>)">👥 Gérer les membres</button>
                            <?php if ($__isAdmin): ?>
                            <button type="button" class="btn btn-secondary btn-sm" style="border-color:var(--gold); color:var(--gold);" onclick="openComModal('managers', <?php echo (int) $c['id']; ?>, <?php echo htmlspecialchars(json_encode($c['nom']), ENT_QUOTES); ?>)">🎖️ Gérer les responsables</button>
                            <?php endif; ?>
                        </div>
                        </details>
                        <?php if (!empty($c['stock_enabled'])): ?>
                        <div class="km-actions" style="margin-top:0.4rem;"><a href="commission_stock.php?id=<?php echo (int) $c['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:#7bd8a6; color:#7bd8a6;">📦 Stock</a></div>
                        <?php endif; ?>

                        <?php
                            $sousDeC = $comSous[(int) $c['id']] ?? [];
                            $peutSous = com_can_manage((int) $c['id'], $__isAdmin, $__managedCommissions);
                            $nomsMembres = [];
                            foreach ($membersOfC as $mm) { $nomsMembres[(int) $mm['id']] = trim($mm['prenom'] . ' ' . $mm['nom']); }
                        ?>
                        <?php if ($peutSous || !empty($sousDeC)): ?>
                        <div style="border-top:1px solid var(--glass-border); padding-top:1rem; margin-top:1rem;">
                            <details data-section="sous" class="com-reu-group">
                            <summary class="com-sub" style="margin-top:0; cursor:pointer; list-style:none; display:flex; align-items:center; gap:0.4rem;">🧩 Sous-commissions <span style="color:var(--text-muted); font-weight:600;">(<?php echo count($sousDeC); ?>)</span> <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                            <div style="margin-top:0.6rem;">
                            <?php if (empty($sousDeC)): ?>
                                <p class="km-empty">Aucune sous-commission.</p>
                            <?php endif; ?>
                            <?php foreach ($sousDeC as $sc): ?>
                                <details id="sc-<?php echo (int) $sc['id']; ?>" style="border:1px solid var(--glass-border); border-radius:10px; padding:0.6rem 0.9rem; background:rgba(255,255,255,0.02); margin-bottom:0.5rem;">
                                    <summary style="cursor:pointer; font-weight:700; color:var(--white); display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                                        🧩 <?php echo htmlspecialchars($sc['nom']); ?>
                                        <span class="badge badge-approved"><?php echo count($sc['membres']); ?> membre(s)</span>
                                        <?php if (!empty($sc['resp']) && isset($nomsMembres[(int) $sc['resp']])): ?>
                                            <span class="badge" style="background:rgba(212,175,55,0.15); color:var(--gold); border:1px solid rgba(212,175,55,0.4);">🎖️ <?php echo htmlspecialchars($nomsMembres[(int) $sc['resp']]); ?></span>
                                        <?php endif; ?>
                                    </summary>
                                    <div class="km-list" style="margin-top:0.6rem;">
                                        <?php if (empty($sc['membres'])): ?>
                                            <p class="km-empty">Aucun membre affecté.</p>
                                        <?php else: foreach ($sc['membres'] as $smid): ?>
                                            <span class="km-chip"><span class="km-name"><?php echo htmlspecialchars($nomsMembres[$smid] ?? ('Membre #' . $smid)); ?></span></span>
                                        <?php endforeach; endif; ?>
                                    </div>
                                    <?php
                                        // Membres supports : n'importe quel membre, hors commission
                                        if (!isset($nomsTous)) {
                                            $nomsTous = [];
                                            foreach ($allMembers as $am) { $nomsTous[(int) $am['id']] = trim($am['prenom'] . ' ' . $am['nom']); }
                                        }
                                        $deLaSous = array_merge($sc['membres'], $sc['supports'] ?? []);
                                    ?>
                                    <div style="margin-top:0.8rem;">
                                        <div style="color:var(--text-muted); font-size:0.74rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.3rem;">🤝 Membres supports (hors commission)</div>
                                        <div class="km-list">
                                            <?php if (empty($sc['supports'])): ?>
                                                <p class="km-empty">Aucun membre support.</p>
                                            <?php else: foreach ($sc['supports'] as $spId): ?>
                                                <span class="km-chip"><span class="km-name">🤝 <?php echo htmlspecialchars($nomsTous[$spId] ?? ('Membre #' . $spId)); ?></span>
                                                    <?php if ($peutSous): ?>
                                                    <form method="POST" style="display:inline; margin:0;" data-confirm="Retirer <?php echo htmlspecialchars($nomsTous[$spId] ?? 'ce membre', ENT_QUOTES); ?> des membres supports ?" data-confirm-danger="1">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="remove_sous_commission_support">
                                                        <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                                        <input type="hidden" name="sous_commission_id" value="<?php echo (int) $sc['id']; ?>">
                                                        <input type="hidden" name="membre_id" value="<?php echo (int) $spId; ?>">
                                                        <button type="submit" title="Retirer" style="background:none; border:0; color:#fca5a5; cursor:pointer; padding:0 0 0 0.3rem; font-size:0.9rem;">✕</button>
                                                    </form>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endforeach; endif; ?>
                                        </div>
                                        <?php if ($peutSous): ?>
                                        <form method="POST" style="margin:0.4rem 0 0; display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="set_sous_commission_option">
                                            <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                            <input type="hidden" name="sous_commission_id" value="<?php echo (int) $sc['id']; ?>">
                                            <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; color:var(--white); font-size:0.84rem;">
                                                <input type="checkbox" name="accepte_supports" value="1"<?php echo !empty($sc['accepte']) ? ' checked' : ''; ?>>
                                                Autoriser l'ajout de n'importe quel membre
                                            </label>
                                            <button type="submit" class="btn btn-secondary btn-sm">💾 Enregistrer</button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($peutSous && !empty($sc['accepte'])): ?>
                                        <form method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-end; margin:0.5rem 0 0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="add_sous_commission_support">
                                            <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                            <input type="hidden" name="sous_commission_id" value="<?php echo (int) $sc['id']; ?>">
                                            <?php if (empty($supAssets)): $supAssets = true;
                                                $pairesMembres = [];
                                                foreach ($allMembers as $am) { $pairesMembres[] = [(int) $am['id'], trim($am['prenom'] . ' ' . $am['nom'])]; }
                                            ?>
                                            <style>
                                                .msel-sup-panel { display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 30; margin-top: 4px; max-height: 230px; overflow-y: auto; background: #10241b; border: 1px solid rgba(212,175,55,0.45); border-radius: 10px; box-shadow: 0 14px 34px rgba(0,0,0,0.5); padding: 0.25rem; }
                                                .msel-sup-opt { padding: 0.5rem 0.8rem; border-radius: 8px; cursor: pointer; color: var(--white); font-size: 0.88rem; }
                                                .msel-sup-opt:hover, .msel-sup-opt.is-actif { background: rgba(212,175,55,0.18); }
                                                .msel-sup-vide { padding: 0.7rem; text-align: center; color: var(--text-muted); font-size: 0.85rem; }
                                            </style>
                                            <script>
                                                (function () {
                                                    var MEMBRES = <?php echo json_encode($pairesMembres, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
                                                    function norm(s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
                                                    function remplir(box, q) {
                                                        var panel = box.querySelector('.msel-sup-panel');
                                                        var ex = (box.getAttribute('data-exclus') || '').split(',').filter(Boolean).map(Number);
                                                        var qn = norm(q.trim()), n = 0;
                                                        panel.textContent = '';
                                                        for (var i = 0; i < MEMBRES.length && n < 40; i++) {
                                                            var m = MEMBRES[i];
                                                            if (ex.indexOf(m[0]) !== -1) { continue; }
                                                            if (qn && norm(m[1]).indexOf(qn) === -1) { continue; }
                                                            var d = document.createElement('div');
                                                            d.className = 'msel-sup-opt'; d.textContent = m[1]; d.setAttribute('data-id', m[0]);
                                                            panel.appendChild(d); n++;
                                                        }
                                                        if (n === 0) {
                                                            var v = document.createElement('div'); v.className = 'msel-sup-vide'; v.textContent = 'Aucun membre trouvé.'; panel.appendChild(v);
                                                        }
                                                        panel.style.display = 'block';
                                                    }
                                                    function fermerTout(sauf) {
                                                        document.querySelectorAll('.msel-sup-panel').forEach(function (p) { if (p !== sauf) { p.style.display = 'none'; } });
                                                    }
                                                    document.addEventListener('focusin', function (e) {
                                                        if (e.target.classList && e.target.classList.contains('msel-sup-input')) {
                                                            var box = e.target.closest('.msel-sup');
                                                            fermerTout(box.querySelector('.msel-sup-panel'));
                                                            remplir(box, e.target.value);
                                                        }
                                                    });
                                                    document.addEventListener('input', function (e) {
                                                        if (e.target.classList && e.target.classList.contains('msel-sup-input')) {
                                                            var box = e.target.closest('.msel-sup');
                                                            box.querySelector('input[name="membre_id"]').value = '';   // le choix est à refaire
                                                            remplir(box, e.target.value);
                                                        }
                                                    });
                                                    document.addEventListener('click', function (e) {
                                                        var opt = e.target.closest ? e.target.closest('.msel-sup-opt') : null;
                                                        if (opt) {
                                                            var box = opt.closest('.msel-sup');
                                                            box.querySelector('input[name="membre_id"]').value = opt.getAttribute('data-id');
                                                            box.querySelector('.msel-sup-input').value = opt.textContent;
                                                            box.querySelector('.msel-sup-panel').style.display = 'none';
                                                            return;
                                                        }
                                                        if (!(e.target.closest && e.target.closest('.msel-sup'))) { fermerTout(null); }
                                                    });
                                                    document.addEventListener('submit', function (e) {
                                                        var box = e.target.querySelector ? e.target.querySelector('.msel-sup') : null;
                                                        if (box && !box.querySelector('input[name="membre_id"]').value) {
                                                            e.preventDefault();
                                                            var inp = box.querySelector('.msel-sup-input'); inp.focus();
                                                        }
                                                    });
                                                })();
                                            </script>
                                            <?php endif; ?>
                                            <?php $exclusSup = array_merge(array_keys($nomsMembres), $deLaSous); ?>
                                            <div class="form-group msel-sup" data-exclus="<?php echo htmlspecialchars(implode(',', $exclusSup), ENT_QUOTES); ?>" style="flex:1; min-width:200px; margin:0; position:relative;">
                                                <input type="hidden" name="membre_id" value="">
                                                <input type="text" class="form-input msel-sup-input" placeholder="🔍 Rechercher un membre à ajouter…" autocomplete="off">
                                                <div class="msel-sup-panel"></div>
                                            </div>
                                            <button type="submit" class="btn btn-secondary btn-sm">🤝 Ajouter</button>
                                        </form>
                                        <p style="color:var(--text-muted); font-size:0.74rem; margin:0.3rem 0 0;">Un membre support aide la sous-commission sans devenir membre de la commission.</p>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($peutSous): ?>
                                    <form method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-end; margin:0.8rem 0 0;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="set_sous_commission_responsable">
                                        <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                        <input type="hidden" name="sous_commission_id" value="<?php echo (int) $sc['id']; ?>">
                                        <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                                            <label class="form-label">🎖️ Responsable de la sous-commission</label>
                                            <select name="membre_id" class="form-input" style="color-scheme:dark;">
                                                <option value="0">— Aucun —</option>
                                                <?php foreach ($membersOfC as $mm): ?><option value="<?php echo (int) $mm['id']; ?>"<?php echo ((int) ($sc['resp'] ?? 0)) === (int) $mm['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--gold); color:var(--gold);">💾 Enregistrer</button>
                                    </form>
                                    <div style="margin:0.8rem 0 0;"><button type="button" class="btn btn-primary btn-sm" onclick="smOpen(<?php echo (int) $sc['id']; ?>)">👥 Gérer les membres</button></div>
                                    <form method="POST" style="margin:0.5rem 0 0;" data-confirm="Supprimer la sous-commission « <?php echo htmlspecialchars($sc['nom'], ENT_QUOTES); ?> » ? Les membres restent dans la commission." data-confirm-danger="1">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_sous_commission">
                                        <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                        <input type="hidden" name="sous_commission_id" value="<?php echo (int) $sc['id']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;">🗑️ Supprimer la sous-commission</button>
                                    </form>
                                    <?php endif; ?>
                                </details>
                            <?php endforeach; ?>
                            <?php if ($peutSous): ?>
                            <form method="POST" data-section="sous-ajout" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-end; margin-top:0.8rem; padding:1rem 1.1rem; border:1px solid rgba(212,175,55,0.45); border-radius:10px; background:rgba(255,255,255,0.03);">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="create_sous_commission">
                                <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                                    <label class="form-label">➕ Nouvelle sous-commission</label>
                                    <input type="text" name="nom" class="form-input" required maxlength="150" placeholder="Ex : Logistique, Accueil…">
                                </div>
                                <div style="flex-basis:100%;">
                                    <label style="display:flex; align-items:center; gap:0.6rem; cursor:pointer; color:var(--white); font-size:0.88rem;">
                                        <input type="checkbox" name="accepte_supports" value="1" onchange="var z = this.closest('form').querySelector('.mpick-zone'); z.style.display = this.checked ? '' : 'none'; if (!this.checked) { z.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; c.dispatchEvent(new Event('change', { bubbles: true })); }); }">
                                        🤝 Autoriser l'ajout de n'importe quel membre (membres supports)
                                    </label>
                                    <p style="color:var(--text-muted); font-size:0.74rem; margin:0.25rem 0 0 3.6rem;">Si activé, vous pouvez ajouter des membres qui ne font pas partie de la commission. Sinon, seuls les membres de la commission peuvent rejoindre la sous-commission.</p>
                                </div>
                                <div class="mpick-zone" style="flex-basis:100%; display:none;">
                                    <?php if (empty($mpickAssets)): $mpickAssets = true; ?>
                                    <style>
                                        .mpick { border: 1px solid var(--glass-border); border-radius: 10px; background: rgba(255,255,255,0.03); overflow: hidden; }
                                        .mpick-search { width: 100%; box-sizing: border-box; border: 0; border-bottom: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); color: var(--white); padding: 0.65rem 0.9rem; font-size: 0.9rem; }
                                        .mpick-search:focus { outline: none; background: rgba(255,255,255,0.08); }
                                        .mpick-list { max-height: 210px; overflow-y: auto; padding: 0.3rem; }
                                        .mpick-item { display: flex; align-items: center; gap: 0.6rem; padding: 0.42rem 0.55rem; border-radius: 8px; cursor: pointer; }
                                        .mpick-item:hover { background: rgba(212,175,55,0.1); }
                                        .mpick-item.is-on { background: rgba(212,175,55,0.14); }
                                        .mpick-nom { flex: 1; color: var(--white); font-size: 0.88rem; }
                                        .mpick-tag { font-size: 0.68rem; font-weight: 700; padding: 0.12rem 0.5rem; border-radius: 12px; border: 1px solid var(--glass-border); color: var(--text-muted); white-space: nowrap; }
                                        .mpick-tag.com { color: #7bd8a6; border-color: rgba(37,211,102,0.4); }
                                        .mpick-tag.sup { color: #8ec5ff; border-color: rgba(45,140,255,0.4); }
                                        .mpick-foot { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; padding: 0.45rem 0.8rem; border-top: 1px solid var(--glass-border); font-size: 0.78rem; color: var(--text-muted); }
                                        .mpick-clear { background: none; border: 0; color: var(--gold); cursor: pointer; font-size: 0.78rem; font-weight: 700; }
                                        .mpick-vide { padding: 0.8rem; text-align: center; color: var(--text-muted); font-size: 0.85rem; display: none; }
                                    </style>
                                    <script>
                                        (function () {
                                            function norm(s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
                                            function maj(box) {
                                                var n = box.querySelectorAll('input[type=checkbox]:checked').length;
                                                box.querySelector('.mpick-count').textContent = n + ' sélectionné' + (n > 1 ? 's' : '');
                                                box.querySelectorAll('.mpick-item').forEach(function (it) { it.classList.toggle('is-on', it.querySelector('input').checked); });
                                            }
                                            document.addEventListener('input', function (e) {
                                                if (!e.target.classList.contains('mpick-search')) { return; }
                                                var box = e.target.closest('.mpick'), q = norm(e.target.value.trim()), vus = 0;
                                                box.querySelectorAll('.mpick-item').forEach(function (it) {
                                                    var ok = q === '' || norm(it.getAttribute('data-nom')).indexOf(q) !== -1;
                                                    it.style.display = ok ? '' : 'none';
                                                    if (ok) { vus++; }
                                                });
                                                box.querySelector('.mpick-vide').style.display = vus === 0 ? 'block' : 'none';
                                            });
                                            document.addEventListener('change', function (e) {
                                                var box = e.target.closest ? e.target.closest('.mpick') : null;
                                                if (box && e.target.type === 'checkbox') { maj(box); }
                                            });
                                            document.addEventListener('click', function (e) {
                                                if (!e.target.classList.contains('mpick-clear')) { return; }
                                                var box = e.target.closest('.mpick');
                                                box.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
                                                maj(box);
                                            });
                                        })();
                                    </script>
                                    <?php endif; ?>
                                    <?php $idsDeLaCom = array_map(function ($m) { return (int) $m['id']; }, $membersOfC);
                                          // membres de la commission déjà dans une sous-commission : non proposés
                                          $dejaSous = [];
                                          foreach ($sousDeC as $oSc) { foreach ($oSc['membres'] as $oMid) { $dejaSous[(int) $oMid] = true; } } ?>
                                    <label class="form-label">👥 Ajouter des membres (facultatif) — n'importe quel membre</label>
                                    <div class="mpick">
                                        <input type="text" class="mpick-search" placeholder="🔍 Filtrer par nom ou prénom…" autocomplete="off">
                                        <div class="mpick-list">
                                            <?php foreach ($allMembers as $am): $amId = (int) $am['id']; $dansCom = in_array($amId, $idsDeLaCom, true); if ($dansCom && isset($dejaSous[$amId])) { continue; } $nomAm = trim($am['prenom'] . ' ' . $am['nom']); ?>
                                                <label class="mpick-item" data-nom="<?php echo htmlspecialchars($nomAm, ENT_QUOTES); ?>">
                                                    <input type="checkbox" name="membre_ids[]" value="<?php echo $amId; ?>">
                                                    <span class="mpick-nom"><?php echo htmlspecialchars($nomAm); ?></span>
                                                    <span class="mpick-tag <?php echo $dansCom ? 'com' : 'sup'; ?>"><?php echo $dansCom ? '👥 commission' : '🤝 support'; ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                            <div class="mpick-vide">Aucun membre ne correspond.</div>
                                        </div>
                                        <div class="mpick-foot"><span class="mpick-count">0 sélectionné</span><button type="button" class="mpick-clear">Tout décocher</button></div>
                                    </div>
                                    <p style="color:var(--text-muted); font-size:0.74rem; margin:0.3rem 0 0;">Les membres de la commission rejoignent la sous-commission ; les autres y entrent comme membres supports (sans devenir membres de la commission).</p>
                                </div>
                                <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">Créer</button>
                            </form>
                            <?php endif; ?>
                            </div>
                            </details>
                        </div>
                        <?php endif; ?>

                        <?php
                            $reusAll = $comReunions[$c['id']] ?? [];
                            $reus = array_values(array_filter($reusAll, function ($x) { return (int) ($x['annule'] ?? 0) !== 1; }));
                            // Une réunion est « terminée » si clôturée ou si son horaire est passé ; sinon en cours ou à venir
                            $reusTerminees = [];
                            $reusAClore = [];
                            $reusPubliees = [];
                            $reusNonPubliees = [];
                            foreach ($reus as $rx) {
                                if ((int) ($rx['cloture'] ?? 0) === 1) { $reusTerminees[] = $rx; continue; }
                                // Horaire dépassé mais pas encore clôturée : reste visible (à clôturer / compte rendu)
                                $passee = dahira_event_etat((string) $rx['date_reunion'], (string) ($rx['heure'] ?? ''), (string) ($rx['heure_fin'] ?? '')) === 'termine';
                                if ($passee) { $reusAClore[] = $rx; }
                                elseif ((int) ($rx['publie'] ?? 0) === 1) { $reusPubliees[] = $rx; }
                                else { $reusNonPubliees[] = $rx; }
                            }
                            // Groupes par statut : publiées et non publiées (non affichées aux membres) ouverts par défaut
                            $htmlCloturees = '';
                            $blocsReunions = [
                                ['titre' => '🚀 Publiées — en cours ou à venir', 'liste' => $reusPubliees, 'open' => true, 'vide' => "Aucune réunion publiée en cours ou à venir."],
                                ['titre' => '⏳ Non publiées — masquées aux membres', 'liste' => $reusNonPubliees, 'open' => true, 'vide' => ''],
                                ['titre' => '⏰ Passées — à clôturer', 'liste' => $reusAClore, 'open' => true, 'vide' => ''],
                                ['titre' => '✅ Clôturées', 'liste' => $reusTerminees, 'open' => false, 'vide' => '', 'bas' => true],
                            ];
                            $reusAnn = array_values(array_filter($reusAll, function ($x) { return (int) ($x['annule'] ?? 0) === 1; }));
                        ?>
                        <div style="border-top:1px solid var(--glass-border); padding-top:1rem; margin-top:1rem;">
                            <div class="com-sub" style="margin-top:0;">📅 Réunions de la commission</div>
                            <?php if (empty($reus)): ?>
                                <p class="km-empty">Aucune réunion pour le moment.</p>
                            <?php else: foreach ($blocsReunions as $bloc): ?>
                                <?php if (empty($bloc['liste'])): ?>
                                    <?php if ($bloc['vide'] !== ''): ?><p class="km-empty"><?php echo htmlspecialchars($bloc['vide']); ?></p><?php endif; ?>
                                <?php else: $reus = $bloc['liste']; if (!empty($bloc['bas'])) { ob_start(); } ?>
                                <details class="com-reu-group" style="margin-bottom:0.75rem;"<?php echo $bloc['open'] ? ' open' : ''; ?>>
                                    <summary style="cursor:pointer; color:var(--white); font-weight:700; font-size:0.9rem; list-style:none; display:flex; align-items:center; gap:0.4rem; margin-bottom:0.5rem;"><?php echo $bloc['titre']; ?> <span style="color:var(--text-muted); font-weight:600;">(<?php echo count($bloc['liste']); ?>)</span> <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                                <?php
                                    // Regrouper par série périodique (serie_id), en conservant l'ordre
                                    $groupes = [];
                                    foreach ($reus as $r) {
                                        $k = (int) ($r['serie_id'] ?: $r['id']);
                                        $groupes[$k][] = $r;
                                    }
                                ?>
                                <div style="display:flex; flex-direction:column; gap:0.75rem; margin-bottom:0.75rem;">
                                <?php foreach ($groupes as $grp): ?>
                                    <?php if (count($grp) <= 1): ?>
                                        <?php echo $renderReunionItem($grp[0], true); ?>
                                    <?php else: ?>
                                        <?php
                                            $periodes = $grp;
                                            usort($periodes, function ($a, $b) { return strcmp($a['date_reunion'], $b['date_reunion']); });
                                            $first = $periodes[0];
                                            $dmin = date('d/m/Y', strtotime($periodes[0]['date_reunion']));
                                            $dmax = date('d/m/Y', strtotime($periodes[count($periodes) - 1]['date_reunion']));
                                        ?>
                                        <div style="border:1px solid rgba(212,175,55,0.35); border-radius:10px; padding:0.8rem 0.9rem; background:rgba(212,175,55,0.05);">
                                            <div style="display:flex; justify-content:space-between; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                                                <div style="color:var(--gold); font-weight:800;">🔁 <?php echo htmlspecialchars($first['titre']); ?></div>
                                                <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                                                    <span class="badge" style="background:rgba(212,175,55,0.15); color:var(--gold); border:1px solid rgba(212,175,55,0.4);"><?php echo count($periodes); ?> réunions · <?php echo htmlspecialchars(reunion_periodicite_label($first['periodicite'])); ?></span>
                                                    <form method="POST" style="margin:0;" data-confirm="Supprimer TOUTE la série « <?php echo htmlspecialchars(addslashes($first['titre']), ENT_QUOTES); ?> » (<?php echo count($periodes); ?> réunions) et tout leur suivi ? Action irréversible." data-confirm-danger="1">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="delete_reunion_serie">
                                                        <input type="hidden" name="reunion_id" value="<?php echo (int) $first['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">🗑️ Supprimer la série</button>
                                                    </form>
                                                </div>
                                            </div>
                                            <div style="color:var(--text-muted); font-size:0.8rem; margin:0.3rem 0 0.6rem;">🗓️ du <?php echo $dmin; ?> au <?php echo $dmax; ?></div>
                                            <?php
                                                // Prochaine réunion = 1re période non clôturée (sinon la dernière)
                                                $idxProchain = null;
                                                foreach ($periodes as $i => $p) { if ((int) $p['cloture'] !== 1) { $idxProchain = $i; break; } }
                                                if ($idxProchain === null) { $idxProchain = count($periodes) - 1; }
                                                $prochain = $periodes[$idxProchain];
                                                $autres = [];
                                                foreach ($periodes as $i => $p) { if ($i !== $idxProchain) { $autres[] = $p; } }
                                            ?>
                                            <div style="color:var(--text-muted); font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.35rem;">⏭️ Prochaine réunion</div>
                                            <?php echo $renderReunionItem($prochain, false); ?>
                                            <?php if (!empty($autres)): ?>
                                            <details class="com-reu-group" style="margin-top:0.6rem;">
                                                <summary style="cursor:pointer; color:var(--gold); font-weight:700; font-size:0.85rem; list-style:none; display:flex; align-items:center; gap:0.4rem;">📂 Voir les <?php echo count($autres); ?> autre(s) réunion(s) <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                                                <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:0.5rem;">
                                                <?php foreach ($autres as $r): ?>
                                                    <?php echo $renderReunionItem($r, false); ?>
                                                <?php endforeach; ?>
                                                </div>
                                            </details>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                </div>
                                </details>
                                <?php if (!empty($bloc['bas'])) { $htmlCloturees = ob_get_clean(); } ?>
                                <?php endif; ?>
                            <?php endforeach; endif; ?>
                            <?php if (!empty($reusAnn)): ?>
                            <details class="com-reu-group" style="margin-bottom:0.75rem;">
                                <summary style="cursor:pointer; color:#fca5a5; font-weight:700; font-size:0.85rem; list-style:none; display:flex; align-items:center; gap:0.4rem;">🚫 Réunions annulées <span style="color:var(--text-muted); font-weight:600;">(<?php echo count($reusAnn); ?>)</span> <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                                <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:0.5rem;">
                                    <?php foreach ($reusAnn as $r): ?>
                                        <?php echo $renderReunionItem($r, true); ?>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <?php endif; ?>
                            <details data-section="creer-reunion" style="border:1px solid var(--glass-border); border-radius:10px; padding:0.85rem 1rem; background:rgba(255,255,255,0.02); margin-top:0.5rem;">
                                <summary style="cursor:pointer; color:var(--gold); font-weight:700; font-size:0.9rem;">➕ Créer une réunion</summary>
                                <form method="POST" class="reunion-create-form" style="margin-top:0.8rem; display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:0.7rem;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="create_reunion">
                                    <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                    <div class="form-group" style="grid-column:1 / -1; margin:0;"><label class="form-label">Titre <span style="color:var(--danger)">*</span></label><input type="text" name="titre" class="form-input" required placeholder="Ex: Réunion mensuelle de la commission"></div>
                                    <div class="form-group" style="grid-column:1 / -1; margin:0;"><label class="form-label">Ordre du jour</label><textarea name="description" class="form-input" rows="2"></textarea></div>
                                    <div class="form-group r-fixe" style="margin:0;"><label class="form-label">Date <span style="color:var(--danger)">*</span></label><input type="date" name="date_debut" class="form-input" required min="<?php echo date('Y-m-d'); ?>" style="width:100%; max-width:100%; box-sizing:border-box;"></div>
                                    <div class="form-group r-fixe" style="margin:0;"><label class="form-label">Heure de début <span style="color:var(--danger)">*</span></label><select name="heure" class="form-input modern-select" required><option value="">— Heure —</option><?php for ($hh = 7; $hh <= 23; $hh++): foreach (['00', '30'] as $mm): $hv = sprintf('%02d:%s', $hh, $mm); ?><option value="<?php echo $hv; ?>"><?php echo $hv; ?></option><?php endforeach; endfor; ?></select></div>
                                    <div class="form-group r-fixe" style="margin:0;"><label class="form-label">Heure de fin <span style="color:var(--danger)">*</span></label><select name="heure_fin" class="form-input modern-select" required><option value="">— Heure —</option><?php for ($hh = 7; $hh <= 23; $hh++): foreach (['00', '30'] as $mm): $hv = sprintf('%02d:%s', $hh, $mm); ?><option value="<?php echo $hv; ?>"><?php echo $hv; ?></option><?php endforeach; endfor; ?></select></div>
                                    <div class="form-group" style="margin:0;"><label class="form-label">Mode <span style="color:var(--danger)">*</span></label><select name="mode" class="form-input modern-select r-mode" required><option value="">— Choisir —</option><option value="distance">💻 À distance (Zoom)</option><option value="presentiel">🏛️ Présentiel</option></select></div>
                                    <div class="form-group r-lien" style="grid-column:1 / -1; margin:0; display:none;"><label class="form-label">🔗 Lien Zoom (réunion à distance) <span style="color:var(--danger)">*</span></label><input type="url" name="lien" class="form-input r-lien-input" value="<?php echo htmlspecialchars($comZoom[(int) $c['id']] ?? $reunionZoomLien); ?>" placeholder="https://us06web.zoom.us/..."><p style="color:var(--text-muted); font-size:0.75rem; margin:0.3rem 0 0;">Pré-rempli avec le lien du Guddi Àjjuma — modifiable.</p></div>
                                    <div class="form-group r-lieu" style="grid-column:1 / -1; margin:0; display:none;"><label class="form-label">📍 Adresse (présentiel) <span style="color:var(--danger)">*</span></label><input type="text" name="lieu" class="form-input r-lieu-input" value="<?php echo htmlspecialchars($reunionLieuDefaut); ?>"><p style="color:var(--text-muted); font-size:0.75rem; margin:0.3rem 0 0;">Pré-rempli avec l'adresse du Dahira — modifiable.</p></div>
                                    <div class="form-group" style="grid-column:1 / -1; margin:0;">
                                        <label class="form-label" style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;"><input type="checkbox" name="periodique" value="1" class="r-periodique"> 🔁 Réunion périodique</label>
                                    </div>
                                    <div class="form-group" style="grid-column:1 / -1; margin:0;">
                                        <label class="form-label" style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;"><input type="checkbox" name="sondage" value="1" class="r-sondage"> 📊 Sondage de date et d'horaire</label>
                                        <p style="color:var(--text-muted); font-size:0.75rem; margin:0.25rem 0 0;">Proposez plusieurs dates : les membres votent via un lien (partagé par WhatsApp), puis vous validez une date avant de publier la réunion.</p>
                                    </div>
                                    <div class="r-sondage-fields" style="grid-column:1 / -1; display:none;">
                                        <div style="color:var(--text-muted); font-size:0.8rem; margin-bottom:0.4rem;">Dates proposées (2 propositions, ajoutez-en d'autres si besoin) — date, heure de début et heure de fin</div>
                                        <?php for ($so = 1; $so <= 2; $so++): ?>
                                        <div class="r-sond-row" style="display:<?php echo $so <= 2 ? 'grid' : 'none'; ?>; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:0.5rem; margin-bottom:0.45rem;">
                                            <input type="date" name="opt_date[]" class="form-input" min="<?php echo date('Y-m-d'); ?>" style="width:100%; max-width:100%; box-sizing:border-box;" aria-label="Date proposée <?php echo $so; ?>">
                                            <select name="opt_heure[]" class="form-input" style="color-scheme:dark;" aria-label="Heure de début <?php echo $so; ?>"><option value="">— Début —</option><?php for ($hh = 7; $hh <= 23; $hh++): foreach (['00', '30'] as $mm): $hv = sprintf('%02d:%s', $hh, $mm); ?><option value="<?php echo $hv; ?>"><?php echo $hv; ?></option><?php endforeach; endfor; ?></select>
                                            <select name="opt_fin[]" class="form-input" style="color-scheme:dark;" aria-label="Heure de fin <?php echo $so; ?>"><option value="">— Fin —</option><?php for ($hh = 7; $hh <= 23; $hh++): foreach (['00', '30'] as $mm): $hv = sprintf('%02d:%s', $hh, $mm); ?><option value="<?php echo $hv; ?>"><?php echo $hv; ?></option><?php endforeach; endfor; ?></select>
                                        </div>
                                        <?php endfor; ?>
                                        <button type="button" class="btn btn-secondary btn-sm r-sond-add" style="border-color:var(--gold); color:var(--gold);">➕ Ajouter une proposition</button>
                                    </div>
                                    <div class="form-group r-per-fields" style="margin:0; display:none;"><label class="form-label">Périodicité</label><select name="periodicite" class="form-input modern-select"><option value="hebdomadaire">Hebdomadaire</option><option value="quinzaine">Une semaine sur deux</option><option value="mensuelle">Mensuelle</option></select></div>
                                    <div class="form-group r-per-fields" style="margin:0; display:none;"><label class="form-label">Date de fin</label><input type="date" name="date_fin" class="form-input" min="<?php echo date('Y-m-d'); ?>" style="width:100%; max-width:100%; box-sizing:border-box;"></div>
                                    <div style="grid-column:1 / -1;"><button type="submit" class="btn btn-primary btn-sm">💾 Créer la réunion</button></div>
                                </form>
                            </details>
                            <?php echo $htmlCloturees ?? ''; ?>
                            <?php if (com_can_manage((int) $c['id'], $__isAdmin, $__managedCommissions)): ?>
                            <details data-section="parametres" class="com-reu-group" style="margin:0.5rem 0;">
                            <summary style="cursor:pointer; color:var(--gold); font-weight:700; font-size:0.9rem; list-style:none; display:flex; align-items:center; gap:0.4rem;">⚙️ Paramètres <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                            <form method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-end; margin:0.6rem 0 0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="set_commission_zoom">
                                <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                <div class="form-group" style="flex:1; min-width:220px; margin:0;">
                                    <label class="form-label">🔗 Lien Zoom par défaut de la commission</label>
                                    <input type="url" name="lien_zoom" class="form-input" value="<?php echo htmlspecialchars($comZoom[(int) $c['id']] ?? ''); ?>" placeholder="https://us06web.zoom.us/...">
                                </div>
                                <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">💾 Enregistrer</button>
                            </form>
                            <form method="POST" class="mg-add" style="margin:0.8rem 0 0;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="set_commission_dispo">
                                <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                <label style="display:flex; align-items:center; gap:0.8rem; cursor:pointer; color:var(--white);">
                                    <input type="checkbox" class="checkbox-switch" name="dispo_active" value="1"<?php echo ($comDispo[(int) $c['id']] ?? true) ? ' checked' : ''; ?> onchange="this.form.submit()">
                                    <span><strong>🗓️ Disponibilités des membres</strong><br><span style="color:var(--text-muted); font-size:0.8rem;">Les membres de la commission indiquent leur disponibilité pour le prochain Dahira (menu « Mes disponibilités »).</span></span>
                                </label>
                            </form>
                            </details>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($mgInvolved[(int) $c['id']]) || com_can_manage((int) $c['id'], $__isAdmin, $__managedCommissions)):
                            $mgTaches = $mgTachesParCom[(int) $c['id']] ?? [];
                            $mgFin = $mgFinParCom[(int) $c['id']] ?? ['lignes' => [], 'rec' => 0.0, 'dep' => 0.0];
                            $mgPeutAjouter = com_can_manage((int) $c['id'], $__isAdmin, $__managedCommissions);
                            $mgStatuts = magal_statuts_tache();
                            $mgNbFait = count(array_filter($mgTaches, function ($t) { return $t['statut'] === 'fait'; }));
                        ?>
                        <div style="border-top:1px solid var(--glass-border); padding-top:1rem; margin-top:1rem;">
                            <details data-section="magal" class="com-reu-group">
                                <summary class="com-sub" style="cursor:pointer; list-style:none; display:flex; align-items:center; gap:0.4rem; margin-top:0;">🕌 Wadial Magal <?php echo (int) $mgAnneeCourante; ?> <span style="color:var(--text-muted); font-weight:600;">(<?php echo $mgNbFait; ?>/<?php echo count($mgTaches); ?> tâche(s) faite(s))</span> <span class="com-reu-chevron" style="transition:transform 0.2s;">▸</span></summary>
                                <?php if (!isset($mgInvolved[(int) $c['id']])): ?>
                                    <p style="color:var(--text-muted); font-size:0.82rem; margin:0.6rem 0 0;">ℹ️ Votre commission n'est pas encore inscrite au Wadial Magal : en ajoutant un besoin ou une tâche, elle y sera inscrite automatiquement.</p>
                                <?php endif; ?>

                                <?php
                                    $mgBesoins = ($mgBesoinsParCom ?? [])[(int) $c['id']] ?? [];
                                    $mgStatutsB = magal_statuts_besoin();
                                    $mgNbObtenus = count(array_filter($mgBesoins, function ($b) { return $b['statut'] === 'obtenu'; }));
                                ?>
                                <details class="mg-grp"><summary>🧰 Liste de besoins de la commission <span class="mg-n">(<?php echo $mgNbObtenus; ?>/<?php echo count($mgBesoins); ?> obtenu(s))</span></summary><div class="mg-body">
                                <?php if (empty($mgBesoins)): ?>
                                    <p class="km-empty">Aucun besoin défini pour le moment.</p>
                                <?php endif; ?>
                                <?php foreach ($mgBesoins as $mgb): ?>
                                    <div class="mg-item mg-st-<?php echo htmlspecialchars((string) $mgb['statut']); ?>">
                                        <div style="flex:1; min-width:160px;">
                                            <strong<?php echo $mgb['statut'] === 'obtenu' ? ' style="text-decoration:line-through; opacity:0.6;"' : ''; ?>><?php echo htmlspecialchars($mgb['libelle']); ?></strong>
                                            <?php if (!empty($mgb['quantite'])): ?><div style="color:var(--text-muted); font-size:0.78rem;">Quantité : <?php echo htmlspecialchars($mgb['quantite']); ?></div><?php endif; ?>
                                        </div>
                                        <?php if ($mgPeutAjouter): ?>
                                        <form method="POST" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="magal_set_besoin_statut">
                                            <input type="hidden" name="besoin_id" value="<?php echo (int) $mgb['id']; ?>">
                                            <select name="statut" class="form-input" style="padding:0.25rem 0.5rem; font-size:0.82rem; color-scheme:dark;" onchange="this.form.submit()">
                                                <?php foreach ($mgStatutsB as $mgk => $mgl): ?><option value="<?php echo $mgk; ?>"<?php echo $mgb['statut'] === $mgk ? ' selected' : ''; ?>><?php echo htmlspecialchars($mgl); ?></option><?php endforeach; ?>
                                            </select>
                                        </form>
                                        <form method="POST" style="margin:0;" data-confirm="Supprimer ce besoin de la liste ?" data-confirm-danger="1">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="magal_delete_besoin">
                                            <input type="hidden" name="besoin_id" value="<?php echo (int) $mgb['id']; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;" title="Supprimer">🗑️</button>
                                        </form>
                                        <?php else: ?>
                                            <span class="badge badge-approved"><?php echo htmlspecialchars($mgStatutsB[$mgb['statut']] ?? $mgb['statut']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if ($mgPeutAjouter): ?>
                                <form method="POST" class="mg-add" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-end; margin:0.4rem 0 0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="magal_add_besoin">
                                    <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                    <div class="form-group" style="flex:2; min-width:200px; margin:0;"><label class="form-label">➕ Nouveau besoin</label><input type="text" name="libelle" class="form-input" required maxlength="255" placeholder="Ex : 20 tables, sono, vaisselle…"></div>
                                    <div class="form-group" style="flex:1; min-width:110px; margin:0;"><label class="form-label">Quantité</label><input type="text" name="quantite" class="form-input" maxlength="60" placeholder="Ex : 20"></div>
                                    <button type="submit" class="btn btn-primary btn-sm">➕ Ajouter</button>
                                </form>
                                <?php endif; ?>
                                </div></details>
                                <details class="mg-grp"><summary>✅ Tâches de la commission <span class="mg-n">(<?php echo count($mgTaches); ?>)</span></summary><div class="mg-body">
                                <?php if (empty($mgTaches)): ?>
                                    <p class="km-empty">Aucune tâche pour le moment.</p>
                                <?php endif; ?>
                                <?php foreach ($mgTaches as $mgt):
                                    $mgRetard = $mgt['statut'] !== 'fait' && !empty($mgt['echeance']) && $mgt['echeance'] < date('Y-m-d');
                                ?>
                                    <div class="mg-item mg-st-<?php echo htmlspecialchars((string) $mgt['statut']); ?><?php echo $mgRetard ? ' mg-late' : ''; ?>">
                                        <div style="flex:1; min-width:160px;">
                                            <strong<?php echo $mgt['statut'] === 'fait' ? ' style="text-decoration:line-through; opacity:0.6;"' : ''; ?>><?php echo htmlspecialchars($mgt['titre']); ?></strong>
                                            <div style="color:var(--text-muted); font-size:0.78rem;">
                                                👤 <?php echo trim((string) $mgt['assignee_nom']) !== '' ? htmlspecialchars($mgt['assignee_nom']) : 'Non attribuée'; ?>
                                                <?php if (!empty($mgt['echeance'])): ?> · 📅 <span<?php echo $mgRetard ? ' style="color:#fca5a5; font-weight:700;"' : ''; ?>><?php echo date('d/m/Y', strtotime($mgt['echeance'])); ?><?php echo $mgRetard ? ' (en retard)' : ''; ?></span><?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($mgPeutAjouter): ?>
                                        <form method="POST" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="magal_set_tache_statut">
                                            <input type="hidden" name="tache_id" value="<?php echo (int) $mgt['id']; ?>">
                                            <select name="statut" class="form-input" style="padding:0.25rem 0.5rem; font-size:0.82rem; color-scheme:dark;" onchange="this.form.submit()">
                                                <?php foreach ($mgStatuts as $mgk => $mgl): ?><option value="<?php echo $mgk; ?>"<?php echo $mgt['statut'] === $mgk ? ' selected' : ''; ?>><?php echo htmlspecialchars($mgl); ?></option><?php endforeach; ?>
                                            </select>
                                        </form>
                                        <?php else: ?>
                                            <span class="badge badge-approved"><?php echo htmlspecialchars($mgStatuts[$mgt['statut']] ?? $mgt['statut']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ($mgPeutAjouter): ?>
                                <form method="POST" class="mg-add" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:0.5rem; align-items:end; margin:0.6rem 0 0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="magal_add_tache">
                                    <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                    <div class="form-group" style="grid-column:1 / -1; margin:0;"><label class="form-label">➕ Nouvelle tâche Wadial Magal</label><input type="text" name="titre" class="form-input" required maxlength="255" placeholder="Ex : Réserver la salle"></div>
                                    <div class="form-group" style="margin:0;"><label class="form-label">Responsable</label>
                                        <select name="assignee_id" class="form-input">
                                            <option value="0">— Non attribuée —</option>
                                            <?php foreach ($membersOfC as $mm): ?><option value="<?php echo (int) $mm['id']; ?>"><?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin:0;"><label class="form-label">Échéance</label><input type="date" name="echeance" class="form-input" style="width:100%; max-width:100%; box-sizing:border-box;"></div>
                                    <div class="form-group" style="grid-column:1 / -1; margin:0;"><label class="form-label">Détails (facultatif)</label><input type="text" name="description" class="form-input" placeholder="Précisions"></div>
                                    <div style="grid-column:1 / -1;"><button type="submit" class="btn btn-primary btn-sm">➕ Ajouter la tâche</button></div>
                                </form>
                                <?php endif; ?>

                                </div></details>
                                <details class="mg-grp"><summary>💶 Finance de la commission</summary><div class="mg-body">
                                <?php $mgSolde = $mgFin['rec'] - $mgFin['dep']; ?>
                                <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.5rem;">
                                    <span class="badge" style="background:rgba(37,211,102,0.15); color:#7bd8a6; border:1px solid rgba(37,211,102,0.4);">📥 Recettes : <?php echo number_format($mgFin['rec'], 2, ',', ' '); ?> €</span>
                                    <span class="badge" style="background:rgba(191,33,33,0.15); color:#fca5a5; border:1px solid rgba(191,33,33,0.45);">📤 Dépenses : <?php echo number_format($mgFin['dep'], 2, ',', ' '); ?> €</span>
                                    <span class="badge" style="background:rgba(255,193,7,0.15); color:#ffd873; border:1px solid rgba(255,193,7,0.4);">⚖️ Solde : <?php echo number_format($mgSolde, 2, ',', ' '); ?> €</span>
                                </div>
                                <?php if (empty($mgFin['lignes'])): ?>
                                    <p class="km-empty">Aucune opération financière enregistrée pour cette commission.</p>
                                <?php else: foreach ($mgFin['lignes'] as $mgl): $mgRec = $mgl['type'] === 'recette'; ?>
                                    <div class="mg-item <?php echo $mgRec ? 'mg-st-fait' : 'mg-st-a_trouver'; ?>" style="justify-content:space-between;">
                                        <div>
                                            <strong><?php echo $mgRec ? '📥' : '📤'; ?> <?php echo htmlspecialchars($mgl['libelle']); ?></strong>
                                            <div style="color:var(--text-muted); font-size:0.78rem;"><?php echo !empty($mgl['date_op']) ? date('d/m/Y', strtotime($mgl['date_op'])) : date('d/m/Y', strtotime($mgl['created_at'])); ?><?php echo !empty($mgl['note']) ? ' · ' . htmlspecialchars($mgl['note']) : ''; ?></div>
                                        </div>
                                        <span style="font-weight:700; color:<?php echo $mgRec ? '#7bd8a6' : '#fca5a5'; ?>;"><?php echo $mgRec ? '+' : '−'; ?><?php echo number_format((float) $mgl['montant'], 2, ',', ' '); ?> €</span>
                                    </div>
                                <?php endforeach; endif; ?>
                                </div></details>
                                <p style="margin:0.6rem 0 0;"><a href="magal.php?annee=<?php echo (int) $mgAnneeCourante; ?>&tab=taches" class="btn btn-secondary btn-sm" style="border-color:var(--gold); color:var(--gold);">🕌 Ouvrir le Wadial Magal</a></p>
                            </details>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="empty-state" id="com-noresult" style="display:none;"><div class="empty-state-icon">🔍</div><p>Aucune commission ne correspond à votre recherche.</p></div>
            <?php endif; ?>
        </div>

            </div>
        </div>
    </main>

    <!-- Sélecteur à deux listes : membres ou responsables d'une commission -->
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
        var TL_ALL = <?php echo json_encode($membersJs, JSON_UNESCAPED_UNICODE); ?>;
        var COM_MEMBERS = <?php echo json_encode((object) $comMembersMap, JSON_UNESCAPED_UNICODE); ?>;
        var COM_MANAGERS = <?php echo json_encode((object) $comManagersMap, JSON_UNESCAPED_UNICODE); ?>;
        var tlCid = null, tlMode = 'members';
        var tlSelected = new Set();
        var TL_CSRF = (document.querySelector('#tl-form input[name=csrf_token]') || {}).value || '';
        var TL_BYID = {};
        TL_ALL.forEach(function (m) { TL_BYID[m.id] = m; });
        function getMap(mode){ return (mode === 'managers') ? COM_MANAGERS : COM_MEMBERS; }
        function tlNorm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
        function tlEsc(s){ var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }
        function tlAvatar(m){ return m.photo ? '<img class="tl-av" src="uploads/' + encodeURIComponent(m.photo) + '" alt="">' : '<span class="tl-av tl-av--ph">' + tlEsc(m.ini) + '</span>'; }
        // Persiste immédiatement un ajout/retrait (sans rechargement) et met à jour la carte.
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
                el.innerHTML = '<p class="km-empty">' + (mode === 'managers' ? 'Aucun responsable désigné.' : 'Aucun membre dans cette commission.') + '</p>';
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
                ? (add ? 'add_commission_manager' : 'remove_commission_manager')
                : (add ? 'add_commission_member' : 'remove_commission_member');
            var params = new URLSearchParams();
            params.append('ajax', '1');
            params.append('action', action);
            params.append('commission_id', cid);
            params.append('membre_id', mid);
            params.append('csrf_token', TL_CSRF);
            return fetch('commission_dashboard.php', {
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
            // Responsables : uniquement parmi les membres de la commission
            var deLaCom = (COM_MEMBERS[tlCid] || []).map(Number);
            (tlMode === 'managers' ? TL_ALL.filter(function (m) { return deLaCom.indexOf(m.id) > -1; }) : TL_ALL).forEach(function (m) {
                if (tlSelected.has(m.id)) {
                    se.insertAdjacentHTML('beforeend', '<div class="tl-item"><span>' + tlAvatar(m) + '</span><span class="tl-name">' + tlEsc(m.name) + '</span><button type="button" class="tl-rem-btn" onclick="tlRemove(' + m.id + ')" title="Retirer">&times;</button></div>');
                    ns++;
                } else {
                    if (term && tlNorm(m.name).indexOf(term) === -1) return;
                    av.insertAdjacentHTML('beforeend', '<div class="tl-item"><span>' + tlAvatar(m) + '</span><span class="tl-name">' + tlEsc(m.name) + '</span><button type="button" class="tl-add-btn" onclick="tlAdd(' + m.id + ')" title="Ajouter">+</button></div>');
                    na++;
                }
            });
            if (!na) av.innerHTML = '<div class="tl-empty">' + (tlMode === 'managers' ? 'Aucun autre membre de la commission : ajoutez d\'abord des membres.' : 'Aucun membre disponible.') + '</div>';
            if (!ns) se.innerHTML = '<div class="tl-empty">Aucun membre sélectionné.</div>';
            document.getElementById('tl-sel-count').textContent = ns;
            document.getElementById('tl-avail-count').textContent = na + ' disponible(s)';
        }
        function tlAdd(id){
            var map = getMap(tlMode);
            if (!map[tlCid]) { map[tlCid] = []; }
            if (map[tlCid].map(Number).indexOf(id) === -1) { map[tlCid].push(id); }
            tlSelected.add(id); tlRender(); updateCard(tlCid, tlMode);
            var mirroredMembers = false;
            comPersist(tlMode, tlCid, id, true).catch(function (e) {
                var arr = map[tlCid]; var i = arr.map(Number).indexOf(id); if (i > -1) { arr.splice(i, 1); }
                tlSelected.delete(id); tlRender(); updateCard(tlCid, tlMode);
                modernAlert("Échec de l'ajout : " + e.message, 'Erreur');
            });
        }
        function tlRemove(id){
            var map = getMap(tlMode);
            var arr = map[tlCid] || []; var i = arr.map(Number).indexOf(id); if (i > -1) { arr.splice(i, 1); }
            if (tlMode === 'members') { var mg = COM_MANAGERS[tlCid] || []; var gi = mg.map(Number).indexOf(id); if (gi > -1) { mg.splice(gi, 1); updateCard(tlCid, 'managers'); } } // un responsable retiré de la commission n'en est plus responsable
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

        // Filtre des commissions par nom
        (function () {
            var input = document.getElementById('com-search');
            if (!input) return;
            var sel = document.getElementById('com-select');
            var cards = Array.prototype.slice.call(document.querySelectorAll('.com-filterable'));
            var noRes = document.getElementById('com-noresult');
            var countEl = document.getElementById('com-count');
            function norm(s){ return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim(); }
            // Restaurer le filtre mémorisé (conservé après une actualisation)
            try {
                var savedT = localStorage.getItem('com_filter_search');
                var savedS = localStorage.getItem('com_filter_select');
                if (savedT !== null) { input.value = savedT; }
                if (sel && savedS !== null) { sel.value = savedS; }
            } catch (e) {}
            function apply() {
                var term = norm(input.value);
                var selVal = sel ? sel.value : '';
                try {
                    localStorage.setItem('com_filter_search', input.value);
                    if (sel) { localStorage.setItem('com_filter_select', sel.value); }
                } catch (e) {}
                var n = 0;
                cards.forEach(function (c) {
                    var ds = c.getAttribute('data-search');
                    var show = (!term || norm(ds).indexOf(term) !== -1) && (!selVal || ds === selVal);
                    c.style.display = show ? '' : 'none';
                    if (show) n++;
                });
                if (noRes) noRes.style.display = n === 0 ? 'block' : 'none';
                if (countEl) countEl.textContent = n + ' commission(s)';
            }
            input.addEventListener('input', apply);
            if (sel) sel.addEventListener('change', apply);
            apply();
        })();
    </script>

    <!-- Sélecteur à deux listes : membres d'une sous-commission -->
    <div id="sub-members-modal">
        <div class="tl-card">
            <div class="tl-head">
                <h3 id="sm-title">Membres de la sous-commission</h3>
                <button type="button" class="tl-x" onclick="smClose()" aria-label="Fermer">&times;</button>
            </div>
            <div id="sm-note" style="padding:0.55rem 1.25rem; font-size:0.8rem; color:var(--text-muted); border-bottom:1px solid rgba(255,255,255,0.08);"></div>
            <div class="tl-cols">
                <div class="tl-col">
                    <div class="tl-col-head">
                        <div class="tl-title">Membres disponibles</div>
                        <input type="text" id="sm-search" placeholder="🔍 Rechercher un membre…">
                    </div>
                    <div class="tl-list" id="sm-available"></div>
                </div>
                <div class="tl-col">
                    <div class="tl-col-head">
                        <div class="tl-title">Dans la sous-commission (<span id="sm-sel-count">0</span>)</div>
                    </div>
                    <div class="tl-list" id="sm-selected"></div>
                </div>
            </div>
            <div class="tl-foot">
                <span class="tl-count" id="sm-avail-count"></span>
                <button type="button" class="btn btn-primary btn-sm" onclick="smClose()">Terminé</button>
            </div>
        </div>
    </div>
    <script>
        // Membres de chaque sous-commission (membres de la commission + membres supports)
        var SM_SUBS = <?php
            $smData = [];
            foreach ($comSous as $smCid => $smSubs) {
                foreach ($smSubs as $smId => $smSub) {
                    $autresSm = [];
                    foreach ($smSubs as $oId => $oSub) { if ((int) $oId !== (int) $smId) { foreach ($oSub['membres'] as $oMid) { $autresSm[(int) $oMid] = true; } } }
                    $smData[$smId] = ['cid' => (int) $smCid, 'nom' => $smSub['nom'], 'accepte' => (int) ($smSub['accepte'] ?? 0),
                        'members' => array_values(array_map('intval', array_merge($smSub['membres'], $smSub['supports'] ?? []))),
                        'autres' => array_keys($autresSm)];
                }
            }
            echo json_encode((object) $smData, JSON_UNESCAPED_UNICODE);
        ?>;
        var smId = null, smSel = new Set(), smDirty = false;
        function smSub() { return SM_SUBS[smId] || { cid: 0, nom: '', accepte: 0, members: [] }; }
        function smDansCommission(id) {
            var cid = smSub().cid;
            return (COM_MEMBERS[cid] || []).map(Number).indexOf(id) !== -1 || (COM_MANAGERS[cid] || []).map(Number).indexOf(id) !== -1;
        }
        // Un membre de la commission déjà dans une AUTRE sous-commission n'est plus proposé
        function smDansAutre(id) { return smDansCommission(id) && (smSub().autres || []).map(Number).indexOf(id) !== -1; }
        function smEligible(id) { if (smDansAutre(id)) { return false; } return smDansCommission(id) || !!smSub().accepte; }
        function smTag(id) { return smDansCommission(id) ? '' : '<span style="font-size:0.68rem; font-weight:700; color:#8ec5ff; border:1px solid rgba(45,140,255,0.4); border-radius:12px; padding:0.1rem 0.45rem; margin-left:0.3rem;">🤝 support</span>'; }
        function smRender() {
            var term = tlNorm(document.getElementById('sm-search').value);
            var av = document.getElementById('sm-available'), se = document.getElementById('sm-selected');
            av.innerHTML = ''; se.innerHTML = '';
            var na = 0, ns = 0;
            TL_ALL.forEach(function (m) {
                if (smSel.has(m.id)) {
                    se.insertAdjacentHTML('beforeend', '<div class="tl-item"><span>' + tlAvatar(m) + '</span><span class="tl-name">' + tlEsc(m.name) + smTag(m.id) + '</span><button type="button" class="tl-rem-btn" onclick="smRemove(' + m.id + ')" title="Retirer">&times;</button></div>');
                    ns++;
                } else {
                    if (!smEligible(m.id)) { return; }
                    if (term && tlNorm(m.name).indexOf(term) === -1) { return; }
                    av.insertAdjacentHTML('beforeend', '<div class="tl-item"><span>' + tlAvatar(m) + '</span><span class="tl-name">' + tlEsc(m.name) + smTag(m.id) + '</span><button type="button" class="tl-add-btn" onclick="smAdd(' + m.id + ')" title="Ajouter">+</button></div>');
                    na++;
                }
            });
            if (!na) { av.innerHTML = '<div class="tl-empty">Aucun membre disponible.</div>'; }
            if (!ns) { se.innerHTML = '<div class="tl-empty">Aucun membre dans la sous-commission.</div>'; }
            document.getElementById('sm-sel-count').textContent = ns;
            document.getElementById('sm-avail-count').textContent = na + ' disponible(s)';
        }
        function smPersist(add, mid) {
            var params = new URLSearchParams();
            params.append('ajax', '1');
            params.append('action', add ? 'add_sous_commission_member' : 'remove_sous_commission_member');
            params.append('commission_id', smSub().cid);
            params.append('sous_commission_id', smId);
            params.append('membre_id', mid);
            params.append('csrf_token', TL_CSRF);
            return fetch('commission_dashboard.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString(), credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (!j.ok) { throw new Error(j.error || 'Erreur'); } });
        }
        function smAdd(id) {
            smSel.add(id); smDirty = true; smRender();
            smPersist(true, id).catch(function (e) {
                smSel.delete(id); smRender();
                modernAlert("Échec de l'ajout : " + e.message, 'Erreur');
            });
        }
        function smRemove(id) {
            smSel.delete(id); smDirty = true; smRender();
            smPersist(false, id).catch(function (e) {
                smSel.add(id); smRender();
                modernAlert("Échec du retrait : " + e.message, 'Erreur');
            });
        }
        function smOpen(id) {
            smId = id; smDirty = false;
            var sub = smSub();
            smSel = new Set((sub.members || []).map(Number));
            document.getElementById('sm-title').textContent = 'Membres — ' + sub.nom;
            document.getElementById('sm-note').textContent = sub.accepte
                ? 'Membres de la commission et membres supports (hors commission) acceptés.'
                : 'Membres de la commission uniquement. Activez l\'option « membres supports » pour ajouter d\'autres membres.';
            document.getElementById('sm-search').value = '';
            smRender();
            document.getElementById('sub-members-modal').classList.add('active');
        }
        function smClose() {
            document.getElementById('sub-members-modal').classList.remove('active');
            // Les listes affichées dans la page sont rafraîchies : on revient sur cette sous-commission
            if (smDirty) { window.location.href = window.location.pathname + '#sc-' + smId; window.location.reload(); }
        }
        document.getElementById('sm-search').addEventListener('input', smRender);
        document.getElementById('sub-members-modal').addEventListener('click', function (e) { if (e.target === this) { smClose(); } });
        // Retour sur une sous-commission : ouvre le groupe qui la contient
        (function () {
            var h = window.location.hash;
            if (h.indexOf('#sc-') !== 0) { return; }
            var el = document.getElementById(h.slice(1));
            if (!el) { return; }
            var n = el;
            while (n) { if (n.tagName === 'DETAILS') { n.open = true; } n = n.parentElement; }
            el.scrollIntoView({ block: 'center' });
        })();
    </script>

    <script>
        // Afficher/masquer le formulaire de modification d'une réunion
        function toggleReuEdit(id) {
            var el = document.getElementById('reu-edit-' + id);
            if (el) { el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none'; }
        }
        // Annulation : confirmation avec option d'ouvrir WhatsApp (message d'annulation)
        function annulerReunion(id, waLink) {
            var submit = function () { var f = document.getElementById('cancel-reu-' + id); if (f) { f.submit(); } };
            if (window.modernConfirm) {
                window.modernConfirm('Annuler cette réunion et notifier les membres de la commission ?', submit, {
                    danger: true, bouton: 'Annuler',
                    extra: { label: '🟢 Annuler + WhatsApp', style: 'background:#25D366;border:1px solid #25D366;color:#053b21;font-weight:700;', onOk: function () { try { window.open(waLink, '_blank'); } catch (e) {} submit(); } }
                });
            } else { submit(); }
        }
        // Formulaire « Créer une réunion » : afficher/masquer selon périodicité et mode
        (function () {
            document.querySelectorAll('.reunion-create-form').forEach(function (f) {
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
                var sond = f.querySelector('.r-sondage');
                var sondFields = f.querySelector('.r-sondage-fields');
                var fixes = f.querySelectorAll('.r-fixe');
                fixes.forEach(function (el) { el.querySelectorAll('input,select').forEach(function (i) { i.dataset.req = i.required ? '1' : ''; }); });
                function syncSondage() {
                    var on = !!(sond && sond.checked);
                    fixes.forEach(function (el) {
                        el.style.display = on ? 'none' : '';
                        el.querySelectorAll('input,select').forEach(function (i) { i.required = on ? false : (i.dataset.req === '1'); });
                    });
                    if (sondFields) { sondFields.style.display = on ? '' : 'none'; }
                    if (per) { if (on) { per.checked = false; } per.disabled = on; syncPer(); }
                }
                if (sond) { sond.addEventListener('change', syncSondage); }
                syncSondage();
                var addBtn = f.querySelector('.r-sond-add');
                if (addBtn) {
                    addBtn.addEventListener('click', function () {
                        var rows = f.querySelectorAll('.r-sond-row');
                        if (rows.length >= 20) { addBtn.style.display = 'none'; return; }
                        // Nouvelle proposition : copie vierge de la dernière ligne
                        var copie = rows[rows.length - 1].cloneNode(true);
                        copie.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                        copie.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
                        rows[rows.length - 1].parentNode.insertBefore(copie, addBtn);
                        if (rows.length + 1 >= 20) { addBtn.style.display = 'none'; }
                    });
                }
            });
        })();
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

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <!-- Popup : compte rendu PDF d'une réunion (aperçu + partage) -->
    <div id="crd-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card" style="max-width:900px; width:calc(100vw - 24px); height:88vh; display:flex; flex-direction:column;">
            <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; gap:0.6rem;">
                <h3 style="color:var(--accent); margin:0;">📄 Compte rendu PDF</h3>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeCrPdfDash()">✕ Fermer</button>
            </div>
            <div style="flex:1; min-height:0; margin:0.7rem 0; border-radius:8px; overflow:hidden; background:#fff;">
                <iframe id="crd-frame" src="" title="Compte rendu" style="width:100%; height:100%; border:0;"></iframe>
            </div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; flex-wrap:wrap; justify-content:flex-end; align-items:center;">
                <button type="button" id="crd-share" class="btn btn-primary btn-sm" onclick="shareCrPdfDash(this)">📤 Partager le compte rendu</button>
            </div>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js"></script>
    <script>
        function openCrPdfDash(id) {
            var f = document.getElementById('crd-frame');
            f.setAttribute('src', 'reunion_rapport.php?id=' + encodeURIComponent(id) + '&embed=1');
            var m = document.getElementById('crd-modal');
            m.style.display = 'flex';
            setTimeout(function () { m.classList.add('active'); }, 10);
        }
        function closeCrPdfDash() {
            var m = document.getElementById('crd-modal');
            m.classList.remove('active');
            setTimeout(function () { m.style.display = 'none'; document.getElementById('crd-frame').setAttribute('src', ''); }, 300);
        }
        // Génère le PDF depuis l'aperçu puis le partage (partage natif) ou le télécharge
        function shareCrPdfDash(btn) {
            var f = document.getElementById('crd-frame');
            var doc = f && f.contentDocument;
            if (!f || !f.contentWindow || typeof f.contentWindow.rptPreparerPdf !== 'function') {
                if (window.modernAlert) { window.modernAlert('Aperçu non prêt. Réessayez dans un instant.'); }
                return;
            }
            var ancien = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '⏳ Préparation…';
            var fin = function () { btn.disabled = false; btn.innerHTML = ancien; };
            f.contentWindow.rptPreparerPdf().then(function (blob) {
                var file = new File([blob], 'compte-rendu.pdf', { type: 'application/pdf' });
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    return navigator.share({ files: [file], title: 'Compte rendu' }).catch(function () {}).then(fin);
                }
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url; a.download = 'compte-rendu.pdf';
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
                fin();
            }).catch(function () {
                fin();
                if (window.modernAlert) { window.modernAlert('Échec de la génération du PDF.'); }
            });
        }
        (function () {
            var m = document.getElementById('crd-modal');
            if (m) { m.addEventListener('click', function (e) { if (e.target === m) { closeCrPdfDash(); } }); }
        })();
    </script>
    <?php include __DIR__ . '/modern_popup.php'; ?>
<script>
// Lien direct depuis « Mes commissions » : ouvre la section demandée de la commission (?ouvrir=creer-reunion#com-card-ID)
(function () {
    var q = new URLSearchParams(window.location.search), section = q.get('ouvrir'), h = window.location.hash;
    if (!section || h.indexOf('#com-card-') !== 0) { return; }
    var card = document.getElementById(h.slice(1));
    if (!card) { return; }
    var cible = card.querySelector('[data-section="' + section.replace(/[^a-z-]/g, '') + '"]');
    var el = cible || card;
    var n = el;
    while (n && n !== document.body) { if (n.tagName === 'DETAILS') { n.open = true; } n = n.parentElement; }
    setTimeout(function () {
        el.scrollIntoView({ block: 'center' });
        var champ = cible && cible.querySelector('input[type="text"]');
        if (champ && section === 'sous-ajout') { champ.focus({ preventScroll: true }); }
    }, 150);
})();
</script>
<?php if ($embed): ?>
<script>
// Mode intégré : les liens vers une autre page s'ouvrent dans la fenêtre principale
(function () {
    document.addEventListener('click', function (ev) {
        var a = ev.target.closest && ev.target.closest('a[href]');
        if (!a || a.target || a.getAttribute('href').charAt(0) === '#') { return; }
        try {
            var u = new URL(a.href, location.href);
            if (u.origin === location.origin && u.pathname.split('/').pop() !== 'commission_dashboard.php') { a.target = '_top'; }
        } catch (e) { /* ignoré */ }
    }, true);
})();
// Mode intégré : hauteur envoyée à « Mes commissions » ; réception de la zone visible (pour centrer les fenêtres)
(function () {
    var dernier = 0;
    function hauteur() {
        var h = Math.ceil(document.body.getBoundingClientRect().height) + 12;
        if (Math.abs(h - dernier) < 2) { return; }
        dernier = h;
        try { parent.postMessage({ type: 'embed-height', h: h }, location.origin); } catch (e) { /* ignoré */ }
    }
    if (window.ResizeObserver) { new ResizeObserver(hauteur).observe(document.body); }
    window.addEventListener('load', hauteur);
    setInterval(hauteur, 700);
    window.addEventListener('message', function (e) {
        if (e.origin !== location.origin) { return; }
        var d = e.data || {};
        if (d.type === 'embed-viewport') {
            document.documentElement.style.setProperty('--vt', d.top + 'px');
            document.documentElement.style.setProperty('--vh', d.height + 'px');
        }
    });
})();
</script>
<?php endif; ?>
<script src="modern-date.js" defer></script>
</body>
</html>
