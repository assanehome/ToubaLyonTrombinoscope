<?php
/**
 * Touba Lyon 2026 - Réalisations des commissions
 *
 * Chaque commission tient la liste de ce qu'elle a réalisé : elle définit ses
 * types de réalisation (nom et description : « Distribution de repas »,
 * « Conférence »…), puis enregistre ses réalisations (type, date, lieu,
 * quantité ou nombre de bénéficiaires, détails). Un tableau de bord en tire
 * les indicateurs, et un rapport PDF les reprend sur une période.
 *
 *   commission_realisation_types   types propres à chaque commission
 *   commission_realisations        les réalisations
 *
 * Pages : commission_realisations.php (gestion et tableau de bord) et
 * commission_realisations_pdf.php (rapport sur une période).
 */

/** Tables des réalisations, créées au premier passage. */
function realisations_schema(PDO $pdo): void
{
    if (!empty($_SESSION['realisations_schema_ok'])) { return; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS commission_realisation_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            commission_id INT NOT NULL,
            nom VARCHAR(120) NOT NULL,
            description TEXT NULL,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_commission (commission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS commission_realisations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            commission_id INT NOT NULL,
            type_id INT NULL,
            titre VARCHAR(255) NOT NULL,
            date_realisation DATE NOT NULL,
            lieu VARCHAR(150) NULL,
            quantite INT NULL,
            details TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            KEY idx_commission_date (commission_id, date_realisation)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $_SESSION['realisations_schema_ok'] = 1;
    } catch (Exception $e) {
        error_log('Touba Lyon - tables des réalisations : ' . $e->getMessage());
    }
}

/** Période demandée (du / au, AAAA-MM-JJ) ; par défaut, l'année en cours jusqu'à aujourd'hui. */
function realisations_periode(array $src): array
{
    $ok = static function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; };
    $du = $ok($src['du'] ?? null) ? (string) $src['du'] : date('Y') . '-01-01';
    $au = $ok($src['au'] ?? null) ? (string) $src['au'] : date('Y-m-d');
    if ($du > $au) { [$du, $au] = [$au, $du]; }
    return [$du, $au];
}

/** Types de la commission (avec le nombre de réalisations de chacun). */
function realisations_types(PDO $pdo, int $cid, bool $actifs_seuls = false): array
{
    try {
        $st = $pdo->prepare("
            SELECT t.*, (SELECT COUNT(*) FROM commission_realisations r WHERE r.type_id = t.id) AS nb
            FROM commission_realisation_types t
            WHERE t.commission_id = ?" . ($actifs_seuls ? " AND t.actif = 1" : '') . "
            ORDER BY t.actif DESC, t.nom ASC");
        $st->execute([$cid]);
        return $st->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/** Réalisations de la commission sur la période, les plus récentes d'abord. */
function realisations_liste(PDO $pdo, int $cid, string $du, string $au): array
{
    try {
        $st = $pdo->prepare("
            SELECT r.*, t.nom AS type_nom
            FROM commission_realisations r
            LEFT JOIN commission_realisation_types t ON t.id = r.type_id
            WHERE r.commission_id = ? AND r.date_realisation BETWEEN ? AND ?
            ORDER BY r.date_realisation DESC, r.id DESC");
        $st->execute([$cid, $du, $au]);
        return $st->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Indicateurs d'une liste de réalisations : nombre, quantité cumulée, types
 * représentés, dernière date, répartition par type et par mois.
 */
function realisations_indicateurs(array $liste, string $du, string $au): array
{
    $ind = ['nb' => count($liste), 'quantite' => 0, 'avec_quantite' => 0, 'derniere' => null,
            'par_type' => [], 'par_mois' => [], 'types' => 0];
    // Tous les mois de la période, même vides : la courbe ne saute aucun mois
    $m = new DateTime(substr($du, 0, 7) . '-01');
    $fin = new DateTime(substr($au, 0, 7) . '-01');
    while ($m <= $fin && count($ind['par_mois']) < 60) {
        $ind['par_mois'][$m->format('Y-m')] = 0;
        $m->modify('+1 month');
    }
    foreach ($liste as $r) {
        $type = trim((string) ($r['type_nom'] ?? '')) !== '' ? (string) $r['type_nom'] : 'Sans type';
        if (!isset($ind['par_type'][$type])) { $ind['par_type'][$type] = ['nb' => 0, 'quantite' => 0]; }
        $ind['par_type'][$type]['nb']++;
        if ($r['quantite'] !== null && $r['quantite'] !== '') {
            $ind['quantite'] += (int) $r['quantite'];
            $ind['avec_quantite']++;
            $ind['par_type'][$type]['quantite'] += (int) $r['quantite'];
        }
        $mois = substr((string) $r['date_realisation'], 0, 7);
        if (isset($ind['par_mois'][$mois])) { $ind['par_mois'][$mois]++; }
        if ($ind['derniere'] === null || $r['date_realisation'] > $ind['derniere']) { $ind['derniere'] = $r['date_realisation']; }
    }
    uasort($ind['par_type'], static fn($a, $b) => $b['nb'] <=> $a['nb']);
    $ind['types'] = count($ind['par_type']);
    return $ind;
}

/** Mois lisible : « janv. 2027 ». */
function realisations_mois_court(string $ym): string
{
    $noms = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return $noms[(int) substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4);
}
