<?php
/**
 * Touba Lyon 2026 - Ancienne page « Rôles Kurels ».
 *
 * Les rôles Kurels sont désormais gérés « comme une commission » :
 *  - les responsables de chaque Kurel se désignent dans l'espace Kurels,
 *  - le droit de créer/gérer les Kurels vient d'être responsable de la commission « Kurels ».
 * Cette page redirige donc vers l'espace Kurels.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Location: kourel_dashboard.php');
exit;
