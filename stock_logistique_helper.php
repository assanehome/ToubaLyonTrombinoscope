<?php
/**
 * Touba Lyon 2026 - Stock (sorties, inventaire) et demandes de logistique : utilitaires communs.
 */
require_once __DIR__ . '/dahira_emails.php';        // send_smtp_mail(), dahira_email_wrap(), dahira_base_url()
require_once __DIR__ . '/notification_helper.php';  // troba_notify_membre()

/** États d'un article : clé => libellé. */
function stock_etats(): array
{
    return ['bon' => 'Bon état', 'a_reparer' => 'À réparer', 'endommage' => 'Endommagé'];
}

function stock_etat_badge(string $etat): string
{
    $lib = stock_etats()[$etat] ?? stock_etats()['bon'];
    $style = $etat === 'endommage' ? 'background:rgba(220,80,80,0.16); color:#ff9a9a; border:1px solid rgba(220,80,80,0.45);'
        : ($etat === 'a_reparer' ? 'background:rgba(212,175,55,0.16); color:#ffd873; border:1px solid rgba(212,175,55,0.45);'
        : 'background:rgba(45,106,79,0.22); color:#7bd8a6; border:1px solid rgba(45,106,79,0.55);');
    return '<span class="badge" style="' . $style . '">' . htmlspecialchars($lib) . '</span>';
}

/** Membres (id, prénom, nom, e-mail) d'une commission : membres + responsables. */
function stock_membres_commission(PDO $pdo, int $cid): array
{
    try {
        $st = $pdo->prepare("SELECT DISTINCT m.id, m.prenom, m.nom, m.email FROM membres m
                             WHERE m.status = 'approved' AND m.id IN (SELECT membre_id FROM commission_membres WHERE commission_id = ?
                                                                       UNION SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?)
                             ORDER BY m.nom ASC, m.prenom ASC");
        $st->execute([$cid, $cid]);
        return $st->fetchAll();
    } catch (Exception $e) { return []; }
}

/** Responsables d'une commission (id, prénom, nom, e-mail). */
function stock_responsables_commission(PDO $pdo, int $cid): array
{
    try {
        $st = $pdo->prepare("SELECT m.id, m.prenom, m.nom, m.email FROM commission_gestionnaires cg JOIN membres m ON m.id = cg.membre_id
                             WHERE cg.commission_id = ? AND m.status = 'approved'");
        $st->execute([$cid]);
        return $st->fetchAll();
    } catch (Exception $e) { return []; }
}

/** Envoie un e-mail habillé (best-effort) à une liste de destinataires [email => nom] sans doublon. */
function sl_envoyer(array $destinataires, string $sujet, string $inner, string $badge = ''): int
{
    $html = dahira_email_wrap($inner, $badge);
    $n = 0;
    foreach ($destinataires as $email => $nom) {
        $email = trim((string) $email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
        try { if (@send_smtp_mail($email, (string) $nom, $sujet, $html)) { $n++; } } catch (Throwable $e) { error_log('Touba Lyon e-mail : ' . $e->getMessage()); }
    }
    return $n;
}

/** Ajoute un destinataire [email => nom] au tableau (sans doublon, e-mail non vide). */
function sl_dest(array &$tab, ?string $email, string $nom = ''): void
{
    $email = strtolower(trim((string) $email));
    if ($email !== '' && !isset($tab[$email])) { $tab[$email] = $nom; }
}

/** Tableau HTML simple pour les e-mails : $entetes = [..], $lignes = [[..], ..] (valeurs déjà échappées si $brut). */
function sl_table_html(array $entetes, array $lignes): string
{
    $h = '<table style="border-collapse:collapse;width:100%;font-size:14px;margin:10px 0;"><thead><tr>';
    foreach ($entetes as $e) { $h .= '<th style="text-align:left;padding:6px 10px;border:1px solid #e0e0e0;background:#f6f3e8;">' . htmlspecialchars($e) . '</th>'; }
    $h .= '</tr></thead><tbody>';
    foreach ($lignes as $l) {
        $h .= '<tr>';
        foreach ($l as $c) { $h .= '<td style="padding:6px 10px;border:1px solid #e0e0e0;">' . $c . '</td>'; }
        $h .= '</tr>';
    }
    return $h . '</tbody></table>';
}

/** Id de la commission « Logistique » (0 si absente). */
function logistique_commission_id(PDO $pdo): int
{
    try {
        $st = $pdo->query("SELECT id FROM commissions WHERE LOWER(nom) LIKE '%logisti%' ORDER BY id ASC LIMIT 1");
        return (int) $st->fetchColumn();
    } catch (Exception $e) { return 0; }
}

/** Le membre est-il responsable de la commission Logistique ? */
function logistique_est_responsable(PDO $pdo, int $membreId): bool
{
    $cid = logistique_commission_id($pdo);
    if ($cid <= 0 || $membreId <= 0) { return false; }
    try {
        $st = $pdo->prepare("SELECT 1 FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ? LIMIT 1");
        $st->execute([$cid, $membreId]);
        return $st->fetchColumn() !== false;
    } catch (Exception $e) { return false; }
}

function logistique_types(): array { return ['materiel' => '📦 Matériel', 'personnes' => '👥 Personnes']; }
function logistique_vehicules(): array { return ['camion' => '🚚 Camion', 'voiture' => '🚗 Voiture normale']; }
function logistique_departs(): array { return ['KST' => 'KST (Keur Serigne Touba)', 'Box Bron' => 'Box Bron', 'autre' => 'Autre adresse']; }
function logistique_statuts(): array { return ['nouvelle' => '🆕 Nouvelle', 'prise_en_compte' => '👍 Prise en compte', 'executee' => '✅ Exécutée', 'terminee' => '✅ Terminée', 'refusee' => '🚫 Refusée']; }
