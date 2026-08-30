<?php
/**
 * Touba Lyon 2026 - Accès à la gestion des Kurels (modèle « commission complet »).
 *
 * Deux niveaux :
 *  - « Admin Kurels » : responsable de la commission « Kurels » (ou administrateur).
 *    → peut CRÉER des Kurels, gérer leurs membres ET désigner les responsables de chaque Kurel.
 *  - « Responsable d'un Kurel » : figure dans kourel_gestionnaires pour ce Kurel.
 *    → peut gérer les MEMBRES de ses Kurels.
 */
if (!function_exists('member_is_kourel_admin')) {
    /** Responsable de la commission « Kurels » (droit de créer/gérer tous les Kurels). */
    function member_is_kourel_admin($pdo, $memberId)
    {
        $memberId = (int) $memberId;
        if ($memberId <= 0) {
            return false;
        }
        try {
            $cnt = (int) $pdo->query(
                "SELECT COUNT(*) FROM commission_gestionnaires cg
                 JOIN commissions c ON c.id = cg.commission_id
                 WHERE cg.membre_id = $memberId AND LOWER(c.nom) LIKE '%kurel%'"
            )->fetchColumn();
            return $cnt > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('member_managed_kourels')) {
    /** Liste des ids de Kurels dont le membre est responsable. */
    function member_managed_kourels($pdo, $memberId)
    {
        $memberId = (int) $memberId;
        if ($memberId <= 0) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("SELECT kourel_id FROM kourel_gestionnaires WHERE membre_id = ?");
            $stmt->execute([$memberId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('member_is_kourel_manager')) {
    /** Le membre a-t-il un accès quelconque à l'espace Kurels ? (pour le menu) */
    function member_is_kourel_manager($pdo, $memberId)
    {
        return member_is_kourel_admin($pdo, $memberId) || !empty(member_managed_kourels($pdo, $memberId));
    }
}
