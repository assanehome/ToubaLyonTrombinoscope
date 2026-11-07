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

// Texte de bas de page (signature) par défaut, ajouté aux messages WhatsApp et e-mails.
// Modifiable dans admin_planning.php (paramètre « dahira_signature »).
const DAHIRA_SIGNATURE_DEFAUT = 'Sëriñ Muntaxaa Mbakke Yalla nafi Yagg lòolu te wer.🙏🏾';

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

/** Convertit une heure saisie (« 17h00 », « 17h », « 17:30 ») en « HH:MM ». */
function dahira_heure_hm(string $heure): string
{
    $h = str_replace(['h', 'H'], ':', trim($heure));
    if (preg_match('/^(\d{1,2}):?(\d{0,2})/', $h, $m)) {
        $hh = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mm = ($m[2] !== '') ? str_pad($m[2], 2, '0', STR_PAD_LEFT) : '00';
        return $hh . ':' . $mm;
    }
    return '00:00';
}

/**
 * État temporel d'un événement : 'avenir', 'encours' ou 'termine'.
 * Utilise l'heure de début et, si fournie, l'heure de fin. Sans heure de fin,
 * l'événement est « en cours » le jour même après le début, « terminé » le lendemain.
 */
function dahira_event_etat(string $date, string $heureDebut, ?string $heureFin = null): string
{
    $now = time();
    $start = strtotime($date . ' ' . dahira_heure_hm($heureDebut));
    if ($start === false) {
        if ($date < date('Y-m-d')) { return 'termine'; }
        if ($date > date('Y-m-d')) { return 'avenir'; }
        return 'encours';
    }
    if ($now < $start) { return 'avenir'; }
    if ($heureFin !== null && trim($heureFin) !== '') {
        $end = strtotime($date . ' ' . dahira_heure_hm($heureFin));
        if ($end !== false) { return $now > $end ? 'termine' : 'encours'; }
    }
    // Pas d'heure de fin : en cours le jour même, terminé une fois le jour passé
    return $date < date('Y-m-d') ? 'termine' : 'encours';
}

/**
 * La validation de présence est-elle ouverte ? Vrai seulement à partir de
 * l'heure de début de l'événement (date + heure de début déjà atteinte).
 */
