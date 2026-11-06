<?php
/**
 * Touba Lyon 2026 - Wadial Magal : accès et utilitaires.
 *
 * Le Wadial Magal est préparé chaque année par plusieurs commissions :
 * liste des commissions impliquées, besoins et tâches de chacune, et gestion
 * financière (recettes / dépenses). Ce fichier regroupe les règles d'accès,
 * partagées par la page magal.php et les menus.
 */

/** Première édition gérée dans l'application : la liste des années commence ici. */
const MAGAL_PREMIERE_ANNEE = 2027;

/** Édition proposée par défaut : l'année en cours, mais jamais avant la première édition. */
function magal_annee_defaut(): int
{
    return max(MAGAL_PREMIERE_ANNEE, (int) date('Y'));
}

/**
 * Le membre est-il « organisateur » du Wadial Magal ? Oui s'il est responsable
 * d'une commission Secrétariat, Finance / Trésorerie ou Magal.
 */
function magal_est_organisateur(PDO $pdo, int $membreId): bool
{
    if ($membreId <= 0) { return false; }
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM commission_gestionnaires cg JOIN commissions c ON c.id = cg.commission_id
             WHERE cg.membre_id = ? AND (LOWER(c.nom) LIKE '%secrétar%' OR LOWER(c.nom) LIKE '%secretar%'
                OR LOWER(c.nom) LIKE '%finance%' OR LOWER(c.nom) LIKE '%trésor%' OR LOWER(c.nom) LIKE '%tresor%'
                OR LOWER(c.nom) LIKE '%magal%')"
        );
        $st->execute([$membreId]);
        return ((int) $st->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/** Le membre est-il responsable de la commission Finance / Trésorerie ? */
function magal_est_finance(PDO $pdo, int $membreId): bool
{
    if ($membreId <= 0) { return false; }
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM commission_gestionnaires cg JOIN commissions c ON c.id = cg.commission_id
             WHERE cg.membre_id = ? AND (LOWER(c.nom) LIKE '%finance%' OR LOWER(c.nom) LIKE '%trésor%' OR LOWER(c.nom) LIKE '%tresor%')"
        );
        $st->execute([$membreId]);
        return ((int) $st->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/** Le membre appartient-il (membre ou responsable) à une commission impliquée dans un Wadial Magal ? */
function magal_membre_implique(PDO $pdo, int $membreId): bool
{
    if ($membreId <= 0) { return false; }
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM magal_commissions mc
             WHERE mc.commission_id IN (SELECT commission_id FROM commission_membres WHERE membre_id = ?
                                        UNION SELECT commission_id FROM commission_gestionnaires WHERE membre_id = ?)"
        );
        $st->execute([$membreId, $membreId]);
        return ((int) $st->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/** Accès à la page Wadial Magal pour un membre (hors administrateur). */
function magal_membre_a_acces(PDO $pdo, int $membreId): bool
{
    return magal_est_organisateur($pdo, $membreId) || magal_membre_implique($pdo, $membreId);
}

/** Libellés des statuts. */
function magal_statuts_besoin(): array
{
    return ['a_trouver' => 'À trouver', 'en_cours' => 'En cours', 'obtenu' => 'Obtenu'];
}

function magal_statuts_tache(): array
{
    return ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'fait' => 'Fait'];
}
