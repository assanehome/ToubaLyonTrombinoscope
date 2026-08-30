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
    $host = $_SERVER['HTTP_HOST'] ?? 'toubalyon.com';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
    // Si l'hôte courant est l'ancien domaine ou un sous-domaine trombinoscope, on
    // bascule vers le nouveau chemin canonique https://toubalyon.com/Dahira
    if (stripos($host, 'trombinoscope') !== false) {
        return 'https://toubalyon.com/Dahira';
    }
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

/** Bouton d'action (compatible clients mail). */
function dahira_email_button($url, $label, $color = '#1b4332') {
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px auto;">'
        . '<tr><td align="center" style="border-radius:10px;background:' . $color . ';box-shadow:0 4px 12px rgba(27,67,50,0.25);">'
        . '<a href="' . $url . '" target="_blank" style="display:inline-block;padding:14px 34px;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px;">'
        . $label . '</a></td></tr></table>';
}

/** Tableau d'identifiants (clé / valeur) mis en forme. */
function dahira_email_credentials($rows) {
    $html = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:separate;border-spacing:0;margin:20px 0;border:1px solid #e6e6e6;border-radius:12px;overflow:hidden;">';
    $i = 0;
    foreach ($rows as $label => $value) {
        $bg = ($i % 2 === 0) ? '#faf8f2' : '#ffffff';
        $html .= '<tr>'
            . '<td style="padding:12px 16px;background:' . $bg . ';font-family:Arial,sans-serif;font-size:13px;color:#6b7280;font-weight:bold;width:42%;border-bottom:1px solid #f0f0f0;">' . htmlspecialchars($label) . '</td>'
            . '<td style="padding:12px 16px;background:' . $bg . ';font-family:\'Courier New\',monospace;font-size:15px;color:#1b4332;font-weight:bold;border-bottom:1px solid #f0f0f0;">' . $value . '</td>'
            . '</tr>';
        $i++;
    }
    $html .= '</table>';
    return $html;
}

/** Encart d'alerte (ex. « pensez à changer votre mot de passe »). */
function dahira_email_notice($text, $accent = '#c2410c', $bg = '#fff7ed') {
    return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:20px 0;border-radius:10px;background:' . $bg . ';border-left:4px solid ' . $accent . ';">'
        . '<tr><td style="padding:14px 18px;font-family:Arial,sans-serif;font-size:14px;color:#7c2d12;line-height:1.5;">' . $text . '</td></tr></table>';
}

/** Enveloppe HTML commune (design carte moderne, compatible clients mail). */
function dahira_email_wrap($inner, $badge = '') {
    $badgeHtml = $badge !== ''
        ? '<div style="display:inline-block;margin-top:12px;padding:4px 14px;border-radius:50px;background:rgba(212,175,55,0.18);color:#d4af37;font-family:Arial,sans-serif;font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">' . htmlspecialchars($badge) . '</div>'
        : '';
    return
        '<div style="margin:0;padding:0;background:#eef1ee;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#eef1ee;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,0.08);">'
        // Bandeau en-tête
        . '<tr><td style="background:#1b4332;background-image:linear-gradient(135deg,#1b4332 0%,#2d6a4f 100%);padding:34px 32px 30px;text-align:center;border-top:4px solid #d4af37;">'
        . '<div style="font-family:Arial,sans-serif;font-size:22px;font-weight:bold;color:#ffffff;letter-spacing:0.5px;">Dahira Touba Lyon</div>'
        . '<div style="font-family:Arial,sans-serif;font-size:12px;color:#b7d4c5;margin-top:4px;">MUBAWWA-A-ASIDQIN</div>'
        . $badgeHtml
        . '</td></tr>'
        // Contenu
        . '<tr><td style="padding:36px 32px 28px;font-family:Arial,sans-serif;color:#2b2b2b;line-height:1.6;font-size:15px;">'
        . $inner
        . '</td></tr>'
        // Pied de page
        . '<tr><td style="background:#f6f7f6;padding:22px 32px;text-align:center;border-top:1px solid #ececec;">'
        . '<div style="font-family:Arial,sans-serif;font-size:12px;color:#9aa39d;line-height:1.6;">'
        . 'Secrétariat Général — <strong style="color:#1b4332;">Touba Lyon</strong><br>'
        . 'Message automatique, merci de ne pas répondre.'
        . '</div></td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</div>';
}

