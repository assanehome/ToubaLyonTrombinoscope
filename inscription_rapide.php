<?php
/**
 * Touba Lyon 2026 - Inscription rapide
 *
 * Version allégée de register.php : seulement les champs essentiels
 * (Civilité, Prénom, Nom, Email, Mot de passe, Téléphone, Statut, Photo).
 * La photo de profil est présentée en haut du formulaire.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/inscription_suivi_helper.php'; // encart de connexion après inscription
require_once __DIR__ . '/email_unique_helper.php'; // une adresse = un seul compte
require_once __DIR__ . '/inscription_verif_helper.php'; // validation de l'e-mail par code après l'inscription
require_once __DIR__ . '/dahira_emails.php'; // e-mail de confirmation d'inscription
require_once __DIR__ . '/rgpd_helper.php'; // consentement RGPD
require_once __DIR__ . '/admin_redirect.php';

$error = '';
$success = '';
$emailInscrit = '';

$civilite = ''; // vide par défaut : le membre doit choisir
$nom = '';
$prenom = '';
$email = '';
$telephone = '';
$statut = '';
$annee_integration = '';
$STATUTS = ['Professionnel', 'Etudiant', 'Alternant'];

// Abandon de l'inscription en cours de validation d'e-mail (« recommencer »)
if (isset($_GET['recommencer'])) {
    verif_annuler($pdo);
    header('Location: inscription_rapide.php');
    exit;
}
// Retour après la saisie du bon code : inscription envoyée, en attente de validation par un administrateur
$etapeCode = false;
$emailVerif = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['verif_termine'])) {
    $emailInscrit = (string) $_SESSION['verif_termine'];
    unset($_SESSION['verif_termine']);
    $success = "Adresse e-mail validée ! Votre inscription est envoyée : un administrateur va la valider prochainement. Un e-mail de confirmation vous a été envoyé.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $civilite = trim($_POST['civilite'] ?? '');
    if ($civilite !== 'Sokhna' && $civilite !== 'Goor Yalla') {
        $civilite = '';
    }
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $telephone = trim($_POST['telephone'] ?? '');
    $statut = trim($_POST['statut'] ?? '');
    $annee_integration = trim($_POST['annee_integration'] ?? '');
    $rgpd = !empty($_POST['rgpd_accepte']);
    $photo = $_FILES['photo'] ?? null;

    if ($civilite === '') {
        $error = "Veuillez choisir votre civilité.";
    } elseif (empty($nom) || empty($prenom) || empty($email) || empty($password)) {
        $error = "Le prénom, le nom, l'email et le mot de passe sont obligatoires.";
    } elseif (strlen($password) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères.";
    } elseif ($password !== $passwordConfirm) {
        $error = "Les deux mots de passe ne sont pas identiques.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "L'adresse email n'est pas valide.";
    } elseif ($telephone === '' || !preg_match('/^[0-9 +().-]{6,30}$/', $telephone)) {
        $error = "Veuillez saisir un numéro de téléphone valide.";
    } elseif (!in_array($statut, $STATUTS, true)) {
        $error = "Veuillez préciser votre statut.";
    } elseif ($annee_integration === '' || !preg_match('/^[0-9]{4}$/', $annee_integration)) {
        $error = "Veuillez saisir une année d'intégration valide (ex: 2022).";
    } elseif (!$rgpd) {
        $error = "Vous devez accepter le traitement de vos données personnelles et de votre photo (RGPD).";
    } elseif (!$photo || $photo['error'] === UPLOAD_ERR_NO_FILE) {
        $error = "La photo de profil est obligatoire.";
    } else {
        try {
            // L'email est-il déjà utilisé ?
            verif_liberer_email($pdo, $email);
            $stmt = $pdo->prepare("SELECT status FROM membres WHERE email = ?");
            $stmt->execute([$email]);
            $existing = $stmt->fetch();

            if (!$existing && email_autre_compte($pdo, $email) !== null) {
                $error = email_autre_compte_message();
            } elseif ($existing) {
                if ($existing['status'] === 'approved') {
                    $error = "Cette adresse email est déjà inscrite dans le Dahira - Mubawwa-A-Sidqin.";
                } elseif ($existing['status'] === 'pending') {
                    $error = "Une demande d'inscription avec cette adresse email est déjà en attente de validation.";
                } else {
                    $error = "Cette adresse email a été refusée. Veuillez contacter un administrateur.";
                }
            } else {
                $newFilename = ''; // photo facultative
                $photoOk = true;

                if ($photo && $photo['error'] !== UPLOAD_ERR_NO_FILE) {
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                    $fileInfo = pathinfo($photo['name']);
                    $extension = strtolower($fileInfo['extension'] ?? '');

                    if (!in_array($extension, $allowedExtensions)) {
                        $error = "Format de photo invalide. Extensions autorisées : JPG, JPEG, PNG, WEBP.";
                        $photoOk = false;
                    } elseif ($photo['size'] > 5 * 1024 * 1024) {
                        $error = "La photo est trop lourde (limite : 5 Mo).";
                        $photoOk = false;
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
                            $photoOk = false;
                        } else {
                            $newFilename = bin2hex(random_bytes(16)) . '.' . $extension;
                            if (!move_uploaded_file($photo['tmp_name'], __DIR__ . '/uploads/' . $newFilename)) {
                                $error = "Erreur lors du transfert de la photo.";
                                $photoOk = false;
                                $newFilename = '';
                            }
                        }
                    }
                }

                if ($photoOk && $error === '') {
                    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                    $genre = ($civilite === 'Sokhna') ? 'Femme' : 'Homme';
                    $stmt = $pdo->prepare("INSERT INTO membres (nom, prenom, civilite, genre, email, photo_path, password, status, type_adhesion, charte_acceptee, telephone, statut, annee_integration, rgpd_accepte, rgpd_date, rgpd_version) VALUES (?, ?, ?, ?, ?, ?, ?, '" . verif_statut_initial($pdo) . "', 'Membre actif', 1, ?, ?, ?, 1, NOW(), ?)");
                    $stmt->execute([$nom, $prenom, $civilite, $genre, $email, $newFilename, $hashedPassword, ($telephone ?: null), ($statut ?: null), ($annee_integration ?: null), RGPD_VERSION]);

                    $etapeCode = verif_apres_insertion($pdo, (int) $pdo->lastInsertId(), 'rapide');
                    if (!$etapeCode) { $success = "Votre inscription a été soumise avec succès ! Un administrateur va la valider prochainement."; $emailInscrit = $email; }
                    $nom = $prenom = $email = $telephone = $statut = $annee_integration = '';
                }
            }
        } catch (Exception $e) {
            error_log('Touba Lyon inscription_rapide: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription rapide - Dahira - Mubawwa-A-Sidqin Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <script src="password-toggle.js" defer></script>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="form-card">
            <h1 class="form-title">Inscription rapide</h1>
            <p style="text-align:center; color:var(--text-muted); margin-top:-0.5rem; margin-bottom:1.5rem;">Renseignez l'essentiel — vous pourrez compléter votre profil plus tard.</p>

            <?php if (empty($success)) { $emailVerif = verif_etat($pdo); $etapeCode = ($emailVerif !== ''); } ?>
            <?php if ($etapeCode) { echo verif_ecran_html($emailVerif, 'inscription_rapide.php'); } ?>
            <?php if (!empty($success)) { echo inscription_login_card_html($emailInscrit, $success); } ?>
            <form action="inscription_rapide.php" method="POST" enctype="multipart/form-data"<?php echo (!empty($success) || $etapeCode) ? ' style="display:none"' : ''; ?>>
                <div class="form-group">
                    <label class="form-label">Photo de profil <span style="color:var(--danger)">*</span></label>
                    <div class="file-upload-wrapper">
                        <div class="file-upload-box" id="upload-box">
                            <svg class="file-upload-icon" width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <p class="file-upload-text" id="upload-text">Glissez-déposez ou <span>parcourez</span> pour choisir une photo</p>
                            <input type="file" id="photo-input" name="photo" class="file-upload-input" accept="image/png, image/jpeg, image/webp" required>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: center;">
                        <img id="photo-preview" class="file-preview" alt="Aperçu de la photo">
                    </div>
                </div>

                <div class="form-group">
                    <label for="civilite" class="form-label">Civilité <span style="color:var(--danger)">*</span></label>
                    <select id="civilite" name="civilite" class="form-input modern-select" required>
                        <option value="" <?php echo ($civilite === '') ? 'selected' : ''; ?>>— Choisir —</option>
                        <option value="Goor Yalla" <?php echo ($civilite === 'Goor Yalla') ? 'selected' : ''; ?>>Goor Yalla (Homme)</option>
                        <option value="Sokhna" <?php echo ($civilite === 'Sokhna') ? 'selected' : ''; ?>>Sokhna (Femme)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="prenom" class="form-label">Prénom <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="prenom" name="prenom" class="form-input" placeholder="Ex: Assane" value="<?php echo htmlspecialchars($prenom); ?>" required>
                </div>

                <div class="form-group">
                    <label for="nom" class="form-label">Nom <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="nom" name="nom" class="form-input" placeholder="Ex: Diop" value="<?php echo htmlspecialchars($nom); ?>" required>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Adresse Email <span style="color:var(--danger)">*</span></label>
                    <input type="email" id="email" name="email" class="form-input" placeholder="Ex: assane@example.com" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Mot de passe <span style="color:var(--danger)">*</span></label>
                    <input type="password" id="password" name="password" class="form-input" placeholder="Au moins 6 caractères" required>
                </div>

                <div class="form-group">
                    <label for="password_confirm" class="form-label">Confirmer le mot de passe <span style="color:var(--danger)">*</span></label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-input" placeholder="Saisissez-le à nouveau" required>
                    <div id="pw-confirm-msg" style="font-size:0.8rem; margin-top:0.3rem; min-height:1em;"></div>
                </div>

                <div class="form-group">
                    <label for="telephone" class="form-label">Téléphone <span style="color:var(--danger)">*</span></label>
                    <input type="tel" id="telephone" name="telephone" class="form-input" value="<?php echo htmlspecialchars($telephone); ?>" required>
                </div>

                <div class="form-group">
                    <label for="statut" class="form-label">Statut <span style="color:var(--danger)">*</span></label>
                    <select id="statut" name="statut" class="form-input modern-select" required>
                        <option value="">— Choisir —</option>
                        <?php foreach ($STATUTS as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo ($statut === $s) ? 'selected' : ''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="annee_integration" class="form-label">Année d'intégration au Dahira <span style="color:var(--danger)">*</span></label>
                    <select id="annee_integration" name="annee_integration" class="form-input modern-select" required>
                        <option value="">— Choisir l'année —</option>
                        <?php for ($y = (int) date('Y'); $y >= 2000; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo ((string) $annee_integration === (string) $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <?php echo rgpd_consent_html(); ?>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Envoyer mon inscription</button>
            </form>
        </div>
    </main>

    <!-- Modern Notification Modal -->
    <?php if (!empty($error) && empty($success)): ?>
    <script>
        (function () {
            var msg = <?php echo json_encode(!empty($success) ? $success : $error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var titre = <?php echo json_encode(!empty($success) ? 'Inscription réussie' : 'Une erreur est survenue', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var opts = {};
            <?php if (!empty($success)): ?>opts.success = true;<?php endif; ?>
            <?php if (!empty($error) && empty($success)): ?>opts.danger = true;<?php endif; ?>
            var onClose = null;
            <?php if (!empty($success)): ?>onClose = function () { window.location.href = 'index.php'; };<?php endif; ?>
            window.modernAlert(msg, titre, onClose, opts);
        })();
    </script>
    <?php endif; ?>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <script>
        const fileInput = document.getElementById('photo-input');
        const uploadBox = document.getElementById('upload-box');
        const uploadText = document.getElementById('upload-text');
        const preview = document.getElementById('photo-preview');

        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                uploadText.innerHTML = `Photo sélectionnée : <strong>${file.name}</strong>`;
                uploadBox.style.borderColor = 'var(--primary)';
                uploadBox.style.background = '#f5f3ff';
                const reader = new FileReader();
                reader.onload = function(event) {
                    preview.src = event.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(file);
            } else {
                uploadText.innerHTML = 'Glissez-déposez ou <span>parcourez</span> pour choisir une photo';
                uploadBox.style.borderColor = 'var(--border)';
                uploadBox.style.background = '#fafafa';
                preview.style.display = 'none';
            }
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            fileInput.addEventListener(eventName, () => {
                uploadBox.style.borderColor = 'var(--primary)';
                uploadBox.style.background = '#f5f3ff';
            });
        });
        ['dragleave', 'drop'].forEach(eventName => {
            fileInput.addEventListener(eventName, () => {
                if(!fileInput.files.length) {
                    uploadBox.style.borderColor = 'var(--border)';
                    uploadBox.style.background = '#fafafa';
                }
            });
        });
    </script>
    <script src="modern-select.js"></script>
    <script>
        // Confirmation du mot de passe : vérification en direct
        (function () {
            var pw = document.getElementById('password');
            var pc = document.getElementById('password_confirm');
            var msg = document.getElementById('pw-confirm-msg');
            if (!pw || !pc || !msg) { return; }
            function verifier() {
                if (pc.value === '') { msg.textContent = ''; pc.style.borderColor = ''; return; }
                if (pc.value === pw.value) {
                    msg.textContent = '✓ Les mots de passe sont identiques';
                    msg.style.color = '#7bd8a6';
                    pc.style.borderColor = 'rgba(37,211,102,0.6)';
                } else {
                    msg.textContent = '✗ Les mots de passe ne correspondent pas';
                    msg.style.color = '#fca5a5';
                    pc.style.borderColor = 'rgba(191,33,33,0.7)';
                }
            }
            pw.addEventListener('input', verifier);
            pc.addEventListener('input', verifier);
            var form = pc.closest('form');
            if (form) {
                form.addEventListener('submit', function (e) {
                    if (pc.value !== pw.value) {
                        e.preventDefault();
                        verifier();
                        pc.focus();
                    }
                });
            }
        })();
    </script>
<script src="modern-file.js" defer></script>
<script>window.TROBA_EMAIL_ERREUR = <?php echo json_encode((!empty($error) && empty($success) && stripos((string) $error, 'adresse email') !== false) ? $error : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="email-check.js" defer></script>
</body>
</html>
