<?php
/**
 * Touba Lyon 2026 - Suivi d'un Kurel : avancement des Khassidas et message à un membre.
 *
 * Un Kurel travaille quelques Khassidas par année, chacune avec sa mélodie :
 * l'une est en cours, d'autres attendent leur tour, les précédentes sont
 * complétées. C'est ce tableau de marche que ces fonctions tiennent, et c'est
 * lui qui donne le contexte du message envoyé à un membre.
 */
require_once __DIR__ . '/dahira_emails.php';       // send_smtp_mail()
require_once __DIR__ . '/notification_helper.php'; // troba_notify_membre()

/** Les trois états d'une Khassida dans l'année, du plus lointain au plus abouti. */
function kourel_statuts(): array
{
    return [
        'attente'  => ['libelle' => 'En attente', 'icone' => '⏳', 'couleur' => '#c2a25a', 'fond' => 'rgba(212, 175, 55, 0.12)'],
        'en_cours' => ['libelle' => 'En cours',   'icone' => '🎼', 'couleur' => '#7fd1a3', 'fond' => 'rgba(45, 106, 79, 0.22)'],
        'complete' => ['libelle' => 'Complété',   'icone' => '✅', 'couleur' => '#9fd3ff', 'fond' => 'rgba(56, 116, 168, 0.20)'],
    ];
}

/** Un statut connu, ou 'attente' par défaut. */
function kourel_statut_valide(?string $statut): string
{
    return array_key_exists((string) $statut, kourel_statuts()) ? (string) $statut : 'attente';
}

/** Le libellé complet d'un statut, icône comprise. */
function kourel_statut_libelle(?string $statut): string
{
    $s = kourel_statuts()[kourel_statut_valide($statut)];
    return $s['icone'] . ' ' . $s['libelle'];
}

/**
 * Les années proposées : l'année en cours, les trois précédentes et la
 * suivante — de quoi préparer l'année à venir sans ouvrir un calendrier.
 */
function kourel_annees(): array
{
    $a = (int) date('Y');
    return range($a + 1, $a - 3);
}

/** Le niveau d'un membre sur une Khassida, tel qu'on le lui annonce. */
function kourel_niveaux(): array
{
    return [
        'decouverte'  => '🌱 Découverte',
        'progression' => '📈 En progression',
        'presque'     => '🎯 Presque maîtrisée',
        'maitrise'    => '✅ Maîtrisée',
    ];
}

/**
 * L'avancement d'un Kurel, groupé par année décroissante.
 * Renvoie [annee => [lignes]]. Une table absente donne un tableau vide.
 */
