<?php
/**
 * Touba Lyon 2026 - Unified Navigation Header
 * Standardized colors & assets including logo and full navigation actions.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPage = basename($_SERVER['PHP_SELF']);
$isAdmin       = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isPlayer      = isset($_SESSION['player_id']);
$isIntegrateur = $isPlayer && !empty($_SESSION['is_integrateur']); // rôle intégrateur porté par un membre
$isKourelMgr   = $isPlayer && !empty($_SESSION['is_gestion_kourel']); // rôle gestion des Kurels porté par un membre
$isCommissionMgr = $isPlayer && !empty($_SESSION['is_gestion_commission']); // responsable d'au moins une commission
$homeLink      = $isAdmin ? 'admin_dashboard.php' : 'index.php';

// Cloche de notifications : proposée aux membres connectés (les admin disposent
// de leur propre espace, mais reçoivent aussi leurs notifications s'ils sont membre).
// Le compteur de départ est lu en base ; il est ensuite rafraîchi par la
// vérification périodique du pied de page.
$notifCloche = (bool) $isPlayer;
$notifNonLues = 0;
if ($notifCloche) {
    try {
        require_once __DIR__ . '/db_setup.php';
        require_once __DIR__ . '/notification_helper.php';
        if (isset($pdo)) {
            $notifNonLues = troba_notifications_unread_count($pdo, (int)$_SESSION['player_id']);
        }
    } catch (Throwable $e) {
        $notifNonLues = 0;
    }
}
// Jeton CSRF exposé au JS (pour les POST en arrière-plan de la cloche)
require_once __DIR__ . '/csrf.php';
$trobaCsrf = csrf_token();
?>

<!-- ── PWA : manifeste, méta iOS et service worker (injectés depuis l'en-tête partagé) ── -->
<script>
(function () {
    var head = document.head || document.getElementsByTagName('head')[0];
    if (!head) return;
    function ensure(tag, selector, attrs) {
        if (document.querySelector(selector)) return;
        var el = document.createElement(tag);
        for (var k in attrs) { el.setAttribute(k, attrs[k]); }
        head.appendChild(el);
    }
    ensure('link', 'link[rel="manifest"]', { rel: 'manifest', href: 'manifest.json' });
    ensure('meta', 'meta[name="theme-color"]', { name: 'theme-color', content: '#081c15' });
    ensure('link', 'link[rel="apple-touch-icon"]', { rel: 'apple-touch-icon', href: 'icone_192.png' });
    ensure('meta', 'meta[name="apple-mobile-web-app-capable"]', { name: 'apple-mobile-web-app-capable', content: 'yes' });
    ensure('meta', 'meta[name="mobile-web-app-capable"]', { name: 'mobile-web-app-capable', content: 'yes' });
    ensure('meta', 'meta[name="apple-mobile-web-app-status-bar-style"]', { name: 'apple-mobile-web-app-status-bar-style', content: 'black-translucent' });
    ensure('meta', 'meta[name="apple-mobile-web-app-title"]', { name: 'apple-mobile-web-app-title', content: 'Dahira Touba Lyon' });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function () { /* installation PWA best-effort */ });
        });
    }
})();
</script>

