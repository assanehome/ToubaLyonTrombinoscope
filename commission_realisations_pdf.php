<?php
/**
 * Touba Lyon 2026 - Rapport des réalisations d'une commission sur une période
 *
 * Page claire, prête à imprimer, et téléchargée en PDF (html2pdf, comme le
 * compte rendu de réunion) : indicateurs, répartition par type, puis chaque
 * réalisation avec sa date, son lieu, sa quantité et ses détails.
 *   commission_realisations_pdf.php?id=12&du=2027-01-01&au=2027-12-31
 * Mêmes droits que la page des réalisations (administrateurs, responsables).
 */
require_once __DIR__ . '/commission_guard.php'; // $__isAdmin, $__managedCommissions, $pdo
require_once __DIR__ . '/realisations_helper.php';

realisations_schema($pdo);

$cid = (int) ($_GET['id'] ?? 0);
$canManage = $__isAdmin || in_array($cid, $__managedCommissions, true);
$commission = null;
if ($cid > 0) {
    try {
        $st = $pdo->prepare("SELECT id, nom FROM commissions WHERE id = ?");
        $st->execute([$cid]);
        $commission = $st->fetch();
    } catch (Exception $e) {
        $commission = null;
    }
}
if (!$commission || !$canManage) {
    header('Location: commission_dashboard.php');
    exit;
}
[$du, $au] = realisations_periode($_GET);
$liste = realisations_liste($pdo, $cid, $du, $au);
$ind = realisations_indicateurs($liste, $du, $au);
$types = realisations_types($pdo, $cid);
$descType = [];
foreach ($types as $t) { $descType[(string) $t['nom']] = (string) ($t['description'] ?? ''); }
// Ordre chronologique dans le rapport
$chrono = array_reverse($liste);
$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$periode = 'du ' . date('d/m/Y', strtotime($du)) . ' au ' . date('d/m/Y', strtotime($au));
$nomFichier = 'realisations-' . preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', (string) $commission['nom']) ?: 'commission'))
    . '-' . $du . '-' . $au . '.pdf';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réalisations — <?php echo $h($commission['nom']); ?> — <?php echo $h($periode); ?></title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f0f2f0; color: #1b1b1b; font-family: Arial, Helvetica, sans-serif; line-height: 1.5; }
        .page { max-width: 800px; margin: 0 auto; background: #fff; padding: 32px 40px 48px; }
        .rpt-bar { max-width: 800px; margin: 12px auto; display: flex; gap: 0.6rem; justify-content: flex-end; flex-wrap: wrap; padding: 0 8px; }
        .rpt-btn { border: 0; border-radius: 8px; padding: 0.6rem 1.1rem; font-size: 0.9rem; font-weight: 700; cursor: pointer; text-decoration: none; }
        .rpt-pdf { background: #d4af37; color: #1b1b1b; }
        .rpt-print { background: #1b4332; color: #fff; }
        .rpt-back { background: #e6e6e6; color: #333; }
        header.rpt-head { border-bottom: 3px solid #d4af37; padding-bottom: 14px; margin-bottom: 18px; }
        header.rpt-head .org { font-size: 12px; color: #6b7280; letter-spacing: 1px; text-transform: uppercase; }
        header.rpt-head h1 { font-size: 21px; margin: 6px 0 4px; color: #1b4332; }
        header.rpt-head .sub { font-size: 13px; color: #555; }
        .kpis { display: flex; flex-wrap: wrap; gap: 10px; margin: 6px 0 20px; }
        .kpis > * { flex: 1 1 calc(25% - 10px); min-width: 0; }
        .kpi { border: 1px solid #e0e0e0; border-radius: 8px; padding: 10px; text-align: center; }
        .kpi .n { font-size: 20px; font-weight: 800; color: #1b4332; }
        .kpi .l { font-size: 10.5px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.3px; margin-top: 2px; }
        h2.sec { font-size: 14px; color: #1b4332; border-left: 4px solid #d4af37; padding-left: 10px; margin: 22px 0 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        table.rpt { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.rpt th, table.rpt td { border: 1px solid #e0e0e0; padding: 6px 9px; text-align: left; vertical-align: top; }
        table.rpt th { background: #f6f3e8; color: #1b4332; }
        table.rpt td.num, table.rpt th.num { text-align: right; white-space: nowrap; }
        .barre { height: 8px; background: #eee; border-radius: 4px; overflow: hidden; min-width: 80px; }
        .barre span { display: block; height: 100%; background: #d4af37; }
        .desc { color: #6b7280; font-size: 11.5px; }
        .item { border: 1px solid #e6e6e6; border-radius: 8px; padding: 9px 12px; margin-bottom: 8px; page-break-inside: avoid; }
        .item .t { font-weight: 700; color: #1b1b1b; }
        .item .m { font-size: 12px; color: #555; margin-top: 2px; }
        .item .d { font-size: 12.5px; margin-top: 5px; }
        .badge { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 10px; background: #f6f3e8; color: #8a6d12; border: 1px solid #e8d9a8; margin-left: 4px; }
        .muted { color: #888; font-size: 13px; }
        footer.rpt-foot { margin-top: 30px; border-top: 1px solid #e6e6e6; padding-top: 10px; font-size: 11px; color: #9aa39d; text-align: center; }
        @media (max-width: 600px) { .page { padding: 20px 16px 32px; } .kpis > * { flex-basis: calc(50% - 10px); } }
        @media print {
            body { background: #fff; }
            .rpt-bar { display: none !important; }
            .page { max-width: none; margin: 0; padding: 0 6mm; }
        }
    </style>
</head>
<body>
    <div class="rpt-bar">
        <a href="commission_realisations.php?id=<?php echo $cid; ?>&du=<?php echo $h($du); ?>&au=<?php echo $h($au); ?>" class="rpt-btn rpt-back">← Retour</a>
        <button type="button" class="rpt-btn rpt-pdf" id="rpt-pdf" onclick="telechargerPdf(this)">📄 Télécharger le PDF</button>
        <button type="button" class="rpt-btn rpt-print" onclick="window.print()">🖨️ Imprimer</button>
    </div>
    <div class="page">
        <header class="rpt-head">
            <div class="org">Dahira Touba Lyon — Commission</div>
            <h1>Réalisations — <?php echo $h($commission['nom']); ?></h1>
            <div class="sub">Période <?php echo $h($periode); ?> · édité le <?php echo date('d/m/Y'); ?></div>
        </header>

        <div class="kpis">
            <div class="kpi"><div class="n"><?php echo (int) $ind['nb']; ?></div><div class="l">Réalisations</div></div>
            <div class="kpi"><div class="n"><?php echo number_format($ind['quantite'], 0, ',', ' '); ?></div><div class="l">Quantité / bénéficiaires</div></div>
            <div class="kpi"><div class="n"><?php echo (int) $ind['types']; ?></div><div class="l">Types réalisés</div></div>
            <div class="kpi"><div class="n" style="font-size:15px;"><?php echo $ind['derniere'] ? date('d/m/Y', strtotime($ind['derniere'])) : '—'; ?></div><div class="l">Dernière réalisation</div></div>
        </div>

        <?php if (empty($liste)): ?>
            <p class="muted">Aucune réalisation enregistrée sur cette période.</p>
        <?php else: ?>
            <h2 class="sec">Répartition par type</h2>
            <table class="rpt">
                <thead><tr><th>Type de réalisation</th><th class="num">Nombre</th><th class="num">Quantité</th><th style="width:30%;">Part</th></tr></thead>
                <tbody>
                <?php foreach ($ind['par_type'] as $nomType => $pt): ?>
                    <tr>
                        <td><strong><?php echo $h($nomType); ?></strong><?php if (($descType[$nomType] ?? '') !== ''): ?><div class="desc"><?php echo $h($descType[$nomType]); ?></div><?php endif; ?></td>
                        <td class="num"><?php echo (int) $pt['nb']; ?></td>
                        <td class="num"><?php echo $pt['quantite'] > 0 ? number_format($pt['quantite'], 0, ',', ' ') : '—'; ?></td>
                        <td><div class="barre"><span style="width:<?php echo (int) round(100 * $pt['nb'] / max(1, $ind['nb'])); ?>%;"></span></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 class="sec">Par mois</h2>
            <table class="rpt">
                <thead><tr><th>Mois</th><th class="num">Réalisations</th></tr></thead>
                <tbody>
                <?php foreach ($ind['par_mois'] as $ym => $nbm): ?>
                    <tr><td><?php echo $h(realisations_mois_court($ym)); ?></td><td class="num"><?php echo (int) $nbm; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 class="sec">Détail des réalisations</h2>
            <?php foreach ($chrono as $r): ?>
                <div class="item">
                    <div class="t"><?php echo $h($r['titre']); ?><?php if (!empty($r['type_nom'])): ?><span class="badge"><?php echo $h($r['type_nom']); ?></span><?php endif; ?></div>
                    <div class="m">📅 <?php echo date('d/m/Y', strtotime($r['date_realisation'])); ?><?php echo !empty($r['lieu']) ? ' · 📍 ' . $h($r['lieu']) : ''; ?><?php echo $r['quantite'] !== null ? ' · 👥 ' . (int) $r['quantite'] : ''; ?></div>
                    <?php if (!empty($r['details'])): ?><div class="d"><?php echo nl2br($h($r['details'])); ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <footer class="rpt-foot">Dahira Touba Lyon — rapport des réalisations de la commission <?php echo $h($commission['nom']); ?>, <?php echo $h($periode); ?></footer>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js"></script>
    <script>
        // Le PDF est composé à partir de la page, puis téléchargé
        function telechargerPdf(btn) {
            if (typeof html2pdf === 'undefined') { window.print(); return; }
            var ancien = btn.innerHTML;
            btn.disabled = true; btn.innerHTML = '⏳ Préparation du PDF…';
            html2pdf().set({
                margin: 8,
                filename: <?php echo json_encode($nomFichier); ?>,
                image: { type: 'jpeg', quality: 0.95 },
                html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak: { mode: ['css', 'legacy'], avoid: '.item, tr' }
            }).from(document.querySelector('.page')).save().then(function () {
                btn.disabled = false; btn.innerHTML = ancien;
            }).catch(function () {
                btn.disabled = false; btn.innerHTML = '⚠️ Échec — réessayer';
                setTimeout(function () { btn.innerHTML = ancien; }, 4000);
            });
        }
    </script>
</body>
</html>
