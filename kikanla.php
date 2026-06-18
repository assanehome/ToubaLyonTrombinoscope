<?php
/**
 * Touba Lyon 2026 - Ki Kan La Game & Leaderboard
 */
require_once __DIR__ . '/db_setup.php';
require_once __DIR__ . '/admin_redirect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check player authentication
if (!isset($_SESSION['player_id'])) {
    header('Location: login.php');
    exit;
}

$playerId = $_SESSION['player_id'];

// Retrieve current player's data from BDD to keep score accurate (members only, not admins)
try {
    $stmt = $pdo->prepare("SELECT score, prenom, nom, civilite FROM membres WHERE id = ? AND status = 'approved'");
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();
    
    if (!$player) {
        // Player not found, logout
        header('Location: play_logout.php');
        exit;
    }
    
    $playerScore = $player['score'];
    $playerName = $player['prenom'] . ' ' . $player['nom'];
    $_SESSION['player_score'] = $playerScore;
} catch (Exception $e) {
    error_log('Touba Lyon kikanla (player): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

$gameError = '';
try {
    // Check if we have at least 3 approved members with photos to allow playing
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM membres WHERE status = 'approved' AND photo_path IS NOT NULL AND photo_path != '' AND photo_path != 'default.jpg'");
    $totalMembers = $stmtCount->fetchColumn();
    
    if ($totalMembers < 3) {
        $gameError = "Le jeu requiert au moins 3 membres validés avec photo dans le Trombinoscope pour pouvoir jouer. Veuillez en ajouter d'abord.";
    }
} catch (Exception $e) {
    error_log('Touba Lyon kikanla (count): ' . $e->getMessage());
    $gameError = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
}

  // Retrieve leaderboard players (approved members only, sorted by score descending)
  try {
      $stmtLeaderboard = $pdo->query("SELECT id, prenom, nom, civilite, score, photo_path FROM membres WHERE status = 'approved' ORDER BY score DESC, created_at ASC LIMIT 10");
      $leaderboard = $stmtLeaderboard->fetchAll();
  } catch (Exception $e) {
      $leaderboard = [];
  }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ki Kan La - Le Jeu du Trombinoscope</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Game Dashboard Specific Styles */
        .game-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.25rem 2rem;
            background: var(--glass);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
        }
        .player-info {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        .player-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--white);
        }
        .player-score-badge {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            font-weight: 800;
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            font-size: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 4px 12px rgba(27, 67, 50, 0.3);
        }
        .game-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
            margin-top: 1.5rem;
        }
        @media(min-width: 768px) {
            .game-layout {
                grid-template-columns: 350px 1fr;
            }
        }
        .game-photo-card {
            display: flex;
            justify-content: center;
            align-items: center;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 1.5rem;
            height: fit-content;
        }
        .game-photo-container {
            width: 100%;
            aspect-ratio: 1/1;
            border-radius: 18px;
            overflow: hidden;
            border: 2px solid var(--glass-border);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .game-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        .game-photo-card:hover .game-photo {
            transform: scale(1.05);
        }
        .game-choices-card {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .game-question-title {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--gold);
            margin-bottom: 0.5rem;
            text-align: center;
        }
        .game-question-subtitle {
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }
        .choices-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .choice-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.25rem 2rem;
            color: var(--white);
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            text-align: left;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .choice-btn:hover:not([disabled]) {
            background: rgba(255, 255, 255, 0.12);
            border-color: var(--gold);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.15);
        }
        .choice-btn.correct {
            background: rgba(27, 67, 50, 0.8) !important;
            border-color: var(--accent) !important;
            color: var(--white) !important;
            box-shadow: 0 0 15px rgba(212, 175, 55, 0.3);
        }
        .choice-btn.incorrect {
            background: rgba(220, 53, 69, 0.2) !important;
            border-color: var(--danger) !important;
            color: rgba(255,255,255,0.7) !important;
            text-decoration: line-through;
        }
        .game-feedback-box {
            border-radius: 18px;
            padding: 1.5rem;
            margin-top: 2rem;
            text-align: center;
            animation: fadeIn 0.4s ease-out;
        }
        .feedback-success {
            background: rgba(27, 67, 50, 0.4);
            border: 1px solid var(--accent);
            color: var(--white);
        }
        .feedback-danger {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid var(--danger);
            color: var(--white);
        }
        .leaderboard-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        .leaderboard-table th, .leaderboard-table td {
            padding: 1.25rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .leaderboard-table th {
            color: var(--gold);
            font-weight: 700;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .leaderboard-row {
            transition: background 0.3s;
        }
        .leaderboard-row:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        .rank-col {
            font-weight: 800;
            width: 80px;
        }
        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            font-size: 0.9rem;
        }
        .rank-1 { background: rgba(212, 175, 55, 0.2); color: var(--gold); border: 1px solid var(--gold); }
        .rank-2 { background: rgba(192, 192, 192, 0.2); color: #c0c0c0; border: 1px solid #c0c0c0; }
        .rank-3 { background: rgba(205, 127, 50, 0.2); color: #cd7f32; border: 1px solid #cd7f32; }
        .rank-other { color: var(--text-muted); }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Mobile specific layout optimizations */
        @media (max-width: 550px) {
            .game-header {
                flex-direction: column !important;
                gap: 0.75rem !important;
                padding: 1rem !important;
                align-items: center !important;
                text-align: center !important;
            }
            .player-info {
                flex-direction: column !important;
                gap: 0.25rem !important;
            }
            .tabs-container {
                gap: 0.5rem !important;
                width: 100% !important;
            }
            .tab-btn {
                flex: 1 !important;
                padding: 0.75rem 0.5rem !important;
                font-size: 0.88rem !important;
                justify-content: center !important;
            }
            /* Visual showcase of Top 5 */
            .top-player-card {
                min-width: 90px !important;
                max-width: 100px !important;
                padding: 0.75rem 0.25rem !important;
                border-radius: 12px !important;
            }
            .top-player-card span {
                font-size: 0.75rem !important;
            }
            .top-player-card .gold-text {
                font-size: 0.8rem !important;
            }
            .top-player-card div {
                width: 50px !important;
                height: 50px !important;
            }
            /* Visual podium of Top 3 (Lobby) */
            .podium-card {
                padding: 0.75rem 0.25rem !important;
                border-radius: 12px !important;
            }
            .podium-photo-wrap {
                width: 52px !important;
                height: 52px !important;
            }
            .podium-photo-wrap-1 {
                width: 62px !important;
                height: 62px !important;
            }
            .podium-name {
                font-size: 0.7rem !important;
            }
            .podium-score {
                font-size: 0.75rem !important;
            }
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/header.php'; ?>

    <main class="container">
        <!-- Game stats bar -->
        <div class="game-header">
            <div class="player-info">
                <span class="player-name">Joueur : <strong class="gold-text"><?php echo htmlspecialchars($playerName); ?></strong></span>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <span style="color: var(--text-muted); font-size: 0.9rem;">Votre score global :</span>
                <span class="player-score-badge"><?php echo $playerScore; ?> pts</span>
            </div>
        </div>

        <!-- Navigation tabs -->
        <div class="tabs-container" style="display: flex; justify-content: center; gap: 0.75rem; margin-bottom: 2rem; flex-wrap: wrap;">
            <button class="tab-btn active" id="tab-play-btn" onclick="switchTab('play')">🎮 Jouer au Jeu</button>
            <button class="tab-btn" id="tab-leaderboard-btn" onclick="switchTab('leaderboard')">🏆 Classement Global</button>
        </div>

        <!-- PLAY TAB -->
        <div id="tab-play" class="tab-content active">
            <?php if (!empty($gameError)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🎮</div>
                    <h2>Impossible de jouer</h2>
                    <p style="margin-top: 0.5rem; color: var(--text-muted); max-width: 500px; margin-left: auto; margin-right: auto;">
                        <?php echo htmlspecialchars($gameError); ?>
                    </p>
                </div>
            <?php else: ?>
                <!-- Welcome Lobby to start the game -->
                <div class="form-card" style="max-width: 600px; margin: 0 auto; text-align: center; padding: 3rem 2rem;">
                    <div style="font-size: 3rem; margin-bottom: 0.5rem;">🎮</div>
                    <h2 class="gold-text" style="font-size: 2.2rem; margin-bottom: 0.5rem; font-weight: 800;">Ki Kan La ?</h2>
                    <p style="color: var(--text-muted); margin-bottom: 2rem; font-size: 1.05rem; line-height: 1.6;">
                        Prêt à tester vos connaissances sur les membres de notre communauté ?<br>
                        Identifiez correctement les 10 visages qui vous seront présentés pour accumuler un maximum de points !
                    </p>

                    <a href="play.php" class="btn btn-primary" style="padding: 1rem 2.5rem; font-size: 1.1rem; width: 100%; display: block; text-align: center; margin-bottom: 2.5rem;">
                        ⚡ Lancer le Jeu
                    </a>

                    <!-- Showcase of Top 3 Players -->
                    <?php if (!empty($leaderboard)): ?>
                        <div style="margin-bottom: 2.5rem;">
                            <h4 style="color: var(--gold); font-size: 0.95rem; margin-bottom: 1.5rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; text-align: center;">🏆 Top 3 des Joueurs</h4>
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; justify-content: center; align-items: end;">
                                <?php 
                                $top3 = array_slice($leaderboard, 0, 3);
                                $rankIdx = 1;
                                foreach ($top3 as $p):
                                    $pName = $p['prenom'] . ' ' . $p['nom'];
                                    $pPhoto = !empty($p['photo_path']) ? htmlspecialchars($p['photo_path']) : 'default.jpg';
                                    $pScore = (int)$p['score'];
                                    
                                    // Custom visual weights for ranks
                                    if ($rankIdx === 1) {
                                        $medal = '🥇';
                                        $photoSize = '72px';
                                        $cardBg = 'rgba(212, 175, 55, 0.05)';
                                        $cardBorder = '1.5px solid var(--gold)';
                                        $shadow = '0 6px 20px rgba(212, 175, 55, 0.15)';
                                        $scale = 'scale(1.05)';
                                    } elseif ($rankIdx === 2) {
                                        $medal = '🥈';
                                        $photoSize = '62px';
                                        $cardBg = 'rgba(255, 255, 255, 0.01)';
                                        $cardBorder = '1px solid rgba(192, 192, 192, 0.25)';
                                        $shadow = '0 4px 10px rgba(0,0,0,0.2)';
                                        $scale = 'scale(1)';
                                    } else {
                                        $medal = '🥉';
                                        $photoSize = '62px';
                                        $cardBg = 'rgba(255, 255, 255, 0.01)';
                                        $cardBorder = '1px solid rgba(205, 127, 50, 0.25)';
                                        $shadow = '0 4px 10px rgba(0,0,0,0.2)';
                                        $scale = 'scale(1)';
                                    }
                                ?>
                                    <div class="podium-card" style="background: <?php echo $cardBg; ?>; border: <?php echo $cardBorder; ?>; border-radius: 18px; padding: 1rem 0.4rem; text-align: center; display: flex; flex-direction: column; align-items: center; position: relative; box-shadow: <?php echo $shadow; ?>; transform: <?php echo $scale; ?>; transition: var(--transition);">
                                        <span style="position: absolute; top: -14px; font-size: 1.25rem; z-index: 2;"><?php echo $medal; ?></span>
                                        <div class="<?php echo ($rankIdx === 1) ? 'podium-photo-wrap-1' : 'podium-photo-wrap'; ?>" style="width: <?php echo $photoSize; ?>; height: <?php echo $photoSize; ?>; border-radius: 50%; overflow: hidden; border: 2.5px solid rgba(255, 255, 255, 0.15); margin-bottom: 0.6rem; box-shadow: 0 4px 10px rgba(0,0,0,0.3);">
                                            <img src="uploads/<?php echo $pPhoto; ?>" alt="Photo de <?php echo htmlspecialchars($pName); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                        </div>
                                        <span class="podium-name" style="font-weight: 700; color: var(--white); font-size: 0.78rem; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%; margin-bottom: 0.2rem;">
                                            <?php echo htmlspecialchars($pName); ?>
                                        </span>
                                        <span class="podium-score gold-text" style="font-weight: 800; font-size: 0.82rem;">
                                            <?php echo $pScore; ?> pts
                                        </span>
                                    </div>
                                <?php 
                                    $rankIdx++;
                                endforeach; 
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border); padding: 1.25rem; border-radius: 16px; margin-bottom: 1rem; text-align: left;">
                        <h4 style="color: var(--gold); font-size: 1.05rem; margin-bottom: 0.5rem; font-weight: 700;">Règles du jeu :</h4>
                        <ul style="color: var(--text-muted); padding-left: 1.25rem; font-size: 0.95rem; line-height: 1.5;">
                             <li style="margin-bottom: 0.35rem;">10 questions par session.</li>
                             <li style="margin-bottom: 0.35rem;">Chaque réponse correcte vous rapporte <strong class="gold-text">+10 points</strong>.</li>
                             <li>Les points s'ajoutent directement à votre classement global.</li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- LEADERBOARD TAB -->
        <div id="tab-leaderboard" class="tab-content" style="display: none;">
            <div class="form-card" style="max-width: 800px; margin: 0 auto; padding: 2.5rem;">
                <h2 style="text-align: center; color: var(--gold); font-size: 1.8rem; margin-bottom: 0.5rem;">Classement des Joueurs</h2>
                <p style="text-align: center; color: var(--text-muted); margin-bottom: 2rem;">Les 10 meilleurs scores du Trombinoscope Touba Lyon.</p>

                <?php if (empty($leaderboard)): ?>
                    <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                        Aucun score enregistré pour le moment. Soyez le premier à jouer !
                    </div>
                <?php else: ?>
                    <!-- Top 5 Visual Showcase -->
                    <div class="top-players-showcase" style="display: flex; flex-wrap: wrap; justify-content: center; gap: 1.25rem; margin-bottom: 3rem;">
                        <?php 
                        $top5 = array_slice($leaderboard, 0, 5);
                        $rankIndex = 1;
                        foreach ($top5 as $player):
                            $pName = $player['prenom'] . ' ' . $player['nom'];
                            $pPhoto = !empty($player['photo_path']) ? htmlspecialchars($player['photo_path']) : 'default.jpg';
                            $pScore = (int)$player['score'];
                            
                            // Custom styling/borders for different ranks
                            $borderColor = 'var(--glass-border)';
                            $boxShadow = 'none';
                            $medal = $rankIndex;
                            if ($rankIndex === 1) {
                                $borderColor = 'var(--gold)';
                                $boxShadow = '0 0 15px rgba(212, 175, 55, 0.4)';
                                $medal = '🥇';
                            } elseif ($rankIndex === 2) {
                                $borderColor = '#c0c0c0';
                                $boxShadow = '0 0 10px rgba(192, 192, 192, 0.25)';
                                $medal = '🥈';
                            } elseif ($rankIndex === 3) {
                                $borderColor = '#cd7f32';
                                $boxShadow = '0 0 10px rgba(205, 127, 50, 0.2)';
                                $medal = '🥉';
                            }
                        ?>
                            <div class="top-player-card glass-card" style="flex: 1; min-width: 120px; max-width: 140px; padding: 1.25rem 0.75rem; text-align: center; border-radius: 20px; border-color: <?php echo $borderColor; ?>; box-shadow: <?php echo $boxShadow; ?>; position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                <span class="rank-badge-podium" style="position: absolute; top: -12px; font-size: 1.3rem; z-index: 2;">
                                    <?php echo $medal; ?>
                                </span>
                                <div style="width: 70px; height: 70px; border-radius: 50%; overflow: hidden; border: 2.5px solid <?php echo $borderColor; ?>; margin-bottom: 0.75rem; box-shadow: 0 4px 10px rgba(0,0,0,0.3);">
                                    <img src="uploads/<?php echo $pPhoto; ?>" alt="Photo de <?php echo htmlspecialchars($pName); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                </div>
                                <span style="font-weight: 700; color: var(--white); font-size: 0.85rem; display: block; margin-bottom: 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%;">
                                    <?php echo htmlspecialchars($pName); ?>
                                </span>
                                <span class="gold-text" style="font-weight: 800; font-size: 0.95rem;">
                                    <?php echo $pScore; ?> pts
                                </span>
                            </div>
                        <?php 
                            $rankIndex++;
                        endforeach; 
                        ?>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="leaderboard-table">
                            <thead>
                                <tr>
                                    <th class="rank-col">Rang</th>
                                    <th>Joueur</th>
                                    <th style="text-align: right;">Score Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                    $rank = 1;
                                    foreach ($leaderboard as $row): 
                                        $rankClass = 'rank-other';
                                        $rankBadge = $rank;
                                        if ($rank === 1) { $rankClass = 'rank-1'; $rankBadge = '🥇'; }
                                        elseif ($rank === 2) { $rankClass = 'rank-2'; $rankBadge = '🥈'; }
                                        elseif ($rank === 3) { $rankClass = 'rank-3'; $rankBadge = '🥉'; }
                                ?>
                                    <?php 
                                        $rowName = $row['prenom'] . ' ' . $row['nom'];
                                    ?>
                                    <tr class="leaderboard-row">
                                        <td class="rank-col">
                                            <span class="rank-badge <?php echo $rankClass; ?>"><?php echo $rankBadge; ?></span>
                                        </td>
                                        <td style="font-weight: 600; color: var(--white);">
                                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                <img src="uploads/<?php echo htmlspecialchars($row['photo_path']); ?>" alt="Photo de <?php echo htmlspecialchars($rowName); ?>" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1.5px solid var(--glass-border);">
                                                <span>
                                                    <?php echo htmlspecialchars($rowName); ?>
                                                    <?php if ((int)$row['id'] === (int)$playerId): ?>
                                                        <span class="gold-text" style="font-size: 0.8rem; margin-left: 0.5rem;">(Vous)</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td style="text-align: right; font-weight: 800; color: var(--gold); font-size: 1.1rem;">
                                            <?php echo (int)$row['score']; ?> pts
                                        </td>
                                    </tr>
                                <?php 
                                    $rank++;
                                    endforeach; 
                                ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <footer class="app-footer">
        <p>&copy; 2026 Touba Lyon - Tous droits réservés.</p>
    </footer>

    <script>
        function switchTab(tabId) {
            // Remove active class from all tabs
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
                content.style.display = 'none';
            });

            // Add active class to selected tab
            if (tabId === 'play') {
                document.getElementById('tab-play-btn').classList.add('active');
                const tabPlay = document.getElementById('tab-play');
                tabPlay.classList.add('active');
                tabPlay.style.display = 'block';
            } else {
                document.getElementById('tab-leaderboard-btn').classList.add('active');
                const tabLeaderboard = document.getElementById('tab-leaderboard');
                tabLeaderboard.classList.add('active');
                tabLeaderboard.style.display = 'block';
            }

            // Save tab state in localStorage
            localStorage.setItem('kikanla_active_tab', tabId);
        }

        // Restore active tab state on reload
        document.addEventListener('DOMContentLoaded', () => {
            const activeTab = localStorage.getItem('kikanla_active_tab') || 'play';
            switchTab(activeTab);
        });
    </script>
</body>
</html>
