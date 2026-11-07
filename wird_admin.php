<?php
/**
 * Touba Lyon 2026 - Lecture collective du Coran (Khatm) : gestion des sessions.
 * Réservé à l'admin et aux responsables de la commission « Culte ».
 */
require_once __DIR__ . '/wird_guard.php';   // $__isAdmin, $__isCulteManager, $pdo
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dahira_emails.php'; // dahira_base_url(), send_smtp_mail()
require_once __DIR__ . '/notification_helper.php'; // cloche notifications
require_once __DIR__ . '/wird_period_helper.php';  // libellé de période

$error = '';
$success = '';

/** Date de fin d'une période selon sa périodicité (bornes incluses). */
function wird_period_end(string $debut, string $per): string
{
    $d = new DateTime($debut);
    if ($per === 'semaine') { $d->modify('+6 days'); }
    elseif ($per === 'quinzaine') { $d->modify('+13 days'); }
    elseif ($per === 'mois') { $d->modify('+1 month')->modify('-1 day'); }
    return $d->format('Y-m-d');
}
/** Date de début de la période suivante. */
function wird_next_start(string $debut, string $per): string
{
    $d = new DateTime($debut);
    if ($per === 'semaine') { $d->modify('+7 days'); }
    elseif ($per === 'quinzaine') { $d->modify('+14 days'); }
    elseif ($per === 'mois') { $d->modify('+1 month'); }
    return $d->format('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        $sid = (int) ($_POST['session_id'] ?? 0);
        // Accès restreint (délégué / gestionnaire de Kamil) : seules la création de session
        // et la gestion de ses propres Kamils sont permises.
        $__denied = '';
        if (!$__isCulteManager) {
            if (in_array($action, ['save_wa_group', 'add_delegue', 'remove_delegue', 'set_kamil_managers', 'delete_session'], true)) {
                $__denied = "Action réservée aux responsables de la commission Culte.";
            } elseif ($action === 'create_session') {
                if (!$__isCulteDelegate) { $__denied = "Vous n'avez pas le droit de créer une session."; }
            } elseif ($sid > 0) {
                try {
                    $__q = $pdo->prepare("SELECT id, serie_id FROM quran_sessions WHERE id = ?");
                    $__q->execute([$sid]);
                    $__row = $__q->fetch();
                    if (!$__row || !wird_can_manage_session($__row, false, $__kamilKeys)) { $__denied = "Ce Kamil ne vous est pas confié."; }
                } catch (Exception $e) { $__denied = "Action non autorisée."; }
            }
        }
        try {
            if ($__denied !== '') {
                $error = $__denied;
            } elseif ($action === 'add_groupe') {
                $gn = trim($_POST['groupe_nom'] ?? '');
                if ($gn !== '') {
                    $pdo->prepare("INSERT IGNORE INTO quran_groupes (nom) VALUES (?)")->execute([$gn]);
                    $success = "Groupe « " . $gn . " » ajouté.";
                } else {
                    $error = "Le nom du groupe est obligatoire.";
                }
            } elseif ($action === 'create_session') {
                $titre = trim($_POST['titre'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $groupe = trim($_POST['groupe'] ?? '');
                // Périodicité : ponctuelle (défaut) ou semaine / quinzaine / mois
                $periodicite = $_POST['periodicite'] ?? 'ponctuelle';
                if (!in_array($periodicite, ['ponctuelle', 'semaine', 'quinzaine', 'mois'], true)) { $periodicite = 'ponctuelle'; }
                // Réallocation aux mêmes membres : seulement si périodique
                $realloc = ($periodicite !== 'ponctuelle' && !empty($_POST['realloc_meme_membre'])) ? 1 : 0;
                // Nombre de Juz par membre : un seul (défaut) ou plusieurs
                $unJuz = (($_POST['juz_par_membre'] ?? 'un') === 'plusieurs') ? 0 : 1;
                if ($titre === '') {
                    $error = "Le titre de la session est obligatoire.";
                } elseif ($periodicite === 'ponctuelle') {
                    // Session unique — en attente par défaut (non visible des membres)
                    // Date de clôture facultative (échéance de validation, réutilise la colonne date_fin)
                    $dateClotureP = (string)($_POST['date_cloture'] ?? '');
                    if (!DateTime::createFromFormat('Y-m-d', $dateClotureP)) { $dateClotureP = null; }
                    $token = bin2hex(random_bytes(12));
                    $pdo->prepare("INSERT INTO quran_sessions (titre, description, groupe, token, periodicite, realloc_meme_membre, lance, un_juz_par_membre, date_fin) VALUES (?, ?, ?, ?, 'ponctuelle', 0, 0, ?, ?)")
                        ->execute([$titre, $desc !== '' ? $desc : null, $groupe !== '' ? $groupe : null, $token, $unJuz, $dateClotureP]);
                    $newId = (int) $pdo->lastInsertId();
                    $ins = $pdo->prepare("INSERT INTO quran_parts (session_id, numero, statut) VALUES (?, ?, 'libre')");
                    for ($n = 1; $n <= 30; $n++) { $ins->execute([$newId, $n]); }
                    // Un délégué devient automatiquement gestionnaire du Kamil qu'il crée
                    if (!$__isCulteManager && $__wirdMemberId > 0) { $pdo->prepare("INSERT IGNORE INTO quran_session_gestionnaires (session_key, membre_id, granted_by) VALUES (?, ?, ?)")->execute([$newId, $__wirdMemberId, $__wirdMemberId]); }
                    $success = "Session « " . $titre . " » créée (30 parties) — en attente. Lancez-la depuis le suivi pour la rendre visible des membres.";
                } else {
                    // Série périodique : générer le calendrier de la date de début à la date de fin
                    $dateDebut = (string)($_POST['date_debut'] ?? '');
                    if (!DateTime::createFromFormat('Y-m-d', $dateDebut)) { $dateDebut = date('Y-m-d'); }
                    $dateFin = (string)($_POST['date_fin'] ?? '');
                    if (!DateTime::createFromFormat('Y-m-d', $dateFin) || $dateFin < $dateDebut) {
                        // Date de fin absente/invalide : une seule période
                        $dateFin = wird_period_end($dateDebut, $periodicite);
                    }
                    $pdo->beginTransaction();
                    $serieId = 0; $prevId = null; $curStart = $dateDebut; $nbPeriodes = 0;
                    $insPart = $pdo->prepare("INSERT INTO quran_parts (session_id, numero, statut) VALUES (?, ?, 'libre')");
                    $insSess = $pdo->prepare("INSERT INTO quran_sessions (titre, description, groupe, token, periodicite, realloc_meme_membre, parent_id, date_debut, date_fin, serie_id, lance, un_juz_par_membre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    // Sécurité : borne haute pour éviter toute boucle excessive
                    while ($curStart <= $dateFin && $nbPeriodes < 104) {
                        $curEnd = wird_period_end($curStart, $periodicite);
                        if ($curEnd > $dateFin) { $curEnd = $dateFin; } // dernière période bornée à la date de fin
                        $token = bin2hex(random_bytes(12));
                        // Toutes les périodes démarrent en attente (à lancer depuis le suivi)
                        $insSess->execute([$titre, $desc !== '' ? $desc : null, $groupe !== '' ? $groupe : null, $token, $periodicite, $realloc, $prevId, $curStart, $curEnd, $serieId ?: null, 0, $unJuz]);
                        $id = (int) $pdo->lastInsertId();
                        if ($serieId === 0) { $serieId = $id; $pdo->prepare("UPDATE quran_sessions SET serie_id = ? WHERE id = ?")->execute([$serieId, $id]); }
                        for ($n = 1; $n <= 30; $n++) { $insPart->execute([$id, $n]); }
                        $prevId = $id;
                        $nbPeriodes++;
                        $curStart = wird_next_start($curStart, $periodicite);
                    }
                    $pdo->commit();
                    if (!$__isCulteManager && $__wirdMemberId > 0 && $serieId > 0) { $pdo->prepare("INSERT IGNORE INTO quran_session_gestionnaires (session_key, membre_id, granted_by) VALUES (?, ?, ?)")->execute([$serieId, $__wirdMemberId, $__wirdMemberId]); }
                    $success = "Série « " . $titre . " » créée — " . $nbPeriodes . " période(s) planifiées du " . date('d/m/Y', strtotime($dateDebut)) . " au " . date('d/m/Y', strtotime($dateFin)) . ". Toutes en attente : lancez la 1re période depuis le suivi pour la rendre visible des membres.";
                }
            } elseif ($action === 'next_period' && $sid > 0) {
                // Lancer la période suivante d'une session périodique
                $srcStmt = $pdo->prepare("SELECT * FROM quran_sessions WHERE id = ?");
                $srcStmt->execute([$sid]);
                $src = $srcStmt->fetch();
                if (!$src) {
                    $error = "Session introuvable.";
                } elseif (($src['periodicite'] ?? 'ponctuelle') === 'ponctuelle') {
                    $error = "Cette session n'est pas périodique.";
                } else {
                    $per = (string)$src['periodicite'];
                    $serie = (int)($src['serie_id'] ?: $src['id']);
                    if (empty($src['serie_id'])) { $pdo->prepare("UPDATE quran_sessions SET serie_id = ? WHERE id = ?")->execute([$serie, (int)$src['id']]); }
                    // Prochaine période : lendemain de la dernière date de fin de la série
                    $lf = $pdo->prepare("SELECT MAX(date_fin) FROM quran_sessions WHERE serie_id = ?");
                    $lf->execute([$serie]);
                    $lastFin = $lf->fetchColumn();
                    if ($lastFin) { $ns = new DateTime($lastFin); $ns->modify('+1 day'); $newStart = $ns->format('Y-m-d'); }
                    else { $newStart = date('Y-m-d'); }
                    $newEnd = wird_period_end($newStart, $per);
                    $token = bin2hex(random_bytes(12));
                    $pdo->prepare("INSERT INTO quran_sessions (titre, description, groupe, token, periodicite, realloc_meme_membre, parent_id, date_debut, date_fin, serie_id, un_juz_par_membre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$src['titre'], $src['description'], $src['groupe'], $token, $src['periodicite'], (int)$src['realloc_meme_membre'], $sid, $newStart, $newEnd, $serie, (int)($src['un_juz_par_membre'] ?? 1)]);
                    $newId = (int) $pdo->lastInsertId();
                    // Clôturer la période précédente avant de lancer la suivante
                    $pdo->prepare("UPDATE quran_sessions SET statut='terminee', closed_at=NOW() WHERE id=?")->execute([$sid]);
                    if ((int)$src['realloc_meme_membre'] === 1) {
                        // Réattribuer les mêmes Juz aux mêmes personnes (à relire cette période)
                        $oldStmt = $pdo->prepare("SELECT numero, membre_id, membre_nom, owner_token FROM quran_parts WHERE session_id = ? AND membre_id IS NOT NULL AND membre_id > 0");
                        $oldStmt->execute([$sid]);
                        $assign = [];
                        foreach ($oldStmt->fetchAll() as $o) { $assign[(int)$o['numero']] = $o; }
                        $insR = $pdo->prepare("INSERT INTO quran_parts (session_id, numero, statut, membre_id, membre_nom, owner_token, reserved_at) VALUES (?, ?, 'reservee', ?, ?, ?, NOW())");
                        $insL = $pdo->prepare("INSERT INTO quran_parts (session_id, numero, statut) VALUES (?, ?, 'libre')");
                        for ($n = 1; $n <= 30; $n++) {
                            if (isset($assign[$n])) {
                                $a = $assign[$n];
                                $insR->execute([$newId, $n, (int)$a['membre_id'], $a['membre_nom'], $a['owner_token']]);
                            } else {
                                $insL->execute([$newId, $n]);
                            }
                        }
                        $success = "Période précédente clôturée. Nouvelle période lancée — mêmes Juz réattribués aux mêmes personnes.";
                    } else {
                        $insL = $pdo->prepare("INSERT INTO quran_parts (session_id, numero, statut) VALUES (?, ?, 'libre')");
                        for ($n = 1; $n <= 30; $n++) { $insL->execute([$newId, $n]); }
                        $success = "Période précédente clôturée. Nouvelle période lancée (30 Juz libres).";
                    }
                }
            } elseif ($action === 'close_session' && $sid > 0) {
                $pdo->prepare("UPDATE quran_sessions SET statut='terminee', closed_at=NOW() WHERE id=?")->execute([$sid]);
                $success = "Session clôturée.";
            } elseif ($action === 'reopen_session' && $sid > 0) {
                $pdo->prepare("UPDATE quran_sessions SET statut='en_cours', closed_at=NULL WHERE id=?")->execute([$sid]);
                $success = "Session rouverte.";
            } elseif ($action === 'set_launch' && $sid > 0) {
                // Lancer / annuler le lancement (revenir en attente)
                $val = ((int)($_POST['val'] ?? 0) === 1) ? 1 : 0;
                if ($val === 1) {
                    // Interdire le lancement tant que la période précédente n'est pas clôturée
                    $blocked = false;
                    $srow = $pdo->prepare("SELECT serie_id, date_debut FROM quran_sessions WHERE id=?");
                    $srow->execute([$sid]);
                    $srow = $srow->fetch();
                    if ($srow && !empty($srow['serie_id']) && !empty($srow['date_debut'])) {
                        $pv = $pdo->prepare("SELECT statut FROM quran_sessions WHERE serie_id=? AND id<>? AND (date_debut < ? OR (date_debut = ? AND id < ?)) ORDER BY date_debut DESC, id DESC LIMIT 1");
                        $pv->execute([(int)$srow['serie_id'], $sid, $srow['date_debut'], $srow['date_debut'], $sid]);
                        $prevStatut = $pv->fetchColumn();
                        if ($prevStatut !== false && $prevStatut !== 'terminee') { $blocked = true; }
                    }
                    if ($blocked) {
                        $error = "Impossible de lancer cette période : la période précédente n'est pas encore clôturée.";
                    } else {
                        $pdo->prepare("UPDATE quran_sessions SET lance=1, statut='en_cours', closed_at=NULL WHERE id=?")->execute([$sid]);
                        $success = "Session lancée — visible des membres.";
                        // Notifier tous les membres (cloche + Web Push) de l'ouverture de la lecture
                        try {
                            $ss = $pdo->prepare("SELECT titre, token, periodicite, serie_id, date_debut, date_fin FROM quran_sessions WHERE id=?");
                            $ss->execute([$sid]);
                            $ss = $ss->fetch();
                            if ($ss) {
                                $perLbl = wird_period_label($pdo, ['id' => $sid] + $ss);
                                $body = 'La lecture « ' . $ss['titre'] . ' » est ouverte' . ($perLbl !== '' ? ' — ' . $perLbl : '') . '. Choisissez votre Juz et lisez-le. Jërëjëf ! 🤲';
                                $lnk = 'wird.php?s=' . (int)$sid . '&t=' . $ss['token'];
                                $nNotif = troba_notify_all_membres($pdo, 'wird_lance', '📖 Lecture du Coran ouverte', $body, $lnk);
                                $success .= ' ' . (int)$nNotif . ' membre(s) notifié(s).';
                            }
                        } catch (Throwable $e) { error_log('Touba Lyon wird_admin notif lancement: ' . $e->getMessage()); }
                    }
                } else {
                    $pdo->prepare("UPDATE quran_sessions SET lance=0 WHERE id=?")->execute([$sid]);
                    $success = "Lancement annulé — session repassée en attente (masquée aux membres).";
                }
            } elseif ($action === 'set_juz_mode' && $sid > 0) {
                // Modifier l'option « un seul / plusieurs Juz par membre » après création
                $val = ((int)($_POST['val'] ?? 0) === 1) ? 1 : 0;
                $sr = $pdo->prepare("SELECT serie_id FROM quran_sessions WHERE id=?");
                $sr->execute([$sid]);
                $serieId = (int)($sr->fetchColumn() ?: 0);
                if ($serieId > 0) {
                    // Appliquer à toute la série pour rester cohérent
                    $pdo->prepare("UPDATE quran_sessions SET un_juz_par_membre=? WHERE serie_id=?")->execute([$val, $serieId]);
                } else {
                    $pdo->prepare("UPDATE quran_sessions SET un_juz_par_membre=? WHERE id=?")->execute([$val, $sid]);
                }
                $success = $val === 1 ? "Réglage appliqué : un seul Juz par membre." : "Réglage appliqué : plusieurs Juz autorisés par membre.";
            } elseif ($action === 'delete_session' && $sid > 0) {
                $pdo->prepare("DELETE FROM quran_parts WHERE session_id=?")->execute([$sid]);
                $pdo->prepare("DELETE FROM quran_sessions WHERE id=?")->execute([$sid]);
                $success = "Session supprimée.";
            } elseif ($action === 'save_wa_group') {
                $link = trim($_POST['wa_group_link'] ?? '');
                $pdo->prepare("INSERT INTO app_settings (cle, valeur) VALUES ('wa_group_link', ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)")->execute([$link !== '' ? $link : null]);
                $success = $link !== '' ? "Groupe WhatsApp par défaut enregistré." : "Groupe WhatsApp par défaut retiré.";
            } elseif ($action === 'notify_to_validate' && $sid > 0) {
                // Relance auprès des personnes ayant des Juz réservés (non validés)
                // dans la session : e-mail + notification cloche.
                require_once __DIR__ . '/send_mail.php';
                $parts = $pdo->prepare(
                    "SELECT p.id AS part_id, p.numero, p.membre_id, p.membre_nom,
                            m.email, m.prenom, m.nom, s.titre AS session_titre, s.token,
                            s.periodicite, s.realloc_meme_membre
                     FROM quran_parts p
                     JOIN quran_sessions s ON s.id = p.session_id
                     LEFT JOIN membres m ON m.id = p.membre_id
                     WHERE p.session_id = ? AND p.statut = 'reservee'
                     ORDER BY p.membre_id, p.numero"
                );
                $parts->execute([$sid]);
                $parts = $parts->fetchAll();

                // Regrouper les Juz par membre
                $byMembre = [];
                $sessionTitre = '';
                $sessionToken = '';
                $sessionPer = 'ponctuelle';
                $sessionRealloc = 0;
                foreach ($parts as $pt) {
                    $sessionTitre = $pt['session_titre'];
                    $sessionToken = $pt['token'];
                    $sessionPer = (string)($pt['periodicite'] ?? 'ponctuelle');
                    $sessionRealloc = (int)($pt['realloc_meme_membre'] ?? 0);
                    $byMembre[(int)$pt['membre_id']][] = $pt;
                }
                // Encart périodicité (sessions périodiques uniquement) pour l'e-mail
                $perLabelsMail = ['semaine' => 'chaque semaine (hebdomadaire)', 'quinzaine' => 'toutes les deux semaines', 'mois' => 'chaque mois (mensuelle)'];
                $periodHtml = '';
                if ($sessionPer !== 'ponctuelle') {
                    $periodHtml = '<p style="background:#eef6f1;border:1px solid #2d6a4f;border-radius:8px;padding:10px 14px;color:#1b4332;font-size:14px;">'
                        . '🔁 <strong>Lecture périodique</strong> : ' . htmlspecialchars($perLabelsMail[$sessionPer] ?? $sessionPer) . '.<br>'
                        . ($sessionRealloc === 1
                            ? '👥 Vos Juz vous sont <strong>réattribués automatiquement à chaque période</strong> — merci de les relire et valider à chaque fois.'
                            : '🆕 À chaque période, les Juz sont remis à zéro (choix libre).')
                        . '</p>';
                }

                if (empty($byMembre)) {
                    $error = "Aucun Juz réservé à valider dans cette session.";
                } else {
                    $nEnvoyes = 0;
                    $nNotif = 0;
                    $lienBase = $baseUrl . '/wird.php?s=' . (int)$sid . '&t=' . htmlspecialchars($sessionToken, ENT_QUOTES);
                    foreach ($byMembre as $mid => $pts) {
                        $nums = array_map(static function ($p) { return (int)$p['numero']; }, $pts);
                        sort($nums);
                        $listeJuz = implode(', ', $nums);
                        $email = $pts[0]['email'] ?? '';
                        $prenom = $pts[0]['prenom'] ?? '';
                        $nom = $pts[0]['nom'] ?? '';
                        $destinataire = trim($prenom . ' ' . $nom) !== '' ? trim($prenom . ' ' . $nom) : ($pts[0]['membre_nom'] ?? 'Cher membre');

                        // 1) Cloche navigateur (déposée en premier : jamais bloquant)
                        // Le clic sur la notification mène à l'accueil (index.php).
                        troba_notify_membre(
                            $pdo,
                            (int)$mid,
                            'wird_relance',
                            '📖 Juz à valider — ' . $sessionTitre,
                            'Vous avez ' . count($pts) . ' Juz réservé(s) (' . $listeJuz . ") en attente de validation. Rendez-vous sur l'accueil pour les valider.",
                            'index.php'
                        );
                        $nNotif++;

                        // 2) E-mail (voie principale)
                        if ($email !== '') {
                            $sujet = '📖 ' . $sessionTitre . ' — vos Juz à valider';
                            $corpsHtml =
                                '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
                                . '<h2 style="color:#1b4332;">Assalamu aleykum ' . htmlspecialchars($destinataire) . ' 🙏</h2>'
                                . '<p style="color:#333;">Dans le cadre de la lecture collective du Coran '
                                . '<strong>' . htmlspecialchars($sessionTitre) . '</strong>, vous avez réservé les Juz suivants :</p>'
                                . '<p style="background:#f6f3e8;border:1px solid #d4af37;border-radius:8px;padding:12px 16px;font-size:18px;font-weight:bold;color:#1b4332;text-align:center;">Juz ' . $listeJuz . '</p>'
                                . $periodHtml
                                . '<p style="color:#333;">Pensez à valider votre lecture pour faire avancer le Khatm. 🤲</p>'
                                . dahira_email_button($lienBase, 'Valider mes Juz')
                                . '<p style="color:#888;font-size:12px;">— Commission Culte, Touba Lyon</p>'
                                . '</div>';
                            $okMail = send_smtp_mail($email, $destinataire, $sujet, $corpsHtml);
                            if ($okMail) { $nEnvoyes++; }
                        }
                    }
                    $success = count($byMembre) . ' membre(s) relancé(s) — ' . $nNotif . ' notification(s) cloche, ' . $nEnvoyes . ' e-mail(s) envoyé(s).';
                }
            } elseif ($action === 'add_delegue') {
                $dm = (int) ($_POST['membre_id'] ?? 0);
                $chk = $pdo->prepare("SELECT id, prenom, nom FROM membres WHERE id = ? AND status = 'approved'");
                $chk->execute([$dm]);
                $dmRow = $chk->fetch();
                if (!$dmRow) {
                    $error = "Choisissez un membre.";
                } else {
                    $ins = $pdo->prepare("INSERT IGNORE INTO culte_delegues (membre_id, granted_by) VALUES (?, ?)");
                    $ins->execute([$dm, $__wirdMemberId > 0 ? $__wirdMemberId : null]);
                    if ($ins->rowCount() > 0) {
                        troba_notify_membre($pdo, $dm, 'culte_delegue', '📖 Création de sessions de lecture', "La commission Culte vous autorise à créer des sessions de lecture du Coran (Kamil). Retrouvez « Lecture Coran » dans votre mode gestion.", 'wird_admin.php');
                    }
                    $success = trim($dmRow['prenom'] . ' ' . $dmRow['nom']) . " peut maintenant créer des sessions de lecture.";
                }
            } elseif ($action === 'remove_delegue') {
                $dm = (int) ($_POST['membre_id'] ?? 0);
                $pdo->prepare("DELETE FROM culte_delegues WHERE membre_id = ?")->execute([$dm]);
                $success = "Droit de création retiré.";
            } else {
                $error = "Action non autorisée.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Touba Lyon wird_admin: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
}

try {
    $sessions = $pdo->query("SELECT * FROM quran_sessions ORDER BY (statut='en_cours') DESC, created_at DESC")->fetchAll();
    // Délégué / gestionnaire de Kamil : uniquement les sessions qui lui sont confiées
    if (!$__isCulteManager) {
        $sessions = array_values(array_filter($sessions, function ($row) use ($__kamilKeys) { return wird_can_manage_session($row, false, $__kamilKeys); }));
    }
    // Indicateurs par session : Juz lus, réservés, libres, et nombre de participants distincts
    $prog = [];
    $rows = $pdo->query("
        SELECT session_id,
               SUM(statut='lue') AS lues,
               SUM(statut='reservee') AS res,
               SUM(statut='libre') AS libres,
               COUNT(*) AS tot,
               COUNT(DISTINCT CASE WHEN membre_id IS NOT NULL AND membre_id > 0 THEN membre_id END) AS participants
        FROM quran_parts GROUP BY session_id
    ");
    foreach ($rows as $r) { $prog[(int)$r['session_id']] = $r; }
} catch (Exception $e) {
    error_log('Touba Lyon wird_admin (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
$baseUrl = rtrim(dahira_base_url(), '/');
$waGroup = '';
try { $waGroup = (string) $pdo->query("SELECT valeur FROM app_settings WHERE cle='wa_group_link'")->fetchColumn(); } catch (Exception $e) { $waGroup = ''; }
$groupes = [];
try { $groupes = $pdo->query("SELECT nom FROM quran_groupes ORDER BY nom ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $groupes = []; }
// Responsables Culte : délégués autorisés à créer des sessions + membres proposables
$delegues = [];
$membresPick = [];
if ($__isCulteManager) {
    try {
        $delegues = $pdo->query("SELECT m.id, m.prenom, m.nom FROM culte_delegues d JOIN membres m ON m.id = d.membre_id ORDER BY m.prenom, m.nom")->fetchAll();
        $membresPick = $pdo->query("SELECT id, prenom, nom FROM membres WHERE status = 'approved' AND id NOT IN (SELECT membre_id FROM culte_delegues) ORDER BY prenom, nom")->fetchAll();
    } catch (Exception $e) { $delegues = []; $membresPick = []; }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecture du Coran - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .wird-wrap { max-width: 900px; margin: 2rem auto; }
        .wird-card { border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1rem; transition: var(--transition); }
        .wird-card:hover { border-color: rgba(212,175,55,0.35); }
        .wird-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .wird-head h3 { margin: 0; color: var(--white); font-size: 1.15rem; }
        .wird-prog { height: 8px; border-radius: 12px; background: rgba(255,255,255,0.08); overflow: hidden; margin: 0.75rem 0; }
        .wird-prog > i { display: block; height: 100%; background: linear-gradient(90deg,#2d6a4f,#7bd8a6); }
        .wird-meta { color: var(--text-muted); font-size: 0.85rem; }
        .wird-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.85rem; }
        .wird-actions .btn { padding: 0.35rem 0.7rem; font-size: 0.78rem; }
        .wird-link { display: flex; gap: 0.4rem; margin-top: 0.6rem; }
        .wird-link input { flex: 1; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 8px; color: #fff; font-size: 0.8rem; padding: 0.45rem 0.7rem; }
        /* ── En-têtes de section (En cours / Clôturées) ── */
        .wird-section-head { display: flex; align-items: center; gap: 0.7rem; margin: 1.6rem 0 0.9rem; }
        .wird-section-head:first-of-type { margin-top: 0.5rem; }
        .wird-section-head h2 { margin: 0; font-size: 1.1rem; color: var(--white); font-weight: 700; }
        .wird-section-head .wird-section-count { font-size: 0.72rem; font-weight: 800; color: #0c241a; background: linear-gradient(135deg,#d4af37,#f1d279); border-radius: 12px; padding: 2px 10px; }
        .wird-section-head .wird-caret { margin-left: auto; color: var(--text-muted); font-size: 0.9rem; cursor: pointer; }
        /* ── Grille d'indicateurs ── */
        .wird-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.6rem; margin: 0.85rem 0 0.35rem; }
        .wird-stat { background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.55rem 0.7rem; text-align: center; }
        .wird-stat .v { font-size: 1.25rem; font-weight: 800; color: var(--white); line-height: 1.1; }
        .wird-stat .v.gold { color: var(--gold); }
        .wird-stat .v.green { color: #7bd8a6; }
        .wird-stat .v.blue { color: #7dd3fc; }
        .wird-stat .l { font-size: 0.68rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 0.15rem; }
        .wird-collapsible { display: none; }
        .wird-collapsible.open { display: block; }
        /* Sélecteurs de date : forcer à tenir dans leur colonne (webkit impose une largeur mini) */
        .wird-date-row input[type="date"] {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
            -webkit-appearance: none;
            appearance: none;
        }
        /* Date de début / date de fin : empilées sur mobile */
        @media (max-width: 600px) {
            .wird-date-row { flex-direction: column; }
            .wird-date-row > div { min-width: 0 !important; width: 100%; }
            .wird-date-row input[type="date"] { min-width: 0 !important; width: 100% !important; font-size: 0.9rem; padding-left: 0.6rem; padding-right: 0.4rem; }
            /* En-tête de session : infos centrées, pleine largeur, badges regroupés */
            .wird-head { flex-direction: column; align-items: center; text-align: center; }
            .wird-head > div { width: 100%; }
            .wird-head h3 { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 0.3rem; }
            .wird-head .wird-meta { text-align: center !important; }
            /* Boutons d'action : empilés, centrés, pleine largeur */
            .wird-actions { flex-direction: column; align-items: stretch; gap: 0.5rem; }
            .wird-actions > * { width: 100%; }
            .wird-actions form { display: block; width: 100%; margin: 0; }
            .wird-actions .btn { width: 100%; justify-content: center; text-align: center; }
            /* Lien de partage (copier) : champ + bouton empilés pleine largeur */
            .wird-link { flex-direction: column; }
            .wird-link .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">

        <div class="wird-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:12px; flex-wrap:wrap; gap:1rem;">
                <span>📖 Lecture du Coran — <strong class="gold-text"><?php echo count($sessions); ?></strong> session(s)</span>
                <a href="<?php echo $__isAdmin ? 'admin_dashboard.php' : 'index.php'; ?>" class="btn btn-secondary btn-sm">← Retour</a>
            </div>

            <?php
                $sessionsEnCours = [];
                $sessionsCloturees = [];
                // Les périodes d'une même série périodique sont regroupées : une
                // seule carte représentative par série (le calendrier complet est
                // visible dans le suivi). Les sessions ponctuelles restent isolées.
                $serieBuckets = [];
                foreach ($sessions as $s) {
                    $sPer = $s['periodicite'] ?? 'ponctuelle';
                    if ($sPer === 'ponctuelle' || empty($s['serie_id'])) {
                        if ($s['statut'] === 'en_cours') { $sessionsEnCours[] = $s; }
                        else { $sessionsCloturees[] = $s; }
                    } else {
                        $serieBuckets[(int)$s['serie_id']][] = $s;
                    }
                }
                foreach ($serieBuckets as $skey => $periods) {
                    usort($periods, function ($a, $b) {
                        $da = (string)($a['date_debut'] ?? ''); $db = (string)($b['date_debut'] ?? '');
                        if ($da === $db) { return ((int)$a['id']) <=> ((int)$b['id']); }
                        return strcmp($da, $db);
                    });
                    $hasEnCours = false;
                    foreach ($periods as $pp) { if ($pp['statut'] === 'en_cours') { $hasEnCours = true; break; } }
                    // Représentant : 1re période en cours (par date), sinon la dernière
                    $rep = null;
                    foreach ($periods as $pp) { if ($pp['statut'] === 'en_cours') { $rep = $pp; break; } }
                    if (!$rep) { $rep = $periods[count($periods) - 1]; }
                    $rep['_serie_count'] = count($periods);
                    $rep['_serie_start'] = $periods[0]['date_debut'] ?? null;
                    $rep['_serie_end']   = $periods[count($periods) - 1]['date_fin'] ?? null;
                    if ($hasEnCours) { $sessionsEnCours[] = $rep; }
                    else { $sessionsCloturees[] = $rep; }
                }
                // Fonction de rendu d'une carte session (réutilisée pour les 2 sections)
                $renderSessionCard = function ($s) use ($pdo, $prog, $baseUrl, $waGroup) {
                    $p = $prog[(int)$s['id']] ?? ['lues' => 0, 'res' => 0, 'libres' => 30, 'tot' => 30, 'participants' => 0];
                    $lues = (int)$p['lues']; $res = (int)$p['res']; $libres = (int)($p['libres'] ?? 0);
                    $participants = (int)($p['participants'] ?? 0);
                    $tot = max(1, (int)$p['tot']);
                    $pct = round($lues / $tot * 100);
                    $link = $baseUrl . '/wird.php?s=' . (int)$s['id'] . '&t=' . htmlspecialchars($s['token'], ENT_QUOTES);
                    $enCours = ($s['statut'] === 'en_cours');
                    // Détail de périodicité (sessions périodiques uniquement)
                    $perLabelsMsg = ['semaine' => 'chaque semaine (hebdomadaire)', 'quinzaine' => 'toutes les deux semaines', 'mois' => 'chaque mois (mensuelle)'];
                    $perMsg = (string)($s['periodicite'] ?? 'ponctuelle');
                    $reallocMsg = ((int)($s['realloc_meme_membre'] ?? 0) === 1);
                    $periodTxt = '';
                    if ($perMsg !== 'ponctuelle') {
                        $periodTxt = "\n🔁 *Lecture périodique* : " . ($perLabelsMsg[$perMsg] ?? $perMsg) . ".\n"
                            . ($reallocMsg
                                ? "👥 Les mêmes Juz sont réattribués aux mêmes personnes à chaque période.\n"
                                : "🆕 Les Juz sont remis à zéro (choix libre) à chaque période.\n");
                    } elseif (!empty($s['date_fin'])) {
                        // Session ponctuelle avec date de clôture (échéance de validation)
                        $periodTxt = "\n⏳ *À valider avant le " . date('d/m/Y', strtotime((string)$s['date_fin'])) . "*.\n";
                    }
                    $shareMsg = "Assalamu aleykum 🙏\n\n"
                        . "📖 *Lecture collective du Coran (Khatm)*\n"
                        . "« " . $s['titre'] . " »\n"
                        . (!empty($s['description']) ? ($s['description'] . "\n") : "")
                        . "\nNous lançons la lecture des *30 Juz* du Saint Coran. 🌙\n"
                        . $periodTxt
                        . "\n👉 Choisissez votre Juz ici :\n" . $link . "\n\n"
                        . "Qu'Allah accepte nos lectures et nos invocations. 🤲\n"
                        . "Commission Culte,\nTouba Lyon";
                    $out = '<div class="glass-card wird-card">';
                    $out .= '<div class="wird-head">';
                    $out .= '<div>';
                    $out .= '<h3>📖 ' . htmlspecialchars($s['titre']) . ' <span class="badge ' . ($enCours ? 'badge-approved' : 'badge-pending') . '" style="margin-left:0.4rem;">' . ($enCours ? 'En cours' : 'Clôturée') . '</span>';
                    if ($enCours) {
                        $lanceB = ((int)($s['lance'] ?? 1) === 1);
                        $out .= '<span class="badge" style="' . ($lanceB ? 'background:rgba(45,106,79,0.25); color:#7bd8a6; border:1px solid rgba(45,106,79,0.5);' : 'background:rgba(255,255,255,0.06); color:var(--text-muted);') . ' margin-left:0.3rem;">' . ($lanceB ? '🚀 Lancée' : '⏳ En attente') . '</span>';
                    }
                    if (!empty($s['groupe'])) { $out .= '<span class="badge" style="background:rgba(212,175,55,0.15); color:var(--gold); border:1px solid rgba(212,175,55,0.4); margin-left:0.3rem;">' . htmlspecialchars($s['groupe']) . '</span>'; }
                    $perLabels = ['semaine' => 'Hebdomadaire', 'quinzaine' => 'Quinzaine', 'mois' => 'Mensuelle'];
                    $per = (string)($s['periodicite'] ?? 'ponctuelle');
                    if ($per !== 'ponctuelle') {
                        $reallocOn = ((int)($s['realloc_meme_membre'] ?? 0) === 1);
                        $out .= '<span class="badge" style="background:rgba(125,211,252,0.15); color:#7dd3fc; border:1px solid rgba(125,211,252,0.4); margin-left:0.3rem;">🔁 ' . htmlspecialchars($perLabels[$per] ?? $per) . ($reallocOn ? ' · mêmes membres' : '') . '</span>';
                    }
                    // Série regroupée : badge « N périodes »
                    if (!empty($s['_serie_count'])) {
                        $out .= '<span class="badge" style="background:rgba(212,175,55,0.15); color:var(--gold); border:1px solid rgba(212,175,55,0.4); margin-left:0.3rem;">📚 Série · ' . (int)$s['_serie_count'] . ' périodes</span>';
                    }
                    // Dates : plage de la série entière si regroupée, sinon la période
                    $ddeb = !empty($s['_serie_count']) ? ($s['_serie_start'] ?? null) : ($s['date_debut'] ?? null);
                    $dfin = !empty($s['_serie_count']) ? ($s['_serie_end'] ?? null) : ($s['date_fin'] ?? null);
                    if (!empty($ddeb)) {
                        $drTxt = 'du ' . date('d/m/Y', strtotime($ddeb)) . (!empty($dfin) ? ' au ' . date('d/m/Y', strtotime($dfin)) : '');
                        $out .= '<span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-muted); margin-left:0.3rem;">🗓️ ' . htmlspecialchars($drTxt) . '</span>';
                    }
                    $unJ = ((int)($s['un_juz_par_membre'] ?? 0) === 1);
                    $out .= '<span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-muted); margin-left:0.3rem;">' . ($unJ ? '1 Juz / membre' : 'Multi-Juz') . '</span>';
                    $out .= '</h3>';
                    if (!empty($s['description'])) { $out .= '<div class="wird-meta">' . htmlspecialchars($s['description']) . '</div>'; }
                    // Période correspondante (courante) pour une série périodique
                    if ($per !== 'ponctuelle') {
                        $curPerLbl = wird_period_label($pdo, $s);
                        if ($curPerLbl !== '') { $out .= '<div class="wird-meta" style="color:#7dd3fc; font-weight:600;">📍 Période en cours : ' . htmlspecialchars($curPerLbl) . '</div>'; }
                    }
                    $out .= '</div>';
                    $out .= '<div class="wird-meta" style="text-align:right;"><strong class="gold-text">' . $lues . '/' . $tot . '</strong> lues<br>' . $res . ' réservées</div>';
                    $out .= '</div>';
                    $out .= '<div class="wird-prog"><i style="width:' . $pct . '%;"></i></div>';
                    // ── Indicateurs ──
                    $out .= '<div class="wird-stats">';
                    $out .= '<div class="wird-stat"><div class="v gold">' . $lues . '</div><div class="l">Juz lus</div></div>';
                    $out .= '<div class="wird-stat"><div class="v blue">' . $participants . '</div><div class="l">Participants</div></div>';
                    $out .= '<div class="wird-stat"><div class="v green">' . $res . '</div><div class="l">Réservés</div></div>';
                    $out .= '<div class="wird-stat"><div class="v">' . $libres . '</div><div class="l">Juz libres</div></div>';
                    $out .= '<div class="wird-stat"><div class="v">' . $pct . '%</div><div class="l">Complété</div></div>';
                    $out .= '<div class="wird-stat"><div class="v" style="font-size:1rem;">' . wird_duree_jh($s['created_at'] ?? null, $s['closed_at'] ?? null) . '</div><div class="l">Durée</div></div>';
                    $out .= '</div>';
                    $out .= '<div class="wird-link">';
                    $out .= '<input type="text" readonly value="' . $link . '" id="wl-' . (int)$s['id'] . '" onclick="this.select()">';
                    $out .= '<button type="button" class="btn btn-secondary btn-sm" onclick="copyWird(\'wl-' . (int)$s['id'] . '\')">📋 Copier</button>';
                    $out .= '</div>';
                    $out .= '<div class="wird-actions">';
                    // Seuls « Copier » (ci-dessus) et « Suivi » restent sur la carte ;
                    // toutes les autres actions ont été déplacées dans wird_suivi.php.
                    $out .= '<a href="wird_suivi.php?s=' . (int)$s['id'] . '" class="btn btn-secondary btn-sm">📊 Suivi</a>';
                    $out .= '</div>';
                    $out .= '</div>';
                    return $out;
                };
            ?>

            <!-- ════ SESSIONS EN COURS (haut de page) ════ -->
            <?php if (!empty($sessionsEnCours)): ?>
                <div class="wird-section-head">
                    <h2>🟢 Sessions en cours</h2>
                    <span class="wird-section-count"><?php echo count($sessionsEnCours); ?></span>
                </div>
                <?php foreach ($sessionsEnCours as $s): ?>
                    <?php echo $renderSessionCard($s); ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state" style="padding:1.5rem;"><div class="empty-state-icon">📖</div><p>Aucune session en cours. Lancez-en une ci-dessous.</p></div>
            <?php endif; ?>

            <!-- ════ FORMULAIRE « Lancer une session » (au milieu) ════ -->
            <?php if ($__isCulteManager || $__isCulteDelegate): ?>
            <div class="form-card" style="max-width:none; margin-top:1.5rem; padding:1.5rem; border:1px solid rgba(212,175,55,0.4);">
                <h3 class="gold-text" style="font-size:1.15rem; font-weight:700; margin:0 0 1rem;">➕ Lancer une session de lecture (Khatm — 30 Juz)</h3>
                <form action="wird_admin.php" method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_session">
                    <div class="form-group"><label class="form-label">Titre <span style="color:var(--danger)">*</span></label><input type="text" name="titre" class="form-input" required placeholder="Ex : Khatm Magal 2026"></div>
                    <div class="form-group"><label class="form-label">Groupe</label>
                        <select name="groupe" class="form-input">
                            <option value="">— Aucun —</option>
                            <?php foreach ($groupes as $g): ?><option value="<?php echo htmlspecialchars($g, ENT_QUOTES); ?>"><?php echo htmlspecialchars($g); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Description (facultatif)</label><input type="text" name="description" class="form-input" placeholder="Intention, date limite…"></div>
                    <div class="form-group"><label class="form-label">Juz par membre</label>
                        <select name="juz_par_membre" class="form-input">
                            <option value="un" selected>Un seul Juz par membre (recommandé)</option>
                            <option value="plusieurs">Plusieurs Juz autorisés par membre</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Périodicité</label>
                        <select name="periodicite" id="wird-periodicite" class="form-input" onchange="wirdTogglePeriod()">
                            <option value="ponctuelle" selected>Ponctuelle — une seule fois</option>
                            <option value="semaine">Périodique — chaque semaine</option>
                            <option value="quinzaine">Périodique — toutes les 2 semaines</option>
                            <option value="mois">Périodique — chaque mois</option>
                        </select>
                    </div>
                    <div class="form-group" id="wird-ponctuelle-group">
                        <label class="form-label">🗓️ Date de clôture (facultatif)</label>
                        <input type="date" name="date_cloture" class="form-input" min="<?php echo date('Y-m-d'); ?>" style="width:100%; max-width:100%; box-sizing:border-box;">
                        <p style="color:var(--text-muted); font-size:0.78rem; margin:0.35rem 0 0;">Échéance affichée aux membres pour valider leur lecture. Laissez vide si sans limite.</p>
                    </div>
                    <div class="form-group" id="wird-realloc-group">
                        <label class="form-label">🗓️ Calendrier de la série</label>
                        <div class="wird-date-row" style="display:flex; gap:0.6rem; flex-wrap:wrap;">
                            <div style="flex:1; min-width:150px;">
                                <label style="color:var(--text-muted); font-size:0.8rem; display:block; margin-bottom:0.2rem;">Date de début</label>
                                <input type="date" name="date_debut" class="form-input" style="width:100%; max-width:100%; box-sizing:border-box;" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div style="flex:1; min-width:150px;">
                                <label style="color:var(--text-muted); font-size:0.8rem; display:block; margin-bottom:0.2rem;">Date de fin</label>
                                <input type="date" name="date_fin" class="form-input" style="width:100%; max-width:100%; box-sizing:border-box;" value="<?php echo date('Y-m-d', strtotime('+3 weeks')); ?>">
                            </div>
                        </div>
                        <label style="display:flex; align-items:center; gap:0.5rem; color:#fff; font-size:0.9rem; cursor:pointer; margin-top:0.7rem;">
                            <input type="checkbox" name="realloc_meme_membre" value="1" checked>
                            Réattribuer automatiquement les mêmes Juz aux mêmes personnes
                        </label>
                        <p style="color:var(--text-muted); font-size:0.78rem; margin:0.35rem 0 0;">Un calendrier de N périodes datées est généré ; le suivi affiche l'avancement de chaque période. Décochez la case pour repartir de zéro à chaque nouvelle période.</p>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Lancer la session</button>
                </form>
                <div style="border-top:1px solid var(--glass-border); margin-top:1rem; padding-top:0.85rem;">
                    <form action="wird_admin.php" method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_groupe">
                        <span style="color:var(--text-muted); font-size:0.82rem;">➕ Nouveau groupe :</span>
                        <input type="text" name="groupe_nom" class="form-input" placeholder="Ex : Kazu Rajab" style="flex:1; min-width:160px;">
                        <button type="submit" class="btn btn-secondary btn-sm">Ajouter</button>
                    </form>
                </div>
            </div>

            <?php endif; ?>

            <!-- ════ SESSIONS CLÔTURÉES (bas de page, repliées par défaut) ════ -->
            <?php if (!empty($sessionsCloturees)): ?>
                <div class="wird-section-head" style="margin-top:2rem;">
                    <h2>🔒 Sessions clôturées</h2>
                    <span class="wird-section-count"><?php echo count($sessionsCloturees); ?></span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleWirdClosed()" aria-expanded="false" id="wird-closed-toggle">Afficher <span id="wird-closed-caret">▾</span></button>
                </div>
                <div id="wird-closed" class="wird-collapsible">
                    <?php foreach ($sessionsCloturees as $s): ?>
                        <?php echo $renderSessionCard($s); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($__isCulteManager): ?>
            <!-- Paramètres (repliable, fermé par défaut) -->
            <div style="margin-top:1.75rem;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleWirdParams()">⚙️ Paramètres <span id="wp-caret">▾</span></button>
            </div>
            <div id="wird-params" style="display:none; margin-top:0.75rem;">
                <div class="form-card" style="max-width:none; padding:1.1rem 1.5rem; margin-bottom:1rem;">
                    <h3 class="gold-text" style="font-size:1rem; font-weight:700; margin:0 0 0.5rem;">👥 Membres autorisés à créer des sessions</h3>
                    <p style="color:var(--text-muted); font-size:0.82rem; margin:0 0 0.75rem;">Ces membres peuvent créer leurs propres sessions de lecture (Kamil) et en suivre l'avancement. Ils ne voient pas les autres sessions.</p>
                    <?php if (empty($delegues)): ?>
                        <p style="color:var(--text-muted); font-size:0.85rem; margin:0 0 0.75rem;">Aucun membre désigné.</p>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:0.4rem; margin-bottom:0.85rem;">
                        <?php foreach ($delegues as $dg): ?>
                            <form action="wird_admin.php" method="POST" style="margin:0; display:flex; align-items:center; justify-content:space-between; gap:0.5rem; background:rgba(255,255,255,0.04); border:1px solid var(--glass-border); border-radius:8px; padding:0.4rem 0.75rem;" data-confirm="Retirer le droit de création de sessions à <?php echo htmlspecialchars(trim($dg['prenom'] . ' ' . $dg['nom']), ENT_QUOTES); ?> ?" data-confirm-danger="1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="remove_delegue">
                                <input type="hidden" name="membre_id" value="<?php echo (int) $dg['id']; ?>">
                                <span><?php echo htmlspecialchars(trim($dg['prenom'] . ' ' . $dg['nom'])); ?></span>
                                <button type="submit" class="btn btn-secondary btn-sm" style="border-color:var(--danger); color:#fca5a5;">Retirer</button>
                            </form>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form action="wird_admin.php" method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_delegue">
                        <select name="membre_id" class="form-input" required style="flex:1; min-width:200px;">
                            <option value="">— Choisir un membre —</option>
                            <?php foreach ($membresPick as $mp): ?><option value="<?php echo (int) $mp['id']; ?>"><?php echo htmlspecialchars(trim($mp['prenom'] . ' ' . $mp['nom'])); ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-secondary btn-sm">Autoriser</button>
                    </form>
                </div>
                <div class="form-card" style="max-width:none; padding:1.1rem 1.5rem;">
                    <h3 class="gold-text" style="font-size:1rem; font-weight:700; margin:0 0 0.5rem;">🟢 Groupe WhatsApp par défaut (facultatif)</h3>
                    <p style="color:var(--text-muted); font-size:0.82rem; margin:0 0 0.75rem;">Collez le <strong>lien d'invitation</strong> du groupe (chat.whatsapp.com/…). Le bouton « Groupe » d'une session copiera le message et ouvrira le groupe pour le coller (WhatsApp ne permet pas l'envoi automatique dans un groupe).</p>
                    <form action="wird_admin.php" method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_wa_group">
                        <input type="url" name="wa_group_link" class="form-input" placeholder="https://chat.whatsapp.com/…" value="<?php echo htmlspecialchars($waGroup, ENT_QUOTES); ?>" style="flex:1; min-width:220px;">
                        <button type="submit" class="btn btn-secondary btn-sm">Enregistrer</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

            </div>
        </div>
    </main>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header"><?php if (!empty($success)): ?><h3 class="gold-text">Réussi</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?></div>
            <div class="modal-body"><p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal de confirmation -->
    <div id="confirm-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card">
            <div class="modal-header"><h3 class="gold-text" id="confirm-title">Confirmation</h3></div>
            <div class="modal-body"><p id="confirm-message"></p></div>
            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                <button type="button" onclick="closeConfirmModal()" class="btn btn-secondary btn-sm">Annuler</button>
                <button type="button" id="confirm-btn" class="btn btn-primary btn-sm">Confirmer</button>
            </div>
        </div>
    </div>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        var confirmTargetForm = '';
        function confirmWird(formId, title, message) {
            confirmTargetForm = formId;
            document.getElementById('confirm-title').textContent = title;
            document.getElementById('confirm-message').textContent = message;
            
            var btn = document.getElementById('confirm-btn');
            if (title.toLowerCase() === 'supprimer') {
                btn.className = 'btn btn-danger btn-sm';
                btn.style = '';
            } else if (title.toLowerCase() === 'clôturer') {
                btn.className = 'btn btn-secondary btn-sm';
                btn.style.color = 'var(--warning)';
                btn.style.borderColor = 'var(--warning)';
            } else if (title.indexOf('Relancer') === 0) {
                btn.className = 'btn btn-primary btn-sm';
                btn.style = '';
            } else {
                btn.className = 'btn btn-primary btn-sm';
                btn.style = '';
            }
            
            var modal = document.getElementById('confirm-modal');
            modal.style.display = 'flex';
            setTimeout(function() {
                modal.classList.add('active');
            }, 10);
        }
        function confirmNotif(formId, title, message) { confirmWird(formId, title, message); }
        function closeConfirmModal() {
            var modal = document.getElementById('confirm-modal');
            modal.classList.remove('active');
            setTimeout(function() {
                modal.style.display = 'none';
            }, 400);
        }
        document.getElementById('confirm-btn').addEventListener('click', function() {
            if (confirmTargetForm) {
                document.getElementById(confirmTargetForm).submit();
            }
        });

        function copyWird(id){
            var el = document.getElementById(id); if (!el) return;
            el.select(); el.setSelectionRange(0, 99999);
            try { navigator.clipboard.writeText(el.value); } catch (e) { document.execCommand('copy'); }
            var b = event.target; var t = b.textContent; b.textContent = '✅ Copié'; setTimeout(function(){ b.textContent = t; }, 1500);
        }
        function shareGroup(msg, link){
            try { navigator.clipboard.writeText(msg); } catch (e) {}
            window.location.href = link;
        }
        // Afficher l'option de réallocation seulement si la session est périodique
        function wirdTogglePeriod(){
            var sel = document.getElementById('wird-periodicite');
            var grp = document.getElementById('wird-realloc-group');
            var grpP = document.getElementById('wird-ponctuelle-group');
            if (!sel || !grp) return;
            var estPonctuelle = (sel.value === 'ponctuelle');
            grp.style.display = estPonctuelle ? 'none' : 'block';
            if (grpP) { grpP.style.display = estPonctuelle ? 'block' : 'none'; }
        }
        document.addEventListener('DOMContentLoaded', wirdTogglePeriod);
        function toggleWirdParams(){
            var d = document.getElementById('wird-params'); var c = document.getElementById('wp-caret');
            var show = (d.style.display === 'none' || d.style.display === '');
            d.style.display = show ? 'block' : 'none';
            if (c) c.textContent = show ? '▴' : '▾';
        }
        function toggleWirdClosed(){
            var d = document.getElementById('wird-closed'); var b = document.getElementById('wird-closed-toggle');
            var c = document.getElementById('wird-closed-caret');
            var open = d.classList.toggle('open');
            if (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); b.textContent = open ? 'Masquer' : 'Afficher'; }
            if (c) c.textContent = open ? '▴' : '▾';
        }
    </script>
<script src="modern-date.js" defer></script>
</body>
</html>
