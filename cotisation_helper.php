<?php
/**
 * Touba Lyon 2026 - Cotisations mensuelles
 *
 * Gestion des cotisations des membres : mode par membre (mensuel via Stripe,
 * virement automatique, exempt), paiement par Stripe Checkout (page hébergée),
 * alertes et notifications de début de mois, remerciement après paiement.
 */
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_param(), dahira_set_param()
require_once __DIR__ . '/notification_helper.php';     // troba_notify_membre()
require_once __DIR__ . '/fonction_responsables_helper.php'; // responsables des cotisations (fonction « cotisation »)
require_once __DIR__ . '/dahira_emails.php';           // dahira_base_url(), dahira_email_wrap(), send_smtp_mail()

/** Montant de la cotisation mensuelle par défaut (euros), tant qu'aucun montant n'est défini pour une année donnée. */
function cotisation_montant(PDO $pdo): float
{
    $v = dahira_param($pdo, 'cotisation_montant', '30');
    $v = (float) str_replace(',', '.', $v);
    return $v > 0 ? $v : 30.0;
}

/** Montant de la cotisation pour une année donnée, ou le montant par défaut si non défini pour cette année. */
function cotisation_montant_annee(PDO $pdo, int $annee): float
{
    $v = trim(dahira_param($pdo, 'cotisation_montant_' . $annee, ''));
    if ($v !== '') {
        $f = (float) str_replace(',', '.', $v);
        if ($f > 0) { return $f; }
    }
    return cotisation_montant($pdo);
}

/** Montant applicable à un mois donné (AAAA-MM) : celui défini pour son année. */
function cotisation_montant_periode(PDO $pdo, string $periode): float
{
    return cotisation_montant_annee($pdo, (int) substr($periode, 0, 4));
}

/**
 * Relevé d'identité bancaire du Dahira, proposé aux membres pour mettre en place un virement automatique.
 * Valeurs par défaut ci-dessous ; chacune peut être remplacée par un réglage « cotisation_rib_<clé> ».
 */
function cotisation_rib(PDO $pdo): array
{
    $defauts = [
        'titulaire' => 'MUBAWWA SIDQ',
        'adresse' => "60 R F ET IRENE JOLIOT CURIE\n69100 VILLEURBANNE",
        'banque' => "SG LYON GARIBALDI (01354)\n100 CRS GAMBETTA\n69007 LYON",
        'code_banque' => '30003',
        'code_guichet' => '01354',
        'numero_compte' => '00036819973',
        'cle_rib' => '04',
        'iban' => 'FR76 3000 3013 5400 0368 1997 304',
        'bic' => 'SOGEFRPP',
    ];
    $rib = [];
    foreach ($defauts as $k => $v) {
        $r = trim((string) dahira_param($pdo, 'cotisation_rib_' . $k, ''));
        $rib[$k] = $r !== '' ? $r : $v;
    }
    return $rib;
}

/** Capacités mensuelles déclarées par les membres [membre_id => euros], chargées une fois par requête. */
function cotisation_capacites(PDO $pdo, bool $recharger = false): array
{
    static $cache = null;
    if ($cache === null || $recharger) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT membre_id, montant_mensuel FROM cotisation_config WHERE montant_mensuel IS NOT NULL") as $r) {
                $v = (float) $r['montant_mensuel'];
                if ($v > 0) { $cache[(int) $r['membre_id']] = $v; }
            }
        } catch (Exception $e) { $cache = []; }
    }
    return $cache;
}

/** Membres ayant validé leur capacité [membre_id => true] (capacité enregistrée, ou validation du tarif standard). */
function cotisation_capacites_validees(PDO $pdo, bool $recharger = false): array
{
    static $cache = null;
    if ($cache === null || $recharger) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT membre_id FROM cotisation_config WHERE capacite_validee_le IS NOT NULL OR montant_mensuel IS NOT NULL") as $r) { $cache[(int) $r['membre_id']] = true; }
        } catch (Exception $e) { $cache = []; }
    }
    return $cache;
}

/** Le membre a-t-il validé sa capacité mensuelle ? Sinon une alerte lui est affichée. */
function cotisation_capacite_validee(PDO $pdo, int $membreId): bool
{
    $v = cotisation_capacites_validees($pdo);
    return !empty($v[$membreId]);
}

/** Capacité mensuelle déclarée par un membre (euros), ou null s'il suit le tarif standard. */
function cotisation_capacite(PDO $pdo, int $membreId): ?float
{
    $c = cotisation_capacites($pdo);
    return $c[$membreId] ?? null;
}

