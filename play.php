<?php
/**
 * Touba Lyon 2026 - Ki Kan La Game (Mobile-Optimized Screen)
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

// Retrieve current player's data from database to keep score accurate
try {
    $stmt = $pdo->prepare("SELECT score, prenom, nom, civilite FROM membres WHERE id = ? AND status = 'approved'");
    $stmt->execute([$playerId]);
    $player = $stmt->fetch();
    
    if (!$player) {
        header('Location: play_logout.php');
        exit;
    }
    
    $playerScore = $player['score'];
    $playerName = $player['prenom'] . ' ' . $player['nom'];
    $_SESSION['player_score'] = $playerScore;
} catch (Exception $e) {
    error_log('Touba Lyon play (player): ' . $e->getMessage());
    http_response_code(500);
    die("Une erreur technique est survenue. Veuillez réessayer plus tard.");
}

$feedback = null;
$selectedChoice = null;

// Initialize Game Round session variables if not set
if (!isset($_SESSION['game_round'])) {
    $_SESSION['game_round'] = [
        'current_question' => 1,
        'correct_answers' => 0,
        'round_score' => 0,
        'asked_ids' => [],
        'completed' => false
    ];
    unset($_SESSION['game_question']);
}

// Handle Reset Game / Replay request
if (isset($_POST['reset_game'])) {
    $_SESSION['game_round'] = [
        'current_question' => 1,
        'correct_answers' => 0,
        'round_score' => 0,
        'asked_ids' => [],
        'completed' => false
    ];
    unset($_SESSION['game_question']);
}

// Handle Answer Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_answer'])) {
    if (isset($_SESSION['game_question']) && $_SESSION['game_round']['current_question'] <= 10) {
        $selectedChoice = (int)($_POST['choice_id'] ?? 0);
        $correctId = (int)$_SESSION['game_question']['correct_id'];
        $correctName = $_SESSION['game_question']['correct_name'];
        
        // Save correct member ID to asked list to avoid showing them again in current round
        if (!in_array($correctId, $_SESSION['game_round']['asked_ids'])) {
            $_SESSION['game_round']['asked_ids'][] = $correctId;
        }

        if ($selectedChoice === $correctId) {
            try {
                // Correct answer: Update database score (+10)
                $stmt = $pdo->prepare("UPDATE membres SET score = score + 10 WHERE id = ?");
                $stmt->execute([$playerId]);
                $playerScore += 10;
                $_SESSION['player_score'] = $playerScore;
                
                // Track round scores
                $_SESSION['game_round']['correct_answers']++;
                $_SESSION['game_round']['round_score'] += 10;
                
                $feedback = [
                    'correct' => true,
                    'message' => "Excellent ! C'est bien <strong>" . htmlspecialchars($correctName) . "</strong>.",
                    'points' => 10
                ];
            } catch (Exception $e) {
                $error = "Erreur lors de la mise à jour du score.";
            }
        } else {
            // Incorrect answer
            $feedback = [
                'correct' => false,
                'message' => "Oups ! Ce n'est pas correct. Il s'agissait de <strong>" . htmlspecialchars($correctName) . "</strong>."
            ];
        }
        
        // Save the submitted choice in question state for visual display
        $_SESSION['game_question']['submitted_id'] = $selectedChoice;
    }
}

// Handle "Next" question request
if (isset($_POST['next_question'])) {
    if ($_SESSION['game_round']['current_question'] < 10) {
        $_SESSION['game_round']['current_question']++;
        unset($_SESSION['game_question']);
    } else {
        $_SESSION['game_round']['completed'] = true;
    }
}

// Generate New Question if not set
$gameError = '';
if (!isset($_SESSION['game_question']) && (!isset($_SESSION['game_round']['completed']) || $_SESSION['game_round']['completed'] === false)) {
    try {
        // Count approved members with valid photos
        $stmtCount = $pdo->query("SELECT COUNT(*) FROM membres WHERE status = 'approved' AND photo_path IS NOT NULL AND photo_path != '' AND photo_path != 'default.jpg'");
        $totalMembers = $stmtCount->fetchColumn();
        
        if ($totalMembers < 3) {
            $gameError = "Le jeu requiert au moins 3 membres validés avec photo dans le Trombinoscope pour pouvoir jouer.";
        } else {
            // Get correct member
            $excludeSql = "";
            $params = [];
            if (!empty($_SESSION['game_round']['asked_ids'])) {
                $placeholders = implode(',', array_fill(0, count($_SESSION['game_round']['asked_ids']), '?'));
                $excludeSql = " AND id NOT IN ($placeholders)";
                $params = $_SESSION['game_round']['asked_ids'];
            }

            // Check if we still have fresh members left
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM membres WHERE status = 'approved' AND photo_path IS NOT NULL AND photo_path != '' AND photo_path != 'default.jpg'" . $excludeSql);
            $stmtCheck->execute($params);
            $remainingCount = $stmtCheck->fetchColumn();

            if ($remainingCount == 0) {
                $_SESSION['game_round']['asked_ids'] = [];
                $excludeSql = "";
                $params = [];
            }

            $stmtCorrect = $pdo->prepare("SELECT id, nom, prenom, civilite, photo_path FROM membres WHERE status = 'approved' AND photo_path IS NOT NULL AND photo_path != '' AND photo_path != 'default.jpg'" . $excludeSql . " ORDER BY RAND() LIMIT 1");
            $stmtCorrect->execute($params);
            $correctMember = $stmtCorrect->fetch();
            
            // Get two incorrect members with the same civility
            $stmtIncorrect = $pdo->prepare("SELECT id, nom, prenom, civilite FROM membres WHERE status = 'approved' AND id != ? AND civilite = ? ORDER BY RAND() LIMIT 2");
            $stmtIncorrect->execute([$correctMember['id'], $correctMember['civilite']]);
            $incorrectMembers = $stmtIncorrect->fetchAll();
            
            // Fallback if not enough members with the same civility exist in the database
            if (count($incorrectMembers) < 2) {
                // Collect already selected IDs (correct member + any incorrect members we did find)
                $selectedIds = [$correctMember['id']];
                foreach ($incorrectMembers as $m) {
                    $selectedIds[] = $m['id'];
                }
                
                $needed = 2 - count($incorrectMembers);
                $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                
                $stmtFallback = $pdo->prepare("SELECT id, nom, prenom, civilite FROM membres WHERE status = 'approved' AND id NOT IN ($placeholders) ORDER BY RAND() LIMIT " . (int)$needed);
                $stmtFallback->execute($selectedIds);
                $fallbackMembers = $stmtFallback->fetchAll();
                
                $incorrectMembers = array_merge($incorrectMembers, $fallbackMembers);
            }
            
            if (count($incorrectMembers) < 2) {
                $gameError = "Erreur : pas assez de membres pour générer des choix.";
            } else {
                $correctName = $correctMember['prenom'] . ' ' . $correctMember['nom'];
                $choices = [
                    ['id' => $correctMember['id'], 'name' => $correctName],
                    ['id' => $incorrectMembers[0]['id'], 'name' => $incorrectMembers[0]['prenom'] . ' ' . $incorrectMembers[0]['nom']],
                    ['id' => $incorrectMembers[1]['id'], 'name' => $incorrectMembers[1]['prenom'] . ' ' . $incorrectMembers[1]['nom']]
                ];
                
                shuffle($choices);
                
                $_SESSION['game_question'] = [
                    'correct_id' => $correctMember['id'],
                    'correct_name' => $correctName,
                    'photo' => $correctMember['photo_path'],
                    'choices' => $choices,
                    'submitted_id' => null
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Touba Lyon play (question): ' . $e->getMessage());
        $gameError = "Une erreur technique est survenue. Veuillez réessayer plus tard.";
    }
}

// Retrieve feedback if already submitted in this request step
if (isset($_SESSION['game_question']['submitted_id']) && $_SESSION['game_question']['submitted_id'] !== null) {
    $submittedId = $_SESSION['game_question']['submitted_id'];
    $correctId = $_SESSION['game_question']['correct_id'];
    $correctName = $_SESSION['game_question']['correct_name'];
    
    if ($submittedId === $correctId) {
        $feedback = [
            'correct' => true,
            'message' => "Excellent ! C'est bien <strong>" . htmlspecialchars($correctName) . "</strong>.",
            'points' => 10
        ];
    } else {
        $feedback = [
            'correct' => false,
            'message' => "Oups ! Ce n'est pas correct. Il s'agissait de <strong>" . htmlspecialchars($correctName) . "</strong>."
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Jeu Ki Kan La - Touba Lyon</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Mobile-first immersive gameplay styles */
        body {
            background-color: #081c15;
            min-height: 100vh;
            min-height: -webkit-fill-available;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow-x: hidden;
            margin: 0;
            padding: 0;
        }

        .game-console {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            flex-grow: 1;
            height: 100%;
        }

        /* Simplified Header */
        .console-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0.25rem;
            margin-bottom: 0.5rem;
        }

        .btn-back {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--white);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .btn-back:hover {
            color: var(--accent);
        }

        .score-display {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            padding: 0.4rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--gold);
        }

        /* Progress indicator */
        .progress-indicator-container {
            margin-bottom: 0.75rem;
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .progress-bar-track {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50px;
            overflow: hidden;
            border: 1px solid var(--glass-border);
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--gold), var(--primary-light));
            border-radius: 50px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Photo viewport */
        .photo-viewport {
            width: 100%;
            max-width: 220px;
            aspect-ratio: 1/1;
            margin: 0 auto 0.75rem;
            border-radius: 20px;
            overflow: hidden;
            border: 2.5px solid var(--glass-border);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.4);
            background: var(--secondary);
            position: relative;
        }

        .photo-viewport img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .game-prompt {
            text-align: center;
            font-size: 1.4rem;
            font-weight: 800;
            margin-bottom: 1rem;
            letter-spacing: -0.01em;
        }

        /* Choices list */
        .choice-stack {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            margin-bottom: 1rem;
        }

        .choice-btn {
            width: 100%;
            background: rgba(255, 255, 255, 0.04);
            border: 1.5px solid var(--glass-border);
            padding: 0.9rem 1.25rem;
            border-radius: 16px;
            color: var(--white);
            font-size: 1rem;
            font-weight: 600;
            text-align: left;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .choice-btn:hover:not([disabled]) {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--gold);
            transform: translateY(-1px);
        }

        .choice-btn:active:not([disabled]) {
            transform: translateY(1px);
        }

        .choice-btn.correct {
            background: rgba(45, 106, 79, 0.8) !important;
            border-color: #2d6a4f !important;
            box-shadow: 0 0 12px rgba(45, 106, 79, 0.4);
            animation: pulseGreen 1.5s infinite;
        }

        .choice-btn.incorrect {
            background: rgba(191, 33, 33, 0.25) !important;
            border-color: #bf2121 !important;
            text-decoration: line-through;
            opacity: 0.7;
            animation: buttonShake 0.4s ease-in-out;
        }

        /* Inline Feedback Card */
        .feedback-toast {
            border-radius: 16px;
            padding: 0.85rem 1.25rem;
            text-align: center;
            margin-top: 0.5rem;
            border: 1px solid transparent;
            font-weight: 500;
            font-size: 0.95rem;
            animation: bounceIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        .feedback-toast.correct {
            background: rgba(45, 106, 79, 0.2);
            border-color: rgba(45, 106, 79, 0.5);
            color: #b7e4c7;
            box-shadow: 0 4px 15px rgba(45, 106, 79, 0.15);
        }

        .feedback-toast.incorrect {
            background: rgba(191, 33, 33, 0.15);
            border-color: rgba(191, 33, 33, 0.4);
            color: #ffb3b3;
            box-shadow: 0 4px 15px rgba(191, 33, 33, 0.15);
        }

        .action-container {
            margin-top: 1rem;
        }

        .btn-next {
            width: 100%;
            padding: 0.95rem;
            font-size: 1.05rem;
            border-radius: 50px;
            font-weight: 700;
            text-align: center;
            background: var(--accent);
            color: var(--secondary);
            border: none;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.25);
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-next:hover {
            background: var(--white);
            color: var(--secondary);
            transform: scale(1.02);
        }

        /* Animation */
        @keyframes slideUp {
            from { transform: translateY(10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* --- Animations de résultat --- */

        /* Secousse pour erreur globale */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            15%, 45%, 75% { transform: translateX(-6px); }
            30%, 60%, 90% { transform: translateX(6px); }
        }
        .shake-anim {
            animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both;
        }

        /* Secousse pour bouton incorrect */
        @keyframes buttonShake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-4px); }
            40%, 80% { transform: translateX(4px); }
        }

        /* Flottement du score +10 */
        @keyframes scoreFloat {
            0% { transform: translateY(10px) scale(0.8); opacity: 0; }
            20% { opacity: 1; transform: translateY(0) scale(1.1); }
            80% { opacity: 1; }
            100% { transform: translateY(-30px) scale(1); opacity: 0; }
        }
        .score-pop {
            position: absolute;
            top: -25px;
            right: 0px;
            background: var(--gold);
            color: #081c15;
            padding: 0.2rem 0.6rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 800;
            box-shadow: 0 4px 10px rgba(212,175,55,0.4);
            animation: scoreFloat 1.6s ease-out forwards;
            z-index: 10;
        }

        /* Pulsation pour bonne réponse */
        @keyframes pulseGreen {
            0% { box-shadow: 0 0 0 0 rgba(45, 106, 79, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(45, 106, 79, 0); }
            100% { box-shadow: 0 0 0 0 rgba(45, 106, 79, 0); }
        }

        /* Rebond pour le toast de feedback */
        @keyframes bounceIn {
            0% { transform: scale(0.9); opacity: 0; }
            60% { transform: scale(1.03); opacity: 0.9; }
            100% { transform: scale(1); opacity: 1; }
        }

        /* --- Confettis CSS --- */
        .confetti-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            pointer-events: none;
            z-index: 9999;
        }

        .confetti-piece {
            position: absolute;
            background: #ffd700;
            top: -20px;
            opacity: 0;
            animation: fall 2.5s linear forwards;
        }

        @keyframes fall {
            0% { top: -20px; transform: translateX(0) rotate(0deg); opacity: 1; }
            100% { top: 100vh; transform: translateX(80px) rotate(360deg); opacity: 0; }
        }
    </style>
