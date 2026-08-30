<?php
/**
 * Touba Lyon 2026 - Notifications « Web Push » (Trombinoscope / Dahira)
 *
 * Permet de prévenir un membre même lorsque le site est fermé : le message
 * est remis par le service de notification du navigateur (Google, Mozilla,
 * Apple), qui réveille le service worker de l'application installée.
 *
 * Tout est fait en PHP natif, sans dépendance à installer (repris du projet
 * Daara) :
 *   - identification du serveur d'envoi : jeton VAPID signé en ES256 (RFC 8292) ;
 *   - chiffrement du message : « aes128gcm » de bout en bout (RFC 8291),
 *     de sorte que le service de notification ne peut pas lire le contenu.
 *
 * Les clés VAPID vivent dans config.secret.php (hors dépôt) :
 *   'vapid_public'  => '...'   (clé publique, base64url, 65 octets décodés)
 *   'vapid_private' => '...'   (clé privée, base64url, 32 octets décodés)
 *   'vapid_subject' => 'mailto:noreply@toubalyon.com'
 *
 * push_generate_vapid_keys() fabrique cette paire une fois pour toutes.
 */

const TROBA_PUSH_TTL = 86400;          // durée de conservation par le service, en secondes
const TROBA_PUSH_RECORD_SIZE = 4096;   // taille d'enregistrement annoncée dans l'entête

// ---------------------------------------------------------------------------
// Encodage
// ---------------------------------------------------------------------------

function push_b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function push_b64url_decode(string $txt): string
{
    $txt = strtr(trim($txt), '-_', '+/');
    $reste = strlen($txt) % 4;
    if ($reste) {
        $txt .= str_repeat('=', 4 - $reste);
    }
    return (string)base64_decode($txt, false);
}

// ---------------------------------------------------------------------------
// ASN.1 : reconstruire des clés OpenSSL à partir d'octets bruts
// ---------------------------------------------------------------------------

/** Longueur ASN.1 (forme courte ou longue). */
function push_asn1_len(int $n): string
{
    if ($n < 0x80) {
        return chr($n);
    }
    $octets = '';
    while ($n > 0) {
        $octets = chr($n & 0xFF) . $octets;
        $n >>= 8;
    }
    return chr(0x80 | strlen($octets)) . $octets;
}

function push_asn1(int $tag, string $contenu): string
{
    return chr($tag) . push_asn1_len(strlen($contenu)) . $contenu;
}

/** Clé privée P-256 au format PEM, à partir du scalaire (32 octets) et du point public (65). */
function push_pem_private(string $d, string $point): string
{
    $oid_courbe = "\x2A\x86\x48\xCE\x3D\x03\x01\x07";      // 1.2.840.10045.3.1.7
    $sec1 = push_asn1(0x30,
        push_asn1(0x02, "\x01")                            // version 1
        . push_asn1(0x04, $d)                              // clé privée
        . push_asn1(0xA0, push_asn1(0x06, $oid_courbe))    // [0] courbe
        . push_asn1(0xA1, push_asn1(0x03, "\x00" . $point))// [1] clé publique
    );
    return "-----BEGIN EC PRIVATE KEY-----\n"
        . chunk_split(base64_encode($sec1), 64, "\n")
        . "-----END EC PRIVATE KEY-----\n";
}

/** Clé publique P-256 au format PEM, à partir du point non compressé (65 octets). */
function push_pem_public(string $point): string
{
    $oid_ec     = "\x2A\x86\x48\xCE\x3D\x02\x01";          // 1.2.840.10045.2.1
    $oid_courbe = "\x2A\x86\x48\xCE\x3D\x03\x01\x07";
    $spki = push_asn1(0x30,
        push_asn1(0x30, push_asn1(0x06, $oid_ec) . push_asn1(0x06, $oid_courbe))
        . push_asn1(0x03, "\x00" . $point)
    );
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spki), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/**
 * Nouvelle paire de clés P-256. Renvoie ['point' => 65 octets, 'd' => 32 octets]
 * ou null si OpenSSL ne peut pas produire de clé.
 */
