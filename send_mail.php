<?php
/**
 * Touba Lyon 2026 - Client SMTP autonome (sans dépendance)
 *
 * Envoie un e-mail via le serveur SMTP configuré dans mail_config.php,
 * avec authentification (AUTH LOGIN) et connexion SSL implicite (port 465).
 *
 * Suffisant pour les e-mails transactionnels (réinitialisation de mot de passe,
 * notifications). Pour des besoins plus avancés, envisager PHPMailer.
 *
 * Usage :
 *   require_once __DIR__ . '/send_mail.php';
 *   $ok = send_smtp_mail('dest@exemple.com', 'Nom Destinataire', 'Sujet', '<p>HTML…</p>', 'Texte brut');
 */
require_once __DIR__ . '/mail_config.php';

/**
 * Encode un en-tête en UTF-8 (RFC 2047) si nécessaire.
 */
function smtp_encode_header(string $value): string
{
    if (preg_match('/[^\x20-\x7e]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

/**
 * Envoie un e-mail. Retourne true en cas de succès, false sinon (erreur journalisée).
 */
function send_smtp_mail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool
{
    $host   = SMTP_HOST;
    $port   = (int) SMTP_PORT;
    $secure = strtolower((string) SMTP_SECURE);

    // Connexion : 'ssl' => SSL implicite dès l'ouverture (port 465).
    $remote = ($secure === 'ssl') ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";

    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $conn = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$conn) {
        error_log("Touba Lyon SMTP: connexion échouée à {$remote} — {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($conn, 30);

    // Lit une réponse SMTP (gère le multi-ligne "250-…" jusqu'à "250 …").
    $readReply = static function () use ($conn): string {
        $data = '';
        while (($line = fgets($conn, 515)) !== false) {
            $data .= $line;
            // 4e caractère ' ' => dernière ligne ; '-' => ligne intermédiaire.
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $write = static function (string $cmd) use ($conn): void {
        fwrite($conn, $cmd . "\r\n");
    };

    $code = static function (string $reply): int {
        return (int) substr($reply, 0, 3);
    };

    $fail = static function (string $stage, string $reply) use ($conn): bool {
        error_log("Touba Lyon SMTP: échec à l'étape {$stage} — réponse: " . trim($reply));
        @fwrite($conn, "QUIT\r\n");
        @fclose($conn);
        return false;
    };

    // Bannière de bienvenue
    $reply = $readReply();
    if ($code($reply) !== 220) {
        return $fail('greeting', $reply);
    }

    $ehloName = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $write('EHLO ' . $ehloName);
    $reply = $readReply();
    if ($code($reply) !== 250) {
        // Repli HELO
        $write('HELO ' . $ehloName);
        $reply = $readReply();
        if ($code($reply) !== 250) {
            return $fail('ehlo', $reply);
        }
    }

    if (SMTP_AUTH) {
        $write('AUTH LOGIN');
        $reply = $readReply();
        if ($code($reply) !== 334) {
            return $fail('auth_login', $reply);
        }
        $write(base64_encode(SMTP_USER));
        $reply = $readReply();
        if ($code($reply) !== 334) {
            return $fail('auth_user', $reply);
        }
        $write(base64_encode(SMTP_PASS));
        $reply = $readReply();
        if ($code($reply) !== 235) {
            return $fail('auth_pass', $reply);
        }
    }

    $write('MAIL FROM:<' . SMTP_FROM_EMAIL . '>');
    $reply = $readReply();
    if ($code($reply) !== 250) {
        return $fail('mail_from', $reply);
    }

    $write('RCPT TO:<' . $toEmail . '>');
    $reply = $readReply();
    if (!in_array($code($reply), [250, 251], true)) {
        return $fail('rcpt_to', $reply);
    }

    $write('DATA');
    $reply = $readReply();
    if ($code($reply) !== 354) {
        return $fail('data', $reply);
    }

    // Construction du message (MIME multipart/alternative : texte + HTML)
    if ($textBody === '') {
        $textBody = trim(preg_replace('/\s+/', ' ', strip_tags($htmlBody)));
    }
    $boundary = 'tl_' . bin2hex(random_bytes(12));
    $date = date('r');
    $messageId = '<' . bin2hex(random_bytes(16)) . '@' . (parse_url('//' . SMTP_HOST, PHP_URL_HOST) ?: 'toubalyon.com') . '>';

    $fromHeader = smtp_encode_header(SMTP_FROM_NAME) . ' <' . SMTP_FROM_EMAIL . '>';
    $toHeader   = ($toName !== '' ? smtp_encode_header($toName) . ' ' : '') . '<' . $toEmail . '>';

    $headers = [];
    $headers[] = 'Date: ' . $date;
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'To: ' . $toHeader;
    $headers[] = 'Reply-To: ' . SMTP_FROM_EMAIL;
    $headers[] = 'Subject: ' . smtp_encode_header($subject);
    $headers[] = 'Message-ID: ' . $messageId;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $body = '';
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
    $body .= chunk_split(base64_encode($textBody)) . "\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $body .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $body .= '--' . $boundary . '--' . "\r\n";

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

    // Dot-stuffing : toute ligne commençant par '.' est doublée.
    $message = preg_replace('/^\./m', '..', $message);
    // Normalise les fins de ligne en CRLF.
    $message = preg_replace('/\r\n|\r|\n/', "\r\n", $message);

    fwrite($conn, $message . "\r\n.\r\n");
    $reply = $readReply();
    if ($code($reply) !== 250) {
        return $fail('send', $reply);
    }

    $write('QUIT');
    @fclose($conn);
    return true;
}
?>
