<?php
// Enhanced Quiz Student Interface with Immediate Answer Feedback
// Configuration
$gameStateFile = __DIR__ . '/quiz_state.json';
$questionsFile = __DIR__ . '/quiz_questions.json';
$playersFile = __DIR__ . '/quiz_players.json';
$stateVersionFile = __DIR__ . '/quiz_state_version.txt';
$messagesFile = __DIR__ . '/quiz_messages.json';
$notesFile = __DIR__ . '/quiz_notes.json';

// Enhanced file reading with same locking mechanism as admin
function safeJsonRead($filename) {
    $lockFile = $filename . '.lock';
    
    // Wait for any write operations to complete
    $lockWaitTime = 0;
    while (file_exists($lockFile) && $lockWaitTime < 2) {
        usleep(50000); // 0.05 second
        $lockWaitTime += 0.05;
    }
    
    if (!file_exists($filename)) {
        return null;
    }
    
    $content = file_get_contents($filename);
    return $content ? json_decode($content, true) : null;
}

function safeJsonWrite($filename, $data) {
    $tempFile = $filename . '.tmp.' . uniqid();
    $lockFile = $filename . '.lock';
    
    // Wait for lock to be available (max 5 seconds)
    $lockWaitTime = 0;
    while (file_exists($lockFile) && $lockWaitTime < 5) {
        usleep(100000); // 0.1 second
        $lockWaitTime += 0.1;
    }
    
    // Create lock
    file_put_contents($lockFile, getmypid());
    
    try {
        // Write to temp file first
        $result = file_put_contents($tempFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        if ($result !== false) {
            // Atomic move to final location
            rename($tempFile, $filename);
        }
        
        // Remove lock
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        
        return $result;
    } catch (Exception $e) {
        // Clean up on error
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        throw $e;
    }
}

// Initialize default questions if file doesn't exist
if (!file_exists($questionsFile)) {
    $defaultQuestions = [
        ["question" => "What is phishing?", "options" => ["Deceptive emails or sites that try to steal information like logins.", "Fishing for real tuna with enterprise-grade hooks."], "correct" => 0, "image" => "", "explanation" => "Phishing is a cybersecurity attack where criminals send fake emails, texts, or create fake websites that look legitimate to trick people into giving away sensitive information like passwords, credit card numbers, or personal details."],
        ["question" => "What is spear phishing?", "options" => ["Highly targeted phishing aimed at a specific person or role.", "Throwing a literal spear at the office Wi-Fi router."], "correct" => 0, "image" => "", "explanation" => "Unlike regular phishing that targets many people, spear phishing is a focused attack aimed at a specific individual or organization. Attackers research their target to make the fake message more convincing."],
        ["question" => "What is smishing?", "options" => ["Phishing by SMS/text messages, often with urgent links.", "Sending memes to cure all security issues."], "correct" => 0, "image" => "", "explanation" => "Smishing combines 'SMS' and 'phishing.' It's when criminals send text messages with malicious links or requests for personal information, often creating false urgency like 'Your bank account will be closed!'"],
        ["question" => "What is vishing?", "options" => ["Voice phishing by phone/VoIP to extract sensitive info.", "Singing loudly until the attacker gives up."], "correct" => 0, "image" => "", "explanation" => "Vishing is 'voice phishing' - fraudulent phone calls where criminals pretend to be from banks, tech support, or government agencies to trick people into revealing passwords, social security numbers, or other sensitive information."],
        ["question" => "What is social engineering?", "options" => ["Manipulating people to bypass procedures or reveal info.", "Designing social media avatars with perfect symmetry."], "correct" => 0, "image" => "", "explanation" => "Social engineering is psychological manipulation - tricking people into breaking normal security procedures or revealing confidential information by exploiting human psychology rather than technical vulnerabilities."],
        ["question" => "What is pretexting?", "options" => ["Using a believable story or fake identity to gain trust and data.", "Texting someone before texting them again, just in case."], "correct" => 0, "image" => "", "explanation" => "Pretexting involves creating a fabricated scenario (pretext) to engage a victim and gain their trust. For example, calling someone pretending to be from IT support to get their password."],
        ["question" => "What is credential stuffing?", "options" => ["Trying leaked username/password pairs on other sites to exploit reuse.", "Shoving printed passwords into a keyboard for safekeeping."], "correct" => 0, "image" => "", "explanation" => "When people reuse the same password on multiple sites, criminals take username/password combinations from data breaches and automatically try them on other websites to gain unauthorized access."],
        ["question" => "What is a data breach?", "options" => ["Unauthorized access or leak of confidential data.", "A beach where laptops go to relax after patches."], "correct" => 0, "image" => "", "explanation" => "A data breach occurs when sensitive, protected, or confidential data is copied, transmitted, viewed, stolen, or used by someone without authorization. This can affect personal information, financial data, or business secrets."],
        ["question" => "What is a passphrase?", "options" => ["A longer, memorable phrase used instead of a short complex password.", "A magic word that unlocks free pizza on Fridays."], "correct" => 0, "image" => "", "explanation" => "A passphrase is a longer password made of multiple words or a sentence that's easier to remember than random characters but still secure. For example: 'Coffee!Makes@Me#Happy2024' instead of 'K9\$mP3zX'."],
        ["question" => "What is multi-factor authentication (MFA)?", "options" => ["An extra login factor (e.g., app code, key) to protect accounts if passwords leak.", "Asking a colleague to say \"please\" twice before logging in."], "correct" => 0, "image" => "", "explanation" => "MFA adds extra security layers beyond just a password. Even if someone steals your password, they still need the second factor - like a code from your phone app, a text message, or a physical security key."]
    ];
    safeJsonWrite($questionsFile, $defaultQuestions);
}

// Initialize game state
if (!file_exists($gameStateFile)) {
    $initialState = [
        'gameStarted' => false,
        'currentQuestion' => 0,
        'phase' => 'waiting', // waiting, question_shown, options_shown, reveal
        'buzzers' => [],
        'answers' => [],
        'timestamp' => time()
    ];
    safeJsonWrite($gameStateFile, $initialState);
}

// Initialize players file
if (!file_exists($playersFile)) {
    safeJsonWrite($playersFile, []);
}

// Initialize state version if needed
if (!file_exists($stateVersionFile)) {
    file_put_contents($stateVersionFile, '1');
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure proper JSON response headers
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    
    if ($input['action'] === 'joinGame') {
        try {
            $players = safeJsonRead($playersFile) ?: [];
            $playerId = uniqid();
            
            // Check if nickname already exists
            $exists = false;
            foreach ($players as $player) {
                if ($player['nickname'] === $input['nickname']) {
                    $exists = true;
                    $playerId = $player['id'];
                    break;
                }
            }
            
            if (!$exists) {
                $players[] = [
                    'id' => $playerId,
                    'nickname' => $input['nickname'],
                    'joinedAt' => date('Y-m-d H:i:s'),
                    'active' => true
                ];
                safeJsonWrite($playersFile, $players);
            }
            
            echo json_encode([
                'success' => true, 
                'playerId' => $playerId,
                'stateVersion' => file_get_contents($stateVersionFile)
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to join game: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'getGameState') {
        try {
            $gameState = safeJsonRead($gameStateFile) ?: [];
            $questions = safeJsonRead($questionsFile) ?: [];
            $currentStateVersion = file_get_contents($stateVersionFile);
            
            // Get messages
            $messages = [];
            if (file_exists($messagesFile)) {
                $messages = safeJsonRead($messagesFile) ?: [];
            }
            
            // Enhanced response with player-specific data
            $currentQuestionData = null;
            if ($gameState['gameStarted'] && isset($questions[$gameState['currentQuestion']])) {
                $currentQuestionData = $questions[$gameState['currentQuestion']];
            }
            
            // Get player's answer history for end-of-quiz summary
            $playerAnswerHistory = [];
            if (isset($input['playerId'])) {
                foreach ($gameState['answers'] as $answer) {
                    if ($answer['playerId'] === $input['playerId']) {
                        $playerAnswerHistory[] = $answer;
                    }
                }
            }
            
            $response = [
                'success' => true,
                'gameState' => $gameState,
                'currentQuestionData' => $currentQuestionData,
                'questions' => $questions, // Send all questions for end-of-quiz analysis
                'totalQuestions' => count($questions),
                'stateVersion' => $currentStateVersion,
                'messages' => $messages,
                'playerAnswerHistory' => $playerAnswerHistory, // For end-of-quiz breakdown
                'serverTime' => time()
            ];
            
            echo json_encode($response);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to get game state: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'pressBuzzer') {
        try {
            $gameState = safeJsonRead($gameStateFile) ?: [];
            $players = safeJsonRead($playersFile) ?: [];
            $currentStateVersion = (int)file_get_contents($stateVersionFile);
            
            // Only process if in correct phase
            if ($gameState['phase'] === 'question_shown') {
                // Find player nickname
                $playerNickname = '';
                foreach ($players as $player) {
                    if ($player['id'] === $input['playerId']) {
                        $playerNickname = $player['nickname'];
                        break;
                    }
                }
                
                if ($playerNickname) {
                    // Check if player already buzzed for this question
                    $alreadyBuzzed = false;
                    foreach ($gameState['buzzers'] as $buzzer) {
                        if ($buzzer['playerId'] === $input['playerId'] && 
                            $buzzer['question'] === $gameState['currentQuestion']) {
                            $alreadyBuzzed = true;
                            break;
                        }
                    }
                    
                    if (!$alreadyBuzzed) {
                        $gameState['buzzers'][] = [
                            'playerId' => $input['playerId'],
                            'nickname' => $playerNickname,
                            'question' => $gameState['currentQuestion'],
                            'timestamp' => microtime(true)
                        ];
                        $gameState['timestamp'] = time();
                        
                        safeJsonWrite($gameStateFile, $gameState);
                        
                        // Increment state version
                        $newVersion = $currentStateVersion + 1;
                        file_put_contents($stateVersionFile, $newVersion);
                        
                        echo json_encode([
                            'success' => true,
                            'stateVersion' => $newVersion
                        ]);
                        exit;
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $currentStateVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to process buzzer: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'submitAnswer') {
        try {
            $gameState = safeJsonRead($gameStateFile) ?: [];
            $questions = safeJsonRead($questionsFile) ?: [];
            $currentStateVersion = (int)file_get_contents($stateVersionFile);
            
            if ($gameState['phase'] === 'options_shown') {
                // Remove any previous answer from this player for this question
                $gameState['answers'] = array_filter($gameState['answers'], function($answer) use ($input, $gameState) {
                    return !($answer['playerId'] === $input['playerId'] && 
                            $answer['question'] === $gameState['currentQuestion']);
                });
                
                // Get current question for immediate feedback
                $currentQuestion = $questions[$gameState['currentQuestion']] ?? null;
                $isCorrect = false;
                if ($currentQuestion) {
                    $isCorrect = ($input['answer'] === $currentQuestion['correct']);
                }
                
                // Add new answer with immediate feedback data
                $gameState['answers'][] = [
                    'playerId' => $input['playerId'],
                    'question' => $gameState['currentQuestion'],
                    'answer' => $input['answer'],
                    'isCorrect' => $isCorrect, // CRITICAL: Store correctness for immediate feedback
                    'timestamp' => microtime(true)
                ];
                
                $gameState['timestamp'] = time();
                safeJsonWrite($gameStateFile, $gameState);
                
                // Increment state version
                $newVersion = $currentStateVersion + 1;
                file_put_contents($stateVersionFile, $newVersion);
                
                echo json_encode([
                    'success' => true,
                    'stateVersion' => $newVersion,
                    'isCorrect' => $isCorrect, // CRITICAL: Send immediate feedback
                    'correctAnswer' => $currentQuestion ? $currentQuestion['options'][$currentQuestion['correct']] : null
                ]);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $currentStateVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to submit answer: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'getNotes') {
        try {
            $notes = [];
            if (file_exists($notesFile)) {
                $notes = safeJsonRead($notesFile) ?: [
                    'content' => '',
                    'updatedAt' => 0
                ];
            } else {
                $notes = [
                    'content' => '',
                    'updatedAt' => 0
                ];
            }
            
            echo json_encode([
                'success' => true,
                'notes' => $notes
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to get notes: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'getPlayerSummary') {
        try {
            $gameState = safeJsonRead($gameStateFile) ?: [];
            $questions = safeJsonRead($questionsFile) ?: [];
            $playerId = $input['playerId'];
            
            // Generate comprehensive player summary
            $playerAnswers = array_filter($gameState['answers'], function($answer) use ($playerId) {
                return $answer['playerId'] === $playerId;
            });
            
            $summary = [
                'totalQuestions' => count($questions),
                'answeredQuestions' => count($playerAnswers),
                'correctAnswers' => 0,
                'incorrectAnswers' => 0,
                'unansweredQuestions' => [],
                'questionBreakdown' => []
            ];
            
            // Build question breakdown
            foreach ($questions as $index => $question) {
                $playerAnswer = null;
                foreach ($playerAnswers as $answer) {
                    if ($answer['question'] === $index) {
                        $playerAnswer = $answer;
                        break;
                    }
                }
                
                if ($playerAnswer) {
                    $isCorrect = ($playerAnswer['answer'] === $question['correct']);
                    if ($isCorrect) {
                        $summary['correctAnswers']++;
                    } else {
                        $summary['incorrectAnswers']++;
                    }
                    
                    $summary['questionBreakdown'][] = [
                        'questionIndex' => $index,
                        'question' => $question['question'],
                        'playerAnswer' => $question['options'][$playerAnswer['answer']],
                        'correctAnswer' => $question['options'][$question['correct']],
                        'isCorrect' => $isCorrect,
                        'explanation' => $question['explanation'] ?? ''
                    ];
                } else {
                    $summary['unansweredQuestions'][] = $index;
                    $summary['questionBreakdown'][] = [
                        'questionIndex' => $index,
                        'question' => $question['question'],
                        'playerAnswer' => 'Not answered',
                        'correctAnswer' => $question['options'][$question['correct']],
                        'isCorrect' => false,
                        'explanation' => $question['explanation'] ?? ''
                    ];
                }
            }
            
            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'allQuestions' => $questions // For PDF generation
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to get player summary: ' . $e->getMessage()]);
        }
        exit;
    }
}

$gameState = safeJsonRead($gameStateFile) ?: [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interactive Quiz</title>
    <style>
        :root {
            --bg-dark: #111827;
            --bg-gray-800: #1f2937;
            --bg-gray-700: #374151;
            --bg-gray-900: #0f172a;
            --text-white: #ffffff;
            --text-gray-300: #d1d5db;
            --text-gray-400: #9ca3af;
            --cyan-400: #22d3ee;
            --cyan-500: #06b6d4;
            --cyan-600: #0891b2;
            --cyan-700: #0e7490;
            --green-500: #10b981;
            --green-600: #059669;
            --red-500: #ef4444;
            --red-600: #dc2626;
            --yellow-500: #f59e0b;
            --purple-500: #8b5cf6;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-white);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        .screen {
            background-color: var(--bg-gray-800);
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-screen {
            text-align: center;
            max-width: 400px;
            margin: 0 auto;
        }

        h1 {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
            color: var(--cyan-400);
        }

        .subtitle {
            color: var(--text-gray-400);
            margin-bottom: 1.5rem;
        }

        input[type="text"] {
            width: 100%;
            padding: 0.75rem;
            border-radius: 0.5rem;
            background-color: var(--bg-gray-700);
            border: 1px solid #4b5563;
            color: var(--text-white);
            font-size: 1rem;
            margin-bottom: 1rem;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: var(--cyan-500);
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
        }

        .btn {
            padding: 0.75rem 1rem;
            border: none;
            border-radius: 0.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1rem;
        }

        .btn-primary {
            background-color: var(--cyan-600);
            color: var(--text-white);
            width: 100%;
        }

        .btn-primary:hover {
            background-color: var(--cyan-700);
        }

        .btn-success {
            background-color: var(--green-600);
            color: var(--text-white);
        }

        .btn-success:hover {
            background-color: var(--green-500);
        }

        .buzzer-btn {
            background-color: var(--red-500);
            color: var(--text-white);
            font-size: 1.5rem;
            padding: 2rem;
            border-radius: 50%;
            width: 150px;
            height: 150px;
            margin: 2rem auto;
            display: block;
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.3);
            position: relative;
            overflow: hidden;
        }

        .buzzer-btn:hover {
            background-color: var(--red-600);
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(239, 68, 68, 0.4);
        }

        .buzzer-btn:active {
            transform: scale(0.95);
        }

        .buzzer-btn:disabled {
            background-color: var(--bg-gray-700);
            color: var(--text-gray-400);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .buzzer-btn::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .buzzer-btn.pressed::after {
            opacity: 1;
        }

        .question-display {
            text-align: center;
            margin-bottom: 2rem;
        }

        .question-number {
            color: var(--text-gray-400);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
        }

        .question-text {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-white);
            margin-bottom: 1rem;
        }

        .question-image {
            max-width: 100%;
            max-height: 200px;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }

        .options-grid {
            display: grid;
            gap: 1rem;
            margin-top: 2rem;
        }

        .option-btn {
            background-color: var(--bg-gray-700);
            border: 2px solid var(--bg-gray-700);
            padding: 1rem;
            border-radius: 0.5rem;
            color: var(--text-white);
            text-align: left;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1rem;
            position: relative;
            overflow: hidden;
        }

        .option-btn:hover:not(:disabled) {
            border-color: var(--cyan-500);
            background-color: var(--bg-gray-800);
        }

        .option-btn.selected {
            border-color: var(--cyan-400);
            background-color: rgba(6, 182, 212, 0.1);
        }

        /* CRITICAL: Enhanced answer feedback states */
        .option-btn.correct {
            border-color: var(--green-500);
            background-color: rgba(16, 185, 129, 0.2);
            color: var(--text-white);
        }

        .option-btn.incorrect {
            border-color: var(--red-500);
            background-color: rgba(239, 68, 68, 0.2);
            color: var(--text-white);
        }

        .option-btn.correct-answer {
            border-color: var(--green-500);
            background-color: rgba(16, 185, 129, 0.3);
            animation: correctPulse 0.6s ease-in-out;
        }

        .option-btn:disabled {
            cursor: not-allowed;
            opacity: 0.8;
        }

        @keyframes correctPulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            50% { transform: scale(1.02); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0.1); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .status-message {
            text-align: center;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }

        .status-waiting {
            background-color: var(--bg-gray-900);
            color: var(--text-gray-300);
        }

        .status-buzzed {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--green-500);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-answered {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--green-500);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-revealed {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--green-500);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        /* Enhanced feedback states */
        .status-correct-answer {
            background-color: rgba(16, 185, 129, 0.2);
            color: var(--green-500);
            border: 2px solid var(--green-500);
            animation: correctFeedback 0.5s ease-in-out;
        }

        .status-incorrect-answer {
            background-color: rgba(239, 68, 68, 0.2);
            color: var(--red-500);
            border: 2px solid var(--red-500);
            animation: incorrectFeedback 0.5s ease-in-out;
        }

        @keyframes correctFeedback {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        @keyframes incorrectFeedback {
            0% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
            100% { transform: translateX(0); }
        }

        .waiting-screen {
            text-align: center;
        }

        .spinner {
            border: 3px solid var(--bg-gray-700);
            border-top: 3px solid var(--cyan-500);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 1rem auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .hidden {
            display: none;
        }

        .admin-link {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            background-color: var(--bg-gray-700);
            color: var(--text-gray-400);
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            text-decoration: none;
            font-size: 0.75rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .admin-link:hover {
            background-color: var(--bg-gray-800);
            color: var(--text-white);
        }

        /* Help system */
        .help-toggle {
            position: fixed;
            bottom: 1rem;
            left: 1rem;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--purple-500);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 100;
            transition: var(--transition);
            font-weight: bold;
            font-size: 1.2rem;
        }

        .help-panel {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 300px;
            height: 80vh;
            background-color: var(--bg-gray-800);
            border-radius: 1rem 1rem 0 0;
            box-shadow: 0 -5px 25px rgba(0,0,0,0.2);
            padding: 1.5rem;
            transform: translateY(100%);
            transition: transform 0.4s ease;
            z-index: 99;
            overflow-y: auto;
            pointer-events: none;
        }

        .help-panel.active {
            transform: translateY(0);
            pointer-events: auto;
        }

        .help-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--bg-gray-700);
        }

        .help-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--cyan-400);
        }

        .help-close {
            background: none;
            border: none;
            color: var(--text-gray-400);
            font-size: 1.5rem;
            cursor: pointer;
        }

        .help-content {
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .help-section {
            margin-bottom: 1.5rem;
        }

        .help-section h3 {
            color: var(--cyan-400);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .help-section p {
            color: var(--text-gray-300);
            margin-bottom: 0.5rem;
        }

        .help-tip {
            background-color: rgba(139, 92, 246, 0.1);
            border-left: 3px solid var(--purple-500);
            padding: 0.75rem;
            border-radius: 0 0.25rem 0.25rem 0;
            margin: 0.5rem 0;
        }

        /* Network status */
        .network-status {
            position: fixed;
            top: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            z-index: 100;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .network-status.active {
            opacity: 1;
            transform: translateY(0);
        }

        .network-status.online {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--green-500);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .network-status.offline {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--red-500);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* Progress bar */
        .progress-container {
            width: 100%;
            height: 6px;
            background-color: var(--bg-gray-700);
            border-radius: 3px;
            margin: 1rem 0;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background-color: var(--cyan-500);
            width: 0%;
            transition: width 0.3s ease;
        }

        /* Message system */
        .message-system {
            position: fixed;
            bottom: 60px;
            right: 1rem;
            width: 300px;
            z-index: 99;
        }

        .message {
            background-color: var(--bg-gray-800);
            color: var(--text-white);
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            animation: slideIn 0.3s ease, fadeOut 0.3s ease 5s forwards;
            opacity: 1;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            to { opacity: 0; }
        }

        .message.info {
            border-left: 4px solid var(--cyan-500);
        }

        .message.warning {
            border-left: 4px solid var(--yellow-500);
        }

        .message.success {
            border-left: 4px solid var(--green-500);
        }

        /* Phase indicators */
        .phase-indicator {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .phase-question {
            background-color: rgba(139, 92, 246, 0.1);
            color: var(--purple-500);
        }

        .phase-options {
            background-color: rgba(6, 182, 212, 0.1);
            color: var(--cyan-500);
        }

        .phase-reveal {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--green-500);
        }

        /* Animation classes */
        .fade-in {
            animation: fadeIn 0.3s ease;
        }

        .slide-in {
            animation: slideIn 0.3s ease;
        }

        /* Student notes panel */
        .student-notes-panel {
            position: fixed;
            top: 1rem;
            right: 1rem;
            width: 350px;
            max-height: 80vh;
            z-index: 100;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            display: none;
        }

        .student-notes-content {
            padding: 1rem;
            background-color: var(--bg-gray-800);
            border-radius: 0 0 0.5rem 0.5rem;
            max-height: calc(80vh - 60px);
            overflow-y: auto;
        }

        /* Notes button */
        .notes-button {
            position: fixed;
            bottom: 1rem;
            left: 60px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
            background-color: var(--purple-500);
            color: white;
            border: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .notes-button:hover {
            background-color: var(--purple-600);
            transform: translateY(-2px);
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .container {
                padding: 0.5rem;
            }
            
            .screen {
                padding: 1.5rem;
            }
            
            .question-text {
                font-size: 1.25rem;
            }
            
            .buzzer-btn {
                width: 120px;
                height: 120px;
                font-size: 1.25rem;
            }
            
            .student-notes-panel {
                width: 90%;
                right: 5%;
            }
            
            .help-panel {
                width: 90%;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <!-- Login Screen -->
        <div id="loginScreen" class="screen login-screen">
            <h1>Interactive Quiz</h1>
            <p class="subtitle">Enter your name to join the quiz</p>
            
            <form id="loginForm">
                <input type="text" id="nicknameInput" placeholder="Enter your name..." required>
                <button type="submit" class="btn btn-primary">Join Quiz</button>
            </form>
        </div>

        <!-- Waiting Screen -->
        <div id="waitingScreen" class="screen waiting-screen hidden">
            <h1>Waiting for Quiz to Start</h1>
            <div class="spinner"></div>
            <p class="subtitle">The teacher will start the quiz soon...</p>
            
            <div class="progress-container">
                <div class="progress-bar" id="quizProgress" style="width: 0%"></div>
            </div>
        </div>

        <!-- Quiz Screen -->
        <div id="quizScreen" class="screen hidden">
            <div class="question-display">
                <div class="question-number" id="questionNumber">
                    <i class="fas fa-question-circle"></i> Question 1 of 10
                </div>
                <div class="question-text" id="questionText">Question will appear here</div>
                <img id="questionImage" class="question-image hidden" src="" alt="">
            </div>

            <div id="statusMessage" class="status-message status-waiting">
                Waiting for question...
            </div>

            <!-- Buzzer Phase -->
            <div id="buzzerPhase" class="hidden">
                <button id="buzzerBtn" class="buzzer-btn">
                    BUZZ!
                </button>
                <p style="color: var(--text-gray-400); text-align: center;">Be the first to buzz in when you know the answer!</p>
            </div>

            <!-- Options Phase -->
            <div id="optionsPhase" class="hidden">
                <div class="options-grid" id="optionsGrid">
                    <!-- Options will be populated here -->
                </div>
            </div>
            
            <!-- Reveal Phase -->
            <div id="revealPhase" class="hidden">
                <div style="background-color: rgba(16, 185, 129, 0.1); padding: 1rem; border-radius: 0.5rem; margin-top: 1rem;">
                    <h4 style="color: var(--green-500); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-check-circle"></i> Correct Answer
                    </h4>
                    <p id="correctAnswerText" style="font-size: 1.2rem; font-weight: 600;"></p>
                </div>
                
                <div id="explanationSection" class="hidden" style="margin-top: 1rem;">
                    <h4 style="color: var(--cyan-400); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-book"></i> Explanation
                    </h4>
                    <p id="questionExplanation" style="color: var(--text-gray-300); line-height: 1.5;"></p>
                </div>
            </div>
        </div>

        <!-- Quiz End Screen -->
        <div id="endScreen" class="screen hidden">
            <div style="text-align: center;">
                <h1 style="color: #10b981; margin-bottom: 1rem;">
                    <i class="fas fa-trophy"></i> Quiz Complete!
                </h1>
                <p style="color: #d1d5db; margin-bottom: 2rem;">
                    Congratulations! You have completed the cybersecurity quiz.
                </p>
                
                <div id="performanceSummary" style="background-color: #0f172a; padding: 2rem; border-radius: 0.5rem; margin-bottom: 2rem;">
                    <h3 style="color: #22d3ee; margin-bottom: 1rem;">
                        <i class="fas fa-chart-bar"></i> Your Performance
                    </h3>
                    <div id="performanceStats" style="text-align: left;">
                        <!-- Performance stats will be populated here -->
                    </div>
                </div>
                
                <div id="detailedBreakdown" style="background-color: #0f172a; padding: 2rem; border-radius: 0.5rem; margin-bottom: 2rem; text-align: left;">
                    <h3 style="color: #22d3ee; margin-bottom: 1rem;">
                        <i class="fas fa-list-alt"></i> Question-by-Question Breakdown
                    </h3>
                    <div id="questionBreakdown">
                        <!-- Detailed breakdown will be populated here -->
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                    <button id="downloadVocabularyBtn" class="btn btn-success">
                        <i class="fas fa-download"></i> Download Complete Vocabulary (PDF)
                    </button>
                    <button id="downloadNotesBtn" class="btn btn-primary">
                        <i class="fas fa-sticky-note"></i> Download Class Notes (PDF)
                    </button>
                    <button id="newQuizBtn" class="btn" style="background-color: #374151; color: #9ca3af;">
                        <i class="fas fa-redo"></i> Wait for New Quiz
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Help System -->
    <div class="help-toggle" id="helpToggle">
        <i class="fas fa-question"></i>
    </div>
    
    <div class="help-panel" id="helpPanel">
        <div class="help-header">
            <div class="help-title">Quiz Help</div>
            <button class="help-close" id="helpClose">&times;</button>
        </div>
        
        <div class="help-content">
            <div class="help-section">
                <h3><i class="fas fa-gamepad"></i> How to Play</h3>
                <p><strong>1. When you see a question:</strong> If you know the answer, press the BUZZ button as fast as you can!</p>
                <p><strong>2. If you buzz first:</strong> The teacher will call on you to answer</p>
                <p><strong>3. When options appear:</strong> Select your answer - you'll get immediate feedback!</p>
                
                <div class="help-tip">
                    <strong>Debug Mode:</strong> Press Ctrl+Shift+D to enable debug logging. Press Ctrl+Shift+R to manually reset all state if you notice selection issues.
                </div>
            </div>
            
            <div class="help-section">
                <h3><i class="fas fa-bug"></i> Troubleshooting</h3>
                <p>If you see options already selected when they shouldn't be:</p>
                <p>1. Try pressing Ctrl+Shift+R to manually reset</p>
                <p>2. Refresh your browser</p>
                <p>3. Ask the teacher to reset the quiz</p>
                
                <div class="help-tip">
                    <strong>New Feature:</strong> You now get instant feedback when you select an answer - green for correct, red for incorrect!
                </div>
            </div>
            
            <div class="help-section">
                <h3><i class="fas fa-wifi"></i> Connection Issues</h3>
                <p>If the quiz doesn't update:</p>
                <p>- Wait a moment (it usually syncs automatically)</p>
                <p>- If it still doesn't work, try refreshing the page</p>
                <p>- Make sure you have a stable internet connection</p>
                
                <div class="help-tip">
                    <strong>Reconnection:</strong> If you lose connection and rejoin, you'll automatically catch up to where everyone else is!
                </div>
            </div>
            
            <div class="help-section">
                <h3><i class="fas fa-question-circle"></i> Question Types</h3>
                <p>This quiz focuses on cybersecurity concepts:</p>
                <p>- Phishing, Smishing, Vishing</p>
                <p>- Multi-factor authentication</p>
                <p>- Social engineering attacks</p>
                
                <div class="help-tip">
                    <strong>Remember:</strong> These concepts help protect your online accounts and information!
                </div>
            </div>
        </div>
    </div>

    <!-- Network Status -->
    <div class="network-status" id="networkStatus">
        <i class="fas fa-circle-notch fa-spin"></i> Syncing with server...
    </div>
    
    <!-- Message System -->
    <div class="message-system" id="messageSystem"></div>

    <!-- Student Notes Panel -->
    <div id="studentNotesPanel" class="student-notes-panel">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background-color: var(--bg-gray-900); border-radius: 0.5rem 0.5rem 0 0;">
            <h3 style="color: var(--cyan-400); display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-sticky-note"></i> Class Notes
            </h3>
            <button id="closeStudentNotes" style="background: none; border: none; color: var(--text-gray-400); font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>
        
        <div id="studentNotesContent" class="student-notes-content"></div>
    </div>

    <!-- Notes button -->
    <button id="notesButton" class="notes-button">
        <i class="fas fa-sticky-note"></i>
    </button>

    <script>
        // Enhanced Student Quiz Interface with Immediate Feedback
        let playerId = null;
        let currentGameState = null;
        let currentStateVersion = 0;
        let lastSuccessfulFetch = null;
        let reconnectAttempts = 0;
        let maxReconnectAttempts = 5;
        let reconnectTimeout = null;
        let pollInterval = null;
        let selectedAnswer = null;
        let lastBuzzerPressTime = 0;
        let messageIds = new Set();
        let buzzSound = null;
        let correctSound = null;
        let incorrectSound = null;
        let hasAnswered = false; // Track if current question has been answered
        let playerAnswerHistory = []; // Store all player answers for end summary

        // DOM elements
        const loginScreen = document.getElementById('loginScreen');
        const waitingScreen = document.getElementById('waitingScreen');
        const quizScreen = document.getElementById('quizScreen');
        const endScreen = document.getElementById('endScreen');
        const loginForm = document.getElementById('loginForm');
        const nicknameInput = document.getElementById('nicknameInput');

        // Quiz elements
        const questionNumber = document.getElementById('questionNumber');
        const questionText = document.getElementById('questionText');
        const questionImage = document.getElementById('questionImage');
        const statusMessage = document.getElementById('statusMessage');
        const buzzerPhase = document.getElementById('buzzerPhase');
        const optionsPhase = document.getElementById('optionsPhase');
        const revealPhase = document.getElementById('revealPhase');
        const buzzerBtn = document.getElementById('buzzerBtn');
        const optionsGrid = document.getElementById('optionsGrid');
        const correctAnswerText = document.getElementById('correctAnswerText');
        const questionExplanation = document.getElementById('questionExplanation');
        const explanationSection = document.getElementById('explanationSection');

        // Help system
        const helpToggle = document.getElementById('helpToggle');
        const helpPanel = document.getElementById('helpPanel');
        const helpClose = document.getElementById('helpClose');
        const networkStatus = document.getElementById('networkStatus');
        const messageSystem = document.getElementById('messageSystem');

        // Student notes functionality
        const studentNotesPanel = document.getElementById('studentNotesPanel');
        const studentNotesContent = document.getElementById('studentNotesContent');
        const notesButton = document.getElementById('notesButton');
        const closeStudentNotes = document.getElementById('closeStudentNotes');

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // NUCLEAR RESET on page load to clear any persistent state
            setTimeout(nuclearStateReset, 100); // Slight delay to ensure DOM is ready
            
            initializeEventListeners();
            initializeAudio();
            fetchStudentNotes();
            setInterval(fetchStudentNotes, 30000); // Auto-refresh notes
        });

        function initializeEventListeners() {
            // Help system
            helpToggle.addEventListener('click', () => helpPanel.classList.add('active'));
            helpClose.addEventListener('click', () => helpPanel.classList.remove('active'));
            
            // Close help panel when clicking outside
            document.addEventListener('click', (e) => {
                if (!helpPanel.contains(e.target) && 
                    !helpToggle.contains(e.target) && 
                    helpPanel.classList.contains('active')) {
                    helpPanel.classList.remove('active');
                }
            });
            
            // Notes event listeners
            notesButton.addEventListener('click', () => {
                studentNotesPanel.style.display = studentNotesPanel.style.display === 'block' ? 'none' : 'block';
                if (studentNotesPanel.style.display === 'block') {
                    fetchStudentNotes();
                }
            });
            
            closeStudentNotes.addEventListener('click', () => {
                studentNotesPanel.style.display = 'none';
            });

            // Game event listeners
            loginForm.addEventListener('submit', handleLogin);
            buzzerBtn.addEventListener('click', pressBuzzer);
        }

        function initializeAudio() {
            // Preload audio with base64 data
            try {
                buzzSound = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBzuR1Oy1bxIHKHfH6tN9IwUZZK7n3nAZBycFXqLYzmsU');
                correctSound = new Audio('data:audio/wav;base64,UklGRiQDAABXQVZFZm10IBAAAAABAAEAiBUAAIgnAAAQAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAD///////////////9mZWxwAAAAAAAAAAAAAAAAAAAAAAAAAAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISIjJCUmJygpKissLS4vMDEyMzQ1Njc4OTo7PD0+P0BBQkNERUZHSElKS0xNTk9QUVJTVFVWV1hZWltcXV5fYGFiY2RlZmdoaWprbG1ub3BxcnN0dXZ3eHl6e3x9fn+AgYKDhIWGh4iJiouMjY6PkJGSk5SVlpeYmZqbnJ2en6ChoqOkpaanqKmqq6ytrq+wsbKztLW2t7i5uru8vb6/wMHCw8TFxsfIycrLzM3Oz9DR0tPU1dbX2Nna29zd3t/g4eLj5OXm5+jp6uvs7e7v8PHy8/T19vf4+fr7/P3+/w==');
                incorrectSound = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBzuR1Oy1bxIHKHfH6tN9IwUZZK7n3nAZBycFXqLYzmsU');
                
                // Set volumes
                [buzzSound, correctSound, incorrectSound].forEach(audio => {
                    if (audio) audio.volume = 0.3;
                });
            } catch (e) {
                console.log('Audio initialization failed:', e);
            }
        }

        async function handleLogin(e) {
            e.preventDefault();
            const nickname = nicknameInput.value.trim();
            
            if (!nickname) return;

            if (nickname.toLowerCase() === 'admin') {
                window.location.href = 'admin.php';
                return;
            }

            try {
                // NUCLEAR RESET before joining to ensure clean state
                nuclearStateReset();
                
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'joinGame', 
                        nickname: nickname 
                    })
                });

                const result = await response.json();
                
                if (result.success) {
                    playerId = result.playerId;
                    currentStateVersion = 0; // Force first poll to update UI
                    
                    // ADDITIONAL RESET after joining
                    nuclearStateReset();
                    
                    showScreen('waiting');
                    startPolling();
                } else {
                    addMessage('error', 'Failed to join game: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error joining game:', error);
                addMessage('error', 'Error joining game. Please try again.');
            }
        }

        function startPolling() {
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            
            pollInterval = setInterval(updateGameState, 1500);
            updateGameState(); // Initial call
        }

        async function updateGameState() {
            try {
                const cacheBuster = new Date().getTime();
                
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ 
                        action: 'getGameState',
                        cacheBuster: cacheBuster,
                        currentStateVersion: currentStateVersion,
                        playerId: playerId
                    }),
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error(`Network error: ${response.status}`);
                }

                const result = await response.json();
                
                if (result.success) {
                    const newStateVersion = parseInt(result.stateVersion);
                    
                    // Always update UI on first poll after joining or if state changed
                    const shouldUpdate = (currentStateVersion === 0 && newStateVersion > 0) || 
                                         (currentStateVersion !== newStateVersion);
                    
                    if (shouldUpdate) {
                        currentStateVersion = newStateVersion;
                        currentGameState = result.gameState;
                        
                        // Store player answer history for end summary
                        if (result.playerAnswerHistory) {
                            playerAnswerHistory = result.playerAnswerHistory;
                        }
                        
                        // Process messages
                        processMessages(result.messages);
                        
                        // Update UI
                        updateUI(result);
                        
                        // Reset reconnect attempts
                        reconnectAttempts = 0;
                        
                        // Update network status if needed
                        if (networkStatus.classList.contains('offline')) {
                            setNetworkStatus('online', 'Connected to server');
                        }
                    }
                } else {
                    console.error('Server reported error:', result.error);
                }
            } catch (error) {
                console.error('Error updating game state:', error);
                
                // Only show error if we weren't already having issues
                if (!networkStatus.classList.contains('offline')) {
                    setNetworkStatus('offline', 'Connection lost. Trying to reconnect...');
                }
                
                reconnectAttempts++;
                
                // Try to reconnect with exponential backoff
                if (reconnectAttempts <= maxReconnectAttempts) {
                    const delay = Math.min(1000 * Math.pow(2, reconnectAttempts), 10000);
                    clearTimeout(reconnectTimeout);
                    reconnectTimeout = setTimeout(updateGameState, delay);
                } else {
                    setNetworkStatus('offline', `Cannot connect after ${maxReconnectAttempts} attempts. Please refresh.`);
                }
            }
        }

        function processMessages(messages) {
            if (!messages || !Array.isArray(messages)) return;
            
            messages.forEach(message => {
                if (!messageIds.has(message.id)) {
                    messageIds.add(message.id);
                    addMessage(message.type, message.text);
                }
            });
        }

        // Debug mode for persistent state issues
        let debugMode = false;
        
        // Enable debug mode with Ctrl+Shift+D
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.shiftKey && e.code === 'KeyD') {
                debugMode = !debugMode;
                console.log('Debug mode:', debugMode ? 'ENABLED' : 'DISABLED');
                if (debugMode) {
                    console.log('Current state:', {
                        selectedAnswer,
                        hasAnswered,
                        playerId,
                        currentStateVersion
                    });
                }
            }
        });

        // NUCLEAR RESET: Completely clear all persistent state
        function nuclearStateReset() {
            if (debugMode) console.log('🚨 NUCLEAR RESET STARTING...');
            
            // Visual indicator for nuclear reset
            document.body.style.transition = 'filter 0.1s ease';
            document.body.style.filter = 'brightness(1.5)';
            setTimeout(() => {
                document.body.style.filter = 'brightness(1)';
                setTimeout(() => {
                    document.body.style.transition = '';
                }, 100);
            }, 100);
            
            // 1. Clear all JavaScript variables
            const prevSelectedAnswer = selectedAnswer;
            const prevHasAnswered = hasAnswered;
            
            selectedAnswer = null;
            hasAnswered = false;
            currentGameState = null;
            playerAnswerHistory = [];
            
            if (debugMode) console.log('Variables cleared:', { prevSelectedAnswer, prevHasAnswered });
            
            // 2. Clear all DOM elements and their classes
            const allElements = document.querySelectorAll('*');
            allElements.forEach(element => {
                // Remove any option-related classes
                element.classList.remove('selected', 'correct', 'incorrect', 'correct-answer');
                // Clear any data attributes that might persist state
                delete element.dataset.optionIndex;
                delete element.dataset.selected;
                delete element.dataset.answered;
            });
            
            // 3. Completely rebuild options container
            if (optionsGrid) {
                optionsGrid.innerHTML = '';
                optionsGrid.className = 'options-grid';
                optionsGrid.removeAttribute('data-question');
                optionsGrid.removeAttribute('data-answered');
            }
            
            // 4. Clear any form-related autocomplete data
            const allInputs = document.querySelectorAll('input, button, select');
            allInputs.forEach(input => {
                input.removeAttribute('data-answered');
                input.removeAttribute('data-selected');
                if (input.form) {
                    input.form.reset();
                }
            });
            
            // 5. Force browser to forget any cached selections
            if (document.activeElement && document.activeElement.blur) {
                document.activeElement.blur();
            }
            
            // 6. Clear sessionStorage of anything that might persist (except playerId)
            const playerId = sessionStorage.getItem('quizPlayerId');
            sessionStorage.clear();
            if (playerId) {
                sessionStorage.setItem('quizPlayerId', playerId);
            }
            
            console.log('Nuclear state reset completed');
            
            if (debugMode) {
                console.log('🚀 NUCLEAR RESET COMPLETED');
                console.log('Final state:', {
                    selectedAnswer,
                    hasAnswered,
                    currentGameState,
                    optionButtonsCount: document.querySelectorAll('.option-btn').length,
                    anySelectedClasses: document.querySelectorAll('.selected, .correct, .incorrect').length
                });
            }
        }
        
        // Manual nuclear reset for debugging (Ctrl+Shift+R)
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.shiftKey && e.code === 'KeyR') {
                e.preventDefault();
                console.log('🔥 MANUAL NUCLEAR RESET TRIGGERED');
                nuclearStateReset();
                addMessage('info', 'Manual state reset performed');
            }
        });

        function updateUI(gameData) {
            const { gameState, currentQuestionData, totalQuestions, messages } = gameData;
            
            // ENHANCED: Detect ANY state change that requires reset
            const questionChanged = currentGameState && gameState.currentQuestion !== currentGameState.currentQuestion;
            const enteringOptionsPhase = gameState.phase === 'options_shown' && 
                (!currentGameState || currentGameState.phase !== 'options_shown');
            const gameRestarted = (!currentGameState && gameState.gameStarted) || 
                (currentGameState && !currentGameState.gameStarted && gameState.gameStarted);
            
            // NUCLEAR OPTION: Reset everything on ANY significant state change
            if (questionChanged || enteringOptionsPhase || gameRestarted) {
                nuclearStateReset();
            }
            
            currentGameState = gameState;
            
            // Update progress bar
            if (gameState.gameStarted && totalQuestions > 0) {
                const progress = Math.min(100, (gameState.currentQuestion / (totalQuestions - 1)) * 100);
                document.getElementById('quizProgress').style.width = `${progress}%`;
            }
            
            // Check for finished phase first
            if (gameState.phase === 'finished' || (!gameState.gameStarted && gameState.phase === 'finished')) {
                showEndScreen(gameData);
                return;
            }
            
            // Then check if game hasn't started yet
            if (!gameState.gameStarted) {
                showScreen('waiting');
                return;
            }

            showScreen('quiz');
            
            // Update question info
            questionNumber.textContent = `Question ${gameState.currentQuestion + 1} of ${totalQuestions}`;
            
            if (currentQuestionData) {
                questionText.textContent = currentQuestionData.question;
                
                if (currentQuestionData.image) {
                    questionImage.src = currentQuestionData.image;
                    questionImage.classList.remove('hidden');
                } else {
                    questionImage.classList.add('hidden');
                }
            }

            // Handle different phases
            switch (gameState.phase) {
                case 'question_shown':
                    showBuzzerPhase(gameState);
                    break;
                case 'options_shown':
                    showOptionsPhase(gameState, currentQuestionData);
                    break;
                case 'reveal':
                    showRevealPhase(gameState, currentQuestionData);
                    break;
                default:
                    showWaitingPhase();
            }
        }

        function showBuzzerPhase(gameState) {
            buzzerPhase.classList.remove('hidden');
            optionsPhase.classList.add('hidden');
            revealPhase.classList.add('hidden');
            
            statusMessage.className = 'status-message status-waiting';
            statusMessage.innerHTML = `
                <i class="fas fa-microphone"></i> Press the buzzer if you know the answer!
            `;
            
            // Check if this player already buzzed
            const hasBuzzed = gameState.buzzers.some(buzzer => 
                buzzer.playerId === playerId && buzzer.question === gameState.currentQuestion
            );
            
            if (hasBuzzed) {
                statusMessage.innerHTML = `
                    <i class="fas fa-check-circle"></i> You buzzed! Wait for the teacher to call on you.
                `;
                statusMessage.className = 'status-message status-buzzed';
                buzzerBtn.disabled = true;
                buzzerBtn.innerHTML = 'BUZZED!';
            } else {
                buzzerBtn.disabled = false;
                buzzerBtn.innerHTML = 'BUZZ!';
            }
        }

        function showOptionsPhase(gameState, questionData) {
            // NUCLEAR SAFEGUARD: Aggressive state clearing before showing options
            nuclearStateReset();
            
            buzzerPhase.classList.add('hidden');
            optionsPhase.classList.remove('hidden');
            revealPhase.classList.add('hidden');
            
            statusMessage.className = 'status-message status-waiting';
            statusMessage.innerHTML = `
                <i class="fas fa-list"></i> Choose your answer:
            `;
            
            // Check if player already answered
            const playerAnswer = gameState.answers.find(answer => 
                answer.playerId === playerId && answer.question === gameState.currentQuestion
            );
            
            if (playerAnswer) {
                hasAnswered = true;
                selectedAnswer = playerAnswer.answer;
                
                // Show feedback based on correctness
                if (playerAnswer.isCorrect) {
                    statusMessage.innerHTML = `
                        <i class="fas fa-check-circle"></i> Correct! Well done!
                    `;
                    statusMessage.className = 'status-message status-correct-answer';
                } else {
                    statusMessage.innerHTML = `
                        <i class="fas fa-times-circle"></i> Incorrect. The right answer will be revealed soon.
                    `;
                    statusMessage.className = 'status-message status-incorrect-answer';
                }
            }

            // Create options with complete state reset and unique identifiers
            if (questionData && questionData.options) {
                // CRITICAL: Complete DOM cleanup with forced reflow
                optionsGrid.innerHTML = '';
                optionsGrid.className = 'options-grid';
                optionsGrid.removeAttribute('data-question');
                optionsGrid.removeAttribute('data-answered');
                
                // Force DOM reflow to ensure cleanup takes effect
                optionsGrid.offsetHeight;
                
                questionData.options.forEach((option, index) => {
                    // Create completely fresh button with unique identifier
                    const button = document.createElement('button');
                    const uniqueId = `option-${Date.now()}-${Math.random().toString(36).substr(2, 9)}-${index}`;
                    
                    button.id = uniqueId;
                    button.className = 'option-btn'; // Start with clean base class only
                    button.textContent = option;
                    button.type = 'button'; // Prevent form submission behavior
                    
                    // Aggressively clear any potential autocomplete/cache attributes
                    button.setAttribute('autocomplete', 'off');
                    button.setAttribute('data-fresh', 'true');
                    button.dataset.questionId = gameState.currentQuestion;
                    button.dataset.optionIndex = index;
                    
                    // Only disable if player has already answered THIS question
                    button.disabled = hasAnswered;
                    
                    if (debugMode) {
                        console.log(`Creating button ${index}:`, {
                            hasAnswered,
                            selectedAnswer,
                            buttonId: uniqueId
                        });
                    }
                    
                    // Apply visual feedback only if answered THIS question
                    if (hasAnswered && selectedAnswer === index) {
                        if (playerAnswer && playerAnswer.isCorrect) {
                            button.classList.add('correct');
                        } else {
                            button.classList.add('incorrect');
                        }
                    } else if (hasAnswered) {
                        // Show correct answer if this player got it wrong
                        if (playerAnswer && !playerAnswer.isCorrect && index === questionData.correct) {
                            button.classList.add('correct-answer');
                        }
                    }
                    
                    // Add click handler only if not answered
                    if (!hasAnswered) {
                        // Use a closure to prevent any variable capture issues
                        button.addEventListener('click', ((btnIndex, btnElement, qData) => {
                            return function(e) {
                                e.stopPropagation();
                                e.preventDefault();
                                if (debugMode) console.log(`Button ${btnIndex} clicked, calling selectAnswer`);
                                selectAnswer(btnIndex, btnElement, qData);
                            };
                        })(index, button, questionData), { once: true });
                    }
                    
                    optionsGrid.appendChild(button);
                });
                
                if (debugMode) {
                    console.log('Options created:', {
                        totalOptions: questionData.options.length,
                        hasAnswered,
                        selectedAnswer,
                        buttonsInDOM: optionsGrid.children.length
                    });
                }
            }
        }

        function showRevealPhase(gameState, questionData) {
            buzzerPhase.classList.add('hidden');
            optionsPhase.classList.add('hidden');
            revealPhase.classList.remove('hidden');
            
            statusMessage.className = 'status-message status-revealed';
            statusMessage.innerHTML = `
                <i class="fas fa-eye"></i> Correct answer revealed
            `;
            
            // Show correct answer
            if (questionData) {
                correctAnswerText.textContent = questionData.options[questionData.correct];
                
                // Show all option states
                const optionButtons = document.querySelectorAll('.option-btn');
                optionButtons.forEach((btn, index) => {
                    btn.disabled = true;
                    if (index === questionData.correct) {
                        btn.classList.add('correct-answer');
                    } else if (selectedAnswer === index) {
                        btn.classList.add('incorrect');
                    }
                });
                
                // Show explanation if available
                if (questionData.explanation) {
                    questionExplanation.textContent = questionData.explanation;
                    explanationSection.classList.remove('hidden');
                } else {
                    explanationSection.classList.add('hidden');
                }
            }
        }

        function showWaitingPhase() {
            buzzerPhase.classList.add('hidden');
            optionsPhase.classList.add('hidden');
            revealPhase.classList.add('hidden');
            statusMessage.textContent = 'Waiting for next question...';
            statusMessage.className = 'status-message status-waiting';
        }

        function showEndScreen(gameData) {
            showScreen('end');
            
            // Get comprehensive performance data
            getPlayerSummary().then(summaryData => {
                if (summaryData.success) {
                    generatePerformanceSummary(summaryData.summary);
                    generateDetailedBreakdown(summaryData.summary);
                    
                    // Store questions data for PDF generation
                    window.currentQuizData = {
                        questions: summaryData.allQuestions,
                        playerSummary: summaryData.summary
                    };
                }
            });
            
            // Add event listeners for end screen buttons
            document.getElementById('downloadVocabularyBtn').onclick = () => generateComprehensiveVocabularyPDF();
            document.getElementById('downloadNotesBtn').onclick = downloadStudentNotesPDF;
            document.getElementById('newQuizBtn').onclick = () => showScreen('waiting');
        }

        async function getPlayerSummary() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'getPlayerSummary',
                        playerId: playerId
                    })
                });
                return await response.json();
            } catch (error) {
                console.error('Error getting player summary:', error);
                return { success: false };
            }
        }

        function generatePerformanceSummary(summary) {
            const statsContainer = document.getElementById('performanceStats');
            const correctPercentage = summary.totalQuestions > 0 ? Math.round((summary.correctAnswers / summary.totalQuestions) * 100) : 0;
            
            let performanceMessage = '';
            let performanceColor = '';
            
            if (correctPercentage >= 80) {
                performanceMessage = 'Excellent work! You have a strong understanding of cybersecurity concepts.';
                performanceColor = '#10b981';
            } else if (correctPercentage >= 60) {
                performanceMessage = 'Good job! You have a solid grasp of most concepts with room for improvement.';
                performanceColor = '#f59e0b';
            } else {
                performanceMessage = 'Keep learning! Review the explanations to strengthen your cybersecurity knowledge.';
                performanceColor = '#ef4444';
            }
            
            statsContainer.innerHTML = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
                    <div style="text-align: center; padding: 1rem; background-color: #1f2937; border-radius: 0.5rem;">
                        <div style="font-size: 2rem; font-weight: bold; color: #22d3ee;">${summary.totalQuestions}</div>
                        <div style="color: #9ca3af;">Total Questions</div>
                    </div>
                    <div style="text-align: center; padding: 1rem; background-color: #1f2937; border-radius: 0.5rem;">
                        <div style="font-size: 2rem; font-weight: bold; color: #10b981;">${summary.correctAnswers}</div>
                        <div style="color: #9ca3af;">Correct</div>
                    </div>
                    <div style="text-align: center; padding: 1rem; background-color: #1f2937; border-radius: 0.5rem;">
                        <div style="font-size: 2rem; font-weight: bold; color: #ef4444;">${summary.incorrectAnswers}</div>
                        <div style="color: #9ca3af;">Incorrect</div>
                    </div>
                    <div style="text-align: center; padding: 1rem; background-color: #1f2937; border-radius: 0.5rem;">
                        <div style="font-size: 2rem; font-weight: bold; color: ${performanceColor};">${correctPercentage}%</div>
                        <div style="color: #9ca3af;">Score</div>
                    </div>
                </div>
                <div style="padding: 1rem; background-color: rgba(34, 211, 238, 0.1); border-radius: 0.5rem; border-left: 4px solid #22d3ee;">
                    <p style="color: #d1d5db; line-height: 1.5;">${performanceMessage}</p>
                </div>
            `;
        }

        function generateDetailedBreakdown(summary) {
            const breakdownContainer = document.getElementById('questionBreakdown');
            
            let html = '';
            summary.questionBreakdown.forEach((item, index) => {
                const statusIcon = item.isCorrect ? 
                    '<i class="fas fa-check-circle" style="color: #10b981;"></i>' : 
                    '<i class="fas fa-times-circle" style="color: #ef4444;"></i>';
                
                const statusColor = item.isCorrect ? '#10b981' : '#ef4444';
                const statusBg = item.isCorrect ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)';
                
                html += `
                    <div style="margin-bottom: 1.5rem; padding: 1rem; background-color: #1f2937; border-radius: 0.5rem; border-left: 4px solid ${statusColor};">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                            ${statusIcon}
                            <h4 style="color: #ffffff; margin: 0;">Question ${index + 1}: ${item.question}</h4>
                        </div>
                        <div style="margin-left: 1.5rem;">
                            <p style="color: #d1d5db; margin-bottom: 0.5rem;">
                                <strong>Your answer:</strong> 
                                <span style="color: ${statusColor};">${item.playerAnswer}</span>
                            </p>
                            ${!item.isCorrect ? `
                                <p style="color: #d1d5db; margin-bottom: 0.5rem;">
                                    <strong>Correct answer:</strong> 
                                    <span style="color: #10b981;">${item.correctAnswer}</span>
                                </p>
                            ` : ''}
                            ${item.explanation ? `
                                <div style="margin-top: 0.75rem; padding: 0.75rem; background-color: ${statusBg}; border-radius: 0.25rem;">
                                    <p style="color: #d1d5db; font-size: 0.9rem; line-height: 1.4; margin: 0;">
                                        <strong>Explanation:</strong> ${item.explanation}
                                    </p>
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            });
            
            breakdownContainer.innerHTML = html;
        }

        async function pressBuzzer() {
            const now = Date.now();
            
            // Prevent rapid double-presses
            if (now - lastBuzzerPressTime < 1000) {
                return;
            }
            lastBuzzerPressTime = now;
            
            // Visual feedback first
            buzzerBtn.classList.add('pressed');
            setTimeout(() => buzzerBtn.classList.remove('pressed'), 300);
            
            // Play buzzer sound
            await playSound(buzzSound);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'pressBuzzer', 
                        playerId: playerId,
                        currentStateVersion: currentStateVersion
                    })
                });

                const result = await response.json();
                
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    
                    // Update UI to show buzz was successful
                    buzzerBtn.disabled = true;
                    buzzerBtn.innerHTML = 'BUZZED!';
                    statusMessage.innerHTML = `
                        <i class="fas fa-check-circle"></i> You buzzed! Wait for the teacher to call on you.
                    `;
                    statusMessage.className = 'status-message status-buzzed';
                } else {
                    // Revert UI since buzz wasn't recorded
                    buzzerBtn.disabled = false;
                    buzzerBtn.innerHTML = 'BUZZ!';
                    statusMessage.innerHTML = `
                        <i class="fas fa-exclamation-circle"></i> Could not record buzz. Please try again.
                    `;
                    statusMessage.className = 'status-message status-waiting';
                }
            } catch (error) {
                console.error('Error pressing buzzer:', error);
                
                // Revert UI since buzz wasn't recorded
                buzzerBtn.disabled = false;
                buzzerBtn.innerHTML = 'BUZZ!';
                statusMessage.innerHTML = `
                    <i class="fas fa-exclamation-circle"></i> Network error. Could not record buzz.
                `;
                statusMessage.className = 'status-message status-waiting';
            }
        }

        async function selectAnswer(answerIndex, buttonElement, questionData) {
            // CRITICAL: Multiple safeguards against double selection
            if (hasAnswered || buttonElement.disabled || buttonElement.classList.contains('selected')) {
                return;
            }
            
            // Immediately mark as answered to prevent race conditions
            hasAnswered = true;
            
            // Visual selection feedback with complete state reset
            optionsGrid.querySelectorAll('.option-btn').forEach(btn => {
                btn.classList.remove('selected', 'correct', 'incorrect', 'correct-answer');
                btn.disabled = true; // Disable all options immediately
            });
            
            buttonElement.classList.add('selected');
            selectedAnswer = answerIndex;
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'submitAnswer', 
                        playerId: playerId, 
                        answer: answerIndex,
                        currentStateVersion: currentStateVersion
                    })
                });

                const result = await response.json();
                
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    
                    // CRITICAL: Immediate visual feedback
                    const isCorrect = result.isCorrect;
                    
                    if (isCorrect) {
                        buttonElement.classList.remove('selected');
                        buttonElement.classList.add('correct');
                        statusMessage.innerHTML = `
                            <i class="fas fa-check-circle"></i> Correct! Well done!
                        `;
                        statusMessage.className = 'status-message status-correct-answer';
                        await playSound(correctSound);
                    } else {
                        buttonElement.classList.remove('selected');
                        buttonElement.classList.add('incorrect');
                        statusMessage.innerHTML = `
                            <i class="fas fa-times-circle"></i> Incorrect. The right answer will be revealed soon.
                        `;
                        statusMessage.className = 'status-message status-incorrect-answer';
                        await playSound(incorrectSound);
                        
                        // Highlight correct answer after a brief delay
                        setTimeout(() => {
                            const correctBtn = optionsGrid.children[questionData.correct];
                            if (correctBtn) {
                                correctBtn.classList.add('correct-answer');
                            }
                        }, 1000);
                    }
                } else {
                    // Revert selection since answer wasn't recorded
                    hasAnswered = false;
                    buttonElement.classList.remove('selected');
                    optionsGrid.querySelectorAll('.option-btn').forEach(btn => {
                        btn.disabled = false;
                    });
                    selectedAnswer = null;
                    
                    statusMessage.innerHTML = `
                        <i class="fas fa-exclamation-circle"></i> Could not submit answer. Please try again.
                    `;
                    statusMessage.className = 'status-message status-waiting';
                    await playSound(incorrectSound);
                }
            } catch (error) {
                console.error('Error submitting answer:', error);
                
                // Revert selection since answer wasn't recorded
                hasAnswered = false;
                buttonElement.classList.remove('selected');
                optionsGrid.querySelectorAll('.option-btn').forEach(btn => {
                    btn.disabled = false;
                });
                selectedAnswer = null;
                
                statusMessage.innerHTML = `
                    <i class="fas fa-exclamation-circle"></i> Network error. Could not submit answer.
                `;
                statusMessage.className = 'status-message status-waiting';
            }
        }

        async function playSound(audioElement) {
            if (audioElement) {
                try {
                    audioElement.currentTime = 0;
                    await audioElement.play();
                } catch (e) {
                    // Sound failed to play - this is fine, continue silently
                }
            }
        }

        function showScreen(screen) {
            loginScreen.classList.add('hidden');
            waitingScreen.classList.add('hidden');
            quizScreen.classList.add('hidden');
            endScreen.classList.add('hidden');
            
            switch(screen) {
                case 'login':
                    loginScreen.classList.remove('hidden');
                    break;
                case 'waiting':
                    waitingScreen.classList.remove('hidden');
                    break;
                case 'quiz':
                    quizScreen.classList.remove('hidden');
                    break;
                case 'end':
                    endScreen.classList.remove('hidden');
                    break;
            }
        }

        // Enhanced PDF generation - Fixed approach without external dependencies
        async function generateComprehensiveVocabularyPDF() {
            if (!window.currentQuizData || !window.currentQuizData.questions) {
                addMessage('error', 'Quiz data not available for PDF generation.');
                return;
            }
            
            try {
                addMessage('info', 'Generating comprehensive vocabulary PDF...');
                
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a4'
                });
                
                const questions = window.currentQuizData.questions;
                const playerSummary = window.currentQuizData.playerSummary;
                
                let yPosition = 20;
                const pageWidth = 210;
                const margin = 15;
                const contentWidth = pageWidth - 2 * margin;
                
                // Helper function to add text with word wrap
                function addWrappedText(text, x, y, maxWidth, fontSize = 10) {
                    pdf.setFontSize(fontSize);
                    const lines = pdf.splitTextToSize(text, maxWidth);
                    pdf.text(lines, x, y);
                    return lines.length * (fontSize * 0.4); // Return height used
                }
                
                // Helper function to check page space and add new page if needed
                function checkPageSpace(requiredHeight) {
                    if (yPosition + requiredHeight > 280) { // Near bottom of page
                        pdf.addPage();
                        yPosition = 20;
                    }
                }
                
                // Title
                pdf.setFontSize(18);
                pdf.setFont(undefined, 'bold');
                pdf.setTextColor(8, 145, 178); // Cyan color
                pdf.text('Cybersecurity Quiz - Complete Study Guide', margin, yPosition);
                yPosition += 10;
                
                // Underline
                pdf.setDrawColor(8, 145, 178);
                pdf.line(margin, yPosition, pageWidth - margin, yPosition);
                yPosition += 15;
                
                // Performance Summary Box
                pdf.setFillColor(248, 249, 250); // Light gray background
                pdf.rect(margin, yPosition - 5, contentWidth, 25, 'F');
                
                pdf.setFontSize(14);
                pdf.setFont(undefined, 'bold');
                pdf.setTextColor(17, 24, 39); // Dark text
                pdf.text('Your Performance Summary', margin + 5, yPosition + 3);
                yPosition += 8;
                
                pdf.setFontSize(10);
                pdf.setFont(undefined, 'normal');
                const scorePercentage = Math.round((playerSummary.correctAnswers / playerSummary.totalQuestions) * 100);
                
                pdf.text(`Questions Answered: ${playerSummary.answeredQuestions} of ${playerSummary.totalQuestions}`, margin + 5, yPosition);
                yPosition += 4;
                pdf.text(`Correct Answers: ${playerSummary.correctAnswers}`, margin + 5, yPosition);
                yPosition += 4;
                pdf.text(`Score: ${scorePercentage}%`, margin + 5, yPosition);
                yPosition += 4;
                pdf.text(`Generated: ${new Date().toLocaleDateString()}`, margin + 5, yPosition);
                yPosition += 15;
                
                // Questions Section
                questions.forEach((question, index) => {
                    checkPageSpace(40); // Ensure space for question block
                    
                    const playerAnswer = playerSummary.questionBreakdown[index];
                    const wasCorrect = playerAnswer && playerAnswer.isCorrect;
                    
                    // Question header with colored border
                    pdf.setDrawColor(wasCorrect ? 16 : 239, wasCorrect ? 185 : 68, wasCorrect ? 129 : 68);
                    pdf.setLineWidth(2);
                    pdf.line(margin, yPosition, margin + 10, yPosition);
                    
                    pdf.setFontSize(12);
                    pdf.setFont(undefined, 'bold');
                    pdf.setTextColor(17, 24, 39);
                    
                    // Question text with number
                    const questionHeader = `${index + 1}. ${question.question}`;
                    const headerHeight = addWrappedText(questionHeader, margin + 15, yPosition, contentWidth - 20, 12);
                    yPosition += headerHeight + 3;
                    
                    // Status indicator
                    pdf.setFontSize(9);
                    pdf.setTextColor(wasCorrect ? 16 : 239, wasCorrect ? 185 : 68, wasCorrect ? 129 : 68);
                    const statusText = playerAnswer ? (wasCorrect ? '✓ Correct' : '✗ Missed') : '— Skipped';
                    pdf.text(statusText, pageWidth - margin - 20, yPosition - headerHeight + 2);
                    
                    // Correct Answer
                    pdf.setFontSize(10);
                    pdf.setFont(undefined, 'bold');
                    pdf.setTextColor(0, 0, 0);
                    pdf.text('Correct Answer:', margin + 10, yPosition);
                    yPosition += 4;
                    
                    pdf.setFont(undefined, 'normal');
                    pdf.setTextColor(16, 185, 129); // Green
                    const correctHeight = addWrappedText(question.options[question.correct], margin + 10, yPosition, contentWidth - 15);
                    yPosition += correctHeight + 2;
                    
                    // Your Answer (if wrong)
                    if (playerAnswer && !wasCorrect && playerAnswer.playerAnswer !== 'Not answered') {
                        pdf.setFont(undefined, 'bold');
                        pdf.setTextColor(0, 0, 0);
                        pdf.text('Your Answer:', margin + 10, yPosition);
                        yPosition += 4;
                        
                        pdf.setFont(undefined, 'normal');
                        pdf.setTextColor(239, 68, 68); // Red
                        const wrongHeight = addWrappedText(playerAnswer.playerAnswer, margin + 10, yPosition, contentWidth - 15);
                        yPosition += wrongHeight + 2;
                    }
                    
                    // Explanation
                    if (question.explanation) {
                        checkPageSpace(20); // Check space for explanation
                        
                        // Light background for explanation
                        const explanationLines = pdf.splitTextToSize(question.explanation, contentWidth - 25);
                        const explanationHeight = explanationLines.length * 4 + 8;
                        
                        pdf.setFillColor(241, 245, 249); // Light blue background
                        pdf.rect(margin + 5, yPosition - 2, contentWidth - 10, explanationHeight, 'F');
                        
                        pdf.setFont(undefined, 'bold');
                        pdf.setTextColor(0, 0, 0);
                        pdf.text('Explanation:', margin + 10, yPosition + 2);
                        yPosition += 6;
                        
                        pdf.setFont(undefined, 'normal');
                        pdf.setFontSize(9);
                        pdf.text(explanationLines, margin + 10, yPosition);
                        yPosition += explanationLines.length * 4 + 3;
                    }
                    
                    yPosition += 8; // Space between questions
                });
                
                // Study Tips Section
                checkPageSpace(60);
                
                pdf.setFillColor(254, 247, 205); // Light yellow background
                pdf.rect(margin, yPosition - 5, contentWidth, 50, 'F');
                
                pdf.setFontSize(14);
                pdf.setFont(undefined, 'bold');
                pdf.setTextColor(17, 24, 39);
                pdf.text('Study Tips for Cybersecurity', margin + 5, yPosition + 3);
                yPosition += 10;
                
                const tips = [
                    'Practice identifying phishing emails in your daily life',
                    'Enable multi-factor authentication on all your important accounts',
                    'Use unique, strong passwords for each online account',
                    'Keep your software and devices updated with security patches',
                    'Be skeptical of unsolicited phone calls asking for personal information',
                    'Review your privacy settings on social media platforms regularly'
                ];
                
                pdf.setFontSize(9);
                pdf.setFont(undefined, 'normal');
                pdf.setTextColor(0, 0, 0);
                
                tips.forEach(tip => {
                    checkPageSpace(6);
                    pdf.text('• ' + tip, margin + 10, yPosition);
                    yPosition += 5;
                });
                
                // Footer
                yPosition += 10;
                checkPageSpace(15);
                
                pdf.setFontSize(8);
                pdf.setTextColor(102, 102, 102);
                pdf.text('Keep this study guide for future reference to strengthen your cybersecurity knowledge!', 
                    margin, yPosition);
                pdf.text('Generated by Interactive Quiz System', margin, yPosition + 5);
                
                // Save the PDF
                pdf.save('cybersecurity-complete-study-guide.pdf');
                addMessage('success', 'Complete study guide PDF downloaded successfully!');
                
            } catch (error) {
                console.error('Error generating comprehensive PDF:', error);
                addMessage('error', 'Error generating PDF: ' + error.message);
            }
        }

        async function downloadStudentNotesPDF() {
            try {
                addMessage('info', 'Generating class notes PDF...');
                
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a4'
                });
                
                // Get the notes content
                const notesHTML = studentNotesContent.innerHTML;
                if (!notesHTML || notesHTML.trim() === '' || notesHTML.includes('No notes available yet')) {
                    addMessage('warning', 'There are no class notes to download.');
                    return;
                }
                
                let yPosition = 20;
                const pageWidth = 210;
                const margin = 15;
                const contentWidth = pageWidth - 2 * margin;
                
                // Helper function to add text with word wrap
                function addWrappedText(text, x, y, maxWidth, fontSize = 10) {
                    pdf.setFontSize(fontSize);
                    const lines = pdf.splitTextToSize(text, maxWidth);
                    pdf.text(lines, x, y);
                    return lines.length * (fontSize * 0.4);
                }
                
                // Helper function to check page space
                function checkPageSpace(requiredHeight) {
                    if (yPosition + requiredHeight > 280) {
                        pdf.addPage();
                        yPosition = 20;
                    }
                }
                
                // Title
                pdf.setFontSize(18);
                pdf.setFont(undefined, 'bold');
                pdf.setTextColor(8, 145, 178);
                pdf.text('Class Notes - Interactive Quiz Session', margin, yPosition);
                yPosition += 10;
                
                // Underline
                pdf.setDrawColor(8, 145, 178);
                pdf.line(margin, yPosition, pageWidth - margin, yPosition);
                yPosition += 15;
                
                // Date/time info
                pdf.setFontSize(10);
                pdf.setFont(undefined, 'normal');
                pdf.setTextColor(102, 102, 102);
                pdf.text(`Downloaded: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}`, 
                    margin, yPosition);
                yPosition += 15;
                
                // Convert HTML to plain text (basic markdown processing)
                let notesText = notesHTML
                    // Remove HTML tags but preserve line breaks
                    .replace(/<br\s*\/?>/gi, '\n')
                    .replace(/<\/p>/gi, '\n')
                    .replace(/<p[^>]*>/gi, '')
                    .replace(/<\/h[1-6]>/gi, '\n')
                    .replace(/<h[1-6][^>]*>/gi, '\n--- ')
                    .replace(/<\/li>/gi, '\n')
                    .replace(/<li[^>]*>/gi, '• ')
                    .replace(/<\/ul>/gi, '\n')
                    .replace(/<ul[^>]*>/gi, '\n')
                    .replace(/<strong[^>]*>(.*?)<\/strong>/gi, '$1')
                    .replace(/<em[^>]*>(.*?)<\/em>/gi, '$1')
                    .replace(/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/gi, '$2 ($1)')
                    .replace(/<[^>]*>/g, '') // Remove remaining HTML tags
                    .replace(/&nbsp;/g, ' ')
                    .replace(/&amp;/g, '&')
                    .replace(/&lt;/g, '<')
                    .replace(/&gt;/g, '>')
                    .replace(/&quot;/g, '"')
                    .replace(/\n\s*\n/g, '\n\n') // Clean up multiple line breaks
                    .trim();
                
                if (!notesText || notesText === '') {
                    addMessage('warning', 'No readable content found in notes.');
                    return;
                }
                
                // Add the notes content
                pdf.setFontSize(11);
                pdf.setFont(undefined, 'normal');
                pdf.setTextColor(0, 0, 0);
                
                // Split content into paragraphs and process each
                const paragraphs = notesText.split('\n\n');
                
                paragraphs.forEach(paragraph => {
                    if (paragraph.trim() === '') return;
                    
                    checkPageSpace(15);
                    
                    // Check if it's a heading (starts with ---)
                    if (paragraph.startsWith('--- ')) {
                        pdf.setFontSize(13);
                        pdf.setFont(undefined, 'bold');
                        pdf.setTextColor(8, 145, 178);
                        const headingText = paragraph.substring(4).trim();
                        const headingHeight = addWrappedText(headingText, margin, yPosition, contentWidth, 13);
                        yPosition += headingHeight + 5;
                        
                        // Reset to normal text
                        pdf.setFontSize(11);
                        pdf.setFont(undefined, 'normal');
                        pdf.setTextColor(0, 0, 0);
                    } else {
                        // Regular paragraph
                        const paragraphHeight = addWrappedText(paragraph, margin, yPosition, contentWidth, 11);
                        yPosition += paragraphHeight + 4;
                    }
                });
                
                // Footer section
                checkPageSpace(25);
                yPosition += 10;
                
                // Footer box
                pdf.setFillColor(241, 245, 249);
                pdf.rect(margin, yPosition - 5, contentWidth, 20, 'F');
                
                pdf.setFontSize(9);
                pdf.setTextColor(102, 102, 102);
                pdf.text('Note: Links and interactive content from the original notes are preserved as text references.', 
                    margin + 5, yPosition);
                yPosition += 5;
                pdf.text('Keep this document for your cybersecurity studies and reference.', 
                    margin + 5, yPosition);
                yPosition += 5;
                pdf.text('Generated by Interactive Quiz System', 
                    margin + 5, yPosition);
                
                // Save the PDF
                pdf.save("class-notes.pdf");
                addMessage('success', 'Class notes PDF downloaded successfully!');
                
            } catch (error) {
                console.error('Error generating notes PDF:', error);
                addMessage('error', 'Error generating notes PDF: ' + error.message);
            }
        }

        // Network status handling
        function setNetworkStatus(status, message) {
            networkStatus.textContent = message || (status === 'online' ? 
                'Connected to server' : 'Connection lost. Reconnecting...');
            
            networkStatus.className = 'network-status ' + status + ' active';
            
            setTimeout(() => {
                networkStatus.classList.remove('active');
            }, 3000);
        }

        // Message system
        function addMessage(type, text) {
            // Don't show duplicate messages
            const messageKey = type + text;
            if (document.querySelector(`[data-key="${messageKey}"]`)) {
                return;
            }
            
            const messageEl = document.createElement('div');
            messageEl.className = `message ${type}`;
            messageEl.dataset.key = messageKey;
            messageEl.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas ${type === 'info' ? 'fa-info-circle' : type === 'warning' ? 'fa-exclamation-triangle' : type === 'error' ? 'fa-times-circle' : 'fa-check-circle'}"></i>
                    <span>${text}</span>
                </div>
            `;
            
            messageSystem.appendChild(messageEl);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                messageEl.style.opacity = '0';
                messageEl.style.transform = 'translateX(20px)';
                setTimeout(() => {
                    if (messageEl.parentNode) {
                        messageEl.parentNode.removeChild(messageEl);
                    }
                }, 300);
            }, 5000);
        }

        // Student notes functionality
        async function fetchStudentNotes() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'getNotes' })
                });
                const result = await response.json();
                
                if (result.success) {
                    renderMarkdownStudent(studentNotesContent, result.notes.content);
                }
            } catch (error) {
                console.error('Error fetching notes:', error);
            }
        }

        function renderMarkdownStudent(element, text) {
            if (!text || text.trim() === '') {
                element.innerHTML = '<p style="color: var(--text-gray-400); font-style: italic;">No notes available yet.</p>';
                return;
            }
            
            // Enhanced markdown to HTML converter with better link handling
            let html = text
                .replace(/\n#{1}\s(.+)/g, '<h2 style="color: var(--cyan-400); margin: 1rem 0 0.5rem 0;">$1</h2>')
                .replace(/\n#{2}\s(.+)/g, '<h3 style="color: var(--text-white); margin: 0.75rem 0 0.5rem 0;">$1</h3>')
                .replace(/\n#{3}\s(.+)/g, '<h4 style="color: var(--text-gray-300); margin: 0.5rem 0 0.25rem 0;">$1</h4>')
                .replace(/\n- (.+)/g, '<li style="margin: 0.25rem 0;">$1</li>')
                .replace(/\n\* (.+)/g, '<li style="margin: 0.25rem 0;">$1</li>')
                .replace(/__(.*?)__/g, '<strong>$1</strong>')
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/_(.*?)_/g, '<em>$1</em>')
                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                // Enhanced link handling to ensure they're clickable in PDFs too
                .replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank" rel="noopener" style="color:var(--cyan-400); text-decoration:underline; cursor: pointer;">$1</a>')
                .replace(/\n\n/g, '<br><br>');
            
            // Wrap lists properly
            html = html.replace(/(<li.*?>.*?<\/li>)+/g, '<ul style="margin: 0.5rem 0; padding-left: 1.5rem;">$&</ul>');
            
            element.innerHTML = html;
            
            // Ensure all links in notes are properly clickable
            const links = element.querySelectorAll('a');
            links.forEach(link => {
                link.addEventListener('click', function(e) {
                    // Ensure links work properly
                    if (this.href) {
                        window.open(this.href, '_blank', 'noopener,noreferrer');
                    }
                });
            });
        }

        // Handle browser refresh/reload - rejoin automatically
        window.addEventListener('beforeunload', function() {
            // Store player ID for potential reconnection
            if (playerId) {
                sessionStorage.setItem('quizPlayerId', playerId);
            }
        });

        // Check for existing player ID on load (reconnection handling)
        window.addEventListener('load', function() {
            const storedPlayerId = sessionStorage.getItem('quizPlayerId');
            if (storedPlayerId && !playerId) {
                // NUCLEAR RESET before rejoining to ensure clean state
                nuclearStateReset();
                
                // Attempt to rejoin automatically
                playerId = storedPlayerId;
                currentStateVersion = 0;
                showScreen('waiting');
                startPolling();
            }
        });

        // Keyboard shortcuts for better accessibility
        document.addEventListener('keydown', function(e) {
            // Space bar or Enter to press buzzer
            if ((e.code === 'Space' || e.code === 'Enter') && 
                !buzzerBtn.disabled && 
                buzzerPhase && !buzzerPhase.classList.contains('hidden')) {
                e.preventDefault();
                pressBuzzer();
            }
            
            // Number keys 1-4 to select answers
            if (e.code.startsWith('Digit') && 
                !optionsPhase.classList.contains('hidden') && 
                !hasAnswered) {
                const num = parseInt(e.code.replace('Digit', ''));
                if (num >= 1 && num <= 4) {
                    const optionButton = optionsGrid.children[num - 1];
                    if (optionButton && !optionButton.disabled) {
                        e.preventDefault();
                        const currentQuestionData = currentGameState?.currentQuestionData || 
                            (window.currentQuizData && window.currentQuizData.questions ? 
                             window.currentQuizData.questions[currentGameState.currentQuestion] : null);
                        if (currentQuestionData) {
                            selectAnswer(num - 1, optionButton, currentQuestionData);
                        }
                    }
                }
            }
            
            // Escape key to close help panel
            if (e.code === 'Escape' && helpPanel.classList.contains('active')) {
                helpPanel.classList.remove('active');
            }
        });

        // Add touch events for better mobile experience
        if ('ontouchstart' in window) {
            buzzerBtn.addEventListener('touchstart', function(e) {
                e.preventDefault();
                this.classList.add('pressed');
            });
            
            buzzerBtn.addEventListener('touchend', function(e) {
                e.preventDefault();
                this.classList.remove('pressed');
                if (!this.disabled) {
                    pressBuzzer();
                }
            });
        }

        // Performance monitoring
        let performanceMetrics = {
            startTime: Date.now(),
            buzzPresses: 0,
            answersSubmitted: 0,
            networkErrors: 0
        };

        // Track performance metrics
        function trackMetric(metric) {
            if (performanceMetrics[metric] !== undefined) {
                performanceMetrics[metric]++;
            }
        }

        // Cleanup function for when page is closed
        window.addEventListener('unload', function() {
            if (pollInterval) {
                clearInterval(pollInterval);
            }
            if (reconnectTimeout) {
                clearTimeout(reconnectTimeout);
            }
        });

        // Initialize service worker for offline capability (if available)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                // Note: Service worker registration would go here in a production environment
                // For now, we'll just track that the feature is available
                console.log('Service Worker support detected - offline capability could be implemented');
            });
        }

        // Add visual feedback for successful operations
        function showSuccessAnimation(element) {
            element.style.transform = 'scale(1.05)';
            element.style.transition = 'transform 0.2s ease';
            setTimeout(() => {
                element.style.transform = 'scale(1)';
            }, 200);
        }

        // Enhanced error handling with user-friendly messages
        window.addEventListener('error', function(e) {
            console.error('Application error:', e.error);
            addMessage('error', 'Something went wrong. Please refresh the page if problems continue.');
        });

        // Ready state indicator
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Enhanced Quiz Interface Loaded Successfully');
            console.log('Features: Immediate feedback, reconnection handling, PDF generation, audio feedback, keyboard shortcuts');
        });
    </script>
</body>
</html>
