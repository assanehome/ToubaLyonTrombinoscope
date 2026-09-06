<?php
/**
 * Touba Lyon 2026 - Libellé de la période courante d'un Khatm périodique.
 * Utilisé côté admin (wird_admin, wird_suivi) et côté membre (index, wird).
 */

/**
 * Retourne un libellé « Période N/total · du JJ/MM/AAAA au JJ/MM/AAAA » pour
 * une session appartenant à une série périodique. Chaîne vide si ponctuelle.
 *
 * @param array $s Ligne quran_sessions (doit contenir periodicite, serie_id,
 *                 date_debut, date_fin, id).
 */
function wird_period_label(?PDO $pdo, array $s): string
{
    $per = $s['periodicite'] ?? 'ponctuelle';
    if ($per === 'ponctuelle' || empty($s['serie_id'])) { return ''; }

    $dr = '';
    if (!empty($s['date_debut'])) {
        $dr = 'du ' . date('d/m/Y', strtotime((string)$s['date_debut']))
            . (!empty($s['date_fin']) ? ' au ' . date('d/m/Y', strtotime((string)$s['date_fin'])) : '');
    }

    $rank = 0; $total = 0;
    if ($pdo instanceof PDO) {
        try {
            $q = $pdo->prepare("SELECT id FROM quran_sessions WHERE serie_id = ? ORDER BY date_debut ASC, id ASC");
            $q->execute([(int)$s['serie_id']]);
            $i = 0;
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                $i++; $total++;
                if ((int)$rid === (int)$s['id']) { $rank = $i; }
            }
        } catch (Exception $e) { /* libellé sans numéro */ }
    }

    $label = '';
    if ($rank > 0) { $label = 'Période ' . $rank . ($total > 0 ? '/' . $total : ''); }
    if ($dr !== '') { $label = ($label !== '' ? $label . ' · ' : '') . $dr; }
    return $label;
}

/**
 * Durée écoulée entre deux dates, en jours + heures (ex. « 3j 5h »).
 * $end vide => maintenant. Renvoie '—' si début absent.
 */
function wird_duree_jh(?string $start, ?string $end = null): string
{
    if (empty($start)) { return '—'; }
    $s = strtotime((string)$start);
    if ($s === false) { return '—'; }
    $e = !empty($end) ? strtotime((string)$end) : time();
    if ($e === false) { $e = time(); }
    $diff = max(0, $e - $s);
    $j = (int) floor($diff / 86400);
    $h = (int) floor(($diff % 86400) / 3600);
    return $j . 'j ' . $h . 'h';
}
