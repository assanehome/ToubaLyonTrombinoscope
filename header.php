<?php
/**
 * Touba Lyon 2026 - Unified Navigation Header
 * Standardized colors & assets including logo and full navigation actions.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPage = basename($_SERVER['PHP_SELF']);
$isAdmin     = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isPlayer    = isset($_SESSION['player_id']);
?>

<header class="hdr" id="hdr">
    <div class="hdr-inner">

        <!-- ── Logo ── -->
        <a href="<?php echo $isAdmin ? 'admin_dashboard.php' : 'index.php'; ?>" class="hdr-logo" aria-label="Accueil Touba Lyon">
            <div class="hdr-logo-img-wrap">
                <img src="touba_lyon_logo.png" alt="Logo Touba Lyon">
            </div>
            <div class="hdr-logo-text">
                <span class="hdr-logo-name">Touba Lyon</span>
                <span class="hdr-logo-tagline">
                    Trombinoscope 2026
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
                <a href="admin_dashboard.php" class="hdr-link <?php echo ($currentPage === 'admin_dashboard.php') ? 'hdr-link--active' : ''; ?>">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    Tableau de bord
                </a>
                <a href="logout.php" class="hdr-btn hdr-btn--danger">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Se déconnecter
                </a>

            <?php elseif ($isPlayer): ?>

                <div class="hdr-player-card">
                    <span class="hdr-player-name"><?php echo htmlspecialchars($_SESSION['player_name'] ?? 'Joueur'); ?></span>
                    <span class="hdr-player-score">🏆 <?php echo (int)($_SESSION['player_score'] ?? 0); ?> pts</span>
                </div>
                <a href="index.php" class="hdr-link <?php echo ($currentPage === 'index.php') ? 'hdr-link--active' : ''; ?>">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    Trombinoscope
                </a>
                <a href="profile.php" class="hdr-link <?php echo ($currentPage === 'profile.php') ? 'hdr-link--active' : ''; ?>">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Mon Profil
                </a>
                <a href="play_logout.php" class="hdr-btn hdr-btn--ghost">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Déconnexion
                </a>

            <?php else: ?>

                <?php if ($currentPage === 'admin_login.php'): ?>
                    <a href="login.php" class="hdr-link">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Connexion Membre
                    </a>

                <?php elseif ($currentPage === 'login.php'): ?>
                    <a href="admin_login.php" class="hdr-link">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Espace Admin
                    </a>
                    <a href="register.php" class="hdr-btn hdr-btn--gold">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
                        S'inscrire
                    </a>

                <?php elseif ($currentPage === 'register.php'): ?>
                    <a href="login.php" class="hdr-link">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                        Se connecter
                    </a>
                    <a href="admin_login.php" class="hdr-link">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Espace Admin
                    </a>

                <?php else: ?>
                    <a href="login.php" class="hdr-link">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                        Connexion
                    </a>
                    <a href="admin_login.php" class="hdr-link">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Espace Admin
                    </a>
                    <a href="register.php" class="hdr-btn hdr-btn--gold">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
                        S'inscrire
                    </a>
                <?php endif; ?>

            <?php endif; ?>

        </nav>

        <!-- ── Mobile Menu Burger Trigger ── -->
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
