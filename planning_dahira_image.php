<?php
/**
 * Touba Lyon 2026 - Image du programme du Dahira (SVG)
 *
 * Utilise l'affiche « Affiche_Dahira.jpg » comme modèle de fond (1080x1350),
 * puis superpose les informations modifiées : date, lieu, horaires et le
 * programme détaillé du dimanche.
 *
 * Sans dépendance GD : le SVG est généré directement. L'image de fond est
 * référencée depuis le même dossier (le serveur la sert comme ressource).
 *
 * Usage : planning_dahira_image.php?id=<id du planning>
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/planning_dahira_helper.php';

$id = (int) ($_GET['id'] ?? 0);
$row = null;
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT * FROM dahira_plannings WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
    } catch (Exception $e) {
        $row = null;
    }
}
if (!$row) {
    http_response_code(404);
    die("Planning introuvable.");
}

$lieu = dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
$debut = dahira_param($pdo, 'dahira_debut', '17h00');
$fin = dahira_param($pdo, 'dahira_fin', '20h30');
$date = $row['date_dahira'];
$programme = (string) ($row['programme'] ?? '');

// Mois en français
$moisFr = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
$moisNum = (int) date('n', strtotime($date));
$annee = date('Y', strtotime($date));
$jourNum = (int) date('j', strtotime($date));
$dateLongue = ucfirst(dahira_jour_fr($date)) . ' ' . $jourNum . ' ' . ($moisFr[$moisNum] ?? '') . ' ' . $annee;

// Découpe le programme en lignes pour l'image
$lignes = preg_split('/\r\n|\r|\n/', trim($programme) !== '' ? $programme : "Programme à venir…");

function dahira_escape_svg(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: no-store');
?>
<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="1350" viewBox="0 0 1080 1350">
  <defs>
    <linearGradient id="gold" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0%" stop-color="#d4af37"/>
      <stop offset="50%" stop-color="#f4dd8c"/>
      <stop offset="100%" stop-color="#d4af37"/>
    </linearGradient>
  </defs>

  <!-- Affiche en fond (modèle) -->
  <image href="Affiche_Dahira.jpg" x="0" y="0" width="1080" height="1350" preserveAspectRatio="xMidYMid slice"/>

  <!-- Voile sombre pour la lisibilité (haut et bas) -->
  <rect x="0" y="0" width="1080" height="330" fill="rgba(5,18,13,0.55)"/>
  <rect x="0" y="920" width="1080" height="430" fill="rgba(5,18,13,0.72)"/>

  <!-- Bordure dorée -->
  <rect x="30" y="30" width="1020" height="1290" rx="24" fill="none" stroke="rgba(212,175,55,0.6)" stroke-width="4"/>

  <!-- Entête -->
  <text x="540" y="150" text-anchor="middle" font-family="Georgia, serif" font-size="54" font-weight="bold" fill="url(#gold)">DAHIRA TOUBA LYON</text>
  <text x="540" y="198" text-anchor="middle" font-family="Georgia, serif" font-size="24" fill="#f4dd8c" letter-spacing="4">M U B A W W A &nbsp;-&nbsp; A - S I D Q I N</text>
  <line x1="340" y1="228" x2="740" y2="228" stroke="url(#gold)" stroke-width="2"/>

  <!-- Date -->
  <text x="540" y="292" text-anchor="middle" font-family="Arial, sans-serif" font-size="58" font-weight="bold" fill="#ffffff"><?php echo dahira_escape_svg($dateLongue); ?></text>

  <!-- Programme (zone basse, sur fond semi-transparent) -->
  <text x="540" y="980" text-anchor="middle" font-family="Georgia, serif" font-size="42" font-weight="bold" fill="url(#gold)">Programme</text>
  <line x1="380" y1="1005" x2="700" y2="1005" stroke="url(#gold)" stroke-width="2"/>

  <?php
  // Rend le programme : chaque ligne (heure grasse si début = HHhMM ou HH:MM)
  $py = 1055;
  $x = 130;
  foreach ($lignes as $lg) {
      $lg = trim($lg);
      if ($lg === '') { $py += 20; continue; }
      $bold = (bool) preg_match('/^\s*(\d{1,2}[hH:]\d{2}|\d{1,2}[hH])\s*[-|]?\s*/', $lg);
      $color = $bold ? '#f4dd8c' : '#eef4ef';
      $fontW = $bold ? 'bold' : 'normal';
      $size = $bold ? 32 : 28;
      $lgEsc = dahira_escape_svg($lg);
      $longueur = function_exists('mb_strlen') ? mb_strlen($lg, 'UTF-8') : strlen($lg);
      if ($longueur > 58) {
          $chars = function_exists('preg_split') ? preg_split('//u', $lg, -1, PREG_SPLIT_NO_EMPTY) : str_split($lg);
          if (!is_array($chars)) { $chars = str_split($lg); }
          $parts = [];
          $cur = '';
          $curLen = 0;
          foreach ($chars as $ch) {
              $cur .= $ch;
              $curLen++;
              if ($curLen >= 54) { $parts[] = $cur; $cur = ''; $curLen = 0; }
          }
          if ($cur !== '') { $parts[] = $cur; }
          foreach ($parts as $part) {
              echo '<text x="' . $x . '" y="' . $py . '" font-family="Arial, sans-serif" font-size="' . $size . '" font-weight="' . $fontW . '" fill="' . $color . '">' . dahira_escape_svg(trim($part)) . '</text>';
              $py += 40;
          }
      } else {
          echo '<text x="' . $x . '" y="' . $py . '" font-family="Arial, sans-serif" font-size="' . $size . '" font-weight="' . $fontW . '" fill="' . $color . '">' . $lgEsc . '</text>';
          $py += 42;
      }
      if ($py > 1280) { break; }
  }
  ?>

  <!-- Pied : lieu & horaires -->
  <text x="540" y="1325" text-anchor="middle" font-family="Arial, sans-serif" font-size="25" font-weight="bold" fill="#ffffff">🕐 <?php echo dahira_escape_svg($debut); ?> – <?php echo dahira_escape_svg($fin); ?> &nbsp;·&nbsp; 📍 <?php echo dahira_escape_svg($lieu); ?></text>
</svg>