/** E-mail de confirmation d'inscription (avec la charte). */
function send_inscription_email($toEmail, $toName) {
    $inner =
        '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Confirmation de votre inscription</h1>'
        . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($toName) . '</strong>,</p>'
        . '<p style="margin:0 0 14px;">Nous avons bien reçu votre demande d\'inscription au <strong>Dahira MUBAWWA-A-ASIDQIN, Touba Lyon</strong>. '
        . 'Votre demande est <strong>en attente de validation</strong> par un administrateur. '
        . 'Vous recevrez un e-mail dès qu\'elle sera validée.</p>'
        . dahira_email_notice('💳 Pensez à prévoir <strong>10 €</strong> pour votre carte de membre.', '#d4af37', '#fdf9ec')
        . '<p style="margin:22px 0 10px;font-weight:bold;color:#1b4332;">Pour rappel, voici la charte que vous avez acceptée :</p>'
        . dahira_charte_html_block()
        . '<p style="margin-top:18px;">Jërëjëf !</p>';
    return send_smtp_mail($toEmail, $toName, 'Confirmation de votre inscription au Dahira - Touba Lyon', dahira_email_wrap($inner, 'Inscription reçue'));
}

/** E-mail de validation par l'admin (avec les fonctions et activités du Dahira). */
function send_validation_email($toEmail, $toName) {
    $loginUrl = htmlspecialchars(dahira_base_url() . '/login.php', ENT_QUOTES);
    $inner =
        '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Votre adhésion est validée 🎉</h1>'
        . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($toName) . '</strong>,</p>'
        . '<p style="margin:0 0 14px;">Nous avons le plaisir de vous informer que votre adhésion au <strong>Dahira Touba Lyon</strong> a été '
        . '<strong>validée</strong>. Vous faites désormais partie des membres actifs.</p>'
        . dahira_email_button($loginUrl, 'Accéder à mon espace membre')
        . '<p style="margin:0 0 14px;">Connectez-vous avec votre <strong>adresse email</strong> et le <strong>mot de passe</strong> définis lors de l\'inscription pour accéder au Dahira - Mubawwa-A-Sidqin et jouer à Ki Kan La.</p>'
        . '<h3 style="color:#1b4332;margin:24px 0 10px;">Les fonctions et activités du Dahira</h3>'
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
        . '<p style="margin-top:16px;">Jërëjëf et bienvenue parmi nous !</p>';
    return send_smtp_mail($toEmail, $toName, 'Votre adhésion au Dahira est validée - Touba Lyon', dahira_email_wrap($inner, 'Adhésion validée'));
}

/** E-mail des identifiants d'un compte intégrateur (créé par l'admin). */
function send_integrateur_credentials($toEmail, $toNom, $tempPassword) {
    $loginUrl = htmlspecialchars(dahira_base_url() . '/integrateur_login.php', ENT_QUOTES);
    $inner =
        '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Votre compte intégrateur</h1>'
        . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($toNom) . '</strong>,</p>'
        . '<p style="margin:0 0 14px;">Un compte <strong>intégrateur</strong> vient de vous être créé sur la plateforme du Dahira Touba Lyon. '
        . 'Il vous permet de compléter les informations des inscriptions (intégrateur en charge, souhait de commission, présentation).</p>'
        . dahira_email_credentials([
            'Adresse email' => htmlspecialchars($toEmail),
            'Mot de passe temporaire' => htmlspecialchars($tempPassword),
        ])
        . dahira_email_button($loginUrl, 'Se connecter')
        . dahira_email_notice('🔒 <strong>Important :</strong> pour des raisons de sécurité, vous devrez <strong>choisir un nouveau mot de passe dès votre première connexion</strong>.')
        . '<p style="margin-top:16px;">Jërëjëf !</p>';
    return send_smtp_mail($toEmail, $toNom, 'Votre compte intégrateur - Touba Lyon', dahira_email_wrap($inner, 'Nouveau compte'));
}

