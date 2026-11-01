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

/**
 * Options d'un sélecteur d'heure au pas de 30 minutes (« 00h00 » … « 23h30 »).
 * $selected est mis en surbrillance s'il correspond à l'une des options ;
 * s'il n'y correspond pas (ancienne valeur libre), il est ajouté en tête pour
 * ne jamais être perdu.
 */
function guddi_options_heures(string $selected = ''): string
{
    $selected = trim($selected);
    $options = [];
    for ($h = 0; $h < 24; $h++) {
        foreach ([0, 30] as $m) {
            $options[] = sprintf('%02dh%02d', $h, $m);
        }
    }
    $html = '';
    // Valeur existante hors grille (ex. « 20h15 ») : on la conserve en tête.
    if ($selected !== '' && !in_array($selected, $options, true)) {
        $html .= '<option value="' . htmlspecialchars($selected, ENT_QUOTES) . '" selected>'
            . htmlspecialchars($selected) . '</option>';
    }
    foreach ($options as $opt) {
        $sel = ($opt === $selected) ? ' selected' : '';
        $html .= '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
    }
    return $html;
}

/**
 * Rend les détails complémentaires (texte simple saisi par l'administrateur)
 * pour un affichage HTML sûr : le texte est échappé et les retours à la ligne
 * deviennent des <br>. Aucune balise saisie n'est interprétée.
 */
