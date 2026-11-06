<?php
/**
 * Touba Lyon 2026 - Sondages des commissions.
 *
 * Un responsable de commission crée un sondage (question + choix), le publie : tous les
 * membres validés peuvent y répondre. À la création, il choisit qui voit les résultats :
 *   - 'tous'   : tous les membres ;
 *   - 'bureau' : seulement le bureau = les responsables de commission.
 * Le créateur et les responsables de la commission du sondage voient toujours les résultats.
 */

const SONDAGE_VISIBILITE_TOUS = 'tous';
const SONDAGE_VISIBILITE_BUREAU = 'bureau';

/** Le membre fait-il partie du bureau (responsable d'au moins une commission) ? */
function sondage_est_bureau(PDO $pdo, int $membreId): bool
{
    if ($membreId <= 0) { return false; }
    try {
        $st = $pdo->prepare("SELECT 1 FROM commission_gestionnaires WHERE membre_id = ? LIMIT 1");
        $st->execute([$membreId]);
        return $st->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

/** Le membre gère-t-il ce sondage (auteur ou responsable de sa commission) ? */
function sondage_peut_gerer(PDO $pdo, array $sondage, int $membreId): bool
{
    if ($membreId <= 0) { return false; }
    if ((int) ($sondage['auteur_id'] ?? 0) === $membreId) { return true; }
    try {
        $st = $pdo->prepare("SELECT 1 FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ? LIMIT 1");
        $st->execute([(int) ($sondage['commission_id'] ?? 0), $membreId]);
        return $st->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

/** Le membre peut-il voir les résultats de ce sondage ? */
function sondage_peut_voir_resultats(PDO $pdo, array $sondage, int $membreId, bool $estBureau): bool
{
    if (($sondage['resultats_visibilite'] ?? '') !== SONDAGE_VISIBILITE_BUREAU) { return true; }
    return $estBureau || sondage_peut_gerer($pdo, $sondage, $membreId);
}

/** Le sondage est-il ouvert aux réponses (publié, non clôturé, date de fin non dépassée) ? */
function sondage_est_ouvert(array $sondage): bool
{
    if (($sondage['statut'] ?? '') !== 'publie') { return false; }
    $fin = (string) ($sondage['date_fin'] ?? '');
    return $fin === '' || $fin >= date('Y-m-d');
}

/** Options d'un sondage avec le nombre de voix : [['id','libelle','nb'], ...]. */
function sondage_options(PDO $pdo, int $sondageId): array
{
    $st = $pdo->prepare("SELECT o.id, o.libelle, (SELECT COUNT(*) FROM sondage_votes v WHERE v.option_id = o.id) AS nb
                         FROM sondage_options o WHERE o.sondage_id = ? ORDER BY o.position ASC, o.id ASC");
    $st->execute([$sondageId]);
    return $st->fetchAll();
}

/** Nombre de membres ayant répondu. */
function sondage_nb_participants(PDO $pdo, int $sondageId): int
{
    $st = $pdo->prepare("SELECT COUNT(DISTINCT membre_id) FROM sondage_votes WHERE sondage_id = ?");
    $st->execute([$sondageId]);
    return (int) $st->fetchColumn();
}

/** Ids des options choisies par un membre. */
function sondage_mes_choix(PDO $pdo, int $sondageId, int $membreId): array
{
    $st = $pdo->prepare("SELECT option_id FROM sondage_votes WHERE sondage_id = ? AND membre_id = ?");
    $st->execute([$sondageId, $membreId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Votants par option (pour les responsables, sondage non anonyme) : [option_id => [noms]]. */
function sondage_votants(PDO $pdo, int $sondageId): array
{
    $st = $pdo->prepare("SELECT v.option_id, TRIM(CONCAT(COALESCE(m.prenom,''), ' ', COALESCE(m.nom,''))) AS nom
                         FROM sondage_votes v JOIN membres m ON m.id = v.membre_id
                         WHERE v.sondage_id = ? ORDER BY m.nom ASC, m.prenom ASC");
    $st->execute([$sondageId]);
    $r = [];
    foreach ($st->fetchAll() as $row) { $r[(int) $row['option_id']][] = (string) $row['nom']; }
    return $r;
}

/** Barres de résultats (HTML). */
function sondage_resultats_html(array $options, int $participants): string
{
    $totalVoix = 0;
    foreach ($options as $o) { $totalVoix += (int) $o['nb']; }
    $max = 0;
    foreach ($options as $o) { $max = max($max, (int) $o['nb']); }
    $h = '<div class="sd-res">';
    foreach ($options as $o) {
        $nb = (int) $o['nb'];
        $pct = $participants > 0 ? (int) round($nb * 100 / $participants) : 0;
        $h .= '<div class="sd-res-row' . ($nb > 0 && $nb === $max ? ' sd-top' : '') . '">'
            . '<div class="sd-res-top"><span class="sd-res-lib">' . htmlspecialchars((string) $o['libelle']) . '</span>'
            . '<span class="sd-res-nb">' . $nb . ' · ' . $pct . ' %</span></div>'
            . '<div class="sd-bar"><div class="sd-bar-fill" style="width:' . $pct . '%;"></div></div></div>';
    }
    $h .= '<div class="sd-res-total">👥 ' . $participants . ' participant' . ($participants > 1 ? 's' : '') . '</div></div>';
    return $h;
}

/** Style commun des pages de sondage. */
function sondage_css(): string
{
    return <<<'CSS'
<style>
    .sd-wrap { max-width: 860px; margin: 1.5rem auto; }
    .sd-card { border-radius: 18px; padding: 1.2rem 1.4rem; margin-bottom: 1.2rem; }
    .sd-q { color: var(--white); font-size: 1.12rem; font-weight: 800; margin: 0 0 0.3rem; line-height: 1.35; }
    .sd-meta { color: var(--text-muted); font-size: 0.8rem; display: flex; flex-wrap: wrap; gap: 0.35rem 0.8rem; margin-bottom: 0.6rem; }
    .sd-desc { color: var(--white); font-size: 0.92rem; line-height: 1.5; margin: 0.3rem 0 0.8rem; white-space: pre-line; }
    .sd-badge { display: inline-block; font-size: 0.72rem; font-weight: 700; padding: 0.15rem 0.6rem; border-radius: 999px; border: 1px solid var(--glass-border); color: var(--text-muted); }
    .sd-badge.ok { color: #7bd8a6; border-color: rgba(37,211,102,0.4); background: rgba(37,211,102,0.1); }
    .sd-badge.gold { color: var(--gold); border-color: rgba(212,175,55,0.45); background: rgba(212,175,55,0.1); }
    .sd-badge.off { color: #fca5a5; border-color: rgba(191,33,33,0.4); background: rgba(191,33,33,0.1); }
    .sd-opt { display: flex; align-items: center; gap: 0.7rem; padding: 0.8rem 0.9rem; margin-bottom: 0.5rem; border: 1px solid var(--glass-border); border-radius: 14px; background: rgba(255,255,255,0.03); cursor: pointer; color: var(--white); font-size: 0.95rem; min-height: 52px; transition: border-color .15s, background .15s; }
    .sd-opt:hover { border-color: var(--accent); }
    .sd-opt:has(input:checked) { border-color: var(--accent); background: rgba(212,175,55,0.1); }
    .sd-opt input[type=radio] { width: 22px; height: 22px; accent-color: var(--accent); flex: 0 0 auto; margin: 0; }
    .sd-opt input[type=checkbox] { flex: 0 0 auto; margin: 0; }
    .sd-res-row { margin-bottom: 0.7rem; }
    .sd-res-top { display: flex; justify-content: space-between; gap: 0.6rem; color: var(--white); font-size: 0.92rem; margin-bottom: 0.25rem; }
    .sd-res-nb { color: var(--text-muted); font-weight: 700; white-space: nowrap; }
    .sd-bar { height: 12px; border-radius: 999px; background: rgba(255,255,255,0.08); overflow: hidden; }
    .sd-bar-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, rgba(212,175,55,0.55), var(--accent)); transition: width .5s; }
    .sd-top .sd-res-lib { color: var(--gold); font-weight: 800; }
    .sd-res-total { color: var(--text-muted); font-size: 0.82rem; margin-top: 0.3rem; }
    .sd-note { color: var(--text-muted); font-size: 0.85rem; padding: 0.7rem 0.9rem; border: 1px dashed var(--glass-border); border-radius: 12px; }
    .sd-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.9rem; }
    .sd-votants { margin-top: 0.7rem; }
    .sd-votants summary { cursor: pointer; color: var(--text-muted); font-size: 0.85rem; }
    .sd-votants p { margin: 0.35rem 0 0; font-size: 0.85rem; color: var(--white); }
    .sd-grp > summary { cursor: pointer; list-style: none; font-weight: 800; color: var(--white); font-size: 1.05rem; padding: 0.2rem 0; display: flex; align-items: center; gap: 0.5rem; }
    .sd-grp > summary::-webkit-details-marker { display: none; }
    .sd-grp { margin-bottom: 1.1rem; }
    @media (max-width: 600px) { .sd-card { padding: 1rem; } .sd-q { font-size: 1.05rem; } }
</style>
CSS;
}
