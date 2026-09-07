<?php
/**
 * Touba Lyon 2026 - Réunions de commission
 *
 * Fonctions partagées : périodicité (génération des dates d'une série),
 * libellés, et notification (cloche + e-mail) des membres d'une commission
 * lorsqu'une réunion est publiée.
 */
require_once __DIR__ . '/dahira_emails.php';       // send_smtp_mail(), dahira_base_url(), dahira_email_button()
require_once __DIR__ . '/notification_helper.php'; // troba_notify_membre()

/** Libellé lisible d'une périodicité. */
function reunion_periodicite_label(string $p): string
{
    switch ($p) {
        case 'hebdomadaire': return 'Hebdomadaire';
        case 'quinzaine':    return 'Une semaine sur deux';
        case 'mensuelle':    return 'Mensuelle';
        default:             return 'Ponctuelle';
    }
}

/**
 * Génère les dates d'une série périodique entre deux dates (incluses).
 * Retourne un tableau de dates 'Y-m-d'. Pour « ponctuelle », uniquement $debut.
 */
function reunion_generer_dates(string $debut, string $fin, string $periodicite): array
{
    $out = [];
    $ts = strtotime($debut);
    if ($ts === false) { return $out; }
    if ($periodicite === 'ponctuelle' || $periodicite === '') {
        return [date('Y-m-d', $ts)];
    }
    $end = strtotime($fin);
    if ($end === false || $end < $ts) { $end = $ts; }
    $step = '+1 week';
    if ($periodicite === 'quinzaine') { $step = '+2 weeks'; }
    elseif ($periodicite === 'mensuelle') { $step = '+1 month'; }
    $cur = $ts;
    $garde = 0;
    while ($cur <= $end && $garde < 260) {
        $out[] = date('Y-m-d', $cur);
        $cur = strtotime($step, $cur);
        $garde++;
    }
    return $out;
}