<?php if (in_array($currentPage, ['login.php', 'admin_login.php'], true)): ?>
<!-- ── Bouton d'installation PWA (raccourci écran d'accueil) — page de connexion uniquement ── -->
<div id="pwa-install-bar" style="display:none; position:fixed; left:50%; transform:translateX(-50%); bottom:1rem; z-index:9998; max-width:92%; background:linear-gradient(135deg,#1b4332,#2d6a4f); border:1px solid rgba(212,175,55,0.4); border-radius:16px; box-shadow:0 12px 34px rgba(0,0,0,0.45); padding:0.7rem 0.8rem 0.7rem 1rem; display:flex; align-items:center; gap:0.8rem;">
    <img src="icone_192.png" alt="" style="width:34px; height:34px; border-radius:9px; background:#fff; padding:2px; flex-shrink:0;">
    <div style="display:flex; flex-direction:column; line-height:1.25;">
        <strong style="color:#fff; font-size:0.9rem;">Installer l'application</strong>
        <span style="color:#b7d4c5; font-size:0.76rem;">Ajouter Dahira Touba Lyon à l'écran d'accueil</span>
    </div>
    <button id="pwa-install-btn" type="button" style="margin-left:0.4rem; background:#d4af37; color:#0c241a; border:0; border-radius:10px; font-weight:800; font-size:0.85rem; padding:0.55rem 1rem; cursor:pointer; white-space:nowrap;">Installer</button>
    <button id="pwa-install-close" type="button" aria-label="Fermer" style="background:rgba(255,255,255,0.12); color:#fff; border:0; width:28px; height:28px; border-radius:50%; font-size:1.1rem; line-height:1; cursor:pointer; flex-shrink:0;">&times;</button>
</div>

<!-- Instructions iOS (pas d'installation automatique sur Safari) -->
<div id="pwa-ios-modal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; padding:1.25rem;">
    <div style="max-width:360px; background:#0c241a; border:1px solid rgba(212,175,55,0.35); border-radius:18px; padding:1.5rem; text-align:center; color:#fff;">
        <img src="icone_192.png" alt="" style="width:56px; height:56px; border-radius:14px; background:#fff; padding:4px;">
        <h3 style="color:#d4af37; margin:1rem 0 0.6rem; font-size:1.1rem;">Installer sur iPhone / iPad</h3>
        <p style="color:#cfe8db; font-size:0.9rem; line-height:1.6; margin:0 0 1rem;">
            Touchez <strong>Partager</strong> <span style="font-size:1.1em;">&#x2191;</span> en bas de Safari,
            puis <strong>« Sur l'écran d'accueil »</strong>.
        </p>
        <button id="pwa-ios-close" type="button" style="background:#d4af37; color:#0c241a; border:0; border-radius:50px; font-weight:700; padding:0.65rem 1.5rem; cursor:pointer;">J'ai compris</button>
    </div>
</div>

<script>
(function () {
    var KEY = 'pwa-install-dismissed';
    var bar = document.getElementById('pwa-install-bar');
    var installBtn = document.getElementById('pwa-install-btn');
    var closeBtn = document.getElementById('pwa-install-close');
    var iosModal = document.getElementById('pwa-ios-modal');
    var iosClose = document.getElementById('pwa-ios-close');
    if (!bar) return;

    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    var dismissed = false;
    try { dismissed = localStorage.getItem(KEY) === '1'; } catch (e) {}
    var deferredPrompt = null;

    function showBar() { if (!standalone && !dismissed) bar.style.display = 'flex'; }
    function hideBar() { bar.style.display = 'none'; }
    function rememberChoice() { dismissed = true; try { localStorage.setItem(KEY, '1'); } catch (e) {} }

    // Android / Chrome / Edge : invite native
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        showBar();
    });

    installBtn.addEventListener('click', function () {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                deferredPrompt = null;
                hideBar();
                // Choix enregistré : accepté OU refusé, on ne re-propose plus l'invite.
                rememberChoice();
            });
        } else {
            // iOS ou navigateur sans invite native : on montre les instructions,
            // et on retient le choix (l'utilisateur a lancé l'installation).
            iosModal.style.display = 'flex';
            rememberChoice();
            hideBar();
        }
    });

    closeBtn.addEventListener('click', function () {
        hideBar();
        rememberChoice();
    });

    if (iosClose) iosClose.addEventListener('click', function () { iosModal.style.display = 'none'; });
    if (iosModal) iosModal.addEventListener('click', function (e) { if (e.target === iosModal) iosModal.style.display = 'none'; });

    // Application installée : ne plus jamais proposer.
    window.addEventListener('appinstalled', function () { hideBar(); rememberChoice(); });

    // iOS Safari : pas d'événement beforeinstallprompt -> on propose la barre avec instructions
    var isIOS = /iphone|ipad|ipod/i.test(window.navigator.userAgent);
    var isSafari = /^((?!chrome|android|crios|fxios|edgios).)*safari/i.test(window.navigator.userAgent);
    if (isIOS && isSafari) { showBar(); }
})();
</script>
<?php endif; ?>