function push_new_keypair(): ?array
{
    $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
    $cle = @openssl_pkey_new($options);
    if (!$cle) {
        // Certaines installations n'ont pas d'openssl.cnf : on en fournit un minimal
        $tmp = tempnam(sys_get_temp_dir(), 'trombi_ssl');
        if ($tmp !== false) {
            file_put_contents($tmp, "[ req ]\ndistinguished_name = dn\n[ dn ]\n");
            $cle = @openssl_pkey_new($options + ['config' => $tmp, 'private_key_bits' => 384]);
            @unlink($tmp);
        }
    }
    if (!$cle) {
        error_log('Touba Lyon - push : génération de clé EC impossible');
        return null;
    }
    $d = openssl_pkey_get_details($cle);
    if (!$d || !isset($d['ec']['x'], $d['ec']['y'], $d['ec']['d'])) {
        return null;
    }
    return [
        'point' => "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                          . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT),
        'd'     => str_pad($d['ec']['d'], 32, "\x00", STR_PAD_LEFT),
    ];
}

/**
 * Fabrique une paire VAPID à recopier dans config.secret.php.
 * À lancer une seule fois (outil d'administration).
 */
function push_generate_vapid_keys(): ?array
{
    $paire = push_new_keypair();
    if ($paire === null) {
        return null;
    }
    return [
        'vapid_public'  => push_b64url_encode($paire['point']),
        'vapid_private' => push_b64url_encode($paire['d']),
    ];
}

// ---------------------------------------------------------------------------
// Chiffrement du message (RFC 8291, « aes128gcm »)
// ---------------------------------------------------------------------------

/**
 * Chiffre un contenu pour un abonnement donné.
 *
 * @param string      $contenu    texte à transmettre (JSON)
 * @param string      $ua_public  clé publique du navigateur (base64url)
 * @param string      $auth       secret d'authentification du navigateur (base64url)
 * @param string|null $sel        sel de 16 octets (imposé seulement pour les tests)
 * @param array|null  $expediteur paire locale ['point' => ..., 'd' => ...] (tests)
 * @return string|null corps binaire prêt à envoyer
 */
function push_encrypt(string $contenu, string $ua_public, string $auth,
                      ?string $sel = null, ?array $expediteur = null): ?string
{
    $point_ua = push_b64url_decode($ua_public);
    $secret_auth = push_b64url_decode($auth);
    if (strlen($point_ua) !== 65 || $point_ua[0] !== "\x04" || strlen($secret_auth) !== 16) {
        error_log('Touba Lyon - push : abonnement invalide (clé ou secret de mauvaise taille)');
        return null;
    }

    $expediteur = $expediteur ?? push_new_keypair();
    if ($expediteur === null) {
        return null;
    }
    $sel = $sel ?? random_bytes(16);

    // Secret partagé entre l'expéditeur et le navigateur
    $prive = openssl_pkey_get_private(push_pem_private($expediteur['d'], $expediteur['point']));
    $publique_ua = openssl_pkey_get_public(push_pem_public($point_ua));
    if (!$prive || !$publique_ua) {
        error_log('Touba Lyon - push : clés inutilisables par OpenSSL');
        return null;
    }
    $partage = openssl_pkey_derive($publique_ua, $prive, 32);
    if ($partage === false || strlen((string)$partage) !== 32) {
        error_log('Touba Lyon - push : échange de clés (ECDH) en échec');
        return null;
    }

    // Dérivation des clés : RFC 8291, section 3.4
    $info_auth = "WebPush: info\x00" . $point_ua . $expediteur['point'];
    $ikm = hash_hkdf('sha256', $partage, 32, $info_auth, $secret_auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $sel);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $sel);

    // Un seul enregistrement : le contenu, suivi du délimiteur de fin (0x02)
    $etiquette = '';
    $chiffre = openssl_encrypt($contenu . "\x02", 'aes-128-gcm', $cek,
        OPENSSL_RAW_DATA, $nonce, $etiquette);
    if ($chiffre === false) {
        error_log('Touba Lyon - push : chiffrement AES-GCM en échec');
        return null;
    }

    // Entête : sel | taille d'enregistrement | longueur de la clé | clé publique
    return $sel
        . pack('N', TROBA_PUSH_RECORD_SIZE)
        . chr(strlen($expediteur['point']))
        . $expediteur['point']
        . $chiffre . $etiquette;
}

// ---------------------------------------------------------------------------
// Identification du serveur d'envoi (VAPID, RFC 8292)
// ---------------------------------------------------------------------------