function guddi_details_texte(string $texte): string
{
    $texte = trim($texte);
    if ($texte === '') {
        return '';
    }
    return nl2br(htmlspecialchars($texte, ENT_QUOTES, 'UTF-8'));
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
function guddi_message(string $date, string $heure, string $theme, string $presentateur, string $lien, string $livre = '', string $pdfUrl = '', string $mode = 'distance', string $lieu = '', string $pageLien = '', string $heureFin = '', string $details = ''): string
{
    $msg = "💎 GUDDI ÀJJUMA 💎\n\n"
        . "Àssalaamu hanlaykum,\n\n"
        . "Le Guddi Àjjuma de ce " . guddi_date_longue($date)
        . " aura lieu inshallaah, " . (trim($heureFin) !== '' ? "de " . trim($heure) . " à " . trim($heureFin) : "à partir de " . trim($heure)) . ".";

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

    // Détails complémentaires saisis pour la séance (texte simple).
    $detailsTxt = trim($details);
    if ($detailsTxt !== '') {
        $msg .= "\n\nℹ️ Infos complémentaires :\n" . $detailsTxt;
    }

    if (trim($pageLien) !== '') {
        $msg .= "\n\n👉 Détails et présence :\n" . trim($pageLien);
    }

    $msg .= "\n\nSëriñ Muntaxaa MBÀKKE yàlla na fi yàgg Lool te wér.\n"
        . "Yàlla na Sunu Boroom defar ligéey bi bamu dëppoo ak niko Sëriñ bi bëggee te mu nangul nuko ci bàrkeb xasida yi.\n\n"
        . "Commission Culte,\nTouba Lyon";

    return $msg;
}

/**
 * Corps HTML d'un e-mail annonçant un Guddi Àjjuma.
 */
function guddi_email(string $date, string $heure, string $theme, string $presentateur, string $lien, string $livre = '', string $pdfUrl = '', string $mode = 'distance', string $lieu = '', string $heureFin = '', string $detailsHtml = ''): string
{
    $date_fr = date('d/m/Y', strtotime($date));
    $jour = guddi_jour_fr($date);

    $modeTxt = $mode === 'presentiel'
        ? 'en présentiel' . (trim($lieu) !== '' ? ' au <strong>' . nl2br(htmlspecialchars(trim($lieu))) . '</strong>' : '')
            . ' — la participation à distance reste possible via le lien Zoom ci-dessous'
        : 'par participation à distance';

    $heureTxt = trim($heureFin) !== ''
        ? 'de <strong>' . htmlspecialchars($heure) . '</strong> à <strong>' . htmlspecialchars($heureFin) . '</strong>'
        : 'à partir de <strong>' . htmlspecialchars($heure) . '</strong>';

    $inner = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
        . '<h2 style="color:#1b4332;">💎 GUDDI ÀJJUMA 💎</h2>'
        . '<p style="color:#333;">Àssalaamu hanlaykum,</p>'
        . '<p style="color:#333;">Le <strong>Guddi Àjjuma</strong> de ce '
        . '<strong>' . htmlspecialchars($jour) . ' ' . htmlspecialchars($date_fr) . '</strong>'
        . ' aura lieu inshallaah, ' . $heureTxt . ' '
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
    // Détails complémentaires (texte simple) saisis pour la séance.
    $detailsSafe = guddi_details_texte($detailsHtml);
    if ($detailsSafe !== '') {
        $inner .= '<div style="margin:18px 0;padding:14px 16px;background:#f6f3e8;border:1px solid #e6e6e6;border-radius:12px;color:#333;line-height:1.5;">'
            . $detailsSafe . '</div>';
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

// ---------------------------------------------------------------------------
// Enregistrement audio d'une séance
// ---------------------------------------------------------------------------

/**
 * Les formats acceptés pour l'enregistrement d'une séance. Le MP3 d'abord :
 * c'est ce que rendent Zoom et les dictaphones de téléphone.
 */
function guddi_audio_extensions(): array
{
    return ['mp3', 'm4a', 'ogg', 'opus', 'wav'];
}

/** Une taille écrite à la mode de php.ini (« 64M », « 512K ») en octets. */
function guddi_taille_ini(string $valeur): int
{
    $valeur = trim($valeur);
    if ($valeur === '') {
        return 0;
    }
    $n = (int) $valeur;
    switch (strtolower(substr($valeur, -1))) {
        case 'g': $n *= 1024 * 1024 * 1024; break;
        case 'm': $n *= 1024 * 1024; break;
        case 'k': $n *= 1024; break;
    }
    return max(0, $n);
}

/**
 * Ce que le serveur accepte vraiment : la plus petite des deux limites.
 * Annoncer 64 Mo quand la requête entière plafonne à 8 ne tromperait que nous.
 */
function guddi_audio_taille_max(): int
{
    $limites = array_filter([
        guddi_taille_ini((string) ini_get('upload_max_filesize')),
        guddi_taille_ini((string) ini_get('post_max_size')),
    ]);
    return empty($limites) ? 0 : (int) min($limites);
}

/** La même limite, dite en français : « 64 Mo ». */
function guddi_audio_taille_max_texte(): string
{
    $o = guddi_audio_taille_max();
    if ($o <= 0) {
        return 'la limite du serveur';
    }
    return $o >= 1024 * 1024
        ? round($o / (1024 * 1024)) . ' Mo'
        : round($o / 1024) . ' Ko';
}

/**
 * Range l'enregistrement reçu dans $dossier et renvoie son nom de fichier.
 *
 * Renvoie null quand rien n'a été joint — ce n'est pas une erreur, la clôture
 * d'une séance sans enregistrement reste normale. $erreur n'est renseignée que
 * lorsqu'un fichier a bien été envoyé mais n'a pas pu être gardé.
 */
function guddi_enregistrer_audio(int $id, array $fichier, string $dossier, string &$erreur): ?string
{
    $etat = (int) ($fichier['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($etat === UPLOAD_ERR_NO_FILE || trim((string) ($fichier['name'] ?? '')) === '') {
        return null;
    }
    if ($etat === UPLOAD_ERR_INI_SIZE || $etat === UPLOAD_ERR_FORM_SIZE) {
        $erreur = "L'enregistrement dépasse " . guddi_audio_taille_max_texte()
            . " : il n'a pas été envoyé. Compressez-le ou déposez-le ailleurs.";
        return null;
    }
    if ($etat !== UPLOAD_ERR_OK) {
        $erreur = "L'envoi de l'enregistrement a échoué. Réessayez.";
        return null;
    }
    // Le format d'abord : c'est ce que la personne peut corriger elle-même.
    $ext = strtolower(pathinfo((string) $fichier['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, guddi_audio_extensions(), true)) {
        $erreur = 'Format audio non reconnu (' . strtoupper(implode(', ', guddi_audio_extensions())) . ' acceptés).';
        return null;
    }
    $tmp = (string) ($fichier['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $erreur = "Fichier inattendu : l'enregistrement n'a pas été accepté.";
        return null;
    }
    if (!is_dir($dossier) && !@mkdir($dossier, 0755, true)) {
        $erreur = "Le dossier des fichiers n'a pas pu être créé.";
        return null;
    }
    $nom = 'guddi_audio_' . $id . '_' . date('Ymd_His') . '.' . $ext;
    if (!move_uploaded_file($tmp, $dossier . '/' . $nom)) {
        $erreur = "L'enregistrement n'a pas pu être rangé sur le serveur.";
        return null;
    }
    return $nom;
}