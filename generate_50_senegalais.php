<?php
/**
 * Touba Lyon 2026 — Générateur 50 membres sénégalais
 * Portraits West-African via Unsplash · Noms wolof/sérère/peul authentiques
 * ⚠️ SUPPRIMER CE FICHIER APRÈS UTILISATION
 * ⚠️ Réservé aux administrateurs connectés (voir admin_guard.php).
 */
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/db_setup.php';

/* ─────────────────────────────────────────────
   NOMS SÉNÉGALAIS AUTHENTIQUES
───────────────────────────────────────────── */
$prenomHommes = [
    'Cheikh', 'Modou', 'Khadim', 'Bamba', 'Mbacké', 'Assane', 'Babacar',
    'Ibrahima', 'Ousmane', 'Abdoulaye', 'Mamadou', 'Serigne', 'Saliou',
    'Lamine', 'Moussa', 'Alioune', 'Gora', 'Daouda', 'Pape', 'Thierno',
    'Aliou', 'Boubacar', 'Idrissa', 'Ndongo', 'Malick', 'Demba', 'Mor',
    'Samba', 'Birame', 'Amadou',
];

$prenomFemmes = [
    'Fatou', 'Awa', 'Aminata', 'Khady', 'Mariama', 'Sokhna', 'Astou',
    'Penda', 'Rokhaya', 'Seynabou', 'Ndéye', 'Coumba', 'Aissatou',
    'Fatoumata', 'Adja', 'Binta', 'Dieynaba', 'Ramatoulaye', 'Yacine',
    'Marème', 'Nafi', 'Oumou', 'Mame', 'Bineta', 'Dior',
];

$noms = [
    'Diop', 'Ndiaye', 'Gueye', 'Fall', 'Sow', 'Diallo', 'Mbacké',
    'Ba', 'Kane', 'Thiam', 'Faye', 'Sy', 'Sarr', 'Cissé', 'Mbaye',
    'Diouf', 'Badji', 'Camara', 'Konaré', 'Coulibaly', 'Traoré',
    'Niang', 'Lô', 'Tall', 'Wague', 'Diagne', 'Ndour', 'Samb',
    'Mbodj', 'Touré',
];

/* ─────────────────────────────────────────────
   PHOTOS UNSPLASH — PORTRAITS AFRICAINS/SÉNÉGALAIS
   Format : ID Unsplash (recadrage carré 500×500)
───────────────────────────────────────────── */
$photosHommes = [
    'photo-1547425260-76bcadfb4f2c',
    'photo-1506794778202-cad84cf45f1d',
    'photo-1500648767791-00dcc994a43e',
    'photo-1522075469751-3a6694fb2f61',
    'photo-1539571696357-5a69c17a67c6',
    'photo-1519085360753-af0119f7cbe7',
    'photo-1531427186611-ecfd6d936c79',
    'photo-1560250097-0b93528c311a',
    'photo-1592085549035-37137caede78',
    'photo-1504257432389-52343af06ae3',
    'photo-1615109398623-88346a601842',
    'photo-1568602471122-7832951cc4c5',
    'photo-1546961342-ea5f61193990',
    'photo-1629425733761-caae3b5f2e50',
    'photo-1607990281513-2c110a25bd8c',
    'photo-1535713875002-d1d0cf377fde',
    'photo-1552374196-c4e7ffc6e126',
    'photo-1506003094589-53954a26283f',
    'photo-1463453091185-61582044d556',
    'photo-1564564321837-a57b7070ac4f',
    'photo-1570295999919-56ceb5ecca61',
    'photo-1472099645785-5658abf4ff4e',
    'photo-1508214751196-bcfd4ca60f91',
    'photo-1599566150163-29194dcaad36',
    'photo-1542909168-82c3e7fdca5c',
];

$photosFemmes = [
    'photo-1531123897727-8f129e1688ce',
    'photo-1567532939604-b6b5b0db2604',
    'photo-1488426862026-3ee34a7d66df',
    'photo-1534528741775-53994a69daeb',
    'photo-1524504388940-b1c1722653e1',
    'photo-1573496359142-b8d87734a5a2',
    'photo-1531746020798-e6953c6e8e04',
    'photo-1554151228-14d9def656e4',
    'photo-1595152772835-219674b2a163',
    'photo-1529626455594-4ff0802cfb7e',
    'photo-1584273143981-41c073dfe8f8',
    'photo-1545912452-8aea7e25a3d3',
    'photo-1520813792240-56fc4a3765a7',
    'photo-1598550874175-4d0ef436c909',
    'photo-1614644147798-f8c0fc9da7f6',
    'photo-1611432579699-484f7990b127',
    'photo-1590650153855-d9e808231d41',
    'photo-1489424731084-a5d8b219a5bb',
    'photo-1508341421810-36b8fc06075b',
    'photo-1502764613149-7f1d229e230f',
    'photo-1559839734-2b71ea197ec2',
    'photo-1494790108377-be9c29b29330',
    'photo-1517841905240-472988babdf9',
    'photo-1548142813-c348350df52b',
    'photo-1543269608-8424596302a0',
];

