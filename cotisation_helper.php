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
function cotisation_set_mode(PDO $pdo, int $membreId, string $mode, ?int $jour = null): void
{
    if (!in_array($mode, ['mensuel', 'virement', 'exempt'], true)) { $mode = 'mensuel'; }
    $ref = ($mode === 'virement') ? cotisation_periode_courante() : null;
    // Jour du mois prévu pour le virement automatique (1-31), seulement en mode virement
    $jourVal = ($mode === 'virement' && $jour !== null && $jour >= 1 && $jour <= 31) ? $jour : null;
    $pdo->prepare("INSERT INTO cotisation_config (membre_id, mode, virement_depuis, virement_jour, updated_at) VALUES (?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE mode = VALUES(mode), virement_depuis = VALUES(virement_depuis), virement_jour = VALUES(virement_jour), updated_at = NOW()")
        ->execute([$membreId, $mode, $ref, $jourVal]);
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
                ->execute([$membreId, $m, cotisation_montant_periode($pdo, $m)]);
        }
        // Le mois concerné est retiré d'un éventuel pointage : il reste dû
        $pdo->prepare("DELETE FROM cotisations WHERE membre_id = ? AND periode = ? AND statut <> 'paye'")->execute([$membreId, $periode]);
        cotisation_set_mode($pdo, $membreId, 'mensuel');
    } catch (Exception $e) {
        error_log('Touba Lyon cotisation virement_non_recu : ' . $e->getMessage());
        return false;
    }
    $libelle = cotisation_libelle_mois($periode);
    $montant = number_format(cotisation_montant_periode($pdo, $periode), 2, ',', ' ');
    troba_notify_membre(
        $pdo,
        $membreId,
        'cotisation',
        '⚠️ Virement non reçu',
        'Nous n\'avons pas reçu votre virement de ' . $libelle . ' (' . $montant . ' €). Le virement automatique est désactivé : merci de régler votre cotisation en ligne.',
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
                . '<p style="margin:0 0 14px;">Le virement automatique a donc été <strong>désactivé</strong> sur votre compte. Merci de régler votre cotisation en ligne, ou de nous contacter si vous pensez qu\'il s\'agit d\'une erreur.</p>'
                . dahira_email_button(rtrim(dahira_base_url(), '/') . '/cotisation.php', 'Régler ma cotisation');
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
 * Appel à l'API Stripe (form-urlencoded). Retourne [ok, data|error].
 */
function cotisation_stripe_api(string $sk, string $method, string $path, array $params = []): array
{
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $sk],
        CURLOPT_TIMEOUT => 20,
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0) {
        return ['ok' => false, 'error' => 'Connexion Stripe impossible : ' . $err];
    }
    $data = json_decode((string) $resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Réponse Stripe invalide.'];
    }
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'data' => $data];
    }
    $msg = $data['error']['message'] ?? ('Erreur Stripe (HTTP ' . $code . ').');
    return ['ok' => false, 'error' => $msg];
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
    $montant = cotisation_montant_periode($pdo, $periode);
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
function cotisation_marquer_paye(PDO $pdo, int $membreId, string $periode, string $methode = 'stripe', string $ref = ''): bool
{
    $montant = cotisation_montant_periode($pdo, $periode);
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
                'La cotisation de ' . cotisation_libelle_mois($periode) . ' (' . number_format($montant, 2, ',', ' ') . ' €) est ouverte. Vous pouvez la régler en ligne.',
                'cotisation.php'
            );
        }
        // Début de mois : les responsables Finance doivent confirmer les virements automatiques reçus
        $attendus = cotisation_virements_attendus($pdo, $periode);
        $aConfirmer = count(array_filter($attendus, function ($v) { return !$v['recu']; }));
        if ($aConfirmer > 0) {
            $fins = $pdo->query("SELECT DISTINCT cg.membre_id FROM commission_gestionnaires cg JOIN commissions c ON c.id = cg.commission_id WHERE LOWER(c.nom) LIKE '%finance%' OR LOWER(c.nom) LIKE '%trésor%' OR LOWER(c.nom) LIKE '%tresor%'")->fetchAll(PDO::FETCH_COLUMN);
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
    $montantCourant = cotisation_montant_periode($pdo, cotisation_periode_courante());
    $moisRetard = cotisation_mois_retard($pdo, $membreId);
    $nb = count($moisRetard);
    $txt = "Assalamu aleykum" . ($prenom !== '' ? ' ' . trim($prenom) : '') . ",\n\n"
        . "Voici le récapitulatif de votre cotisation au Dahira Touba Lyon :\n"
        . "- Montant mensuel : " . number_format($montantCourant, 2, ',', ' ') . " €\n";
    if ($nb > 0) {
        $total = 0.0;
        $txt .= "- Mois en retard (" . $nb . ") :\n";
        foreach ($moisRetard as $m) {
            $mMontant = cotisation_montant_periode($pdo, $m);
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
        $st = $pdo->prepare("SELECT periode FROM cotisations WHERE membre_id = ? AND statut = 'paye'");
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
                $bilan['attendu'] += $montant;
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
