<?php
/**
 * Touba Lyon 2026 - Modifications de profil soumises à validation
 *
 * Un membre validé qui modifie ses données (identité, coordonnées, informations, photo…) ne les change pas
 * directement : sa demande est enregistrée et attend la validation d'un administrateur. Tant qu'elle n'est pas
 * validée, les données en vigueur restent les anciennes et le membre garde son accès. Une seule demande en attente
 * par membre (une nouvelle demande remplace la précédente). Le mot de passe n'est pas concerné.
 * Table : membre_modifications (donnees = JSON des seuls champs modifiés, photo_path = nouvelle photo).
 */

/** Champs du profil soumis à validation : clé => libellé. */
function profil_champs(): array
{
    return [
        'civilite' => 'Civilité', 'prenom' => 'Prénom', 'nom' => 'Nom', 'email' => 'E-mail', 'telephone' => 'Téléphone',
        'adresse' => 'Adresse', 'code_postal' => 'Code postal', 'commune' => 'Commune', 'statut' => 'Statut',
        'secteur_activite' => "Secteur d'activité", 'profession' => 'Profession', 'annee_integration' => "Année d'intégration",
        'souhait_commission' => 'Souhait de commission', 'commentaires' => 'Commentaires',
    ];
}

/** Champs qui valent NULL en base quand ils sont vides. */
function profil_champs_nullables(): array
{
    return ['adresse', 'commentaires', 'souhait_commission'];
}

/** Supprime un fichier du dossier uploads (jamais l'image par défaut). */
function profil_supprimer_fichier(?string $nom): void
{
    $nom = trim((string) $nom);
    if ($nom === '' || $nom === 'default.jpg' || strpbrk($nom, '/\\') !== false) { return; }
    $f = __DIR__ . '/uploads/' . $nom;
    if (is_file($f)) { @unlink($f); }
}

/** Demande de modification en attente d'un membre : ['id', 'champs' => [...], 'photo' => nom|null, 'created_at'] ou null. */
function profil_modif_en_attente(PDO $pdo, int $membreId): ?array
{
    try {
        $st = $pdo->prepare("SELECT * FROM membre_modifications WHERE membre_id = ? AND statut = 'attente' ORDER BY id DESC LIMIT 1");
        $st->execute([$membreId]);
        $r = $st->fetch();
        if (!$r) { return null; }
        $champs = json_decode((string) $r['donnees'], true);
        return ['id' => (int) $r['id'], 'champs' => is_array($champs) ? $champs : [], 'photo' => $r['photo_path'] ?: null, 'created_at' => $r['created_at']];
    } catch (Exception $e) {
        return null;
    }
}

/** Nombre de demandes de modification en attente (badge du menu d'administration). */
function profil_modif_nb_attente(PDO $pdo): int
{
    try { return (int) $pdo->query("SELECT COUNT(*) FROM membre_modifications WHERE statut = 'attente'")->fetchColumn(); }
    catch (Exception $e) { return 0; }
}

/**
 * Enregistre la demande de modification d'un membre validé.
 * $nouveau : valeurs saisies (clés de profil_champs()). $photo : nom du fichier déjà enregistré dans uploads/, ou null.
 * Retourne ['aucune' => true] s'il n'y a rien à changer, sinon ['ok' => true, 'champs' => [clés modifiées], 'photo' => bool].
 */
function profil_modif_soumettre(PDO $pdo, array $membre, array $nouveau, ?string $photo): array
{
    $diff = [];
    foreach (profil_champs() as $k => $lib) {
        $ancien = trim((string) ($membre[$k] ?? ''));
        $val = trim((string) ($nouveau[$k] ?? ''));
        if ($ancien !== $val) { $diff[$k] = $val; }
    }
    if (empty($diff) && $photo === null) { return ['aucune' => true]; }
    $mid = (int) $membre['id'];
    // Une seule demande en attente : la précédente (et sa photo) est remplacée
    $anc = profil_modif_en_attente($pdo, $mid);
    if ($anc) {
        profil_supprimer_fichier($anc['photo']);
        $pdo->prepare("DELETE FROM membre_modifications WHERE id = ?")->execute([$anc['id']]);
    }
    $pdo->prepare("INSERT INTO membre_modifications (membre_id, donnees, photo_path, statut) VALUES (?, ?, ?, 'attente')")
        ->execute([$mid, json_encode($diff, JSON_UNESCAPED_UNICODE), $photo]);
    return ['ok' => true, 'champs' => array_keys($diff), 'photo' => $photo !== null];
}

