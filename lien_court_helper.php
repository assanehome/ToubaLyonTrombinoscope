<?php
/**
 * Touba Lyon 2026 - Liens courts pour les messages (WhatsApp)
 *
 * Un message WhatsApp ne peut pas contenir de lien « caché » derrière un libellé : on y raccourcit donc les adresses longues
 * (lien Zoom, liseuse du livre à une page donnée…) par une adresse du site, par exemple https://toubalyon.com/Dahira/l.php?c=1a2b3c4d,
 * qui renvoie vers l'adresse d'origine. Les adresses sont enregistrées dans la table liens_courts (une même adresse garde le même code).
 */

/** Adresse courte d'une adresse longue ; sans base de données ou en cas d'erreur, l'adresse d'origine est renvoyée telle quelle. */
function lien_court(PDO $pdo, string $url): string
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url) || strlen($url) > 2000) { return $url; }
    $base = function_exists('dahira_base_url') ? rtrim((string) dahira_base_url(), '/') : 'https://toubalyon.com/Dahira';
    $court = $base . '/l.php?c=';
    // Une adresse déjà courte n'a pas besoin de détour
    if (strlen($url) <= strlen($court) + 8) { return $url; }
    try {
        $code = substr(sha1($url), 0, 8);
        $pdo->prepare("INSERT IGNORE INTO liens_courts (code, url) VALUES (?, ?)")->execute([$code, $url]);
        return $court . $code;
    } catch (Exception $e) {
        return $url;
    }
}
