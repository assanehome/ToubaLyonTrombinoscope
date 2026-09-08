<?php
/**
 * Touba Lyon 2026 - 👥 Détail d'une réunion de commission
 *
 * Inspiré de guddi_detail.php. Accessible aux membres/responsables de la
 * commission concernée et aux administrateurs. Validation de présence,
 * lien Zoom (celui du Guddi Àjjuma) pour les réunions à distance.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_param(), dahira_presence_ouverte()
require_once __DIR__ . '/reunion_helper.php';         // reunion_periodicite_label()
require_once __DIR__ . '/notification_helper.php';    // troba_notify_membre()
require_once __DIR__ . '/dahira_emails.php';          // dahira_email_wrap(), send_smtp_mail(), dahira_base_url()

/** Convertit le compte rendu HTML en texte simple (pour WhatsApp / aperçu). */
function reunion_cr_texte(string $html): string
{
    $t = str_replace(['</p>', '<br>', '<br/>', '<br />', '</h3>', '</h4>', '</h5>', '</li>', '</blockquote>'], "\n", $html);
    $t = preg_replace('/<li[^>]*>/i', '• ', $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t]+\n/", "\n", $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim((string) $t);
}

/**
 * Assainit le HTML d'un compte rendu (éditeur riche) : ne conserve qu'un
 * jeu de balises de mise en forme et retire les attributs dangereux.
 */
function reunion_sanitize_html(string $html): string
{
    $allowed = '<p><br><strong><b><em><i><u><s><ul><ol><li><h3><h4><h5><a><blockquote><span><div>';
    $clean = strip_tags($html, $allowed);
    // Retire les gestionnaires d'événements (onclick, onerror, …)
    $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean);
    // Neutralise les href javascript:
    $clean = preg_replace('/(href\s*=\s*)("|\')\s*javascript:[^"\']*(\2)/i', '$1$2#$3', $clean);
    // Retire tous les attributs style
    $clean = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $clean);
    return trim((string) $clean);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__reAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$membreId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;

if ($membreId === 0 && !$__reAdmin) {
    header('Location: login.php');
    exit;
}

// Charger la réunion
$reunion = null;
$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM commission_reunions WHERE id = ?");
        $st->execute([$id]);
        $reunion = $st->fetch();
    } catch (Exception $e) {
        $reunion = null;
    }
}
if (!$reunion) {
    header('Location: ' . ($__reAdmin ? 'commission_dashboard.php' : 'index.php'));
    exit;
}

$commissionId = (int) $reunion['commission_id'];

// Accès : admin, responsable ou membre de la commission
$autorise = $__reAdmin;
if (!$autorise && $membreId > 0) {
    try {
        // membre validé ?
        $sc = $pdo->prepare("SELECT status FROM membres WHERE id = ?");
        $sc->execute([$membreId]);
        $stt = $sc->fetchColumn();
        if ($stt === 'approved') {
            $q1 = $pdo->prepare("SELECT 1 FROM commission_membres WHERE commission_id = ? AND membre_id = ? LIMIT 1");
            $q1->execute([$commissionId, $membreId]);
            $q2 = $pdo->prepare("SELECT 1 FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ? LIMIT 1");
            $q2->execute([$commissionId, $membreId]);
            $autorise = ($q1->fetchColumn() !== false) || ($q2->fetchColumn() !== false);
        }
    } catch (Exception $e) {
        $autorise = false;
    }
}
if (!$autorise) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Le visiteur est-il responsable de la commission (ou admin) ?
$estResponsable = $__reAdmin;
if (!$estResponsable && $membreId > 0) {
    try {
        $qr = $pdo->prepare("SELECT 1 FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ? LIMIT 1");
        $qr->execute([$commissionId, $membreId]);
        $estResponsable = ($qr->fetchColumn() !== false);
    } catch (Exception $e) { $estResponsable = false; }
}

// Membres de la commission (membres + responsables) pour l'attribution des tâches
$membresCommission = [];
$membresCommissionIds = [];
try {
    $qm = $pdo->prepare("
        SELECT DISTINCT m.id, m.prenom, m.nom
        FROM membres m
        WHERE m.status = 'approved' AND (
            m.id IN (SELECT membre_id FROM commission_membres WHERE commission_id = ?)
            OR m.id IN (SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?)
        )
        ORDER BY m.prenom ASC, m.nom ASC
    ");
    $qm->execute([$commissionId, $commissionId]);
    $membresCommission = $qm->fetchAll();
    $membresCommissionIds = array_map(static function ($m) { return (int) $m['id']; }, $membresCommission);
} catch (Exception $e) {
    $membresCommission = [];
    $membresCommissionIds = [];
}
$membresCommissionIndex = [];
foreach ($membresCommission as $mc) { $membresCommissionIndex[(int) $mc['id']] = trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? '')); }

$reunionPubliee = ((int) ($reunion['publie'] ?? 0)) === 1;