/** Valide une demande : applique les changements au membre. Retourne ['ok' => bool, 'msg' => string]. */
function profil_modif_valider(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT * FROM membre_modifications WHERE id = ? AND statut = 'attente'");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) { return ['ok' => false, 'msg' => "Demande introuvable ou déjà traitée."]; }
    $mid = (int) $r['membre_id'];
    $sm = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
    $sm->execute([$mid]);
    $m = $sm->fetch();
    if (!$m) {
        profil_supprimer_fichier($r['photo_path']);
        $pdo->prepare("DELETE FROM membre_modifications WHERE id = ?")->execute([$id]);
        return ['ok' => false, 'msg' => "Ce membre n'existe plus : la demande est supprimée."];
    }
    $champs = json_decode((string) $r['donnees'], true);
    if (!is_array($champs)) { $champs = []; }
    $autorises = profil_champs();
    $sets = []; $params = [];
    foreach ($champs as $k => $v) {
        if (!isset($autorises[$k])) { continue; }
        $v = trim((string) $v);
        if ($k === 'email') {
            if (!filter_var($v, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'msg' => "L'adresse e-mail demandée n'est pas valide : refusez la demande."]; }
            $q = $pdo->prepare("SELECT id FROM membres WHERE email = ? AND id != ?");
            $q->execute([$v, $mid]);
            $dejaPris = ($q->fetchColumn() !== false);
            if (!$dejaPris && function_exists('email_autre_compte')) { $dejaPris = (email_autre_compte($pdo, $v) !== null); }
            if ($dejaPris) { return ['ok' => false, 'msg' => "Cette adresse e-mail est déjà utilisée par un autre compte : refusez la demande."]; }
        }
        $sets[] = "`$k` = ?";
        $params[] = ($v === '' && in_array($k, profil_champs_nullables(), true)) ? null : $v;
    }
    $anciennePhoto = null;
    if (!empty($r['photo_path'])) {
        $sets[] = "photo_path = ?";
        $params[] = $r['photo_path'];
        $anciennePhoto = $m['photo_path'] ?? null;
    }
    $pdo->beginTransaction();
    try {
        if (!empty($sets)) {
            $params[] = $mid;
            $pdo->prepare("UPDATE membres SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }
        $pdo->prepare("UPDATE membre_modifications SET statut = 'validee', traite_le = NOW() WHERE id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Touba Lyon profil_modif_valider : ' . $e->getMessage());
        return ['ok' => false, 'msg' => "Une erreur technique est survenue."];
    }
    if ($anciennePhoto !== null) { profil_supprimer_fichier($anciennePhoto); }
    if (function_exists('troba_notify_membre')) {
        troba_notify_membre($pdo, $mid, 'profil', '✅ Modifications validées', 'Vos modifications de profil ont été validées par un administrateur.', 'profile.php');
    }
    return ['ok' => true, 'msg' => "Modifications validées et appliquées au profil de " . trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '')) . "."];
}

/** Refuse une demande (la nouvelle photo est supprimée) et prévient le membre. */
function profil_modif_refuser(PDO $pdo, int $id, string $motif = ''): array
{
    $st = $pdo->prepare("SELECT * FROM membre_modifications WHERE id = ? AND statut = 'attente'");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) { return ['ok' => false, 'msg' => "Demande introuvable ou déjà traitée."]; }
    profil_supprimer_fichier($r['photo_path']);
    $pdo->prepare("UPDATE membre_modifications SET statut = 'refusee', motif = ?, traite_le = NOW(), photo_path = NULL WHERE id = ?")
        ->execute([$motif !== '' ? mb_substr($motif, 0, 255) : null, $id]);
    if (function_exists('troba_notify_membre')) {
        troba_notify_membre($pdo, (int) $r['membre_id'], 'profil', '❌ Modifications refusées', 'Vos modifications de profil n\'ont pas été validées' . ($motif !== '' ? ' : ' . mb_substr($motif, 0, 120) : '.') , 'profile.php');
    }
    return ['ok' => true, 'msg' => "Demande refusée : le membre est prévenu."];
}