/** Signature ES256 : la forme DER d'OpenSSL est convertie en 64 octets bruts. */
function push_der_to_raw(string $der): ?string
{
    $i = 0;
    if (($der[$i++] ?? '') !== "\x30") { return null; }
    $len = ord($der[$i++]);
    if ($len & 0x80) { $i += ($len & 0x7F); }

    $lire = static function (string $der, int &$i): ?string {
        if (($der[$i++] ?? '') !== "\x02") { return null; }
        $n = ord($der[$i++]);
        $v = substr($der, $i, $n);
        $i += $n;
        $v = ltrim($v, "\x00");
        return str_pad($v, 32, "\x00", STR_PAD_LEFT);
    };
    $r = $lire($der, $i);
    $s = $lire($der, $i);
    return ($r === null || $s === null) ? null : $r . $s;
}

/**
 * Entête Authorization pour un endpoint donné.
 */
function push_vapid_header(string $endpoint, string $public_b64, string $private_b64, string $sujet): ?string
{
    $parties = parse_url($endpoint);
    if (!$parties || empty($parties['host'])) {
        return null;
    }
    $audience = ($parties['scheme'] ?? 'https') . '://' . $parties['host'];

    $entete = push_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $charge = push_b64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => $sujet,
    ]));
    $a_signer = $entete . '.' . $charge;

    $point = push_b64url_decode($public_b64);
    $d = push_b64url_decode($private_b64);
    if (strlen($point) !== 65 || strlen($d) !== 32) {
        error_log('Touba Lyon - push : clés VAPID de taille inattendue');
        return null;
    }
    $prive = openssl_pkey_get_private(push_pem_private($d, $point));
    if (!$prive) {
        error_log('Touba Lyon - push : clé VAPID privée illisible');
        return null;
    }
    $der = '';
    if (!openssl_sign($a_signer, $der, $prive, OPENSSL_ALGO_SHA256)) {
        error_log('Touba Lyon - push : signature VAPID en échec');
        return null;
    }
    $brut = push_der_to_raw($der);
    if ($brut === null) {
        return null;
    }

    return 'vapid t=' . $a_signer . '.' . push_b64url_encode($brut) . ', k=' . $public_b64;
}

// ---------------------------------------------------------------------------
// Envoi
// ---------------------------------------------------------------------------

/** Clés VAPID de la configuration, ou null si elles n'ont pas été posées. */
function push_vapid_config(): ?array
{
    static $conf = null;
    if ($conf !== null) {
        return $conf ?: null;
    }
    $fichier = __DIR__ . '/config.secret.php';
    $secrets = is_file($fichier) ? require $fichier : [];
    $public  = getenv('VAPID_PUBLIC')  ?: ($secrets['vapid_public']  ?? '');
    $prive   = getenv('VAPID_PRIVATE') ?: ($secrets['vapid_private'] ?? '');
    $sujet   = getenv('VAPID_SUBJECT') ?: ($secrets['vapid_subject'] ?? 'mailto:noreply@toubalyon.com');
    if ($public === '' || $prive === '') {
        $conf = false;
        return null;
    }
    $conf = ['public' => $public, 'private' => $prive, 'subject' => $sujet];
    return $conf;
}

/**
 * Envoie un message à un abonnement. Renvoie le code HTTP obtenu, ou 0 en cas
 * d'échec local (clés absentes, chiffrement impossible).
 */
function push_send(array $abonnement, array $message): int
{
    $conf = push_vapid_config();
    if ($conf === null) {
        return 0;
    }
    $endpoint = (string)($abonnement['endpoint'] ?? '');
    if ($endpoint === '' || !preg_match('#^https://#', $endpoint)) {
        return 0;
    }

    $corps = push_encrypt(
        json_encode($message, JSON_UNESCAPED_UNICODE),
        (string)($abonnement['p256dh'] ?? ''),
        (string)($abonnement['auth'] ?? '')
    );
    if ($corps === null) {
        return 0;
    }
    $autorisation = push_vapid_header($endpoint, $conf['public'], $conf['private'], $conf['subject']);
    if ($autorisation === null) {
        return 0;
    }

    $entetes = [
        'Authorization: ' . $autorisation,
        'Content-Encoding: aes128gcm',
        'Content-Type: application/octet-stream',
        'TTL: ' . TROBA_PUSH_TTL,
        'Urgency: normal',
    ];

    // cURL si disponible, flux HTTP sinon : l'hébergement n'a pas toujours cURL
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $corps,
            CURLOPT_HTTPHEADER     => $entetes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erreur = curl_error($ch);
        curl_close($ch);
        if ($code === 0 && $erreur !== '') {
            error_log('Touba Lyon - push : envoi impossible (' . $erreur . ')');
        }
        return $code;
    }

    $contexte = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $entetes),
        'content'       => $corps,
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);
    $reponse = @file_get_contents($endpoint, false, $contexte);
    if ($reponse === false && empty($http_response_header)) {
        return 0;
    }
    foreach ($http_response_header ?? [] as $ligne) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $ligne, $m)) {
            return (int)$m[1];
        }
    }
    return 0;
}

