<?php
/**
 * Touba Lyon 2026 - Mock Data Generator (Senegalese/African Portraits)
 * Ce script permet de générer 100 membres de test ou de mettre à jour les photos & mots de passe des membres existants
 * avec des visages sénégalais/africains de haute qualité provenant d'Unsplash et le mot de passe par défaut "toubalyon".
 * AVERTISSEMENT : Supprimez ce fichier de votre serveur après exécution !
 * ⚠️ Réservé aux administrateurs connectés (voir admin_guard.php).
 */
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/db_setup.php';

$error = '';
$success = '';
$insertedCount = 0;

// Lists of first names and last names for random generation
$firstNamesMen = ['Cheikh', 'Modou', 'Khadim', 'Bamba', 'Mbacké', 'Assane', 'Babacar', 'Ibrahima', 'Ousmane', 'Abdoulaye', 'Jean', 'Michel', 'Pierre', 'Thomas', 'Lucas', 'Nicolas', 'David', 'Antoine'];
$firstNamesWomen = ['Fatou', 'Awa', 'Aminata', 'Khady', 'Mariama', 'Sokhna', 'Astou', 'Penda', 'Rokhaya', 'Seynabou', 'Sophie', 'Marie', 'Julie', 'Camille', 'Sarah', 'Léa', 'Emma', 'Chloé'];
$lastNames = ['Diop', 'Ndiaye', 'Gueye', 'Fall', 'Sow', 'Diallo', 'Mbacké', 'Ba', 'Kane', 'Thiam', 'Faye', 'Sy', 'Dupont', 'Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit'];

// Unsplash photos of African/Senegalese faces (neat, professional portraits)
$unsplashPhotos = [
    'men' => [
        'photo-1507003211169-0a1dd7228f2d',
        'photo-1506794778202-cad84cf45f1d',
        'photo-1500648767791-00dcc994a43e',
        'photo-1522075469751-3a6694fb2f61',
        'photo-1539571696357-5a69c17a67c6',
        'photo-1501196354995-cbb51c65aaea',
        'photo-1519085360753-af0119f7cbe7',
        'photo-1500048993953-d23a436266cf',
        'photo-1556157382-97eda2d62296',
        'photo-1531427186611-ecfd6d936c79',
        'photo-1560250097-0b93528c311a',
        'photo-1534308983496-4fabb1a015ee',
        'photo-1492562080023-ab3db95bfbce',
        'photo-1489980508314-941910ded1f4',
        'photo-1506803682981-6e718a9dd3ee'
    ],
    'women' => [
        'photo-1531123897727-8f129e1688ce',
        'photo-1567532939604-b6b5b0db2604',
        'photo-1544005313-94ddf0286df2',
        'photo-1488426862026-3ee34a7d66df',
        'photo-1534528741775-53994a69daeb',
        'photo-1509631179647-0177331693ae',
        'photo-1524504388940-b1c1722653e1',
        'photo-1494790108377-be9c29b29330',
        'photo-1517841905240-472988babdf9',
        'photo-1543269608-8424596302a0',
        'photo-1508214751196-bcfd4ca60f91',
        'photo-1531746020798-e6953c6e8e04',
        'photo-1548142813-c348350df52b',
        'photo-1554151228-14d9def656e4',
        'photo-1573496359142-b8d87734a5a2'
    ]
];

