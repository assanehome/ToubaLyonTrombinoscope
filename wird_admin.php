<?php
/**
 * Touba Lyon 2026 - Lecture collective du Coran (Khatm) : gestion des sessions.
 * Réservé à l'admin et aux responsables de la commission « Culte ».
 */
require_once __DIR__ . '/wird_guard.php';   // $__isAdmin, $__isCulteManager, $pdo
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dahira_emails.php'; // dahira_base_url(), send_smtp_mail()
require_once __DIR__ . '/notification_helper.php'; // cloche notifications

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        $sid = (int) ($_POST['session_id'] ?? 0);
        try {
            if ($action === 'add_groupe') {
                $gn = trim($_POST['groupe_nom'] ?? '');
                if ($gn !== '') {
                    $pdo->prepare("INSERT IGNORE INTO quran_groupes (nom) VALUES (?)")->execute([$gn]);
                    $success = "Groupe « " . $gn . " » ajouté.";
                } else {
                    $error = "Le nom du groupe est obligatoire.";
                }
            } elseif ($action === 'create_session') {
                $titre = trim($_POST['titre'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $groupe = trim($_POST['groupe'] ?? '');
                if ($titre === '') {
                    $error = "Le titre de la session est obligatoire.";
                } else {
                    $token = bin2hex(random_bytes(12));
                    $pdo->prepare("INSERT INTO quran_sessions (titre, description, groupe, token) VALUES (?, ?, ?, ?)")
                        ->execute([$titre, $desc !== '' ? $desc : null, $groupe !== '' ? $groupe : null, $token]);
                    $newId = (int) $pdo->lastInsertId();
                    $ins = $pdo->prepare("INSERT INTO quran_parts (session_id, numero, statut) VALUES (?, ?, 'libre')");
                    for ($n = 1; $n <= 30; $n++) { $ins->execute([$newId, $n]); }
                    $success = "Session « " . $titre . " » lancée (30 parties).";
                }
            } elseif ($action === 'close_session' && $sid > 0) {
                $pdo->prepare("UPDATE quran_sessions SET statut='terminee', closed_at=NOW() WHERE id=?")->execute([$sid]);
                $success = "Session clôturée.";
            } elseif ($action === 'reopen_session' && $sid > 0) {
                $pdo->prepare("UPDATE quran_sessions SET statut='en_cours', closed_at=NULL WHERE id=?")->execute([$sid]);
                $success = "Session rouverte.";
            } elseif ($action === 'delete_session' && $sid > 0) {
                $pdo->prepare("DELETE FROM quran_parts WHERE session_id=?")->execute([$sid]);
                $pdo->prepare("DELETE FROM quran_sessions WHERE id=?")->execute([$sid]);
                $success = "Session supprimée.";
            } elseif ($action === 'save_wa_group') {
                $link = trim($_POST['wa_group_link'] ?? '');
                $pdo->prepare("INSERT INTO app_settings (cle, valeur) VALUES ('wa_group_link', ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)")->execute([$link !== '' ? $link : null]);
                $success = $link !== '' ? "Groupe WhatsApp par défaut enregistré." : "Groupe WhatsApp par défaut retiré.";
            } elseif ($action === 'notify_to_validate' && $sid > 0) {
                // Relance auprès des personnes ayant des Juz réservés (non validés)
                // dans la session : e-mail + notification cloche.
                require_once __DIR__ . '/send_mail.php';
                $parts = $pdo->prepare(
                    "SELECT p.id AS part_id, p.numero, p.membre_id, p.membre_nom,
                            m.email, m.prenom, m.nom, s.titre AS session_titre, s.token
                     FROM quran_parts p
                     JOIN quran_sessions s ON s.id = p.session_id
                     LEFT JOIN membres m ON m.id = p.membre_id
                     WHERE p.session_id = ? AND p.statut = 'reservee'
                     ORDER BY p.membre_id, p.numero"
                );
                $parts->execute([$sid]);
                $parts = $parts->fetchAll();

                // Regrouper les Juz par membre
                $byMembre = [];
                $sessionTitre = '';
                $sessionToken = '';
                foreach ($parts as $pt) {
                    $sessionTitre = $pt['session_titre'];
                    $sessionToken = $pt['token'];
                    $byMembre[(int)$pt['membre_id']][] = $pt;
                }

                if (empty($byMembre)) {
                    $error = "Aucun Juz réservé à valider dans cette session.";
                } else {
                    $nEnvoyes = 0;
                    $nNotif = 0;
                    $lienBase = $baseUrl . '/wird.php?s=' . (int)$sid . '&t=' . htmlspecialchars($sessionToken, ENT_QUOTES);
                    foreach ($byMembre as $mid => $pts) {
                        $nums = array_map(static function ($p) { return (int)$p['numero']; }, $pts);
                        sort($nums);
                        $listeJuz = implode(', ', $nums);
                        $email = $pts[0]['email'] ?? '';
                        $prenom = $pts[0]['prenom'] ?? '';
                        $nom = $pts[0]['nom'] ?? '';
                        $destinataire = trim($prenom . ' ' . $nom) !== '' ? trim($prenom . ' ' . $nom) : ($pts[0]['membre_nom'] ?? 'Cher membre');

                        // 1) Cloche navigateur (déposée en premier : jamais bloquant)
                        // Le clic sur la notification mène à l'accueil (index.php).
                        troba_notify_membre(
                            $pdo,
                            (int)$mid,
                            'wird_relance',
                            '📖 Juz à valider — ' . $sessionTitre,
                            'Vous avez ' . count($pts) . ' Juz réservé(s) (' . $listeJuz . ") en attente de validation. Rendez-vous sur l'accueil pour les valider.",
                            'index.php'
                        );
                        $nNotif++;

                        // 2) E-mail (voie principale)
                        if ($email !== '') {
                            $sujet = '📖 ' . $sessionTitre . ' — vos Juz à valider';
                            $corpsHtml =
                                '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
                                . '<h2 style="color:#1b4332;">Assalamu aleykum ' . htmlspecialchars($destinataire) . ' 🙏</h2>'
                                . '<p style="color:#333;">Dans le cadre de la lecture collective du Coran '
                                . '<strong>' . htmlspecialchars($sessionTitre) . '</strong>, vous avez réservé les Juz suivants :</p>'
                                . '<p style="background:#f6f3e8;border:1px solid #d4af37;border-radius:10px;padding:12px 16px;font-size:18px;font-weight:bold;color:#1b4332;text-align:center;">Juz ' . $listeJuz . '</p>'
                                . '<p style="color:#333;">Pensez à valider votre lecture pour faire avancer le Khatm. 🤲</p>'
                                . dahira_email_button($lienBase, 'Valider mes Juz')
                                . '<p style="color:#888;font-size:12px;">— Dahira Touba Lyon (Mubawwa-A-Sidqin)</p>'
                                . '</div>';
                            $okMail = send_smtp_mail($email, $destinataire, $sujet, $corpsHtml);
                            if ($okMail) { $nEnvoyes++; }
                        }
                    }
                    $success = count($byMembre) . ' membre(s) relancé(s) — ' . $nNotif . ' notification(s) cloche, ' . $nEnvoyes . ' e-mail(s) envoyé(s).';
                }
            } else {
                $error = "Action non autorisée.";
            }
        } catch (Exception $e) {
            error_log('Touba Lyon wird_admin: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
}

try {
    $sessions = $pdo->query("SELECT * FROM quran_sessions ORDER BY (statut='en_cours') DESC, created_at DESC")->fetchAll();
    // Indicateurs par session : Juz lus, réservés, libres, et nombre de participants distincts
    $prog = [];
    $rows = $pdo->query("
        SELECT session_id,
               SUM(statut='lue') AS lues,
               SUM(statut='reservee') AS res,
               SUM(statut='libre') AS libres,
               COUNT(*) AS tot,
               COUNT(DISTINCT CASE WHEN membre_id IS NOT NULL AND membre_id > 0 THEN membre_id END) AS participants
        FROM quran_parts GROUP BY session_id
    ");
    foreach ($rows as $r) { $prog[(int)$r['session_id']] = $r; }
} catch (Exception $e) {
    error_log('Touba Lyon wird_admin (load): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
$baseUrl = rtrim(dahira_base_url(), '/');
$waGroup = '';
try { $waGroup = (string) $pdo->query("SELECT valeur FROM app_settings WHERE cle='wa_group_link'")->fetchColumn(); } catch (Exception $e) { $waGroup = ''; }
$groupes = [];
try { $groupes = $pdo->query("SELECT nom FROM quran_groupes ORDER BY nom ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $groupes = []; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecture du Coran - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .wird-wrap { max-width: 900px; margin: 2rem auto; }
        .wird-card { border-radius: 16px; padding: 1.25rem 1.5rem; margin-bottom: 1rem; transition: var(--transition); }
        .wird-card:hover { border-color: rgba(212,175,55,0.35); }
        .wird-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .wird-head h3 { margin: 0; color: var(--white); font-size: 1.15rem; }
        .wird-prog { height: 8px; border-radius: 50px; background: rgba(255,255,255,0.08); overflow: hidden; margin: 0.75rem 0; }
        .wird-prog > i { display: block; height: 100%; background: linear-gradient(90deg,#2d6a4f,#7bd8a6); }
        .wird-meta { color: var(--text-muted); font-size: 0.85rem; }
        .wird-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.85rem; }
        .wird-actions .btn { padding: 0.35rem 0.7rem; font-size: 0.78rem; }
        .wird-link { display: flex; gap: 0.4rem; margin-top: 0.6rem; }
        .wird-link input { flex: 1; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); border-radius: 8px; color: #fff; font-size: 0.8rem; padding: 0.45rem 0.7rem; }
        /* ── En-têtes de section (En cours / Clôturées) ── */
        .wird-section-head { display: flex; align-items: center; gap: 0.7rem; margin: 1.6rem 0 0.9rem; }
        .wird-section-head:first-of-type { margin-top: 0.5rem; }
        .wird-section-head h2 { margin: 0; font-size: 1.1rem; color: var(--white); font-weight: 700; }
        .wird-section-head .wird-section-count { font-size: 0.72rem; font-weight: 800; color: #0c241a; background: linear-gradient(135deg,#d4af37,#f1d279); border-radius: 50px; padding: 2px 10px; }
        .wird-section-head .wird-caret { margin-left: auto; color: var(--text-muted); font-size: 0.9rem; cursor: pointer; }
        /* ── Grille d'indicateurs ── */
        .wird-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.6rem; margin: 0.85rem 0 0.35rem; }
        .wird-stat { background: rgba(255,255,255,0.04); border: 1px solid var(--glass-border); border-radius: 12px; padding: 0.55rem 0.7rem; text-align: center; }
        .wird-stat .v { font-size: 1.25rem; font-weight: 800; color: var(--white); line-height: 1.1; }
        .wird-stat .v.gold { color: var(--gold); }
        .wird-stat .v.green { color: #7bd8a6; }
        .wird-stat .v.blue { color: #7dd3fc; }
        .wird-stat .l { font-size: 0.68rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 0.15rem; }
        .wird-collapsible { display: none; }
        .wird-collapsible.open { display: block; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__isAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>
            <div class="dashboard-main">

        <div class="wird-wrap">
            <div class="admin-welcome-banner glass-card" style="margin-bottom:1.5rem; padding:1.25rem 2rem; display:flex; justify-content:space-between; align-items:center; border-radius:20px; flex-wrap:wrap; gap:1rem;">
                <span>📖 Lecture du Coran — <strong class="gold-text"><?php echo count($sessions); ?></strong> session(s)</span>
                <a href="<?php echo $__isAdmin ? 'admin_dashboard.php' : 'index.php'; ?>" class="btn btn-secondary btn-sm">← Retour</a>
            </div>

            <?php
                $sessionsEnCours = [];
                $sessionsCloturees = [];
                foreach ($sessions as $s) {
                    if ($s['statut'] === 'en_cours') { $sessionsEnCours[] = $s; }
                    else { $sessionsCloturees[] = $s; }
                }
                // Fonction de rendu d'une carte session (réutilisée pour les 2 sections)
                $renderSessionCard = function ($s) use ($pdo, $prog, $baseUrl, $waGroup) {
                    $p = $prog[(int)$s['id']] ?? ['lues' => 0, 'res' => 0, 'libres' => 30, 'tot' => 30, 'participants' => 0];
                    $lues = (int)$p['lues']; $res = (int)$p['res']; $libres = (int)($p['libres'] ?? 0);
                    $participants = (int)($p['participants'] ?? 0);
                    $tot = max(1, (int)$p['tot']);
                    $pct = round($lues / $tot * 100);
                    $link = $baseUrl . '/wird.php?s=' . (int)$s['id'] . '&t=' . htmlspecialchars($s['token'], ENT_QUOTES);
                    $enCours = ($s['statut'] === 'en_cours');
                    $shareMsg = "Assalamu aleykum 🙏\n\n"
                        . "📖 *Lecture collective du Coran (Khatm)*\n"
                        . "« " . $s['titre'] . " »\n"
                        . (!empty($s['description']) ? ($s['description'] . "\n") : "")
                        . "\nNous lançons la lecture des *30 Juz* du Saint Coran. 🌙\n"
                        . "Chaque personne choisit un ou plusieurs Juz, le(s) lit, puis *valide sa lecture* pour compléter le Khatm ensemble.\n\n"
                        . "👉 Choisissez votre Juz ici :\n" . $link . "\n\n"
                        . "Qu'Allah accepte nos lectures et nos invocations. 🤲\n"
                        . "— Dahira Touba Lyon (Mubawwa-A-Sidqin)";
                    $out = '<div class="glass-card wird-card">';
                    $out .= '<div class="wird-head">';
                    $out .= '<div>';
                    $out .= '<h3>📖 ' . htmlspecialchars($s['titre']) . ' <span class="badge ' . ($enCours ? 'badge-approved' : 'badge-pending') . '" style="margin-left:0.4rem;">' . ($enCours ? 'En cours' : 'Clôturée') . '</span>';
                    if (!empty($s['groupe'])) { $out .= '<span class="badge" style="background:rgba(212,175,55,0.15); color:var(--gold); border:1px solid rgba(212,175,55,0.4); margin-left:0.3rem;">' . htmlspecialchars($s['groupe']) . '</span>'; }
                    $out .= '</h3>';
                    if (!empty($s['description'])) { $out .= '<div class="wird-meta">' . htmlspecialchars($s['description']) . '</div>'; }
                    $out .= '</div>';
                    $out .= '<div class="wird-meta" style="text-align:right;"><strong class="gold-text">' . $lues . '/' . $tot . '</strong> lues<br>' . $res . ' réservées</div>';
                    $out .= '</div>';
                    $out .= '<div class="wird-prog"><i style="width:' . $pct . '%;"></i></div>';
                    // ── Indicateurs ──
                    $out .= '<div class="wird-stats">';
                    $out .= '<div class="wird-stat"><div class="v gold">' . $lues . '</div><div class="l">Juz lus</div></div>';
                    $out .= '<div class="wird-stat"><div class="v blue">' . $participants . '</div><div class="l">Participants</div></div>';
                    $out .= '<div class="wird-stat"><div class="v green">' . $res . '</div><div class="l">Réservés</div></div>';
                    $out .= '<div class="wird-stat"><div class="v">' . $libres . '</div><div class="l">Juz libres</div></div>';
                    $out .= '<div class="wird-stat"><div class="v">' . $pct . '%</div><div class="l">Complété</div></div>';
                    $out .= '</div>';
                    $out .= '<div class="wird-link">';
                    $out .= '<input type="text" readonly value="' . $link . '" id="wl-' . (int)$s['id'] . '" onclick="this.select()">';
                    $out .= '<button type="button" class="btn btn-secondary btn-sm" onclick="copyWird(\'wl-' . (int)$s['id'] . '\')">📋 Copier</button>';
                    $out .= '</div>';
                    $out .= '<div class="wird-actions">';
                    $out .= '<a href="wird.php?s=' . (int)$s['id'] . '&t=' . htmlspecialchars($s['token'], ENT_QUOTES) . '" target="_blank" class="btn btn-primary btn-sm">🔗 Ouvrir</a>';
                    $out .= '<a href="https://wa.me/?text=' . rawurlencode($shareMsg) . '" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366; border:1px solid #25D366; color:#053b21; font-weight:700;">🟢 WhatsApp</a>';
                    if ($waGroup !== '') {
                        $out .= '<button type="button" class="btn btn-sm" style="background:#128C7E; border:1px solid #128C7E; color:#fff; font-weight:700;" onclick="shareGroup(' . htmlspecialchars(json_encode($shareMsg), ENT_QUOTES) . ', ' . htmlspecialchars(json_encode($waGroup), ENT_QUOTES) . ')">🟢 Groupe (copier)</button>';
                    }
                    $out .= '<a href="wird_suivi.php?s=' . (int)$s['id'] . '" class="btn btn-secondary btn-sm">📊 Suivi</a>';
                    // Notifier Juz à valider
                    $out .= '<form action="wird_admin.php" method="POST" style="margin:0;" id="notif-form-' . (int)$s['id'] . '">';
                    $out .= csrf_field();
                    $out .= '<input type="hidden" name="action" value="notify_to_validate">';
                    $out .= '<input type="hidden" name="session_id" value="' . (int)$s['id'] . '">';
                    $out .= '<button type="button" class="btn btn-sm" style="background:#128C7E; border:1px solid #128C7E; color:#fff; font-weight:700;" onclick="confirmNotif(\'notif-form-' . (int)$s['id'] . '\', \'Relancer les Juz à valider\', \'Envoyer une notification (cloche + e-mail) aux ' . (int)$res . ' membre(s) ayant des Juz réservés non validés de la session « ' . addslashes(htmlspecialchars($s['titre'])) . ' » ?\')">🔔 Notifier Juz à valider</button>';
                    $out .= '</form>';
                    // Clôturer / Rouvrir
                    $out .= '<form action="wird_admin.php" method="POST" style="margin:0;" id="sess-form-' . (int)$s['id'] . '">';
                    $out .= csrf_field();
                    $out .= '<input type="hidden" name="session_id" value="' . (int)$s['id'] . '">';
                    if ($enCours) {
                        $out .= '<input type="hidden" name="action" value="close_session">';
                        $out .= '<button type="button" class="btn btn-secondary btn-sm" style="color:var(--warning); border-color:var(--warning);" onclick="confirmWird(\'sess-form-' . (int)$s['id'] . '\', \'Clôturer\', \'Clôturer la session « ' . addslashes(htmlspecialchars($s['titre'])) . ' » ? Elle ne sera plus modifiable.\')">Clôturer</button>';
                    } else {
                        $out .= '<input type="hidden" name="action" value="reopen_session">';
                        $out .= '<button type="submit" class="btn btn-secondary btn-sm">Rouvrir</button>';
                    }
                    $out .= '</form>';
                    // Supprimer
                    $out .= '<form action="wird_admin.php" method="POST" style="margin:0;" id="del-form-' . (int)$s['id'] . '">';
                    $out .= csrf_field();
                    $out .= '<input type="hidden" name="action" value="delete_session">';
                    $out .= '<input type="hidden" name="session_id" value="' . (int)$s['id'] . '">';
                    $out .= '<button type="button" class="btn btn-danger btn-sm" onclick="confirmWird(\'del-form-' . (int)$s['id'] . '\', \'Supprimer\', \'Supprimer définitivement la session « ' . addslashes(htmlspecialchars($s['titre'])) . ' » et tout son suivi ? Cette action est irréversible.\')">Supprimer</button>';
                    $out .= '</form>';
                    $out .= '</div>';
                    $out .= '</div>';
                    return $out;
                };
            ?>

            <!-- ════ SESSIONS EN COURS (haut de page) ════ -->
            <?php if (!empty($sessionsEnCours)): ?>
                <div class="wird-section-head">
                    <h2>🟢 Sessions en cours</h2>
                    <span class="wird-section-count"><?php echo count($sessionsEnCours); ?></span>
                </div>
                <?php foreach ($sessionsEnCours as $s): ?>
                    <?php echo $renderSessionCard($s); ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state" style="padding:1.5rem;"><div class="empty-state-icon">📖</div><p>Aucune session en cours. Lancez-en une ci-dessous.</p></div>
            <?php endif; ?>

            <!-- ════ FORMULAIRE « Lancer une session » (au milieu) ════ -->
            <div class="form-card" style="max-width:none; margin-top:1.5rem; padding:1.5rem; border:1px solid rgba(212,175,55,0.4);">
                <h3 class="gold-text" style="font-size:1.15rem; font-weight:700; margin:0 0 1rem;">➕ Lancer une session de lecture (Khatm — 30 Juz)</h3>
                <form action="wird_admin.php" method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_session">
                    <div class="form-group"><label class="form-label">Titre <span style="color:var(--danger)">*</span></label><input type="text" name="titre" class="form-input" required placeholder="Ex : Khatm Magal 2026"></div>
                    <div class="form-group"><label class="form-label">Groupe</label>
                        <select name="groupe" class="form-input">
                            <option value="">— Aucun —</option>
                            <?php foreach ($groupes as $g): ?><option value="<?php echo htmlspecialchars($g, ENT_QUOTES); ?>"><?php echo htmlspecialchars($g); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Description (facultatif)</label><input type="text" name="description" class="form-input" placeholder="Intention, date limite…"></div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Lancer la session</button>
                </form>
                <div style="border-top:1px solid var(--glass-border); margin-top:1rem; padding-top:0.85rem;">
                    <form action="wird_admin.php" method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="add_groupe">
                        <span style="color:var(--text-muted); font-size:0.82rem;">➕ Nouveau groupe :</span>
                        <input type="text" name="groupe_nom" class="form-input" placeholder="Ex : Kazu Rajab" style="flex:1; min-width:160px;">
                        <button type="submit" class="btn btn-secondary btn-sm">Ajouter</button>
                    </form>
                </div>
            </div>

            <!-- ════ SESSIONS CLÔTURÉES (bas de page, repliées par défaut) ════ -->
            <?php if (!empty($sessionsCloturees)): ?>
                <div class="wird-section-head" style="margin-top:2rem;">
                    <h2>🔒 Sessions clôturées</h2>
                    <span class="wird-section-count"><?php echo count($sessionsCloturees); ?></span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleWirdClosed()" aria-expanded="false" id="wird-closed-toggle">Afficher <span id="wird-closed-caret">▾</span></button>
                </div>
                <div id="wird-closed" class="wird-collapsible">
                    <?php foreach ($sessionsCloturees as $s): ?>
                        <?php echo $renderSessionCard($s); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Paramètres (repliable, fermé par défaut) -->
            <div style="margin-top:1.75rem;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="toggleWirdParams()">⚙️ Paramètres <span id="wp-caret">▾</span></button>
            </div>
            <div id="wird-params" style="display:none; margin-top:0.75rem;">
                <div class="form-card" style="max-width:none; padding:1.1rem 1.5rem;">
                    <h3 class="gold-text" style="font-size:1rem; font-weight:700; margin:0 0 0.5rem;">🟢 Groupe WhatsApp par défaut (facultatif)</h3>
                    <p style="color:var(--text-muted); font-size:0.82rem; margin:0 0 0.75rem;">Collez le <strong>lien d'invitation</strong> du groupe (chat.whatsapp.com/…). Le bouton « Groupe » d'une session copiera le message et ouvrira le groupe pour le coller (WhatsApp ne permet pas l'envoi automatique dans un groupe).</p>
                    <form action="wird_admin.php" method="POST" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_wa_group">
                        <input type="url" name="wa_group_link" class="form-input" placeholder="https://chat.whatsapp.com/…" value="<?php echo htmlspecialchars($waGroup, ENT_QUOTES); ?>" style="flex:1; min-width:220px;">
                        <button type="submit" class="btn btn-secondary btn-sm">Enregistrer</button>
                    </form>
                </div>
            </div>
        </div>

            </div>
        </div>
    </main>

    <?php if (!empty($success) || !empty($error)): ?>
    <div id="notification-modal" class="modal-overlay active" style="display:flex;">
        <div class="modal-card glass-card">
            <div class="modal-header"><?php if (!empty($success)): ?><h3 class="gold-text">Réussi</h3><?php else: ?><h3 style="color:var(--danger);">Erreur</h3><?php endif; ?></div>
            <div class="modal-body"><p><?php echo htmlspecialchars(!empty($success) ? $success : $error); ?></p></div>
            <div class="modal-footer"><button onclick="document.getElementById('notification-modal').style.display='none'" class="btn btn-primary btn-sm">OK</button></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal de confirmation -->
    <div id="confirm-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card">
            <div class="modal-header"><h3 class="gold-text" id="confirm-title">Confirmation</h3></div>
            <div class="modal-body"><p id="confirm-message"></p></div>
            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.5rem;">
                <button type="button" onclick="closeConfirmModal()" class="btn btn-secondary btn-sm">Annuler</button>
                <button type="button" id="confirm-btn" class="btn btn-primary btn-sm">Confirmer</button>
            </div>
        </div>
    </div>

    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <script>
        var confirmTargetForm = '';
        function confirmWird(formId, title, message) {
            confirmTargetForm = formId;
            document.getElementById('confirm-title').textContent = title;
            document.getElementById('confirm-message').textContent = message;
            
            var btn = document.getElementById('confirm-btn');
            if (title.toLowerCase() === 'supprimer') {
                btn.className = 'btn btn-danger btn-sm';
                btn.style = '';
            } else if (title.toLowerCase() === 'clôturer') {
                btn.className = 'btn btn-secondary btn-sm';
                btn.style.color = 'var(--warning)';
                btn.style.borderColor = 'var(--warning)';
            } else if (title.indexOf('Relancer') === 0) {
                btn.className = 'btn btn-primary btn-sm';
                btn.style = '';
            } else {
                btn.className = 'btn btn-primary btn-sm';
                btn.style = '';
            }
            
            var modal = document.getElementById('confirm-modal');
            modal.style.display = 'flex';
            setTimeout(function() {
                modal.classList.add('active');
            }, 10);
        }
        function confirmNotif(formId, title, message) { confirmWird(formId, title, message); }
        function closeConfirmModal() {
            var modal = document.getElementById('confirm-modal');
            modal.classList.remove('active');
            setTimeout(function() {
                modal.style.display = 'none';
            }, 400);
        }
        document.getElementById('confirm-btn').addEventListener('click', function() {
            if (confirmTargetForm) {
                document.getElementById(confirmTargetForm).submit();
            }
        });

        function copyWird(id){
            var el = document.getElementById(id); if (!el) return;
            el.select(); el.setSelectionRange(0, 99999);
            try { navigator.clipboard.writeText(el.value); } catch (e) { document.execCommand('copy'); }
            var b = event.target; var t = b.textContent; b.textContent = '✅ Copié'; setTimeout(function(){ b.textContent = t; }, 1500);
        }
        function shareGroup(msg, link){
            try { navigator.clipboard.writeText(msg); } catch (e) {}
            window.location.href = link;
        }
        function toggleWirdParams(){
            var d = document.getElementById('wird-params'); var c = document.getElementById('wp-caret');
            var show = (d.style.display === 'none' || d.style.display === '');
            d.style.display = show ? 'block' : 'none';
            if (c) c.textContent = show ? '▴' : '▾';
        }
        function toggleWirdClosed(){
            var d = document.getElementById('wird-closed'); var b = document.getElementById('wird-closed-toggle');
            var c = document.getElementById('wird-closed-caret');
            var open = d.classList.toggle('open');
            if (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); b.textContent = open ? 'Masquer' : 'Afficher'; }
            if (c) c.textContent = open ? '▴' : '▾';
        }
    </script>
</body>
</html>
