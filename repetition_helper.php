<?php
/**
 * Touba Lyon 2026 - Répétitions de Kurel
 *
 * Miroir de reunion_helper.php pour les Kurels. Réutilise les fonctions
 * génériques de reunion_helper (génération des dates, libellé de périodicité)
 * et ajoute la notification des membres du Kurel + les règles de série.
 */
require_once __DIR__ . '/dahira_emails.php';       // send_smtp_mail(), dahira_base_url(), dahira_email_button()
require_once __DIR__ . '/notification_helper.php'; // troba_notify_membre()
require_once __DIR__ . '/reunion_helper.php';       // reunion_generer_dates(), reunion_periodicite_label()

/** Y a-t-il une période antérieure NON clôturée dans la même série ? */
function repetition_precedente_non_cloturee(PDO $pdo, array $r): bool
{
    $serie = (int) ($r['serie_id'] ?: $r['id']);
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM kourel_repetitions WHERE serie_id = ? AND id <> ? AND date_reunion < ? AND cloture = 0");
        $q->execute([$serie, (int) $r['id'], $r['date_reunion']]);
        return ((int) $q->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/** Prochaine période de la série (date postérieure), non publiée et non clôturée. */
function repetition_prochaine_a_publier(PDO $pdo, array $r): ?array
{
    $serie = (int) ($r['serie_id'] ?: $r['id']);
    try {
        $q = $pdo->prepare("SELECT * FROM kourel_repetitions WHERE serie_id = ? AND id <> ? AND date_reunion > ? AND publie = 0 AND cloture = 0 ORDER BY date_reunion ASC, id ASC LIMIT 1");
        $q->execute([$serie, (int) $r['id'], $r['date_reunion']]);
        $n = $q->fetch();
        return $n ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/** Nom d'un Kurel (best-effort). */
function repetition_kourel_nom(PDO $pdo, int $kid): string
{
    try {
        $s = $pdo->prepare("SELECT nom FROM kourels WHERE id = ?");
        $s->execute([$kid]);
        $n = $s->fetchColumn();
        return $n !== false ? (string) $n : '';
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Notifie tous les membres d'un Kurel (cloche + e-mail) qu'une répétition
 * vient d'être publiée. Retourne le nombre de membres notifiés. Best-effort.
 */
function repetition_notifier_membres(PDO $pdo, array $rep): int
{
    $kid = (int) ($rep['kourel_id'] ?? 0);
    if ($kid <= 0) { return 0; }
    $kourelNom = repetition_kourel_nom($pdo, $kid);
    $titre = (string) ($rep['titre'] ?? 'Répétition');
    $date = (string) ($rep['date_reunion'] ?? '');
    $heure = (string) ($rep['heure'] ?? '');
    $mode = (string) ($rep['mode'] ?? 'distance');
    $dateFr = $date !== '' ? date('d/m/Y', strtotime($date)) : '';
    $lien = dahira_base_url() . '/repetition_detail.php?id=' . (int) ($rep['id'] ?? 0);

    $count = 0;
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT m.id, m.prenom, m.nom, m.email
             FROM membres m
             WHERE m.status = 'approved' AND m.id IN (
                 SELECT membre_id FROM kourel_membres WHERE kourel_id = :kid
                 UNION SELECT membre_id FROM kourel_gestionnaires WHERE kourel_id = :kid2
             )"
        );
        $st->execute([':kid' => $kid, ':kid2' => $kid]);
        $membres = $st->fetchAll();
    } catch (Exception $e) {
        $membres = [];
    }

    $sujet = '🎵 Répétition — ' . $kourelNom . ' : ' . $titre;
    foreach ($membres as $m) {
        $mid = (int) $m['id'];
        $destinataire = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
        if ($destinataire === '') { $destinataire = 'Cher membre'; }

        troba_notify_membre(
            $pdo,
            $mid,
            'repetition',
            '🎵 Répétition — ' . $kourelNom,
            $titre . ($dateFr !== '' ? ' — ' . $dateFr . ($heure !== '' ? ' à ' . $heure : '') : ''),
            'repetition_detail.php?id=' . (int) ($rep['id'] ?? 0)
        );

        $email = trim((string) ($m['email'] ?? ''));
        if ($email !== '') {
            $corpsHtml =
                '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
                . '<h2 style="color:#1b4332;">Assalamu aleykum ' . htmlspecialchars($destinataire) . ' 🙏</h2>'
                . '<p style="color:#333;">Une <strong>répétition</strong> du Kurel <strong>' . htmlspecialchars($kourelNom) . '</strong> est programmée :</p>'
                . '<p style="background:#f6f3e8;border:1px solid #d4af37;border-radius:8px;padding:12px 16px;color:#1b4332;">'
                . '🎼 <strong>' . htmlspecialchars($titre) . '</strong><br>'
                . ($dateFr !== '' ? '🗓️ ' . htmlspecialchars($dateFr) : '')
                . ($heure !== '' ? ' &nbsp;🕐 ' . htmlspecialchars($heure) : '')
                . '<br>' . ($mode === 'presentiel' ? '🏛️ En présentiel' : '💻 À distance (visioconférence)')
                . '</p>'
                . dahira_email_button($lien, 'Voir le détail de la répétition')
                . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p>'
                . '</div>';
            send_smtp_mail($email, $destinataire, $sujet, $corpsHtml);
        }
        $count++;
    }
    return $count;
}

/**
 * Notification « début de répétition » (cloche + Web Push uniquement, PAS d'e-mail)
 * aux membres et responsables du Kurel.
 */
function repetition_notifier_debut(PDO $pdo, array $r): void
{
    $kid = (int) ($r['kourel_id'] ?? 0);
    if ($kid <= 0) { return; }
    $nom = repetition_kourel_nom($pdo, $kid);
    $titre = (string) ($r['titre'] ?? 'Répétition');
    try {
        $st = $pdo->prepare("SELECT DISTINCT m.id FROM membres m WHERE m.status = 'approved' AND m.id IN (SELECT membre_id FROM kourel_membres WHERE kourel_id = :k UNION SELECT membre_id FROM kourel_gestionnaires WHERE kourel_id = :k2)");
        $st->execute([':k' => $kid, ':k2' => $kid]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $ids = []; }
    foreach ($ids as $mid) {
        troba_notify_membre($pdo, (int) $mid, 'repetition_debut', '⏰ Répétition — ' . $nom, 'La répétition « ' . $titre . ' » commence maintenant.', 'repetition_detail.php?id=' . (int) ($r['id'] ?? 0));
    }
}

/** Déclenché au chargement : notifie (cloche) les répétitions dont l'heure de début est atteinte. */
function repetition_check_debut(PDO $pdo): void
{
    try {
        $rows = $pdo->query("SELECT * FROM kourel_repetitions WHERE publie = 1 AND cloture = 0 AND COALESCE(annule,0) = 0 AND COALESCE(debut_notifie,0) = 0")->fetchAll();
    } catch (Exception $e) { return; }
    foreach ($rows as $r) {
        $startTs = strtotime((string) $r['date_reunion'] . ' ' . dahira_heure_hm((string) $r['heure']));
        if ($startTs === false || time() < $startTs) { continue; }
        if (time() - $startTs <= 6 * 3600) {
            repetition_notifier_debut($pdo, $r);
        }
        try { $pdo->prepare("UPDATE kourel_repetitions SET debut_notifie = 1 WHERE id = ?")->execute([(int) $r['id']]); } catch (Exception $e) {}
    }
}

/** Message WhatsApp de partage d'une répétition (détails + lien vers la page). */
function repetition_message_partage(array $r, string $kourelNom, string $lien): string
{
    $date = (string) ($r['date_reunion'] ?? '');
    $dateFr = $date !== '' ? date('d/m/Y', strtotime($date)) : '';
    $heure = (string) ($r['heure'] ?? '');
    $mode = (string) ($r['mode'] ?? 'distance');
    $lieu = trim((string) ($r['lieu'] ?? ''));
    $msg = "As-salaamu haleykum,\n\n🎼 *" . (string) ($r['titre'] ?? 'Répétition') . "*";
    if ($kourelNom !== '') { $msg .= "\n🎵 Kurel " . $kourelNom; }
    if ($dateFr !== '') { $msg .= "\n🗓️ " . $dateFr . ($heure !== '' ? " à " . $heure : ''); }
    $msg .= "\n" . ($mode === 'presentiel' ? ('🏛️ En présentiel' . ($lieu !== '' ? " : " . $lieu : '')) : '💻 À distance (visioconférence)');
    if (trim((string) ($r['khassida'] ?? '')) !== '') { $msg .= "\n📜 " . trim((string) $r['khassida']); }
    if (trim($lien) !== '') { $msg .= "\n\n👉 Détails : " . trim($lien); }
    return $msg;
}

/** Message WhatsApp d'annulation d'une répétition. */
function repetition_message_annulation(array $r, string $kourelNom): string
{
    $date = (string) ($r['date_reunion'] ?? '');
    $dateFr = $date !== '' ? date('d/m/Y', strtotime($date)) : '';
    $heure = (string) ($r['heure'] ?? '');
    $msg = "As-salaamu haleykum,\n\n⚠️ *ANNULATION*\nLa répétition « " . (string) ($r['titre'] ?? '') . " »";
    if ($kourelNom !== '') { $msg .= " (Kurel " . $kourelNom . ")"; }
    if ($dateFr !== '') { $msg .= " prévue le " . $dateFr . ($heure !== '' ? " à " . $heure : ''); }
    $msg .= " est *annulée*.\n\nUne nouvelle date vous sera communiquée. Jërëjëf.";
    return $msg;
}

/** Notifie les membres du Kurel de l'ANNULATION (cloche + e-mail). */
function repetition_notifier_annulation(PDO $pdo, array $rep): int
{
    $kid = (int) ($rep['kourel_id'] ?? 0);
    if ($kid <= 0) { return 0; }
    $kourelNom = repetition_kourel_nom($pdo, $kid);
    $titre = (string) ($rep['titre'] ?? 'Répétition');
    $date = (string) ($rep['date_reunion'] ?? '');
    $heure = (string) ($rep['heure'] ?? '');
    $dateFr = $date !== '' ? date('d/m/Y', strtotime($date)) : '';
    $count = 0;
    try {
        $st = $pdo->prepare("SELECT DISTINCT m.id, m.prenom, m.nom, m.email FROM membres m WHERE m.status = 'approved' AND m.id IN (SELECT membre_id FROM kourel_membres WHERE kourel_id = :kid UNION SELECT membre_id FROM kourel_gestionnaires WHERE kourel_id = :kid2)");
        $st->execute([':kid' => $kid, ':kid2' => $kid]);
        $membres = $st->fetchAll();
    } catch (Exception $e) { $membres = []; }
    $sujet = '🚫 Répétition annulée — ' . $kourelNom . ' : ' . $titre;
    foreach ($membres as $m) {
        $mid = (int) $m['id'];
        $destinataire = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
        if ($destinataire === '') { $destinataire = 'Cher membre'; }
        troba_notify_membre($pdo, $mid, 'repetition_annule', '🚫 Répétition annulée — ' . $kourelNom, $titre . ($dateFr !== '' ? ' — ' . $dateFr . ($heure !== '' ? ' à ' . $heure : '') : '') . ' est annulée.', 'index.php');
        $email = trim((string) ($m['email'] ?? ''));
        if ($email !== '') {
            $corps = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
                . '<h2 style="color:#b91c1c;">Assalamu aleykum ' . htmlspecialchars($destinataire) . '</h2>'
                . '<p style="color:#333;">La répétition <strong>' . htmlspecialchars($titre) . '</strong> du Kurel <strong>' . htmlspecialchars($kourelNom) . '</strong>'
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
