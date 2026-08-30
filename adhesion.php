<?php
/**
 * Touba Lyon 2026 - Formulaire d'adhésion au Dahira (nouveau membre)
 * Reproduit le formulaire Google "Dahira Touba Lyon - Formulaire d'inscription".
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/send_mail.php';
require_once __DIR__ . '/dahira_emails.php';

$error = '';
$success = '';

// Valeurs ré-affichées en cas d'erreur
$f = [
    'type_adhesion' => '', 'nom' => '', 'prenom' => '', 'genre' => '', 'test_kourel' => '',
    'adresse' => '', 'code_postal' => '', 'commune' => '', 'telephone' => '', 'email' => '',
    'statut' => '', 'secteur_activite' => '', 'profession' => '', 'commentaires' => '',
    'annee_integration' => '',
];

$TYPES   = ['Membre actif', 'Membre sympathisant'];
$GENRES  = ['Homme', 'Femme'];
$STATUTS = ['Professionnel', 'Etudiant', 'Alternant'];
try { $secteursList = $pdo->query("SELECT nom FROM secteurs ORDER BY nom ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $secteursList = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        foreach ($f as $k => $_) {
            $f[$k] = trim($_POST[$k] ?? '');
        }
        $charte = isset($_POST['charte_acceptee']);
        $photo  = $_FILES['photo'] ?? null;
        $password = $_POST['password'] ?? '';

        // Validation
        if (!in_array($f['type_adhesion'], $TYPES, true)) {
            $error = "Veuillez préciser le type d'adhésion souhaité.";
        } elseif ($f['nom'] === '' || $f['prenom'] === '') {
            $error = "Le nom et le prénom sont obligatoires.";
        } elseif (!in_array($f['genre'], $GENRES, true)) {
            $error = "Veuillez préciser votre genre.";
        } elseif ($f['test_kourel'] !== '' && !in_array($f['test_kourel'], ['Oui', 'Non'], true)) {
            $error = "Réponse invalide pour le test Kourel.";
        } elseif ($f['code_postal'] === '' || !preg_match('/^[0-9]{4,9}$/', $f['code_postal'])) {
            $error = "Veuillez saisir un code postal valide.";
        } elseif ($f['commune'] === '') {
            $error = "La commune est obligatoire.";
        } elseif ($f['telephone'] === '' || !preg_match('/^[0-9 +().-]{6,30}$/', $f['telephone'])) {
            $error = "Veuillez saisir un numéro de téléphone valide.";
        } elseif ($f['email'] === '' || !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            $error = "L'adresse email n'est pas valide.";
        } elseif ($password === '' || strlen($password) < 6) {
            $error = "Veuillez définir un mot de passe d'au moins 6 caractères.";
        } elseif (!in_array($f['statut'], $STATUTS, true)) {
            $error = "Veuillez préciser votre statut.";
        } elseif ($f['secteur_activite'] === '') {
            $error = "Le secteur d'activité est obligatoire.";
        } elseif ($f['profession'] === '') {
            $error = "La profession est obligatoire.";
        } elseif ($f['annee_integration'] === '' || !preg_match('/^[0-9]{4}$/', $f['annee_integration'])) {
            $error = "Veuillez saisir une année d'intégration valide (ex: 2022).";
        } elseif (!$charte) {
            $error = "Vous devez accepter la charte du Dahira pour vous inscrire.";
        } elseif (!$photo || $photo['error'] === UPLOAD_ERR_NO_FILE) {
            $error = "La photo de profil est obligatoire.";
        } else {
            try {
                // Vérification directe en base : cet email est-il déjà utilisé ?
                // (comparaison insensible à la casse, statut réel pris en compte)
                $stmt = $pdo->prepare("SELECT id, status FROM membres WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $stmt->execute([$f['email']]);
                $existing = $stmt->fetch();
                if ($existing) {
                    if ($existing['status'] === 'approved') {
                        $error = "Cette adresse email correspond déjà à un membre validé. Connectez-vous, ou utilisez « Mot de passe oublié ».";
                    } elseif ($existing['status'] === 'pending') {
                        $error = "Une demande d'inscription avec cette adresse email est déjà en attente de validation.";
                    } else {
                        $error = "Cette adresse email a été refusée précédemment. Veuillez contacter un administrateur.";
                    }
                } else {
                    // Traitement de la photo de profil
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                    $fileInfo  = pathinfo($photo['name']);
                    $extension = strtolower($fileInfo['extension'] ?? '');
                    $newFilename = null;

                    if (!in_array($extension, $allowedExtensions)) {
                        $error = "Format de photo invalide. Extensions autorisées : JPG, JPEG, PNG, WEBP.";
                    } elseif ($photo['size'] > 5 * 1024 * 1024) {
                        $error = "La photo est trop lourde (limite : 5 Mo).";
                    } else {
                        $mimeType = '';
                        if (function_exists('finfo_open')) {
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mimeType = finfo_file($finfo, $photo['tmp_name']);
                            finfo_close($finfo);
                        } else {
                            $mimeType = $photo['type'];
                        }
                        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/pjpeg', 'image/x-png'];
                        if (!in_array($mimeType, $allowedMimes)) {
                            $error = "Le fichier n'est pas une image valide.";
                        } else {
                            $newFilename = bin2hex(random_bytes(16)) . '.' . $extension;
                            $destination = __DIR__ . '/uploads/' . $newFilename;
                            if (!move_uploaded_file($photo['tmp_name'], $destination)) {
                                $error = "Erreur lors du transfert de la photo.";
                                $newFilename = null;
                            }
                        }
                    }

                    if (empty($error) && $newFilename !== null) {
                    // civilite dérivée du genre (cohérence avec le Dahira - Mubawwa-A-Sidqin)
                    $civilite = ($f['genre'] === 'Femme') ? 'Sokhna' : 'Goor Yalla';
                    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                    $sql = "INSERT INTO membres
                        (nom, prenom, civilite, email, photo_path, password, status,
                         type_adhesion, genre, test_kourel, adresse, code_postal, commune,
                         telephone, statut, secteur_activite, profession, commentaires,
                         annee_integration, charte_acceptee)
                        VALUES (?,?,?,?,?,?,'pending',?,?,?,?,?,?,?,?,?,?,?,?,?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $f['nom'], $f['prenom'], $civilite, $f['email'], $newFilename, $hashedPassword,
                        $f['type_adhesion'], $f['genre'], ($f['test_kourel'] ?: null),
                        ($f['adresse'] ?: null), $f['code_postal'], $f['commune'],
                        $f['telephone'], $f['statut'], $f['secteur_activite'], $f['profession'],
                        ($f['commentaires'] ?: null), $f['annee_integration'], 1,
                    ]);

                    // Notification e-mail au secrétariat (best effort)
                    $rows = '';
                    $recap = [
                        "Type d'adhésion" => $f['type_adhesion'],
                        'Nom' => $f['nom'], 'Prénom' => $f['prenom'], 'Genre' => $f['genre'],
                        'Test Kourel' => ($f['test_kourel'] ?: '—'),
                        'Adresse' => ($f['adresse'] ?: '—'), 'Code postal' => $f['code_postal'],
                        'Commune' => $f['commune'], 'Téléphone' => $f['telephone'], 'Email' => $f['email'],
                        'Statut' => $f['statut'], "Secteur d'activité" => $f['secteur_activite'],
                        'Profession' => $f['profession'], 'Commentaires' => ($f['commentaires'] ?: '—'),
                        "Année d'intégration" => $f['annee_integration'], 'Charte acceptée' => 'Oui',
                    ];
                    foreach ($recap as $label => $val) {
                        $rows .= '<tr><td style="padding:6px 12px;border:1px solid #e0e0e0;font-weight:bold;background:#f7f7f7;">'
                            . htmlspecialchars($label) . '</td><td style="padding:6px 12px;border:1px solid #e0e0e0;">'
                            . nl2br(htmlspecialchars($val)) . '</td></tr>';
                    }
                    $html = '<div style="font-family:Arial,sans-serif;color:#1a1a1a;">'
                        . '<h2 style="color:#1b4332;">Nouvelle inscription Dahira Touba Lyon</h2>'
                        . '<p>Une nouvelle demande d\'adhésion vient d\'être soumise :</p>'
                        . '<table style="border-collapse:collapse;font-size:14px;">' . $rows . '</table>'
                        . '<p style="font-size:0.85em;color:#888;margin-top:16px;">Touba Lyon — notification automatique.</p>'
                        . '</div>';

                    @send_smtp_mail(
                        SMTP_TO_EMAIL, 'Secrétariat Touba Lyon',
                        'Nouvelle inscription Dahira : ' . $f['prenom'] . ' ' . $f['nom'],
                        $html
                    );

                    // Confirmation d'inscription à l'adhérent (avec la charte)
                    @send_inscription_email($f['email'], $f['prenom'] . ' ' . $f['nom']);

                    $success = "Votre inscription a bien été enregistrée. Jërëjëf ! Le secrétariat vous contactera prochainement. (Pensez à prévoir 10€ pour votre carte de membre.)";
                    foreach ($f as $k => $_) {
                        $f[$k] = '';
                    }
                    } // fin du bloc photo OK + insertion
                }
            } catch (Exception $e) {
                error_log('Touba Lyon adhesion: ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
}

/** Aide : attribut selected/checked. */
function sel($a, $b) { return $a === $b ? 'selected' : ''; }
function chk($a, $b) { return $a === $b ? 'checked' : ''; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription au Dahira - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .adh-section-title {
            font-size: 1.2rem; font-weight: 700; color: var(--gold);
            margin: 2rem 0 1rem; padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--glass-border);
        }
        .charte-box {
            background: rgba(212, 175, 55, 0.06);
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 14px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;
            font-size: 0.9rem; color: var(--text-muted); line-height: 1.6;
            max-height: 280px; overflow-y: auto;
        }
        .charte-box h4 { color: var(--gold); margin: 0.75rem 0 0.35rem; }
        .charte-box ul { margin: 0.25rem 0 0.75rem 1.25rem; }
        .radio-row { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .radio-opt { display: flex; align-items: center; gap: 0.5rem; cursor: pointer; }
        .radio-opt input { width: 18px; height: 18px; accent-color: var(--accent); }
        .charte-check { display: flex; align-items: flex-start; gap: 0.65rem; margin-top: 1rem; cursor: pointer; }
        .charte-check input { width: 20px; height: 20px; margin-top: 2px; accent-color: var(--accent); }

        /* Lecteur paginé de la charte */
        .charte-reader {
            background: rgba(212, 175, 55, 0.06);
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 14px; padding: 1.25rem 1.5rem; margin-bottom: 1rem;
        }
        .charte-page { display: none; font-size: 0.92rem; color: var(--text-muted); line-height: 1.6; min-height: 230px; }
        .charte-page.active { display: block; animation: fadeIn 0.25s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .charte-page h4 { color: var(--gold); margin: 0.5rem 0 0.5rem; font-size: 1.05rem; }
        .charte-page ul { margin: 0.4rem 0 0.85rem 1.25rem; }
        .charte-page p { margin: 0.4rem 0; }
        .charte-pager {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; margin-top: 1rem; padding-top: 1rem;
            border-top: 1px solid var(--glass-border); flex-wrap: wrap;
        }
        .charte-dots { display: flex; gap: 0.4rem; }
        .charte-dot {
            width: 10px; height: 10px; border-radius: 50%;
            background: rgba(255,255,255,0.15); border: 1px solid var(--glass-border);
            transition: background 0.2s;
        }
        .charte-dot.seen { background: var(--accent); border-color: var(--accent); }
        .charte-dot.current { box-shadow: 0 0 0 3px rgba(212,175,55,0.25); }
        .charte-progress { font-size: 0.85rem; color: var(--text-muted); }
        .btn[disabled] { opacity: 0.4; cursor: not-allowed; }
        .charte-check.locked { opacity: 0.55; }
        .charte-hint { font-size: 0.82rem; color: var(--gold); margin-top: 0.5rem; }

        /* Uploader photo de profil (avatar) */
        .avatar-upload { display: flex; flex-direction: column; align-items: center; gap: 0.6rem; margin-bottom: 1.75rem; }
        .avatar-circle {
            width: 130px; height: 130px; border-radius: 50%;
            border: 2px dashed var(--glass-border); background: rgba(255, 255, 255, 0.04);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; position: relative; cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
        }
        .avatar-circle:hover { border-color: var(--accent); background: rgba(212, 175, 55, 0.06); }
        .avatar-circle.has-photo { border-style: solid; border-color: var(--accent); box-shadow: 0 4px 15px rgba(0,0,0,0.35); }
        .avatar-circle.invalid { border-color: var(--danger); }
        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; display: none; }
        .avatar-circle.has-photo img { display: block; }
        .avatar-placeholder {
            display: flex; flex-direction: column; align-items: center; gap: 0.3rem;
            color: var(--text-muted); pointer-events: none; text-align: center; padding: 0 0.5rem;
        }
        .avatar-circle.has-photo .avatar-placeholder { display: none; }
        .avatar-badge {
            position: absolute; bottom: 4px; right: 4px;
            width: 34px; height: 34px; border-radius: 50%;
            background: var(--accent); color: var(--secondary);
            display: flex; align-items: center; justify-content: center;
            border: 2px solid var(--secondary); box-shadow: 0 2px 8px rgba(0,0,0,0.4);
        }
        .avatar-hint { font-size: 0.82rem; color: var(--text-muted); }

        /* Surbrillance des champs manquants */
        .form-invalid,
        .msel.field-invalid .msel-trigger {
            border-color: var(--danger) !important;
            box-shadow: 0 0 0 3px rgba(191, 33, 33, 0.18) !important;
        }
        .field-invalid-zone { animation: shakeInvalid 0.4s ease; }
        @keyframes shakeInvalid {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        #val-list li { line-height: 1.5; }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="form-card" style="max-width: 720px;">
            <h1 class="form-title">Inscription au Dahira</h1>
            <p style="text-align:center; color:var(--text-muted); margin-bottom:1rem;">
                Assalamu aleykum. Merci de remplir ce formulaire pour vous inscrire en tant que membre du
                <strong>Dahira MUBAWWA-A-ASIDQIN, Touba Lyon</strong>. Ces informations permettront votre inscription
                à la liste des membres et la confection de votre carte de membre.
            </p>

            <div style="display:flex; align-items:flex-start; gap:0.65rem; background:rgba(212,175,55,0.08); border:1px solid rgba(212,175,55,0.3); border-radius:12px; padding:0.85rem 1rem; margin-bottom:1.75rem;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:2px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <p style="font-size:0.85rem; color:var(--text-muted); margin:0; line-height:1.5;">
                    <strong style="color:var(--gold);">NB :</strong> Les informations recueillies seront enregistrées et traitées dans un cadre sécurisé.
                </p>
            </div>

            <form action="adhesion.php" method="POST" enctype="multipart/form-data" id="adhesion-form" novalidate>
                <?php echo csrf_field(); ?>

                <!-- ── Charte (lecteur paginé) ── -->
                <h2 class="adh-section-title">Charte du Dahira Touba Lyon</h2>
                <p style="color:var(--text-muted); font-size:0.88rem; margin-bottom:0.75rem;">
                    Veuillez lire l'intégralité de la charte. Vous pourrez l'accepter une fois <strong>toutes les pages parcourues</strong>.
                </p>

                <div class="charte-reader">
                    <!-- Page 1 : Préambule -->
                    <div class="charte-page active" data-page="0">
                        <h4>Préambule</h4>
                        <p>La présente charte a été établie lors de l'assemblée générale du Dahira Touba Lyon tenue le <strong>29 octobre 2022</strong>.</p>
                        <p>Elle s'applique à <strong>tous les membres</strong> du Dahira Touba Lyon. En adhérant, chaque membre s'engage à en respecter l'ensemble des articles présentés dans les pages suivantes.</p>
                    </div>

                    <!-- Page 2 : Section Activité -->
                    <div class="charte-page" data-page="1">
                        <h4>Section Activité</h4>
                        <p><strong>Article 1 :</strong> Les activités principales du dahira sont :</p>
                        <ul>
                            <li>Dahira : organisé 2 fois par mois</li>
                            <li>Goudi Al Jumah : 1 fois par semaine (jeudi soir)</li>
                            <li>École coranique pour enfants : tous les dimanches</li>
                            <li>Répétitions Kourels : hebdomadaire</li>
                            <li>Magals et Gamou</li>
                        </ul>
                        <p><strong>Article 2 :</strong> Tout membre doit assister aux activités programmées si les conditions sont réunies.</p>
                        <p><strong>Article 4 :</strong> Tout membre doit intégrer une commission et participer à ses activités.</p>
                        <p><strong>Article 5 :</strong> Tout membre doit veiller au bon fonctionnement et à l'image du Dahira.</p>
                    </div>

                    <!-- Page 3 : Section Finance et Social -->
                    <div class="charte-page" data-page="2">
                        <h4>Section Finance et Social</h4>
                        <p><strong>Article 6 :</strong> Tout membre doit acheter une carte membre de 10 €.</p>
                        <p><strong>Article 7 :</strong> Trois cotisations sont obligatoires pour tous les membres :</p>
                        <ul>
                            <li>Cotisation mensuelle ordinaire de 30 € (soit 360 €/an)</li>
                            <li>Cotisation annuelle pour le Magal de 300 €/an</li>
                            <li>Cotisation pour les travaux du Keur Serigne Touba selon les capacités du membre</li>
                        </ul>
                        <p><strong>Article 8 :</strong> Participation au financement des activités sociales sur sollicitation de la commission sociale.</p>
                        <p><strong>Article 9 :</strong> Tout membre peut solliciter la commission sociale pour une assistance (financière, physique ou matérielle).</p>
                    </div>

                    <!-- Pagination -->
                    <div class="charte-pager">
                        <button type="button" class="btn btn-secondary btn-sm" id="charte-prev" disabled>← Précédent</button>
                        <div style="display:flex; flex-direction:column; align-items:center; gap:0.35rem;">
                            <div class="charte-dots" id="charte-dots"></div>
                            <span class="charte-progress" id="charte-progress">Page 1 / 3</span>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" id="charte-next">Suivant →</button>
                    </div>
                </div>

                <label class="charte-check locked" id="charte-check-label">
                    <input type="checkbox" name="charte_acceptee" value="1" id="charte-input" disabled required>
                    <span>J'accepte de respecter l'ensemble des articles de la charte du Dahira. <span style="color:var(--danger)">*</span></span>
                </label>
                <p class="charte-hint" id="charte-hint">🔒 Parcourez toutes les pages de la charte pour pouvoir l'accepter.</p>

                <!-- ── Type d'adhésion ── -->
                <h2 class="adh-section-title">Type d'adhésion</h2>
                <div class="form-group">
                    <label class="form-label">Souhaitez-vous devenir membre actif ou suivre l'actualité du Dahira ? <span style="color:var(--danger)">*</span></label>
                    <div class="radio-row">
                        <?php foreach ($TYPES as $t): ?>
                            <label class="radio-opt"><input type="radio" name="type_adhesion" value="<?php echo $t; ?>" <?php echo chk($f['type_adhesion'], $t); ?> required> <?php echo $t; ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ── Informations personnelles ── -->
                <h2 class="adh-section-title">Informations personnelles</h2>

                <!-- Photo de profil (obligatoire) -->
                <div class="avatar-upload">
                    <label class="form-label" style="text-align:center;">Photo de profil <span style="color:var(--danger)">*</span></label>
                    <div class="avatar-circle" id="avatar-circle" role="button" tabindex="0" aria-label="Ajouter une photo de profil" onclick="document.getElementById('photo-input').click()">
                        <img id="avatar-preview" alt="Aperçu de la photo">
                        <div class="avatar-placeholder">
                            <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <span style="font-size:0.78rem;">Ajouter une photo</span>
                        </div>
                        <span class="avatar-badge" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        </span>
                    </div>
                    <input type="file" id="photo-input" name="photo" accept="image/png, image/jpeg, image/webp" required style="display:none;">
                    <span class="avatar-hint">JPG, PNG ou WEBP — 5 Mo maximum.</span>
                </div>

                <div class="form-group">
                    <label for="nom" class="form-label">Nom de famille <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="nom" name="nom" class="form-input" value="<?php echo htmlspecialchars($f['nom']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="prenom" class="form-label">Prénom <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="prenom" name="prenom" class="form-input" value="<?php echo htmlspecialchars($f['prenom']); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Genre <span style="color:var(--danger)">*</span></label>
                    <div class="radio-row">
                        <?php foreach ($GENRES as $g): ?>
                            <label class="radio-opt"><input type="radio" name="genre" value="<?php echo $g; ?>" <?php echo chk($f['genre'], $g); ?> required> <?php echo $g; ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Si vous êtes un homme, souhaitez-vous passer un test pour intégrer un Kourel ?</label>
                    <div class="radio-row">
                        <label class="radio-opt"><input type="radio" name="test_kourel" value="Oui" <?php echo chk($f['test_kourel'], 'Oui'); ?>> Oui</label>
                        <label class="radio-opt"><input type="radio" name="test_kourel" value="Non" <?php echo chk($f['test_kourel'], 'Non'); ?>> Non</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="adresse" class="form-label">Adresse</label>
                    <input type="text" id="adresse" name="adresse" class="form-input" value="<?php echo htmlspecialchars($f['adresse']); ?>">
                </div>
                <div class="form-group">
                    <label for="code_postal" class="form-label">Code postal <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="code_postal" name="code_postal" class="form-input" inputmode="numeric" value="<?php echo htmlspecialchars($f['code_postal']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="commune" class="form-label">Commune <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="commune" name="commune" class="form-input" value="<?php echo htmlspecialchars($f['commune']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="telephone" class="form-label">Téléphone <span style="color:var(--danger)">*</span></label>
                    <input type="tel" id="telephone" name="telephone" class="form-input" value="<?php echo htmlspecialchars($f['telephone']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email" class="form-label">Email <span style="color:var(--danger)">*</span></label>
                    <input type="email" id="email" name="email" class="form-input" value="<?php echo htmlspecialchars($f['email']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="password" class="form-label">Mot de passe <span style="color:var(--danger)">*</span></label>
                    <input type="password" id="password" name="password" class="form-input" placeholder="Au moins 6 caractères (pour vous connecter et jouer à Ki Kan La)" minlength="6" required>
                </div>

                <!-- ── Votre situation ── -->
                <h2 class="adh-section-title">Votre situation</h2>
                <div class="form-group">
                    <label for="statut" class="form-label">Statut <span style="color:var(--danger)">*</span></label>
                    <select id="statut" name="statut" class="form-input modern-select" required>
                        <option value="">— Choisir —</option>
                        <?php foreach ($STATUTS as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo sel($f['statut'], $s); ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="secteur_activite" class="form-label">Secteur d'activité <span style="color:var(--danger)">*</span></label>
                    <select id="secteur_activite" name="secteur_activite" class="form-input modern-select" required>
                        <option value="">— Choisir un secteur —</option>
                        <?php foreach ($secteursList as $sName): ?>
                            <option value="<?php echo htmlspecialchars($sName); ?>" <?php echo ($f['secteur_activite'] === $sName) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sName); ?></option>
                        <?php endforeach; ?>
                        <?php if ($f['secteur_activite'] !== '' && !in_array($f['secteur_activite'], $secteursList, true)): ?>
                            <option value="<?php echo htmlspecialchars($f['secteur_activite']); ?>" selected><?php echo htmlspecialchars($f['secteur_activite']); ?> (ancienne valeur)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="profession" class="form-label">Profession <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="profession" name="profession" class="form-input" value="<?php echo htmlspecialchars($f['profession']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="annee_integration" class="form-label">Année d'intégration au Dahira <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="annee_integration" name="annee_integration" class="form-input" inputmode="numeric" placeholder="Ex: 2022" value="<?php echo htmlspecialchars($f['annee_integration']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="commentaires" class="form-label">Commentaires</label>
                    <textarea id="commentaires" name="commentaires" class="form-input" rows="3"><?php echo htmlspecialchars($f['commentaires']); ?></textarea>
                </div>

                <p style="color:var(--text-muted); font-size:0.85rem; margin: 1rem 0;">
                    En envoyant ce formulaire, vous recevrez bientôt votre carte de membre actif du Dahira (prévoir 10 € pour la carte). Secrétariat Général, Touba Lyon.
                </p>

                <button type="submit" class="btn btn-primary" style="width:100%; margin-top:0.5rem; font-size:1rem; padding:1rem;">Envoyer mon inscription</button>
            </form>
        </div>
    </main>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display: flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($success)): ?>
                    <h3 class="gold-text">Inscription enregistrée</h3>
                <?php else: ?>
                    <h3 style="color: var(--danger);">Une erreur est survenue</h3>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p>
            </div>
            <div class="modal-footer">
                <?php if (!empty($success)): ?>
                    <a href="index.php" class="btn btn-primary btn-sm">Retour à l'accueil</a>
                <?php else: ?>
                    <button onclick="closeNotificationModal()" class="btn btn-primary btn-sm">Réessayer</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal de validation (champs manquants) -->
    <div id="validation-modal" class="modal-overlay">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <h3 style="color: var(--danger);">⚠️ Informations manquantes</h3>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 0.85rem;">Veuillez compléter les champs suivants avant d'envoyer votre inscription :</p>
                <ul id="val-list" style="margin: 0 0 0 1.1rem; color: var(--text-muted); display: flex; flex-direction: column; gap: 0.35rem;"></ul>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeValidationModal()" class="btn btn-primary btn-sm">Compléter</button>
            </div>
        </div>
    </div>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <script>
        function closeNotificationModal() {
            const modal = document.getElementById('notification-modal');
            if (modal) {
                modal.classList.remove('active');
                setTimeout(() => { modal.style.display = 'none'; }, 300);
            }
        }

        function closeValidationModal() {
            const modal = document.getElementById('validation-modal');
            if (modal) {
                modal.classList.remove('active');
                setTimeout(() => { modal.style.display = 'none'; }, 300);
            }
        }

        // ── Validation claire avant envoi ──
        (function () {
            const form = document.getElementById('adhesion-form');
            if (!form) return;

            function fieldEl(name) { return form.elements[name]; }
            function groupOf(el) { return el ? el.closest('.form-group') || el : null; }
            function radioChecked(name) { return !!form.querySelector('input[name="' + name + '"]:checked'); }
            function radioGroupZone(name) {
                const el = form.querySelector('input[name="' + name + '"]');
                return el ? el.closest('.form-group') : null;
            }

            function mark(zone) {
                if (!zone) return;
                zone.classList.add('field-invalid-zone');
                setTimeout(() => zone.classList.remove('field-invalid-zone'), 500);
            }

            form.addEventListener('submit', function (e) {
                // Nettoyer les surbrillances précédentes
                form.querySelectorAll('.form-invalid').forEach(el => el.classList.remove('form-invalid'));
                form.querySelectorAll('.msel.field-invalid').forEach(el => el.classList.remove('field-invalid'));
                const circle = document.getElementById('avatar-circle');
                if (circle) circle.classList.remove('invalid');

                const missing = [];
                let firstZone = null;
                function fail(label, zone, inputEl) {
                    missing.push(label);
                    if (inputEl) inputEl.classList.add('form-invalid');
                    if (!firstZone) firstZone = zone || inputEl;
                }

                // Photo
                const photo = document.getElementById('photo-input');
                if (!photo || !photo.files || !photo.files.length) {
                    missing.push('Photo de profil');
                    if (circle) circle.classList.add('invalid');
                    if (!firstZone) firstZone = document.querySelector('.avatar-upload');
                }
                // Type d'adhésion
                if (!radioChecked('type_adhesion')) fail("Type d'adhésion", radioGroupZone('type_adhesion'));
                // Nom / Prénom
                if (!fieldEl('nom').value.trim()) fail('Nom de famille', null, fieldEl('nom'));
                if (!fieldEl('prenom').value.trim()) fail('Prénom', null, fieldEl('prenom'));
                // Genre
                if (!radioChecked('genre')) fail('Genre', radioGroupZone('genre'));
                // Code postal
                if (!/^[0-9]{4,9}$/.test(fieldEl('code_postal').value.trim())) fail('Code postal (chiffres)', null, fieldEl('code_postal'));
                // Commune
                if (!fieldEl('commune').value.trim()) fail('Commune', null, fieldEl('commune'));
                // Téléphone
                if (!/^[0-9 +().-]{6,30}$/.test(fieldEl('telephone').value.trim())) fail('Téléphone valide', null, fieldEl('telephone'));
                // Email
                const email = fieldEl('email').value.trim();
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) fail('Adresse email valide', null, fieldEl('email'));
                // Mot de passe
                if (fieldEl('password').value.length < 6) fail('Mot de passe (6 caractères minimum)', null, fieldEl('password'));
                // Statut (select moderne)
                const statut = fieldEl('statut');
                if (!statut.value) {
                    missing.push('Statut');
                    const msel = statut.closest('.msel');
                    if (msel) msel.classList.add('field-invalid');
                    if (!firstZone) firstZone = msel || statut.closest('.form-group');
                }
                // Secteur / Profession / Année
                if (!fieldEl('secteur_activite').value.trim()) fail("Secteur d'activité", null, fieldEl('secteur_activite'));
                if (!fieldEl('profession').value.trim()) fail('Profession', null, fieldEl('profession'));
                if (!/^[0-9]{4}$/.test(fieldEl('annee_integration').value.trim())) fail("Année d'intégration (ex : 2022)", null, fieldEl('annee_integration'));
                // Charte
                const charte = document.getElementById('charte-input');
                if (!charte || !charte.checked) {
                    missing.push('Lecture et acceptation de la charte');
                    if (!firstZone) firstZone = document.querySelector('.charte-reader');
                }

                if (missing.length) {
                    e.preventDefault();
                    const list = document.getElementById('val-list');
                    list.innerHTML = '';
                    missing.forEach(function (m) {
                        const li = document.createElement('li');
                        li.textContent = m;
                        list.appendChild(li);
                    });
                    const modal = document.getElementById('validation-modal');
                    modal.style.display = 'flex';
                    setTimeout(() => modal.classList.add('active'), 10);
                    if (firstZone) {
                        firstZone.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        mark(firstZone);
                    }
                }
            });

            // Retirer la surbrillance quand l'utilisateur corrige
            form.addEventListener('input', function (e) {
                if (e.target.classList) e.target.classList.remove('form-invalid');
            });
        })();

        // ── Lecteur paginé de la charte ──
        (function () {
            const pages = Array.from(document.querySelectorAll('.charte-page'));
            const total = pages.length;
            if (!total) return;

            const prevBtn  = document.getElementById('charte-prev');
            const nextBtn  = document.getElementById('charte-next');
            const progress = document.getElementById('charte-progress');
            const dotsWrap = document.getElementById('charte-dots');
            const input    = document.getElementById('charte-input');
            const label    = document.getElementById('charte-check-label');
            const hint     = document.getElementById('charte-hint');

            let current = 0;
            const seen = new Set([0]);

            // Création des pastilles
            for (let i = 0; i < total; i++) {
                const d = document.createElement('span');
                d.className = 'charte-dot';
                dotsWrap.appendChild(d);
            }
            const dots = Array.from(dotsWrap.children);

            function render() {
                pages.forEach((p, i) => p.classList.toggle('active', i === current));
                dots.forEach((d, i) => {
                    d.classList.toggle('seen', seen.has(i));
                    d.classList.toggle('current', i === current);
                });
                progress.textContent = 'Page ' + (current + 1) + ' / ' + total;
                prevBtn.disabled = (current === 0);
                nextBtn.disabled = (current === total - 1);

                if (seen.size === total) {
                    input.disabled = false;
                    label.classList.remove('locked');
                    hint.innerHTML = '✅ Vous avez parcouru toute la charte. Vous pouvez maintenant l\'accepter.';
                    hint.style.color = 'var(--accent)';
                }
            }

            nextBtn.addEventListener('click', function () {
                if (current < total - 1) { current++; seen.add(current); render(); }
            });
            prevBtn.addEventListener('click', function () {
                if (current > 0) { current--; render(); }
            });

            render();
        })();

        // ── Aperçu de la photo de profil ──
        (function () {
            const input = document.getElementById('photo-input');
            const circle = document.getElementById('avatar-circle');
            const preview = document.getElementById('avatar-preview');
            if (!input) return;
            input.addEventListener('change', function (e) {
                const file = e.target.files[0];
                circle.classList.remove('invalid');
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function (ev) {
                        preview.src = ev.target.result;
                        circle.classList.add('has-photo');
                    };
                    reader.readAsDataURL(file);
                } else {
                    preview.removeAttribute('src');
                    circle.classList.remove('has-photo');
                }
            });
            // Accessibilité : ouvrir le sélecteur au clavier
            circle.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
            });
        })();
    </script>
    <script src="modern-select.js"></script>
</body>
</html>
