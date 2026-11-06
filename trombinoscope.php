<?php
/**
 * Touba Lyon 2026 - Dahira - Mubawwa-A-Sidqin · Trombinoscope
 *
 * Page dédiée au trombinoscope : annuaire illustré de la communauté
 * (photos, noms, civilité), avec recherche et filtres.
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';
require_once __DIR__ . '/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_config.php'; session_start();
}

// Redirect to login if not authenticated as a member (player)
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/player_approved_guard.php';

try {
    // Retrieve only approved members qui ont une photo (exclut les adhésions Dahira sans photo)
    $stmt = $pdo->query("SELECT * FROM membres WHERE status = 'approved' AND photo_path != '' ORDER BY prenom ASC, nom ASC");
    $members = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Touba Lyon trombinoscope: ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🖼️ Trombinoscope - Dahira - Mubawwa-A-Sidqin - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .filter-container {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }
        .filter-btn {
            background: rgba(255, 255, 255, 0.05);
            color: var(--white);
            border: 1px solid var(--glass-border);
            padding: 0.6rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .filter-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--gold);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.15);
        }
        .filter-btn.active {
            background: var(--accent);
            color: var(--secondary);
            border-color: var(--accent);
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/member_menu.php'; ?>
            <div class="dashboard-main">

        <!-- Intro Header -->
        <section class="intro-section">
            <h1 class="intro-title">Membres de <span class="gold-text">Touba Lyon</span></h1>
            <p class="intro-desc">Retrouvez l'annuaire illustré de notre communauté — photos, noms et fraternité.</p>

            <!-- Search bar -->
            <div class="search-container">
                <svg class="search-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <input type="text" id="search-input" autocomplete="off" class="search-input" placeholder="Rechercher un membre par nom ou prénom...">
            </div>

            <div class="filter-container">
                <button class="filter-btn active" data-filter="all">Tous</button>
                <button class="filter-btn" data-filter="Goor Yalla">Goor Yalla</button>
                <button class="filter-btn" data-filter="Sokhna">Sokhna</button>
            </div>
        </section>

        <!-- Grid of Members -->
        <?php if (empty($members)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">👥</div>
                <h2>Aucun membre validé</h2>
                <p style="margin-top: 0.5rem; color: var(--text-muted);">
                    Le Dahira - Mubawwa-A-Sidqin est actuellement vide ou les inscriptions sont en cours de validation.
                </p>
                <div style="margin-top: 1.5rem;">
                    <a href="register.php" class="btn btn-primary">Créer la première inscription</a>
                </div>
            </div>
        <?php else: ?>
            <div class="trombi-grid" id="trombi-grid">
                <?php foreach ($members as $m): ?>
                    <?php 
                        $fullName = $m['prenom'] . ' ' . $m['nom'];
                    ?>
                    <a class="member-card" href="membre_detail.php?id=<?php echo (int)$m['id']; ?>"
                        data-name="<?php echo htmlspecialchars($fullName); ?>" data-civilite="<?php echo htmlspecialchars($m['civilite'] ?? 'Goor Yalla'); ?>">
                        <div class="member-photo-container">
                            <img src="uploads/<?php echo htmlspecialchars($m['photo_path']); ?>" class="member-photo" alt="Photo de <?php echo htmlspecialchars($fullName); ?>" loading="lazy">
                        </div>
                        <div class="member-info">
                            <h3 class="member-name"><?php echo htmlspecialchars($fullName); ?></h3>
                            <?php if (!empty($m['civilite'])): ?>
                                <span class="member-civilite-badge"><?php echo htmlspecialchars($m['civilite']); ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <!-- No results message -->
            <div class="empty-state" id="no-results" style="display: none;">
                <div class="empty-state-icon">🔍</div>
                <h2>Aucun résultat trouvé</h2>
                <p style="margin-top: 0.5rem; color: var(--text-muted);">
                    Aucun membre ne correspond à votre recherche. Essayez d'autres termes.
                </p>
            </div>
        <?php endif; ?>
            </div>
        </div>
    </main>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <script>
        const searchInput = document.getElementById('search-input');
        const filterBtns = document.querySelectorAll('.filter-btn');
        const cards = document.querySelectorAll('.member-card');
        const grid = document.getElementById('trombi-grid');
        const noResults = document.getElementById('no-results');

        let activeFilter = 'all';

        function filterMembers() {
            const term = searchInput ? searchInput.value.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim() : '';
            let matchCount = 0;

            cards.forEach(card => {
                const name = card.getAttribute('data-name').toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
                const civilite = card.getAttribute('data-civilite');

                const matchesSearch = name.includes(term);
                const matchesFilter = activeFilter === 'all' || civilite === activeFilter;

                if (matchesSearch && matchesFilter) {
                    card.style.display = 'flex';
                    matchCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            if (matchCount === 0) {
                grid.style.display = 'none';
                noResults.style.display = 'block';
            } else {
                grid.style.display = 'grid';
                noResults.style.display = 'none';
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterMembers);
        }

        filterBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                filterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                activeFilter = btn.getAttribute('data-filter');
                filterMembers();
            });
        });

        // Le filtre n'est pas conservé : à chaque affichage de la page, retour à « Tous » sans recherche
        function reinitialiserFiltre() {
            if (searchInput) { searchInput.value = ''; }
            activeFilter = 'all';
            filterBtns.forEach(b => b.classList.toggle('active', b.getAttribute('data-filter') === 'all'));
            filterMembers();
        }
        window.addEventListener('pageshow', reinitialiserFiltre);
        reinitialiserFiltre();
    </script>
</body>
</html>
