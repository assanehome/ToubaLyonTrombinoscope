<?php
/**
 * Touba Lyon 2026 - E-mails automatiques du Dahira (inscription & validation)
 *
 * Fournit des fonctions réutilisables pour notifier l'adhérent :
 *   - send_inscription_email() : confirmation d'inscription (avec la charte) ;
 *   - send_validation_email()  : confirmation de validation (avec les fonctions du Dahira).
 *
 * Envoi best-effort via le client SMTP autonome (send_mail.php).
 */
require_once __DIR__ . '/send_mail.php';

/** URL de base de l'application (pour les liens dans les e-mails). */
function dahira_base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'trombinoscope.toubalyon.com';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
    return $scheme . '://' . $host . $dir;
}

/** Bloc HTML de la charte (réutilisé dans les e-mails). */
function dahira_charte_html_block() {
    return
        '<h3 style="color:#1b4332;">Charte du Dahira Touba Lyon</h3>'
        . '<p style="font-size:0.9em;color:#555;">Établie lors de l\'assemblée générale du 29 octobre 2022, applicable à tous les membres.</p>'
        . '<p style="font-weight:700;">Section Activité</p>'
        . '<p><strong>Article 1 :</strong> Les activités principales du dahira sont :</p>'
        . '<ul>'
        . '<li>Dahira : 2 fois par mois</li>'
        . '<li>Goudi Al Jumah : chaque jeudi soir</li>'
        . '<li>École coranique pour enfants : tous les dimanches</li>'
        . '<li>Répétitions Kourels : hebdomadaire</li>'
        . '<li>Magals et Gamou</li>'
        . '</ul>'
        . '<p><strong>Article 2 :</strong> Assister aux activités programmées si les conditions sont réunies.</p>'
        . '<p><strong>Article 4 :</strong> Intégrer une commission et participer à ses activités.</p>'
        . '<p><strong>Article 5 :</strong> Veiller au bon fonctionnement et à l\'image du Dahira.</p>'
        . '<p style="font-weight:700;">Section Finance et Social</p>'
        . '<p><strong>Article 6 :</strong> Acheter une carte de membre de 10 €.</p>'
        . '<p><strong>Article 7 :</strong> Cotisations obligatoires : 30 €/mois (360 €/an), 300 €/an pour le Magal, et contribution aux travaux du Keur Serigne Touba selon les capacités.</p>'
        . '<p><strong>Article 8 :</strong> Participer au financement des activités sociales sur sollicitation de la commission sociale.</p>'
        . '<p><strong>Article 9 :</strong> Possibilité de solliciter la commission sociale pour une assistance (financière, physique ou matérielle).</p>';
}

/** Enveloppe HTML commune. */
function dahira_email_wrap($inner) {
    return '<div style="font-family:Arial,sans-serif;color:#1a1a1a;max-width:620px;margin:auto;line-height:1.55;">'
        . $inner
        . '<hr style="border:none;border-top:1px solid #eee;margin:24px 0;">'
        . '<p style="font-size:0.8em;color:#999;">Secrétariat Général, Touba Lyon — message automatique, merci de ne pas répondre.</p>'
        . '</div>';
}

/** E-mail de confirmation d'inscription (avec la charte). */
function send_inscription_email($toEmail, $toName) {
    $inner =
        '<h2 style="color:#1b4332;">Confirmation de votre inscription</h2>'
        . '<p>Assalamu aleykum <strong>' . htmlspecialchars($toName) . '</strong>,</p>'
        . '<p>Nous avons bien reçu votre demande d\'inscription au <strong>Dahira MUBAWWA-A-ASIDQIN, Touba Lyon</strong>. '
        . 'Votre demande est <strong>en attente de validation</strong> par un administrateur. '
        . 'Vous recevrez un e-mail dès qu\'elle sera validée.</p>'
        . '<p>Pour rappel, voici la charte que vous avez acceptée lors de votre inscription :</p>'
        . dahira_charte_html_block()
        . '<p style="margin-top:16px;">Pensez à prévoir <strong>10 €</strong> pour votre carte de membre.</p>'
        . '<p>Jërëjëf !</p>';
    return send_smtp_mail($toEmail, $toName, 'Confirmation de votre inscription au Dahira - Touba Lyon', dahira_email_wrap($inner));
}

/** E-mail de validation par l'admin (avec les fonctions et activités du Dahira). */
function send_validation_email($toEmail, $toName) {
    $loginUrl = htmlspecialchars(dahira_base_url() . '/login.php', ENT_QUOTES);
    $inner =
        '<h2 style="color:#1b4332;">Votre adhésion est validée 🎉</h2>'
        . '<p>Assalamu aleykum <strong>' . htmlspecialchars($toName) . '</strong>,</p>'
        . '<p>Nous avons le plaisir de vous informer que votre adhésion au <strong>Dahira Touba Lyon</strong> a été '
        . '<strong>validée</strong>. Vous faites désormais partie des membres actifs.</p>'
        . '<p style="text-align:center;margin:24px 0;">'
        . '<a href="' . $loginUrl . '" style="background:#1b4332;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:bold;display:inline-block;">Accéder à mon espace membre</a>'
        . '</p>'
        . '<p>Connectez-vous avec votre <strong>adresse email</strong> et le <strong>mot de passe</strong> définis lors de l\'inscription pour accéder au Trombinoscope et jouer à Ki Kan La.</p>'
        . '<h3 style="color:#1b4332;">Les fonctions et activités du Dahira</h3>'
        . '<p>En tant que membre actif, vous êtes invité à participer aux activités suivantes :</p>'
        . '<ul>'
        . '<li><strong>Dahira</strong> : 2 fois par mois</li>'
        . '<li><strong>Goudi Al Jumah</strong> : chaque jeudi soir</li>'
        . '<li><strong>École coranique</strong> pour enfants : tous les dimanches</li>'
        . '<li><strong>Répétitions Kourels</strong> : hebdomadaire</li>'
        . '<li><strong>Magals et Gamou</strong></li>'
        . '</ul>'
        . '<p style="font-weight:700;">Vos engagements :</p>'
        . '<ul>'
        . '<li>Intégrer une <strong>commission</strong> et participer à ses activités (Article 4).</li>'
        . '<li>Carte de membre : <strong>10 €</strong> (Article 6).</li>'
        . '<li>Cotisations : <strong>30 €/mois</strong> (360 €/an), <strong>300 €/an</strong> pour le Magal, et contribution aux travaux du Keur Serigne Touba selon vos capacités (Article 7).</li>'
        . '<li>Participer au financement des activités sociales (Article 8).</li>'
        . '</ul>'
        . '<p>La commission sociale reste à votre disposition pour toute assistance (Article 9).</p>'
        . '<p>Jërëjëf et bienvenue parmi nous !</p>';
    return send_smtp_mail($toEmail, $toName, 'Votre adhésion au Dahira est validée - Touba Lyon', dahira_email_wrap($inner));
}
?>