function kourel_avancements(PDO $pdo, int $kourel_id): array
{
    if ($kourel_id <= 0) {
        return [];
    }
    try {
        $q = $pdo->prepare("
            SELECT id, annee, khassida, melodie, statut
            FROM kourel_avancements
            WHERE kourel_id = ?
            ORDER BY annee DESC, khassida ASC
        ");
        $q->execute([$kourel_id]);
        $lignes = $q->fetchAll();
    } catch (Exception $e) {
        return [];
    }
    $par_annee = [];
    foreach ($lignes as $l) {
        $par_annee[(int) $l['annee']][] = $l;
    }
    return $par_annee;
}

/**
 * Les Khassidas que le Kurel travaille en ce moment : celles marquées
 * « en cours », année la plus récente d'abord. C'est à l'une d'elles qu'un
 * message adressé à un membre se rattache.
 */
function kourel_avancements_en_cours(PDO $pdo, int $kourel_id): array
{
    if ($kourel_id <= 0) {
        return [];
    }
    try {
        $q = $pdo->prepare("
            SELECT id, annee, khassida, melodie, statut
            FROM kourel_avancements
            WHERE kourel_id = ? AND statut = 'en_cours'
            ORDER BY annee DESC, khassida ASC
        ");
        $q->execute([$kourel_id]);
        return $q->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Une ligne d'avancement précise de ce Kurel, encore « en cours ».
 * Null si elle n'existe plus, appartient à un autre Kurel, ou a changé d'état.
 */
function kourel_avancement_en_cours(PDO $pdo, int $kourel_id, int $avancement_id): ?array
{
    if ($kourel_id <= 0 || $avancement_id <= 0) {
        return null;
    }
    try {
        $q = $pdo->prepare("
            SELECT id, annee, khassida, melodie, statut
            FROM kourel_avancements
            WHERE id = ? AND kourel_id = ? AND statut = 'en_cours'
        ");
        $q->execute([$avancement_id, $kourel_id]);
        $r = $q->fetch();
        return $r ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Tout le programme d'un Kurel à plat, année récente d'abord : de quoi
 * remplir une liste déroulante. Lu une seule fois par requête.
 */
function kourel_avancements_plats(PDO $pdo, int $kourel_id): array
{
    static $cache = [];
    if ($kourel_id <= 0) {
        return [];
    }
    if (array_key_exists($kourel_id, $cache)) {
        return $cache[$kourel_id];
    }
    try {
        $q = $pdo->prepare("
            SELECT id, annee, khassida, melodie, statut
            FROM kourel_avancements
            WHERE kourel_id = ?
            ORDER BY annee DESC, khassida ASC
        ");
        $q->execute([$kourel_id]);
        $cache[$kourel_id] = $q->fetchAll();
    } catch (Exception $e) {
        $cache[$kourel_id] = [];
    }
    return $cache[$kourel_id];
}

/** Une ligne du programme de ce Kurel, quel que soit son état. Null sinon. */
function kourel_avancement_du_kourel(PDO $pdo, int $kourel_id, int $avancement_id): ?array
{
    if ($kourel_id <= 0 || $avancement_id <= 0) {
        return null;
    }
    try {
        $q = $pdo->prepare("
            SELECT id, annee, khassida, melodie, statut
            FROM kourel_avancements
            WHERE id = ? AND kourel_id = ?
        ");
        $q->execute([$avancement_id, $kourel_id]);
        $r = $q->fetch();
        return $r ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Combien de répétitions travaillent chaque Khassida du programme.
 * Renvoie [avancement_id => nombre].
 */
function kourel_repetitions_par_avancement(PDO $pdo, int $kourel_id): array
{
    if ($kourel_id <= 0) {
        return [];
    }
    try {
        $q = $pdo->prepare("
            SELECT avancement_id, COUNT(*) AS nb
            FROM kourel_repetitions
            WHERE kourel_id = ? AND avancement_id IS NOT NULL
            GROUP BY avancement_id
        ");
        $q->execute([$kourel_id]);
        $par = [];
        foreach ($q->fetchAll() as $l) {
            $par[(int) $l['avancement_id']] = (int) $l['nb'];
        }
        return $par;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Le libellé court d'une ligne : « Midaadi · Classique », l'année seulement si
 * ce n'est pas celle en cours, l'état si on le demande.
 */
function kourel_avancement_libelle(array $av, bool $avec_etat = false): string
{
    $melodie = trim((string) ($av['melodie'] ?? ''));
    $libelle = (string) $av['khassida']
        . ($melodie !== '' ? ' · ' . $melodie : '')
        . ((int) ($av['annee'] ?? 0) !== (int) date('Y') ? ' · ' . (int) $av['annee'] : '');
    if ($avec_etat) {
        $etat = kourel_statuts()[kourel_statut_valide($av['statut'] ?? null)];
        $libelle = $etat['icone'] . ' ' . $libelle;
    }
    return $libelle;
}

/**
 * Inscrit une Khassida au programme d'une année.
 * Renvoie un message d'erreur, ou '' si tout s'est bien passé.
 */
function kourel_avancement_ajouter(PDO $pdo, int $kourel_id, int $annee, string $khassida, string $melodie, string $statut): string
{
    $khassida = trim($khassida);
    if ($kourel_id <= 0 || $khassida === '') {
        return "La Khassida est obligatoire.";
    }
    if (!in_array($annee, kourel_annees(), true)) {
        return "Cette année n'est pas proposée.";
    }
    try {
        $existe = $pdo->prepare("SELECT COUNT(*) FROM kourel_avancements WHERE kourel_id = ? AND annee = ? AND khassida = ?");
        $existe->execute([$kourel_id, $annee, $khassida]);
        if ((int) $existe->fetchColumn() > 0) {
            return "« " . $khassida . " » est déjà au programme de " . $annee . ".";
        }
        $pdo->prepare("
            INSERT INTO kourel_avancements (kourel_id, annee, khassida, melodie, statut)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$kourel_id, $annee, $khassida, (trim($melodie) !== '' ? trim($melodie) : null), kourel_statut_valide($statut)]);
        return '';
    } catch (Exception $e) {
        error_log('Touba Lyon kourel_avancement_ajouter: ' . $e->getMessage());
        return "L'ajout n'a pas abouti.";
    }
}

/** Change le statut d'une ligne d'avancement (le Kurel est vérifié). */
function kourel_avancement_statut(PDO $pdo, int $id, int $kourel_id, string $statut): bool
{
    try {
        $q = $pdo->prepare("UPDATE kourel_avancements SET statut = ?, updated_at = NOW() WHERE id = ? AND kourel_id = ?");
        $q->execute([kourel_statut_valide($statut), $id, $kourel_id]);
        return true;
    } catch (Exception $e) {
        error_log('Touba Lyon kourel_avancement_statut: ' . $e->getMessage());
        return false;
    }
}

/**
 * Retire une Khassida du programme d'une année. Les répétitions qui la
 * travaillaient sont détachées : elles gardent leur libellé, mais ne
 * renvoient plus vers une ligne disparue.
 */
function kourel_avancement_supprimer(PDO $pdo, int $id, int $kourel_id): bool
{
    try {
        try {
            $pdo->prepare("UPDATE kourel_repetitions SET avancement_id = NULL WHERE avancement_id = ? AND kourel_id = ?")
                ->execute([$id, $kourel_id]);
        } catch (Exception $e) {
            // Colonne pas encore créée : il n'y a rien à détacher
        }
        $pdo->prepare("DELETE FROM kourel_avancements WHERE id = ? AND kourel_id = ?")->execute([$id, $kourel_id]);
        return true;
    } catch (Exception $e) {
        error_log('Touba Lyon kourel_avancement_supprimer: ' . $e->getMessage());
        return false;
    }
}

/**
 * Le nom du Kurel précédé du mot « Kurel », sauf s'il le porte déjà :
 * « du Kurel Hizbut », jamais « du Kurel Kurel Hizbut ».
 */
function kourel_libelle(string $nom): string
{
    $nom = trim($nom);
    if ($nom === '') {
        return 'Kurel';
    }
    return preg_match('/^kurel\b/iu', $nom) ? $nom : 'Kurel ' . $nom;
}

/** Le nom de qui écrit : le membre connecté, ou l'administration. */
function kourel_auteur_nom(PDO $pdo): string
{
    if (!empty($_SESSION['player_id'])) {
        try {
            $q = $pdo->prepare("SELECT prenom, nom FROM membres WHERE id = ?");
            $q->execute([(int) $_SESSION['player_id']]);
            $m = $q->fetch();
            if ($m) {
                $n = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
                if ($n !== '') {
                    return $n;
                }
            }
        } catch (Exception $e) {
            // On signera « l'administration »
        }
    }
    return "L'administration du Dahira";
}

/**
 * Écrit à un membre du Kurel : cloche dans l'application, et e-mail s'il a
 * une adresse. Le niveau annoncé porte sur la Khassida indiquée.
 *
 * Renvoie ['erreur' => string, 'message' => string] : l'erreur si l'envoi
 * n'était pas possible, sinon le compte rendu à afficher.
 */
function kourel_message_membre(PDO $pdo, int $kourel_id, int $membre_id, string $texte, int $avancement_id, string $niveau): array
{
    $texte = trim($texte);
    if ($texte === '') {
        return ['erreur' => "Le message ne peut pas être vide.", 'message' => ''];
    }

    // Le message parle d'un travail en cours : la ligne est relue en base,
    // car son état a pu changer depuis l'affichage du formulaire.
    $avancement = kourel_avancement_en_cours($pdo, $kourel_id, $avancement_id);
    if ($avancement === null) {
        return ['erreur' => "Cette Khassida n'est plus « en cours » : la liste vient d'être remise à jour.", 'message' => ''];
    }
    try {
        $q = $pdo->prepare("
            SELECT m.id, m.prenom, m.nom, m.email
            FROM kourel_membres km JOIN membres m ON m.id = km.membre_id
            WHERE km.kourel_id = ? AND m.id = ? AND m.status = 'approved'
        ");
        $q->execute([$kourel_id, $membre_id]);
        $membre = $q->fetch();
    } catch (Exception $e) {
        $membre = null;
    }
    if (!$membre) {
        return ['erreur' => "Ce membre ne fait pas partie du Kurel.", 'message' => ''];
    }

    $kourel_nom = '';
    try {
        $k = $pdo->prepare("SELECT nom FROM kourels WHERE id = ?");
        $k->execute([$kourel_id]);
        $kourel_nom = (string) ($k->fetchColumn() ?: '');
    } catch (Exception $e) {
        // Le nom du Kurel n'est pas indispensable au message
    }

    $niveaux = kourel_niveaux();
    $niveau_libelle = $niveaux[$niveau] ?? '';
    $khassida = (string) $avancement['khassida'];
    $melodie = trim((string) $avancement['melodie']);
    $destinataire = trim(($membre['prenom'] ?? '') . ' ' . ($membre['nom'] ?? ''));
    if ($destinataire === '') {
        $destinataire = 'Cher membre';
    }
    $auteur = kourel_auteur_nom($pdo);
    $libelle_kourel = kourel_libelle($kourel_nom);

    // Le contexte en une ligne : la Khassida travaillée, sa mélodie, l'année,
    // et où en est la personne.
    $contexte = '📜 ' . $khassida
        . ($melodie !== '' ? ' · 🎶 ' . $melodie : '')
        . ' · ' . (int) $avancement['annee']
        . ($niveau_libelle !== '' ? ' — ' . $niveau_libelle : '');

    troba_notify_membre(
        $pdo,
        (int) $membre['id'],
        'kourel',
        '✉️ Message du ' . $libelle_kourel,
        ($contexte !== '' ? $contexte . ' — ' : '') . $texte,
        null
    );

    $courriel_envoye = false;
    $email = trim((string) ($membre['email'] ?? ''));
    if ($email !== '') {
        $corps =
            '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
            . '<h2 style="color:#1b4332;">Assalamu aleykum ' . htmlspecialchars($destinataire) . ' 🙏</h2>'
            . '<p style="color:#333;">Un mot du <strong>' . htmlspecialchars($libelle_kourel) . '</strong>, au sujet de la Khassida en cours :</p>'
            . '<p style="background:#f6f3e8;border:1px solid #d4af37;border-radius:8px;padding:12px 16px;color:#1b4332;">'
                . htmlspecialchars($contexte) . '</p>'
            . '<p style="color:#333;white-space:pre-line;">' . htmlspecialchars($texte) . '</p>'
            . '<p style="color:#555;">— ' . htmlspecialchars($auteur) . '</p>'
            . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p>'
            . '</div>';
        $sujet = '✉️ ' . $libelle_kourel . ($khassida !== '' ? ' — ' . $khassida : '');
        try {
            send_smtp_mail($email, $destinataire, $sujet, $corps);
            $courriel_envoye = true;
        } catch (Exception $e) {
            error_log('Touba Lyon kourel_message_membre (mail): ' . $e->getMessage());
        }
    }

    return [
        'erreur' => '',
        'message' => 'Message envoyé à ' . $destinataire . ' au sujet de « ' . $khassida . ' » : notification'
            . ($courriel_envoye ? ' et e-mail.' : " seule (pas d'adresse e-mail enregistrée)."),
    ];
}
