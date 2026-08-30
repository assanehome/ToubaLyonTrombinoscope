<?php
/**
 * Touba Lyon 2026 - Aides pour la prise de contact (WhatsApp / e-mail)
 */

/**
 * Normalise un numéro de téléphone au format international sans "+",
 * utilisable dans un lien wa.me (WhatsApp). Par défaut, préfixe France (33).
 * Retourne '' si aucun chiffre.
 */
function wa_number($phone, $defaultCountry = '33') {
    $d = preg_replace('/\D+/', '', (string)($phone ?? ''));
    if ($d === '') return '';
    // 00XX... -> XX...
    if (strpos($d, '00') === 0) {
        $d = substr($d, 2);
    } elseif (strpos($d, '0') === 0) {
        // Numéro national commençant par 0 -> préfixe pays
        $d = $defaultCountry . substr($d, 1);
    }
    return $d;
}
