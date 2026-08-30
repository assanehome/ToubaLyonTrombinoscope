<?php
/**
 * Touba Lyon 2026 - Notifications du Dahira (cloche dans le navigateur)
 *
 * Repris de la plateforme Daara : chaque fois qu'un avis part (ex. « vous avez
 * des Juz à valider »), la même information est déposée ici. La page ouverte
 * dans le navigateur (ou le raccourci installé sur le téléphone) la récupère
 * et l'affiche : une notification du système si la personne l'a autorisée,
 * sinon un petit bandeau dans la page.
 *
 * Les notifications sont liées aux comptes membres (table membres).
 */

const TROBA_NOTIF_KEEP_DAYS = 60;

/**
 * Table des notifications, créée à la volée sur les bases existantes.
 */
function troba_ensure_notification_schema(PDO $pdo): void
{
    if (!empty($_SESSION['troba_notif_schema_ok'])) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                membre_id INT NOT NULL,
                kind VARCHAR(40) NOT NULL,
                title VARCHAR(150) NOT NULL,
                body VARCHAR(500) NOT NULL,
                url VARCHAR(190) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                shown_at DATETIME NULL DEFAULT NULL,
                read_at DATETIME NULL DEFAULT NULL,
                deleted_at DATETIME NULL DEFAULT NULL,
                INDEX idx_membre_created (membre_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Migration : colonne deleted_at si absente (bases existantes)
        try {
            $pdo->query("SELECT deleted_at FROM notifications LIMIT 1");
        } catch (PDOException $e2) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
        }
        // Migration : colonne pushed_at (Web Push vers les appareils) si absente
        try {
            $pdo->query("SELECT pushed_at FROM notifications LIMIT 1");
        } catch (PDOException $e2) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN pushed_at DATETIME NULL DEFAULT NULL");
        }
        $_SESSION['troba_notif_schema_ok'] = 1;
    } catch (PDOException $e) {
        error_log('Touba Lyon - création table notifications : ' . $e->getMessage());
    }
}

/**
 * Dépose une notification pour un membre. Jamais bloquant : si l'enregistrement
 * échoue, l'e-mail a de toute façon été envoyé.
 */
function troba_notify_membre(PDO $pdo, int $membre_id, string $kind, string $title, string $body, ?string $url = null): void
{
    if ($membre_id <= 0) {
        return;
    }
    troba_ensure_notification_schema($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (membre_id, kind, title, body, url)
            VALUES (:mid, :kind, :title, :body, :url)
        ");
        $stmt->execute([
            ':mid'   => $membre_id,
            ':kind'  => mb_substr($kind, 0, 40),
            ':title' => mb_substr(trim($title), 0, 150),
            ':body'  => mb_substr(trim($body), 0, 500),
            ':url'   => $url !== null ? mb_substr($url, 0, 190) : null,
        ]);
        // Web Push : planifie l'envoi vers les appareils en fin de requête
        troba_notification_push_apres_reponse($pdo);
    } catch (PDOException $e) {
        error_log('Touba Lyon - dépôt d\'une notification : ' . $e->getMessage());
    }
}

/**
 * Notifie tous les membres validés (statut approved) — utilisé pour les
 * publications de Dahira / Guddi Àjjuma. Jamais bloquant.
 */
function troba_notify_all_membres(PDO $pdo, string $kind, string $title, string $body, ?string $url = null): int
{
    $count = 0;
    try {
        $ids = $pdo->query("SELECT id FROM membres WHERE status = 'approved'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $mid) {
            troba_notify_membre($pdo, (int)$mid, $kind, $title, $body, $url);
            $count++;
        }
    } catch (Exception $e) {
        error_log('Touba Lyon - notification tous membres : ' . $e->getMessage());
    }
    return $count;
}

/**
 * Notifications récentes d'un membre, la plus récente d'abord.
 */