// Helper to remove accents for email address generation
function cleanString($string) {
    $string = str_replace(
        ['à', 'á', 'â', 'ã', 'ä', 'å', 'ç', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ñ', 'ò', 'ó', 'ô', 'õ', 'ö', 'ù', 'ú', 'û', 'ü', 'ý', 'ÿ', ' '],
        ['a', 'a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'n', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y', '_'],
        mb_strtolower($string, 'UTF-8')
    );
    return preg_replace('/[^a-z0-9_.-]/', '', $string);
}

// Helper to download Unsplash image with square crop
function downloadUnsplashPhoto($id, $uploadDir) {
    $filename = "senegal_{$id}.jpg";
    $targetPath = $uploadDir . '/' . $filename;
    if (!file_exists($targetPath)) {
        $url = "https://images.unsplash.com/{$id}?auto=format&fit=crop&w=500&h=500&q=80";
        $content = @file_get_contents($url);
        if ($content) {
            file_put_contents($targetPath, $content);
            return $filename;
        }
        return false;
    }
    return $filename;
}

if (isset($_POST['generate'])) {
    try {
        // 1. Download sample portrait photos from Unsplash
        $photos = ['men' => [], 'women' => []];
        $uploadDir = __DIR__ . '/uploads';

        // Clean up uploads directory to delete all old images first
        if (file_exists($uploadDir)) {
            $files = glob($uploadDir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== '.htaccess') {
                    @unlink($file);
                }
            }
        } else {
            mkdir($uploadDir, 0755, true);
        }

        // Men photos
        foreach ($unsplashPhotos['men'] as $id) {
            $file = downloadUnsplashPhoto($id, $uploadDir);
            if ($file) {
                $photos['men'][] = $file;
            }
        }

        // Women photos
        foreach ($unsplashPhotos['women'] as $id) {
            $file = downloadUnsplashPhoto($id, $uploadDir);
            if ($file) {
                $photos['women'][] = $file;
            }
        }

        // If download failed completely, check if we have placeholders
        if (empty($photos['men']) && empty($photos['women'])) {
            throw new Exception("Impossible de télécharger les avatars de test depuis Unsplash. Vérifiez la connexion Internet de votre hébergement.");
        }

        // 2. Generate 100 validated accounts with password "toubalyon"
        $pdo->beginTransaction();
        
        // 2. Generate validated accounts with password "toubalyon"
        $pdo->beginTransaction();
        
        // Prepare insert statement
        $stmt = $pdo->prepare("INSERT INTO membres (nom, prenom, civilite, email, photo_path, password, score, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'approved')");
        $defaultPasswordHash = password_hash('toubalyon', PASSWORD_BCRYPT);

        $availableMenPhotos = $photos['men'];
        $availableWomenPhotos = $photos['women'];
        shuffle($availableMenPhotos);
        shuffle($availableWomenPhotos);

        while (!empty($availableMenPhotos) || !empty($availableWomenPhotos)) {
            // Pick gender randomly depending on remaining photos
            if (!empty($availableMenPhotos) && !empty($availableWomenPhotos)) {
                $gender = (rand(0, 1) === 0) ? 'men' : 'women';
            } elseif (!empty($availableMenPhotos)) {
                $gender = 'men';
            } else {
                $gender = 'women';
            }
            
            // Pick random first name depending on gender
            if ($gender === 'men') {
                $prenom = $firstNamesMen[array_rand($firstNamesMen)];
                $photo = array_pop($availableMenPhotos);
                $civilite = 'Goor Yalla';
            } else {
                $prenom = $firstNamesWomen[array_rand($firstNamesWomen)];
                $photo = array_pop($availableWomenPhotos);
                $civilite = 'Sokhna';
            }
            
            // Pick random last name
            $nom = $lastNames[array_rand($lastNames)];
            
            // Build unique email address
            $email = cleanString($prenom) . '.' . cleanString($nom) . rand(1, 999) . '@toubalyon.dev';
            
            // Ensure email uniqueness check
            $check = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetchColumn() > 0) {
                $email = cleanString($prenom) . '.' . cleanString($nom) . rand(1000, 9999) . '@toubalyon.dev';
            }

            // Random score between 0 and 150 points for leaderboard demonstration
            $randomScore = rand(0, 15) * 10;

            $stmt->execute([$nom, $prenom, $civilite, $email, $photo, $defaultPasswordHash, $randomScore]);
            $insertedCount++;
        }

        $pdo->commit();
        $success = "Succès ! {$insertedCount} membres validés (avec civilité et photos uniques) ont été créés avec le mot de passe par défaut 'toubalyon' et des scores aléatoires.";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Une erreur est survenue : " . $e->getMessage();
    }
}

