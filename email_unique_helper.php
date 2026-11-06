<?php
/**
 * Touba Lyon 2026 - Unicité de l'adresse e-mail entre tous les types de comptes.
 *
 * Une adresse e-mail ne peut servir qu'à un seul compte, quel que soit son type
 * (membre, administrateur, intégrateur). Les formulaires d'inscription contrôlent
 * déjà les membres (avec le détail du statut) ; cette fonction complète le contrôle
 * avec les autres tables de comptes.
 */

/** Message affiché quand l'adresse appartient déjà à un autre compte. */
function email_autre_compte_message(): string
{
    return "Cette adresse email est déjà utilisée par un autre compte. Chaque adresse ne peut servir qu'à un seul compte : utilisez une autre adresse, ou « Mot de passe oublié » si c'est la vôtre.";
}

/**
 * L'adresse appartient-elle à un compte administrateur ou intégrateur ?
 * Retourne 'admin', 'integrateur', ou null si l'adresse est libre (hors membres).
 */
function email_autre_compte(PDO $pdo, string $email): ?string
{
    $email = trim($email);
    if ($email === '') { return null; }
    $tables = ['admin' => 'admins', 'integrateur' => 'integrateurs'];
    foreach ($tables as $type => $table) {
        try {
            $st = $pdo->prepare("SELECT 1 FROM $table WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $st->execute([$email]);
            if ($st->fetchColumn() !== false) { return $type; }
        } catch (Exception $e) { /* table absente : on ignore */ }
    }
    return null;
}