// ---------------------------------------------------------------------------
// Actions POST : tâches de réunion (après publication, ouvertes à tout membre
// de la commission ; suppression réservée au créateur ou à un responsable)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_compte_rendu') {
                if (!$reunionPubliee) {
                    $error = "Le compte rendu sera disponible une fois la réunion publiée.";
                } else {
                    $cr = reunion_sanitize_html((string) ($_POST['compte_rendu'] ?? ''));
                    $cr = mb_substr($cr, 0, 60000);
                    $pdo->prepare("UPDATE commission_reunions SET compte_rendu = ?, updated_at = NOW() WHERE id = ?")->execute([($cr !== '' ? $cr : null), $id]);
                    $reunion['compte_rendu'] = $cr;
                    $success = "Compte rendu enregistré.";
                }
            } elseif ($action === 'share_cr_email') {
                if (!$estResponsable) {
                    $error = "Seul un responsable peut envoyer le compte rendu par e-mail.";
                } elseif (trim((string) ($reunion['compte_rendu'] ?? '')) === '') {
                    $error = "Rédigez d'abord le compte rendu avant de l'envoyer.";
                } else {
                    try {
                        $qe = $pdo->prepare("SELECT DISTINCT m.nom, m.prenom, m.email FROM membres m
                            WHERE m.status = 'approved' AND m.email IS NOT NULL AND m.email != '' AND (
                                m.id IN (SELECT membre_id FROM commission_membres WHERE commission_id = ?)
                                OR m.id IN (SELECT membre_id FROM commission_gestionnaires WHERE commission_id = ?))");
                        $qe->execute([$commissionId, $commissionId]);
                        $dests = $qe->fetchAll();
                    } catch (Exception $e) { $dests = []; }
                    if (empty($dests)) {
                        $error = "Aucun membre de la commission avec e-mail.";
                    } else {
                        $rapportUrl = dahira_base_url() . '/reunion_rapport.php?id=' . $id;
                        $inner = '<h1 style="margin:0 0 6px;font-size:20px;color:#1b4332;">📝 Compte rendu — ' . htmlspecialchars((string) $reunion['titre']) . '</h1>'
                            . '<p style="margin:0 0 16px;font-size:12px;color:#9aa39d;">' . htmlspecialchars(reunion_commission_nom($pdo, $commissionId)) . ' · ' . date('d/m/Y', strtotime((string) $reunion['date_reunion'])) . '</p>'
                            . '<div style="margin:0 0 14px;color:#2b2b2b;line-height:1.6;">' . reunion_sanitize_html((string) $reunion['compte_rendu']) . '</div>'
                            . dahira_email_button($rapportUrl, '📄 Voir le rapport complet (PDF)')
                            . '<p style="color:#888;font-size:12px;margin-top:18px;">— Secrétariat, Touba Lyon</p>';
                        $corpsHtml = dahira_email_wrap($inner, 'Compte rendu');
                        $sujet = '📝 Compte rendu — ' . (string) $reunion['titre'] . ' (' . date('d/m/Y', strtotime((string) $reunion['date_reunion'])) . ')';
                        $sent = 0;
                        foreach ($dests as $d) {
                            $nomD = trim(($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? ''));
                            if (send_smtp_mail($d['email'], $nomD !== '' ? $nomD : 'Cher membre', $sujet, $corpsHtml)) { $sent++; }
                        }
                        $success = "Compte rendu envoyé par e-mail à $sent membre(s) de la commission.";
                    }
                }
            } elseif (!$reunionPubliee) {
                $error = "Les tâches sont disponibles une fois la réunion publiée.";
            } elseif ($action === 'add_tache') {
                $tTitre = trim($_POST['tache_titre'] ?? '');
                $tDesc = trim($_POST['tache_description'] ?? '');
                $tAssignee = (int) ($_POST['tache_assignee'] ?? 0);
                $tEcheance = trim($_POST['tache_echeance'] ?? '');
                if ($tAssignee > 0 && !in_array($tAssignee, $membresCommissionIds, true)) { $tAssignee = 0; }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tEcheance)) { $tEcheance = ''; }
                if ($tTitre === '') {
                    $error = "L'intitulé de la tâche est obligatoire.";
                } else {
                    $pdo->prepare("INSERT INTO reunion_taches (reunion_id, commission_id, titre, description, assignee_id, echeance, created_by) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$id, $commissionId, $tTitre, ($tDesc ?: null), ($tAssignee ?: null), ($tEcheance ?: null), ($membreId ?: null)]);
                    // Notifier le membre assigné (sauf s'il s'attribue la tâche lui-même)
                    if ($tAssignee > 0 && $tAssignee !== $membreId) {
                        troba_notify_membre(
                            $pdo,
                            $tAssignee,
                            'reunion',
                            '✅ Nouvelle tâche',
                            'Une tâche vous a été attribuée pour la réunion « ' . (string) $reunion['titre'] . ' » : ' . $tTitre,
                            'reunion_detail.php?id=' . $id
                        );
                    }
                    $success = "Tâche ajoutée.";
                }
            } elseif ($action === 'set_tache_statut') {
                $tid = (int) ($_POST['tache_id'] ?? 0);
                $newStatut = $_POST['statut'] ?? '';
                $statutLabels = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'fait' => 'Fait'];
                if (!in_array($newStatut, ['a_faire', 'en_cours', 'fait'], true)) { $newStatut = ''; }
                if ($tid > 0 && $newStatut !== '') {
                    $chk = $pdo->prepare("SELECT titre, statut, assignee_id, created_by FROM reunion_taches WHERE id = ? AND reunion_id = ?");
                    $chk->execute([$tid, $id]);
                    $tRow = $chk->fetch();
                    if ($tRow) {
                        $ancien = (string) ($tRow['statut'] ?? 'a_faire');
                        $pdo->prepare("UPDATE reunion_taches SET statut = ?, updated_at = NOW() WHERE id = ?")->execute([$newStatut, $tid]);
                        if ($ancien !== $newStatut) {
                            // Commentaire système traçant le changement de statut
                            $auteur = trim((string) ($membresCommissionIndex[$membreId] ?? ''));
                            $txt = '🔄 Statut : ' . ($statutLabels[$ancien] ?? $ancien) . ' → ' . ($statutLabels[$newStatut] ?? $newStatut) . ($auteur !== '' ? ' (par ' . $auteur . ')' : '');
                            $pdo->prepare("INSERT INTO reunion_tache_commentaires (tache_id, membre_id, texte, systeme) VALUES (?,?,?,1)")->execute([$tid, ($membreId ?: null), $txt]);
                            // Notifier l'assigné et le créateur (hors auteur du changement)
                            $notifs = [];
                            $aid = (int) ($tRow['assignee_id'] ?? 0); $cby = (int) ($tRow['created_by'] ?? 0);
                            if ($aid > 0 && $aid !== $membreId) { $notifs[$aid] = true; }
                            if ($cby > 0 && $cby !== $membreId) { $notifs[$cby] = true; }
                            foreach (array_keys($notifs) as $nid) {
                                troba_notify_membre($pdo, (int) $nid, 'reunion', '🔄 Tâche mise à jour', 'La tâche « ' . (string) $tRow['titre'] . ' » est passée à « ' . ($statutLabels[$newStatut] ?? $newStatut) . ' ».', 'reunion_detail.php?id=' . $id);
                            }
                        }
                        // Commentaire libre éventuel joint au changement
                        $comTxt = trim($_POST['commentaire'] ?? '');
                        if ($comTxt !== '') {
                            $pdo->prepare("INSERT INTO reunion_tache_commentaires (tache_id, membre_id, texte, systeme) VALUES (?,?,?,0)")->execute([$tid, ($membreId ?: null), mb_substr($comTxt, 0, 1000)]);
                        }
                        $success = "Statut de la tâche mis à jour.";
                    }
                }
            } elseif ($action === 'add_tache_commentaire') {
                $tid = (int) ($_POST['tache_id'] ?? 0);
                $comTxt = trim($_POST['commentaire'] ?? '');
                if ($tid > 0 && $comTxt !== '') {
                    $chk = $pdo->prepare("SELECT titre, assignee_id, created_by FROM reunion_taches WHERE id = ? AND reunion_id = ?");
                    $chk->execute([$tid, $id]);
                    $tRow = $chk->fetch();
                    if ($tRow) {
                        $pdo->prepare("INSERT INTO reunion_tache_commentaires (tache_id, membre_id, texte, systeme) VALUES (?,?,?,0)")->execute([$tid, ($membreId ?: null), mb_substr($comTxt, 0, 1000)]);
                        $notifs = [];
                        $aid = (int) ($tRow['assignee_id'] ?? 0); $cby = (int) ($tRow['created_by'] ?? 0);
                        if ($aid > 0 && $aid !== $membreId) { $notifs[$aid] = true; }
                        if ($cby > 0 && $cby !== $membreId) { $notifs[$cby] = true; }
                        foreach (array_keys($notifs) as $nid) {
                            troba_notify_membre($pdo, (int) $nid, 'reunion', '💬 Commentaire tâche', 'Nouveau commentaire sur la tâche « ' . (string) $tRow['titre'] . ' ».', 'reunion_detail.php?id=' . $id);
                        }
                        $success = "Commentaire ajouté.";
                    }
                }
            } elseif ($action === 'assign_tache') {
                $tid = (int) ($_POST['tache_id'] ?? 0);
                $tAssignee = (int) ($_POST['tache_assignee'] ?? 0);
                if ($tAssignee > 0 && !in_array($tAssignee, $membresCommissionIds, true)) { $tAssignee = 0; }
                if ($tid > 0) {
                    $chk = $pdo->prepare("SELECT titre FROM reunion_taches WHERE id = ? AND reunion_id = ?");
                    $chk->execute([$tid, $id]);
                    $tRow = $chk->fetch();
                    if ($tRow) {
                        $pdo->prepare("UPDATE reunion_taches SET assignee_id = ?, updated_at = NOW() WHERE id = ?")->execute([($tAssignee ?: null), $tid]);
                        if ($tAssignee > 0 && $tAssignee !== $membreId) {
                            troba_notify_membre(
                                $pdo,
                                $tAssignee,
                                'reunion',
                                '✅ Tâche attribuée',
                                'Une tâche vous a été attribuée pour la réunion « ' . (string) $reunion['titre'] . ' » : ' . (string) $tRow['titre'],
                                'reunion_detail.php?id=' . $id
                            );
                        }
                        $success = "Tâche attribuée.";
                    }
                }
            } elseif ($action === 'delete_tache') {
                $tid = (int) ($_POST['tache_id'] ?? 0);
                if ($tid > 0) {
                    $chk = $pdo->prepare("SELECT created_by FROM reunion_taches WHERE id = ? AND reunion_id = ?");
                    $chk->execute([$tid, $id]);
                    $tRow = $chk->fetch();
                    if ($tRow) {
                        $peutSupprimer = $estResponsable || ((int) ($tRow['created_by'] ?? 0) === $membreId && $membreId > 0);
                        if ($peutSupprimer) {
                            $pdo->prepare("DELETE FROM reunion_taches WHERE id = ?")->execute([$tid]);
                            $pdo->prepare("DELETE FROM reunion_tache_commentaires WHERE tache_id = ?")->execute([$tid]);
                            $success = "Tâche supprimée.";
                        } else {
                            $error = "Seul le créateur de la tâche ou un responsable peut la supprimer.";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Touba Lyon reunion_taches: ' . $e->getMessage());
            $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
        }
    }
}

// Tâches de la réunion
$taches = [];
try {
    $qt = $pdo->prepare("SELECT * FROM reunion_taches WHERE reunion_id = ? ORDER BY (statut = 'fait') ASC, FIELD(statut,'en_cours','a_faire','fait'), created_at ASC");
    $qt->execute([$id]);
    $taches = $qt->fetchAll();
} catch (Exception $e) {
    $taches = [];
}
$tachesStats = ['a_faire' => 0, 'en_cours' => 0, 'fait' => 0];
foreach ($taches as $tc) { $st0 = (string) ($tc['statut'] ?? 'a_faire'); if (isset($tachesStats[$st0])) { $tachesStats[$st0]++; } }

// Commentaires par tâche
$tachesComs = [];
if (!empty($taches)) {
    try {
        $tids = array_map(static function ($t) { return (int) $t['id']; }, $taches);
        $ph = implode(',', array_fill(0, count($tids), '?'));
        $qc = $pdo->prepare("SELECT c.tache_id, c.texte, c.systeme, c.created_at, m.prenom, m.nom
                             FROM reunion_tache_commentaires c LEFT JOIN membres m ON m.id = c.membre_id
                             WHERE c.tache_id IN ($ph) ORDER BY c.created_at ASC, c.id ASC");
        $qc->execute($tids);
        while ($r = $qc->fetch()) { $tachesComs[(int) $r['tache_id']][] = $r; }
    } catch (Exception $e) {
        $tachesComs = [];
    }
}

// Rendu d'une carte de tâche complète (statut, attribution, suppression, commentaires).
// Réutilisée dans la liste des tâches et dans le bloc « Mes tâches » en haut de page.
$renderTacheCard = function (array $tc) use ($id, $membresCommission, $membresCommissionIndex, $estResponsable, $membreId, $tachesComs) {
    $tStatut = (string) ($tc['statut'] ?? 'a_faire');
    $tAssId = (int) ($tc['assignee_id'] ?? 0);
    $tAssNom = $tAssId > 0 && isset($membresCommissionIndex[$tAssId]) ? $membresCommissionIndex[$tAssId] : '';
    $peutSuppr = $estResponsable || ((int) ($tc['created_by'] ?? 0) === $membreId && $membreId > 0);
    $coms = $tachesComs[(int) $tc['id']] ?? [];
    ob_start(); ?>
    <div class="glass-card tk-item <?php echo $tStatut === 'fait' ? 'tk-fait' : ($tStatut === 'en_cours' ? 'tk-encours' : ''); ?>">
        <div style="display:flex; justify-content:space-between; gap:0.6rem; flex-wrap:wrap; align-items:flex-start;">
            <div class="tk-titre <?php echo $tStatut === 'fait' ? 'done' : ''; ?>"><?php echo htmlspecialchars((string) $tc['titre']); ?></div>
            <span class="tk-badge <?php echo $tStatut === 'fait' ? 'tk-badge-f' : ($tStatut === 'en_cours' ? 'tk-badge-e' : 'tk-badge-a'); ?>">
                <?php echo $tStatut === 'fait' ? '✅ Fait' : ($tStatut === 'en_cours' ? '🔧 En cours' : '⏳ À faire'); ?>
            </span>
        </div>
        <?php if (!empty(trim((string) ($tc['description'] ?? '')))): ?>
            <div class="tk-desc"><?php echo nl2br(htmlspecialchars((string) $tc['description'])); ?></div>
        <?php endif; ?>
        <div class="tk-meta">
            <?php if ($tAssNom !== ''): ?>
                <span class="tk-chip tk-chip-assignee">👤 <?php echo htmlspecialchars($tAssNom); ?></span>
            <?php else: ?>
                <span class="tk-chip">👤 Non attribuée</span>
            <?php endif; ?>
            <?php if (!empty($tc['echeance'])): ?>
                <span class="tk-chip">📅 <?php echo date('d/m/Y', strtotime((string) $tc['echeance'])); ?></span>
            <?php endif; ?>
        </div>
        <div class="tk-actions">
            <form action="reunion_detail.php?id=<?php echo (int) $id; ?>" method="POST" style="margin:0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="set_tache_statut">
                <input type="hidden" name="tache_id" value="<?php echo (int) $tc['id']; ?>">
                <select name="statut" onchange="this.form.submit()" title="Changer le statut">
                    <option value="a_faire" <?php echo $tStatut === 'a_faire' ? 'selected' : ''; ?>>⏳ À faire</option>
                    <option value="en_cours" <?php echo $tStatut === 'en_cours' ? 'selected' : ''; ?>>🔧 En cours</option>
                    <option value="fait" <?php echo $tStatut === 'fait' ? 'selected' : ''; ?>>✅ Fait</option>
                </select>
            </form>
            <form action="reunion_detail.php?id=<?php echo (int) $id; ?>" method="POST" style="margin:0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="assign_tache">
                <input type="hidden" name="tache_id" value="<?php echo (int) $tc['id']; ?>">
                <select name="tache_assignee" onchange="this.form.submit()" title="Attribuer à">
                    <option value="">👤 Non attribuée</option>
                    <?php foreach ($membresCommission as $mc): ?>
                        <option value="<?php echo (int) $mc['id']; ?>" <?php echo $tAssId === (int) $mc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? ''))); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if ($peutSuppr): ?>
            <form action="reunion_detail.php?id=<?php echo (int) $id; ?>" method="POST" style="margin:0;" data-confirm="Supprimer cette tâche ?" data-confirm-danger="1">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete_tache">
                <input type="hidden" name="tache_id" value="<?php echo (int) $tc['id']; ?>">
                <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(191,33,33,0.6); color:#fca5a5;">🗑️</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="tk-coms">
            <?php foreach ($coms as $cm):
                $sys = ((int) ($cm['systeme'] ?? 0) === 1);
                $cAuteur = trim(($cm['prenom'] ?? '') . ' ' . ($cm['nom'] ?? ''));
            ?>
                <?php if ($sys): ?>
                <div class="tk-com tk-com-sys"><?php echo htmlspecialchars((string) $cm['texte']); ?> · <span class="tk-com-date"><?php echo date('d/m H:i', strtotime((string) $cm['created_at'])); ?></span></div>
                <?php else: ?>
                <div class="tk-com"><span class="tk-com-auth"><?php echo htmlspecialchars($cAuteur !== '' ? $cAuteur : 'Membre'); ?> :</span> <?php echo nl2br(htmlspecialchars((string) $cm['texte'])); ?> <span class="tk-com-date"><?php echo date('d/m H:i', strtotime((string) $cm['created_at'])); ?></span></div>
                <?php endif; ?>
            <?php endforeach; ?>
            <form action="reunion_detail.php?id=<?php echo (int) $id; ?>" method="POST" class="tk-com-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_tache_commentaire">
                <input type="hidden" name="tache_id" value="<?php echo (int) $tc['id']; ?>">
                <input type="text" name="commentaire" placeholder="💬 Ajouter un commentaire…" maxlength="1000">
                <button type="submit" class="btn btn-secondary btn-sm">Envoyer</button>
            </form>
        </div>
    </div>
    <?php return ob_get_clean();
};