<header class="hdr" id="hdr">
    <div class="hdr-inner">

        <!-- ── Logo ── -->
        <a href="<?php echo $homeLink; ?>" class="hdr-logo" aria-label="Accueil Touba Lyon">
            <div class="hdr-logo-img-wrap">
                <img src="touba_lyon_logo.png" alt="Logo Touba Lyon">
            </div>
            <div class="hdr-logo-text">
                <span class="hdr-logo-name">Dahira - Mubawwa-A-Sidqin</span>
                <span class="hdr-logo-tagline">
                    Touba Lyon
                    <?php if ($isAdmin): ?>
                        <span class="hdr-badge-admin">Admin</span>
                    <?php endif; ?>
                </span>
            </div>
        </a>

        <!-- ── Navigation ── -->
        <nav class="hdr-nav" id="hdr-nav" aria-label="Navigation principale">

            <?php if ($isAdmin): ?>

                <div class="hdr-player-card hdr-player-card--admin">
                    <svg class="hdr-player-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <span class="hdr-player-name">Administrateur</span>
                </div>
                <a href="logout.php" class="hdr-btn hdr-btn--danger">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Se déconnecter
                </a>

            <?php elseif ($isPlayer): ?>

                <div class="hdr-player-card">
                    <span class="hdr-player-name"><?php echo htmlspecialchars($_SESSION['player_name'] ?? 'Joueur'); ?></span>
                    <span class="hdr-player-score">🏆 <?php echo (int)($_SESSION['player_score'] ?? 0); ?> pts</span>
                </div>
                <a href="play_logout.php" class="hdr-btn hdr-btn--ghost">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Déconnexion
                </a>

            <?php else: ?>

                <?php if ($currentPage !== 'login.php'): ?>
                    <a href="login.php" class="hdr-link">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                        Connexion Membre
                    </a>
                <?php endif; ?>
                <?php if ($currentPage !== 'admin_login.php'): ?>
                    <a href="admin_login.php" class="hdr-link">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Espace Admin
                    </a>
                <?php endif; ?>

            <?php endif; ?>

        </nav>

        <!-- ── Mobile Menu Burger Trigger ── -->
        <?php if ($notifCloche): ?>
        <style>
            .hdr-notif { position: relative; margin-left: 0.4rem; }
            .hdr-notif-btn {
                position: relative;
                background: rgba(255, 255, 255, 0.04);
                border: 1px solid var(--glass-border);
                border-radius: 50%;
                width: 40px;
                height: 40px;
                font-size: 1.05rem;
                line-height: 1;
                color: var(--white);
                cursor: pointer;
                transition: var(--transition);
            }
            .hdr-notif-btn:hover { border-color: var(--accent); background: rgba(212, 175, 55, 0.1); }
            .hdr-notif-badge {
                position: absolute;
                top: -4px;
                right: -4px;
                min-width: 19px;
                height: 19px;
                padding: 0 5px;
                border-radius: 50px;
                background: #bf2121;
                color: #fff;
                font-size: 0.68rem;
                font-weight: 700;
                line-height: 19px;
                border: 2px solid #081c15;
            }
            .hdr-notif-badge[hidden] { display: none !important; }
            .hdr-notif-panneau {
                position: absolute;
                right: 0;
                top: 48px;
                width: min(330px, calc(100vw - 24px));
                background: #0a1f18;
                border: 1px solid rgba(212, 175, 55, 0.35);
                border-radius: 14px;
                box-shadow: 0 18px 44px rgba(0, 0, 0, 0.6);
                z-index: 9200;
                overflow: hidden;
            }
            .hdr-notif-panneau[hidden] { display: none !important; }
            @media (max-width: 520px) {
                .hdr-notif-panneau {
                    position: fixed;
                    left: 10px;
                    right: 10px;
                    top: 66px;
                    width: auto;
                }
            }
            .hdr-notif-titre {
                padding: 0.7rem 0.9rem;
                font-size: 0.78rem;
                letter-spacing: 0.1em;
                text-transform: uppercase;
                color: #f4dd8c;
                border-bottom: 1px solid rgba(255, 255, 255, 0.07);
            }
            .hdr-notif-liste { max-height: min(60vh, 380px); overflow-y: auto; }
            .hdr-notif-item {
                display: block;
                padding: 0.7rem 0.9rem;
                border-bottom: 1px solid rgba(255, 255, 255, 0.05);
                text-decoration: none;
            }
            .hdr-notif-item:hover { background: rgba(255, 255, 255, 0.04); }
            .hdr-notif-item.non-lu { border-left: 3px solid var(--accent); }
            .hdr-notif-item strong { display: block; color: var(--white); font-size: 0.86rem; margin-bottom: 0.15rem; }
            .hdr-notif-item span { display: block; color: var(--text-muted); font-size: 0.79rem; line-height: 1.5; }
            .hdr-notif-item em { display: block; color: rgba(255, 255, 255, 0.3); font-size: 0.7rem; margin-top: 0.25rem; font-style: normal; }
            .hdr-notif-vide { padding: 1.2rem 0.9rem; color: var(--text-muted); font-size: 0.82rem; text-align: center; margin: 0; }
            /* Toast des nouvelles notifications */
            .troba-toast {
                position: fixed; left: 50%; bottom: 1.4rem; transform: translateX(-50%) translateY(20px);
                z-index: 9999; max-width: min(92vw, 420px);
                background: rgba(12, 36, 26, 0.97); border: 1px solid var(--accent); border-radius: 14px;
                padding: 0.9rem 1.1rem; box-shadow: 0 14px 44px rgba(0,0,0,0.5);
                opacity: 0; transition: opacity .3s ease, transform .3s ease; cursor: pointer;
                display: flex; align-items: center; gap: 0.8rem;
            }
            .troba-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
            .troba-toast .t-ico { font-size: 1.5rem; flex-shrink: 0; }
            .troba-toast .t-ttl { color: #fff; font-weight: 700; font-size: 0.92rem; line-height: 1.3; }
            .troba-toast .t-txt { color: var(--text-muted); font-size: 0.82rem; line-height: 1.45; margin-top: 0.15rem; }
            /* Bandeau d'invitation aux notifications push (allure Daara) */
            #trobaInvitePush[hidden] { display: none !important; }
            #trobaInvitePush {
                position: fixed;
                left: 50%;
                bottom: 16px;
                transform: translateX(-50%);
                z-index: 9000;
                display: flex;
                align-items: center;
                gap: 0.6rem;
                background: rgba(8, 28, 21, 0.96);
                border: 1px solid rgba(212, 175, 55, 0.45);
                border-radius: 50px;
                padding: 0.55rem 0.7rem 0.55rem 1rem;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
                font-size: 0.85rem;
                color: #f4dd8c;
                max-width: calc(100% - 24px);
            }
            .troba-invite-ico { font-size: 1.1rem; flex-shrink: 0; }
            .troba-invite-txt { flex: 1; min-width: 0; line-height: 1.35; }
            #trobaInvitePush button {
                font-family: inherit;
                font-size: 0.8rem;
                font-weight: 700;
                border: 0;
                border-radius: 50px;
                padding: 0.4rem 0.9rem;
                cursor: pointer;
                white-space: nowrap;
            }
            #trobaInvitePush .oui { background: rgba(212, 175, 55, 0.95); color: #08150f; }
            #trobaInvitePush .non { background: transparent; color: rgba(255, 255, 255, 0.45); padding: 0.4rem 0.5rem; }
        </style>
        <div class="hdr-notif">
            <button type="button" class="hdr-notif-btn" id="trobaCloche"
                    aria-label="Notifications" aria-expanded="false">🔔<span
                    class="hdr-notif-badge" id="trobaCompteur" <?php echo $notifNonLues > 0 ? '' : 'hidden'; ?>><?php
                    echo $notifNonLues > 99 ? '99+' : (int)$notifNonLues; ?></span></button>
            <div class="hdr-notif-panneau" id="trobaPanneau" hidden>
                <div class="hdr-notif-titre">Notifications</div>
                <div class="hdr-notif-liste" id="trobaListe">
                    <p class="hdr-notif-vide">Chargement…</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <button class="hdr-burger" id="hdr-burger" aria-label="Menu de navigation" aria-expanded="false" aria-controls="hdr-nav">
            <span></span>
            <span></span>
            <span></span>
        </button>

    </div>

    <!-- Glowing gold line overlay at the bottom border -->
    <div class="hdr-line" aria-hidden="true"></div>