if (isset($_POST['update_existing'])) {
    try {
        $uploadDir = __DIR__ . '/uploads';
        // Clean up uploads directory to delete all old images first
        if (file_exists($uploadDir)) {
            $files = glob($uploadDir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== '.htaccess') {
                    @unlink($file);
                }
            }
        } else {
            mkdir($uploadDir, 0755, true);
        }

        // Download Unsplash photos first
        $photos = ['men' => [], 'women' => []];
        foreach ($unsplashPhotos['men'] as $id) {
            $file = downloadUnsplashPhoto($id, $uploadDir);
            if ($file) {
                $photos['men'][] = $file;
            }
        }
        foreach ($unsplashPhotos['women'] as $id) {
            $file = downloadUnsplashPhoto($id, $uploadDir);
            if ($file) {
                $photos['women'][] = $file;
            }
        }

        if (empty($photos['men']) && empty($photos['women'])) {
            throw new Exception("Impossible de télécharger les avatars de test depuis Unsplash.");
        }

        // Fetch all existing members
        $stmt = $pdo->query("SELECT id, prenom FROM membres");
        $membres = $stmt->fetchAll();

        if (empty($membres)) {
            throw new Exception("Aucun membre trouvé dans la base de données. Veuillez d'abord en inscrire ou générer.");
        }

        $pdo->beginTransaction();
        $updateStmt = $pdo->prepare("UPDATE membres SET photo_path = ?, password = ?, score = ?, civilite = ? WHERE id = ?");
        $defaultPasswordHash = password_hash('toubalyon', PASSWORD_BCRYPT);

        $availableMenPhotos = $photos['men'];
        $availableWomenPhotos = $photos['women'];
        shuffle($availableMenPhotos);
        shuffle($availableWomenPhotos);

        $updatedCount = 0;
        foreach ($membres as $m) {
            $prenom = $m['prenom'];
            
            // Detect gender
            $isMan = false;
            foreach ($firstNamesMen as $fn) {
                if (mb_strtolower($prenom, 'UTF-8') === mb_strtolower($fn, 'UTF-8')) {
                    $isMan = true;
                    break;
                }
            }
            
            $isWoman = false;
            foreach ($firstNamesWomen as $fn) {
                if (mb_strtolower($prenom, 'UTF-8') === mb_strtolower($fn, 'UTF-8')) {
                    $isWoman = true;
                    break;
                }
            }
            
            if ($isMan) {
                $civilite = 'Goor Yalla';
                if (!empty($availableMenPhotos)) {
                    $photo = array_pop($availableMenPhotos);
                } else {
                    $photo = !empty($photos['men']) ? $photos['men'][array_rand($photos['men'])] : 'default.jpg';
                }
            } elseif ($isWoman) {
                $civilite = 'Sokhna';
                if (!empty($availableWomenPhotos)) {
                    $photo = array_pop($availableWomenPhotos);
                } else {
                    $photo = !empty($photos['women']) ? $photos['women'][array_rand($photos['women'])] : 'default.jpg';
                }
            } else {
                $civilite = (rand(0, 1) === 0) ? 'Goor Yalla' : 'Sokhna';
                $allUniquePhotos = array_merge($availableMenPhotos, $availableWomenPhotos);
                if (!empty($allUniquePhotos)) {
                    $photo = $allUniquePhotos[array_rand($allUniquePhotos)];
                    if (($key = array_search($photo, $availableMenPhotos)) !== false) {
                        unset($availableMenPhotos[$key]);
                    } elseif (($key = array_search($photo, $availableWomenPhotos)) !== false) {
                        unset($availableWomenPhotos[$key]);
                    }
                } else {
                    $allPhotos = array_merge($photos['men'], $photos['women']);
                    $photo = !empty($allPhotos) ? $allPhotos[array_rand($allPhotos)] : 'default.jpg';
                }
            }

            // Assign random score (between 0 and 150 points) for ranking visibility
            $randomScore = rand(0, 15) * 10;

            $updateStmt->execute([$photo, $defaultPasswordHash, $randomScore, $civilite, $m['id']]);
            $updatedCount++;
        }

        $pdo->commit();
        $success = "Succès ! Les civilités, photos, mots de passe ('toubalyon') et scores de {$updatedCount} membres existants ont été mis à jour avec des images uniques.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Une erreur est survenue : " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Générateur de Données - Trombinoscope</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="form-card" style="max-width: 650px;">
            <h1 class="form-title">Génération & Mise à jour des Profils</h1>
            
            <div class="alert alert-info">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <div style="text-align: left;">
                    <strong>À quoi sert ce script ?</strong><br>
                    Il permet de peupler le Trombinoscope ou de rafraîchir les civilités, photos, et mots de passe des membres déjà créés en attribuant des civilités cohérentes (Sokhna / Goor Yalla) et en téléchargeant des visages sénégalais/africains professionnels depuis Unsplash, avec le mot de passe par défaut <strong>toubalyon</strong> pour pouvoir jouer à "Ki Kan La".
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
                <div style="text-align: center; margin-top: 1.5rem; display: flex; gap: 1rem; justify-content: center;">
                    <a href="index.php" class="btn btn-primary">Voir le Trombinoscope</a>
                    <a href="admin_login.php" class="btn btn-secondary">Aller à l'Administration</a>
                </div>
            <?php else: ?>
                <form action="generate_mock_data.php" method="POST" style="text-align: center; margin-top: 2rem; display: flex; flex-direction: column; gap: 1.25rem; align-items: center;">
                    <button type="submit" name="generate" class="btn btn-primary" style="padding: 1.25rem 2.5rem; font-size: 1.1rem; width: 100%; max-width: 450px;">
                        ⚡ Générer de nouveaux membres avec photos uniques (toubalyon)
                    </button>
                    
                    <button type="submit" name="update_existing" class="btn btn-secondary" style="padding: 1.25rem 2.5rem; font-size: 1.1rem; width: 100%; max-width: 450px;">
                        🔄 Mettre à jour les civilités et photos uniques des membres existants
                    </button>
                    
                    <p style="color: var(--danger); font-size: 0.85rem; margin-top: 1.5rem; font-weight: 600; max-width: 500px; line-height: 1.4;">
                        ⚠️ AVERTISSEMENT : Supprimez ce fichier (generate_mock_data.php) de votre serveur FTP après utilisation en production.
                    </p>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

</body>
</html>