/** E-mail de notification d'attribution d'un rôle (intégrateur ou administrateur) à un membre. */
function send_role_notification($toEmail, $toName, $role) {
    if ($role === 'admin') {
        $loginUrl = htmlspecialchars(dahira_base_url() . '/admin_login.php', ENT_QUOTES);
        $inner =
            '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Vous êtes désormais administrateur</h1>'
            . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($toName) . '</strong>,</p>'
            . '<p style="margin:0 0 14px;">Le rôle d\'<strong>administrateur</strong> vient de vous être attribué sur la plateforme du Dahira Touba Lyon. '
            . 'Vous avez désormais accès à l\'administration (validation des adhésions, gestion des membres, suivi des intégrations, etc.).</p>'
            . '<p style="margin:0 0 14px;">Pour accéder à l\'administration, connectez-vous à l\'<strong>espace admin</strong> avec votre '
            . '<strong>adresse e-mail</strong> et le <strong>mot de passe de votre compte membre</strong>.</p>'
            . dahira_email_button($loginUrl, 'Accéder à l\'administration')
            . '<p style="margin:0;">Jërëjëf pour votre engagement !</p>';
        return send_smtp_mail($toEmail, $toName, 'Vous êtes administrateur - Dahira Touba Lyon', dahira_email_wrap($inner, 'Nouveau rôle'));
    }
    if ($role === 'gestion_kourel') {
        $loginUrl = htmlspecialchars(dahira_base_url() . '/login.php', ENT_QUOTES);
        $inner =
            '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Vous gérez désormais les Kurels</h1>'
            . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($toName) . '</strong>,</p>'
            . '<p style="margin:0 0 14px;">Le rôle de <strong>gestion des Kurels</strong> vient de vous être attribué sur la plateforme du Dahira Touba Lyon. '
            . 'Vous pouvez désormais créer des Kurels (groupes de membres) et gérer leurs membres.</p>'
            . '<p style="margin:0 0 14px;">Connectez-vous à votre <strong>espace membre</strong> : un lien '
            . '<strong>« Kurels »</strong> apparaît dans le menu et vous donne accès à la gestion des Kurels.</p>'
            . dahira_email_button($loginUrl, 'Accéder à mon espace membre')
            . '<p style="margin:0;">Jërëjëf pour votre engagement !</p>';
        return send_smtp_mail($toEmail, $toName, 'Gestion des Kurels - Dahira Touba Lyon', dahira_email_wrap($inner, 'Nouveau rôle'));
    }
    // Rôle intégrateur (par défaut)
    $loginUrl = htmlspecialchars(dahira_base_url() . '/login.php', ENT_QUOTES);
    $inner =
        '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Vous êtes désormais intégrateur</h1>'
        . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($toName) . '</strong>,</p>'
        . '<p style="margin:0 0 14px;">Le rôle d\'<strong>intégrateur</strong> vient de vous être attribué. '
        . 'Vous accompagnerez de nouveaux membres dans leur intégration au Dahira Touba Lyon '
        . '(souhait de commission, présentation, test Kourel…).</p>'
        . '<p style="margin:0 0 14px;">Connectez-vous à votre <strong>espace membre</strong> : un lien '
        . '<strong>« Suivi intégrations »</strong> apparaît dans le menu et vous donne accès aux inscrits qui vous sont assignés.</p>'
        . dahira_email_button($loginUrl, 'Accéder à mon espace membre')
        . '<p style="margin:0;">Jërëjëf pour votre engagement !</p>';
    return send_smtp_mail($toEmail, $toName, 'Vous êtes intégrateur - Dahira Touba Lyon', dahira_email_wrap($inner, 'Nouveau rôle'));
}

/** E-mail des identifiants d'un compte administrateur (créé par un autre admin). */
function send_admin_credentials($toEmail, $toNom, $username, $tempPassword) {
    $loginUrl = htmlspecialchars(dahira_base_url() . '/admin_login.php', ENT_QUOTES);
    $inner =
        '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Votre compte administrateur</h1>'
        . '<p style="margin:0 0 14px;">Bonjour <strong>' . htmlspecialchars($toNom) . '</strong>,</p>'
        . '<p style="margin:0 0 14px;">Un compte <strong>administrateur</strong> vient de vous être créé sur la plateforme Touba Lyon.</p>'
        . dahira_email_credentials([
            'Nom d\'utilisateur' => htmlspecialchars($username),
            'Mot de passe temporaire' => htmlspecialchars($tempPassword),
        ])
        . dahira_email_button($loginUrl, 'Se connecter à l\'administration')
        . dahira_email_notice('🔒 <strong>Important :</strong> vous devrez <strong>choisir un nouveau mot de passe dès votre première connexion</strong>.')
        . '<p style="margin-top:16px;">Jërëjëf !</p>';
    return send_smtp_mail($toEmail, $toNom, 'Votre compte administrateur - Touba Lyon', dahira_email_wrap($inner, 'Nouveau compte'));
}
?>