</header>

<div class="hdr-backdrop" id="hdr-backdrop" aria-hidden="true"></div>

<?php if ($notifCloche): ?>
<!-- Bandeau d'invitation aux notifications push (comme Daara) -->
<div class="notif-invite" id="trobaInvitePush" hidden>
    <span class="troba-invite-ico">🔔</span>
    <span class="troba-invite-txt">Recevez les nouvelles du Dahira même quand l'application est fermée.</span>
    <button type="button" class="oui" id="trobaInviteOui">Activer</button>
    <button type="button" class="non" id="trobaInviteNon" aria-label="Plus tard">✕</button>
</div>
<?php endif; ?>

<script>
(function () {
    const hdr      = document.getElementById('hdr');
    const nav      = document.getElementById('hdr-nav');
    const burger   = document.getElementById('hdr-burger');
    const backdrop = document.getElementById('hdr-backdrop');

    function openMenu() {
        nav.classList.add('is-open');
        burger.classList.add('is-open');
        burger.setAttribute('aria-expanded', 'true');
        backdrop.classList.add('is-visible');
        document.body.classList.add('menu-open');
    }

    function closeMenu() {
        nav.classList.remove('is-open');
        burger.classList.remove('is-open');
        burger.setAttribute('aria-expanded', 'false');
        backdrop.classList.remove('is-visible');
        document.body.classList.remove('menu-open');
    }

    burger.addEventListener('click', function () {
        nav.classList.contains('is-open') ? closeMenu() : openMenu();
    });

    backdrop.addEventListener('click', closeMenu);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
    });

    // Scroll effect: add class when scrolled past 15px
    let lastY = window.scrollY;
    window.addEventListener('scroll', function () {
        const y = window.scrollY;
        hdr.classList.toggle('hdr--scrolled', y > 15);
        if (y > 90) {
            if (y > lastY + 5) {
                hdr.classList.add('hdr--hidden');
                hdr.classList.remove('hdr--visible');
            } else if (y < lastY - 5) {
                hdr.classList.remove('hdr--hidden');
                hdr.classList.add('hdr--visible');
            }
        } else {
            hdr.classList.remove('hdr--hidden', 'hdr--visible');
        }
        lastY = y;
    }, { passive: true });
})();
</script>


