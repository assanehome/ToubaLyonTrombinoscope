<?php
/**
 * Touba Lyon 2026 - Registration Page
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';

$error = '';
$success = '';

$civilite = 'Goor Yalla';
$nom = '';
$prenom = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $civilite = trim($_POST['civilite'] ?? 'Goor Yalla');
    if ($civilite !== 'Sokhna' && $civilite !== 'Goor Yalla') {
        $civilite = 'Goor Yalla';
    }
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $photo = $_FILES['photo'] ?? null;

    if (empty($nom) || empty($prenom) || empty($email) || empty($password)) {
        $error = "Tous les champs texte et mot de passe sont obligatoires.";
    } elseif (strlen($password) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "L'adresse email n'est pas valide.";
    } elseif (!$photo || $photo['error'] === UPLOAD_ERR_NO_FILE) {
        $error = "La photo de profil est obligatoire.";
    } else {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT status FROM membres WHERE email = ?");
            $stmt->execute([$email]);
            $existing = $stmt->fetch();

            if ($existing) {
                if ($existing['status'] === 'approved') {
                    $error = "Cette adresse email est déjà inscrite dans le Trombinoscope.";
                } elseif ($existing['status'] === 'pending') {
                    $error = "Une demande d'inscription avec cette adresse email est déjà en attente de validation.";
                } else {
                    $error = "Cette adresse email a été refusée. Veuillez contacter un administrateur.";
                }
            } else {
                // File check
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                $fileInfo = pathinfo($photo['name']);
                $extension = strtolower($fileInfo['extension'] ?? '');

                if (!in_array($extension, $allowedExtensions)) {
                    $error = "Format de photo invalide. Extensions autorisées : JPG, JPEG, PNG, WEBP.";
                } else {
                    // Size limit check: 5MB
                    $maxSize = 5 * 1024 * 1024;
                    if ($photo['size'] > $maxSize) {
                        $error = "La photo est trop lourde (limite : 5 Mo).";
                    } else {
                        // Check mime type (server-side check)
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
                            // Generate clean, secure filename
                            $newFilename = bin2hex(random_bytes(16)) . '.' . $extension;
                            $destination = __DIR__ . '/uploads/' . $newFilename;

                            if (move_uploaded_file($photo['tmp_name'], $destination)) {
                                // Save to DB
                                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                                $stmt = $pdo->prepare("INSERT INTO membres (nom, prenom, civilite, email, photo_path, password, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                                $stmt->execute([$nom, $prenom, $civilite, $email, $newFilename, $hashedPassword]);
                                
                                $success = "Votre inscription a été soumise avec succès ! Un administrateur va la valider prochainement.";
                                
                                // Reset variables
                                $nom = '';
                                $prenom = '';
                                $email = '';
                            } else {
                                $error = "Erreur lors du transfert de la photo.";
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Touba Lyon register: ' . $e->getMessage());
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
    <title>Inscription - Trombinoscope Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="form-card">
            <h1 class="form-title">Rejoindre le Trombinoscope</h1>


            <form action="register.php" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="civilite" class="form-label">Civilité <span style="color:var(--danger)">*</span></label>
                    <select id="civilite" name="civilite" class="form-input modern-select" required>
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
                    <label for="password" class="form-label">Mot de passe (pour jouer à Ki Kan La) <span style="color:var(--danger)">*</span></label>
                    <input type="password" id="password" name="password" class="form-input" placeholder="Au moins 6 caractères" required>
                </div>

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

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Envoyer mon inscription</button>
            </form>
        </div>
    </main>

    <!-- Modern Notification Modal -->
    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display: flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($success)): ?>
                    <h3 class="gold-text">Inscription Réussie</h3>
                <?php else: ?>
                    <h3 style="color: var(--danger);">Une erreur est survenue</h3>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <p>
                    <?php 
                        if (!empty($success)) {
                            echo htmlspecialchars($success);
                        } else {
                            echo htmlspecialchars($error);
                        }
                    ?>
                </p>
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

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <script>
        function closeNotificationModal() {
            const modal = document.getElementById('notification-modal');
            if (modal) {
                modal.classList.remove('active');
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 300);
            }
        }
        const fileInput = document.getElementById('photo-input');
        const uploadBox = document.getElementById('upload-box');
        const uploadText = document.getElementById('upload-text');
        const preview = document.getElementById('photo-preview');

        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Show name in upload box
                uploadText.innerHTML = `Photo sélectionnée : <strong>${file.name}</strong>`;
                uploadBox.style.borderColor = 'var(--primary)';
                uploadBox.style.background = '#f5f3ff';
                
                // Show preview
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

        // Highlight upload box on dragover
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
</body>
</html>
