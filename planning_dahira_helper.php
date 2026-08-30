<?php
/**
 * Touba Lyon 2026 - Planning hebdomadaire du Dahira (commission Secrétariat Général)
 *
 * Repris du projet Daara : les dimanches de Dahira (un dimanche sur deux),
 * le programme de la séance, et les messages WhatsApp / e-mails prêts à
 * diffuser aux membres.
 *
 * Envoi WhatsApp en mode semi-automatique (lien wa.me pré-rempli, sans compte
 * WhatsApp Business) ; envoi e-mail via le client SMTP autonome du site.
 */

// ---------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------

/** Nom du jour en français pour une date donnée. */
function dahira_jour_fr(string $date): string
{
    $days = ['Sunday' => 'dimanche', 'Monday' => 'lundi', 'Tuesday' => 'mardi', 'Wednesday' => 'mercredi',
             'Thursday' => 'jeudi', 'Friday' => 'vendredi', 'Saturday' => 'samedi'];
    return $days[date('l', strtotime($date))] ?? '';
}

/** Date lisible : « dimanche 16/08/2026 ». */
function dahira_date_longue(string $date): string
{
    return dahira_jour_fr($date) . ' ' . date('d/m/Y', strtotime($date));
}

/** Prochains dimanches (un dimanche sur deux) entre deux dates. */
function dahira_dimanches(string $from, string $to, bool $alterner = true): array
{
    $out = [];
    $ts = strtotime($from);
    $end = strtotime($to);
    if ($ts === false || $end === false || $end < $ts) {
        return $out;
    }
    if ((int)date('w', $ts) !== 0) {
        $ts = strtotime('next sunday', $ts);
    }
    $i = 0;
    while ($ts !== false && $ts <= $end) {
        if (!$alterner || $i % 2 === 0) {
            $out[] = date('Y-m-d', $ts);
        }
        $ts = strtotime('+7 days', $ts);
        $i++;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// WhatsApp (mode semi-automatique)
// ---------------------------------------------------------------------------

/** Convertit un numéro en format international sans « + » (attendu par wa.me). */
function dahira_phone_to_e164(?string $phone, string $default_country = '33'): ?string
{
    if ($phone === null) {
        return null;
    }
    $has_plus = strpos(trim($phone), '+') === 0;
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '' || $digits === null) {
        return null;
    }
    if ($has_plus) {
        return strlen($digits) >= 8 ? $digits : null;
    }
    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
        return strlen($digits) >= 8 ? $digits : null;
    }
    if (strpos($digits, '0') === 0) {
        $digits = $default_country . substr($digits, 1);
        return strlen($digits) >= 10 ? $digits : null;
    }
    if (strpos($digits, $default_country) === 0 && strlen($digits) >= 10) {
        return $digits;
    }
    return strlen($digits) >= 8 ? $digits : null;
}

/** Lien WhatsApp pré-rempli (sans numéro : ouvre le choix du destinataire/groupe). */
function dahira_wa_link(?string $phone, string $message, string $default_country = '33'): string
{
    $number = dahira_phone_to_e164($phone, $default_country);
    $text = rawurlencode($message);
    return $number !== null
        ? 'https://wa.me/' . $number . '?text=' . $text
        : 'https://wa.me/?text=' . $text;
}

/** Bouton WhatsApp vert, ouvrant un nouvel onglet. */
function dahira_wa_button(string $link, string $label = 'Partager sur WhatsApp', bool $small = false): string
{
    $padding = $small ? '0.3rem 0.7rem' : '0.6rem 1.1rem';
    $font = $small ? '0.76rem' : '0.9rem';
    return '<a href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener"'
        . ' style="display:inline-flex;align-items:center;gap:0.4rem;background:#25D366;color:#06281a;'
        . 'font-weight:700;font-size:' . $font . ';padding:' . $padding . ';border-radius:50px;'
        . 'text-decoration:none;white-space:nowrap;">'
        . '<span>🟢</span><span>' . htmlspecialchars($label) . '</span></a>';
}

// ---------------------------------------------------------------------------
// Messages du Dahira
// ---------------------------------------------------------------------------

/**
 * Message d'annonce d'un Dahira, dans le style demandé :
 *
 * « As-salaamu haleykum,
 *    Un Dahira aura lieu ce Dimanche 16 Août 2026 au <adresse> de <debut> à <fin>.
 *    Programme joint ci-dessus.
 *    Sëriñ Muntaxaa Mbakke Yalla nafi Yagg lòolu te wer. »
 */