// Données
$commissionNom = reunion_commission_nom($pdo, $commissionId);
$titre = (string) $reunion['titre'];
$description = (string) ($reunion['description'] ?? '');
$date = (string) $reunion['date_reunion'];
$heure = (string) ($reunion['heure'] ?? '20h00');
$heureFin = trim((string) ($reunion['heure_fin'] ?? ''));
$mode = (string) ($reunion['mode'] ?? 'distance');
$lieu = (string) ($reunion['lieu'] ?? '');
$periodicite = (string) ($reunion['periodicite'] ?? 'ponctuelle');
$publie = ((int) ($reunion['publie'] ?? 0)) === 1;
$cloture = ((int) ($reunion['cloture'] ?? 0)) === 1;
$compteRendu = (string) ($reunion['compte_rendu'] ?? '');
$rapportUrl = dahira_base_url() . '/reunion_rapport.php?id=' . $id;
$crWaMsg = '';
if (trim($compteRendu) !== '') {
    $crPlain = reunion_cr_texte($compteRendu);
    if (mb_strlen($crPlain) > 1500) { $crPlain = mb_substr($crPlain, 0, 1500) . '…'; }
    $crWaMsg = "📝 COMPTE RENDU — " . $titre . "\n"
        . "📋 " . $commissionNom . " · 🗓️ " . date('d/m/Y', strtotime($date)) . "\n\n"
        . $crPlain . "\n\n"
        . "📄 Rapport complet :\n" . $rapportUrl . "\n\n"
        . "Secrétariat, Touba Lyon";
}