// ---------------------------------------------------------------------------
// Abonnements
// ---------------------------------------------------------------------------

function ensure_push_schema(PDO $pdo): void
{
    if (!empty($_SESSION['trombi_push_schema_ok'])) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                membre_id INT NOT NULL,
                endpoint VARCHAR(500) NOT NULL,
                endpoint_hash CHAR(64) NOT NULL,
                p256dh VARCHAR(200) NOT NULL,
                auth VARCHAR(100) NOT NULL,
                user_agent VARCHAR(190) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_sent_at DATETIME NULL DEFAULT NULL,
                last_status INT NULL DEFAULT NULL,
                UNIQUE KEY uniq_endpoint (endpoint_hash),
                INDEX idx_membre (membre_id),
                FOREIGN KEY (membre_id) REFERENCES membres(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $_SESSION['trombi_push_schema_ok'] = 1;
    } catch (PDOException $e) {
        error_log('Touba Lyon - création table push_subscriptions : ' . $e->getMessage());
    }
}

/** Enregistre (ou met à jour) l'abonnement d'un navigateur. */
function push_save_subscription(PDO $pdo, int $membre_id, string $endpoint, string $p256dh, string $auth): bool
{
    ensure_push_schema($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions (membre_id, endpoint, endpoint_hash, p256dh, auth, user_agent)
            VALUES (:uid, :ep, :hash, :p256, :auth, :ua)
            ON DUPLICATE KEY UPDATE
                membre_id = VALUES(membre_id),
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
                user_agent = VALUES(user_agent),
                last_status = NULL
        ");
        $stmt->execute([
            ':uid'  => $membre_id,
            ':ep'   => mb_substr($endpoint, 0, 500),
            ':hash' => hash('sha256', $endpoint),
            ':p256' => mb_substr($p256dh, 0, 200),
            ':auth' => mb_substr($auth, 0, 100),
            ':ua'   => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('Touba Lyon - enregistrement d\'un abonnement push : ' . $e->getMessage());
        return false;
    }
}

function push_delete_subscription(PDO $pdo, string $endpoint): void
{
    try {
        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = :hash");
        $stmt->execute([':hash' => hash('sha256', $endpoint)]);
    } catch (PDOException $e) {
        error_log('Touba Lyon - suppression d\'un abonnement push : ' . $e->getMessage());
    }
}

function push_delete_user_subscriptions(PDO $pdo, int $membre_id): void
{
    try {
        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE membre_id = :uid");
        $stmt->execute([':uid' => $membre_id]);
    } catch (PDOException $e) {
        error_log('Touba Lyon - suppression des abonnements push : ' . $e->getMessage());
    }
}

/**
 * Pousse une notification vers tous les appareils d'un compte.
 * Les abonnements devenus caducs (404 / 410) sont supprimés.
 */
function push_to_user(PDO $pdo, int $membre_id, string $titre, string $texte, ?string $lien = null): int
{
    if (push_vapid_config() === null) {
        return 0; // clés VAPID absentes : le push est simplement inactif
    }
    ensure_push_schema($pdo);
    try {
        $stmt = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE membre_id = :uid");
        $stmt->execute([':uid' => $membre_id]);
        $abonnements = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Touba Lyon - lecture des abonnements push : ' . $e->getMessage());
        return 0;
    }

    $envoyes = 0;
    foreach ($abonnements as $ab) {
        $code = push_send($ab, [
            'titre' => $titre,
            'texte' => $texte,
            'lien'  => $lien ?: 'index.php',
        ]);

        if ($code === 404 || $code === 410) {
            push_delete_subscription($pdo, (string)$ab['endpoint']);
            continue;
        }
        try {
            $maj = $pdo->prepare("UPDATE push_subscriptions SET last_sent_at = NOW(), last_status = :code WHERE id = :id");
            $maj->execute([':code' => $code, ':id' => (int)$ab['id']]);
        } catch (PDOException $e) {
            // sans importance : l'envoi a eu lieu
        }
        if ($code >= 200 && $code < 300) {
            $envoyes++;
        } else {
            error_log('Touba Lyon - push refusé (HTTP ' . $code . ') pour l\'abonnement ' . (int)$ab['id']);
        }
    }
    return $envoyes;
}