function dahira_presence_ouverte(string $date, string $heureDebut): bool
{
    $ts = strtotime($date . ' ' . dahira_heure_hm($heureDebut));
    if ($ts === false) {
        return $date <= date('Y-m-d');
    }
    return time() >= $ts;
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

/**
 * Bouton WhatsApp vert, ouvrant un nouvel onglet.
 * Si $disabled est vrai, le bouton est affiché grisé et non cliquable
 * (ex. tant que l'événement n'est pas publié).
 */
function dahira_wa_button(string $link, string $label = 'Partager sur WhatsApp', bool $small = false, bool $disabled = false): string
{
    $padding = $small ? '0.3rem 0.7rem' : '0.6rem 1.1rem';
    $font = $small ? '0.76rem' : '0.9rem';
    if ($disabled) {
        return '<span title="Disponible une fois l\'événement publié" aria-disabled="true"'
            . ' style="display:inline-flex;align-items:center;gap:0.4rem;background:rgba(37,211,102,0.22);'
            . 'color:rgba(255,255,255,0.5);font-weight:700;font-size:' . $font . ';padding:' . $padding . ';'
            . 'border-radius:12px;white-space:nowrap;cursor:not-allowed;opacity:0.65;">'
            . '<span>🟢</span><span>' . htmlspecialchars($label) . '</span></span>';
    }
    return '<a href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener"'
        . ' style="display:inline-flex;align-items:center;gap:0.4rem;background:#25D366;color:#06281a;'
        . 'font-weight:700;font-size:' . $font . ';padding:' . $padding . ';border-radius:12px;'
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
/**
 * Assainit le HTML du programme (éditeur riche) : jeu de balises limité,
 * sans attributs dangereux.
 */
function dahira_sanitize_html(string $html): string
{
    $allowed = '<p><br><strong><b><em><i><u><s><ul><ol><li><h3><h4><h5><span><div><a><blockquote>';
    $clean = strip_tags($html, $allowed);
    $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
    $clean = preg_replace('/(href\s*=\s*)("|\')\s*javascript:[^"\']*(\2)/i', '$1$2#$3', $clean);
    $clean = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $clean);
    return trim((string) $clean);
}

/** Convertit le programme HTML en texte simple (usage générique). */
function dahira_html_to_texte(string $html): string
{
    $t = str_replace(['</p>', '<br>', '<br/>', '<br />', '</h3>', '</h4>', '</h5>', '</li>', '</blockquote>'], "\n", $html);
    $t = preg_replace('/<li[^>]*>/i', '• ', $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t]+\n/", "\n", $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim((string) $t);
}

/**
 * Convertit le programme HTML en texte formaté WhatsApp
 * (*gras*, _italique_, ~barré~, listes à puces, sauts de ligne).
 */
function dahira_html_to_whatsapp(string $html): string
{
    $t = $html;
    $t = preg_replace('#<(strong|b)\b[^>]*>(.*?)</\1>#is', '*$2*', $t);
    $t = preg_replace('#<(em|i)\b[^>]*>(.*?)</\1>#is', '_$2_', $t);
    $t = preg_replace('#<(s|strike|del)\b[^>]*>(.*?)</\1>#is', '~$2~', $t);
    $t = preg_replace('#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', "\n*$1*\n", $t);
    $t = preg_replace('#<li\b[^>]*>(.*?)</li>#is', "• $1\n", $t);
    $t = preg_replace('#</p\s*>#i', "\n\n", $t);
    $t = preg_replace('#<br\s*/?>#i', "\n", $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t]+\n/", "\n", $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim((string) $t);
}

function dahira_message_annonce(string $date, string $lieu, string $debut, string $fin, string $programme = '', string $lien = '', string $signature = '', string $type = 'dahira'): string
{
    if (trim($signature) === '') { $signature = DAHIRA_SIGNATURE_DEFAUT; }
    // Le programme peut être saisi en HTML : converti en texte formaté WhatsApp
    $programme = dahira_html_to_whatsapp($programme);

    $msg = "As-salaamu haleykum,\n\n"
        . dahira_type_un($type) . " " . dahira_type_libelle($type) . " aura lieu ce " . dahira_date_longue($date) . " :\n";

    if (trim($lieu) !== '') {
        $msg .= "\n📍 Lieu :\n" . trim($lieu) . "\n";
    }
    $msg .= "\n🕐 De " . trim($debut) . " à " . trim($fin) . ".";
    $msg .= "\n📋 Commission Secrétariat Général.";

    if (trim($programme) !== '') {
        $msg .= "\n\n🗓️ *Programme :*\n" . trim($programme);
    }
    if (trim($lien) !== '') {
        $msg .= "\n\n👉 Détails et présence :\n" . trim($lien);
    }
    // Bas de page (signature) à la toute fin du message
    $msg .= "\n\n" . trim($signature);
    return $msg;
}

/**
 * Corps HTML d'un e-mail annonçant un Dahira.
 */
function dahira_email_annonce(string $date, string $lieu, string $debut, string $fin, string $programme = '', string $signature = '', string $type = 'dahira'): string
{
    if (trim($signature) === '') { $signature = DAHIRA_SIGNATURE_DEFAUT; }
    $date_fr = date('d/m/Y', strtotime($date));
    $jour = dahira_jour_fr($date);

    $inner = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
        . '<h2 style="color:#1b4332;">As-salaamu haleykum 🙏</h2>'
        . '<p style="color:#333;">' . dahira_type_un($type) . ' <strong>' . htmlspecialchars(dahira_type_libelle($type)) . '</strong> aura lieu ce '
        . '<strong>' . htmlspecialchars($jour) . ' ' . htmlspecialchars($date_fr) . '</strong> :</p>'
        . '<p style="background:#f6f3e8;border:1px solid #d4af37;border-radius:8px;padding:12px 16px;color:#1b4332;">'
        . '📍 ' . nl2br(htmlspecialchars(trim($lieu))) . '<br>🕐 De <strong>' . htmlspecialchars($debut) . '</strong> à <strong>' . htmlspecialchars($fin) . '</strong><br>📋 Commission Secrétariat Général</p>';

    if (trim($programme) !== '') {
        $inner .= '<h3 style="color:#1b4332;margin:20px 0 8px;">🗓️ Programme</h3>'
            . '<div style="background:#ffffff;border:1px solid #e6e6e6;border-radius:10px;padding:12px 16px;color:#333;line-height:1.5;">'
            . dahira_sanitize_html(trim($programme)) . '</div>';
    }

    $inner .= '<p style="color:#333;margin-top:18px;">' . nl2br(htmlspecialchars(trim($signature))) . '</p>'
        . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p>'
        . '</div>';

    return $inner;
}

/**
 * Message WhatsApp d'ANNULATION d'un Dahira.
 */
function dahira_message_annulation(string $date, string $signature = '', string $type = 'dahira'): string
{
    if (trim($signature) === '') { $signature = DAHIRA_SIGNATURE_DEFAUT; }
    return "‼️‼️ ANNULATION " . mb_strtoupper(dahira_type_libelle($type)) . "\n\n"
        . "As-salaamu haleykum,\n\n"
        . dahira_type_le($type) . " prévu" . (dahira_type_feminin($type) ? "e" : "") . " ce " . dahira_date_longue($date) . " est annulé" . (dahira_type_feminin($type) ? "e" : "") . " inshallaah.\n\n"
        . "Ñungi leen di tuubal bu baax.\n\n"
        . trim($signature);
}

/**
 * Corps HTML d'un e-mail annonçant l'annulation d'un Dahira.
 */
function dahira_email_annulation(string $date, string $signature = '', string $type = 'dahira'): string
{
    if (trim($signature) === '') { $signature = DAHIRA_SIGNATURE_DEFAUT; }
    $date_fr = date('d/m/Y', strtotime($date));
    $jour = dahira_jour_fr($date);
    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
        . '<h2 style="color:#b91c1c;">‼️‼️ ANNULATION ' . htmlspecialchars(mb_strtoupper(dahira_type_libelle($type))) . '</h2>'
        . '<p style="color:#333;">As-salaamu haleykum,</p>'
        . '<p style="color:#333;">' . (dahira_type_feminin($type) ? 'L\'' : 'Le ') . '<strong>' . htmlspecialchars(dahira_type_libelle($type)) . '</strong> prévu' . (dahira_type_feminin($type) ? 'e' : '') . ' ce '
        . '<strong>' . htmlspecialchars($jour) . ' ' . htmlspecialchars($date_fr) . '</strong> est <strong>annulé' . (dahira_type_feminin($type) ? 'e' : '') . '</strong> inshallaah.</p>'
        . '<p style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;color:#991b1b;">Ñungi leen di tuubal bu baax.</p>'
        . '<p style="color:#333;margin-top:16px;">' . nl2br(htmlspecialchars(trim($signature))) . '</p>'
        . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p>'
        . '</div>';
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
// Types d'événement (Dahira, AG, Gamou, Magal, Ngan, Déplacement)
// ---------------------------------------------------------------------------

/** Types d'événement du planning : clé => [libellé, emoji]. */
function dahira_types_evenement(): array
{
    return [
        'dahira'     => ['Dahira', '🕌'],
        'ag'         => ['Assemblée générale', '🏛️'],
        'gamou'      => ['Gamou', '🌙'],
        'magal'      => ['Magal', '🕋'],
        'ngan'       => ['Ngan', '🍲'],
        'deplacement' => ['Déplacement', '🚌'],
    ];
}

/** Clé de type valide (« dahira » par défaut). */
function dahira_type_valide(?string $type): string
{
    $type = strtolower(trim((string) $type));
    return isset(dahira_types_evenement()[$type]) ? $type : 'dahira';
}

/** Libellé du type (ex. « Gamou »). */
function dahira_type_libelle(?string $type): string
{
    return dahira_types_evenement()[dahira_type_valide($type)][0];
}

/** Type féminin en français (accords : une, prévue, annulée) ? */
function dahira_type_feminin(?string $type): bool
{
    return dahira_type_valide($type) === 'ag';
}

/** Article indéfini : « un » ou « une ». */
function dahira_type_un(?string $type): string
{
    return dahira_type_feminin($type) ? 'Une' : 'Un';
}

/** Article défini + type : « Le Gamou » / « L'Assemblée générale ». */
function dahira_type_le(?string $type): string
{
    return dahira_type_feminin($type) ? "L'" . dahira_type_libelle($type) : 'Le ' . dahira_type_libelle($type);
}

/** Emoji du type. */
function dahira_type_emoji(?string $type): string
{
    return dahira_types_evenement()[dahira_type_valide($type)][1];
}

/** Type par défaut choisi dans la configuration du Dahira. */
function dahira_type_defaut(PDO $pdo): string
{
    return dahira_type_valide(dahira_param($pdo, 'dahira_type_defaut', 'dahira'));
}

/** Options <option> d'une liste de types, $selectionne étant la clé choisie. */
function dahira_type_options_html(string $selectionne): string
{
    $h = '';
    foreach (dahira_types_evenement() as $k => $t) {
        $h .= '<option value="' . $k . '"' . ($k === $selectionne ? ' selected' : '') . '>' . $t[1] . ' ' . htmlspecialchars($t[0]) . '</option>';
    }
    return $h;
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

/**
 * Membres ayant validé leur présence à une séance (dahira, guddi, reunion, repetition).
 * @return array<int,array{id:int,prenom:string,nom:string,photo_path:?string}>
 */
function dahira_presence_membres(PDO $pdo, string $type, int $planningId): array
{
    try {
        $st = $pdo->prepare("SELECT m.id, m.prenom, m.nom, m.photo_path
                             FROM presence_validations pv JOIN membres m ON m.id = pv.membre_id
                             WHERE pv.planning_type = ? AND pv.planning_id = ?
                             ORDER BY m.prenom ASC, m.nom ASC");
        $st->execute([$type, $planningId]);
        return $st->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Rend une grille « trombinoscope » (photo + prénom nom) des membres présents.
 * Retourne '' si la liste est vide. Styles inclus (une seule fois par page).
 */
function dahira_presence_trombi_html(array $membres): string
{
    if (empty($membres)) { return ''; }
    static $cssOut = false;
    $h = '';
    if (!$cssOut) {
        $cssOut = true;
        $h .= '<style>'
            . '.pres-trombi{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:0.9rem;}'
            . '.pres-trombi .pt-card{text-align:center;}'
            . '.pres-trombi .pt-av,.pres-trombi .pt-ph{width:62px;height:62px;border-radius:50%;margin:0 auto 0.35rem;display:flex;align-items:center;justify-content:center;border:2px solid var(--accent);}'
            . '.pres-trombi .pt-av{object-fit:cover;}'
            . '.pres-trombi .pt-ph{background:linear-gradient(135deg,#d4af37,#b8902f);color:#0c241a;font-weight:800;font-size:1.05rem;text-transform:uppercase;}'
            . '.pres-trombi .pt-name{font-size:0.78rem;color:var(--white);line-height:1.25;word-break:break-word;}'
            . '</style>';
    }
    $h .= '<div class="pres-trombi">';
    foreach ($membres as $m) {
        $nom = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
        $ini = mb_substr(trim((string) ($m['prenom'] ?? '')), 0, 1) . mb_substr(trim((string) ($m['nom'] ?? '')), 0, 1);
        $photo = trim((string) ($m['photo_path'] ?? ''));
        $av = $photo !== ''
            ? '<img class="pt-av" src="uploads/' . htmlspecialchars($photo) . '" alt="">'
            : '<span class="pt-ph">' . htmlspecialchars($ini) . '</span>';
        $h .= '<div class="pt-card">' . $av . '<div class="pt-name">' . htmlspecialchars($nom) . '</div></div>';
    }
    $h .= '</div>';
    return $h;
}

/**
 * Enregistre l'image jointe à un Dahira (champ de formulaire $champ).
 * Retourne ['name' => nom du fichier ou null, 'error' => message ou null].
 * Formats : JPG, PNG, WebP, GIF — 6 Mo au maximum, contenu vérifié comme image.
 */
function dahira_upload_image(string $champ = 'image'): array
{
    $f = $_FILES[$champ] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['name' => null, 'error' => null];
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['name' => null, 'error' => "L'image n'a pas pu être envoyée (fichier trop volumineux ?)."];
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        return ['name' => null, 'error' => null];
    }
    if ((int) $f['size'] > 6 * 1024 * 1024) {
        return ['name' => null, 'error' => "L'image est trop lourde (6 Mo maximum)."];
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return ['name' => null, 'error' => "Format d'image non autorisé : utilisez JPG, PNG, WebP ou GIF."];
    }
    if (@getimagesize($f['tmp_name']) === false) {
        return ['name' => null, 'error' => "Le fichier n'est pas une image valide."];
    }
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    try { $rand = bin2hex(random_bytes(3)); } catch (Exception $e) { $rand = (string) mt_rand(100000, 999999); }
    $nom = 'dahira_img_' . date('Ymd_His') . '_' . $rand . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nom)) {
        return ['name' => null, 'error' => "Impossible d'enregistrer l'image."];
    }
    return ['name' => $nom, 'error' => null];
}

/** Supprime le fichier image d'un Dahira (nom enregistré en base). */
function dahira_supprimer_image(?string $nom): void
{
    $nom = basename((string) $nom);
    if ($nom !== '' && strpos($nom, 'dahira_img_') === 0) {
        @unlink(__DIR__ . '/uploads/' . $nom);
    }
}
