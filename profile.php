<?php
/**
 * Touba Lyon 2026 - Member Profile Page
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/send_mail.php';
require_once __DIR__ . '/contact.php';
require_once __DIR__ . '/dahira_emails.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mode admin lecture seule : un administrateur peut consulter la fiche d'un membre via ?id=.
// Sinon confinement admin (redirection tableau de bord) et exigence de session membre.
$adminView = false;
if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    if (isset($_GET['id'])) {
        $adminView = true;
    } else {
        header('Location: admin_dashboard.php');
        exit;
    }
} elseif (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}
$readonly = $adminView;
$playerId = $adminView ? (int)$_GET['id'] : $_SESSION['player_id'];
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
$adresse = $member['adresse'] ?? '';
$code_postal = $member['code_postal'] ?? '';
$commune = $member['commune'] ?? '';
$telephone = $member['telephone'] ?? '';
$statut = $member['statut'] ?? '';
$secteur_activite = $member['secteur_activite'] ?? '';
$profession = $member['profession'] ?? '';
$annee_integration = $member['annee_integration'] ?? '';
$commentaires = $member['commentaires'] ?? '';
$souhait_commission = $member['souhait_commission'] ?? '';
$STATUTS = ['Professionnel', 'Etudiant', 'Alternant'];
try { $commissionsList = $pdo->query("SELECT nom FROM commissions ORDER BY nom ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $commissionsList = []; }
try { $secteursList = $pdo->query("SELECT nom FROM secteurs ORDER BY nom ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $secteursList = []; }

// Intégrateur en charge (pour le suivi affiché aux membres en attente)
$integrateur = null;
if (!empty($member['integrateur_id'])) {
    try {
        $stmtI = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(prenom,''), ' ', nom)) AS nom, email, telephone FROM membres WHERE id = ?");
        $stmtI->execute([$member['integrateur_id']]);
        $integrateur = $stmtI->fetch();
    } catch (Exception $e) {
        $integrateur = null;
    }
}
$waI = $integrateur ? wa_number($integrateur['telephone'] ?? '') : '';

if (!$adminView && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact_integrateur') {
    // Message du membre vers son intégrateur en charge
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } elseif (!$integrateur || empty($integrateur['email'])) {
        $error = "Aucun intégrateur ne vous est assigné pour le moment.";
    } else {
        $subject = trim($_POST['mail_subject'] ?? '');
        $body = trim($_POST['mail_body'] ?? '');
        if ($subject === '' || $body === '') {
            $error = "L'objet et le message sont obligatoires.";
        } else {
            try {
                $statutFr = ['approved' => 'Validé', 'pending' => 'En attente de validation', 'rejected' => 'Refusé'];
                $htmlBody = dahira_email_wrap(
                    '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Message d\'un membre à suivre</h1>'
                    . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($integrateur['nom']) . '</strong>,</p>'
                    . '<p style="margin:0 0 16px;">Vous avez reçu un message de <strong>' . htmlspecialchars($member['prenom'] . ' ' . $member['nom']) . '</strong>, '
                    . 'dont vous assurez le suivi d\'intégration au Dahira Touba Lyon. Voici ses coordonnées :</p>'
                    . dahira_email_credentials([
                        'Membre' => htmlspecialchars($member['prenom'] . ' ' . $member['nom']),
                        'E-mail' => htmlspecialchars($member['email']),
                        'Téléphone' => htmlspecialchars($member['telephone'] ?: '—'),
                        'Commune' => htmlspecialchars($member['commune'] ?: '—'),
                        'Souhait commission' => htmlspecialchars($member['souhait_commission'] ?: '—'),
                        'Statut du dossier' => htmlspecialchars($statutFr[$member['status']] ?? $member['status']),
                    ])
                    . '<p style="margin:16px 0 8px;font-weight:bold;color:#1b4332;">Son message :</p>'
                    . '<div style="white-space:pre-wrap;background:#f6f7f6;border-left:4px solid #1b4332;border-radius:10px;padding:14px 18px;margin:0 0 18px;">' . nl2br(htmlspecialchars($body)) . '</div>'
                    . '<p style="margin:0;">Merci de prendre contact avec ce membre afin de poursuivre son intégration. '
                    . 'Vous pouvez lui répondre directement par e-mail (' . htmlspecialchars($member['email']) . ')'
                    . (!empty($member['telephone']) ? ' ou par téléphone (' . htmlspecialchars($member['telephone']) . ')' : '') . '.</p>',
                    'Message membre'
                );
                $sent = @send_smtp_mail($integrateur['email'], $integrateur['nom'], $subject, $htmlBody);
                $success = $sent ? "Votre message a été envoyé à votre intégrateur." : "Le message n'a pas pu être envoyé. Veuillez réessayer plus tard.";
            } catch (Exception $e) {
                error_log('Touba Lyon profile (contact integrateur): ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
} elseif (!$adminView && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $civilite = trim($_POST['civilite'] ?? 'Goor Yalla');
    if ($civilite !== 'Sokhna' && $civilite !== 'Goor Yalla') {
        $civilite = 'Goor Yalla';
    }
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $photo = $_FILES['photo'] ?? null;
    $adresse = trim($_POST['adresse'] ?? '');
    $code_postal = trim($_POST['code_postal'] ?? '');
    $commune = trim($_POST['commune'] ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $statut = trim($_POST['statut'] ?? '');
    $secteur_activite = trim($_POST['secteur_activite'] ?? '');
    $profession = trim($_POST['profession'] ?? '');
    $annee_integration = trim($_POST['annee_integration'] ?? '');
    $commentaires = trim($_POST['commentaires'] ?? '');
    $souhait_commission = trim($_POST['souhait_commission'] ?? '');

    if (empty($nom) || empty($prenom) || empty($email)) {
        $error = "Les champs Civilité, Prénom, Nom et Adresse Email sont obligatoires.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "L'adresse email n'est pas valide.";
    } elseif (!empty($password) && strlen($password) < 6) {
        $error = "Le nouveau mot de passe doit contenir au moins 6 caractères.";
    } elseif ($code_postal === '' || !preg_match('/^[0-9]{4,9}$/', $code_postal)) {
        $error = "Veuillez saisir un code postal valide.";
    } elseif ($commune === '') {
        $error = "La commune est obligatoire.";
    } elseif ($telephone === '' || !preg_match('/^[0-9 +().-]{6,30}$/', $telephone)) {
        $error = "Veuillez saisir un numéro de téléphone valide.";
    } elseif (!in_array($statut, $STATUTS, true)) {
        $error = "Veuillez préciser votre statut.";
    } elseif ($secteur_activite === '') {
        $error = "Le secteur d'activité est obligatoire.";
    } elseif ($profession === '') {
        $error = "La profession est obligatoire.";
    } elseif ($annee_integration === '' || !preg_match('/^[0-9]{4}$/', $annee_integration)) {
        $error = "Veuillez saisir une année d'intégration valide (ex: 2022).";
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
                    $sql = "UPDATE membres SET civilite = ?, nom = ?, prenom = ?, email = ?, adresse = ?, code_postal = ?, commune = ?, telephone = ?, statut = ?, secteur_activite = ?, profession = ?, annee_integration = ?, commentaires = ?, souhait_commission = ?";
                    $params = [$civilite, $nom, $prenom, $email, ($adresse ?: null), $code_postal, $commune, $telephone, $statut, $secteur_activite, $profession, $annee_integration, ($commentaires ?: null), ($souhait_commission ?: null)];

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
                        $success = "Vos modifications ont été enregistrées. Votre compte est de nouveau en attente de validation par un administrateur.";
                    } else {
                        $success = "Vos modifications ont été enregistrées avec succès.";
                    }

                    // Reload member details (statut potentiellement repassé en 'pending')
                    $stmtReload = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
                    $stmtReload->execute([$playerId]);
                    $member = $stmtReload->fetch();
                    $civilite = $member['civilite'];
                    $nom = $member['nom'];
                    $prenom = $member['prenom'];
                    $email = $member['email'];
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
        /* ── Modale e-mail (UI moderne) ── */
        .mailx-overlay { align-items:center; justify-content:center; }
        .mailx-card { width:100%; max-width:520px; background:linear-gradient(180deg,#123528 0%, #0c241a 100%); border:1px solid rgba(212,175,55,0.25); border-radius:22px; overflow:hidden; box-shadow:0 30px 80px rgba(0,0,0,0.55); transform:translateY(14px) scale(0.98); opacity:0; transition:transform .28s cubic-bezier(.2,.8,.2,1), opacity .28s ease; }
        .modal-overlay.active .mailx-card { transform:translateY(0) scale(1); opacity:1; }
        .mailx-header { display:flex; align-items:center; gap:0.9rem; padding:1.4rem 1.5rem; background:linear-gradient(135deg,#1b4332 0%, #2d6a4f 100%); border-bottom:1px solid rgba(212,175,55,0.25); position:relative; }
        .mailx-header-icon { width:44px; height:44px; border-radius:14px; background:rgba(212,175,55,0.18); color:#f2d574; display:flex; align-items:center; justify-content:center; flex-shrink:0; border:1px solid rgba(212,175,55,0.35); }
        .mailx-title { font-size:1.15rem; font-weight:700; color:#fff; margin:0; }
        .mailx-subtitle { font-size:0.8rem; color:#b7d4c5; margin:0.1rem 0 0; }
        .mailx-close { position:absolute; top:0.85rem; right:1rem; background:rgba(255,255,255,0.1); border:none; color:#fff; width:30px; height:30px; border-radius:50%; font-size:1.2rem; line-height:1; cursor:pointer; transition:background .2s ease; }
        .mailx-close:hover { background:rgba(255,255,255,0.25); }
        .mailx-body { padding:1.4rem 1.5rem 0.5rem; }
        .mailx-recipient { display:flex; align-items:center; gap:0.6rem; background:rgba(255,255,255,0.05); border:1px solid var(--glass-border); border-radius:12px; padding:0.55rem 0.75rem; margin-bottom:1.1rem; }
        .mailx-recipient-label { font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; }
        .mailx-avatar { width:30px; height:30px; border-radius:50%; background:linear-gradient(135deg,#d4af37,#b8902f); color:#0c241a; font-weight:800; font-size:0.8rem; display:flex; align-items:center; justify-content:center; text-transform:uppercase; flex-shrink:0; }
        .mailx-recipient-name { color:#fff; font-weight:600; font-size:0.95rem; }
        .mailx-field { margin-bottom:1.1rem; }
        .mailx-field label { display:block; font-size:0.75rem; color:#f2d574; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; margin-bottom:0.4rem; }
        .mailx-field input, .mailx-field textarea { width:100%; padding:0.8rem 1rem; background:rgba(255,255,255,0.06); border:1px solid var(--glass-border); border-radius:12px; color:#fff; font-size:0.95rem; font-family:inherit; transition:border-color .2s ease, box-shadow .2s ease; }
        .mailx-field textarea { resize:vertical; min-height:120px; line-height:1.5; }
        .mailx-field input:focus, .mailx-field textarea:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(212,175,55,0.15); }
        .mailx-hint { display:block; font-size:0.72rem; color:var(--text-muted); margin-top:0.4rem; }
        .mailx-footer { display:flex; justify-content:flex-end; gap:0.6rem; padding:0.6rem 1.5rem 1.4rem; }
        .mailx-btn { display:inline-flex; align-items:center; gap:0.45rem; padding:0.7rem 1.4rem; border-radius:12px; font-size:0.9rem; font-weight:700; cursor:pointer; border:1px solid transparent; transition:transform .15s ease, background .2s ease, box-shadow .2s ease; }
        .mailx-btn:active { transform:scale(0.97); }
        .mailx-btn-ghost { background:transparent; border-color:var(--glass-border); color:var(--text-muted); }
        .mailx-btn-ghost:hover { color:#fff; border-color:rgba(255,255,255,0.3); }
        .mailx-btn-send { background:linear-gradient(135deg,#d4af37,#c49a2c); color:#0c241a; box-shadow:0 8px 22px rgba(212,175,55,0.3); }
        .mailx-btn-send:hover { box-shadow:0 10px 28px rgba(212,175,55,0.45); }
        .mailx-btn-wa { background:linear-gradient(135deg,#25D366,#1aa851); color:#053b21; box-shadow:0 8px 22px rgba(37,211,102,0.3); }
        .mailx-btn-wa:hover { box-shadow:0 10px 28px rgba(37,211,102,0.45); }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <?php if (!$adminView): ?>
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="dashboard-main">
        <?php endif; ?>
        <div class="profile-container">
            
            <!-- Link back -->
            <div style="margin-bottom: 1.5rem;">
                <a href="<?php echo $adminView ? 'admin_adhesions.php' : 'index.php'; ?>" style="color: var(--text-muted); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; transition: var(--transition);" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-muted)'">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"></path></svg>
                    <?php echo $adminView ? 'Retour : Inscriptions Dahira' : 'Retour au Dahira - Mubawwa-A-Sidqin'; ?>
                </a>
            </div>

            <?php if ($readonly): ?>
            <style>
                .profile-view { display:flex; flex-direction:column; gap:1.5rem; }
                .pv-section h4 { color:var(--gold); font-size:0.8rem; text-transform:uppercase; letter-spacing:0.05em; font-weight:700; margin:0 0 0.5rem; padding-bottom:0.5rem; border-bottom:1px solid rgba(212,175,55,0.25); }
                .pv-row { display:flex; align-items:baseline; justify-content:space-between; gap:1rem; padding:0.6rem 0; border-bottom:1px solid rgba(255,255,255,0.06); }
                .pv-row:last-child { border-bottom:none; }
                .pv-k { color:var(--text-muted); font-size:0.85rem; flex-shrink:0; }
                .pv-v { color:var(--white); font-size:0.95rem; font-weight:600; text-align:right; word-break:break-word; }
                @media (max-width:520px){ .pv-row { flex-direction:column; gap:0.15rem; } .pv-v { text-align:left; } }
            </style>
            <div class="glass-card" style="display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:0.7rem 1rem; margin-bottom:1.25rem; border-left:3px solid var(--gold);">
                <span style="color:var(--text-muted); font-size:0.9rem;">👁️ Profil en lecture seule (consultation administrateur)</span>
                <a href="membre.php?id=<?php echo (int)$playerId; ?>" class="btn btn-primary btn-sm">✏️ Modifier</a>
            </div>
            <?php endif; ?>

            <!-- Profile Summary Card -->
            <div class="profile-header-card glass-card">
                <div class="profile-large-photo-wrap">
                    <?php if (!empty($member['photo_path'])): ?>
                        <img src="uploads/<?php echo htmlspecialchars($member['photo_path']); ?>" alt="Photo de <?php echo htmlspecialchars($member['prenom']); ?>">
                    <?php else: ?>
                        <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:2.4rem; font-weight:700; color:var(--gold); background:#081c15;"><?php echo htmlspecialchars(strtoupper(mb_substr($member['prenom'] ?? '?', 0, 1))); ?></div>
                    <?php endif; ?>
                </div>
                <div class="profile-title-info">
                    <h2><?php echo htmlspecialchars($member['prenom'] . ' ' . $member['nom']); ?></h2>
                    <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 0.75rem;"><?php echo htmlspecialchars($member['email']); ?></p>
                    <?php if ($member['status'] === 'approved'): ?>
                        <span class="profile-status-badge">Compte Validé</span>
                    <?php else: ?>
                        <span class="profile-status-badge" style="background:rgba(212,175,55,0.2); color:#ffd966; border-color:rgba(212,175,55,0.5);">⏳ En attente de validation</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($member['status'] !== 'approved'): ?>
            <div style="background:rgba(212,175,55,0.12); border:1px solid rgba(212,175,55,0.4); border-radius:16px; padding:1.25rem 1.5rem; margin-bottom:1.5rem; display:flex; gap:1rem; align-items:flex-start;">
                <span style="font-size:1.5rem; line-height:1;">⏳</span>
                <p style="color:var(--text-muted); font-size:0.95rem; line-height:1.5; margin:0;">
                    <strong style="color:var(--gold);">Compte en attente de validation.</strong> Votre inscription (ou vos dernières modifications) doit être validée par un administrateur avant que vous puissiez accéder au Dahira - Mubawwa-A-Sidqin et au jeu Ki Kan La. Vous pouvez consulter et modifier votre profil en attendant.
                </p>
            </div>

            <!-- Suivi intégration (lecture seule) -->
            <div class="glass-card" style="border-radius:16px; padding:1.5rem; margin-bottom:1.5rem;">
                <h3 class="gold-text" style="font-size:1.15rem; font-weight:700; margin-bottom:1rem;">🧭 Votre suivi d'intégration</h3>
                <?php if ($integrateur): ?>
                    <p style="color:var(--text-muted); font-size:0.92rem; line-height:1.6; margin:0 0 1rem;">
                        Un intégrateur vous accompagne dans vos démarches d'adhésion. N'hésitez pas à le contacter pour toute question.
                    </p>
                    <?php
                        $dash = '<span style="color:var(--text-muted);">—</span>';
                        $suiviRows = [
                            'Intégrateur en charge (assignation)' => htmlspecialchars($integrateur['nom']),
                            'Souhait commission' => ($member['souhait_commission'] !== null && $member['souhait_commission'] !== '') ? htmlspecialchars($member['souhait_commission']) : $dash,
                            'Présentation Ok / non OK' => ($member['presentation_ok'] !== null && $member['presentation_ok'] !== '') ? htmlspecialchars($member['presentation_ok']) : $dash,
                            'Test Kourel' => ($member['test_kourel'] !== null && $member['test_kourel'] !== '') ? htmlspecialchars($member['test_kourel']) : $dash,
                        ];
                    ?>
                    <div style="display:flex; flex-direction:column; gap:0.6rem; font-size:0.95rem;">
                        <?php foreach ($suiviRows as $label => $value): ?>
                        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                            <span style="color:var(--text-muted); min-width:210px;"><?php echo $label; ?></span>
                            <strong style="color:var(--white);"><?php echo $value; ?></strong>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!empty($integrateur['email'])): ?>
                        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                            <span style="color:var(--text-muted); min-width:210px;">Contact intégrateur</span>
                            <a href="mailto:<?php echo htmlspecialchars($integrateur['email']); ?>" style="color:var(--accent); font-weight:600; text-decoration:none;"><?php echo htmlspecialchars($integrateur['email']); ?></a>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($integrateur['telephone'])): ?>
                        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                            <span style="color:var(--text-muted); min-width:210px;">Téléphone intégrateur</span>
                            <strong style="color:var(--white);"><?php echo htmlspecialchars($integrateur['telephone']); ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php
                        $waMsg = rawurlencode('Assalamu aleykum ' . $integrateur['nom'] . ', ');
                        $waHref = $waI ? 'https://wa.me/' . $waI . '?text=' . $waMsg : 'https://wa.me/?text=' . $waMsg;
                    ?>
                    <?php if (!$readonly): ?>
                    <div style="display:flex; gap:0.6rem; flex-wrap:wrap; margin-top:1.25rem;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="openIntegMail()">✉️ Envoyer un e-mail</button>
                        <a href="<?php echo $waHref; ?>" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700; box-shadow:none;">🟢 Message WhatsApp</a>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.92rem; line-height:1.6; margin:0;">
                        Aucun intégrateur ne vous a encore été assigné. Un membre du secrétariat prendra bientôt contact avec vous.
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Edit Profile Form -->
            <div class="form-card" style="margin-top: 0; padding: 2.5rem 2rem;">
                <h3 class="gold-text" style="font-size: 1.4rem; font-weight: 700; margin-bottom: 1.5rem; text-align: center;"><?php echo $readonly ? 'Informations du membre' : 'Modifier mes Informations'; ?></h3>

                <?php if ($readonly): ?>
                <?php
                    $dispSections = [
                        'Identité' => [
                            'Civilité' => $civilite,
                            'Prénom' => $prenom,
                            'Nom' => $nom,
                        ],
                        'Coordonnées' => [
                            'Email' => $email,
                            'Téléphone' => $telephone,
                            'Adresse' => $adresse,
                            'Code postal' => $code_postal,
                            'Commune' => $commune,
                        ],
                        'Adhésion & profil' => [
                            'Statut (activité)' => $statut,
                            "Secteur d'activité" => $secteur_activite,
                            'Profession' => $profession,
                            "Année d'intégration" => $annee_integration,
                            'Souhait commission' => $souhait_commission,
                            'Commentaires' => $commentaires,
                        ],
                    ];
                ?>
                <div class="profile-view">
                    <?php foreach ($dispSections as $secTitle => $rows): ?>
                        <div class="pv-section">
                            <h4><?php echo $secTitle; ?></h4>
                            <?php foreach ($rows as $lab => $val): ?>
                                <div class="pv-row"><span class="pv-k"><?php echo $lab; ?></span><span class="pv-v"><?php echo ($val !== null && $val !== '') ? nl2br(htmlspecialchars($val)) : '<span style="color:var(--text-muted);">—</span>'; ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>

                <form action="profile.php" method="POST" enctype="multipart/form-data">
                    <fieldset style="border:0; padding:0; margin:0; min-width:0;">

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
                        <label for="adresse" class="form-label">Adresse</label>
                        <input type="text" id="adresse" name="adresse" class="form-input" value="<?php echo htmlspecialchars($adresse); ?>">
                    </div>

                    <div class="form-group">
                        <label for="code_postal" class="form-label">Code postal <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="code_postal" name="code_postal" class="form-input" inputmode="numeric" value="<?php echo htmlspecialchars($code_postal); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="commune" class="form-label">Commune <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="commune" name="commune" class="form-input" value="<?php echo htmlspecialchars($commune); ?>" required>
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
                        <label for="secteur_activite" class="form-label">Secteur d'activité <span style="color:var(--danger)">*</span></label>
                        <select id="secteur_activite" name="secteur_activite" class="form-input modern-select" required>
                            <option value="">— Choisir un secteur —</option>
                            <?php foreach ($secteursList as $sName): ?>
                                <option value="<?php echo htmlspecialchars($sName); ?>" <?php echo ($secteur_activite === $sName) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sName); ?></option>
                            <?php endforeach; ?>
                            <?php if ($secteur_activite !== '' && !in_array($secteur_activite, $secteursList, true)): ?>
                                <option value="<?php echo htmlspecialchars($secteur_activite); ?>" selected><?php echo htmlspecialchars($secteur_activite); ?> (ancienne valeur)</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="profession" class="form-label">Profession <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="profession" name="profession" class="form-input" value="<?php echo htmlspecialchars($profession); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="annee_integration" class="form-label">Année d'intégration au Dahira <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="annee_integration" name="annee_integration" class="form-input" inputmode="numeric" placeholder="Ex: 2022" value="<?php echo htmlspecialchars($annee_integration); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="souhait_commission" class="form-label">Commission dont vous êtes membre <span style="color:var(--text-muted); font-size:0.8rem;">(facultatif)</span></label>
                        <select id="souhait_commission" name="souhait_commission" class="form-input modern-select">
                            <option value="">— Aucune —</option>
                            <?php foreach ($commissionsList as $cName): ?>
                                <option value="<?php echo htmlspecialchars($cName); ?>" <?php echo ($souhait_commission === $cName) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="commentaires" class="form-label">Commentaires</label>
                        <textarea id="commentaires" name="commentaires" class="form-input" rows="3"><?php echo htmlspecialchars($commentaires); ?></textarea>
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
                    </fieldset>
                </form>
                <?php endif; ?>
            </div>

            <!-- Informational Warning Banner (déplacé en bas) -->
            <div class="info-warning-box" style="margin-top: 1.5rem; margin-bottom: 0;">
                <span class="info-warning-icon">⚠️</span>
                <p class="info-warning-text">
                    <strong>Attention :</strong> Toute modification de vos données d'identité (votre nom, prénom, civilité, ou votre photo de profil) nécessite une <strong>nouvelle validation administrative</strong>. Si vous modifiez ces champs, votre compte sera suspendu temporairement et vous serez déconnecté jusqu'à ce qu'un administrateur valide vos modifications.
                </p>
            </div>

        </div>
        <?php if (!$adminView): ?>
            </div>
        </div>
        <?php endif; ?>
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

    <?php if ($integrateur): ?>
    <?php
        $intParts = preg_split('/\s+/', trim($integrateur['nom']));
        $intInitials = strtoupper(mb_substr($intParts[0] ?? '', 0, 1) . mb_substr($intParts[1] ?? '', 0, 1));
        if ($intInitials === '') { $intInitials = '?'; }
    ?>
    <!-- Modale : message du membre vers son intégrateur (UI moderne) -->
    <div id="integ-mail-modal" class="modal-overlay mailx-overlay">
        <div class="mailx-card">
            <div class="mailx-header">
                <div class="mailx-header-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                </div>
                <div>
                    <h3 class="mailx-title">Message à votre intégrateur</h3>
                    <p class="mailx-subtitle">Il vous répondra par e-mail</p>
                </div>
                <button type="button" class="mailx-close" onclick="closeIntegMail()" aria-label="Fermer">&times;</button>
            </div>
            <form action="profile.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="contact_integrateur">
                <div class="mailx-body">
                    <div class="mailx-recipient">
                        <span class="mailx-recipient-label">À</span>
                        <span class="mailx-avatar"><?php echo htmlspecialchars($intInitials); ?></span>
                        <span class="mailx-recipient-name"><?php echo htmlspecialchars($integrateur['nom']); ?></span>
                    </div>
                    <div class="mailx-field">
                        <label for="im-subject">Objet</label>
                        <input type="text" name="mail_subject" id="im-subject" value="Suivi d'intégration" required>
                    </div>
                    <div class="mailx-field">
                        <label for="im-body">Message</label>
                        <textarea name="mail_body" id="im-body" rows="6" placeholder="Votre message à votre intégrateur…" required></textarea>
                    </div>
                </div>
                <div class="mailx-footer">
                    <button type="button" class="mailx-btn mailx-btn-ghost" onclick="closeIntegMail()">Annuler</button>
                    <button type="submit" class="mailx-btn mailx-btn-send">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                        Envoyer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <script>
        function openIntegMail() {
            var m = document.getElementById('integ-mail-modal');
            if (!m) return;
            m.style.display = 'flex';
            setTimeout(function () { m.classList.add('active'); }, 10);
        }
        function closeIntegMail() {
            var m = document.getElementById('integ-mail-modal');
            if (!m) return;
            m.classList.remove('active');
            setTimeout(function () { m.style.display = 'none'; }, 300);
        }
        (function () {
            var m = document.getElementById('integ-mail-modal');
            if (m) m.addEventListener('click', function (e) { if (e.target === this) closeIntegMail(); });
        })();


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
    <?php if (!$readonly): ?>
    <script src="modern-select.js"></script>
    <?php endif; ?>
</body>
</html>
