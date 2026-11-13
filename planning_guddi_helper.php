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
 * Pied des messages d'annonce (WhatsApp et e-mail), modifiable dans les paramètres du Guddi Àjjuma (« guddi_message_pied »).
 * Deux lignes séparées par un retour à la ligne ; ce texte est la valeur par défaut.
 */
function guddi_pied_defaut(): string
{
    return "Sëriñ Muntaxaa MBÀKKE yàlla na fi yàgg Lool te wér.\n"
        . "Yàlla na Sunu Boroom defar ligéey bi bamu dëppoo ak niko Sëriñ bi bëggee te mu nangul nuko ci bàrkeb xasida yi.";
}

/** Pied à utiliser : celui des paramètres s'il est renseigné, sinon la valeur par défaut. */
function guddi_pied_effectif(string $pied): string
{
    $p = trim(str_replace("\r\n", "\n", $pied));
    return $p !== '' ? $p : guddi_pied_defaut();
}

/**
 * Adresse de réunion utilisable : sans « https:// » (collée telle quelle depuis Zoom, par exemple « us06web.zoom.us/j/123… »),
 * un lien serait traité comme une page du site et n'ouvrirait pas la réunion. On complète donc l'adresse si besoin.
 */
function guddi_lien_normalise(string $lien): string
{
    $l = trim($lien);
    if ($l === '') { return ''; }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $l)) { $l = 'https://' . ltrim($l, '/'); }
    return $l;
}

/**
 * Lien du livre à étudier d'une séance : avec une page de début définie, il ouvre la liseuse du site sur cette page
 * (pdf_viewer.php?…&page=N) ; sans page, c'est le fichier PDF lui-même. $base : adresse du site (absolue pour un message, « . » dans les pages).
 */