/** Y a-t-il une période antérieure NON clôturée dans la même série ? */
function reunion_precedente_non_cloturee(PDO $pdo, array $r): bool
{
    $serie = (int) ($r['serie_id'] ?: $r['id']);
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM commission_reunions WHERE serie_id = ? AND id <> ? AND date_reunion < ? AND cloture = 0");
        $q->execute([$serie, (int) $r['id'], $r['date_reunion']]);
        return ((int) $q->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/** Prochaine période de la série (date postérieure), non publiée et non clôturée. */
function reunion_prochaine_a_publier(PDO $pdo, array $r): ?array
{
    $serie = (int) ($r['serie_id'] ?: $r['id']);
    try {
        $q = $pdo->prepare("SELECT * FROM commission_reunions WHERE serie_id = ? AND id <> ? AND date_reunion > ? AND publie = 0 AND cloture = 0 ORDER BY date_reunion ASC, id ASC LIMIT 1");
        $q->execute([$serie, (int) $r['id'], $r['date_reunion']]);
        $n = $q->fetch();
        return $n ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/** Nom d'une commission (best-effort). */
function reunion_commission_nom(PDO $pdo, int $cid): string
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
 * Notifie tous les membres d'une commission (cloche + e-mail) qu'une réunion
 * vient d'être publiée. Retourne le nombre de membres notifiés. Best-effort.
 */
function reunion_notifier_membres(PDO $pdo, array $reunion): int
{
    $cid = (int) ($reunion['commission_id'] ?? 0);
    if ($cid <= 0) { return 0; }
    $commissionNom = reunion_commission_nom($pdo, $cid);
    $titre = (string) ($reunion['titre'] ?? 'Réunion');
    $date = (string) ($reunion['date_reunion'] ?? '');
    $heure = (string) ($reunion['heure'] ?? '');
    $mode = (string) ($reunion['mode'] ?? 'distance');
    $dateFr = $date !== '' ? date('d/m/Y', strtotime($date)) : '';
    $lien = dahira_base_url() . '/reunion_detail.php?id=' . (int) ($reunion['id'] ?? 0);

    $count = 0;
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT m.id, m.prenom, m.nom, m.email
             FROM membres m
             WHERE m.status = 'approved' AND m.id IN (
                 SELECT membre_id FROM commission_membres WHERE commission_id = :cid
                 UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = :cid2
             )"
        );
        $st->execute([':cid' => $cid, ':cid2' => $cid]);
        $membres = $st->fetchAll();
    } catch (Exception $e) {
        $membres = [];
    }

    $sujet = '👥 Réunion — ' . $commissionNom . ' : ' . $titre;
    foreach ($membres as $m) {
        $mid = (int) $m['id'];
        $destinataire = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
        if ($destinataire === '') { $destinataire = 'Cher membre'; }

        // 1) Cloche + Web Push
        troba_notify_membre(
            $pdo,
            $mid,
            'reunion',
            '👥 Réunion — ' . $commissionNom,
            $titre . ($dateFr !== '' ? ' — ' . $dateFr . ($heure !== '' ? ' à ' . $heure : '') : ''),
            'reunion_detail.php?id=' . (int) ($reunion['id'] ?? 0)
        );

        // 2) E-mail
        $email = trim((string) ($m['email'] ?? ''));
        if ($email !== '') {
            $corpsHtml =
                '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
                . '<h2 style="color:#1b4332;">Assalamu aleykum ' . htmlspecialchars($destinataire) . ' 🙏</h2>'
                . '<p style="color:#333;">Une <strong>réunion</strong> de la commission <strong>' . htmlspecialchars($commissionNom) . '</strong> est programmée :</p>'
                . '<p style="background:#f6f3e8;border:1px solid #d4af37;border-radius:10px;padding:12px 16px;color:#1b4332;">'
                . '📌 <strong>' . htmlspecialchars($titre) . '</strong><br>'
                . ($dateFr !== '' ? '🗓️ ' . htmlspecialchars($dateFr) : '')
                . ($heure !== '' ? ' &nbsp;🕐 ' . htmlspecialchars($heure) : '')
                . '<br>' . ($mode === 'presentiel' ? '🏛️ En présentiel' : '💻 À distance (visioconférence)')
                . '</p>'
                . dahira_email_button($lien, 'Voir le détail de la réunion')
                . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p>'
                . '</div>';
            send_smtp_mail($email, $destinataire, $sujet, $corpsHtml);
        }
        $count++;
    }
    return $count;
}

/**
 * Notification « début de réunion » (cloche + Web Push uniquement, PAS d'e-mail)
 * aux membres et responsables de la commission.
 */
function reunion_notifier_debut(PDO $pdo, array $r): void
{
    $cid = (int) ($r['commission_id'] ?? 0);
    if ($cid <= 0) { return; }
    $nom = reunion_commission_nom($pdo, $cid);
    $titre = (string) ($r['titre'] ?? 'Réunion');
    try {
        $st = $pdo->prepare("SELECT DISTINCT m.id FROM membres m WHERE m.status = 'approved' AND m.id IN (SELECT membre_id FROM commission_membres WHERE commission_id = :c UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = :c2)");
        $st->execute([':c' => $cid, ':c2' => $cid]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $ids = []; }
    foreach ($ids as $mid) {
        troba_notify_membre($pdo, (int) $mid, 'reunion_debut', '⏰ Réunion — ' . $nom, 'La réunion « ' . $titre . ' » commence maintenant.', 'reunion_detail.php?id=' . (int) ($r['id'] ?? 0));
    }
}

/**
 * Déclenché au chargement : notifie (cloche) les réunions publiées dont l'heure
 * de début est atteinte et qui n'ont pas encore été notifiées. Best-effort.
 */
function reunion_check_debut(PDO $pdo): void
{
    try {
        $rows = $pdo->query("SELECT * FROM commission_reunions WHERE publie = 1 AND cloture = 0 AND COALESCE(annule,0) = 0 AND COALESCE(debut_notifie,0) = 0")->fetchAll();
    } catch (Exception $e) { return; }
    foreach ($rows as $r) {
        $startTs = strtotime((string) $r['date_reunion'] . ' ' . dahira_heure_hm((string) $r['heure']));
        if ($startTs === false || time() < $startTs) { continue; }
        // Fenêtre de 6h : au-delà, on marque sans notifier (événement trop ancien)
        if (time() - $startTs <= 6 * 3600) {
            reunion_notifier_debut($pdo, $r);
        }
        try { $pdo->prepare("UPDATE commission_reunions SET debut_notifie = 1 WHERE id = ?")->execute([(int) $r['id']]); } catch (Exception $e) {}
    }
}

/** Message WhatsApp de partage d'une réunion (détails + lien vers la page). */
function reunion_message_partage(array $r, string $commissionNom, string $lien): string
{
    $date = (string) ($r['date_reunion'] ?? '');
    $dateFr = $date !== '' ? date('d/m/Y', strtotime($date)) : '';
    $heure = (string) ($r['heure'] ?? '');
    $heureFin = trim((string) ($r['heure_fin'] ?? ''));
    $mode = (string) ($r['mode'] ?? 'distance');
    $lieu = trim((string) ($r['lieu'] ?? ''));
    $msg = "As-salaamu haleykum,\n\n📌 *" . (string) ($r['titre'] ?? 'Réunion') . "*";
    if ($commissionNom !== '') { $msg .= "\n📋 Commission " . $commissionNom; }
    if ($dateFr !== '') { $msg .= "\n🗓️ " . $dateFr . ($heure !== '' ? ($heureFin !== '' ? " de " . $heure . " à " . $heureFin : " à " . $heure) : ''); }
    $msg .= "\n" . ($mode === 'presentiel' ? ('🏛️ En présentiel' . ($lieu !== '' ? " : " . $lieu : '')) : '💻 À distance (visioconférence)');
    if (trim((string) ($r['description'] ?? '')) !== '') { $msg .= "\n\n📝 " . trim((string) $r['description']); }
    if (trim($lien) !== '') { $msg .= "\n\n👉 Détails : " . trim($lien); }
    return $msg;
}

/** Message WhatsApp d'annulation d'une réunion. */
function reunion_message_annulation(array $r, string $commissionNom): string
{
    $date = (string) ($r['date_reunion'] ?? '');
    $dateFr = $date !== '' ? date('d/m/Y', strtotime($date)) : '';
    $heure = (string) ($r['heure'] ?? '');
    $msg = "As-salaamu haleykum,\n\n⚠️ *ANNULATION*\nLa réunion « " . (string) ($r['titre'] ?? '') . " »";
    if ($commissionNom !== '') { $msg .= " (commission " . $commissionNom . ")"; }
    if ($dateFr !== '') { $msg .= " prévue le " . $dateFr . ($heure !== '' ? " à " . $heure : ''); }
    $msg .= " est *annulée*.\n\nUne nouvelle date vous sera communiquée. Jërëjëf.";
    return $msg;
}

/** Notifie les membres de la commission de l'ANNULATION (cloche + e-mail). */
function reunion_notifier_annulation(PDO $pdo, array $reunion): int
{
    $cid = (int) ($reunion['commission_id'] ?? 0);
    if ($cid <= 0) { return 0; }
    $commissionNom = reunion_commission_nom($pdo, $cid);
    $titre = (string) ($reunion['titre'] ?? 'Réunion');
    $date = (string) ($reunion['date_reunion'] ?? '');
    $heure = (string) ($reunion['heure'] ?? '');
    $dateFr = $date !== '' ? date('d/m/Y', strtotime($date)) : '';
    $count = 0;
    try {
        $st = $pdo->prepare("SELECT DISTINCT m.id, m.prenom, m.nom, m.email FROM membres m WHERE m.status = 'approved' AND m.id IN (SELECT membre_id FROM commission_membres WHERE commission_id = :cid UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = :cid2)");
        $st->execute([':cid' => $cid, ':cid2' => $cid]);
        $membres = $st->fetchAll();
    } catch (Exception $e) { $membres = []; }
    $sujet = '🚫 Réunion annulée — ' . $commissionNom . ' : ' . $titre;
    foreach ($membres as $m) {
        $mid = (int) $m['id'];
        $destinataire = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
        if ($destinataire === '') { $destinataire = 'Cher membre'; }
        troba_notify_membre($pdo, $mid, 'reunion_annule', '🚫 Réunion annulée — ' . $commissionNom, $titre . ($dateFr !== '' ? ' — ' . $dateFr . ($heure !== '' ? ' à ' . $heure : '') : '') . ' est annulée.', 'index.php');
        $email = trim((string) ($m['email'] ?? ''));
        if ($email !== '') {
            $corps = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
                . '<h2 style="color:#b91c1c;">Assalamu aleykum ' . htmlspecialchars($destinataire) . '</h2>'
                . '<p style="color:#333;">La réunion <strong>' . htmlspecialchars($titre) . '</strong> de la commission <strong>' . htmlspecialchars($commissionNom) . '</strong>'
                . ($dateFr !== '' ? ' prévue le <strong>' . htmlspecialchars($dateFr) . '</strong>' . ($heure !== '' ? ' à <strong>' . htmlspecialchars($heure) . '</strong>' : '') : '')
                . ' est <strong style="color:#b91c1c;">annulée</strong>.</p>'
                . '<p style="color:#333;">Une nouvelle date vous sera communiquée. Jërëjëf.</p>'
                . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p></div>';
            send_smtp_mail($email, $destinataire, $sujet, $corps);
        }
        $count++;
    }
    return $count;
}
