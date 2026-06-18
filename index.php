<?php
/**
 * Touba Lyon 2026 - Trombinoscope Homepage
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not authenticated as a member (player)
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}

if (isset($_SESSION['player_id'])) {
    try {
        $stmtScore = $pdo->prepare("SELECT score FROM membres WHERE id = ?");
        $stmtScore->execute([$_SESSION['player_id']]);
        $pScore = $stmtScore->fetchColumn();
        if ($pScore !== false) {
            $_SESSION['player_score'] = $pScore;
        }
    } catch (Exception $e) {
        // Silent catch
    }
}

try {
    // Retrieve only approved members qui ont une photo (exclut les adhésions Dahira sans photo)
    $stmt = $pdo->query("SELECT * FROM membres WHERE status = 'approved' AND photo_path != '' ORDER BY prenom ASC, nom ASC");
    $members = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Touba Lyon index: ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trombinoscope - Touba Lyon</title>
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
        <!-- User Welcome Banner -->
        <?php if (isset($_SESSION['player_id'])): ?>
            <div class="user-welcome-banner glass-card" style="margin-top: 2rem; margin-bottom: 1rem; padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center; border-radius: 20px; flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <span style="font-size: 1.15rem; font-weight: 500;">Bienvenue, <strong class="gold-text"><?php echo htmlspecialchars($_SESSION['player_name']); ?></strong> !</span>
                    <span class="player-score-badge" style="background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: var(--white); font-weight: 800; padding: 0.5rem 1.25rem; border-radius: 50px; font-size: 1rem; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 4px 12px rgba(27,67,50,0.3);">
                        🏆 <?php echo (int)($_SESSION['player_score'] ?? 0); ?> pts
                    </span>
                </div>
                <a href="kikanla.php" class="btn btn-primary btn-sm">🎮 Jouer à Ki Kan La</a>
            </div>
        <?php endif; ?>

        <!-- Intro Header -->
        <section class="intro-section">
            <h1 class="intro-title">Membres de <span class="gold-text">Touba Lyon</span></h1>
            <p class="intro-desc">Retrouvez l'annuaire illustré de notre communauté — photos, noms et fraternité.</p>

            <!-- Search bar -->
            <div class="search-container">
                <svg class="search-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <input type="text" id="search-input" class="search-input" placeholder="Rechercher un membre par nom ou prénom...">
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
                    Le trombinoscope est actuellement vide ou les inscriptions sont en cours de validation.
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
                    <div class="member-card" data-name="<?php echo htmlspecialchars($fullName); ?>" data-civilite="<?php echo htmlspecialchars($m['civilite'] ?? 'Goor Yalla'); ?>">
                        <div class="member-photo-container">
                            <img src="uploads/<?php echo htmlspecialchars($m['photo_path']); ?>" class="member-photo" alt="Photo de <?php echo htmlspecialchars($fullName); ?>" loading="lazy">
                        </div>
                        <div class="member-info">
                            <h3 class="member-name"><?php echo htmlspecialchars($fullName); ?></h3>
                            <?php if (!empty($m['civilite'])): ?>
                                <span class="member-civilite-badge"><?php echo htmlspecialchars($m['civilite']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
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
    </script>
</body>
</html>
