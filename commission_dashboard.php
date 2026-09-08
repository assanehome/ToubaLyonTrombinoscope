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
require_once __DIR__ . '/csrf.php';
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
                    // Un responsable est aussi membre de la commission : ajout automatique.
                    $insMbr = $pdo->prepare("INSERT IGNORE INTO commission_membres (commission_id, membre_id) VALUES (?, ?)");
                    foreach ($ids as $one) { $one = (int) $one; if ($one > 0) { $ins->execute([$cid, $one]); $insMbr->execute([$cid, $one]); $demandes[] = $one; } }
                }
                $pdo->commit();
                $success = "Les responsables de la commission ont été mis à jour.";
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
                $success = "Membre retiré.";
            } elseif ($action === 'add_commission_manager' && $cid > 0 && $mid > 0 && $__isAdmin) {
                $st = $pdo->prepare("INSERT IGNORE INTO commission_gestionnaires (commission_id, membre_id) VALUES (?, ?)");
                $st->execute([$cid, $mid]);
                // Un responsable est aussi membre de la commission : ajout automatique.
                $pdo->prepare("INSERT IGNORE INTO commission_membres (commission_id, membre_id) VALUES (?, ?)")->execute([$cid, $mid]);
                $success = "Responsable ajouté.";
                if ($st->rowCount() > 0) { com_notify_membre_ajout($pdo, $mid, com_commission_nom($pdo, $cid), true); }
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
                if ($titre === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut)) {
                    $error = "Le titre et une date valide sont obligatoires.";
                } elseif ($dateDebut < date('Y-m-d')) {
                    $error = "La date de la réunion ne peut pas être dans le passé.";
                } elseif ($heure === '') {
                    $error = "L'heure est obligatoire.";
                } elseif ($heureFin !== '' && $heureFin <= $heure) {
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
                    $success = count($dates) > 1 ? (count($dates) . " réunions créées (série périodique).") : "Réunion créée.";
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
                    if (reunion_precedente_non_cloturee($pdo, $r)) {
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
                        } elseif ($eHeureFin !== '' && $eHeureFin <= $eHeure) {
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
            $h = '<div style="border:1px solid rgba(191,33,33,0.4); border-radius:12px; padding:0.7rem 0.9rem; background:rgba(191,33,33,0.06);">';
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
        $h = '<div style="border:1px solid var(--glass-border); border-radius:12px; padding:0.7rem 0.9rem; background:rgba(255,255,255,0.03);">';
        $h .= '<div style="display:flex; justify-content:space-between; gap:0.5rem; flex-wrap:wrap; align-items:center;">';
        $h .= '<div style="color:#fff; font-weight:700;">' . ($showTitle ? '📌 ' . htmlspecialchars($r['titre']) : '🗓️ ' . $rDateFr) . '</div>';
        $h .= '<div style="display:flex; gap:0.3rem; flex-wrap:wrap;">';
        if ($rClo) { $h .= '<span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-muted);">Clôturée</span>'; }
        elseif ($rPub) { $h .= '<span class="badge badge-approved">🚀 Publiée</span>'; }
        else { $h .= '<span class="badge badge-pending">⏳ En attente</span>'; }
        $h .= '</div></div>';
        $hFinTxt = trim((string) ($r['heure_fin'] ?? '')) !== '' ? ' – ' . htmlspecialchars($r['heure_fin']) : '';
        $h .= '<div style="color:var(--text-muted); font-size:0.82rem; margin-top:0.3rem;">🗓️ ' . $rDateFr . ' · 🕐 ' . htmlspecialchars($r['heure']) . $hFinTxt . ' · ' . ((string) $r['mode'] === 'presentiel' ? '🏛️ présentiel' : '💻 à distance') . '</div>';
        $h .= '<div class="km-actions" style="border-top:0; padding-top:0.6rem; margin-top:0.4rem;">';
        // Partage WhatsApp uniquement si la réunion est publiée
        if ($rPub) {
            $waShare = 'https://wa.me/?text=' . rawurlencode(reunion_message_partage($r, reunion_commission_nom($pdo, (int) $r['commission_id']), dahira_base_url() . '/reunion_detail.php?id=' . $rid));
            $h .= '<a href="' . htmlspecialchars($waShare, ENT_QUOTES) . '" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 WhatsApp</a>';
        }
        // Publier / Dépublier juste après WhatsApp
        if (!$rClo) {
            if (!$rPub) {
                if ($canPublish) {
                    $h .= '<form method="POST" style="margin:0;" data-confirm="Publier cette réunion et notifier les membres de la commission ?">' . $csrf . '<input type="hidden" name="action" value="publish_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><button type="submit" class="btn btn-primary btn-sm">🚀 Publier</button></form>';
                } else {
                    $h .= '<span class="btn btn-sm" style="background:rgba(255,255,255,0.06); color:rgba(255,255,255,0.4); cursor:not-allowed;" title="Clôturez d\'abord la réunion précédente de la série">🚀 Publier</span>';
                }
            } else {
                $h .= '<form method="POST" style="margin:0;" data-confirm="Dépublier cette réunion ? Elle sera masquée aux membres." data-confirm-danger="1">' . $csrf . '<input type="hidden" name="action" value="unpublish_reunion"><input type="hidden" name="reunion_id" value="' . $rid . '"><button type="submit" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);">⏳ Dépublier</button></form>';
            }
        }
        $h .= '<a href="reunion_detail.php?id=' . $rid . '" class="btn btn-secondary btn-sm">📊 Détail</a>';
        $h .= '<a href="reunion_rapport.php?id=' . $rid . '" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);">📄 PDF</a>';
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
            $optsHF = '<option value="">— Aucune —</option>';
            for ($hh = 7; $hh <= 23; $hh++) { foreach (['00', '30'] as $mm) { $hv = sprintf('%02d:%s', $hh, $mm); $optsHF .= '<option value="' . $hv . '"' . (($r['heure_fin'] ?? '') !== '' && $curHF === $hv ? ' selected' : '') . '>' . $hv . '</option>'; } }
            $h .= '<div class="form-group" style="margin:0;"><label class="form-label">Heure de fin</label><select name="heure_fin" class="form-input modern-select">' . $optsHF . '</select></div>';
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
        .kourel-card { margin-bottom: 1.25rem; border-radius: 18px; padding: 1.25rem 1.5rem; }
        .kourel-card-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .kourel-card-head h3 { margin: 0; color: var(--white); font-size: 1.2rem; }
        .com-sub { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gold); font-weight: 700; margin: 0.5rem 0 0.5rem; }
        .km-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem; }
        .km-chip { display: inline-flex; align-items: center; gap: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 50px; padding: 0.25rem 0.7rem 0.25rem 0.3rem; }
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
        .com-filters input { flex: 1 1 240px; border-radius: 50px; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); font-size: 0.9rem; padding: 0.6rem 1rem; }
        .com-filters input:focus { outline: none; border-color: var(--accent); }
        .com-dd { flex: 1 1 200px; border-radius: 10px; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); font-size: 0.9rem; padding: 0.6rem 0.9rem; color-scheme: dark; }
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
        .tl-card { width: 100%; max-width: 820px; max-height: 90vh; display: flex; flex-direction: column; background: linear-gradient(180deg,#123528,#0c241a); border: 1px solid rgba(212,175,55,0.25); border-radius: 18px; overflow: hidden; box-shadow: 0 30px 80px rgba(0,0,0,0.55); }
        .tl-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem; background: linear-gradient(135deg,#1b4332,#2d6a4f); flex-shrink: 0; }
        .tl-head h3 { margin: 0; color: #fff; font-size: 1.05rem; }
        .tl-x { background: rgba(255,255,255,0.15); color: #fff; border: 0; width: 28px; height: 28px; border-radius: 50%; font-size: 1.1rem; line-height: 1; cursor: pointer; flex-shrink: 0; }
        .tl-cols { display: flex; flex: 1; min-height: 0; }
        .tl-col { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .tl-col + .tl-col { border-left: 1px solid var(--glass-border); }
        .tl-col-head { padding: 0.75rem 1rem 0.5rem; flex-shrink: 0; }
        .tl-col-head .tl-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gold); font-weight: 700; }
        .tl-col-head input { width: 100%; margin-top: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 50px; color: #fff; font-size: 0.85rem; padding: 0.45rem 0.9rem; }
        .tl-col-head input:focus { outline: none; border-color: var(--accent); }
        .tl-list { flex: 1; overflow-y: auto; padding: 0.5rem 0.75rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; }
        .tl-item { display: flex; align-items: center; gap: 0.6rem; padding: 0.4rem 0.5rem; border: 1px solid var(--glass-border); border-radius: 12px; background: rgba(255,255,255,0.03); }
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
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">

        <div class="kourel-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <span>📋 Espaces commissions — <strong class="gold-text"><?php echo count($commissions); ?></strong> commission(s)</span>
                <?php if ($__isAdmin): ?>
                    <a href="admin_dashboard.php" class="btn btn-secondary btn-sm">← Tableau de bord</a>
                <?php else: ?>
                    <a href="trombinoscope.php" class="btn btn-secondary btn-sm">← Trombinoscope</a>
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

                        <details class="com-members-group">
                            <summary class="com-sub" style="cursor:pointer; list-style:none; display:flex; align-items:center; gap:0.4rem;">👥 Membres de la commission <span style="color:var(--text-muted); font-weight:600;">(<?php echo count($membersOfC); ?>)</span> <span class="com-mem-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                            <div class="km-list com-members-list" id="com-members-<?php echo (int) $c['id']; ?>" style="margin-top:0.5rem;">
                                <?php if (empty($membersOfC)): ?>
                                    <p class="km-empty">Aucun membre dans cette commission.</p>
                                <?php else: foreach ($membersOfC as $mm): ?>
                                    <span class="km-chip"><?php echo com_member_avatar($mm); ?><span class="km-name"><?php echo htmlspecialchars($mm['prenom'] . ' ' . $mm['nom']); ?></span></span>
                                <?php endforeach; endif; ?>
                            </div>
                        </details>

                        <div class="km-actions">
                            <button type="button" class="btn btn-primary btn-sm" onclick="openComModal('members', <?php echo (int) $c['id']; ?>, <?php echo htmlspecialchars(json_encode($c['nom']), ENT_QUOTES); ?>)">👥 Gérer les membres</button>
                            <?php if ($__isAdmin): ?>
                            <button type="button" class="btn btn-secondary btn-sm" style="border-color:var(--gold); color:var(--gold);" onclick="openComModal('managers', <?php echo (int) $c['id']; ?>, <?php echo htmlspecialchars(json_encode($c['nom']), ENT_QUOTES); ?>)">🎖️ Gérer les responsables</button>
                            <?php endif; ?>
                            <?php if (!empty($c['stock_enabled'])): ?>
                            <a href="commission_stock.php?id=<?php echo (int) $c['id']; ?>" class="btn btn-secondary btn-sm" style="border-color:#7bd8a6; color:#7bd8a6;">📦 Stock</a>
                            <?php endif; ?>
                        </div>

                        <?php
                            $reusAll = $comReunions[$c['id']] ?? [];
                            $reus = array_values(array_filter($reusAll, function ($x) { return (int) ($x['annule'] ?? 0) !== 1; }));
                            $reusAnn = array_values(array_filter($reusAll, function ($x) { return (int) ($x['annule'] ?? 0) === 1; }));
                        ?>
                        <div style="border-top:1px solid var(--glass-border); padding-top:1rem; margin-top:1rem;">
                            <div class="com-sub" style="margin-top:0;">📅 Réunions de la commission</div>
                            <?php if (empty($reus)): ?>
                                <p class="km-empty">Aucune réunion pour le moment.</p>
                            <?php else: ?>
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
                                        <div style="border:1px solid rgba(212,175,55,0.35); border-radius:14px; padding:0.8rem 0.9rem; background:rgba(212,175,55,0.05);">
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
                            <?php endif; ?>
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
                            <details style="border:1px solid var(--glass-border); border-radius:12px; padding:0.85rem 1rem; background:rgba(255,255,255,0.02); margin-top:0.5rem;">
                                <summary style="cursor:pointer; color:var(--gold); font-weight:700; font-size:0.9rem;">➕ Créer une réunion</summary>
                                <form method="POST" class="reunion-create-form" style="margin-top:0.8rem; display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:0.7rem;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="create_reunion">
                                    <input type="hidden" name="commission_id" value="<?php echo (int) $c['id']; ?>">
                                    <div class="form-group" style="grid-column:1 / -1; margin:0;"><label class="form-label">Titre <span style="color:var(--danger)">*</span></label><input type="text" name="titre" class="form-input" required placeholder="Ex: Réunion mensuelle de la commission"></div>
                                    <div class="form-group" style="grid-column:1 / -1; margin:0;"><label class="form-label">Ordre du jour</label><textarea name="description" class="form-input" rows="2"></textarea></div>
                                    <div class="form-group" style="margin:0;"><label class="form-label">Date <span style="color:var(--danger)">*</span></label><input type="date" name="date_debut" class="form-input" required min="<?php echo date('Y-m-d'); ?>" style="width:100%; max-width:100%; box-sizing:border-box;"></div>
                                    <div class="form-group" style="margin:0;"><label class="form-label">Heure de début <span style="color:var(--danger)">*</span></label><select name="heure" class="form-input modern-select" required><option value="">— Heure —</option><?php for ($hh = 7; $hh <= 23; $hh++): foreach (['00', '30'] as $mm): $hv = sprintf('%02d:%s', $hh, $mm); ?><option value="<?php echo $hv; ?>"><?php echo $hv; ?></option><?php endforeach; endfor; ?></select></div>
                                    <div class="form-group" style="margin:0;"><label class="form-label">Heure de fin <span style="color:var(--text-muted); font-size:0.8rem;">(facultatif)</span></label><select name="heure_fin" class="form-input modern-select"><option value="">— Aucune —</option><?php for ($hh = 7; $hh <= 23; $hh++): foreach (['00', '30'] as $mm): $hv = sprintf('%02d:%s', $hh, $mm); ?><option value="<?php echo $hv; ?>"><?php echo $hv; ?></option><?php endforeach; endfor; ?></select></div>
                                    <div class="form-group" style="margin:0;"><label class="form-label">Mode <span style="color:var(--danger)">*</span></label><select name="mode" class="form-input modern-select r-mode" required><option value="">— Choisir —</option><option value="distance">💻 À distance (Zoom)</option><option value="presentiel">🏛️ Présentiel</option></select></div>
                                    <div class="form-group r-lien" style="grid-column:1 / -1; margin:0; display:none;"><label class="form-label">🔗 Lien Zoom (réunion à distance) <span style="color:var(--danger)">*</span></label><input type="url" name="lien" class="form-input r-lien-input" value="<?php echo htmlspecialchars($reunionZoomLien); ?>" placeholder="https://us06web.zoom.us/..."><p style="color:var(--text-muted); font-size:0.75rem; margin:0.3rem 0 0;">Pré-rempli avec le lien du Guddi Àjjuma — modifiable.</p></div>
                                    <div class="form-group r-lieu" style="grid-column:1 / -1; margin:0; display:none;"><label class="form-label">📍 Adresse (présentiel) <span style="color:var(--danger)">*</span></label><input type="text" name="lieu" class="form-input r-lieu-input" value="<?php echo htmlspecialchars($reunionLieuDefaut); ?>"><p style="color:var(--text-muted); font-size:0.75rem; margin:0.3rem 0 0;">Pré-rempli avec l'adresse du Dahira — modifiable.</p></div>
                                    <div class="form-group" style="grid-column:1 / -1; margin:0;">
                                        <label class="form-label" style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;"><input type="checkbox" name="periodique" value="1" class="r-periodique"> 🔁 Réunion périodique</label>
                                    </div>
                                    <div class="form-group r-per-fields" style="margin:0; display:none;"><label class="form-label">Périodicité</label><select name="periodicite" class="form-input modern-select"><option value="hebdomadaire">Hebdomadaire</option><option value="quinzaine">Une semaine sur deux</option><option value="mensuelle">Mensuelle</option></select></div>
                                    <div class="form-group r-per-fields" style="margin:0; display:none;"><label class="form-label">Date de fin</label><input type="date" name="date_fin" class="form-input" min="<?php echo date('Y-m-d'); ?>" style="width:100%; max-width:100%; box-sizing:border-box;"></div>
                                    <div style="grid-column:1 / -1;"><button type="submit" class="btn btn-primary btn-sm">💾 Créer la réunion</button></div>
                                </form>
                            </details>
                        </div>
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
            // Un responsable est aussi membre de la commission : refléter l'ajout côté membres.
            var mirroredMembers = false;
            if (tlMode === 'managers') {
                if (!COM_MEMBERS[tlCid]) { COM_MEMBERS[tlCid] = []; }
                if (COM_MEMBERS[tlCid].map(Number).indexOf(id) === -1) { COM_MEMBERS[tlCid].push(id); mirroredMembers = true; }
                updateCard(tlCid, 'members');
            }
            comPersist(tlMode, tlCid, id, true).catch(function (e) {
                var arr = map[tlCid]; var i = arr.map(Number).indexOf(id); if (i > -1) { arr.splice(i, 1); }
                tlSelected.delete(id); tlRender(); updateCard(tlCid, tlMode);
                if (mirroredMembers) {
                    var mi = COM_MEMBERS[tlCid].map(Number).indexOf(id);
                    if (mi > -1) { COM_MEMBERS[tlCid].splice(mi, 1); }
                    updateCard(tlCid, 'members');
                }
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
    <?php include __DIR__ . '/modern_popup.php'; ?>
</body>
</html>
