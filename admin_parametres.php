<?php
/**
 * Touba Lyon 2026 - ⚙️ Paramètres (administration)
 *
 * Page dédiée aux réglages généraux de l'application. Premier réglage :
 * l'interrupteur global d'envoi de notifications aux membres.
 * Réservée aux administrateurs connectés.
 */
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/planning_dahira_helper.php'; // dahira_param(), dahira_set_param()

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = "Échec de validation de sécurité (CSRF). Veuillez réessayer.";
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'toggle_notif_membres') {
            $nouveau = (($_POST['etat'] ?? '') === '1') ? '1' : '0';
            dahira_set_param($pdo, 'notif_membres_actives', $nouveau);
            $success = $nouveau === '1'
                ? "L'envoi de notifications aux membres est désormais activé."
                : "L'envoi de notifications aux membres est désormais désactivé.";
        } else {
            $error = "Action non reconnue.";
        }
    }
}

$notifActives = dahira_param($pdo, 'notif_membres_actives', '1') !== '0';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚙️ Paramètres — Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .param-wrap { max-width: 820px; margin: 2rem auto; }
        .param-card { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: 1.2rem 1.35rem; border-radius: 16px; margin-bottom: 1rem; }
        .param-info { flex: 1 1 260px; min-width: 0; }
        .param-title { color: #fff; font-weight: 800; font-size: 1.02rem; }
        .param-desc { color: var(--text-muted); font-size: 0.86rem; margin-top: 0.25rem; line-height: 1.5; }
        .param-right { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; }
        .param-state { font-size: 0.78rem; font-weight: 700; border-radius: 50px; padding: 0.2rem 0.7rem; }
        .param-state.on { background: rgba(37,211,102,0.15); color: #7bd8a6; border: 1px solid rgba(37,211,102,0.4); }
        .param-state.off { background: rgba(191,33,33,0.15); color: #fca5a5; border: 1px solid rgba(191,33,33,0.4); }
        .param-btn.is-off { background: #c62828; border-color: #c62828; color: #fff; }
        .param-btn.is-on { background: #1f9d55; border-color: #1f9d55; color: #fff; }
        @media (max-width: 520px) { .param-right { width: 100%; } .param-right form, .param-btn { width: 100%; } .param-btn { justify-content: center; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/admin_menu.php'; ?>
            <div class="admin-content">
                <h1 class="admin-page-title">⚙️ Paramètres</h1>
                <p class="admin-page-desc" style="color:var(--text-muted); margin-top:0.9rem; font-size:0.9rem;">Réglages généraux de l'application.</p>

                <?php if (!empty($success)): ?><div class="alert-success" style="background:rgba(37,211,102,0.12);border:1px solid rgba(37,211,102,0.4);color:#7bd8a6;padding:0.8rem 1rem;border-radius:10px;margin:1rem 0;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert-danger" style="background:rgba(191,33,33,0.12);border:1px solid rgba(191,33,33,0.4);color:#fca5a5;padding:0.8rem 1rem;border-radius:10px;margin:1rem 0;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <div class="param-wrap" style="margin-top:1.2rem;">
                    <!-- Notifications aux membres -->
                    <div class="glass-card param-card">
                        <div class="param-info">
                            <div class="param-title">🔔 Notifications aux membres</div>
                            <div class="param-desc">
                                <?php echo $notifActives
                                    ? 'Activé — les membres reçoivent les notifications (cloche + push) lors des publications (actualités, réunions, Guddi Àjjuma, etc.).'
                                    : 'Désactivé — aucune notification n\'est envoyée aux membres. Les e-mails et messages WhatsApp restent disponibles manuellement.'; ?>
                            </div>
                        </div>
                        <div class="param-right">
                            <span class="param-state <?php echo $notifActives ? 'on' : 'off'; ?>"><?php echo $notifActives ? '✅ Activé' : '⛔ Désactivé'; ?></span>
                            <form action="admin_parametres.php" method="POST" style="margin:0;" data-confirm="<?php echo $notifActives ? 'Désactiver l\'envoi de notifications à tous les membres ?' : 'Réactiver l\'envoi de notifications aux membres ?'; ?>"<?php echo $notifActives ? ' data-confirm-danger="1"' : ''; ?>>
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_notif_membres">
                                <input type="hidden" name="etat" value="<?php echo $notifActives ? '0' : '1'; ?>">
                                <button type="submit" class="btn btn-sm param-btn <?php echo $notifActives ? 'is-off' : 'is-on'; ?>">
                                    <?php echo $notifActives ? '⛔ Désactiver' : '✅ Activer'; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <footer class="app-footer"><p>&copy; 2026 Touba Lyon - Tous droits réservés.</p></footer>
    <?php include __DIR__ . '/modern_popup.php'; ?>
</body>
</html>
