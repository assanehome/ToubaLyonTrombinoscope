<?php
/**
 * Touba Lyon 2026 - 📅 Planning Hebdomadaire du Dahira
 *
 * Associé à la commission « Secrétariat Général ». Permet de :
 *   - définir les paramètres du groupe (lieu, horaires, lien WhatsApp) ;
 *   - générer les dimanches de Dahira (un dimanche sur deux) sur une période ;
 *   - saisir le programme détaillé de chaque séance ;
 *   - préparer le message WhatsApp (lien wa.me) et l'e-mail aux membres ;
 *   - générer une image (SVG) du programme, à joindre au message.
 */
require_once __DIR__ . '/planning_guard.php'; // admins + responsables Secrétariat Général
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dahira_emails.php';
require_once __DIR__ . '/planning_dahira_helper.php';

$error = '';
$success = '';

// Commission « Secrétariat Général » (associée au planning)
function dahira_commission_id(PDO $pdo): int
{
    static $cid = null;
    if ($cid !== null) {
        return $cid;
    }
    try {
        $st = $pdo->prepare("SELECT id FROM commissions WHERE LOWER(nom) LIKE '%secrétariat général%' OR LOWER(nom) LIKE '%secretariat general%' LIMIT 1");
        $st->execute();
        $cid = (int) $st->fetchColumn();
        if ($cid > 0) {
            return $cid;
        }
    } catch (Exception $e) {
        // table absente
    }
    $cid = 0;
    return $cid;
}

$commissionId = dahira_commission_id($pdo);

