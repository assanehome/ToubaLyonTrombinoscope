<?php
/**
 * Touba Lyon 2026 - 🖼️ Détail d'un membre du Trombinoscope
 *
 * Page de détail d'un membre, ouverte au clic sur une photo du trombinoscope.
 * Affiche la fiche complète et permet d'envoyer un message au membre
 * (par e-mail via le client SMTP autonome du site).
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/send_mail.php';
require_once __DIR__ . '/dahira_emails.php';
require_once __DIR__ . '/contact.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

// Accès membre validé (ou admin en lecture)
$__mdAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if (empty($_SESSION['player_id']) && !$__mdAdmin) {
    header('Location: login.php');
    exit;
}
if (!empty($_SESSION['player_id']) && !$__mdAdmin) {
    try {
        $st = $pdo->prepare("SELECT status FROM membres WHERE id = ?");
        $st->execute([(int) $_SESSION['player_id']]);
        $stt = $st->fetchColumn();
        if ($stt !== false && $stt !== 'approved') {
            header('Location: profile.php');
            exit;
        }
    } catch (Exception $e) {
        // silencieux
    }
}

$error = '';
$success = '';

// Charger le membre ciblé
$membre = null;
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM membres WHERE id = ?");
        $st->execute([$id]);
        $membre = $st->fetch();
    } catch (Exception $e) {
        $membre = null;
    }
}
if (!$membre) {
    header('Location: index.php');
    exit;
}

$nomComplet = trim(($membre['prenom'] ?? '') . ' ' . ($membre['nom'] ?? ''));
$emailMembre = (string)($membre['email'] ?? '');

// Envoi d'un message au membre
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } elseif ($emailMembre === '') {
        $error = "Ce membre n'a pas d'adresse e-mail enregistrée.";
    } else {
        $subject = trim($_POST['mail_subject'] ?? '');
        $body = trim($_POST['mail_body'] ?? '');
        $expediteur = trim($_SESSION['player_name'] ?? 'Un membre du Dahira');
        if ($subject === '' || $body === '') {
            $error = "L'objet et le message sont obligatoires.";
        } else {
            try {
                $htmlBody = dahira_email_wrap(
                    '<h1 style="margin:0 0 18px;font-size:20px;color:#1b4332;">Vous avez reçu un message</h1>'
                    . '<p style="margin:0 0 14px;">Assalamu aleykum <strong>' . htmlspecialchars($nomComplet) . '</strong>,</p>'
                    . '<p style="margin:0 0 16px;">Vous avez reçu un message de <strong>' . htmlspecialchars($expediteur) . '</strong> '
                    . 'via le trombinoscope du Dahira Touba Lyon :</p>'
                    . '<div style="white-space:pre-wrap;background:#f6f7f6;border-left:4px solid #1b4332;border-radius:8px;padding:14px 18px;margin:0 0 18px;">' . nl2br(htmlspecialchars($body)) . '</div>'
                    . '<p style="margin:0;color:#888;font-size:13px;">Vous pouvez répondre directement par e-mail. — Dahira Touba Lyon (Mubawwa-A-Sidqin)</p>',
                    'Message du trombinoscope'
                );
                $sent = send_smtp_mail($emailMembre, $nomComplet, $subject, $htmlBody);
                $success = $sent
                    ? "Votre message a été envoyé à " . htmlspecialchars($nomComplet) . "."
                    : "Le message n'a pas pu être envoyé. Veuillez réessayer plus tard.";
            } catch (Exception $e) {
                error_log('Touba Lyon membre_detail (envoi message): ' . $e->getMessage());
                $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
            }
        }
    }
}

// Coordonnées affichées
$civilite = (string)($membre['civilite'] ?? '');
$commune = (string)($membre['commune'] ?? '');
$profession = (string)($membre['profession'] ?? '');
$secteur = (string)($membre['secteur_activite'] ?? '');
$annee = (string)($membre['annee_integration'] ?? '');
$type = (string)($membre['type_adhesion'] ?? '');
$photo = (string)($membre['photo_path'] ?? '');
$score = (int)($membre['score'] ?? 0);

// Commissions & Kurels du membre (membre / responsable)
$commissionsMembre = [];
$commissionsResp   = [];
$kourelsMembre     = [];
$kourelsResp       = [];
try {
    $st = $pdo->prepare(
        "SELECT c.id, c.nom FROM commission_membres cm JOIN commissions c ON c.id = cm.commission_id WHERE cm.membre_id = ? ORDER BY c.nom ASC"
    );
    $st->execute([$id]);
    $commissionsMembre = $st->fetchAll();

    $st = $pdo->prepare(
        "SELECT c.id, c.nom FROM commission_gestionnaires cg JOIN commissions c ON c.id = cg.commission_id WHERE cg.membre_id = ? ORDER BY c.nom ASC"
    );
    $st->execute([$id]);
    $commissionsResp = $st->fetchAll();

    $st = $pdo->prepare(
        "SELECT k.id, k.nom FROM kourel_membres km JOIN kourels k ON k.id = km.kourel_id WHERE km.membre_id = ? ORDER BY k.nom ASC"
    );
    $st->execute([$id]);
    $kourelsMembre = $st->fetchAll();

    $st = $pdo->prepare(
        "SELECT k.id, k.nom FROM kourel_gestionnaires kg JOIN kourels k ON k.id = kg.kourel_id WHERE kg.membre_id = ? ORDER BY k.nom ASC"
    );
    $st->execute([$id]);
    $kourelsResp = $st->fetchAll();
} catch (Exception $e) {
    // silencieux : si les tables n'existent pas encore, on n'affiche rien
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🖼️ <?php echo htmlspecialchars($nomComplet); ?> — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .md-wrap { max-width: 860px; margin: 0 auto; }
        .md-hero {
            border-radius: 12px;
            padding: 1.6rem 1.6rem 1.4rem;
            background: linear-gradient(160deg, rgba(212,175,55,0.14) 0%, rgba(255,255,255,0.03) 100%);
            border: 2px solid rgba(212,175,55,0.5);
            margin-bottom: 1.4rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .md-hero::before {
            content: '';
            position: absolute;
            top: 0; left: -20%; right: -20%;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(212,175,55,0.9), rgba(241,210,121,0.95), transparent);
            background-size: 200% 100%;
            animation: mdFlow 4.5s linear infinite;
        }
        @keyframes mdFlow { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        /* Photo au format trombinoscope : carte ratio 3/4, photo en couverture */
        .md-photo {
            position: relative;
            max-width: 320px;
            margin: 0 auto 0.9rem;
            border-radius: 12px;
            overflow: hidden;
            aspect-ratio: 3 / 4;
            border: 1px solid var(--glass-border);
            background: var(--primary);
            box-shadow: 0 18px 40px rgba(0,0,0,0.4);
        }
        .md-photo-container { position: absolute; inset: 0; overflow: hidden; }
        .md-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .md-photo::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(to top,
                rgba(8, 28, 21, 0.92) 0%,
                rgba(8, 28, 21, 0.35) 40%,
                transparent 65%);
            z-index: 1;
        }
        .md-photo .md-initial {
            position: absolute;
            inset: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 4rem; font-weight: 800; color: var(--gold);
        }
        .md-photo-caption {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            padding: 1.25rem 1.1rem 1rem;
            z-index: 2;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            text-align: left;
        }
        .md-name { font-size: 1.2rem; font-weight: 700; color: var(--white); margin: 0; text-transform: capitalize; text-shadow: 0 1px 4px rgba(0,0,0,0.5); }
        .md-civ { margin-top: 0.1rem; }
        .md-grid { display: flex; flex-wrap: wrap; gap: 0.9rem; margin-bottom: 1.4rem; }
        .md-grid > * { flex: 1 1 220px; min-width: 0; }
        .md-item { border-radius: 10px; padding: 1rem 1.1rem; }
        .md-item .k { font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.35rem; }
        .md-item .v { font-size: 0.98rem; font-weight: 600; color: var(--white); white-space: pre-line; word-break: break-word; }
        .md-item .v a { color: var(--accent); }
        .md-back { display: inline-flex; align-items: center; gap: 0.4rem; margin-bottom: 1rem; }
        /* Chips Commissions / Kurels */
        .md-affil { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .md-chip {
            display: inline-flex; align-items: center; gap: 0.35rem;
            background: rgba(45,106,79,0.18);
            border: 1px solid rgba(45,106,79,0.4);
            color: var(--white);
            font-size: 0.8rem; font-weight: 600;
            padding: 0.35rem 0.8rem; border-radius: 12px;
        }
        .md-chip--resp {
            background: rgba(212,175,55,0.16);
            border-color: rgba(212,175,55,0.45);
            color: var(--accent);
        }
        /* Animations d'entrée */
        @keyframes mdCardIn { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
        .md-anim { animation: mdCardIn 0.45s cubic-bezier(0.22,1,0.36,1) both; }
        .md-anim-1 { animation-delay: 0.05s; }
        .md-anim-2 { animation-delay: 0.10s; }
        .md-anim-3 { animation-delay: 0.15s; }
        /* Formulaire de message */
        .md-form textarea, .md-form input { width: 100%; box-sizing: border-box; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__mdAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>

            <div class="admin-content">
                <a href="<?php echo $__mdAdmin ? 'admin_dashboard.php#trombi' : 'trombinoscope.php'; ?>" class="md-back btn btn-secondary btn-sm">← Retour au trombinoscope</a>
                <h1 class="admin-page-title">🖼️ Fiche membre</h1>

                <?php if (!empty($success)): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:8px;margin-bottom:1rem;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:8px;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <div class="md-wrap">
                    <!-- En-tête -->
                    <div class="md-hero md-anim md-anim-1">
                        <div class="md-photo">
                            <?php if ($photo !== ''): ?>
                                <div class="md-photo-container">
                                    <img src="uploads/<?php echo htmlspecialchars($photo); ?>" alt="Photo de <?php echo htmlspecialchars($nomComplet); ?>">
                                </div>
                            <?php else: ?>
                                <div class="md-initial"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($nomComplet, 0, 1))); ?></div>
                            <?php endif; ?>
                            <div class="md-photo-caption">
                                <h2 class="md-name"><?php echo htmlspecialchars($nomComplet); ?></h2>
                                <?php if ($civilite !== ''): ?>
                                    <span class="member-civilite-badge md-civ"><?php echo htmlspecialchars($civilite); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.5rem;">🏆 <?php echo $score; ?> pts</div>
                    </div>

                    <!-- Informations -->
                    <div class="md-grid md-anim md-anim-2">
                        <div class="glass-card md-item">
                            <div class="k">📧 E-mail</div>
                            <div class="v"><?php echo $emailMembre !== '' ? '<a href="mailto:' . htmlspecialchars($emailMembre) . '">' . htmlspecialchars($emailMembre) . '</a>' : '—'; ?></div>
                        </div>
                        <div class="glass-card md-item">
                            <div class="k"> Commune</div>
                            <div class="v"><?php echo $commune !== '' ? htmlspecialchars($commune) : '—'; ?></div>
                        </div>
                        <div class="glass-card md-item">
                            <div class="k">💼 Profession</div>
                            <div class="v"><?php echo $profession !== '' ? htmlspecialchars($profession) : '—'; ?></div>
                        </div>
                        <div class="glass-card md-item">
                            <div class="k">🏭 Secteur d'activité</div>
                            <div class="v"><?php echo $secteur !== '' ? htmlspecialchars($secteur) : '—'; ?></div>
                        </div>
                        <div class="glass-card md-item">
                            <div class="k">📅 Année d'intégration</div>
                            <div class="v"><?php echo $annee !== '' ? htmlspecialchars($annee) : '—'; ?></div>
                        </div>
                        <div class="glass-card md-item">
                            <div class="k">📝 Type d'adhésion</div>
                            <div class="v"><?php echo $type !== '' ? htmlspecialchars($type) : '—'; ?></div>
                        </div>
                    </div>

                    <!-- Commissions & Kurels du membre -->
                    <?php if (!empty($commissionsMembre) || !empty($commissionsResp) || !empty($kourelsMembre) || !empty($kourelsResp)): ?>
                    <div class="glass-card md-anim md-anim-3" style="margin-bottom:1.4rem;">
                        <h3 style="color:var(--white); margin:0 0 0.9rem; font-size:1.05rem;">🤝 Commissions & Kurels</h3>
                        <div class="md-affil">
                            <?php foreach ($commissionsResp as $c): ?>
                                <span class="md-chip md-chip--resp" title="Responsable de la commission">👑 <?php echo htmlspecialchars($c['nom']); ?></span>
                            <?php endforeach; ?>
                            <?php foreach ($commissionsMembre as $c): ?>
                                <span class="md-chip" title="Membre de la commission">⚙️ <?php echo htmlspecialchars($c['nom']); ?></span>
                            <?php endforeach; ?>
                            <?php foreach ($kourelsResp as $k): ?>
                                <span class="md-chip md-chip--resp" title="Responsable du Kurel">👑 Kurel · <?php echo htmlspecialchars($k['nom']); ?></span>
                            <?php endforeach; ?>
                            <?php foreach ($kourelsMembre as $k): ?>
                                <span class="md-chip" title="Membre du Kurel">🕌 Kurel · <?php echo htmlspecialchars($k['nom']); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Envoi de message au membre -->
                    <?php if ($emailMembre !== ''): ?>
                    <div class="glass-card md-form md-anim md-anim-3" style="margin-bottom:1.4rem; border:2px solid rgba(212,175,55,0.45);">
                        <h3 style="color:var(--white); margin-bottom:0.6rem;">💬 Envoyer un message</h3>
                        <p style="color:var(--text-muted); font-size:0.82rem; margin:0 0 0.8rem;">Votre message sera envoyé par e-mail à <?php echo htmlspecialchars($nomComplet); ?>.</p>
                        <form action="membre_detail.php?id=<?php echo (int)$id; ?>" method="POST" style="margin:0;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="send_message">
                            <div class="form-group" style="margin-bottom:0.7rem;">
                                <label class="form-label" style="display:block; margin-bottom:0.4rem;">Objet</label>
                                <input type="text" name="mail_subject" class="form-input" placeholder="Objet du message" value="<?php echo htmlspecialchars($_POST['mail_subject'] ?? ''); ?>">
                            </div>
                            <div class="form-group" style="margin-bottom:0.7rem;">
                                <label class="form-label" style="display:block; margin-bottom:0.4rem;">Message</label>
                                <textarea name="mail_body" class="form-input" rows="5" placeholder="Écrivez votre message ici…"><?php echo htmlspecialchars($_POST['mail_body'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">📨 Envoyer le message</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="glass-card md-anim md-anim-3" style="margin-bottom:1.4rem;">
                        <p style="color:var(--text-muted); margin:0;">💬 Ce membre n'a pas d'adresse e-mail enregistrée : l'envoi de message n'est pas disponible.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
</body>
</html>
