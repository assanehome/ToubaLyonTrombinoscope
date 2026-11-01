<?php
/**
 * Touba Lyon 2026 - Actualités (commission Secrétariat Général)
 *
 * Un responsable prépare un message d'actualité (type + titre + contenu),
 * le publie, puis le diffuse aux membres : notification in-app, WhatsApp
 * (lien wa.me pré-rempli) et e-mail. Les membres peuvent être mentionnés
 * par « @Prénom Nom » dans le contenu.
 */
require_once __DIR__ . '/dahira_emails.php';

/** Liste des types d'actualité (triés par nom). */
function actu_types(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT id, nom FROM actualite_types ORDER BY nom ASC")->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/** Émoji d'en-tête selon le type d'actualité. */
function actu_type_emoji(string $type): string
{
    $t = mb_strtolower(trim($type));
    if ($t === '') { return '📰'; }
    if (strpos($t, 'décès') !== false || strpos($t, 'deces') !== false || strpos($t, 'condol') !== false) { return '🕊️'; }
    if (strpos($t, 'mariage') !== false || strpos($t, 'mariée') !== false || strpos($t, 'nikah') !== false) { return '💍'; }
    if (strpos($t, 'félicit') !== false || strpos($t, 'felicit') !== false) { return '🎉'; }
    if (strpos($t, 'événement') !== false || strpos($t, 'evenement') !== false) { return '📅'; }
    if (strpos($t, 'visite') !== false) { return '🤝'; }
    if (strpos($t, 'rappel') !== false) { return '⏰'; }
    if (strpos($t, 'contribution') !== false) { return '🤲'; }
    if (strpos($t, 'compte') !== false) { return '📝'; }
    if (strpos($t, 'communiqué') !== false || strpos($t, 'communique') !== false) { return '📢'; }
    return '📰';
}

/** URL de la page d'un membre (relative, ou absolue pour les e-mails / WhatsApp). */
function actu_lien_membre(int $id, bool $absolu = false): string
{
    return ($absolu ? dahira_base_url() . '/' : '') . 'membre_detail.php?id=' . (int) $id;
}

/** Carte id => « Prénom Nom » des membres mentionnés (à partir du champ mentions). */
function actu_mentions_map(PDO $pdo, string $mentions): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $mentions)))));
    if (empty($ids)) { return []; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $map = [];
    try {
        $st = $pdo->prepare("SELECT id, prenom, nom FROM membres WHERE id IN ($ph)");
        $st->execute($ids);
        while ($r = $st->fetch()) { $map[(int) $r['id']] = trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')); }
    } catch (Exception $e) {
        $map = [];
    }
    return $map;
}

/**
 * Contenu échappé en HTML, avec les mentions « @Prénom Nom » transformées en
 * liens cliquables vers la page du membre. $membres : [id => « Prénom Nom »].
 */
function actu_contenu_html(string $contenu, array $membres = [], bool $absolu = false, string $linkStyle = 'color:#1b4332;font-weight:700;text-decoration:underline;'): string
{
    $html = nl2br(htmlspecialchars(trim($contenu)));
    // Noms les plus longs d'abord : évite les remplacements partiels
    uasort($membres, static function ($a, $b) { return mb_strlen((string) $b) - mb_strlen((string) $a); });
    foreach ($membres as $mid => $nom) {
        $nom = trim((string) $nom);
        if ($nom === '') { continue; }
        $needle = '@' . htmlspecialchars($nom);
        $url = htmlspecialchars(actu_lien_membre((int) $mid, $absolu));
        $link = '<a href="' . $url . '" style="' . $linkStyle . '">@' . htmlspecialchars($nom) . '</a>';
        $html = str_replace($needle, $link, $html);
    }
    return $html;
}

/** Section WhatsApp listant les membres cités et le lien vers leur page. */
function actu_mentions_wa(array $membres = []): string
{
    if (empty($membres)) { return ''; }
    $out = "\n\n👥 Membres cités :";
    foreach ($membres as $mid => $nom) {
        $nom = trim((string) $nom);
        if ($nom === '') { continue; }
        $out .= "\n- " . $nom . " : " . actu_lien_membre((int) $mid, true);
    }
    return $out;
}

/**
 * Message WhatsApp d'une actualité. $mentions : [id => « Prénom Nom »] pour
 * ajouter en bas les liens vers la page des membres cités.
 */
function actu_message(string $titre, string $contenu, string $type = '', array $mentions = [], int $id = 0, string $pdfPath = ''): string
{
    $emoji = actu_type_emoji($type);
    $msg = $emoji . " " . mb_strtoupper(trim($type !== '' ? $type : 'Actualité')) . " " . $emoji . "\n\n"
        . "Àssalaamu haleykum,\n\n";
    if (trim($titre) !== '') {
        $msg .= "*" . trim($titre) . "*\n\n";
    }
    $msg .= trim($contenu) . actu_mentions_wa($mentions);
    // Liens : page de l'actualité (détails) et document PDF joint le cas échéant
    if ($id > 0) {
        $msg .= "\n\n👉 Voir le détail :\n" . dahira_base_url() . '/actualite_detail.php?id=' . $id;
    }
    if (trim($pdfPath) !== '') {
        $msg .= "\n\n📄 Document joint :\n" . dahira_base_url() . '/uploads/' . rawurlencode(trim($pdfPath));
    }
    $msg .= "\n\nSecrétariat Général,\nTouba Lyon";
    return $msg;
}

/**
 * Corps HTML d'un e-mail d'actualité (enveloppe commune du Dahira).
 * $mentions : [id => « Prénom Nom »] pour rendre les mentions cliquables.
 */
function actu_email(string $titre, string $contenu, string $type = '', array $mentions = []): string
{
    $emoji = actu_type_emoji($type);
    $inner =
        '<h1 style="margin:0 0 6px;font-size:20px;color:#1b4332;">' . $emoji . ' ' . htmlspecialchars(trim($titre !== '' ? $titre : ($type !== '' ? $type : 'Actualité'))) . '</h1>'
        . ($type !== '' ? '<p style="margin:0 0 16px;font-size:12px;color:#9aa39d;text-transform:uppercase;letter-spacing:1px;font-weight:bold;">' . htmlspecialchars(trim($type)) . '</p>' : '')
        . '<p style="margin:0 0 14px;">Àssalaamu haleykum,</p>'
        . '<div style="margin:0 0 14px;color:#2b2b2b;line-height:1.6;">' . actu_contenu_html($contenu, $mentions, true) . '</div>'
        . '<p style="color:#888;font-size:12px;margin-top:18px;">— Secrétariat Général, Touba Lyon</p>';
    return dahira_email_wrap($inner, $type !== '' ? $type : 'Actualité');
}