// ---------------------------------------------------------------------------
// Actions POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';

        // Réglages généraux
        if ($action === 'save_params') {
            dahira_set_param($pdo, 'dahira_lieu', trim($_POST['lieu'] ?? ''));
            dahira_set_param($pdo, 'dahira_debut', trim($_POST['debut'] ?? ''));
            dahira_set_param($pdo, 'dahira_fin', trim($_POST['fin'] ?? ''));
            // Programme par défaut (modèle réutilisable d'un Dahira à l'autre)
            dahira_set_param($pdo, 'dahira_programme_defaut', trim($_POST['programme_defaut'] ?? ''));
            // Lien du groupe WhatsApp : clé partagée avec wird_admin (wa_group_link)
            $waGroupLink = trim($_POST['groupe_wa'] ?? '');
            dahira_set_param($pdo, 'wa_group_link', $waGroupLink);
            $success = "Paramètres du Dahira enregistrés.";
        }

        // Charger le programme par défaut dans le Prochain Dahira
        elseif ($action === 'load_default_programme') {
            $id = (int) ($_POST['id'] ?? 0);
            $defaut = dahira_param($pdo, 'dahira_programme_defaut', '');
            // Modèle intégré utilisé si aucun programme par défaut n'est défini
            if ($defaut === '') {
                $defaut = "17h00 | 19h15  DURUS\n"
                    . "19h15 | 20h15  Prestation Xassidas\n"
                    . "20h15 | 20h30  Gammu\n"
                    . "20h30 | 20h45  REX Maggal\n"
                    . "20h45 | 20h55  Zikrullah";
            }
            if ($id > 0) {
                try {
                    $pdo->prepare("UPDATE dahira_plannings SET programme = ?, updated_at = NOW() WHERE id = ?")->execute([$defaut, $id]);
                    $success = "Programme par défaut chargé dans le Prochain Dahira.";
                } catch (Exception $e) {
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Générer les dimanches (un dimanche sur deux) sur une période
        elseif ($action === 'generate_sundays') {
            $from = $_POST['period_start'] ?? '';
            $to = $_POST['period_end'] ?? '';
            $alterner = (($_POST['alterner'] ?? '1') === '1');
            $dimanches = dahira_dimanches($from, $to, $alterner);
            if (empty($dimanches)) {
                $error = "Veuillez indiquer une période valide contenant au moins un dimanche.";
            } else {
                $added = 0;
                try {
                    $st = $pdo->prepare("INSERT IGNORE INTO dahira_plannings (date_dahira, commission_id) VALUES (?, ?)");
                    foreach ($dimanches as $d) {
                        $st->execute([$d, $commissionId > 0 ? $commissionId : null]);
                        if ($st->rowCount() > 0) { $added++; }
                    }
                    $success = count($dimanches) . " dimanche(s) de Dahira planifié(s) (" . $added . " nouveau(x), " . (count($dimanches) - $added) . " déjà présent(s)).";
                } catch (Exception $e) {
                    error_log('Touba Lyon planning - génération : ' . $e->getMessage());
                    $error = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
                }
            }
        }

        // Enregistrer le programme d'un dimanche
        elseif ($action === 'save_programme') {
            $id = (int) ($_POST['id'] ?? 0);
            $programme = trim($_POST['programme'] ?? '');
            if ($id > 0) {
                try {
                    $pdo->prepare("UPDATE dahira_plannings SET programme = ?, updated_at = NOW() WHERE id = ?")->execute([$programme !== '' ? $programme : null, $id]);
                    $success = "Programme enregistré.";
                } catch (Exception $e) {
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Basculer le statut « Dahira » / « Pas Dahira » d'un dimanche
        elseif ($action === 'toggle_dahira') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $pdo->prepare("UPDATE dahira_plannings SET a_dahira = 1 - a_dahira, updated_at = NOW() WHERE id = ?")->execute([$id]);
                    $success = "Statut du dimanche mis à jour.";
                } catch (Exception $e) {
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Publier / dépublier le Dahira sur l'accueil membre (validation de présence)
        elseif ($action === 'toggle_publie') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $st = $pdo->prepare("SELECT date_dahira, publie, programme FROM dahira_plannings WHERE id = ?");
                    $st->execute([$id]);
                    $rowP = $st->fetch();
                    if (!$rowP) {
                        $error = "Ce dimanche n'existe plus.";
                    } else {
                        $nouveau = ((int)($rowP['publie'] ?? 0)) === 1 ? 0 : 1;
                        $pdo->prepare("UPDATE dahira_plannings SET publie = ?, updated_at = NOW() WHERE id = ?")->execute([$nouveau, $id]);
                        if ($nouveau === 1) {
                            // Notifier tous les membres validés
                            require_once __DIR__ . '/notification_helper.php';
                            $dateLongue = dahira_date_longue($rowP['date_dahira']);
                            $body = '🕌 Dahira du ' . $dateLongue . ' — Validez votre présence !';
                            troba_notify_all_membres($pdo, 'dahira_publie', '🕌 Dahira publié', $body, 'dahira_detail.php?id=' . $id);
                            $success = "Dahira publié : " . count($pdo->query("SELECT id FROM membres WHERE status = 'approved'")->fetchAll()) . " membre(s) notifié(s).";
                        } else {
                            $success = "Dahira dépublié.";
                        }
                    }
                } catch (Exception $e) {
                    error_log('Touba Lyon planning - publication : ' . $e->getMessage());
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Clôturer un Dahira passé (ou du jour), nombre de participants = présences validées
        elseif ($action === 'cloture') {
            $id = (int) ($_POST['id'] ?? 0);
            $nb = trim($_POST['nb_participants'] ?? '');
            if ($id > 0) {
                try {
                    $st = $pdo->prepare("SELECT date_dahira, a_dahira FROM dahira_plannings WHERE id = ?");
                    $st->execute([$id]);
                    $row = $st->fetch();
                    if (!$row) {
                        $error = "Ce dimanche n'existe plus.";
                    } elseif (((int)($row['a_dahira'] ?? 1)) !== 1) {
                        $error = "Ce dimanche n'est pas un Dahira.";
                    } elseif ($row['date_dahira'] > date('Y-m-d')) {
                        $error = "Impossible de clôturer : le Dahira n'a pas encore eu lieu.";
                    } else {
                        // Si le champ est vide : nombre = présences validées par les membres
                        if ($nb === '') {
                            try {
                                $stP = $pdo->prepare("SELECT COUNT(*) FROM presence_validations WHERE planning_type = 'dahira' AND planning_id = ?");
                                $stP->execute([$id]);
                                $nb = (string) $stP->fetchColumn();
                            } catch (Exception $e) {
                                $nb = '';
                            }
                        }
                        $pdo->prepare("UPDATE dahira_plannings SET cloture = 1, nb_participants = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$nb !== '' ? (int) $nb : null, $id]);
                        $success = "Dahira clôturé." . ($nb !== '' ? " Participants : $nb." : "");
                    }
                } catch (Exception $e) {
                    error_log('Touba Lyon planning - clôture : ' . $e->getMessage());
                    $error = "Une erreur technique est survenue.";
                }
            }
        }

        // Envoyer l'e-mail d'annonce aux membres
        elseif ($action === 'send_email') {
            $id = (int) ($_POST['id'] ?? 0);
            require_once __DIR__ . '/send_mail.php';
            try {
                $st = $pdo->prepare("SELECT * FROM dahira_plannings WHERE id = ?");
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) {
                    $error = "Ce dimanche n'existe plus.";
                } else {
                    $lieu = dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
                    $debut = dahira_param($pdo, 'dahira_debut', '17h00');
                    $fin = dahira_param($pdo, 'dahira_fin', '20h30');
                    $date = $row['date_dahira'];
                    $programme = (string) ($row['programme'] ?? '');
                    $destinataires = dahira_destinataires($pdo, $commissionId);
                    if (empty($destinataires)) {
                        $error = "Aucun membre à prévenir (aucun membre validé rattaché à la commission).";
                    } else {
                        $sujet = '📅 Dahira — ' . dahira_date_longue($date);
                        $corps = dahira_email_annonce($date, $lieu, $debut, $fin, $programme);
                        $sent = 0;
                        foreach ($destinataires as $m) {
                            $nom = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
                            if (!empty($m['email'])) {
                                if (send_smtp_mail($m['email'], $nom !== '' ? $nom : 'Cher membre', $sujet, $corps)) { $sent++; }
                            }
                        }
                        $pdo->prepare("UPDATE dahira_plannings SET email_envoye = 1, updated_at = NOW() WHERE id = ?")->execute([$id]);
                        $success = "Annonce envoyée par e-mail à $sent membre(s).";
                    }
                }
            } catch (Exception $e) {
                error_log('Touba Lyon planning - email : ' . $e->getMessage());
                $error = "Une erreur technique est survenue lors de l'envoi.";
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Données
// ---------------------------------------------------------------------------
$lieu = dahira_param($pdo, 'dahira_lieu', '1 rue du 35 régiment d\'aviation, 69500 Bron');
$debut = dahira_param($pdo, 'dahira_debut', '17h00');
$fin = dahira_param($pdo, 'dahira_fin', '20h30');
$groupeWa = dahira_param($pdo, 'wa_group_link', '');
$programmeDefaut = dahira_param($pdo, 'dahira_programme_defaut', '');

$plannings = [];
try {
    $plannings = $pdo->query("SELECT * FROM dahira_plannings ORDER BY date_dahira ASC")->fetchAll();
} catch (Exception $e) {
    $plannings = [];
}

// Prochain vrai Dahira (a_dahira = 1). Les dimanches « sans Dahira » ne sont
// pas proposés à l'annonce.
$prochain = null;
foreach ($plannings as $p) {
    if ($p['date_dahira'] >= date('Y-m-d') && (int) ($p['a_dahira'] ?? 1) === 1) { $prochain = $p; break; }
}

// Séparation à venir / passés
$planningsAvenir = [];
$planningsPasses = [];
$today = date('Y-m-d');
foreach ($plannings as $p) {
    if ($p['date_dahira'] >= $today) {
        $planningsAvenir[] = $p;
    } else {
        $planningsPasses[] = $p;
    }
}
$planningsPassesCloturees = array_values(array_filter($planningsPasses, static function ($p) {
    return ((int)($p['cloture'] ?? 0)) === 1;
}));
// Séances passées non clôturées (à traiter) : seuls les vrais Dahiras (a_dahira = 1)
$planningsPassesNonClotures = array_values(array_filter($planningsPasses, static function ($p) {
    return ((int)($p['cloture'] ?? 0)) !== 1 && ((int)($p['a_dahira'] ?? 1)) === 1;
}));

// Nombre de présences validées par séance (source de l'indicateur Participants)
$presencesDahira = [];
try {
    $stPres = $pdo->query("SELECT planning_id, COUNT(*) AS n FROM presence_validations WHERE planning_type = 'dahira' GROUP BY planning_id");
    while ($r = $stPres->fetch()) { $presencesDahira[(int)$r['planning_id']] = (int)$r['n']; }
} catch (Exception $e) {
    $presencesDahira = [];
}

// Nombre de participants d'un Dahira : présences validées, sinon saisie manuelle
$nbPartDahira = static function (array $p) use ($presencesDahira): int {
    $presence = $presencesDahira[(int)($p['id'] ?? 0)] ?? 0;
    return $presence > 0 ? $presence : (int)($p['nb_participants'] ?? 0);
};

// Indicateurs globaux (groupe replié, comme admin_guddi)
$statsGlobales = [
    'a_venir'       => count($planningsAvenir),
    'seances'       => count($planningsPasses),
    'cloturees'     => count($planningsPassesCloturees),
    'participants'  => 0,
];
foreach ($planningsPasses as $p) {
    $statsGlobales['participants'] += $nbPartDahira($p);
}

// Statistiques par année (filtre des indicateurs)
$statsParAnnee = [];
foreach ($plannings as $p) {
    $annee = date('Y', strtotime($p['date_dahira']));
    if (!isset($statsParAnnee[$annee])) {
        $statsParAnnee[$annee] = ['a_venir' => 0, 'seances' => 0, 'cloturees' => 0, 'participants' => 0];
    }
    if ($p['date_dahira'] >= $today) {
        $statsParAnnee[$annee]['a_venir']++;
    } else {
        $statsParAnnee[$annee]['seances']++;
        if (((int)($p['cloture'] ?? 0)) === 1) {
            $statsParAnnee[$annee]['cloturees']++;
        }
        $statsParAnnee[$annee]['participants'] += $nbPartDahira($p);
    }
}
krsort($statsParAnnee);
$anneesDisponibles = array_keys($statsParAnnee);

// Nombre de membres à prévenir
$nbMembres = count(dahira_destinataires($pdo, $commissionId));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📅 Planning Hebdomadaire — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .pl-wrap { max-width: 980px; margin: 2rem auto; }
        .pl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
        .pl-card { border-radius: 16px; padding: 1rem 1.1rem; display: flex; flex-direction: column; gap: 0.6rem; }
        .pl-date { font-size: 1.05rem; font-weight: 700; color: var(--white); }
        .pl-date small { color: var(--text-muted); font-weight: 400; font-size: 0.78rem; }
        .pl-prog { white-space: pre-wrap; color: var(--text-muted); font-size: 0.82rem; line-height: 1.5; background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.7rem; max-height: 220px; overflow-y: auto; }
        .pl-prog:empty::before { content: 'Aucun programme saisi.'; color: rgba(255,255,255,0.35); }
        .pl-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .pl-badge { font-size: 0.7rem; padding: 0.15rem 0.45rem; border-radius: 6px; }
        .pl-badge-ok { background: rgba(37,211,102,0.15); color: #7bd8a6; border: 1px solid rgba(37,211,102,0.3); }
        .pl-badge-no { background: rgba(255,255,255,0.05); color: var(--text-muted); border: 1px solid var(--glass-border); }
        details.pl-details summary { cursor: pointer; color: var(--text-muted); font-size: 0.8rem; }
        details.pl-details pre { white-space: pre-wrap; font-family: inherit; font-size: 0.83rem; color: var(--white); background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border); border-radius: 10px; padding: 0.9rem; margin-top: 0.5rem; }
        .pl-params { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.8rem; }
        .pl-params .form-group { margin: 0; }
        /* Panneaux repliables de cette page : padding réduit, hauteur compacte */
        details.glass-card { padding: 0.9rem 1.1rem; }
        details.glass-card > summary { padding: 0.25rem 0; line-height: 1.3; }
        /* Boutons de filtre par année (indicateurs) : pilules modernes */
        .pl-annee-btn {
            border-radius: 50px;
            padding: 0.45rem 1rem;
            font-size: 0.85rem;
            border: 1px solid var(--glass-border);
            background: rgba(255,255,255,0.04);
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition);
        }
        .pl-annee-btn:hover { border-color: var(--accent); color: var(--accent); background: rgba(212,175,55,0.08); }
        .pl-annee-btn.active {
            background: linear-gradient(135deg, #d4af37, #e8c766);
            color: #08150f;
            border-color: transparent;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(212,175,55,0.35);
        }
        /* Flèche du panneau dépliable : rotation à l'ouverture */
        details[open] > summary .pl-chevron { transform: rotate(90deg); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php if ($__plAdmin) { include __DIR__ . '/admin_menu.php'; } else { include __DIR__ . '/member_menu.php'; } ?>

            <div class="admin-content">
                <h1 class="admin-page-title">📅 Planning Hebdomadaire du Dahira</h1>
                <p class="admin-page-desc" style="color:var(--text-muted); margin-top:-0.4rem; font-size:0.9rem;">
                    Commission Secrétariat Général — un Dahira a lieu un dimanche sur deux.
                    <?php echo $commissionId > 0 ? '' : '<span style="color:#ffd873;">(Aucune commission « Secrétariat Général » trouvée : les annonces iront à tous les membres validés.)</span>'; ?>
                </p>

                <?php if (!empty($success)): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <!-- Indicateurs (groupe replié fermé par défaut, filtrables par année) -->
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">📊 Indicateurs <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <div style="margin-top:0.9rem;">
                        <?php
                        // Année en cours sélectionnée par défaut (comme admin_guddi)
                        $anneeCourante = (int) date('Y');
                        $anneeCouranteDispo = in_array($anneeCourante, array_map('intval', $anneesDisponibles), true);
                        $filtreDefaut = $anneeCouranteDispo ? $anneeCourante : 'all';
                        ?>
                        <?php if (count($anneesDisponibles) > 0): ?>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem;">
                            <button type="button" class="btn btn-sm pl-annee-btn <?php echo $filtreDefaut === 'all' ? 'active' : ''; ?>" data-annee="all">Toutes</button>
                            <?php foreach ($anneesDisponibles as $an): ?>
                                <button type="button" class="btn btn-sm pl-annee-btn <?php echo ((int)$an === $filtreDefaut) ? 'active' : ''; ?>" data-annee="<?php echo (int)$an; ?>"><?php echo (int)$an; ?></button>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="pl-stats" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.75rem;">
                            <?php
                            $cartes = [
                                ['key' => 'a_venir', 'label' => '🕌 À venir', 'color' => 'var(--accent)'],
                                ['key' => 'seances', 'label' => '🕰️ Dahiras passés', 'color' => 'var(--white)'],
                                ['key' => 'cloturees', 'label' => '✅ Clôturés', 'color' => '#7bd8a6'],
                                ['key' => 'participants', 'label' => '👥 Participants', 'color' => 'var(--white)'],
                            ];
                            foreach ($cartes as $carte):
                                $valAffichee = $filtreDefaut === 'all' ? $statsGlobales[$carte['key']] : ($statsParAnnee[$filtreDefaut][$carte['key']] ?? 0);
                                $dataAttrs = 'data-global="' . (int)$statsGlobales[$carte['key']] . '"';
                                foreach ($anneesDisponibles as $an) {
                                    $v = $statsParAnnee[$an][$carte['key']] ?? 0;
                                    $dataAttrs .= ' data-' . (int)$an . '="' . (int)$v . '"';
                                }
                            ?>
                            <div class="glass-card" style="padding:1rem; text-align:center;">
                                <div class="pl-stat-valeur" style="font-size:1.6rem; font-weight:700; color:<?php echo $carte['color']; ?>;" <?php echo $dataAttrs; ?>><?php echo (int)$valAffichee; ?></div>
                                <div style="color:var(--text-muted); font-size:0.82rem;"><?php echo $carte['label']; ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>

                <!-- Prochain Dahira -->
                <div class="glass-card" style="margin-bottom:1.5rem; border:2px solid rgba(212,175,55,0.55); background:linear-gradient(160deg, rgba(212,175,55,0.09) 0%, rgba(255,255,255,0.02) 100%);">
                    <?php if ($prochain):
                        $pdate = $prochain['date_dahira'];
                        $waMsg = dahira_message_annonce($pdate, $lieu, $debut, $fin, (string)($prochain['programme'] ?? ''));
                        $waLink = dahira_wa_link(null, $waMsg);
                    ?>
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1.5rem; flex-wrap:wrap;">
                            <div>
                                <h3 style="color:var(--white); font-size:1.1rem; margin-bottom:0.4rem;">📣 Prochain Dahira</h3>
                                <div style="font-size:1.6rem; font-weight:700; color:var(--accent); line-height:1.2;">
                                    <?php echo ucfirst(dahira_jour_fr($pdate)) . ' ' . date('d/m/Y', strtotime($pdate)); ?>
                                </div>
                                <div style="font-size:0.95rem; color:var(--white); margin-top:0.3rem;">
                                    🕐 de <strong><?php echo htmlspecialchars($debut); ?></strong> à <strong><?php echo htmlspecialchars($fin); ?></strong>
                                </div>
                                <div style="font-size:0.82rem; color:var(--text-muted); margin-top:0.3rem; white-space:pre-line;">📍 <?php echo htmlspecialchars($lieu); ?></div>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:0.55rem; min-width:240px;">
                                <?php echo dahira_wa_button($waLink, 'Partager au groupe WhatsApp'); ?>
                                <?php if ($groupeWa !== ''): ?>
                                    <button type="button" class="btn btn-sm" style="background:#128C7E; border:1px solid #128C7E; color:#fff; font-weight:700;" onclick="shareGroup(<?php echo htmlspecialchars(json_encode($waMsg), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($groupeWa), ENT_QUOTES); ?>)">🟢 Groupe (copier)</button>
                                <?php endif; ?>
                                <form action="admin_planning.php" method="POST" style="margin:0;" data-confirm="Envoyer l'annonce du Dahira du <?php echo date('d/m/Y', strtotime($pdate)); ?> par e-mail à <?php echo $nbMembres; ?> membre(s) ?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="send_email">
                                    <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;" <?php echo $nbMembres === 0 ? 'disabled' : ''; ?>>
                                        📧 Envoyer par e-mail (<?php echo $nbMembres; ?> membre<?php echo $nbMembres > 1 ? 's' : ''; ?>)
                                    </button>
                                </form>
                                <a href="planning_dahira_image.php?id=<?php echo (int)$prochain['id']; ?>" target="_blank" class="btn btn-secondary btn-sm" style="width:100%; text-align:center; border-color:var(--accent); color:var(--accent);">🖼️ Voir l'image du programme</a>
                            </div>
                        </div>
                        <!-- Zone de saisie du programme du prochain Dahira -->
                        <form action="admin_planning.php" method="POST" style="margin-top:1.1rem; padding-top:1rem; border-top:1px solid rgba(255,255,255,0.07);">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="save_programme">
                            <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                            <label class="form-label" style="margin-bottom:0.4rem;">🗓️ Programme détaillé (horaires | activités)</label>
                            <textarea name="programme" class="form-input" rows="5" placeholder="17h00 | 19h15  DURUS&#10;19h15 | 20h15  Prestation Xassidas&#10;20h15 | 20h30  Gammu"><?php echo htmlspecialchars((string)($prochain['programme'] ?? '')); ?></textarea>
                            <div style="margin-top:0.5rem; display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                                <button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer le programme</button>
                                <span style="color:var(--text-muted); font-size:0.78rem;">Une ligne par activité, avec l'horaire en début de ligne.</span>
                            </div>
                        </form>
                        <form action="admin_planning.php" method="POST" style="margin-top:0.6rem;" data-confirm="Remplacer le programme actuel par le programme par défaut ?">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="load_default_programme">
                            <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">📋 Charger le programme par défaut</button>
                        </form>
                        <form action="admin_planning.php" method="POST" style="margin-top:0.6rem;" data-confirm="<?php echo ((int)($prochain['publie'] ?? 0)) === 1 ? 'Dépublier ce Dahira de l\'accueil membre ?' : 'Publier ce Dahira sur l\'accueil membre (validation de présence) ?'; ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="toggle_publie">
                            <input type="hidden" name="id" value="<?php echo (int)$prochain['id']; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm" style="<?php echo ((int)($prochain['publie'] ?? 0)) === 1 ? 'border-color:rgba(37,211,102,0.6); color:#7bd8a6;' : 'border-color:var(--accent); color:var(--accent);'; ?>"><?php echo ((int)($prochain['publie'] ?? 0)) === 1 ? '🟢 Publié' : '⚪ Publier'; ?></button>
                        </form>
                        <details style="margin-top:0.9rem;" class="pl-details">
                            <summary>Voir / copier le message WhatsApp</summary>
                            <pre><?php echo htmlspecialchars($waMsg); ?></pre>
                        </details>
                    <?php else: ?>
                        <h3 style="color:var(--white); font-size:1.1rem; margin-bottom:0.3rem;">📣 Prochain Dahira</h3>
                        <p style="color:var(--text-muted); margin:0;">Aucun dimanche avec Dahira à venir. Générez les dimanches ci-dessous.</p>
                    <?php endif; ?>
                </div>

                <!-- Paramètres du groupe -->
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">⚙️ Paramètres du Dahira <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <form action="admin_planning.php" method="POST" style="margin-top:1rem;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save_params">
                        <div class="pl-params">
                            <div class="form-group" style="grid-column:1 / -1;"><label class="form-label">Lieu du Dahira</label><textarea name="lieu" class="form-input" rows="3" placeholder="1 rue du 35 régiment d'aviation,&#10;69500 Bron"><?php echo htmlspecialchars($lieu); ?></textarea><p style="color:var(--text-muted); font-size:0.78rem; margin-top:0.35rem;">Vous pouvez saisir le lieu sur plusieurs lignes.</p></div>
                            <div class="form-group"><label class="form-label">Heure de début</label><input type="text" name="debut" class="form-input" value="<?php echo htmlspecialchars($debut); ?>" placeholder="17h00"></div>
                            <div class="form-group"><label class="form-label">Heure de fin</label><input type="text" name="fin" class="form-input" value="<?php echo htmlspecialchars($fin); ?>" placeholder="20h30"></div>
                            <div class="form-group" style="grid-column:1 / -1;">
                                <label class="form-label">🗓️ Programme par défaut (modèle réutilisable)</label>
                                <textarea name="programme_defaut" class="form-input" rows="5" placeholder="17h00 | 19h15  DURUS&#10;19h15 | 20h15  Prestation Xassidas&#10;20h15 | 20h30  Gammu"><?php echo htmlspecialchars($programmeDefaut); ?></textarea>
                                <p style="color:var(--text-muted); font-size:0.78rem; margin-top:0.35rem;">Ce modèle peut être chargé d'un clic dans « Prochain Dahira » via le bouton « Charger le programme par défaut ».</p>
                            </div>
                            <div class="form-group" style="grid-column:1 / -1;"><label class="form-label">Lien du groupe WhatsApp (facultatif)</label><input type="url" name="groupe_wa" class="form-input" value="<?php echo htmlspecialchars($groupeWa); ?>" placeholder="https://chat.whatsapp.com/…"></div>
                        </div>
                        <div style="margin-top:0.9rem;"><button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer</button></div>
                    </form>
                </details>

                <!-- Dahiras passés à clôturer -->
                <?php if (!empty($planningsPassesNonClotures)): ?>
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">🕰️ Dahiras passés à clôturer (<?php echo count($planningsPassesNonClotures); ?>) <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <div class="pl-grid" style="margin-top:0.9rem;">
                        <?php foreach ($planningsPassesNonClotures as $p):
                            $d = $p['date_dahira'];
                        ?>
                        <div class="glass-card pl-card" style="opacity:0.8; border-left:3px solid rgba(255,255,255,0.18);">
                            <div class="pl-date">
                                <?php echo ucfirst(dahira_jour_fr($d)); ?> <?php echo date('d/m/Y', strtotime($d)); ?>
                                <small>· passé</small>
                            </div>
                            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                                <span>
                                    <span class="pl-badge pl-badge-no" style="font-size:0.75rem; padding:0.25rem 0.6rem;">🕌 Terminé</span>
                                    <?php $nbPres = $presencesDahira[(int)$p['id']] ?? 0; ?>
                                    <?php if ($nbPres > 0): ?>
                                        <span class="pl-badge" style="font-size:0.75rem; padding:0.25rem 0.6rem; background:rgba(37,211,102,0.15); color:#7bd8a6; border:1px solid rgba(37,211,102,0.3);">👥 <?php echo $nbPres; ?> présence<?php echo $nbPres > 1 ? 's' : ''; ?></span>
                                    <?php endif; ?>
                                </span>
                                <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center;">
                                    <a href="dahira_detail.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-secondary btn-sm" style="font-size:0.7rem; padding:0.2rem 0.5rem; border-color:var(--accent); color:var(--accent);">👁️ Détail</a>
                                    <button type="button" class="btn btn-secondary btn-sm dahira-cloture" data-id="<?php echo (int)$p['id']; ?>" data-date="<?php echo date('d/m/Y', strtotime($d)); ?>" data-presence="<?php echo $nbPres; ?>" style="font-size:0.7rem; padding:0.2rem 0.5rem; border-color:rgba(37,211,102,0.6); color:#7bd8a6;">✅ Clôturer</button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>

                <!-- Dahiras clôturés (historique, après les paramètres par défaut) -->
                <?php if (!empty($planningsPassesCloturees)): ?>
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">✅ Dahiras clôturés (<?php echo count($planningsPassesCloturees); ?>) <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <div class="pl-grid" style="margin-top:0.9rem;">
                        <?php foreach ($planningsPassesCloturees as $p):
                            $d = $p['date_dahira'];
                            $nbCloture = $nbPartDahira($p);
                        ?>
                        <div class="glass-card pl-card" style="opacity:0.8; border-left:3px solid rgba(37,211,102,0.4);">
                            <div class="pl-date">
                                <?php echo ucfirst(dahira_jour_fr($d)); ?> <?php echo date('d/m/Y', strtotime($d)); ?>
                                <small>· passé</small>
                            </div>
                            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                                <span>
                                    <span class="pl-badge pl-badge-ok" style="font-size:0.75rem; padding:0.25rem 0.6rem;">✅ Clôturé<?php echo $nbCloture > 0 ? ' · ' . $nbCloture . ' pers.' : ''; ?></span>
                                </span>
                                <a href="dahira_detail.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-secondary btn-sm" style="font-size:0.7rem; padding:0.2rem 0.5rem; border-color:var(--accent); color:var(--accent);">👁️ Détail</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>

                <!-- Générer les dimanches -->
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">🗓️ Générer les dimanches de Dahira <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <form action="admin_planning.php" method="POST" style="margin-top:1rem;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="generate_sundays">
                        <div class="pl-params">
                            <div class="form-group"><label class="form-label">Du</label><input type="date" name="period_start" class="form-input" value="<?php echo date('Y-m-d'); ?>" required></div>
                            <div class="form-group"><label class="form-label">Au</label><input type="date" name="period_end" class="form-input" value="<?php echo date('Y-m-d', strtotime('+6 months')); ?>" required></div>
                            <div class="form-group" style="display:flex; align-items:flex-end;">
                                <label class="form-check" style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem;">
                                    <input type="checkbox" name="alterner" value="1" checked style="width:auto;"> Un dimanche sur deux
                                </label>
                            </div>
                        </div>
                        <div style="margin-top:0.9rem;"><button type="submit" class="btn btn-primary btn-sm">➕ Générer les dimanches</button></div>
                    </form>
                </details>

                <!-- Liste des dimanches planifiés -->
                <details class="glass-card" style="margin-bottom:1.5rem;">
                    <summary style="color:var(--white); font-size:1.17rem; font-weight:700; cursor:pointer; list-style:none; user-select:none; display:flex; align-items:center; gap:0.5rem;">📅 Dimanches planifiés (<?php echo count($plannings); ?>) <span class="pl-chevron" style="color:var(--text-muted); transition:transform 0.2s;">▸</span></summary>
                    <div style="margin-top:0.9rem;">
                    <?php if (empty($plannings)): ?>
                        <p style="color:var(--text-muted); text-align:center; padding:2rem 0;">Aucun dimanche planifié. Utilisez le générateur ci-dessus.</p>
                    <?php else: ?>
                    <div class="pl-grid">
                        <?php foreach ($plannings as $p):
                            $d = $p['date_dahira'];
                            $aDahira = ((int) ($p['a_dahira'] ?? 1)) === 1;
                            $isPast = $d < date('Y-m-d');
                        ?>
                        <div class="glass-card pl-card" style="<?php echo $aDahira ? 'border-left:3px solid var(--accent);' : 'opacity:0.85; border-left:3px solid rgba(255,255,255,0.25);'; ?>">
                            <div class="pl-date">
                                <?php echo ucfirst(dahira_jour_fr($d)); ?> <?php echo date('d/m/Y', strtotime($d)); ?>
                                <?php if ($isPast): ?><small>· passé</small><?php endif; ?>
                            </div>
                            <div style="display:flex; gap:0.4rem; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                                <span>
                                <?php if ($aDahira): ?>
                                    <span class="pl-badge pl-badge-ok" style="font-size:0.75rem; padding:0.25rem 0.6rem;">🕌 Dahira</span>
                                <?php else: ?>
                                    <span class="pl-badge" style="font-size:0.75rem; padding:0.25rem 0.6rem; background:rgba(255,255,255,0.07); color:var(--text-muted); border:1px solid var(--glass-border);">🚫 Pas Dahira</span>
                                <?php endif; ?>
                                </span>
                                <form action="admin_planning.php" method="POST" style="margin:0;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_dahira">
                                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm" style="font-size:0.7rem; padding:0.2rem 0.5rem;"><?php echo $aDahira ? '→ Pas Dahira' : '→ Dahira'; ?></button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    </div>
                </details>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
    <!-- Modale de clôture d'un Dahira passé -->
    <div id="dahira-cloture-modal" class="modal-overlay" style="display:none;">
        <div class="modal-card glass-card" style="max-width:430px; width:calc(100vw - 28px);">
            <div class="modal-header"><h3 style="color:var(--accent);">✅ Clôturer le Dahira</h3></div>
            <div class="modal-body" style="display:block; text-align:left;">
                <p id="dahira-cloture-msg" style="margin:0 0 1rem; line-height:1.5; text-align:left;">Clôturer ce Dahira ?</p>
                <form id="dahira-cloture-form" action="admin_planning.php" method="POST" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="cloture">
                    <input type="hidden" name="id" id="dahira-cloture-id">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label" style="display:block; margin-bottom:0.4rem;">👥 Nombre de participants (facultatif)</label>
                        <input type="number" name="nb_participants" id="dahira-cloture-nb" class="form-input" min="0" placeholder="Ex : 25" style="width:100%;">
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="display:flex; gap:0.5rem; justify-content:flex-end;">
                <button type="button" id="dahira-cloture-cancel" class="btn btn-secondary btn-sm">Annuler</button>
                <button type="button" id="dahira-cloture-ok" class="btn btn-primary btn-sm">✅ Clôturer</button>
            </div>
        </div>
    </div>
    <script>
        // Partager dans le groupe WhatsApp : copie le message puis ouvre le groupe
        function shareGroup(msg, link) {
            try { navigator.clipboard.writeText(msg); } catch (e) {}
            window.location.href = link;
        }
        // Filtrage des indicateurs par année
        (function () {
            var btns = document.querySelectorAll('.pl-annee-btn');
            var valeurs = document.querySelectorAll('.pl-stat-valeur');
            if (!btns.length || !valeurs.length) { return; }
            btns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    btns.forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    var annee = btn.getAttribute('data-annee');
                    valeurs.forEach(function (v) {
                        var val = v.getAttribute('data-' + annee);
                        if (val === null) { val = v.getAttribute('data-global'); }
                        v.textContent = val;
                    });
                });
            });
        })();
        // Modale de clôture d'un Dahira passé
        (function () {
            var modal = document.getElementById('dahira-cloture-modal');
            var form = document.getElementById('dahira-cloture-form');
            var idInput = document.getElementById('dahira-cloture-id');
            var msg = document.getElementById('dahira-cloture-msg');
            var okBtn = document.getElementById('dahira-cloture-ok');
            var cancelBtn = document.getElementById('dahira-cloture-cancel');
            if (!modal || !form || !idInput) { return; }
            function openModal(m) { m.style.display = 'flex'; setTimeout(function () { m.classList.add('active'); }, 10); }
            function closeModal(m) { m.classList.remove('active'); setTimeout(function () { m.style.display = 'none'; }, 280); }
            // Aujourd'hui au format jj/mm/aaaa
            var now = new Date();
            var todayStr = String(now.getDate()).padStart(2, '0') + '/' + String(now.getMonth() + 1).padStart(2, '0') + '/' + now.getFullYear();
            document.querySelectorAll('.dahira-cloture').forEach(function (btn) {
                var date = btn.getAttribute('data-date') || '';
                if (date > todayStr) { btn.style.display = 'none'; } // date future : pas de clôture
                btn.addEventListener('click', function () {
                    idInput.value = btn.getAttribute('data-id');
                    msg.textContent = 'Clôturer le Dahira du ' + date + ' ?';
                    var nb = document.getElementById('dahira-cloture-nb');
                    var presence = parseInt(btn.getAttribute('data-presence') || '0', 10) || 0;
                    if (nb) { nb.value = presence > 0 ? String(presence) : ''; }
                    openModal(modal);
                });
            });
            if (okBtn) { okBtn.addEventListener('click', function () { form.submit(); }); }
            if (cancelBtn) { cancelBtn.addEventListener('click', function () { closeModal(modal); }); }
            modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(modal); } });
        })();
    </script>
</body>
</html>