<!-- ── Logo : intitulé complet partout ("Dahira - Mubawwa-A-Sidqin") ── -->
<script>
(function () {
    var el = document.querySelector('.hdr-logo-name');
    if (!el) return;
    var DESKTOP = 'Dahira - Mubawwa-A-Sidqin';
    var MOBILE  = 'Dahira - Mubawwa-A-Sidqin';

    function apply() {
        el.textContent = mq.matches ? MOBILE : DESKTOP;
    }
    var mq = window.matchMedia('(max-width: 768px)');
    apply();
    mq.addEventListener ? mq.addEventListener('change', apply) : mq.addListener(apply);
})();
</script>

<script>
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('notification-modal');
        if (!modal) return;

        var heading = modal.querySelector('.modal-header h3');
        var isError = heading && /danger/i.test(heading.getAttribute('style') || '');
        var bodyP = modal.querySelector('.modal-body p');
        var msg = bodyP ? bodyP.textContent.trim() : '';

        // Retirer le popup bloquant
        if (modal.parentNode) modal.parentNode.removeChild(modal);
        if (!msg) return;

        var color = isError ? 'var(--danger)' : 'var(--accent)';
        var toast = document.createElement('div');
        toast.setAttribute('role', 'status');
        toast.style.cssText = 'position:fixed;left:50%;bottom:2rem;transform:translateX(-50%) translateY(20px);z-index:9999;max-width:90%;background:rgba(12,36,26,0.96);border:1px solid ' + color + ';border-radius:14px;padding:1rem 1.5rem;box-shadow:0 12px 40px rgba(0,0,0,0.45);opacity:0;transition:opacity .3s ease, transform .3s ease;cursor:pointer;';
        var span = document.createElement('span');
        span.style.cssText = 'color:' + color + ';font-weight:600;font-size:0.95rem;line-height:1.5;';
        span.textContent = msg;
        toast.appendChild(span);
        document.body.appendChild(toast);

        requestAnimationFrame(function () {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(-50%) translateY(0)';
        });

        var hide = function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-50%) translateY(20px)';
            setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 320);
        };
        var timer = setTimeout(hide, isError ? 6000 : 4000);
        toast.addEventListener('click', function () { clearTimeout(timer); hide(); });
    });
})();
</script>