function guddi_pdf_lien(array $seance, string $base = 'https://toubalyon.com/Dahira'): string
{
    $pdf = trim((string) ($seance['pdf_path'] ?? ''));
    if ($pdf === '') { return ''; }
    $base = rtrim($base, '/');
    $page = (int) ($seance['pdf_page_debut'] ?? 0);
    if ($page > 0) {
        $titre = trim((string) ($seance['livre'] ?? ''));
        return $base . '/pdf_viewer.php?file=' . rawurlencode('uploads/' . $pdf) . ($titre !== '' ? '&titre=' . rawurlencode($titre) : '') . '&page=' . $page;
    }
    return $base . '/uploads/' . rawurlencode($pdf);
}

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
function guddi_message(string $date, string $heure, string $theme, string $presentateur, string $lien, string $livre = '', string $pdfUrl = '', string $mode = 'distance', string $lieu = '', string $pageLien = '', string $heureFin = '', string $details = '', string $pied = ''): string
{
    $lien = guddi_lien_normalise($lien);
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
    // Lien des détails et de la présence : avant le lien du livre
    if (trim($pageLien) !== '') {
        $msg .= "\n\n👉 Détails et présence :\n" . trim($pageLien);
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

    // Historique des séances (enregistrements à écouter)
    $baseSite = function_exists('dahira_base_url') ? rtrim((string) dahira_base_url(), '/') : 'https://toubalyon.com/Dahira';
    $msg .= "\n\n📚 Historique des séances (enregistrements à écouter) :\n" . $baseSite . '/guddi_historique.php';

    $msg .= "\n\n" . guddi_pied_effectif($pied) . "\n\n"
        . "Commission Culte,\nTouba Lyon";

    return $msg;
}

/**
 * Corps HTML d'un e-mail annonçant un Guddi Àjjuma.
 */
function guddi_email(string $date, string $heure, string $theme, string $presentateur, string $lien, string $livre = '', string $pdfUrl = '', string $mode = 'distance', string $lieu = '', string $heureFin = '', string $detailsHtml = '', string $pied = ''): string
{
    $lien = guddi_lien_normalise($lien);
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
        $inner .= '<table style="margin:18px 0;border-collapse:separate;border-spacing:0;width:100%;border:1px solid #e6e6e6;border-radius:10px;overflow:hidden;">';
        foreach ($facts as $f) {
            $inner .= '<tr><td style="background:#f6f3e8;padding:10px 14px;font-weight:700;color:#1b4332;border-bottom:1px solid #e6e6e6;">' . $f[0] . '</td>'
                . '<td style="padding:10px 14px;color:#333;border-bottom:1px solid #e6e6e6;">' . $f[1] . '</td></tr>';
        }
        $inner .= '</table>';
    }
    if (trim($lien) !== '') {
        $inner .= '<p style="color:#333;"><strong>🔗 Participer :</strong> <a href="' . htmlspecialchars(trim($lien)) . '" style="color:#1b4332;font-weight:700;">Rejoindre la séance en Zoom</a></p>';
    }
    if (trim($pdfUrl) !== '') {
        $inner .= '<p style="color:#333;"><strong>🔗 Livre à étudier :</strong> <a href="' . htmlspecialchars(trim($pdfUrl)) . '" style="color:#1b4332;font-weight:700;">' . (trim($livre) !== '' ? 'Ouvrir « ' . htmlspecialchars(trim($livre)) . ' »' : 'Ouvrir le livre') . '</a></p>';
    }
    // Détails complémentaires (texte simple) saisis pour la séance.
    $detailsSafe = guddi_details_texte($detailsHtml);
    if ($detailsSafe !== '') {
        $inner .= '<div style="margin:18px 0;padding:14px 16px;background:#f6f3e8;border:1px solid #e6e6e6;border-radius:10px;color:#333;line-height:1.5;">'
            . $detailsSafe . '</div>';
    }
    $inner .= '<p style="color:#333;margin-top:18px;">' . nl2br(htmlspecialchars(guddi_pied_effectif($pied)), false) . ' 🤲</p>'
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
function guddi_message_annulation(string $date, string $pageLien = ''): string
{
    $msg = "‼️‼️ ANNULATION GUDDI ÀJJUMA\n\n"
        . "Àssalaamu hanlaykum,\n\n"
        . "Le Guddi Àjjuma de ce " . guddi_date_longue($date) . " est annulé.\n\n";
    // Lien vers la page de la séance, où l'annulation est affichée
    if (trim($pageLien) !== '') {
        $msg .= "👉 Détails :\n" . trim($pageLien) . "\n\n";
    }
    return $msg
        . "Ñungi leen di tuubal bu baax.\n\n"
        . "Sëriñ Muntaxaa MBÀKKE yàlla na fi yàgg Lool te wér.\n\n"
        . "Commission Culte,\nTouba Lyon";
}

/**
 * Corps HTML d'un e-mail annonçant l'annulation d'un Guddi Àjjuma.
 */
function guddi_email_annulation(string $date, string $pageLien = ''): string
{
    $date_fr = date('d/m/Y', strtotime($date));
    $jour = guddi_jour_fr($date);

    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
        . '<h2 style="color:#b91c1c;">‼️‼️ ANNULATION GUDDI ÀJJUMA</h2>'
        . '<p style="color:#333;">Àssalaamu hanlaykum,</p>'
        . '<p style="color:#333;">Le <strong>Guddi Àjjuma</strong> de ce '
        . '<strong>' . htmlspecialchars($jour) . ' ' . htmlspecialchars($date_fr) . '</strong> est annulé.</p>'
        . (trim($pageLien) !== '' ? '<p style="color:#333;"><strong>👉 Détails :</strong> <a href="' . htmlspecialchars(trim($pageLien)) . '" style="color:#b91c1c;font-weight:700;">Voir la page du Guddi Àjjuma</a></p>' : '')
        . '<p style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;color:#991b1b;">Ñungi leen di tuubal bu baax.</p>'
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
/** Dossier (dans uploads/) où sont rangés les enregistrements des séances du Guddi Àjjuma. */
function guddi_audio_dossier_nom(): string
{
    return 'GuddiAjjuma_Historique';
}

/** Chemin absolu de ce dossier. */
function guddi_audio_dossier_abs(): string
{
    return __DIR__ . '/uploads/' . guddi_audio_dossier_nom();
}

/**
 * Chemin absolu du fichier d'un enregistrement d'après la valeur stockée en base :
 * « GuddiAjjuma_Historique/nom.mp3 » (nouveau) ou « nom.mp3 » directement dans uploads/ (anciens enregistrements). Null si valeur douteuse.
 */
function guddi_audio_fichier_abs(string $audioPath): ?string
{
    $p = trim($audioPath);
    if ($p === '') { return null; }
    if (preg_match('#^' . preg_quote(guddi_audio_dossier_nom(), '#') . '/([A-Za-z0-9._-]+)$#', $p, $m)) { return guddi_audio_dossier_abs() . '/' . $m[1]; }
    if (strpos($p, '/') === false && strpos($p, '\\') === false) { return __DIR__ . '/uploads/' . basename($p); }
    return null;
}

/**
 * Ferme le dossier des enregistrements à tout accès direct (fichier .htaccess) : ils ne se lisent que par guddi_audio.php,
 * pour un membre connecté, sans lien de téléchargement.
 */
function guddi_audio_proteger_dossier(): void
{
    $d = guddi_audio_dossier_abs();
    if (!is_dir($d)) { @mkdir($d, 0755, true); }
    $h = $d . '/.htaccess';
    if (is_dir($d) && !is_file($h)) {
        @file_put_contents($h, "# Enregistrements du Guddi Ajjuma : aucun acces direct, lecture par guddi_audio.php uniquement\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\nOptions -Indexes\n");
    }
}

/** Adresse du lecteur sécurisé d'une séance (balise <audio>). */
function guddi_audio_lecture_url(int $idSeance): string
{
    return 'guddi_audio.php?id=' . $idSeance;
}

/** Adresse (relative au site) de l'enregistrement dans uploads/ (usage interne : ne sert plus au lecteur, qui passe par guddi_audio.php). */
function guddi_audio_url(string $audioPath): string
{
    $p = trim($audioPath);
    $morceaux = array_map('rawurlencode', array_values(array_filter(explode('/', $p), 'strlen')));
    return 'uploads/' . implode('/', $morceaux);
}

/**
 * Nom du fichier d'un enregistrement d'après la date de la séance : « GuddiAjjuma_2026-11-12.mp3 ».
 * Si ce nom existe déjà (ancien enregistrement de la même séance, encore en place pendant le remplacement), « _2 », « _3 »… est ajouté :
 * un fichier existant n'est jamais écrasé.
 */
function guddi_audio_nom_fichier(string $dateGuddi, string $ext, string $dossier, int $id): string
{
    guddi_audio_proteger_dossier();
    $base = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateGuddi) ? 'GuddiAjjuma_' . $dateGuddi : 'GuddiAjjuma_seance_' . $id;
    $nom = $base . '.' . $ext;
    for ($n = 2; is_file($dossier . '/' . $nom) && $n < 100; $n++) { $nom = $base . '_' . $n . '.' . $ext; }
    return $nom;
}

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
function guddi_enregistrer_audio(int $id, array $fichier, string $dossier, string &$erreur, string $dateGuddi = ''): ?string
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
    $nom = guddi_audio_nom_fichier($dateGuddi, $ext, $dossier, $id);
    if (!move_uploaded_file($tmp, $dossier . '/' . $nom)) {
        $erreur = "L'enregistrement n'a pas pu être rangé sur le serveur.";
        return null;
    }
    return basename($dossier) === guddi_audio_dossier_nom() ? guddi_audio_dossier_nom() . '/' . $nom : $nom;
}


