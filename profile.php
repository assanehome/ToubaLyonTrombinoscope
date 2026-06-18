<?php
/**
 * Touba Lyon 2026 - Member Profile Page
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not authenticated
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}

$playerId = $_SESSION['player_id'];
$error = '';
$success = '';

// Retrieve current player's data
try {
    $stmt = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
    $stmt->execute([$playerId]);
    $member = $stmt->fetch();
    
    if (!$member) {
        // Log out if member record doesn't exist anymore
        header('Location: play_logout.php');
        exit;
    }
} catch (Exception $e) {
    error_log('Touba Lyon profile (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

$civilite = $member['civilite'];
$nom = $member['nom'];
$prenom = $member['prenom'];
$email = $member['email'];

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

    if (empty($nom) || empty($prenom) || empty($email)) {
        $error = "Les champs Civilité, Prénom, Nom et Adresse Email sont obligatoires.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "L'adresse email n'est pas valide.";
    } elseif (!empty($password) && strlen($password) < 6) {
        $error = "Le nouveau mot de passe doit contenir au moins 6 caractères.";
    } else {
        try {
            // Check if email already exists for another member
            $stmt = $pdo->prepare("SELECT id FROM membres WHERE email = ? AND id != ?");
            $stmt->execute([$email, $playerId]);
            $existing = $stmt->fetch();

            if ($existing) {
                $error = "Cette adresse email est déjà utilisée par un autre membre.";
            } else {
                $newFilename = null;
                $photoUploaded = false;

                // Handle optional photo upload
                if ($photo && $photo['error'] !== UPLOAD_ERR_NO_FILE) {
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
                            // Mime type check
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
                                    $photoUploaded = true;
                                } else {
                                    $error = "Erreur lors du transfert de la nouvelle photo.";
                                }
                            }
                        }
                    }
                }

                if (empty($error)) {
                    // Check if identity details or photo were updated
                    $identityChanged = ($civilite !== $member['civilite'] || $nom !== $member['nom'] || $prenom !== $member['prenom'] || $email !== $member['email'] || $photoUploaded);
                    
                    // Build update query
                    $sql = "UPDATE membres SET civilite = ?, nom = ?, prenom = ?, email = ?";
                    $params = [$civilite, $nom, $prenom, $email];

                    if ($photoUploaded) {
                        $sql .= ", photo_path = ?";
                        $params[] = $newFilename;
                    }

                    if (!empty($password)) {
                        $sql .= ", password = ?";
                        $params[] = password_hash($password, PASSWORD_BCRYPT);
                    }

                    if ($identityChanged) {
                        $sql .= ", status = 'pending'";
                    }

                    $sql .= " WHERE id = ?";
                    $params[] = $playerId;

                    $stmtUpdate = $pdo->prepare($sql);
                    $stmtUpdate->execute($params);

                    // If photo was updated, delete old photo file to save space
                    if ($photoUploaded && !empty($member['photo_path'])) {
                        $oldPhotoPath = __DIR__ . '/uploads/' . $member['photo_path'];
                        if (file_exists($oldPhotoPath) && $member['photo_path'] !== 'default.jpg') {
                            @unlink($oldPhotoPath);
                        }
                    }

                    if ($identityChanged) {
                        // Destroy session and redirect to login page with notice parameter
                        session_destroy();
                        header('Location: login.php?pending_validation=1');
                        exit;
                    } else {
                        $success = "Vos modifications ont été enregistrées avec succès.";
                        
                        // Reload member details
                        $stmtReload = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
                        $stmtReload->execute([$playerId]);
                        $member = $stmtReload->fetch();
                        
                        $civilite = $member['civilite'];
                        $nom = $member['nom'];
                        $prenom = $member['prenom'];
                        $email = $member['email'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Touba Lyon profile (update): ' . $e->getMessage());
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
    <title>Mon Profil - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .profile-container {
            max-width: 650px;
            margin: 2rem auto;
        }
        .profile-header-card {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding: 2rem;
        }
        .profile-large-photo-wrap {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--accent);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
            flex-shrink: 0;
        }
        .profile-large-photo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-title-info h2 {
            font-size: 1.8rem;
            color: var(--white);
            margin-bottom: 0.25rem;
        }
        .profile-status-badge {
            display: inline-block;
            background: rgba(45, 106, 79, 0.2);
            color: #b7e4c7;
            border: 1px solid rgba(45, 106, 79, 0.5);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.75rem;
            border-radius: 50px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .info-warning-box {
            background: rgba(212, 175, 55, 0.08);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }
        .info-warning-icon {
            font-size: 1.5rem;
            line-height: 1;
        }
        .info-warning-text {
            font-size: 0.92rem;
            color: var(--text-muted);
            line-height: 1.5;
        }
        .info-warning-text strong {
            color: var(--gold);
        }
        @media (max-width: 550px) {
            .profile-header-card {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
                padding: 1.5rem;
            }
            .info-warning-box {
                padding: 1rem;
                gap: 0.75rem;
            }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="profile-container">
            
            <!-- Link back to Trombinoscope -->
            <div style="margin-bottom: 1.5rem;">
                <a href="index.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; transition: var(--transition);" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-muted)'">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"></path></svg>
                    Retour au Trombinoscope
                </a>
            </div>

            <!-- Profile Summary Card -->
            <div class="profile-header-card glass-card">
                <div class="profile-large-photo-wrap">
                    <img src="uploads/<?php echo htmlspecialchars($member['photo_path']); ?>" alt="Photo de <?php echo htmlspecialchars($member['prenom']); ?>">
                </div>
                <div class="profile-title-info">
                    <h2><?php echo htmlspecialchars($member['prenom'] . ' ' . $member['nom']); ?></h2>
                    <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 0.75rem;"><?php echo htmlspecialchars($member['email']); ?></p>
                    <span class="profile-status-badge">Compte Validé</span>
                </div>
            </div>

            <!-- Informational Warning Banner -->
            <div class="info-warning-box">
                <span class="info-warning-icon">⚠️</span>
                <p class="info-warning-text">
                    <strong>Attention :</strong> Toute modification de vos données d'identité (votre nom, prénom, civilité, ou votre photo de profil) nécessite une <strong>nouvelle validation administrative</strong>. Si vous modifiez ces champs, votre compte sera suspendu temporairement et vous serez déconnecté jusqu'à ce qu'un administrateur valide vos modifications.
                </p>
            </div>

            <!-- Edit Profile Form -->
            <div class="form-card" style="margin-top: 0; padding: 2.5rem 2rem;">
                <h3 class="gold-text" style="font-size: 1.4rem; font-weight: 700; margin-bottom: 1.5rem; text-align: center;">Modifier mes Informations</h3>

                <form action="profile.php" method="POST" enctype="multipart/form-data">
                    
                    <div class="form-group">
                        <label for="civilite" class="form-label">Civilité <span style="color:var(--danger)">*</span></label>
                        <select id="civilite" name="civilite" class="form-input modern-select" required>
                            <option value="Goor Yalla" <?php echo ($civilite === 'Goor Yalla') ? 'selected' : ''; ?>>Goor Yalla (Homme)</option>
                            <option value="Sokhna" <?php echo ($civilite === 'Sokhna') ? 'selected' : ''; ?>>Sokhna (Femme)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="prenom" class="form-label">Prénom <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="prenom" name="prenom" class="form-input" placeholder="Votre prénom" value="<?php echo htmlspecialchars($prenom); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="nom" class="form-label">Nom <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="nom" name="nom" class="form-input" placeholder="Votre nom" value="<?php echo htmlspecialchars($nom); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">Adresse Email <span style="color:var(--danger)">*</span></label>
                        <input type="email" id="email" name="email" class="form-input" placeholder="Votre adresse email" value="<?php echo htmlspecialchars($email); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">Nouveau mot de passe <span style="color:var(--text-muted); font-size: 0.8rem;">(laisser vide pour ne pas changer)</span></label>
                        <input type="password" id="password" name="password" class="form-input" placeholder="Au moins 6 caractères">
                    </div>

                    <div class="form-group" style="margin-bottom: 2rem;">
                        <label class="form-label">Remplacer la photo de profil <span style="color:var(--text-muted); font-size: 0.8rem;">(laisser vide pour conserver l'actuelle)</span></label>
                        <div class="file-upload-wrapper">
                            <div class="file-upload-box" id="upload-box">
                                <svg class="file-upload-icon" width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                <p class="file-upload-text" id="upload-text">Glissez-déposez ou <span>parcourez</span> pour choisir une nouvelle photo</p>
                                <input type="file" id="photo-input" name="photo" class="file-upload-input" accept="image/png, image/jpeg, image/webp">
                            </div>
                        </div>
                        <div style="display: flex; justify-content: center;">
                            <img id="photo-preview" class="file-preview" alt="Aperçu de la photo">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">Enregistrer les modifications</button>
                </form>
            </div>

        </div>
    </main>

    <!-- Success/Error Notifications Modal -->
    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display: flex;">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <?php if (!empty($success)): ?>
                    <h3 class="gold-text">Mise à jour réussie</h3>
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
                <button onclick="closeNotificationModal()" class="btn btn-primary btn-sm">OK</button>
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
                uploadText.innerHTML = `Nouvelle photo : <strong>${file.name}</strong>`;
                uploadBox.style.borderColor = 'var(--primary)';
                uploadBox.style.background = '#f5f3ff';
                
                const reader = new FileReader();
                reader.onload = function(event) {
                    preview.src = event.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(file);
            } else {
                uploadText.innerHTML = 'Glissez-déposez ou <span>parcourez</span> pour choisir une nouvelle photo';
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
</body>
</html>
