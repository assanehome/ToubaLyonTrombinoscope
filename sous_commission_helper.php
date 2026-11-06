<?php
/**
 * Touba Lyon 2026 - Règles des sous-commissions.
 *
 * Un membre d'une commission ne peut appartenir qu'à UNE seule sous-commission de cette commission
 * (les membres supports, extérieurs à la commission, ne sont pas concernés).
 */

/**
 * Nom de l'AUTRE sous-commission de la même commission dont le membre fait déjà partie
 * (hors membres supports), ou null s'il n'est dans aucune autre.
 */
function sc_autre_sous_commission(PDO $pdo, int $commissionId, int $scId, int $membreId): ?string
{
    try {
        $st = $pdo->prepare("SELECT sc.nom FROM sous_commission_membres scm
                             JOIN sous_commissions sc ON sc.id = scm.sous_commission_id
                             WHERE sc.commission_id = ? AND sc.id <> ? AND scm.membre_id = ? AND scm.support = 0 LIMIT 1");
        $st->execute([$commissionId, $scId, $membreId]);
        $nom = $st->fetchColumn();
        return $nom === false ? null : (string) $nom;
    } catch (Exception $e) {
        return null;
    }
}

/** Message d'erreur standard. */
function sc_message_deja_dans(string $nomSousCommission): string
{
    return "Ce membre fait déjà partie de la sous-commission « " . $nomSousCommission . " » : un membre d'une commission ne peut être que dans une seule sous-commission.";
}