<?php if ($notifCloche): ?>
<script>
// Cloche de notifications : le compteur est rafraîchi périodiquement ; les
// nouvelles notifications apparaissent en toast, et le panneau charge la liste
// à l'ouverture, ce qui marque le tout comme lu.
(function () {
    var cloche   = document.getElementById('trobaCloche');
    var badge    = document.getElementById('trobaCompteur');
    var panneau  = document.getElementById('trobaPanneau');
    var liste    = document.getElementById('trobaListe');
    var jeton    = <?php echo json_encode($trobaCsrf); ?>;
    var vues     = {};      // id -> true, pour ne pas re-toaster deux fois
    var toastTimer = null;

    if (!cloche) return;

    function majBadge(n) {
        n = parseInt(n, 10) || 0;
        badge.textContent = n > 99 ? '99+' : String(n);
        badge.hidden = (n === 0);
    }
    window.trobaMajBadge = majBadge;

    function dateCourte(txt) {
        var d = new Date(String(txt).replace(' ', 'T'));
        if (isNaN(d.getTime())) { return ''; }
        return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })
            + ' à ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    }

    function afficher(items) {
        liste.textContent = '';
        if (!items || !items.length) {
            var p = document.createElement('p');
            p.className = 'hdr-notif-vide';
            p.textContent = 'Aucune notification pour le moment.';
            liste.appendChild(p);
            return;
        }
        items.forEach(function (n) {
            var a = document.createElement('a');
            a.className = 'hdr-notif-item' + (n.non_lu ? ' non-lu' : '');
            // Clic → si un lien est associé (page de détail Dahira / Guddi),
            // on ouvre le lien ; sinon on supprime la notification.
            if (n.lien) {
                a.href = n.lien;
                a.target = '_self';
                a.addEventListener('click', function () {
                    supprimerNotif(n.id, a);
                });
            } else {
                a.href = '#';
                a.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    supprimerNotif(n.id, a);
                });
            }
            var t = document.createElement('strong');
            t.textContent = n.titre;
            var c = document.createElement('span');
            c.textContent = n.texte;
            var d = document.createElement('em');
            d.textContent = dateCourte(n.date);
            a.appendChild(t);
            a.appendChild(c);
            a.appendChild(d);
            liste.appendChild(a);
        });
    }

    // Supprime une notification : retire l'élément de la liste affichée puis
    // appelle le service (suppression douce). Si la liste devient vide, on
    // affiche le message « Aucune notification ».
    function supprimerNotif(id, el) {
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }
        var restants = liste.querySelectorAll('.hdr-notif-item').length;
        if (restants === 0) {
            liste.textContent = '';
            var p = document.createElement('p');
            p.className = 'hdr-notif-vide';
            p.textContent = 'Aucune notification pour le moment.';
            liste.appendChild(p);
        }
        var corps = new URLSearchParams();
        corps.set('action', 'supprimer');
        corps.set('id', String(id));
        corps.set('csrf_token', jeton);
        fetch('notifications.php', { method: 'POST', body: corps }).catch(function () {});
        majBadge(Math.max(0, (parseInt(badge.textContent, 10) || 1) - 1));
    }

    // Clic sur un toast : supprimer la notification (le toast disparaît).
    function supprimerDepuisToast(id, toast) {
        var masquer = function (el) {
            el.classList.remove('show');
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 320);
        };
        masquer(toast);
        supprimerNotif(id, null);
    }

    function marquerLues() {
        var corps = new URLSearchParams();
        corps.set('action', 'lues');
        corps.set('csrf_token', jeton);
        fetch('notifications.php', { method: 'POST', body: corps })
            .then(function () { majBadge(0); })
            .catch(function () {});
    }

    function ouvrir() {
        panneau.hidden = false;
        cloche.setAttribute('aria-expanded', 'true');
        fetch('notifications.php?liste=1', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.ok) { return; }
                afficher(d.liste || []);
                if ((d.non_lues || 0) > 0) { marquerLues(); }
            })
            .catch(function () {
                liste.textContent = '';
                var p = document.createElement('p');
                p.className = 'hdr-notif-vide';
                p.textContent = 'Liste indisponible pour le moment.';
                liste.appendChild(p);
            });
    }

    function fermer() {
        panneau.hidden = true;
        cloche.setAttribute('aria-expanded', 'false');
    }

    cloche.addEventListener('click', function (e) {
        e.stopPropagation();
        if (panneau.hidden) { ouvrir(); } else { fermer(); }
    });
    document.addEventListener('click', function (e) {
        if (!panneau.hidden && !panneau.contains(e.target) && e.target !== cloche) { fermer(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { fermer(); }
    });

    function afficherToast(n) {
        if (vues[n.id]) { return; }
        vues[n.id] = true;
        var toast = document.createElement('div');
        toast.className = 'troba-toast';
        var ico = document.createElement('span'); ico.className = 't-ico'; ico.textContent = '🔔';
        var wrap = document.createElement('div'); wrap.style.cssText = 'flex:1;min-width:0;';
        var t = document.createElement('div'); t.className = 't-ttl'; t.textContent = n.titre;
        var c = document.createElement('div'); c.className = 't-txt'; c.textContent = n.texte;
        wrap.appendChild(t); wrap.appendChild(c);
        toast.appendChild(ico); toast.appendChild(wrap);
        document.body.appendChild(toast);
        requestAnimationFrame(function () { toast.classList.add('show'); });
        var masquer = function (el) {
            el.classList.remove('show');
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 320);
        };
        var tm = setTimeout(function () { masquer(toast); }, 6000);
        toast.addEventListener('click', function () {
            clearTimeout(tm);
            // Si un lien est associé (page de détail Dahira / Guddi), on ouvre
            // le lien ; sinon on supprime simplement la notification.
            if (n.lien) {
                supprimerNotif(n.id, null);
                window.location.href = n.lien;
            } else {
                supprimerDepuisToast(n.id, toast);
            }
        });
    }

    function verifier() {
        fetch('notifications.php', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.ok) { return; }
                majBadge(d.non_lues || 0);
                var ids = [];
                (d.nouvelles || []).forEach(function (n) { afficherToast(n); ids.push(n.id); });
                if (ids.length) {
                    var corps = new URLSearchParams();
                    corps.set('action', 'affichees');
                    corps.set('ids', ids.join(','));
                    corps.set('csrf_token', jeton);
                    fetch('notifications.php', { method: 'POST', body: corps }).catch(function () {});
                }
            })
            .catch(function () {});
    }

    // Première vérification au chargement, puis toutes les 30 s.
    setTimeout(verifier, 1500);
    setInterval(verifier, 30000);
})();
</script>

