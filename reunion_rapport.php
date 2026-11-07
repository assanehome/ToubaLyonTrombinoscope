<?php
/**
 * Touba Lyon 2026 - 📄 Rapport de réunion (version imprimable / export PDF)
 *
 * Rassemble le compte rendu, la liste de présence, et les tâches avec leur
 * statut. Optimisé pour l'impression : « Imprimer → Enregistrer au format PDF ».
 * Accessible aux membres/responsables de la commission et aux administrateurs.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_event_etat()
require_once __DIR__ . '/reunion_helper.php';         // reunion_commission_nom(), reunion_periodicite_label()

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

$__reAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$membreId = !empty($_SESSION['player_id']) ? (int) $_SESSION['player_id'] : 0;
if ($membreId === 0 && !$__reAdmin) {
    header('Location: login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$reunion = null;
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM commission_reunions WHERE id = ?");
        $st->execute([$id]);
        $reunion = $st->fetch();
    } catch (Exception $e) { $reunion = null; }
}
if (!$reunion) {
    header('Location: ' . ($__reAdmin ? 'commission_dashboard.php' : 'index.php'));
    exit;
}

$commissionId = (int) $reunion['commission_id'];

// Accès : réservé au côté admin — administrateurs et responsables de la commission
$autorise = $__reAdmin;
if (!$autorise && $membreId > 0) {
    try {
        $q2 = $pdo->prepare("SELECT 1 FROM commission_gestionnaires WHERE commission_id = ? AND membre_id = ? LIMIT 1");
        $q2->execute([$commissionId, $membreId]);
        $autorise = ($q2->fetchColumn() !== false);
    } catch (Exception $e) { $autorise = false; }
}
if (!$autorise) {
    header('Location: index.php');
    exit;
}

// Données de la réunion
$commissionNom = reunion_commission_nom($pdo, $commissionId);
$titre = (string) $reunion['titre'];
$description = (string) ($reunion['description'] ?? '');
$date = (string) $reunion['date_reunion'];
$heure = (string) ($reunion['heure'] ?? '');
$heureFin = trim((string) ($reunion['heure_fin'] ?? ''));
$mode = (string) ($reunion['mode'] ?? 'distance');
$lieu = (string) ($reunion['lieu'] ?? '');
$periodicite = (string) ($reunion['periodicite'] ?? 'ponctuelle');
$compteRendu = (string) ($reunion['compte_rendu'] ?? '');

// Présence (liste des membres ayant validé)
$presents = [];
try {
    $qp = $pdo->prepare("SELECT m.prenom, m.nom FROM presence_validations pv JOIN membres m ON m.id = pv.membre_id WHERE pv.planning_type = 'reunion' AND pv.planning_id = ? ORDER BY m.prenom ASC, m.nom ASC");
    $qp->execute([$id]);
    $presents = $qp->fetchAll();
} catch (Exception $e) { $presents = []; }

// Tâches
$taches = [];
try {
    $qt = $pdo->prepare("SELECT * FROM reunion_taches WHERE reunion_id = ? ORDER BY FIELD(statut,'en_cours','a_faire','fait'), created_at ASC");
    $qt->execute([$id]);
    $taches = $qt->fetchAll();
} catch (Exception $e) { $taches = []; }

// Noms des membres assignés
$assIndex = [];
try {
    $ids = array_values(array_filter(array_map(static function ($t) { return (int) ($t['assignee_id'] ?? 0); }, $taches)));
    if (!empty($ids)) {
        $ids = array_values(array_unique($ids));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $qa = $pdo->prepare("SELECT id, prenom, nom FROM membres WHERE id IN ($ph)");
        $qa->execute($ids);
        while ($r = $qa->fetch()) { $assIndex[(int) $r['id']] = trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')); }
    }
} catch (Exception $e) { $assIndex = []; }

$embed = !empty($_GET['embed']); // affiché dans un iframe (popup) : barre masquée
$statutLabel = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'fait' => 'Fait'];
$nbFait = 0; foreach ($taches as $t) { if ((string) ($t['statut'] ?? '') === 'fait') { $nbFait++; } }

// Message de partage WhatsApp (texte du compte rendu)
$shareText = '';
if (trim($compteRendu) !== '') {
    $plain = str_replace(['</p>', '<br>', '<br/>', '<br />', '</h3>', '</h4>', '</h5>', '</li>', '</blockquote>'], "\n", $compteRendu);
    $plain = preg_replace('/<li[^>]*>/i', '• ', $plain);
    $plain = html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace("/[ \t]+\n/", "\n", $plain)));
    if (mb_strlen($plain) > 1500) { $plain = mb_substr($plain, 0, 1500) . '…'; }
    $shareText = "📝 COMPTE RENDU — " . $titre . "\n"
        . "📋 " . $commissionNom . " · 🗓️ " . date('d/m/Y', strtotime($date)) . "\n\n"
        . $plain . "\n\n"
        . "Secrétariat, Touba Lyon";
}
$waHref = 'https://wa.me/?text=' . rawurlencode($shareText);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapport réunion — <?php echo htmlspecialchars($titre); ?></title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f0f2f0; color: #1b1b1b; font-family: Arial, Helvetica, sans-serif; line-height: 1.55; }
        .page { max-width: 800px; margin: 0 auto; background: #fff; padding: 32px 40px 48px; }
        .rpt-bar { max-width: 800px; margin: 12px auto; display: flex; gap: 0.6rem; justify-content: flex-end; padding: 0 8px; }
        .rpt-btn { border: 0; border-radius: 8px; padding: 0.6rem 1.1rem; font-size: 0.9rem; font-weight: 700; cursor: pointer; text-decoration: none; }
        .rpt-print { background: #1b4332; color: #fff; }
        .rpt-back { background: #e6e6e6; color: #333; }
        .rpt-share { background: #25D366; color: #06281a; }
        header.rpt-head { border-bottom: 3px solid #d4af37; padding-bottom: 16px; margin-bottom: 20px; }
        header.rpt-head .org { font-size: 13px; color: #6b7280; letter-spacing: 1px; text-transform: uppercase; }
        header.rpt-head h1 { font-size: 22px; margin: 6px 0 4px; color: #1b4332; }
        header.rpt-head .sub { font-size: 13px; color: #555; }
        .meta-grid > * { flex: 1 1 calc(50% - 20px); min-width: 0; }
        .meta-grid { display: flex; flex-wrap: wrap; gap: 8px 20px; margin: 16px 0 22px; font-size: 13.5px; }
        .meta-grid .k { color: #6b7280; font-weight: 700; }
        h2.sec { font-size: 15px; color: #1b4332; border-left: 4px solid #d4af37; padding-left: 10px; margin: 26px 0 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .cr-content { font-size: 14px; }
        .cr-content h3, .cr-content h4 { color: #1b4332; margin: 12px 0 4px; }
        .cr-content ul, .cr-content ol { padding-left: 22px; }
        .cr-content blockquote { border-left: 3px solid #d4af37; margin: 8px 0; padding: 2px 0 2px 12px; color: #555; }
        .muted { color: #888; font-size: 13px; }
        table.rpt { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 6px; }
        table.rpt th, table.rpt td { border: 1px solid #e0e0e0; padding: 7px 10px; text-align: left; vertical-align: top; }
        table.rpt th { background: #f6f3e8; color: #1b4332; }
        .pres-list { columns: 2; font-size: 13.5px; margin: 4px 0; padding-left: 18px; }
        .badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 12px; }
        .b-fait { background: #dcfce7; color: #166534; }
        .b-encours { background: #dbeafe; color: #1e40af; }
        .b-afaire { background: #f1f1f1; color: #555; }
        footer.rpt-foot { margin-top: 34px; border-top: 1px solid #e6e6e6; padding-top: 12px; font-size: 11px; color: #9aa39d; text-align: center; }
        @media print {
            body { background: #fff; }
            .rpt-bar { display: none !important; }
            .page { max-width: none; margin: 0; padding: 0 6mm; }
            .pres-list { columns: 3; }
        }
    </style>
</head>
<body>
    <?php if (!$embed): ?>
    <div class="rpt-bar">
        <a href="reunion_detail.php?id=<?php echo (int) $id; ?>" class="rpt-btn rpt-back">← Retour</a>
        <?php if ($shareText !== ''): ?>
        <button type="button" id="rpt-share-pdf" class="rpt-btn rpt-share" onclick="partagerPdf(this)">🟢 Partager le compte rendu</button>
        <?php endif; ?>
        <button type="button" class="rpt-btn rpt-print" onclick="window.print()">🖨️ Imprimer le compte rendu</button>
    </div>
    <?php endif; ?>
    <div class="page">
        <header class="rpt-head">
            <div class="org">Dahira Touba Lyon — Mubawwa-A-Sidqin</div>
            <h1><?php echo htmlspecialchars($titre); ?></h1>
            <div class="sub">Compte rendu de réunion — <?php echo htmlspecialchars($commissionNom); ?></div>
        </header>

        <div class="meta-grid">
            <div><span class="k">📋 Commission :</span> <?php echo htmlspecialchars($commissionNom !== '' ? $commissionNom : '—'); ?></div>
            <div><span class="k">🗓️ Date :</span> <?php echo date('d/m/Y', strtotime($date)); ?></div>
            <div><span class="k">🕐 Horaire :</span> <?php echo $heureFin !== '' ? ('de ' . htmlspecialchars($heure) . ' à ' . htmlspecialchars($heureFin)) : htmlspecialchars($heure); ?></div>
            <div><span class="k">📍 Mode :</span> <?php echo $mode === 'presentiel' ? ('Présentiel' . ($lieu !== '' ? ' — ' . htmlspecialchars($lieu) : '')) : 'À distance'; ?></div>
            <div><span class="k">🔁 Périodicité :</span> <?php echo htmlspecialchars(reunion_periodicite_label($periodicite)); ?></div>
            <div><span class="k">👥 Présents :</span> <?php echo count($presents); ?></div>
        </div>

        <?php if ($description !== ''): ?>
        <h2 class="sec">Ordre du jour</h2>
        <div class="cr-content"><?php echo nl2br(htmlspecialchars($description)); ?></div>
        <?php endif; ?>

        <h2 class="sec">Compte rendu</h2>
        <?php if (trim($compteRendu) !== ''): ?>
            <div class="cr-content"><?php echo $compteRendu; ?></div>
        <?php else: ?>
            <p class="muted">Aucun compte rendu saisi.</p>
        <?php endif; ?>

        <h2 class="sec">Présence (<?php echo count($presents); ?>)</h2>
        <?php if (!empty($presents)): ?>
            <ul class="pres-list">
                <?php foreach ($presents as $p): ?>
                    <li><?php echo htmlspecialchars(trim(($p['prenom'] ?? '') . ' ' . ($p['nom'] ?? ''))); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Aucune présence validée.</p>
        <?php endif; ?>

        <h2 class="sec">Tâches (<?php echo count($taches); ?> · <?php echo $nbFait; ?> terminée<?php echo $nbFait > 1 ? 's' : ''; ?>)</h2>
        <?php if (!empty($taches)): ?>
            <table class="rpt">
                <thead><tr><th style="width:44%;">Tâche</th><th style="width:26%;">Responsable</th><th style="width:15%;">Échéance</th><th style="width:15%;">Statut</th></tr></thead>
                <tbody>
                    <?php foreach ($taches as $t):
                        $stt = (string) ($t['statut'] ?? 'a_faire');
                        $assId = (int) ($t['assignee_id'] ?? 0);
                        $assNom = $assId > 0 && isset($assIndex[$assId]) ? $assIndex[$assId] : '—';
                        $bcl = $stt === 'fait' ? 'b-fait' : ($stt === 'en_cours' ? 'b-encours' : 'b-afaire');
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars((string) $t['titre']); ?></strong>
                            <?php if (!empty(trim((string) ($t['description'] ?? '')))): ?>
                                <div class="muted" style="margin-top:3px;"><?php echo nl2br(htmlspecialchars((string) $t['description'])); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($assNom); ?></td>
                        <td><?php echo !empty($t['echeance']) ? date('d/m/Y', strtotime((string) $t['echeance'])) : '—'; ?></td>
                        <td><span class="badge <?php echo $bcl; ?>"><?php echo htmlspecialchars($statutLabel[$stt] ?? $stt); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">Aucune tâche enregistrée.</p>
        <?php endif; ?>

        <footer class="rpt-foot">
            Document généré le <?php echo date('d/m/Y à H:i'); ?> — Secrétariat, Touba Lyon
        </footer>
    </div>
    <?php if ($embed || $shareText !== ''): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js"></script>
    <script>
        // Partage du compte rendu en PDF joint (WhatsApp via le partage natif du téléphone).
        // Le PDF est préparé en arrière-plan dès l'ouverture pour que le partage soit immédiat.
        var rptNom = <?php echo json_encode('compte-rendu-' . date('Y-m-d', strtotime($date)) . '.pdf'); ?>;
        var rptTitre = <?php echo json_encode('Compte rendu — ' . $titre); ?>;
        var rptTexte = <?php echo json_encode($shareText, JSON_UNESCAPED_UNICODE); ?>;
        var rptWaHref = <?php echo json_encode($waHref); ?>;
        var rptBlobPromise = null;
        function rptPreparerPdf() {
            if (rptBlobPromise) { return rptBlobPromise; }
            var el = document.querySelector('.page');
            rptBlobPromise = html2pdf().set({
                margin: 6,
                filename: rptNom,
                image: { type: 'jpeg', quality: 0.95 },
                html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            }).from(el).outputPdf('blob');
            rptBlobPromise.catch(function () { rptBlobPromise = null; });
            return rptBlobPromise;
        }
        // Dans l'aperçu (popup), le PDF est généré à la demande depuis la fenêtre parente
        window.addEventListener('load', function () {
            if (!<?php echo $embed ? 'true' : 'false'; ?> && typeof html2pdf !== 'undefined') { setTimeout(rptPreparerPdf, 800); }
        });
        function partagerPdf(btn) {
            if (typeof html2pdf === 'undefined') { window.open(rptWaHref, '_blank'); return; }
            var ancien = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '⏳ Préparation du compte rendu…';
            var fin = function () { btn.disabled = false; btn.innerHTML = ancien; };
            rptPreparerPdf().then(function (blob) {
                var file = new File([blob], rptNom, { type: 'application/pdf' });
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    return navigator.share({ files: [file], title: rptTitre, text: rptTexte }).catch(function () {}).then(fin);
                }
                // Ordinateur : le PDF est téléchargé, puis WhatsApp s'ouvre pour le joindre au message
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url; a.download = rptNom;
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
                window.open(rptWaHref, '_blank');
                fin();
            }).catch(function () {
                // Pas d'alerte native : l'échec s'affiche sur le bouton, qui reste utilisable
                btn.disabled = false;
                btn.innerHTML = '⚠️ Échec — réessayer';
                setTimeout(function () { btn.innerHTML = ancien; }, 4000);
            });
        }
    </script>
    <?php endif; ?>
</body>
</html>
