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
    // Filtre par commune : communes distinctes des membres affichés
    $communesFiltre = [];
    foreach ($members as $mc) { $cn = trim((string) ($mc['commune'] ?? '')); if ($cn !== '') { $communesFiltre[mb_strtolower($cn)] = $cn; } }
    ksort($communesFiltre, SORT_NATURAL | SORT_FLAG_CASE);
    // Filtre par statut : statuts distincts des membres affichés
    $statutsFiltre = [];
    foreach ($members as $ms) { $sn = trim((string) ($ms['statut'] ?? '')); if ($sn !== '') { $statutsFiltre[mb_strtolower($sn)] = $sn; } }
    ksort($statutsFiltre, SORT_NATURAL | SORT_FLAG_CASE);
    // Filtre par commission : commissions et appartenance des membres (membres et responsables de chaque commission)
    $commissionsFiltre = $pdo->query("SELECT id, nom FROM commissions ORDER BY nom ASC")->fetchAll();
    $comParMembre = [];
    foreach (['commission_membres', 'commission_gestionnaires'] as $tableCom) {
        try {
            foreach ($pdo->query("SELECT commission_id, membre_id FROM $tableCom")->fetchAll() as $lc) {
                $comParMembre[(int) $lc['membre_id']][(int) $lc['commission_id']] = true;
            }
        } catch (Exception $e) { /* table absente : filtre sans ces membres */ }
    }
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
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .filter-commission { display: flex; justify-content: center; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.9rem; }
        .filter-commission select { background: rgba(255,255,255,0.06); border: 1px solid var(--glass-border); color: var(--white); border-radius: 12px; padding: 0.55rem 1rem; font-size: 0.9rem; color-scheme: dark; min-width: 240px; max-width: 100%; }
        .filter-commission select option { background-color: #0c241a; color: #fff; }
        @media (max-width: 600px) { .filter-commission select { width: 100%; } }
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

            <!-- Autres filtres (civilité, commission, commune) : groupe fermé par défaut ; seule la recherche par texte reste affichée -->
            <details id="filtres-trombi" class="glass-card" style="max-width:640px; margin:1rem auto 0; text-align:left;">
                <summary>🔎 Plus de filtres</summary><!-- civilité, commission, commune, statut -->
                <div style="margin-top:0.8rem;">
            <div class="filter-container">
                <button class="filter-btn active" data-filter="all">Tous</button>
                <button class="filter-btn" data-filter="Goor Yalla">Goor Yalla</button>
                <button class="filter-btn" data-filter="Sokhna">Sokhna</button>
            </div>

            <?php if (!empty($commissionsFiltre) || !empty($communesFiltre) || !empty($statutsFiltre)): ?>
            <div class="filter-commission">
                <?php if (!empty($commissionsFiltre)): ?>
                <select id="filter-commission" aria-label="Filtrer par commission">
                    <option value="">📋 Commission : toutes</option>
                    <?php foreach ($commissionsFiltre as $cf): ?><option value="<?php echo (int) $cf['id']; ?>"><?php echo htmlspecialchars($cf['nom']); ?></option><?php endforeach; ?>
                </select>
                <?php endif; ?>
                <?php if (!empty($statutsFiltre)): ?>
                <select id="filter-statut" aria-label="Filtrer par statut">
                    <option value="">🏷️ Statut : tous</option>
                    <?php foreach ($statutsFiltre as $snom): ?><option value="<?php echo htmlspecialchars(mb_strtolower($snom), ENT_QUOTES); ?>"><?php echo htmlspecialchars($snom); ?></option><?php endforeach; ?>
                </select>
                <?php endif; ?>
                <?php if (!empty($communesFiltre)): ?>
                <select id="filter-commune" aria-label="Filtrer par commune">
                    <option value="">📍 Commune : toutes</option>
                    <?php foreach ($communesFiltre as $cnom): ?><option value="<?php echo htmlspecialchars(mb_strtolower($cnom), ENT_QUOTES); ?>"><?php echo htmlspecialchars($cnom); ?></option><?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <?php endif; ?>
                </div>
            </details>
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
                        data-name="<?php echo htmlspecialchars($fullName); ?>" data-civilite="<?php echo htmlspecialchars($m['civilite'] ?? 'Goor Yalla'); ?>" data-commissions="<?php echo ',' . implode(',', array_keys($comParMembre[(int) $m['id']] ?? [])) . ','; ?>" data-commune="<?php echo htmlspecialchars(mb_strtolower(trim((string) ($m['commune'] ?? ''))), ENT_QUOTES); ?>" data-statut="<?php echo htmlspecialchars(mb_strtolower(trim((string) ($m['statut'] ?? ''))), ENT_QUOTES); ?>">
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
        const commissionSel = document.getElementById('filter-commission');
        const communeSel = document.getElementById('filter-commune');
        const statutSel = document.getElementById('filter-statut');

        function filterMembers() {
            const term = searchInput ? searchInput.value.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").trim() : '';
            let matchCount = 0;

            cards.forEach(card => {
                const name = card.getAttribute('data-name').toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
                const civilite = card.getAttribute('data-civilite');

                const matchesSearch = name.includes(term);
                const matchesFilter = activeFilter === 'all' || civilite === activeFilter;
                const comChoisie = commissionSel ? commissionSel.value : '';
                const matchesCommission = comChoisie === '' || (card.getAttribute('data-commissions') || '').indexOf(',' + comChoisie + ',') !== -1;

                const communeChoisie = communeSel ? communeSel.value : '';
                const matchesCommune = communeChoisie === '' || card.getAttribute('data-commune') === communeChoisie;

                const statutChoisi = statutSel ? statutSel.value : '';
                const matchesStatut = statutChoisi === '' || card.getAttribute('data-statut') === statutChoisi;

                if (matchesSearch && matchesFilter && matchesCommission && matchesCommune && matchesStatut) {
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
                grid.style.display = 'flex';
                noResults.style.display = 'none';
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterMembers);
        }

        if (commissionSel) { commissionSel.addEventListener('change', filterMembers); }
        if (communeSel) { communeSel.addEventListener('change', filterMembers); }
        if (statutSel) { statutSel.addEventListener('change', filterMembers); }

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
            if (commissionSel) { commissionSel.value = ''; commissionSel.dispatchEvent(new Event('change')); }
            if (communeSel) { communeSel.value = ''; communeSel.dispatchEvent(new Event('change')); }
            if (statutSel) { statutSel.value = ''; statutSel.dispatchEvent(new Event('change')); }
            activeFilter = 'all';
            filterBtns.forEach(b => b.classList.toggle('active', b.getAttribute('data-filter') === 'all'));
            filterMembers();
        }
        window.addEventListener('pageshow', reinitialiserFiltre);
        reinitialiserFiltre();
    </script>
</body>
</html>