/* ─────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────── */
function cleanStr($s) {
    $s = str_replace(
        ['à','á','â','ã','ä','å','ç','è','é','ê','ë','ì','í','î','ï','ñ',
         'ò','ó','ô','õ','ö','ù','ú','û','ü','ý','ÿ',' '],
        ['a','a','a','a','a','a','c','e','e','e','e','i','i','i','i','n',
         'o','o','o','o','o','u','u','u','u','y','y','_'],
        mb_strtolower($s, 'UTF-8')
    );
    return preg_replace('/[^a-z0-9_.-]/', '', $s);
}

function downloadPhoto($id, $dir) {
    $filename = "sn_{$id}.jpg";
    $path = "$dir/$filename";
    if (!file_exists($path)) {
        $url = "https://images.unsplash.com/{$id}?auto=format&fit=crop&w=500&h=500&q=80&face";
        $ctx = stream_context_create(['http' => ['timeout' => 15]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) return false;
        file_put_contents($path, $data);
    }
    return $filename;
}

/* ─────────────────────────────────────────────
   TRAITEMENT DU FORMULAIRE
───────────────────────────────────────────── */
$message = '';
$type    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $uploadDir = __DIR__ . '/uploads';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

    /* ── Purger les membres de démo existants (optionnel) ── */
    if (!empty($_POST['purge_demo'])) {
        $pdo->exec("DELETE FROM membres WHERE email LIKE '%@toubalyon.dev'");
    }

    $action = $_POST['action'];  // 'generate' ou 'update_photos'

    /* ── Télécharger les photos ── */
    shuffle($photosHommes);
    shuffle($photosFemmes);

    $dlH = $dlF = [];
    foreach ($photosHommes as $id) {
        $f = downloadPhoto($id, $uploadDir);
        if ($f) $dlH[] = $f;
    }
    foreach ($photosFemmes as $id) {
        $f = downloadPhoto($id, $uploadDir);
        if ($f) $dlF[] = $f;
    }

    if (empty($dlH) && empty($dlF)) {
        $message = "Impossible de télécharger les photos depuis Unsplash. Vérifiez la connexion serveur.";
        $type    = 'danger';
    } else {

        $defaultHash = password_hash('toubalyon', PASSWORD_BCRYPT);
        $count = 0;
        $errors = [];

        if ($action === 'generate') {

            /* ── Générer 50 nouveaux membres ── */
            $stmt = $pdo->prepare(
                "INSERT INTO membres (nom, prenom, civilite, email, photo_path, password, score, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'approved')"
            );

            $pool = [];
            // Constituer 25 hommes + 25 femmes
            for ($i = 0; $i < 25; $i++) $pool[] = 'H';
            for ($i = 0; $i < 25; $i++) $pool[] = 'F';
            shuffle($pool);

            $hIdx = $fIdx = 0;

            foreach ($pool as $genre) {
                if ($genre === 'H') {
                    $prenom   = $prenomHommes[array_rand($prenomHommes)];
                    $civilite = 'Goor Yalla';
                    $photo    = $dlH[$hIdx % count($dlH)]; $hIdx++;
                } else {
                    $prenom   = $prenomFemmes[array_rand($prenomFemmes)];
                    $civilite = 'Sokhna';
                    $photo    = $dlF[$fIdx % count($dlF)]; $fIdx++;
                }

                $nom   = $noms[array_rand($noms)];
                $email = cleanStr($prenom) . '.' . cleanStr($nom) . rand(100, 9999) . '@toubalyon.dev';
                $score = rand(0, 20) * 5;

                try {
                    $stmt->execute([$nom, $prenom, $civilite, $email, $photo, $defaultHash, $score]);
                    $count++;
                } catch (Exception $e) {
                    // doublon email → on reessaie avec un autre suffix
                    try {
                        $email = cleanStr($prenom) . '.' . cleanStr($nom) . rand(10000, 99999) . '@toubalyon.dev';
                        $stmt->execute([$nom, $prenom, $civilite, $email, $photo, $defaultHash, $score]);
                        $count++;
                    } catch (Exception $e2) {
                        $errors[] = $e2->getMessage();
                    }
                }
            }

            $message = "✅ {$count} membres sénégalais créés avec succès (mdp : <strong>toubalyon</strong>).";
            if ($errors) $message .= " (" . count($errors) . " ignorés — doublons)";
            $type = 'success';

        } elseif ($action === 'update_photos') {

            /* ── Rafraîchir uniquement les photos des membres existants ── */
            $membres = $pdo->query("SELECT id, prenom, civilite FROM membres")->fetchAll();

            if (empty($membres)) {
                $message = "Aucun membre en base. Générez-en d'abord.";
                $type    = 'danger';
            } else {
                $updStmt = $pdo->prepare("UPDATE membres SET photo_path = ? WHERE id = ?");
                $hIdx = $fIdx = 0;

                foreach ($membres as $m) {
                    if ($m['civilite'] === 'Sokhna') {
                        $photo = $dlF[$fIdx % count($dlF)]; $fIdx++;
                    } else {
                        $photo = $dlH[$hIdx % count($dlH)]; $hIdx++;
                    }
                    $updStmt->execute([$photo, $m['id']]);
                    $count++;
                }

                $message = "✅ Photos mises à jour pour {$count} membres.";
                $type    = 'success';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Génération 50 membres sénégalais</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .gen-card { max-width: 600px; }
        .option-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            margin-bottom: 0.75rem;
            cursor: pointer;
            transition: all 0.25s;
        }
        .option-row:hover { border-color: rgba(212,175,55,0.3); background: rgba(255,255,255,0.05); }
        .option-row input[type=radio] { accent-color: var(--accent); width: 16px; height: 16px; }
        .option-label { font-weight: 600; font-size: 0.95rem; }
        .option-desc  { font-size: 0.82rem; color: var(--text-muted); margin-top: 2px; }
        .checkbox-row {
            display: flex; align-items: center; gap: 0.6rem;
            font-size: 0.88rem; color: var(--text-muted); margin: 1rem 0;
        }
        .checkbox-row input { accent-color: var(--accent); }
        .info-pill {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(212,175,55,0.08);
            border: 1px solid rgba(212,175,55,0.2);
            border-radius: 8px; padding: 0.5rem 1rem;
            font-size: 0.82rem; color: var(--text-muted);
            margin-bottom: 1.5rem; flex-wrap: wrap;
        }
        .info-pill strong { color: var(--accent); }
        .spinner { display: none; }
        form.loading .spinner { display: inline-block; }
        form.loading .btn-submit { opacity: 0.6; pointer-events: none; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .spinner svg { animation: spin 0.9s linear infinite; }
    </style>
</head>
<body>

<?php include __DIR__ . '/header.php'; ?>

<main class="container">
<div class="form-card gen-card">

    <h1 class="form-title" style="font-size:1.6rem;">
        👥 Génération — <span class="gold-text">50 membres sénégalais</span>
    </h1>

    <div class="info-pill">
        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Portraits téléchargés depuis <strong>Unsplash</strong> ·
        Noms <strong>wolof / sérère / peul</strong> ·
        Mdp par défaut&nbsp;: <strong>toubalyon</strong>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $type; ?>" style="margin-bottom:1.5rem;">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <?php if ($type === 'success'): ?>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                <?php else: ?>
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                <?php endif; ?>
            </svg>
            <span><?php echo $message; ?></span>
        </div>
        <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;">
            <a href="index.php" class="btn btn-primary">Voir le Dahira - Mubawwa-A-Sidqin</a>
            <a href="admin_dashboard.php" class="btn btn-secondary">Administration</a>
        </div>
    <?php else: ?>

    <form method="POST" id="genForm" onsubmit="this.classList.add('loading')">

        <p style="font-size:0.9rem;color:var(--text-muted);margin-bottom:1.25rem;">Choisissez une action :</p>

        <label class="option-row">
            <input type="radio" name="action" value="generate" checked>
            <div>
                <div class="option-label">⚡ Créer 50 nouveaux membres</div>
                <div class="option-desc">25 Goor Yalla + 25 Sokhna · photos uniques · statut approuvé</div>
            </div>
        </label>

        <label class="option-row">
            <input type="radio" name="action" value="update_photos">
            <div>
                <div class="option-label">🖼️ Rafraîchir uniquement les photos</div>
                <div class="option-desc">Nouvelle série de portraits pour les membres déjà en base</div>
            </div>
        </label>

        <label class="checkbox-row">
            <input type="checkbox" name="purge_demo" value="1">
            Supprimer les membres de démo existants (<code>@toubalyon.dev</code>) avant génération
        </label>

        <button type="submit" class="btn btn-primary btn-submit" style="width:100%;padding:1rem;font-size:1rem;margin-top:0.5rem;">
            <span class="spinner">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
            </span>
            Lancer la génération
        </button>

        <p style="color:var(--danger);font-size:0.78rem;margin-top:1.25rem;text-align:center;line-height:1.5;">
            ⚠️ Supprimez <code>generate_50_senegalais.php</code> du serveur après utilisation.
        </p>

    </form>
    <?php endif; ?>

</div>
</main>

<footer class="app-footer">
    <p>&copy; 2026 Touba Lyon — Tous droits réservés.</p>
</footer>
</body>
</html>