<script>
// Notifications « application fermée » (Web Push) : proposition d'activation
// et abonnement du navigateur au service de notification.
(function () {
    var invite = document.getElementById('trobaInvitePush');
    if (!invite) { return; }
    var supporte = ('Notification' in window);
    var jeton = <?php echo json_encode($trobaCsrf); ?>;

    function fermerInvite() {
        invite.hidden = true;
        invite.style.display = 'none';
    }

    function refusMemorise() {
        try { return localStorage.getItem('trombi_notif_refus') === '1'; } catch (e) { return false; }
    }

    // Proposition d'activation : une seule fois, et seulement si la personne
    // n'a jamais répondu à la demande du navigateur.
    if (supporte && Notification.permission === 'default' && !refusMemorise()) {
        invite.hidden = false;
        invite.style.display = '';
    } else {
        fermerInvite();
    }

    document.getElementById('trobaInviteOui').addEventListener('click', function () {
        fermerInvite();
        if (!supporte) { return; }
        try {
            // Selon les navigateurs, la demande renvoie une promesse ou passe
            // par une fonction de rappel : les deux formes sont gérées.
            var demande = Notification.requestPermission(function () { abonnerAuPush(); });
            if (demande && typeof demande.then === 'function') {
                demande.then(function () { abonnerAuPush(); });
            }
        } catch (e) {
            /* rien à faire : les bandeaux dans la page suffisent */
        }
    });

    document.getElementById('trobaInviteNon').addEventListener('click', function () {
        fermerInvite();
        try { localStorage.setItem('trombi_notif_refus', '1'); } catch (e) {}
    });

    /**
     * Abonnement au service de notification du navigateur : c'est lui qui
     * permet de prévenir le membre même quand le site est fermé.
     * Sans clé VAPID côté serveur, la fonction ne fait rien.
     */
    function abonnerAuPush() {
        if (!supporte || Notification.permission !== 'granted') { return; }
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) { return; }

        fetch('push_subscribe.php', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.ok || !d.cle) { return null; }
                return navigator.serviceWorker.ready.then(function (reg) {
                    return reg.pushManager.getSubscription().then(function (dejaLa) {
                        if (dejaLa) { return dejaLa; }
                        return reg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: base64UrlVersOctets(d.cle)
                        });
                    });
                });
            })
            .then(function (abonnement) {
                if (!abonnement) { return; }
                var brut = abonnement.toJSON();
                if (!brut.keys || !brut.keys.p256dh || !brut.keys.auth) { return; }
                var corps = new URLSearchParams();
                corps.set('action', 'abonner');
                corps.set('endpoint', brut.endpoint);
                corps.set('p256dh', brut.keys.p256dh);
                corps.set('auth', brut.keys.auth);
                corps.set('csrf_token', jeton);
                return fetch('push_subscribe.php', { method: 'POST', body: corps });
            })
            .catch(function () { /* le navigateur refuse : les bandeaux suffisent */ });
    }

    /** La clé VAPID doit être fournie au navigateur en octets. */
    function base64UrlVersOctets(base64url) {
        var base64 = (base64url + '==='.slice((base64url.length + 3) % 4))
            .replace(/-/g, '+').replace(/_/g, '/');
        var brut = atob(base64);
        var octets = new Uint8Array(brut.length);
        for (var i = 0; i < brut.length; i++) { octets[i] = brut.charCodeAt(i); }
        return octets;
    }
})();
</script>
<?php endif; ?>
