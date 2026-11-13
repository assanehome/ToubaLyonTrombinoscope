<?php
/**
 * Touba Lyon 2026 - Redirection des liens courts (voir lien_court_helper.php)
 *
 * l.php?c=<code à 8 caractères> renvoie vers l'adresse enregistrée. Seules les adresses enregistrées par le site dans ses propres
 * messages sont atteignables (pas de redirection libre) et uniquement en http / https.
 */
require_once __DIR__ . '/db_setup.php';

$code = (string) ($_GET['c'] ?? '');
$url = '';
if (preg_match('/^[a-f0-9]{8}$/', $code)) {
    try {
        $st = $pdo->prepare("SELECT url FROM liens_courts WHERE code = ?");
        $st->execute([$code]);
        $url = (string) $st->fetchColumn();
    } catch (Exception $e) { $url = ''; }
}
if ($url === '' || !preg_match('#^https?://#i', $url)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Lien introuvable</title></head>'
        . '<body style="font-family:system-ui,sans-serif;background:#0a110e;color:#e8e2d0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1.5rem;text-align:center;">'
        . '<div><h1 style="color:#ffd873;font-size:1.3rem;">🔗 Lien introuvable</h1><p style="color:#a9b5ad;">Ce lien n\'existe plus ou n\'est pas valide.</p></div></body></html>';
    exit;
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: ' . $url, true, 302);
exit;