function troba_notifications_recent(PDO $pdo, int $membre_id, int $limit = 15): array
{
    troba_ensure_notification_schema($pdo);
    $limit = max(1, min(50, $limit));
    try {
        $stmt = $pdo->prepare("
            SELECT id, kind, title, body, url, created_at, shown_at, read_at
            FROM notifications
            WHERE membre_id = :mid AND deleted_at IS NULL
            ORDER BY id DESC
            LIMIT $limit
        ");
        $stmt->execute([':mid' => $membre_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Touba Lyon - lecture des notifications : ' . $e->getMessage());
        return [];
    }
}

/**
 * Notifications jamais affichées (celles à faire apparaître maintenant).
 */
function troba_notifications_pending(PDO $pdo, int $membre_id): array
{
    troba_ensure_notification_schema($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT id, kind, title, body, url, created_at
            FROM notifications
            WHERE membre_id = :mid AND shown_at IS NULL AND deleted_at IS NULL
            ORDER BY id ASC
            LIMIT 10
        ");
        $stmt->execute([':mid' => $membre_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Touba Lyon - notifications en attente : ' . $e->getMessage());
        return [];
    }
}

function troba_notifications_unread_count(PDO $pdo, int $membre_id): int
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE membre_id = :mid AND read_at IS NULL AND deleted_at IS NULL");
        $stmt->execute([':mid' => $membre_id]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Suppression douce d'une notification pour un membre (elle disparaît de la
 * liste et ne réapparaît plus).
 */
function troba_notifications_delete(PDO $pdo, int $membre_id, int $id): void
{
    if ($id <= 0) {
        return;
    }
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET deleted_at = NOW() WHERE id = ? AND membre_id = ?");
        $stmt->execute([$id, $membre_id]);
    } catch (PDOException $e) {
        error_log('Touba Lyon - suppression d\'une notification : ' . $e->getMessage());
    }
}

/**
 * Marque comme affichées les notifications que le navigateur vient de montrer.
 */
function troba_notifications_mark_shown(PDO $pdo, int $membre_id, array $ids): void
{
    $ids = array_values(array_filter(array_map('intval', $ids), static function ($i) { return $i > 0; }));
    if (empty($ids)) {
        return;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications SET shown_at = NOW()
            WHERE membre_id = ? AND shown_at IS NULL AND id IN ($in)
        ");
        $stmt->execute(array_merge([$membre_id], $ids));
    } catch (PDOException $e) {
        error_log('Touba Lyon - marquage des notifications : ' . $e->getMessage());
    }
}

function troba_notifications_mark_all_read(PDO $pdo, int $membre_id): void
{
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE membre_id = :mid AND read_at IS NULL");
        $stmt->execute([':mid' => $membre_id]);
    } catch (PDOException $e) {
        error_log('Touba Lyon - notifications lues : ' . $e->getMessage());
    }
}

/**
 * Purge des notifications anciennes, une fois par session.
 */
function troba_purge_notifications(PDO $pdo): void
{
    if (!empty($_SESSION['troba_notif_purged'])) {
        return;
    }
    $_SESSION['troba_notif_purged'] = 1;
    $jours = (int)TROBA_NOTIF_KEEP_DAYS;
    try {
        $pdo->exec("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL $jours DAY)");
    } catch (PDOException $e) {
        error_log('Touba Lyon - purge des notifications : ' . $e->getMessage());
    }
}

/**
 * Envoie vers les appareils (Web Push) les notifications pas encore poussées.
 * Appelée en fin de requête et par la vérification périodique : un membre
 * dont le site est fermé reçoit ainsi l'information sur son téléphone.
 */
function troba_notification_push_pending(PDO $pdo, int $limite = 30): int
{
    require_once __DIR__ . '/push_helper.php';
    if (push_vapid_config() === null) {
        return 0; // clés VAPID absentes : le push est inactif
    }
    $limite = max(1, min(100, $limite));
    try {
        $stmt = $pdo->query("
            SELECT id, membre_id, title, body, url
            FROM notifications
            WHERE pushed_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 2 DAY)
            ORDER BY id ASC
            LIMIT $limite
        ");
        $lignes = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Touba Lyon - lecture des notifications à pousser : ' . $e->getMessage());
        return 0;
    }

    $envoyees = 0;
    foreach ($lignes as $n) {
        push_to_user($pdo, (int)$n['membre_id'], (string)$n['title'], (string)$n['body'], $n['url'] ?: null);
        try {
            $maj = $pdo->prepare("UPDATE notifications SET pushed_at = NOW() WHERE id = :id");
            $maj->execute([':id' => (int)$n['id']]);
        } catch (PDOException $e) {
            // sans importance : au pire la notification repartira une fois
        }
        $envoyees++;
    }
    return $envoyees;
}

/**
 * Programme l'envoi des notifications pour la fin de la requête : la réponse
 * est d'abord rendue au navigateur (fastcgi_finish_request), l'utilisateur
 * n'attend donc pas les appels aux services de notification.
 */
function troba_notification_push_apres_reponse(PDO $pdo, int $limite = 30): void
{
    static $programme = false;
    if ($programme) {
        return;
    }
    $programme = true;
    register_shutdown_function(static function () use ($pdo, $limite) {
        // En FPM, la réponse part avant les envois ; en CGI (cas de
        // l'hébergement actuel), on se contente de quelques envois pour ne pas
        // faire attendre la personne, le reste partira à la vérification suivante.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            $limite = min($limite, 3);
        }
        try {
            troba_notification_push_pending($pdo, $limite);
        } catch (Throwable $e) {
            error_log('Touba Lyon - envoi des notifications en fin de requête : ' . $e->getMessage());
        }
    });
}