/** Définit (1 à 1000 €) ou efface (null = tarif standard de la Finance) la capacité mensuelle d'un membre ; cela vaut validation. */
function cotisation_set_capacite(PDO $pdo, int $membreId, ?float $montant): bool
{
    if ($montant !== null && ($montant < 1 || $montant > 1000)) { return false; }
    try {
        $pdo->prepare("INSERT INTO cotisation_config (membre_id, mode, montant_mensuel, capacite_validee_le, updated_at) VALUES (?, 'mensuel', ?, NOW(), NOW())
                       ON DUPLICATE KEY UPDATE montant_mensuel = VALUES(montant_mensuel), capacite_validee_le = NOW(), updated_at = NOW()")
            ->execute([$membreId, $montant]);
        cotisation_capacites($pdo, true);
        cotisation_capacites_validees($pdo, true);
        return true;
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation capacité : ' . $e->getMessage());
        return false;
    }
}

/** Montant dû par un membre pour une période : sa capacité mensuelle s'il en a déclaré une, sinon le tarif de l'année. */
function cotisation_montant_membre(PDO $pdo, int $membreId, string $periode): float
{
    $c = cotisation_capacite($pdo, $membreId);
    return $c !== null ? $c : cotisation_montant_periode($pdo, $periode);
}

/** Période courante au format AAAA-MM. */
function cotisation_periode_courante(): string
{
    return date('Y-m');
}

/** Libellé lisible d'une période : « septembre 2026 ». */
function cotisation_libelle_mois(string $periode): string
{
    $ts = strtotime($periode . '-01');
    if ($ts === false) { return $periode; }
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return ($mois[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
}

/** Mode de cotisation d'un membre : 'mensuel' | 'virement' | 'exempt'. */
function cotisation_mode(PDO $pdo, int $membreId): string
{
    try {
        $st = $pdo->prepare("SELECT mode FROM cotisation_config WHERE membre_id = ?");
        $st->execute([$membreId]);
        $m = $st->fetchColumn();
        if ($m !== false && in_array($m, ['mensuel', 'virement', 'exempt'], true)) { return (string) $m; }
    } catch (Exception $e) { /* défaut ci-dessous */ }
    return 'mensuel';
}

/**
 * Date de référence (AAAA-MM) du passage d'un membre en virement automatique,
 * ou null si non définie (réglage ancien, ou membre non concerné). Le virement
 * ne couvre que les mois strictement postérieurs à cette référence : les mois
 * déjà écoulés au moment du changement restent à régulariser normalement.
 */
function cotisation_virement_ref(PDO $pdo, int $membreId): ?string
{
    try {
        $st = $pdo->prepare("SELECT virement_depuis FROM cotisation_config WHERE membre_id = ?");
        $st->execute([$membreId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null && preg_match('/^\d{4}-\d{2}$/', (string) $v)) ? (string) $v : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Le virement automatique d'un membre couvre-t-il la période donnée ? Vrai si
 * aucune référence n'est enregistrée (ancien réglage, comportement historique :
 * toujours couvert) ou si la période est postérieure à la référence.
 */
function cotisation_virement_couvre(PDO $pdo, int $membreId, string $periode): bool
{
    $ref = cotisation_virement_ref($pdo, $membreId);
    return $ref === null || $periode > $ref;
}

/**
 * Change le mode de cotisation d'un membre. En passant en virement automatique,
 * enregistre le mois courant comme date de référence : le virement ne couvrira
 * que les mois à venir, jamais rétroactivement. En quittant le virement, la
 * référence est effacée.
 */
function cotisation_set_mode(PDO $pdo, int $membreId, string $mode, ?int $jour = null, ?string $depuis = null): void
{
    if (!in_array($mode, ['mensuel', 'virement', 'exempt'], true)) { $mode = 'mensuel'; }
    // Référence de couverture : par défaut le mois courant (le virement ne couvre que les mois suivants) ;
    // avec une date de début, le virement couvre dès le mois de cette date (référence = mois précédent)
    $debut = null;
    if ($mode === 'virement' && $depuis !== null) {
        $dd = DateTime::createFromFormat('Y-m-d', $depuis);
        if ($dd && $dd->format('Y-m-d') === $depuis) { $debut = $depuis; }
    }
    if ($mode === 'virement') {
        $ref = $debut !== null ? date('Y-m', strtotime(substr($debut, 0, 7) . '-01 -1 month')) : cotisation_periode_courante();
    } else {
        $ref = null;
    }
    // Jour du mois prévu pour le virement automatique (1-31), seulement en mode virement
    $jourVal = ($mode === 'virement' && $jour !== null && $jour >= 1 && $jour <= 31) ? $jour : null;
    $pdo->prepare("INSERT INTO cotisation_config (membre_id, mode, virement_depuis, virement_jour, virement_debut, updated_at) VALUES (?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE mode = VALUES(mode), virement_depuis = VALUES(virement_depuis), virement_jour = VALUES(virement_jour), virement_debut = VALUES(virement_debut), virement_decl_jour = NULL, virement_decl_le = NULL, virement_decl_date = NULL, updated_at = NOW()")
        ->execute([$membreId, $mode, $ref, $jourVal, $debut]);
    if ($mode === 'virement') {
        // virement (ré)activé : plus de signalement « non reçu »
        try { $pdo->prepare("UPDATE cotisation_config SET virement_non_recu_le = NULL, virement_non_recu_periode = NULL WHERE membre_id = ?")->execute([$membreId]); } catch (Exception $e) { /* sans effet */ }
    }
}

/** Déclaration de virement automatique en attente d'un membre : ['jour' => 1..31, 'le' => date de déclaration, 'date' => date de début] ou null. */
function cotisation_virement_declaration(PDO $pdo, int $membreId): ?array
{
    try {
        $st = $pdo->prepare("SELECT virement_decl_jour, virement_decl_le, virement_decl_date FROM cotisation_config WHERE membre_id = ? AND mode <> 'virement'");
        $st->execute([$membreId]);
        $r = $st->fetch();
        if ($r && $r['virement_decl_jour'] !== null && (int) $r['virement_decl_jour'] >= 1 && (int) $r['virement_decl_jour'] <= 31) {
            return ['jour' => (int) $r['virement_decl_jour'], 'le' => (string) $r['virement_decl_le'], 'date' => (string) ($r['virement_decl_date'] ?? '')];
        }
    } catch (Exception $e) { /* aucune déclaration */ }
    return null;
}

/**
 * Le membre déclare à la Finance qu'il a mis en place un virement automatique. Le jour du mois (1 à 31) est
 * déduit de la date de début choisie (obligatoire) : la Finance est prévenue ce jour-là chaque mois. Réservé aux membres en mode mensuel.
 */
function cotisation_virement_declarer(PDO $pdo, int $membreId, string $dateDebut): bool
{
    $dd = DateTime::createFromFormat('Y-m-d', $dateDebut);
    if (!$dd || $dd->format('Y-m-d') !== $dateDebut || cotisation_mode($pdo, $membreId) !== 'mensuel') { return false; }
    $jour = (int) $dd->format('j'); // le jour de cette date est repris chaque mois pour prévenir la Finance
    try {
        $pdo->prepare("INSERT INTO cotisation_config (membre_id, mode, virement_decl_jour, virement_decl_le, virement_decl_date, updated_at) VALUES (?, 'mensuel', ?, NOW(), ?, NOW())
                       ON DUPLICATE KEY UPDATE virement_decl_jour = VALUES(virement_decl_jour), virement_decl_le = NOW(), virement_decl_date = VALUES(virement_decl_date), virement_non_recu_le = NULL, virement_non_recu_periode = NULL, updated_at = NOW()")
            ->execute([$membreId, $jour, $dateDebut]);
        $st = $pdo->prepare("SELECT TRIM(CONCAT(prenom, ' ', nom)) FROM membres WHERE id = ?");
        $st->execute([$membreId]);
        $nom = (string) $st->fetchColumn();
        $fins = cotisation_responsables_ids($pdo);
        foreach ($fins as $fid) {
            troba_notify_membre($pdo, (int) $fid, 'cotisation', '🏦 Virement automatique déclaré', $nom . ' déclare un virement automatique à partir du ' . $dd->format('d/m/Y') . ' (le ' . $jour . ' de chaque mois, ' . number_format(cotisation_montant_membre($pdo, $membreId, cotisation_periode_courante()), 2, ',', ' ') . ' €).', 'admin_cotisations.php');
        }
        return true;
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation virement_declarer : ' . $e->getMessage());
        return false;
    }
}

/** Le membre retire sa déclaration de virement automatique (encore en attente). */
function cotisation_virement_declaration_annuler(PDO $pdo, int $membreId): void
{
    try {
        $pdo->prepare("UPDATE cotisation_config SET virement_decl_jour = NULL, virement_decl_le = NULL, updated_at = NOW() WHERE membre_id = ? AND mode <> 'virement'")->execute([$membreId]);
    } catch (Exception $e) { /* sans effet */ }
}

/** Déclarations de virement automatique en attente (pour la Finance) : membre_id, prenom, nom, jour, le, montant. */
function cotisation_virement_declarations(PDO $pdo): array
{
    $out = [];
    try {
        $rows = $pdo->query("SELECT c.membre_id, c.virement_decl_jour AS jour, c.virement_decl_le AS le, c.virement_decl_date AS ddate, m.prenom, m.nom
                             FROM cotisation_config c JOIN membres m ON m.id = c.membre_id
                             WHERE c.virement_decl_jour IS NOT NULL AND c.mode <> 'virement' AND m.status = 'approved'
                             ORDER BY c.virement_decl_le ASC")->fetchAll();
        foreach ($rows as $r) {
            $mid = (int) $r['membre_id'];
            $out[] = ['id' => $mid, 'prenom' => (string) $r['prenom'], 'nom' => (string) $r['nom'], 'jour' => (int) $r['jour'], 'le' => (string) $r['le'], 'date' => (string) ($r['ddate'] ?? ''),
                      'montant' => cotisation_montant_membre($pdo, $mid, cotisation_periode_courante())];
        }
    } catch (Exception $e) { return []; }
    return $out;
}

/** La Finance valide la déclaration : le membre passe en virement automatique, au jour indiqué. */
function cotisation_virement_decl_valider(PDO $pdo, int $membreId, ?string $dateChoisie = null): bool
{
    $d = cotisation_virement_declaration($pdo, $membreId);
    if ($d === null) { return false; }
    // Date de mise en place choisie par la Finance (à défaut : celle déclarée par le membre) ; le jour du mois en découle
    if ($dateChoisie !== null && $dateChoisie !== '') {
        $dd = DateTime::createFromFormat('Y-m-d', $dateChoisie);
        if (!$dd || $dd->format('Y-m-d') !== $dateChoisie) { return false; }
        $d['date'] = $dateChoisie;
        $d['jour'] = (int) $dd->format('j');
    }
    cotisation_set_mode($pdo, $membreId, 'virement', $d['jour'], $d['date'] !== '' ? $d['date'] : null);
    troba_notify_membre($pdo, $membreId, 'cotisation', '🏦 Virement automatique activé', 'La Finance a activé votre virement automatique' . ($d['date'] !== '' ? ' à partir du ' . date('d/m/Y', strtotime($d['date'])) : '') . ' (le ' . $d['jour'] . ' de chaque mois). Merci !', 'cotisation.php');
    return true;
}

/** La Finance refuse la déclaration (motif facultatif) : le membre est prévenu et reste en cotisation mensuelle. */
function cotisation_virement_decl_refuser(PDO $pdo, int $membreId, string $motif = ''): bool
{
    if (cotisation_virement_declaration($pdo, $membreId) === null) { return false; }
    cotisation_virement_declaration_annuler($pdo, $membreId);
    troba_notify_membre($pdo, $membreId, 'cotisation', '🏦 Virement automatique non retenu', 'La Finance n\'a pas pu confirmer votre virement automatique' . ($motif !== '' ? ' : ' . mb_substr($motif, 0, 160) : '.') . ' Vous pouvez régler votre cotisation en ligne ou refaire une déclaration.', 'cotisation.php');
    return true;
}

/** Jour du mois (1-31) prévu pour le virement automatique d'un membre, ou null. */
function cotisation_virement_jour(PDO $pdo, int $membreId): ?int
{
    try {
        $st = $pdo->prepare("SELECT virement_jour FROM cotisation_config WHERE membre_id = ?");
        $st->execute([$membreId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null && (int) $v >= 1 && (int) $v <= 31) ? (int) $v : null;
    } catch (Exception $e) {
        return null;
    }
}

/** Modifie le jour du mois prévu pour le virement automatique (1-31). */
function cotisation_set_virement_jour(PDO $pdo, int $membreId, int $jour): bool
{
    if ($jour < 1 || $jour > 31 || cotisation_mode($pdo, $membreId) !== 'virement') { return false; }
    $pdo->prepare("UPDATE cotisation_config SET virement_jour = ?, updated_at = NOW() WHERE membre_id = ?")->execute([$jour, $membreId]);
    return true;
}

/**
 * Virements automatiques attendus pour une période : membres en mode virement
 * dont le virement couvre ce mois. Chaque ligne : id, prenom, nom, jour (ou null)
 * et recu (true si la Finance a confirmé la réception).
 */
function cotisation_virements_attendus(PDO $pdo, string $periode): array
{
    $out = [];
    try {
        $rows = $pdo->query("SELECT m.id, m.prenom, m.nom, c.virement_jour FROM cotisation_config c JOIN membres m ON m.id = c.membre_id WHERE c.mode = 'virement' AND m.status = 'approved' ORDER BY m.nom ASC, m.prenom ASC")->fetchAll();
    } catch (Exception $e) { return []; }
    foreach ($rows as $r) {
        $mid = (int) $r['id'];
        if (!cotisation_virement_couvre($pdo, $mid, $periode)) { continue; }
        $out[] = [
            'id' => $mid,
            'prenom' => (string) $r['prenom'],
            'nom' => (string) $r['nom'],
            'jour' => ($r['virement_jour'] !== null && (int) $r['virement_jour'] > 0) ? (int) $r['virement_jour'] : null,
            'recu' => cotisation_est_paye($pdo, $mid, $periode),
        ];
    }
    return $out;
}

/** La Finance confirme avoir bien reçu le virement automatique du mois. */
function cotisation_virement_confirmer(PDO $pdo, int $membreId, string $periode): bool
{
    if (cotisation_mode($pdo, $membreId) !== 'virement') { return false; }
    return cotisation_marquer_paye($pdo, $membreId, $periode, 'virement', '');
}

/**
 * La Finance annule la confirmation de réception d'un virement automatique (confirmation faite par erreur) :
 * le mois redevient « à confirmer ». Retire le pointage « payé par virement » de ce mois, rien d'autre.
 */
function cotisation_virement_annuler_reception(PDO $pdo, int $membreId, string $periode): bool
{
    if (cotisation_mode($pdo, $membreId) !== 'virement' || !cotisation_virement_couvre($pdo, $membreId, $periode)) { return false; }
    try {
        $st = $pdo->prepare("DELETE FROM cotisations WHERE membre_id = ? AND periode = ? AND statut = 'paye' AND methode = 'virement'");
        $st->execute([$membreId, $periode]);
        return $st->rowCount() > 0;
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation virement_annuler_reception : ' . $e->getMessage());
        return false;
    }
}

/**
 * Virement non reçu : le membre est prévenu (cloche + e-mail) et l'option
 * « virement automatique » est désactivée (retour en cotisation mensuelle).
 * Les mois déjà couverts par le virement sont d'abord enregistrés comme réglés,
 * afin de ne pas apparaître en retard après la désactivation.
 */
function cotisation_virement_non_recu(PDO $pdo, int $membreId, string $periode): bool
{
    if (cotisation_mode($pdo, $membreId) !== 'virement') { return false; }
    $ref = cotisation_virement_ref($pdo, $membreId);
    try {
        // Mois écoulés couverts par le virement, sans enregistrement : conservés comme réglés
        foreach (cotisation_mois_ecoules(cotisation_debut($pdo)) as $m) {
            if ($m === $periode) { continue; }
            if ($ref !== null && $m <= $ref) { continue; }
            if (cotisation_statut($pdo, $membreId, $m) !== null) { continue; }
            $pdo->prepare("INSERT IGNORE INTO cotisations (membre_id, periode, montant, statut, methode, paid_at) VALUES (?,?,?, 'paye', 'virement', NOW())")
                ->execute([$membreId, $m, cotisation_montant_membre($pdo, $membreId, $m)]);
        }
        // Le mois concerné est retiré d'un éventuel pointage : il reste dû
        $pdo->prepare("DELETE FROM cotisations WHERE membre_id = ? AND periode = ? AND statut <> 'paye'")->execute([$membreId, $periode]);
        cotisation_set_mode($pdo, $membreId, 'mensuel');
        // Le membre doit remettre le virement en place : le message de mise en place lui est réaffiché
        $pdo->prepare("UPDATE cotisation_config SET virement_non_recu_le = NOW(), virement_non_recu_periode = ? WHERE membre_id = ?")->execute([$periode, $membreId]);
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation virement_non_recu : ' . $e->getMessage());
        return false;
    }
    $libelle = cotisation_libelle_mois($periode);
    $montant = number_format(cotisation_montant_membre($pdo, $membreId, $periode), 2, ',', ' ');
    troba_notify_membre(
        $pdo,
        $membreId,
        'cotisation',
        '⚠️ Virement non reçu',
        'Nous n\'avons pas reçu votre virement de ' . $libelle . ' (' . $montant . ' €). Vous repassez en gestion mensuelle : réglez votre cotisation ou remettez le virement automatique en place (RIB et déclaration dans « Mes cotisations »).',
        'cotisation.php'
    );
    try {
        $st = $pdo->prepare("SELECT prenom, nom, email FROM membres WHERE id = ?");
        $st->execute([$membreId]);
        $m = $st->fetch();
        if ($m && trim((string) ($m['email'] ?? '')) !== '') {
            $nom = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
            $inner = '<h1 style="margin:0 0 18px;font-size:20px;color:#b91c1c;">Virement non reçu</h1>'
                . '<p style="margin:0 0 14px;">Àssalaamu haleykum ' . htmlspecialchars($nom) . ',</p>'
                . '<p style="margin:0 0 14px;">Nous n\'avons pas reçu votre virement automatique de <strong>' . htmlspecialchars($libelle) . '</strong> (' . htmlspecialchars($montant) . ' €).</p>'
                . '<p style="margin:0 0 14px;">Le virement automatique a donc été <strong>désactivé</strong> et vous repassez en <strong>gestion mensuelle</strong>. Merci de régler votre cotisation, ou de <strong>remettre le virement en place</strong> : le RIB du Dahira et la déclaration à la Finance sont à nouveau disponibles dans « Mes cotisations ». Contactez-nous si vous pensez qu\'il s\'agit d\'une erreur.</p>'
                . dahira_email_button(rtrim(dahira_base_url(), '/') . '/cotisation.php', 'Remettre en place le virement');
            @send_smtp_mail($m['email'], $nom, '⚠️ Virement non reçu — ' . $libelle, dahira_email_wrap($inner, 'Cotisation'));
        }
    } catch (Exception $e) { /* e-mail best-effort */ }
    return true;
}

/** Enregistrement de cotisation d'un membre pour une période (ou null). */
function cotisation_statut(PDO $pdo, int $membreId, string $periode): ?array
{
    try {
        $st = $pdo->prepare("SELECT * FROM cotisations WHERE membre_id = ? AND periode = ?");
        $st->execute([$membreId, $periode]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/** La cotisation du membre pour la période est-elle réglée ? */
function cotisation_est_paye(PDO $pdo, int $membreId, string $periode): bool
{
    $r = cotisation_statut($pdo, $membreId, $periode);
    return $r !== null && ($r['statut'] ?? '') === 'paye';
}

/** Clés Stripe configurées ? */
function cotisation_stripe_config(PDO $pdo): array
{
    return [
        'pk' => trim(dahira_param($pdo, 'stripe_pk', '')),
        'sk' => trim(dahira_param($pdo, 'stripe_sk', '')),
    ];
}
function cotisation_stripe_pret(PDO $pdo): bool
{
    $c = cotisation_stripe_config($pdo);
    return $c['pk'] !== '' && $c['sk'] !== '';
}

/**
 * Appel à l'API Stripe (form-urlencoded). Retourne ['ok' => bool, 'data' => …|'error' => …, 'http' => code, 'errno' => code cURL].
 * Plus tolérant sur un hébergement mutualisé : IPv4 forcé (IPv6 sortant souvent bloqué), nouvel essai sur une
 * erreur réseau passagère, autre bundle de certificats si celui de PHP est introuvable, et repli sans cURL.
 */
function cotisation_stripe_api(string $sk, string $method, string $path, array $params = []): array
{
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $post = strtoupper($method) === 'POST';
    $body = $post ? http_build_query($params) : '';

    if (!function_exists('curl_init')) {
        return cotisation_stripe_api_flux($sk, $url, $post, $body);
    }

    // Bundles de certificats usuels (essayés seulement si la vérification SSL échoue)
    $bundles = [];
    foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem', '/usr/local/share/certs/ca-root-nss.crt', __DIR__ . '/cacert.pem'] as $cb) {
        if (@is_readable($cb)) { $bundles[] = $cb; }
    }
    $resp = false; $errno = 0; $err = ''; $code = 0;
    $essai = 0; $bundleIdx = -1;
    while ($essai < 4) {
        $essai++;
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $sk],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (defined('CURL_IPRESOLVE_V4')) { $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4; }
        if ($bundleIdx >= 0 && isset($bundles[$bundleIdx])) { $opts[CURLOPT_CAINFO] = $bundles[$bundleIdx]; }
        if ($post) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno === 0) { break; }
        if (in_array($errno, [60, 77, 35, 58, 83], true) && $bundleIdx + 1 < count($bundles)) { $bundleIdx++; continue; } // certificat : autre bundle
        if (in_array($errno, [28, 7, 6, 56, 52, 55], true) && $essai < 3) { usleep(400000); continue; }                    // réseau : nouvel essai
        break;
    }
    if ($errno !== 0) {
        // Dernier recours : même appel sans cURL (flux PHP), si l'hébergeur l'autorise
        $flux = cotisation_stripe_api_flux($sk, $url, $post, $body);
        if (!empty($flux['ok']) || (isset($flux['http']) && $flux['http'] > 0)) { return $flux; }
        return ['ok' => false, 'error' => 'Connexion Stripe impossible (cURL ' . $errno . ') : ' . $err, 'http' => 0, 'errno' => $errno];
    }
    return cotisation_stripe_reponse((string) $resp, $code, 0);
}

/** Interprète la réponse HTTP de Stripe. */
function cotisation_stripe_reponse(string $resp, int $code, int $errno): array
{
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Réponse Stripe invalide (HTTP ' . $code . ').', 'http' => $code, 'errno' => $errno];
    }
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'data' => $data, 'http' => $code, 'errno' => $errno];
    }
    $msg = $data['error']['message'] ?? ('Erreur Stripe (HTTP ' . $code . ').');
    return ['ok' => false, 'error' => $msg, 'http' => $code, 'errno' => $errno];
}

/** Appel Stripe sans cURL (flux PHP : allow_url_fopen + OpenSSL). */
function cotisation_stripe_api_flux(string $sk, string $url, bool $post, string $body): array
{
    if (!ini_get('allow_url_fopen')) {
        return ['ok' => false, 'error' => 'Connexion Stripe impossible : ni cURL ni allow_url_fopen ne sont disponibles sur ce serveur.', 'http' => 0, 'errno' => -1];
    }
    $headers = "Authorization: Bearer " . $sk . "\r\n";
    if ($post) { $headers .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n"; }
    $ctx = stream_context_create(['http' => ['method' => $post ? 'POST' : 'GET', 'header' => $headers, 'content' => $post ? $body : '', 'timeout' => 25, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $e = error_get_last();
        return ['ok' => false, 'error' => 'Connexion Stripe impossible (flux PHP) : ' . ($e['message'] ?? 'échec réseau'), 'http' => 0, 'errno' => -2];
    }
    $code = 0;
    foreach (($http_response_header ?? []) as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int) $m[1]; } }
    return cotisation_stripe_reponse((string) $resp, $code, 0);
}

/**
 * Diagnostic de la connexion à Stripe (administrateur) : environnement, DNS et appel d'essai.
 * Retourne [ok => bool, lignes => string[]]. Ne révèle jamais la clé secrète.
 */
function cotisation_stripe_diagnostic(PDO $pdo): array
{
    $l = [];
    $l[] = 'PHP ' . PHP_VERSION;
    if (function_exists('curl_version')) { $cv = curl_version(); $l[] = 'cURL ' . ($cv['version'] ?? '?') . ' (' . ($cv['ssl_version'] ?? 'SSL ?') . ')'; }
    else { $l[] = 'cURL absent'; }
    $l[] = 'OpenSSL ' . (extension_loaded('openssl') ? 'oui' : 'non') . ', allow_url_fopen ' . (ini_get('allow_url_fopen') ? 'oui' : 'non');
    $ip = @gethostbyname('api.stripe.com');
    $l[] = 'DNS api.stripe.com : ' . (($ip !== '' && $ip !== 'api.stripe.com') ? $ip : 'échec de résolution');
    $conf = cotisation_stripe_config($pdo);
    $sk = $conf['sk'];
    $mode = $sk === '' ? 'aucune clé secrète' : (strpos($sk, '_live_') !== false ? 'clé réelle (live)' : (strpos($sk, '_test_') !== false ? 'clé de test' : 'format de clé inattendu'));
    $l[] = 'Clé secrète : ' . $mode . ($conf['pk'] === '' ? ' · clé publique absente' : '');
    $res = cotisation_stripe_api($sk, 'GET', 'balance');
    if (!empty($res['ok'])) {
        $l[] = 'Appel Stripe : réussi (HTTP ' . (int) ($res['http'] ?? 200) . ') — la clé est valide.';
        return ['ok' => true, 'lignes' => $l];
    }
    $http = (int) ($res['http'] ?? 0);
    if ($http > 0) {
        // Stripe répond : la connexion fonctionne, c'est la clé qui est refusée ou limitée
        $l[] = 'Connexion à Stripe : OK (HTTP ' . $http . ').';
        $l[] = 'Réponse de Stripe : ' . ($res['error'] ?? '');
        return ['ok' => false, 'lignes' => $l, 'connexion' => true];
    }
    $l[] = 'Connexion à Stripe : ÉCHEC — ' . ($res['error'] ?? 'erreur réseau');
    return ['ok' => false, 'lignes' => $l, 'connexion' => false];
}

/**
 * Crée une session Stripe Checkout pour la cotisation d'un membre.
 * Retourne ['url'=>..., 'error'=>...].
 */
function cotisation_creer_session(PDO $pdo, int $membreId, string $periode, string $emailMembre = ''): array
{
    $conf = cotisation_stripe_config($pdo);
    if ($conf['sk'] === '') {
        return ['url' => null, 'error' => 'Le paiement en ligne n\'est pas encore configuré.'];
    }
    $montant = cotisation_montant_membre($pdo, $membreId, $periode);
    $cents = (int) round($montant * 100);
    if ($cents < 100) {
        return ['url' => null, 'error' => 'Montant de cotisation invalide.'];
    }
    // Enregistre (ou récupère) la ligne de cotisation en attente
    try {
        $pdo->prepare("INSERT INTO cotisations (membre_id, periode, montant, statut, methode) VALUES (?,?,?, 'attente', 'stripe')
                       ON DUPLICATE KEY UPDATE montant = VALUES(montant), updated_at = NOW()")
            ->execute([$membreId, $periode, $montant]);
    } catch (Exception $e) { /* non bloquant */ }

    $base = dahira_base_url();
    $params = [
        'mode' => 'payment',
        'success_url' => $base . '/cotisation_retour.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $base . '/cotisation.php?annule=1',
        'client_reference_id' => $membreId . ':' . $periode,
        'line_items[0][quantity]' => '1',
        'line_items[0][price_data][currency]' => 'eur',
        'line_items[0][price_data][unit_amount]' => (string) $cents,
        'line_items[0][price_data][product_data][name]' => 'Cotisation ' . cotisation_libelle_mois($periode) . ' — Touba Lyon',
        'metadata[membre_id]' => (string) $membreId,
        'metadata[periode]' => $periode,
    ];
    if ($emailMembre !== '') { $params['customer_email'] = $emailMembre; }

    $res = cotisation_stripe_api($conf['sk'], 'POST', 'checkout/sessions', $params);
    if (!$res['ok']) {
        return ['url' => null, 'error' => $res['error']];
    }
    $sid = $res['data']['id'] ?? '';
    $url = $res['data']['url'] ?? '';
    if ($sid !== '') {
        try {
            $pdo->prepare("UPDATE cotisations SET stripe_session = ?, updated_at = NOW() WHERE membre_id = ? AND periode = ?")
                ->execute([$sid, $membreId, $periode]);
        } catch (Exception $e) {}
    }
    if ($url === '') {
        return ['url' => null, 'error' => 'Session de paiement non créée.'];
    }
    return ['url' => $url, 'error' => null];
}

/**
 * Marque une cotisation comme payée et envoie un remerciement (une seule fois).
 */
function cotisation_marquer_paye(PDO $pdo, int $membreId, string $periode, string $methode = 'stripe', string $ref = '', ?float $montantForce = null): bool
{
    $montant = ($montantForce !== null && $montantForce > 0) ? round($montantForce, 2) : cotisation_montant_membre($pdo, $membreId, $periode);
    try {
        // Déjà payée ? éviter double remerciement
        $deja = cotisation_est_paye($pdo, $membreId, $periode);
        $pdo->prepare("INSERT INTO cotisations (membre_id, periode, montant, statut, methode, stripe_session, paid_at)
                       VALUES (?,?,?, 'paye', ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE statut = 'paye', methode = VALUES(methode),
                           stripe_session = COALESCE(VALUES(stripe_session), stripe_session),
                           montant = VALUES(montant), paid_at = NOW(), updated_at = NOW()")
            ->execute([$membreId, $periode, $montant, $methode, ($ref !== '' ? $ref : null)]);
        if (!$deja) {
            // Notification de remerciement (une seule fois)
            troba_notify_membre(
                $pdo,
                $membreId,
                'cotisation',
                '🙏 Merci pour votre cotisation',
                'Votre cotisation de ' . cotisation_libelle_mois($periode) . ' (' . number_format($montant, 2, ',', ' ') . ' €) a bien été enregistrée. Jërëjëf !',
                'cotisation.php'
            );
        }
        return true;
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation marquer_paye : ' . $e->getMessage());
        return false;
    }
}

/**
 * Notifie, une fois par mois, les membres « mensuel » non encore à jour.
 * Best-effort, déclenché au chargement de l'accueil.
 */
function cotisation_check_mois(PDO $pdo): void
{
    $periode = cotisation_periode_courante();
    $flag = 'cotisation_notif_' . $periode;
    if (dahira_param($pdo, $flag, '') === '1') { return; }
    try {
        $montant = cotisation_montant_periode($pdo, $periode);
        $membres = $pdo->query("SELECT id FROM membres WHERE status = 'approved'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($membres as $mid) {
            $mid = (int) $mid;
            if (cotisation_mode($pdo, $mid) !== 'mensuel') { continue; }
            if (cotisation_est_paye($pdo, $mid, $periode)) { continue; }
            troba_notify_membre(
                $pdo,
                $mid,
                'cotisation',
                '💶 Cotisation du mois',
                'La cotisation de ' . cotisation_libelle_mois($periode) . ' (' . number_format(cotisation_montant_membre($pdo, $mid, $periode), 2, ',', ' ') . ' €) est ouverte. Vous pouvez la régler en ligne.',
                'cotisation.php'
            );
        }
        // Début de mois : les responsables Finance doivent confirmer les virements automatiques reçus
        $attendus = cotisation_virements_attendus($pdo, $periode);
        $aConfirmer = count(array_filter($attendus, function ($v) { return !$v['recu'] && $v['jour'] === null; }));
        if ($aConfirmer > 0) {
            $fins = cotisation_responsables_ids($pdo);
            foreach ($fins as $fid) {
                troba_notify_membre(
                    $pdo,
                    (int) $fid,
                    'cotisation',
                    '🏦 Virements automatiques à confirmer',
                    $aConfirmer . ' virement(s) automatique(s) de ' . cotisation_libelle_mois($periode) . ' sont à confirmer (bien reçu / non reçu).',
                    'admin_cotisations.php'
                );
            }
        }
        dahira_set_param($pdo, $flag, '1');
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation check_mois : ' . $e->getMessage());
    }
}

/**
 * Début de la saison de cotisation, au format AAAA-MM. Aucune cotisation n'est
 * due avant ce mois. Par défaut août 2026 (première saison). Réglable via le
 * paramètre « cotisation_debut ».
 */
function cotisation_debut(PDO $pdo): string
{
    $v = trim(dahira_param($pdo, 'cotisation_debut', '2026-08'));
    return preg_match('/^\d{4}-\d{2}$/', $v) ? $v : '2026-08';
}

/**
 * Mois de cotisation déjà écoulés (du début de saison au mois précédant le mois
 * courant, inclus). Sert au calcul du retard.
 */
function cotisation_mois_ecoules(string $debut): array
{
    $out = [];
    $courant = cotisation_periode_courante();
    $p = $debut;
    // Garde-fou : au plus 240 mois (20 ans)
    $garde = 0;
    while ($p < $courant && $garde < 240) {
        $out[] = $p;
        $p = date('Y-m', strtotime($p . '-01 +1 month'));
        $garde++;
    }
    return $out;
}

/**
 * Années de cotisation disponibles pour le sélecteur : de l'année de début de
 * saison jusqu'à l'année courante (la plus récente en premier).
 */
function cotisation_annees(PDO $pdo): array
{
    $debut = cotisation_debut($pdo);
    $y0 = (int) substr($debut, 0, 4);
    // Inclut plusieurs années à venir, pour préparer montants et suivi à l'avance
    $y1 = max($y0, (int) date('Y')) + 5;
    $out = [];
    for ($y = $y1; $y >= $y0; $y--) { $out[] = $y; }
    return $out;
}

/**
 * Message WhatsApp de rappel simple (sans détail chiffré), pour relancer un
 * membre sur sa cotisation.
 */
function cotisation_wa_rappel(string $prenom): string
{
    $prenom = trim($prenom);
    return "Assalamu aleykum" . ($prenom !== '' ? ' ' . $prenom : '') . ",\n\n"
        . "Petit rappel amical concernant votre cotisation au Dahira. N'hésitez pas à nous contacter en cas de besoin.\n\n"
        . "Jërëjëf 🤲\nCommission Finance, Touba Lyon";
}

/**
 * Récapitulatif texte de la situation de cotisation d'un membre : mois en
 * retard listés et total à régulariser (ou message de félicitations si à
 * jour). Sert au message WhatsApp détaillé et à pré-remplir l'e-mail de
 * relance (éditable avant envoi).
 */
function cotisation_recap_texte(PDO $pdo, int $membreId, string $prenom): string
{
    $montantCourant = cotisation_montant_membre($pdo, $membreId, cotisation_periode_courante());
    $moisRetard = cotisation_mois_retard($pdo, $membreId);
    $nb = count($moisRetard);
    $txt = "Assalamu aleykum" . ($prenom !== '' ? ' ' . trim($prenom) : '') . ",\n\n"
        . "Voici le récapitulatif de votre cotisation au Dahira Touba Lyon :\n"
        . "- Montant mensuel : " . number_format($montantCourant, 2, ',', ' ') . " €" . (cotisation_capacite($pdo, $membreId) !== null ? " (votre capacité déclarée)" : "") . "\n";
    if ($nb > 0) {
        $total = 0.0;
        $txt .= "- Mois en retard (" . $nb . ") :\n";
        foreach ($moisRetard as $m) {
            $mMontant = cotisation_montant_membre($pdo, $membreId, $m);
            $total += $mMontant;
            $txt .= "   • " . cotisation_libelle_mois($m) . " (" . number_format($mMontant, 2, ',', ' ') . " €)\n";
        }
        $txt .= "- Total à régulariser : " . number_format($total, 2, ',', ' ') . " €\n\n"
            . "Merci de régulariser votre situation dès que possible.\n\n";
    } else {
        $txt .= "- Situation à jour, aucun mois en retard. Jërëjëf pour votre régularité !\n\n";
    }
    $txt .= "Jërëjëf 🤲\nCommission Finance, Touba Lyon";
    return $txt;
}

/**
 * Bilan agrégé d'une année de cotisation entière : nombre de mois réglés, en
 * retard, en attente (mois courant) et total encaissé, sur tous les membres et
 * tous les mois de l'année déjà commencés (bornés au début de saison et au mois
 * courant). Les modes virement/exempt sont comptés par membre (pas par mois).
 */
function cotisation_bilan_annee(PDO $pdo, int $annee): array
{
    $bilan = ['payes' => 0, 'retard' => 0, 'attente' => 0, 'virement' => 0, 'virement_attente' => 0, 'exempt' => 0, 'total' => 0.0, 'membres' => 0];
    $debut = cotisation_debut($pdo);
    $moisCourant = cotisation_periode_courante();
    $moisAnnee = [];
    for ($m = 1; $m <= 12; $m++) {
        $per = sprintf('%04d-%02d', $annee, $m);
        if ($per >= $debut && $per <= $moisCourant) { $moisAnnee[] = $per; }
    }
    if (empty($moisAnnee)) { return $bilan; }
    try {
        $montant = cotisation_montant_annee($pdo, $annee);
        $membres = $pdo->query("SELECT id FROM membres WHERE status = 'approved'")->fetchAll(PDO::FETCH_COLUMN);
        $bilan['membres'] = count($membres);
        $modes = [];
        $virementRefs = [];
        foreach ($membres as $mid) {
            $mid = (int) $mid;
            $mode = cotisation_mode($pdo, $mid);
            $modes[$mid] = $mode;
            if ($mode === 'exempt') { $bilan['exempt']++; }
            elseif ($mode === 'virement') {
                $bilan['virement']++;
                $virementRefs[$mid] = cotisation_virement_ref($pdo, $mid);
            }
        }
        // Paiements réglés des mois de l'année : map [membre_id][periode] = montant
        $paye = [];
        $ph = implode(',', array_fill(0, count($moisAnnee), '?'));
        $st = $pdo->prepare("SELECT membre_id, periode, montant FROM cotisations WHERE statut = 'paye' AND periode IN ($ph)");
        $st->execute($moisAnnee);
        while ($r = $st->fetch()) { $paye[(int) $r['membre_id']][(string) $r['periode']] = (float) $r['montant']; }
        foreach ($moisAnnee as $per) {
            foreach ($modes as $mid => $mode) {
                if ($mode === 'exempt') { continue; }
                if ($mode === 'virement') {
                    $ref = $virementRefs[$mid] ?? null;
                    if ($ref === null || $per > $ref) {
                        // Virement automatique : compté seulement si la Finance a confirmé la réception
                        if (isset($paye[$mid][$per])) {
                            $bilan['payes']++;
                            $bilan['total'] += $paye[$mid][$per];
                        } else {
                            $bilan['virement_attente']++;
                        }
                        continue;
                    }
                    // Mois antérieur (ou égal) à la bascule en virement : traité comme un mois normal ci-dessous
                }
                if (isset($paye[$mid][$per])) {
                    $bilan['payes']++;
                    $bilan['total'] += $paye[$mid][$per];
                } elseif ($per === $moisCourant) {
                    $bilan['attente']++;
                } else {
                    $bilan['retard']++;
                }
            }
        }
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation bilan_annee : ' . $e->getMessage());
    }
    return $bilan;
}

/**
 * Liste des mois de cotisation en retard d'un membre (format AAAA-MM) : mois
 * écoulés depuis le début de saison (août 2026) et non réglés — y compris les
 * mois sans aucune ligne enregistrée. Vide pour les membres en virement
 * automatique ou exemptés.
 */
function cotisation_mois_retard(PDO $pdo, int $membreId): array
{
    $mode = cotisation_mode($pdo, $membreId);
    if ($mode === 'exempt') { return []; }
    $mois = cotisation_mois_ecoules(cotisation_debut($pdo));
    if (empty($mois)) { return []; }
    if ($mode === 'virement') {
        $ref = cotisation_virement_ref($pdo, $membreId);
        if ($ref === null) { return []; } // virement sans date de référence (ancien réglage) : jamais en retard
        // Seuls les mois antérieurs (ou égaux) à la bascule ne sont pas couverts par le virement
        $mois = array_values(array_filter($mois, function ($m) use ($ref) { return $m <= $ref; }));
        if (empty($mois)) { return []; }
    }
    try {
        $paye = [];
        $st = $pdo->prepare("SELECT periode FROM cotisations WHERE membre_id = ? AND statut IN ('paye', 'declare')");
        $st->execute([$membreId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { $paye[(string) $p] = true; }
        $out = [];
        foreach ($mois as $m) { if (empty($paye[$m])) { $out[] = $m; } }
        return $out;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Nombre de mois de cotisation en retard d'un membre. Voir cotisation_mois_retard().
 */
function cotisation_nb_retard(PDO $pdo, int $membreId): int
{
    return count(cotisation_mois_retard($pdo, $membreId));
}

/**
 * Bilan d'une période : nombre de payés, en attente, exemptés/virement, total encaissé.
 */
function cotisation_bilan(PDO $pdo, string $periode): array
{
    $bilan = ['payes' => 0, 'attente' => 0, 'virement' => 0, 'virement_attente' => 0, 'exempt' => 0, 'total' => 0.0, 'attendu' => 0.0, 'membres' => 0];
    try {
        $montant = cotisation_montant_periode($pdo, $periode);
        $membres = $pdo->query("SELECT id FROM membres WHERE status = 'approved'")->fetchAll(PDO::FETCH_COLUMN);
        $bilan['membres'] = count($membres);
        // Payés de la période
        $paye = [];
        $st = $pdo->prepare("SELECT membre_id, montant FROM cotisations WHERE periode = ? AND statut = 'paye'");
        $st->execute([$periode]);
        while ($r = $st->fetch()) { $paye[(int) $r['membre_id']] = (float) $r['montant']; }
        foreach ($membres as $mid) {
            $mid = (int) $mid;
            $mode = cotisation_mode($pdo, $mid);
            if ($mode === 'exempt') { $bilan['exempt']++; continue; }
            if ($mode === 'virement') {
                $bilan['virement']++;
                if (cotisation_virement_couvre($pdo, $mid, $periode)) {
                    // Virement automatique en vigueur pour ce mois : réglé seulement si la réception est confirmée
                    if (isset($paye[$mid])) {
                        $bilan['payes']++;
                        $bilan['total'] += $paye[$mid];
                    } else {
                        $bilan['virement_attente']++;
                    }
                    continue;
                }
                // Mois antérieur (ou égal) à la bascule en virement : traité comme un mois normal ci-dessous
            }
            if (isset($paye[$mid])) {
                $bilan['payes']++;
                $bilan['total'] += $paye[$mid];
            } else {
                $bilan['attente']++;
                $bilan['attendu'] += cotisation_montant_membre($pdo, $mid, $periode);
            }
        }
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation bilan : ' . $e->getMessage());
    }
    return $bilan;
}

/**
 * Total encaissé d'un mois, réparti par mode de paiement (espèces, virement,
 * chèque, Stripe). Ne compte que les paiements réellement enregistrés
 * (statut « paye ») — un membre en virement automatique couvert sans ligne
 * explicite n'apparaît pas ici (voir l'indicateur « Virement automatique »
 * du bilan, qui le compte séparément).
 */
function cotisation_totaux_methode(PDO $pdo, string $periode): array
{
    $out = ['especes' => 0.0, 'virement' => 0.0, 'cheque' => 0.0, 'stripe' => 0.0, 'autre' => 0.0];
    try {
        $st = $pdo->prepare("SELECT methode, SUM(montant) AS total FROM cotisations WHERE periode = ? AND statut = 'paye' GROUP BY methode");
        $st->execute([$periode]);
        while ($r = $st->fetch()) {
            $meth = (string) ($r['methode'] ?? '');
            $cle = in_array($meth, ['especes', 'virement', 'cheque', 'stripe'], true) ? $meth : 'autre';
            $out[$cle] += (float) $r['total'];
        }
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation totaux_methode : ' . $e->getMessage());
    }
    return $out;
}

/**
 * Même répartition par mode de paiement, agrégée sur tous les mois écoulés
 * d'une année de cotisation.
 */
function cotisation_totaux_methode_annee(PDO $pdo, int $annee): array
{
    $out = ['especes' => 0.0, 'virement' => 0.0, 'cheque' => 0.0, 'stripe' => 0.0, 'autre' => 0.0];
    $debut = cotisation_debut($pdo);
    $moisCourant = cotisation_periode_courante();
    $moisAnnee = [];
    for ($m = 1; $m <= 12; $m++) {
        $per = sprintf('%04d-%02d', $annee, $m);
        if ($per >= $debut && $per <= $moisCourant) { $moisAnnee[] = $per; }
    }
    if (empty($moisAnnee)) { return $out; }
    try {
        $ph = implode(',', array_fill(0, count($moisAnnee), '?'));
        $st = $pdo->prepare("SELECT methode, SUM(montant) AS total FROM cotisations WHERE periode IN ($ph) AND statut = 'paye' GROUP BY methode");
        $st->execute($moisAnnee);
        while ($r = $st->fetch()) {
            $meth = (string) ($r['methode'] ?? '');
            $cle = in_array($meth, ['especes', 'virement', 'cheque', 'stripe'], true) ? $meth : 'autre';
            $out[$cle] += (float) $r['total'];
        }
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation totaux_methode_annee : ' . $e->getMessage());
    }
    return $out;
}

/**
 * Chaque jour (au premier chargement de l'accueil), prévient les responsables Finance des virements automatiques dont
 * le jour du mois est arrivé (ou dépassé) et dont la réception n'est pas encore confirmée, pour qu'ils valident
 * « bien reçu » ou « non reçu ». Une seule notification par membre et par mois. Jamais bloquant.
 */
function cotisation_check_virements_jour(PDO $pdo): void
{
    $aujourdhui = date('Y-m-d');
    $flag = 'vir_jour_check_' . $aujourdhui;
    if (dahira_param($pdo, $flag, '') === '1') { return; }
    $periode = cotisation_periode_courante();
    $jourAuj = (int) date('j');
    $nbJours = (int) date('t');
    try {
        $fins = cotisation_responsables_ids($pdo);
        $rows = $pdo->query("SELECT m.id, m.prenom, m.nom, c.virement_jour, c.virement_debut FROM cotisation_config c JOIN membres m ON m.id = c.membre_id
                             WHERE c.mode = 'virement' AND m.status = 'approved' AND c.virement_jour IS NOT NULL")->fetchAll();
        foreach ($rows as $r) {
            $mid = (int) $r['id'];
            if (!cotisation_virement_couvre($pdo, $mid, $periode)) { continue; }
            $jourEff = min((int) $r['virement_jour'], $nbJours);
            $dateAttendue = sprintf('%s-%02d', $periode, $jourEff);
            // mois de début : jamais avant la date de début choisie
            if (!empty($r['virement_debut']) && substr((string) $r['virement_debut'], 0, 7) === $periode && (string) $r['virement_debut'] > $dateAttendue) { $dateAttendue = (string) $r['virement_debut']; }
            if ($aujourdhui < $dateAttendue) { continue; }
            if (cotisation_est_paye($pdo, $mid, $periode)) { continue; }
            $cle = 'vir_notif_' . $mid . '_' . $periode;
            if (dahira_param($pdo, $cle, '') === '1') { continue; }
            $nom = trim($r['prenom'] . ' ' . $r['nom']);
            $montant = number_format(cotisation_montant_membre($pdo, $mid, $periode), 2, ',', ' ');
            foreach ($fins as $fid) {
                troba_notify_membre($pdo, (int) $fid, 'cotisation', '🏦 Virement à confirmer', $nom . ' : virement de ' . $montant . ' € attendu le ' . date('d/m', strtotime($dateAttendue)) . '. Confirmez la réception ou signalez qu\'il n\'est pas arrivé.', 'admin_cotisations.php?periode=' . $periode);
            }
            dahira_set_param($pdo, $cle, '1');
        }
        dahira_set_param($pdo, $flag, '1');
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation check_virements_jour : ' . $e->getMessage());
    }
}

/** Modes de paiement qu'un membre peut déclarer : clé => libellé. */
function cotisation_modes_declarables(): array
{
    return ['virement' => '🏦 Virement bancaire', 'especes' => '💵 Versement en liquide'];
}

/** Paiement déclaré par un membre pour une période (statut « declare »), ou null. */
function cotisation_paiement_declare(PDO $pdo, int $membreId, string $periode): ?array
{
    $r = cotisation_statut($pdo, $membreId, $periode);
    return ($r !== null && ($r['statut'] ?? '') === 'declare') ? $r : null;
}

/** Mois que le membre peut déclarer comme payés : mois en retard, et mois courant s'il n'est ni réglé ni déjà déclaré. */
function cotisation_mois_declarables(PDO $pdo, int $membreId): array
{
    $mode = cotisation_mode($pdo, $membreId);
    if ($mode === 'exempt') { return []; }
    $out = cotisation_mois_retard($pdo, $membreId); // exclut déjà les mois déclarés
    $per = cotisation_periode_courante();
    $modeEff = ($mode === 'virement' && cotisation_virement_couvre($pdo, $membreId, $per)) ? 'virement' : 'mensuel';
    if ($modeEff === 'mensuel' && !cotisation_est_paye($pdo, $membreId, $per) && cotisation_paiement_declare($pdo, $membreId, $per) === null && !in_array($per, $out, true)) { $out[] = $per; }
    rsort($out);
    return $out;
}

/**
 * Le membre déclare à la Finance avoir payé un mois, par virement ou en liquide. Le paiement reste « déclaré »
 * (non réglé) jusqu'à validation par la Finance, qui est prévenue.
 */
function cotisation_paiement_declarer(PDO $pdo, int $membreId, string $periode, string $methode, ?float $montantDeclare = null): bool
{
    if (!isset(cotisation_modes_declarables()[$methode]) || !preg_match('/^\d{4}-\d{2}$/', $periode)) { return false; }
    if ($montantDeclare !== null && ($montantDeclare < 1 || $montantDeclare > 10000)) { return false; }
    if (!in_array($periode, cotisation_mois_declarables($pdo, $membreId), true)) { return false; }
    $montant = ($montantDeclare !== null) ? round($montantDeclare, 2) : cotisation_montant_membre($pdo, $membreId, $periode); // par défaut : la capacité confirmée par le membre
    try {
        $pdo->prepare("INSERT INTO cotisations (membre_id, periode, montant, statut, methode, declare_le) VALUES (?, ?, ?, 'declare', ?, NOW())
                       ON DUPLICATE KEY UPDATE statut = 'declare', methode = VALUES(methode), montant = VALUES(montant), declare_le = NOW(), updated_at = NOW()")
            ->execute([$membreId, $periode, $montant, $methode]);
        $st = $pdo->prepare("SELECT TRIM(CONCAT(prenom, ' ', nom)) FROM membres WHERE id = ?");
        $st->execute([$membreId]);
        $nom = (string) $st->fetchColumn();
        $fins = cotisation_responsables_ids($pdo);
        $lib = $methode === 'especes' ? 'versement en liquide' : 'virement';
        foreach ($fins as $fid) {
            troba_notify_membre($pdo, (int) $fid, 'cotisation', '💶 Paiement déclaré', $nom . ' déclare avoir payé ' . cotisation_libelle_mois($periode) . ' (' . number_format($montant, 2, ',', ' ') . ' €) par ' . $lib . '. À valider.', 'admin_cotisations.php?periode=' . $periode);
        }
        return true;
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation paiement_declarer : ' . $e->getMessage());
        return false;
    }
}

/** Le membre retire sa déclaration de paiement (encore en attente). */
function cotisation_paiement_declaration_retirer(PDO $pdo, int $membreId, string $periode): void
{
    try { $pdo->prepare("DELETE FROM cotisations WHERE membre_id = ? AND periode = ? AND statut = 'declare'")->execute([$membreId, $periode]); }
    catch (Exception $e) { /* sans effet */ }
}

/** Paiements déclarés en attente de validation (pour la Finance). */
function cotisation_paiements_declares(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT c.id, c.membre_id, c.periode, c.methode, c.montant, c.declare_le, m.prenom, m.nom
                            FROM cotisations c JOIN membres m ON m.id = c.membre_id
                            WHERE c.statut = 'declare' AND m.status = 'approved' ORDER BY c.declare_le ASC, c.id ASC")->fetchAll();
    } catch (Exception $e) { return []; }
}

/** La Finance valide un paiement déclaré : la cotisation est réglée avec le mode déclaré (le membre est remercié). */
function cotisation_paiement_declare_valider(PDO $pdo, int $ligneId): bool
{
    $st = $pdo->prepare("SELECT membre_id, periode, methode, montant FROM cotisations WHERE id = ? AND statut = 'declare'");
    $st->execute([$ligneId]);
    $r = $st->fetch();
    if (!$r) { return false; }
    $methode = in_array($r['methode'], ['virement', 'especes'], true) ? $r['methode'] : 'especes';
    return cotisation_marquer_paye($pdo, (int) $r['membre_id'], (string) $r['periode'], $methode, '', (float) $r['montant']);
}

/** La Finance refuse un paiement déclaré (motif facultatif) : le mois reste dû et le membre est prévenu. */
function cotisation_paiement_declare_refuser(PDO $pdo, int $ligneId, string $motif = ''): bool
{
    $st = $pdo->prepare("SELECT membre_id, periode FROM cotisations WHERE id = ? AND statut = 'declare'");
    $st->execute([$ligneId]);
    $r = $st->fetch();
    if (!$r) { return false; }
    $pdo->prepare("DELETE FROM cotisations WHERE id = ? AND statut = 'declare'")->execute([$ligneId]);
    troba_notify_membre($pdo, (int) $r['membre_id'], 'cotisation', '💶 Paiement non confirmé', 'La Finance n\'a pas pu confirmer votre paiement de ' . cotisation_libelle_mois((string) $r['periode']) . ($motif !== '' ? ' : ' . mb_substr($motif, 0, 160) : '.') . ' Vous pouvez refaire une déclaration ou régler en ligne.', 'cotisation.php');
    return true;
}
/** Le membre est-il responsable de la commission Finance / Trésorerie (gestion des cotisations) ? */
function cotisation_est_finance(PDO $pdo, int $membreId): bool
{
    return fr_est_responsable($pdo, 'cotisation', $membreId); // responsables désignés des cotisations
}

/** Identifiants des responsables désignés des cotisations (destinataires des notifications). */
function cotisation_responsables_ids(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT DISTINCT fr.membre_id FROM fonction_responsables fr JOIN membres m ON m.id = fr.membre_id WHERE fr.fonction = 'cotisation' AND m.status = 'approved'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Ce qui attend la Finance (accueil des responsables des cotisations) : paiements déclarés à valider,
 * virements automatiques déclarés à activer, virements du mois à confirmer dont le jour est arrivé.
 */
function cotisation_a_traiter(PDO $pdo): array
{
    $paiements = count(cotisation_paiements_declares($pdo));
    $declarations = count(cotisation_virement_declarations($pdo));
    $aConfirmer = 0;
    $jourAuj = (int) date('j');
    foreach (cotisation_virements_attendus($pdo, cotisation_periode_courante()) as $v) {
        if (!$v['recu'] && ($v['jour'] === null || $v['jour'] <= $jourAuj)) { $aConfirmer++; }
    }
    return ['paiements' => $paiements, 'declarations' => $declarations, 'virements' => $aConfirmer, 'total' => $paiements + $declarations + $aConfirmer];
}

/**
 * Virement automatique signalé « non reçu » par la Finance (désactivé côté membre) : ['periode' => AAAA-MM, 'le' => date]
 * tant que le membre n'a pas remis un virement en place (nouvelle déclaration) ni été réactivé ; sinon null.
 */
function cotisation_virement_non_recu_info(PDO $pdo, int $membreId): ?array
{
    try {
        $st = $pdo->prepare("SELECT virement_non_recu_le, virement_non_recu_periode FROM cotisation_config WHERE membre_id = ? AND mode = 'mensuel' AND virement_non_recu_le IS NOT NULL");
        $st->execute([$membreId]);
        $r = $st->fetch();
        if ($r) { return ['periode' => (string) ($r['virement_non_recu_periode'] ?? ''), 'le' => (string) $r['virement_non_recu_le']]; }
    } catch (Exception $e) { /* aucun signalement */ }
    return null;
}