/** Taille maximale d'un enregistrement envoyé par morceaux : 250 Mo (indépendante de la limite par requête du serveur). */
function guddi_audio_limite_totale(): int
{
    return 250 * 1024 * 1024;
}

/** La même limite, dite en français. */
function guddi_audio_limite_totale_texte(): string
{
    return round(guddi_audio_limite_totale() / (1024 * 1024)) . ' Mo';
}

/**
 * Reçoit un morceau d'un enregistrement (envoi par morceaux de quelques Mo : chaque requête reste petite, donc acceptée
 * par l'hébergeur quelle que soit la taille totale). Les morceaux arrivent dans l'ordre et sont assemblés dans
 * uploads/.part_<jeton> (le point initial interdit tout accès direct). Renvoie ['ok' => bool, 'recu' => octets, 'complet' => bool, 'error' => ?].
 */
function guddi_recevoir_morceau(int $id, array $post, array $fichiers, string $dossier): array
{
    $jeton = (string) ($post['upload_id'] ?? '');
    if ($id <= 0 || !preg_match('/^[a-f0-9]{24,40}$/', $jeton)) { return ['ok' => false, 'error' => "Envoi invalide : rechargez la page puis réessayez."]; }
    $ext = strtolower(pathinfo((string) ($post['nom'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, guddi_audio_extensions(), true)) { return ['ok' => false, 'error' => 'Format audio non reconnu (' . strtoupper(implode(', ', guddi_audio_extensions())) . ' acceptés).']; }
    $total = (int) ($post['taille'] ?? 0);
    $offset = (int) ($post['offset'] ?? -1);
    if ($total <= 0 || $total > guddi_audio_limite_totale()) { return ['ok' => false, 'error' => "L'enregistrement dépasse " . guddi_audio_limite_totale_texte() . '.']; }
    $m = $fichiers['morceau'] ?? null;
    if (!$m || (int) ($m['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($m['tmp_name'] ?? ''))) {
        $code = (int) ($m['error'] ?? UPLOAD_ERR_NO_FILE);
        return ['ok' => false, 'error' => ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) ? "Un morceau dépasse la limite du serveur : réessayez, ou contactez l'administrateur du site." : "Le morceau n'a pas été reçu (code $code). Réessayez."];
    }
    if (!is_dir($dossier) && !@mkdir($dossier, 0755, true)) { return ['ok' => false, 'error' => "Le dossier des fichiers n'a pas pu être créé."]; }
    guddi_audio_proteger_dossier();
    // Nettoyage : envois abandonnés depuis plus d'un jour
    foreach ((array) glob($dossier . '/.part_*') as $vieux) { if (is_file($vieux) && filemtime($vieux) < time() - 86400) { @unlink($vieux); } }
    $part = $dossier . '/.part_' . $jeton;
    $meta = $part . '.json';
    if ($offset === 0) {
        @unlink($part);
        file_put_contents($meta, json_encode(['id' => $id, 'ext' => $ext, 'taille' => $total, 'nom' => basename((string) ($post['nom'] ?? ''))]));
        file_put_contents($part, '');
    } else {
        $mj = is_file($meta) ? json_decode((string) file_get_contents($meta), true) : null;
        if (!is_array($mj) || (int) $mj['id'] !== $id || (int) $mj['taille'] !== $total || $mj['ext'] !== $ext || !is_file($part)) {
            return ['ok' => false, 'error' => "L'envoi a été interrompu côté serveur : recommencez depuis le début."];
        }
        clearstatcache(true, $part);
        if (filesize($part) !== $offset) { return ['ok' => true, 'recu' => (int) filesize($part), 'complet' => false, 'resync' => true]; }
    }
    $in = @fopen($m['tmp_name'], 'rb');
    $out = @fopen($part, 'ab');
    if (!$in || !$out) { return ['ok' => false, 'error' => "Le morceau n'a pas pu être enregistré sur le serveur."]; }
    stream_copy_to_stream($in, $out);
    fclose($in); fclose($out);
    clearstatcache(true, $part);
    $recu = (int) filesize($part);
    if ($recu > $total) { @unlink($part); @unlink($meta); return ['ok' => false, 'error' => "Taille reçue supérieure à la taille annoncée : recommencez l'envoi."]; }
    return ['ok' => true, 'recu' => $recu, 'complet' => $recu === $total];
}

/** Range l'enregistrement assemblé par morceaux : renvoie son nom de fichier définitif, ou null avec $erreur. */
function guddi_audio_depuis_morceaux(int $id, string $jeton, string $dossier, string &$erreur, string $dateGuddi = ''): ?string
{
    if (!preg_match('/^[a-f0-9]{24,40}$/', $jeton)) { $erreur = "Envoi invalide : recommencez."; return null; }
    $part = $dossier . '/.part_' . $jeton;
    $meta = $part . '.json';
    $mj = is_file($meta) ? json_decode((string) file_get_contents($meta), true) : null;
    if (!is_array($mj) || (int) $mj['id'] !== $id || !is_file($part)) { $erreur = "L'enregistrement envoyé est introuvable sur le serveur : recommencez l'envoi."; return null; }
    clearstatcache(true, $part);
    if ((int) filesize($part) !== (int) $mj['taille']) { $erreur = "L'enregistrement est incomplet sur le serveur (" . (int) filesize($part) . ' / ' . (int) $mj['taille'] . " octets) : recommencez l'envoi."; return null; }
    $nom = guddi_audio_nom_fichier($dateGuddi, (string) $mj['ext'], $dossier, $id);
    if (!@rename($part, $dossier . '/' . $nom)) { $erreur = "L'enregistrement n'a pas pu être rangé sur le serveur."; return null; }
    @unlink($meta);
    return basename($dossier) === guddi_audio_dossier_nom() ? guddi_audio_dossier_nom() . '/' . $nom : $nom;
}

/** Enregistrement joint à une requête : assemblé par morceaux (audio_token) ou envoyé d'un bloc (champ audio_guddi). */
function guddi_audio_recu(int $id, array $post, array $fichiers, string $dossier, string &$erreur, string $dateGuddi = ''): ?string
{
    $jeton = trim((string) ($post['audio_token'] ?? ''));
    if ($jeton !== '') { return guddi_audio_depuis_morceaux($id, $jeton, $dossier, $erreur, $dateGuddi); }
    return guddi_enregistrer_audio($id, $fichiers['audio_guddi'] ?? [], $dossier, $erreur, $dateGuddi);
}