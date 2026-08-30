<?php
/**
 * Touba Lyon 2026 - Planning « Guddi Àjjuma » (commission Culte)
 *
 * Même modèle que le planning Dahira, pour les JEUDIS :
 * séance de Zikrullah à distance (Zoom), thème, présentateur, lien.
 *
 * Envoi WhatsApp en mode semi-automatique (lien wa.me pré-rempli).
 */

// ---------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------

/** Nom du jour en français pour une date donnée. */
function guddi_jour_fr(string $date): string
{
    $days = ['Sunday' => 'dimanche', 'Monday' => 'lundi', 'Tuesday' => 'mardi', 'Wednesday' => 'mercredi',
             'Thursday' => 'jeudi', 'Friday' => 'vendredi', 'Saturday' => 'samedi'];
    return $days[date('l', strtotime($date))] ?? '';
}

/** Date lisible : « jeudi 28/08/2026 ». */
function guddi_date_longue(string $date): string
{
    return guddi_jour_fr($date) . ' ' . date('d/m/Y', strtotime($date));
}

/** Tous les jeudis entre deux dates. */
function guddi_jeudis(string $from, string $to): array
{
    $out = [];
    $ts = strtotime($from);
    $end = strtotime($to);
    if ($ts === false || $end === false || $end < $ts) {
        return $out;
    }
    if ((int)date('w', $ts) !== 4) {
        $ts = strtotime('next thursday', $ts);
    }
    while ($ts !== false && $ts <= $end) {
        $out[] = date('Y-m-d', $ts);
        $ts = strtotime('+7 days', $ts);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// WhatsApp (réutilise les helpers Dahira : wa_link, wa_button)
// ---------------------------------------------------------------------------

/**
 * Message type « Guddi Àjjuma », dans le style demandé :
 *
 * « 💎 GUDDI ÀJJUMA 💎
 *    Àssalaamu hanlaykum,
 *    Le Guddi Àjjuma d'aujourd'hui aura lieu inshallaah, à partir de 20h00 par participation à distance.
 *    - Thème : <thème>
 *    - Présentateur : <présentateur>
 *    Lien: <zoom>
 *    Sëriñ Muntaxaa MBÀKKE yàlla na fi yàgg Lool te wér.
 *    Yàlla na Sunu Boroom defar ligéey bi bamu dëppoo ak niko Sëriñ bi bëggee te mu nangul nuko ci bàrkeb xasida yi.
 *    Commission Culte,
 *    Touba Lyon »
 */
function guddi_message(string $date, string $heure, string $theme, string $presentateur, string $lien, string $livre = '', string $pdfUrl = '', string $mode = 'distance', string $lieu = ''): string
{
    $msg = "💎 GUDDI ÀJJUMA 💎\n\n"
        . "Àssalaamu hanlaykum,\n\n"
        . "Le Guddi Àjjuma de ce " . guddi_date_longue($date)
        . " aura lieu inshallaah, à partir de " . trim($heure) . ".";

    if ($mode === 'presentiel') {
        $msg .= "\nEn présentiel";
        if (trim($lieu) !== '') {
            $msg .= " à " . trim($lieu);
        }
        $msg .= ".\nLa participation à distance reste possible via le lien Zoom ci-dessous.\n";
    } else {
        $msg .= "\nPar participation à distance.\n";
    }

    if (trim($theme) !== '') {
        $msg .= "\n- Thème : " . trim($theme);
    }
    if (trim($presentateur) !== '') {
        $msg .= "\n- Présentateur : " . trim($presentateur);
    }
    if (trim($lien) !== '') {
        $msg .= "\n\n🔗 Lien de participation Zoom :\n" . trim($lien);
    }
    if (trim($livre) !== '' || trim($pdfUrl) !== '') {
        $msg .= "\n\n📖 Livre à étudier";
        if (trim($livre) !== '') {
            $msg .= " : " . trim($livre);
        }
        if (trim($pdfUrl) !== '') {
            $msg .= "\n🔗 Lien du livre :\n" . trim($pdfUrl);
        }
    }

    $msg .= "\n\nSëriñ Muntaxaa MBÀKKE yàlla na fi yàgg Lool te wér.\n"
        . "Yàlla na Sunu Boroom defar ligéey bi bamu dëppoo ak niko Sëriñ bi bëggee te mu nangul nuko ci bàrkeb xasida yi.\n\n"
        . "Commission Culte,\nTouba Lyon";

    return $msg;
}

/**
 * Corps HTML d'un e-mail annonçant un Guddi Àjjuma.
 */
function guddi_email(string $date, string $heure, string $theme, string $presentateur, string $lien, string $livre = '', string $pdfUrl = '', string $mode = 'distance', string $lieu = ''): string
{
    $date_fr = date('d/m/Y', strtotime($date));
    $jour = guddi_jour_fr($date);

    $modeTxt = $mode === 'presentiel'
        ? 'en présentiel' . (trim($lieu) !== '' ? ' au <strong>' . nl2br(htmlspecialchars(trim($lieu))) . '</strong>' : '')
            . ' — la participation à distance reste possible via le lien Zoom ci-dessous'
        : 'par participation à distance';

    $inner = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
        . '<h2 style="color:#1b4332;">💎 GUDDI ÀJJUMA 💎</h2>'
        . '<p style="color:#333;">Àssalaamu hanlaykum,</p>'
        . '<p style="color:#333;">Le <strong>Guddi Àjjuma</strong> de ce '
        . '<strong>' . htmlspecialchars($jour) . ' ' . htmlspecialchars($date_fr) . '</strong>'
        . ' aura lieu inshallaah, à partir de <strong>' . htmlspecialchars($heure) . '</strong> '
        . $modeTxt . '.</p>';

    $facts = [];
    if (trim($theme) !== '') { $facts[] = ['Thème', htmlspecialchars(trim($theme))]; }
    if (trim($presentateur) !== '') { $facts[] = ['Présentateur', htmlspecialchars(trim($presentateur))]; }
    if (trim($livre) !== '') { $facts[] = ['📖 Livre à étudier', htmlspecialchars(trim($livre))]; }
    if (!empty($facts)) {
        $inner .= '<table style="margin:18px 0;border-collapse:separate;border-spacing:0;width:100%;border:1px solid #e6e6e6;border-radius:12px;overflow:hidden;">';
        foreach ($facts as $f) {
            $inner .= '<tr><td style="background:#f6f3e8;padding:10px 14px;font-weight:700;color:#1b4332;border-bottom:1px solid #e6e6e6;">' . $f[0] . '</td>'
                . '<td style="padding:10px 14px;color:#333;border-bottom:1px solid #e6e6e6;">' . $f[1] . '</td></tr>';
        }
        $inner .= '</table>';
    }
    if (trim($lien) !== '') {
        $inner .= '<p style="color:#333;"><strong>🔗 Lien de participation Zoom :</strong><br><a href="' . htmlspecialchars(trim($lien)) . '" style="color:#1b4332;font-weight:700;">' . htmlspecialchars(trim($lien)) . '</a></p>';
    }
    if (trim($pdfUrl) !== '') {
        $inner .= '<p style="color:#333;"><strong>🔗 Lien du livre :</strong><br><a href="' . htmlspecialchars(trim($pdfUrl)) . '" style="color:#1b4332;font-weight:700;">' . htmlspecialchars(trim($pdfUrl)) . '</a></p>';
    }
    $inner .= '<p style="color:#333;margin-top:18px;">Sëriñ Muntaxaa MBÀKKE yàlla na fi yàgg Lool te wér.<br>'
        . 'Yàlla na Sunu Boroom defar ligéey bi bamu dëppoo ak niko Sëriñ bi bëggee te mu nangul nuko ci bàrkeb xasida yi. 🤲</p>'
        . '<p style="color:#888;font-size:12px;">— Commission Culte, Touba Lyon</p>'
        . '</div>';

    return $inner;
}

/**
 * Message d'ANNULATION d'un Guddi Àjjuma, dans le style demandé :
 *
 * « ‼️‼️ANNULATION GUDDI ÀJJUMA
 *    Àssalaamu hanlaykum,
 *    Le Guddi Àjjuma d'aujourd'hui est annulé.
 *    Ñungi leen di tuubal bu baax.
 *    Sëriñ Muntaxaa MBÀKKE yàlla na fi yàgg Lool te wér.
 *    Commission Culte,
 *    Touba Lyon »
 */
function guddi_message_annulation(string $date): string
{
    return "‼️‼️ ANNULATION GUDDI ÀJJUMA\n\n"
        . "Àssalaamu hanlaykum,\n\n"
        . "Le Guddi Àjjuma de ce " . guddi_date_longue($date) . " est annulé.\n\n"
        . "Ñungi leen di tuubal bu baax.\n\n"
        . "Sëriñ Muntaxaa MBÀKKE yàlla na fi yàgg Lool te wér.\n\n"
        . "Commission Culte,\nTouba Lyon";
}

/**
 * Corps HTML d'un e-mail annonçant l'annulation d'un Guddi Àjjuma.
 */
function guddi_email_annulation(string $date): string
{
    $date_fr = date('d/m/Y', strtotime($date));
    $jour = guddi_jour_fr($date);

    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
        . '<h2 style="color:#b91c1c;">‼️‼️ ANNULATION GUDDI ÀJJUMA</h2>'
        . '<p style="color:#333;">Àssalaamu hanlaykum,</p>'
        . '<p style="color:#333;">Le <strong>Guddi Àjjuma</strong> de ce '
        . '<strong>' . htmlspecialchars($jour) . ' ' . htmlspecialchars($date_fr) . '</strong> est annulé.</p>'
        . '<p style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 16px;color:#991b1b;">Ñungi leen di tuubal bu baax.</p>'
        . '<p style="color:#333;">Sëriñ Muntaxaa MBÀKKE yàlla na fi yàgg Lool te wér. 🤲</p>'
        . '<p style="color:#888;font-size:12px;">— Commission Culte, Touba Lyon</p>'
        . '</div>';
}

// ---------------------------------------------------------------------------
// Membres destinataires (réutilise dahira_destinataires)
// ---------------------------------------------------------------------------