function dahira_message_annonce(string $date, string $lieu, string $debut, string $fin, string $programme = ''): string
{
    $msg = "As-salaamu haleykum,\n\n"
        . "Un Dahira aura lieu ce " . dahira_date_longue($date) . " :\n";

    if (trim($lieu) !== '') {
        $msg .= "\n📍 Lieu :\n" . trim($lieu) . "\n";
    }
    $msg .= "\n🕐 De " . trim($debut) . " à " . trim($fin) . ".\n\n"
        . "Programme joint ci-dessus.\n\n"
        . "Sëriñ Muntaxaa Mbakke Yalla nafi Yagg lòolu te wer.";

    if (trim($programme) !== '') {
        $msg .= "\n\n🗓️ *Programme :*\n" . trim($programme);
    }
    return $msg;
}

/**
 * Corps HTML d'un e-mail annonçant un Dahira.
 */
function dahira_email_annonce(string $date, string $lieu, string $debut, string $fin, string $programme = ''): string
{
    $date_fr = date('d/m/Y', strtotime($date));
    $jour = dahira_jour_fr($date);

    $inner = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
        . '<h2 style="color:#1b4332;">As-salaamu haleykum 🙏</h2>'
        . '<p style="color:#333;">Un <strong>Dahira</strong> aura lieu ce Dimanche '
        . '<strong>' . htmlspecialchars($jour) . ' ' . htmlspecialchars($date_fr) . '</strong> :</p>'
        . '<p style="background:#f6f3e8;border:1px solid #d4af37;border-radius:10px;padding:12px 16px;color:#1b4332;">'
        . '📍 ' . nl2br(htmlspecialchars(trim($lieu))) . '<br>🕐 De <strong>' . htmlspecialchars($debut) . '</strong> à <strong>' . htmlspecialchars($fin) . '</strong></p>';

    if (trim($programme) !== '') {
        $inner .= '<h3 style="color:#1b4332;margin:20px 0 8px;">🗓️ Programme</h3>'
            . '<div style="background:#ffffff;border:1px solid #e6e6e6;border-radius:12px;padding:12px 16px;white-space:pre-wrap;color:#333;line-height:1.5;">'
            . htmlspecialchars(trim($programme)) . '</div>';
    }

    $inner .= '<p style="color:#333;margin-top:18px;">Sëriñ Muntaxaa Mbakke Yalla nafi Yagg lòolu te wer. 🤲</p>'
        . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p>'
        . '</div>';

    return $inner;
}

// ---------------------------------------------------------------------------
// Membres destinataires
// ---------------------------------------------------------------------------

/**
 * Membres à prévenir : ceux qui sont rattachés à la commission donnée
 * (via commission_membres), ou, à défaut, tous les membres validés.
 */
function dahira_destinataires(PDO $pdo, int $commission_id): array
{
    try {
        $st = $pdo->prepare("
            SELECT DISTINCT m.id, m.nom, m.prenom, m.email, m.telephone
            FROM membres m
            JOIN commission_membres cm ON cm.membre_id = m.id
            WHERE cm.commission_id = :cid AND m.status = 'approved'
            ORDER BY m.nom ASC, m.prenom ASC
        ");
        $st->execute([':cid' => $commission_id]);
        $rows = $st->fetchAll();
        if (!empty($rows)) {
            return $rows;
        }
    } catch (Exception $e) {
        error_log('Touba Lyon planning - destinataires commission : ' . $e->getMessage());
    }
    // Repli : tous les membres validés
    try {
        return $pdo->query("SELECT id, nom, prenom, email, telephone FROM membres WHERE status = 'approved' ORDER BY nom ASC, prenom ASC")->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Réglages (app_settings) — partagés par admin_planning et planning_dahira_image
// ---------------------------------------------------------------------------

/** Lit un réglage (clé/valeur) ; renvoie $defaut si absent. */
function dahira_param(PDO $pdo, string $cle, string $defaut = ''): string
{
    try {
        $st = $pdo->prepare("SELECT valeur FROM app_settings WHERE cle = ?");
        $st->execute([$cle]);
        $v = (string) $st->fetchColumn();
        return $v !== '' ? $v : $defaut;
    } catch (Exception $e) {
        return $defaut;
    }
}

/** Écrit un réglage (clé/valeur). */
function dahira_set_param(PDO $pdo, string $cle, string $valeur): void
{
    try {
        $pdo->prepare("INSERT INTO app_settings (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)")->execute([$cle, $valeur]);
    } catch (Exception $e) {
        error_log('Touba Lyon planning - réglage : ' . $e->getMessage());
    }
}