</head>
<body>

    <main class="game-console <?php echo ($feedback !== null && !$feedback['correct']) ? 'shake-anim' : ''; ?>">
        <?php if (!empty($gameError)): ?>
            <!-- Error panel -->
            <div class="console-header">
                <a href="index.php" class="btn-back">← Quitter</a>
            </div>
            <div class="form-card" style="text-align: center; margin-top: 3rem; padding: 2rem;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🎮</div>
                <h3 style="color: var(--gold); margin-bottom: 0.5rem;">Configuration requise</h3>
                <a href="index.php" class="btn btn-primary" style="margin-top: 1rem; margin-bottom: 1.5rem; display: inline-block; width: auto;">Retour au Trombinoscope</a>
                <p style="color: var(--text-muted); font-size: 0.95rem; line-height: 1.5;"><?php echo htmlspecialchars($gameError); ?></p>
            </div>
            
        <?php elseif (isset($_SESSION['game_round']['completed']) && $_SESSION['game_round']['completed'] === true): ?>
            <!-- Round Completed Summary Screen -->
            <div class="console-header">
                <a href="index.php" class="btn-back">← Quitter</a>
            </div>
            
            <div class="form-card" style="margin: auto 0; text-align: center; padding: 2rem 1.5rem;">
                <div style="font-size: 3.5rem; margin-bottom: 0.5rem; filter: drop-shadow(0 0 10px rgba(212,175,55,0.3));">🏆</div>
                <h2 class="gold-text" style="font-size: 1.8rem; font-weight: 800; margin-bottom: 0.5rem;">Partie Terminée !</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">Vous avez répondu aux 10 questions.</p>
                
                <form action="play.php" method="POST" style="margin-bottom: 2rem;">
                    <button type="submit" name="reset_game" class="btn btn-primary" style="width: 100%; padding: 0.95rem; font-size: 1.05rem; border-radius: 50px;">
                        ⚡ Rejouer une partie
                    </button>
                </form>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border); padding: 1rem; border-radius: 16px;">
                        <div style="color: var(--text-muted); font-size: 0.8rem; margin-bottom: 0.25rem;">Bonnes réponses</div>
                        <div style="color: var(--white); font-size: 1.5rem; font-weight: 800;"><?php echo (int)$_SESSION['game_round']['correct_answers']; ?> / 10</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border); padding: 1rem; border-radius: 16px;">
                        <div style="color: var(--text-muted); font-size: 0.8rem; margin-bottom: 0.25rem;">Score gagné</div>
                        <div style="color: var(--gold); font-size: 1.5rem; font-weight: 800;">+<?php echo (int)$_SESSION['game_round']['round_score']; ?> pts</div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Main Game Play View -->
            <div>
                <!-- Top Console Header -->
                <div class="console-header">
                    <a href="index.php" class="btn-back">← Quitter</a>
                    <div style="position: relative; display: inline-block;">
                        <span class="score-display">Score : <?php echo (int)$playerScore; ?> pts</span>
                        <?php if ($feedback !== null && $feedback['correct']): ?>
                            <span class="score-pop">+10 pts</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Linear Progress Bar -->
                <?php 
                $currQ = (int)$_SESSION['game_round']['current_question'];
                $progressPercent = ($currQ - 1) * 10;
                if ($feedback !== null) {
                    $progressPercent = $currQ * 10;
                }
                ?>
                <div class="progress-indicator-container">
                    <div class="progress-text">
                        <span>Question <?php echo $currQ; ?> sur 10</span>
                        <span><?php echo (int)($_SESSION['game_round']['round_score']); ?> pts de round</span>
                    </div>
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill" style="width: <?php echo $progressPercent; ?>%;"></div>
                    </div>
                </div>

                <!-- Profile Photo Display -->
                <div class="photo-viewport">
                    <img src="uploads/<?php echo htmlspecialchars($_SESSION['game_question']['photo']); ?>" alt="Ki Kan La ?">
                </div>

                <h2 class="game-prompt gold-text">Ki Kan La ?</h2>

                <!-- Feedback Alert Panel & Continue Button (placed after photo and prompt) -->
                <?php if ($feedback !== null): ?>
                    <div class="action-container" style="margin-top: 1rem; margin-bottom: 1rem;">
                        <form action="play.php" method="POST">
                            <button type="submit" name="next_question" class="btn-next">
                                <?php echo ($currQ === 10) ? 'Terminer la partie' : 'Continuer ➔'; ?>
                            </button>
                        </form>
                    </div>

                    <div class="feedback-toast <?php echo $feedback['correct'] ? 'correct' : 'incorrect'; ?>" style="margin-top: 0.5rem; margin-bottom: 1.25rem;">
                        <?php echo $feedback['message']; ?>
                    </div>
                <?php endif; ?>

                <!-- Choice Stack Form -->
                <form action="play.php" method="POST" id="choices-form">
                    <input type="hidden" name="choice_id" id="choice-id-input" value="">
                    <input type="hidden" name="submit_answer" value="1">
                    
                    <div class="choice-stack">
                        <?php foreach ($_SESSION['game_question']['choices'] as $choice): ?>
                            <?php 
                            $btnClass = '';
                            $isDisabled = '';
                            if ($feedback !== null) {
                                $isDisabled = 'disabled';
                                $correctId = (int)$_SESSION['game_question']['correct_id'];
                                $submittedId = (int)$_SESSION['game_question']['submitted_id'];
                                if ($choice['id'] === $correctId) {
                                    $btnClass = 'correct';
                                } elseif ($choice['id'] === $submittedId) {
                                    $btnClass = 'incorrect';
                                }
                            }
                            ?>
                            <button type="button" 
                                    class="choice-btn <?php echo $btnClass; ?>" 
                                    onclick="makeSelection(<?php echo $choice['id']; ?>)" 
                                    <?php echo $isDisabled; ?>>
                                <span><?php echo htmlspecialchars($choice['name']); ?></span>
                                <?php if ($btnClass === 'correct'): ?>
                                    <span>✔️</span>
                                <?php elseif ($btnClass === 'incorrect'): ?>
                                    <span>❌</span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </form>

                <!-- Initial prompt helper text at the bottom -->
                <?php if ($feedback === null): ?>
                    <div style="height: 50px; text-align: center; color: var(--text-muted); font-size: 0.85rem; font-weight: 500; display: flex; align-items: center; justify-content: center; margin-top: 1rem; margin-bottom: 1rem;">
                        Choisissez un nom ci-dessus pour répondre.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <script>
        function makeSelection(choiceId) {
            const input = document.getElementById('choice-id-input');
            const form = document.getElementById('choices-form');
            if (input && form) {
                input.value = choiceId;
                form.submit();
            }
        }

        <?php if ($feedback !== null && $feedback['correct']): ?>
        function createConfetti() {
            const container = document.createElement('div');
            container.className = 'confetti-container';
            document.body.appendChild(container);
            
            const colors = ['#2d6a4f', '#d4af37', '#ffffff', '#52b788', '#ffd700', '#ffb3b3'];
            for (let i = 0; i < 45; i++) {
                const piece = document.createElement('div');
                piece.className = 'confetti-piece';
                piece.style.left = Math.random() * 100 + 'vw';
                piece.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                piece.style.width = (Math.random() * 6 + 6) + 'px';
                piece.style.height = (Math.random() * 10 + 10) + 'px';
                piece.style.animationDelay = (Math.random() * 1.2) + 's';
                piece.style.animationDuration = (Math.random() * 2 + 1.5) + 's';
                piece.style.transform = `rotate(${Math.random() * 360}deg)`;
                container.appendChild(piece);
            }
            
            setTimeout(() => {
                container.remove();
            }, 3700);
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            createConfetti();
        });
        <?php endif; ?>
    </script>
</body>
</html>