// Lien de participation (Zoom) : celui de la réunion, sinon le lien par défaut du Guddi
$lienZoom = trim((string) ($reunion['lien'] ?? ''));
if ($lienZoom === '') { $lienZoom = trim(dahira_param($pdo, 'guddi_lien_defaut', '')); }

// Présences validées
$nbPresences = 0;
try {
    $stN = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'reunion' AND planning_id = ?");
    $stN->execute([$id]);
    $nbPresences = (int) $stN->fetchColumn();
} catch (Exception $e) { $nbPresences = 0; }

// Présence du membre connecté
$presenceFaite = false;
if ($membreId > 0) {
    try {
        $stP = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'reunion' AND planning_id = ? AND membre_id = ?");
        $stP->execute([$id, $membreId]);
        $presenceFaite = ((int) $stP->fetchColumn()) > 0;
    } catch (Exception $e) { $presenceFaite = false; }
}

$retour = $__reAdmin ? 'commission_dashboard.php' : 'index.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>👥 Détail réunion — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .gd-wrap { max-width: 860px; margin: 0 auto; }
        .gd-hero { border-radius: 24px; padding: 1.6rem 1.6rem 1.4rem; background: linear-gradient(160deg, rgba(212,175,55,0.14) 0%, rgba(255,255,255,0.03) 100%); border: 2px solid rgba(212,175,55,0.5); margin-bottom: 1.4rem; position: relative; overflow: hidden; }
        .gd-hero::before { content: ''; position: absolute; top: 0; left: -20%; right: -20%; height: 2px; background: linear-gradient(90deg, transparent, rgba(212,175,55,0.9), rgba(241,210,121,0.95), transparent); background-size: 200% 100%; animation: gdFlow 4.5s linear infinite; }
        @keyframes gdFlow { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .gd-hero .gd-date { font-size: 1.6rem; font-weight: 800; color: var(--accent); line-height: 1.25; }
        .gd-hero .gd-date small { display: block; font-size: 0.85rem; font-weight: 400; color: var(--text-muted); margin-top: 0.25rem; }
        .gd-badges { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.8rem; }
        .gd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.9rem; margin-bottom: 1.4rem; }
        .gd-item { border-radius: 14px; padding: 1rem 1.1rem; }
        .gd-item .k { font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.35rem; }
        .gd-item .v { font-size: 0.98rem; font-weight: 600; color: var(--white); white-space: pre-line; word-break: break-word; }
        .gd-back { display: inline-flex; align-items: center; gap: 0.4rem; margin-bottom: 1rem; }
        @keyframes gdCardIn { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
        .gd-anim { animation: gdCardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both; }
        .gd-anim-1 { animation-delay: 0.05s; } .gd-anim-2 { animation-delay: 0.10s; } .gd-anim-3 { animation-delay: 0.15s; } .gd-anim-4 { animation-delay: 0.20s; } .gd-anim-5 { animation-delay: 0.25s; }
        .gd-presence .btn-primary { transition: transform 0.12s ease, box-shadow 0.2s ease, filter 0.2s ease; }
        .gd-presence .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.3); filter: brightness(1.05); }
        @media (max-width: 480px) { .gd-presence { text-align: center; } .gd-presence .btn { width: 100%; } }
        .gd-zoom-btn { display: flex; align-items: center; justify-content: center; gap: 0.9rem; width: 100%; background: linear-gradient(135deg, #2d8cff 0%, #1a63d0 100%); color: #fff; font-weight: 800; font-size: 1.25rem; border: 0; border-radius: 18px; padding: 1.2rem 1.4rem; text-decoration: none; box-shadow: 0 14px 34px rgba(45,140,255,0.4), inset 0 1px 0 rgba(255,255,255,0.2); transition: transform 0.15s ease, box-shadow 0.2s ease, filter 0.2s ease; animation: gdZoomPulse 2.6s ease-in-out infinite; }
        @keyframes gdZoomPulse { 0%, 100% { box-shadow: 0 14px 34px rgba(45,140,255,0.4), inset 0 1px 0 rgba(255,255,255,0.2); } 50% { box-shadow: 0 18px 42px rgba(45,140,255,0.6), inset 0 1px 0 rgba(255,255,255,0.2); } }
        .gd-zoom-btn:hover { transform: translateY(-2px); filter: brightness(1.08); animation-play-state: paused; }
        .gd-zoom-btn .zoom-ic { width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0; background: rgba(255,255,255,0.22); display: inline-flex; align-items: center; justify-content: center; }
        .gd-zoom-btn .zoom-lbl { display: flex; flex-direction: column; align-items: flex-start; line-height: 1.2; }
        .gd-zoom-btn .zoom-lbl small { font-size: 0.78rem; font-weight: 600; opacity: 0.85; }
        .gd-zoom-hint { color: var(--text-muted); font-size: 0.8rem; margin-top: 0.6rem; text-align: center; }
        .pl-badge { font-size: 0.8rem; padding: 0.3rem 0.7rem; border-radius: 6px; }
        .pl-badge-ok { background: rgba(37,211,102,0.15); color: #7bd8a6; border: 1px solid rgba(37,211,102,0.3); }
        .pl-badge-no { background: rgba(255,255,255,0.05); color: var(--text-muted); border: 1px solid var(--glass-border); }
        .pl-badge-pub { background: rgba(45,140,255,0.15); color: #8ec5ff; border: 1px solid rgba(45,140,255,0.4); }
        /* Tâches de réunion */
        .tk-item { border-radius: 14px; padding: 0.9rem 1rem; margin-bottom: 0.7rem; border-left: 3px solid var(--glass-border); }
        .tk-item.tk-fait { opacity: 0.72; border-left-color: rgba(37,211,102,0.5); }
        .tk-item.tk-encours { border-left-color: rgba(45,140,255,0.6); }
        .tk-titre { font-weight: 700; color: var(--white); font-size: 0.98rem; }
        .tk-titre.done { text-decoration: line-through; color: var(--text-muted); }
        .tk-desc { color: var(--text-muted); font-size: 0.85rem; white-space: pre-line; margin-top: 0.3rem; }
        .tk-meta { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-top: 0.55rem; font-size: 0.8rem; color: var(--text-muted); }
        .tk-chip { padding: 0.15rem 0.55rem; border-radius: 50px; border: 1px solid var(--glass-border); font-size: 0.76rem; }
        .tk-chip-assignee { background: rgba(212,175,55,0.14); color: var(--accent); border-color: rgba(212,175,55,0.4); }
        .tk-badge { font-size: 0.72rem; padding: 0.18rem 0.55rem; border-radius: 6px; }
        .tk-badge-a { background: rgba(255,255,255,0.06); color: var(--text-muted); border: 1px solid var(--glass-border); }
        .tk-badge-e { background: rgba(45,140,255,0.15); color: #8ec5ff; border: 1px solid rgba(45,140,255,0.4); }
        .tk-badge-f { background: rgba(37,211,102,0.15); color: #7bd8a6; border: 1px solid rgba(37,211,102,0.35); }
        .tk-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center; margin-top: 0.6rem; }
        .tk-actions select { background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); border-radius: 8px; padding: 0.3rem 0.5rem; font-size: 0.8rem; color-scheme: dark; }
        .tk-actions select option { background-color: #0c241a; color: #fff; }
        @media (max-width: 480px) { .tk-actions { flex-direction: column; align-items: stretch; } .tk-actions form, .tk-actions select { width: 100%; } }
        /* Compte rendu : éditeur riche */
        .cr-toolbar { display: flex; flex-wrap: wrap; gap: 0.3rem; padding: 0.5rem; border: 1px solid var(--glass-border); border-bottom: 0; border-radius: 12px 12px 0 0; background: rgba(255,255,255,0.03); }
        .cr-toolbar button { background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); border-radius: 8px; min-width: 34px; height: 32px; padding: 0 0.5rem; cursor: pointer; font-size: 0.9rem; line-height: 1; transition: all 0.15s ease; }
        .cr-toolbar button:hover { border-color: var(--accent); color: var(--accent); }
        .cr-toolbar .sep { width: 1px; background: var(--glass-border); margin: 0 0.2rem; }
        .cr-editor { min-height: 180px; border: 1px solid var(--glass-border); border-radius: 0 0 12px 12px; padding: 0.9rem 1rem; background: rgba(255,255,255,0.02); color: var(--white); font-size: 0.94rem; line-height: 1.6; outline: none; overflow-y: auto; max-height: 460px; }
        .cr-editor:focus { border-color: var(--accent); }
        .cr-editor:empty::before { content: attr(data-placeholder); color: var(--text-muted); }
        .cr-editor h3, .cr-editor h4 { color: #fff; margin: 0.6rem 0 0.3rem; }
        .cr-editor a { color: var(--accent); }
        .cr-editor ul, .cr-editor ol { padding-left: 1.4rem; }
        .cr-view { color: #e8e8e8; font-size: 0.94rem; line-height: 1.65; }
        .cr-view h3, .cr-view h4 { color: #fff; margin: 0.6rem 0 0.3rem; }
        .cr-view a { color: var(--accent); }
        .cr-view ul, .cr-view ol { padding-left: 1.4rem; }
        .cr-view blockquote { border-left: 3px solid var(--accent); margin: 0.5rem 0; padding: 0.2rem 0 0.2rem 0.9rem; color: var(--text-muted); }
        /* Commentaires de tâche */
        .tk-coms { margin-top: 0.6rem; padding-top: 0.6rem; border-top: 1px dashed var(--glass-border); display: flex; flex-direction: column; gap: 0.35rem; }
        .tk-com { font-size: 0.82rem; color: var(--white); line-height: 1.45; }
        .tk-com-auth { color: var(--accent); font-weight: 700; }
        .tk-com-sys { color: var(--text-muted); font-style: italic; font-size: 0.78rem; }
        .tk-com-date { color: var(--text-muted); font-size: 0.72rem; }
        .tk-com-form { display: flex; flex-direction: column; gap: 0.4rem; margin-top: 0.2rem; }
        .tk-com-form input[type="text"] { width: 100%; box-sizing: border-box; background: rgba(255,255,255,0.05); border: 1px solid var(--glass-border); color: var(--white); border-radius: 8px; padding: 0.4rem 0.6rem; font-size: 0.83rem; }
        .tk-com-form input[type="text"]:focus { outline: none; border-color: var(--accent); }
        .tk-com-form .btn { align-self: flex-start; }
        /* Mobile : boutons pleine largeur et centrés */
        @media (max-width: 600px) {
            .gd-wrap form { width: 100%; }
            .gd-wrap .btn,
            .gd-wrap a[href^="https://wa.me"],
            .gd-wrap a[href^="https://api.whatsapp"] { width: 100%; justify-content: center; box-sizing: border-box; }
            #cr-pdf-modal .modal-footer .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__reAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>

            <div class="admin-content">
                <?php if ($__reAdmin): ?><a href="<?php echo $retour; ?>" class="gd-back btn btn-secondary btn-sm">← Retour</a><?php endif; ?>
                <h1 class="admin-page-title">👥 Détail réunion</h1>

                <?php if (!empty($success)): ?><div class="gd-wrap" style="margin-bottom:0;"><div style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo htmlspecialchars($success); ?></div></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="gd-wrap" style="margin-bottom:0;"><div style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div></div><?php endif; ?>

                <div class="gd-wrap">
                    <!-- En-tête -->
                    <div class="gd-hero gd-anim gd-anim-1">
                        <div class="gd-date">
                            📌 <?php echo htmlspecialchars($titre); ?>
                            <small>📋 <?php echo htmlspecialchars($commissionNom); ?> · 🗓️ <?php echo date('d/m/Y', strtotime($date)); ?> · 🕐 <?php echo $heureFin !== '' ? 'de ' . htmlspecialchars($heure) . ' à ' . htmlspecialchars($heureFin) : 'à partir de ' . htmlspecialchars($heure); ?></small>
                        </div>
                        <div class="gd-badges">
                            <?php $etat = $cloture ? 'termine' : dahira_event_etat($date, $heure, ($heureFin !== '' ? $heureFin : null)); ?>
                            <?php if ($etat === 'termine'): ?>
                                <span class="pl-badge pl-badge-no">🕌 Terminée</span>
                            <?php elseif ($etat === 'encours'): ?>
                                <span class="pl-badge pl-badge-ok badge-live">En cours</span>
                            <?php else: ?>
                                <span class="pl-badge pl-badge-pub">🗓️ À venir</span>
                            <?php endif; ?>
                            <?php if ($periodicite !== 'ponctuelle'): ?>
                                <span class="pl-badge pl-badge-no">🔁 <?php echo htmlspecialchars(reunion_periodicite_label($periodicite)); ?></span>
                            <?php endif; ?>
                            <span class="pl-badge pl-badge-no"><?php echo $mode === 'presentiel' ? '🏛️ Présentiel' : '💻 À distance'; ?></span>
                        </div>
                    </div>

                    <?php
                        $mesTachesReu = array_values(array_filter($taches, function ($t) use ($membreId) {
                            return $membreId > 0 && (int) ($t['assignee_id'] ?? 0) === $membreId && (string) ($t['statut'] ?? '') !== 'fait';
                        }));
                    ?>
                    <?php if (!empty($mesTachesReu)): ?>
                    <div class="glass-card gd-anim gd-anim-2" style="margin-bottom:1.4rem; border:2px solid rgba(212,175,55,0.45);">
                        <h3 style="color:var(--white); margin:0 0 0.7rem;">✅ Mes tâches sur cette réunion (<?php echo count($mesTachesReu); ?>)</h3>
                        <?php foreach ($mesTachesReu as $mtr) { echo $renderTacheCard($mtr); } ?>
                    </div>
                    <?php endif; ?>

                    <!-- Indicateur de participation -->
                    <div class="gd-anim gd-anim-2" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.75rem; margin-bottom:1.4rem;">
                        <div class="glass-card" style="padding:1rem; text-align:center;">
                            <div style="font-size:1.8rem; font-weight:700; color:var(--accent); line-height:1.2;"><?php echo (int) $nbPresences; ?></div>
                            <div style="color:var(--text-muted); font-size:0.82rem;">👥 Participants</div>
                        </div>
                    </div>

                    <!-- Validation de présence -->
                    <?php if ($membreId > 0 && $publie): ?>
                    <div class="glass-card gd-presence gd-anim gd-anim-3" style="margin-bottom:1.4rem; border:2px solid rgba(212,175,55,0.45);">
                        <h3 style="color:var(--white); margin-bottom:0.6rem;">✅ Validez votre présence</h3>
                        <?php if ($presenceFaite): ?>
                            <div style="color:#7bd8a6; font-weight:700; margin-bottom:0.6rem;">✅ Présence confirmée — Diadieuf</div>
                            <button type="button" class="btn btn-secondary btn-sm" style="width:100%; text-align:center;" onclick="annulerPresence('reunion', <?php echo (int) $id; ?>, this)">↩️ Annuler ma présence</button>
                        <?php elseif (dahira_presence_ouverte($date, $heure)): ?>
                            <button type="button" class="btn btn-primary btn-sm" style="width:100%;" onclick="validerPresence('reunion', <?php echo (int) $id; ?>, this)">✅ J'étais présent(e)</button>
                        <?php else: ?>
                            <div style="color:var(--text-muted); font-size:0.85rem;">🔔 La validation sera possible à partir de <?php echo htmlspecialchars($heure); ?> le <?php echo date('d/m/Y', strtotime($date)); ?>.</div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($membreId > 0 && !$publie): ?>
                    <div class="gd-anim gd-anim-3" style="margin-bottom:1.4rem; color:var(--text-muted); font-size:0.85rem;">💤 Cette réunion n'est pas encore publiée : la validation de présence n'est pas disponible.</div>
                    <?php endif; ?>

                    <!-- Participer : lien Zoom (mode distance) -->
                    <?php if ($mode === 'distance' && $lienZoom !== ''): ?>
                    <div class="glass-card gd-anim gd-anim-3" style="margin-bottom:1.4rem; border:2px solid rgba(45,140,255,0.5);">
                        <h3 style="color:var(--white); margin-bottom:0.8rem;"><?php echo $cloture ? '💻 Revoir / participer' : '💻 Participer à cette réunion'; ?></h3>
                        <a class="gd-zoom-btn" href="<?php echo htmlspecialchars($lienZoom); ?>" target="_blank" rel="noopener">
                            <span class="zoom-ic"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" aria-hidden="true"><path d="M17.96 8.55V7.3c0-1.9-1.55-3.45-3.45-3.45H7.29c-1.9 0-3.45 1.55-3.45 3.45v9.4c0 1.9 1.55 3.45 3.45 3.45h7.22c1.9 0 3.45-1.55 3.45-3.45v-1.25l4.5 3.35v-14.7l-4.5 3.35Z" fill="#fff"/></svg></span>
                            <span class="zoom-lbl">Rejoindre en Zoom<small>Réunion à distance</small></span>
                        </a>
                        <div class="gd-zoom-hint"><?php echo $cloture ? 'La réunion est clôturée — vous pouvez toujours rejoindre le lien.' : '🕐 à partir de ' . htmlspecialchars($heure) . ' · le lien s\'ouvre dans un nouvel onglet'; ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Informations -->
                    <div class="gd-grid gd-anim gd-anim-4">
                        <div class="glass-card gd-item">
                            <div class="k">📋 Commission</div>
                            <div class="v"><?php echo $commissionNom !== '' ? htmlspecialchars($commissionNom) : '—'; ?></div>
                        </div>
                        <div class="glass-card gd-item">
                            <div class="k">🗓️ Date & heure</div>
                            <div class="v"><?php echo date('d/m/Y', strtotime($date)); ?> · <?php echo $heureFin !== '' ? 'de ' . htmlspecialchars($heure) . ' à ' . htmlspecialchars($heureFin) : htmlspecialchars($heure); ?></div>
                        </div>
                        <?php if ($mode === 'presentiel' && $lieu !== ''): ?>
                        <div class="glass-card gd-item">
                            <div class="k">📍 Lieu</div>
                            <div class="v"><?php echo htmlspecialchars($lieu); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="glass-card gd-item">
                            <div class="k">🔁 Périodicité</div>
                            <div class="v"><?php echo htmlspecialchars(reunion_periodicite_label($periodicite)); ?></div>
                        </div>
                        <div class="glass-card gd-item">
                            <div class="k">👥 Participants</div>
                            <div class="v"><?php echo $nbPresences > 0 ? (int) $nbPresences : '—'; ?></div>
                        </div>
                        <?php if ($description !== ''): ?>
                        <div class="glass-card gd-item" style="grid-column:1 / -1;">
                            <div class="k">📝 Ordre du jour</div>
                            <div class="v"><?php echo nl2br(htmlspecialchars($description)); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex; flex-direction:column;">
                    <!-- Tâches de la réunion -->
                    <div class="glass-card gd-anim gd-anim-5" style="margin-bottom:1.4rem;">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.4rem;">
                            <h3 style="color:var(--white); margin:0;">✅ Tâches de la réunion</h3>
                            <?php if ($reunionPubliee): ?>
                            <span style="font-size:0.8rem; color:var(--text-muted);">
                                ⏳ <?php echo (int) $tachesStats['a_faire']; ?> · 🔧 <?php echo (int) $tachesStats['en_cours']; ?> · ✅ <?php echo (int) $tachesStats['fait']; ?>
                            </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!$reunionPubliee): ?>
                            <p style="color:var(--text-muted); font-size:0.88rem; margin:0.4rem 0 0;">💤 Les tâches seront disponibles une fois la réunion publiée.</p>
                        <?php else: ?>
                            <p style="color:var(--text-muted); font-size:0.84rem; margin:0 0 0.9rem;">Tout membre de la commission peut ajouter des tâches et les attribuer.</p>

                            <div style="display:flex; flex-direction:column;">
                            <!-- Créer une tâche (bloc bordé) -->
                            <form action="reunion_detail.php?id=<?php echo (int) $id; ?>" method="POST" style="margin:0 0 1.1rem; padding:0.9rem; border:1px solid var(--glass-border); border-radius:12px; background:rgba(255,255,255,0.02);">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="add_tache">
                                <div style="font-weight:700; color:#fff; font-size:0.92rem; margin-bottom:0.7rem;">➕ Créer une tâche</div>
                                <div class="form-group" style="margin-bottom:0.6rem;">
                                    <label class="form-label">📌 Intitulé de la tâche</label>
                                    <input type="text" name="tache_titre" class="form-input" style="width:100%;" placeholder="Ex : Réserver la salle" maxlength="255" required>
                                </div>
                                <div class="form-group" style="margin-bottom:0.6rem;">
                                    <label class="form-label">📝 Précisions (facultatif)</label>
                                    <textarea name="tache_description" class="form-input" style="width:100%; min-height:70px; resize:vertical;" placeholder="Détails éventuels…"></textarea>
                                </div>
                                <div style="display:flex; gap:0.6rem; flex-wrap:wrap;">
                                    <div class="form-group" style="flex:1 1 220px; margin-bottom:0.6rem;">
                                        <label class="form-label">👤 Attribuer à</label>
                                        <select name="tache_assignee" class="form-input" style="width:100%;">
                                            <option value="">— Non attribuée —</option>
                                            <?php foreach ($membresCommission as $mc): ?>
                                                <option value="<?php echo (int) $mc['id']; ?>"><?php echo htmlspecialchars(trim(($mc['prenom'] ?? '') . ' ' . ($mc['nom'] ?? ''))); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex:1 1 160px; margin-bottom:0.6rem;">
                                        <label class="form-label">📅 Échéance (facultatif)</label>
                                        <input type="date" name="tache_echeance" class="form-input" style="width:100%;">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">➕ Ajouter la tâche</button>
                            </form>

                            <!-- Liste des tâches (bloc bordé, affiché au-dessus du formulaire) -->
                            <div style="order:-1; border:1px solid var(--glass-border); border-radius:12px; padding:0.9rem; margin-bottom:1.1rem; background:rgba(255,255,255,0.015);">
                            <div style="font-weight:700; color:#fff; font-size:0.92rem; margin-bottom:0.7rem;">📋 Liste des tâches</div>
                            <?php if (empty($taches)): ?>
                                <p style="color:var(--text-muted); text-align:center; padding:0.6rem 0; margin:0;">Aucune tâche pour le moment.</p>
                            <?php else: ?>
                                <?php foreach ($taches as $tc) { echo $renderTacheCard($tc); } ?>
                            <?php endif; ?>
                            </div><!-- /liste (order:-1) -->
                            </div><!-- /flex tâches -->
                        <?php endif; ?>
                    </div>

                    <!-- Compte rendu (placé avant les tâches via l'ordre CSS) -->
                    <div class="glass-card gd-anim gd-anim-5" style="order:-1; margin-bottom:1.4rem;">
                        <h3 style="color:var(--white); margin:0 0 0.6rem;">📝 Compte rendu</h3>
                        <?php if (!$reunionPubliee): ?>
                            <p style="color:var(--text-muted); font-size:0.88rem; margin:0;">💤 Le compte rendu pourra être saisi une fois la réunion publiée.</p>
                        <?php else: ?>
                            <form action="reunion_detail.php?id=<?php echo (int) $id; ?>" method="POST" style="margin:0;" onsubmit="document.getElementById('cr-input').value = document.getElementById('cr-editor').innerHTML;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="save_compte_rendu">
                                <input type="hidden" name="compte_rendu" id="cr-input">
                                <div class="cr-toolbar" id="cr-toolbar">
                                    <button type="button" data-cmd="bold" title="Gras"><strong>G</strong></button>
                                    <button type="button" data-cmd="italic" title="Italique"><em>I</em></button>
                                    <button type="button" data-cmd="underline" title="Souligné"><u>S</u></button>
                                    <span class="sep"></span>
                                    <button type="button" data-cmd="formatBlock" data-val="h3" title="Titre">H₃</button>
                                    <button type="button" data-cmd="formatBlock" data-val="h4" title="Sous-titre">H₄</button>
                                    <button type="button" data-cmd="formatBlock" data-val="p" title="Paragraphe">¶</button>
                                    <span class="sep"></span>
                                    <button type="button" data-cmd="insertUnorderedList" title="Liste à puces">•—</button>
                                    <button type="button" data-cmd="insertOrderedList" title="Liste numérotée">1.</button>
                                    <button type="button" data-cmd="formatBlock" data-val="blockquote" title="Citation">❝</button>
                                    <span class="sep"></span>
                                    <button type="button" id="cr-link" title="Lien">🔗</button>
                                    <button type="button" data-cmd="removeFormat" title="Effacer la mise en forme">✖︎</button>
                                </div>
                                <div class="cr-editor" id="cr-editor" contenteditable="true" data-placeholder="Rédigez le compte rendu de la réunion…"><?php echo $compteRendu; ?></div>
                                <div style="margin-top:0.7rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                                    <button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer le compte rendu</button>
                                </div>
                            </form>
                            <?php if ($estResponsable && $crWaMsg !== ''): ?>
                            <div style="margin-top:0.7rem; padding-top:0.7rem; border-top:1px solid var(--glass-border); display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
                                <span style="color:var(--text-muted); font-size:0.82rem;">Partager :</span>
                                <?php echo dahira_wa_button(dahira_wa_link(null, $crWaMsg), 'WhatsApp', true); ?>
                                <form action="reunion_detail.php?id=<?php echo (int) $id; ?>" method="POST" style="margin:0;" data-confirm="Envoyer le compte rendu par e-mail aux membres de la commission ?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="share_cr_email">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="border-color:rgba(45,140,255,0.6); color:#8ec5ff;">📧 Partager par e-mail</button>
                                </form>
                                <button type="button" class="btn btn-secondary btn-sm" style="border-color:var(--accent); color:var(--accent);" onclick="openCrPdf()">📄 PDF du compte rendu</button>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    </div><!-- /flex (compte rendu avant tâches) -->

                    <!-- Membres présents (trombinoscope) -->
                    <?php $presMembres = dahira_presence_membres($pdo, 'reunion', $id); ?>
                    <div class="glass-card gd-anim gd-anim-5" style="margin-bottom:1.4rem;">
                        <h3 style="color:var(--white); margin:0 0 0.8rem;">👥 Membres présents (<?php echo count($presMembres); ?>)</h3>
                        <?php if (!empty($presMembres)): ?>
                            <?php echo dahira_presence_trombi_html($presMembres); ?>
                        <?php else: ?>
                            <p style="color:var(--text-muted); font-size:0.88rem; margin:0;">Aucune présence validée pour le moment.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="gd-anim gd-anim-5" style="display:flex; gap:0.6rem; flex-wrap:wrap;">
                        <a href="<?php echo $retour; ?>" class="btn btn-secondary btn-sm">← Retour</a>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>

    <?php if ($estResponsable && $crWaMsg !== ''): ?>
    <!-- Popup : aperçu du PDF du compte rendu + partage -->
    <div id="cr-pdf-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card" style="max-width:900px; width:calc(100vw - 24px); height:88vh; display:flex; flex-direction:column;">
            <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; gap:0.6rem;">
                <h3 style="color:var(--accent); margin:0;">📄 Compte rendu</h3>
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeCrPdf()">✕ Fermer</button>
            </div>
            <div style="flex:1; min-height:0; margin:0.7rem 0; border-radius:10px; overflow:hidden; background:#fff;">
                <iframe id="cr-pdf-frame" src="" title="Compte rendu" style="width:100%; height:100%; border:0;"></iframe>
            </div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; flex-wrap:wrap; justify-content:flex-end; align-items:center;">
                <button type="button" id="cr-share-pdf" class="btn btn-primary btn-sm" onclick="sharePdf(this)">📤 Partager le PDF</button>
            </div>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js"></script>
    <script>
        function openCrPdf() {
            var f = document.getElementById('cr-pdf-frame');
            if (f && !f.getAttribute('src')) { f.setAttribute('src', 'reunion_rapport.php?id=<?php echo (int) $id; ?>&embed=1'); }
            var m = document.getElementById('cr-pdf-modal');
            if (!m) { return; }
            m.style.display = 'flex';
            setTimeout(function () { m.classList.add('active'); }, 10);
        }
        function closeCrPdf() {
            var m = document.getElementById('cr-pdf-modal');
            if (!m) { return; }
            m.classList.remove('active');
            setTimeout(function () { m.style.display = 'none'; }, 300);
        }
        // Génère le PDF depuis l'aperçu (iframe) puis le partage (partage natif) ou le télécharge.
        function sharePdf(btn) {
            var f = document.getElementById('cr-pdf-frame');
            if (!f || !f.contentDocument || typeof html2pdf === 'undefined') {
                if (window.modernAlert) { window.modernAlert('Aperçu non prêt. Réessayez dans un instant.'); } else { alert('Aperçu non prêt.'); }
                return;
            }
            var el = f.contentDocument.querySelector('.page') || f.contentDocument.body;
            var oldLabel = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Génération…'; }
            var opt = {
                margin: 6,
                filename: 'compte-rendu.pdf',
                image: { type: 'jpeg', quality: 0.95 },
                html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };
            html2pdf().set(opt).from(el).outputPdf('blob').then(function (blob) {
                var file = new File([blob], 'compte-rendu.pdf', { type: 'application/pdf' });
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    navigator.share({ files: [file], title: 'Compte rendu' }).catch(function () {}).finally(function () {
                        if (btn) { btn.disabled = false; btn.innerHTML = oldLabel; }
                    });
                } else {
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url; a.download = 'compte-rendu.pdf';
                    document.body.appendChild(a); a.click(); a.remove();
                    setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
                    if (btn) { btn.disabled = false; btn.innerHTML = oldLabel; }
                }
            }).catch(function () {
                if (btn) { btn.disabled = false; btn.innerHTML = oldLabel; }
                if (window.modernAlert) { window.modernAlert('Échec de la génération du PDF.'); } else { alert('Échec de la génération du PDF.'); }
            });
        }
        (function () {
            var m = document.getElementById('cr-pdf-modal');
            if (m) { m.addEventListener('click', function (e) { if (e.target === m) { closeCrPdf(); } }); }
        })();
    </script>
    <?php endif; ?>

    <?php include __DIR__ . '/modern_popup.php'; ?>
    <script>
        var presenceToken = <?php echo json_encode(isset($trobaCsrf) ? $trobaCsrf : ''); ?>;
        function validerPresence(type, id, btn) {
            if (presenceToken === '') {
                try { presenceToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content'); } catch (e) {}
            }
            var corps = new URLSearchParams();
            corps.set('action', 'validate');
            corps.set('type', type);
            corps.set('id', String(id));
            corps.set('csrf_token', presenceToken);
            fetch('presence_validate.php', { method: 'POST', body: corps })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { window.location.reload(); }
                    else if (window.modernAlert) { window.modernAlert(d.msg || 'Erreur.', 'Erreur'); }
                    else { alert(d.msg || 'Erreur.'); }
                })
                .catch(function () {
                    if (window.modernAlert) { window.modernAlert('Une erreur est survenue. Réessayez.', 'Erreur'); }
                    else { alert('Une erreur est survenue. Réessayez.'); }
                });
        }
        function annulerPresence(type, id, btn) {
            if (window.modernConfirm) {
                window.modernConfirm(
                    'Voulez-vous vraiment <strong>annuler votre présence</strong> à cette réunion ?',
                    function () { annulerPresenceOk(type, id); },
                    { danger: true, bouton: 'Oui, annuler', titre: '↩️ Annuler ma présence' }
                );
            } else {
                annulerPresenceOk(type, id);
            }
        }
        function annulerPresenceOk(type, id) {
            var corps = new URLSearchParams();
            corps.set('action', 'cancel');
            corps.set('type', type);
            corps.set('id', String(id));
            corps.set('csrf_token', presenceToken);
            fetch('presence_validate.php', { method: 'POST', body: corps })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { window.location.reload(); }
                    else if (window.modernAlert) { window.modernAlert(d.msg || 'Erreur.'); }
                    else { alert(d.msg || 'Erreur.'); }
                })
                .catch(function () {
                    if (window.modernAlert) { window.modernAlert('Une erreur est survenue. Réessayez.'); }
                    else { alert('Une erreur est survenue. Réessayez.'); }
                });
        }
    </script>
    <script>
        // Éditeur riche du compte rendu (léger, sans dépendance externe)
        (function () {
            var editor = document.getElementById('cr-editor');
            var toolbar = document.getElementById('cr-toolbar');
            if (!editor || !toolbar) { return; }
            toolbar.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-cmd]');
                if (!btn) { return; }
                e.preventDefault();
                editor.focus();
                var cmd = btn.getAttribute('data-cmd');
                var val = btn.getAttribute('data-val') || null;
                if (cmd === 'formatBlock' && val) {
                    try { document.execCommand('formatBlock', false, val); }
                    catch (err) { document.execCommand('formatBlock', false, '<' + val + '>'); }
                } else {
                    document.execCommand(cmd, false, null);
                }
            });
            var linkBtn = document.getElementById('cr-link');
            if (linkBtn) {
                linkBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    editor.focus();
                    var url = window.prompt('Adresse du lien (https://…) :', 'https://');
                    if (url) { document.execCommand('createLink', false, url); }
                });
            }
        })();
    </script>
</body>
</html>
