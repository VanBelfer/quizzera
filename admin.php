<?php
// Enhanced Quiz Admin System with Critical Bug Fixes
// Configuration
$gameStateFile = __DIR__ . '/quiz_state.json';
$questionsFile = __DIR__ . '/quiz_questions.json';
$playersFile = __DIR__ . '/quiz_players.json';
$stateVersionFile = __DIR__ . '/quiz_state_version.txt';
$notesFile = __DIR__ . '/quiz_notes.json';
$messagesFile = __DIR__ . '/quiz_messages.json';
$sessionsDir = __DIR__ . '/sessions';

// Critical Fix: File locking functions to prevent corruption
function safeJsonWrite($filename, $data) {
    $tempFile = $filename . '.tmp.' . uniqid();
    $lockFile = $filename . '.lock';
    
    // Wait for lock to be available (max 5 seconds)
    $lockWaitTime = 0;
    while (file_exists($lockFile) && $lockWaitTime < 5) {
        usleep(100000); // 0.1 second
        $lockWaitTime += 0.1;
    }
    
    // Check if we timed out waiting for lock
    if (file_exists($lockFile)) {
        throw new Exception('Could not acquire file lock after 5 seconds - system may be overloaded');
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

// Initialize notes if needed
if (!file_exists($notesFile)) {
    $initialNotes = [
        'content' => "# Class Notes\n*Start adding your notes here...*\n## Useful Links\n- [Cybersecurity Basics](https://www.cisa.gov/cybersecurity)\n- [Phishing Examples](https://www.us-cert.gov/ncas/tips/ST04-014)",
        'updatedAt' => time()
    ];
    safeJsonWrite($notesFile, $initialNotes);
}

// Initialize sessions directory
if (!file_exists($sessionsDir)) {
    mkdir($sessionsDir, 0755, true);
}

// Initialize state version if needed
if (!file_exists($stateVersionFile)) {
    file_put_contents($stateVersionFile, '1');
}

// Load data with enhanced error handling and safe reading
$gameState = [];
$questions = [];
$players = [];

try {
    $gameState = safeJsonRead($gameStateFile) ?: [
        'gameStarted' => false,
        'currentQuestion' => 0,
        'phase' => 'waiting',
        'buzzers' => [],
        'answers' => [],
        'spokenPlayers' => [],
        'firstBuzzer' => null,
        'buzzLocked' => false,
        'timestamp' => time()
    ];
    
    $questions = safeJsonRead($questionsFile) ?: [];
    $players = safeJsonRead($playersFile) ?: [];
} catch (Exception $e) {
    // Reset to safe state if JSON is corrupted
    $gameState = [
        'gameStarted' => false,
        'currentQuestion' => 0,
        'phase' => 'waiting',
        'buzzers' => [],
        'answers' => [],
        'spokenPlayers' => [],
        'firstBuzzer' => null,
        'buzzLocked' => false,
        'timestamp' => time()
    ];
    $questions = [];
    $players = [];
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
    
    // Get current state version for conflict detection
    $currentStateVersion = (int)file_get_contents($stateVersionFile);
    
    if ($input['action'] === 'updateQuestions') {
        try {
            $newQuestions = json_decode($input['questionsJson'], true);
            if (!$newQuestions || !is_array($newQuestions)) {
                throw new Exception('Invalid JSON format');
            }
            
            // Validate questions array is not empty
            if (count($newQuestions) === 0) {
                throw new Exception('Question array cannot be empty - please add at least one question');
            }
            
            // Validate question format
            foreach ($newQuestions as $index => $q) {
                if (!isset($q['question']) || !isset($q['options']) || !isset($q['correct'])) {
                    throw new Exception("Invalid question format at index $index");
                }
                
                if (!is_array($q['options']) || count($q['options']) < 2) {
                    throw new Exception("Question at index $index must have at least 2 options");
                }
                
                if (!is_int($q['correct']) || $q['correct'] < 0 || $q['correct'] >= count($q['options'])) {
                    throw new Exception("Invalid correct answer index at question $index");
                }
            }
            
            // CRITICAL FIX: Enhanced answer shuffling with proper tracking
            foreach ($newQuestions as &$question) {
                if (!isset($question['image'])) {
                    $question['image'] = '';
                }
                if (!isset($question['explanation'])) {
                    $question['explanation'] = '';
                }
                
                // Store original correct answer text for validation
                $correctAnswerText = $question['options'][$question['correct']];
                
                // Create shuffled indices
                $originalIndices = array_keys($question['options']);
                shuffle($originalIndices);
                
                // Build new shuffled options array
                $shuffledOptions = [];
                $newCorrectIndex = -1;
                
                foreach ($originalIndices as $position => $originalIndex) {
                    $shuffledOptions[] = $question['options'][$originalIndex];
                    
                    // Track where the correct answer ended up
                    if ($originalIndex === $question['correct']) {
                        $newCorrectIndex = $position;
                    }
                }
                
                // Double-check: verify the correct answer is in the right position
                if ($newCorrectIndex === -1 || $shuffledOptions[$newCorrectIndex] !== $correctAnswerText) {
                    // Fallback: find correct answer by text matching
                    $newCorrectIndex = array_search($correctAnswerText, $shuffledOptions);
                    if ($newCorrectIndex === false) {
                        throw new Exception("Critical error: Could not maintain correct answer integrity for question: " . substr($question['question'], 0, 50));
                    }
                }
                
                $question['options'] = $shuffledOptions;
                $question['correct'] = $newCorrectIndex;
                
                // Store validation data for debugging
                $question['_originalCorrectText'] = $correctAnswerText;
                $question['_shuffleVerified'] = ($shuffledOptions[$newCorrectIndex] === $correctAnswerText);
            }
            
            safeJsonWrite($questionsFile, $newQuestions);
            
            // Increment state version
            $newVersion = $currentStateVersion + 1;
            file_put_contents($stateVersionFile, $newVersion);
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $newVersion,
                'questionsUpdated' => count($newQuestions)
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'startGame') {
        try {
            $gameState['gameStarted'] = true;
            $gameState['currentQuestion'] = 0;
            $gameState['phase'] = 'question_shown';
            $gameState['buzzers'] = [];
            $gameState['answers'] = [];
            $gameState['spokenPlayers'] = [];
            $gameState['firstBuzzer'] = null;
            $gameState['buzzLocked'] = false;
            $gameState['timestamp'] = time();
            
            safeJsonWrite($gameStateFile, $gameState);
            
            // Increment state version
            $newVersion = $currentStateVersion + 1;
            file_put_contents($stateVersionFile, $newVersion);
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $newVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to start game: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'nextQuestion') {
        try {
            $expectedVersion = (int)($input['currentStateVersion'] ?? 0);
            
            // Enhanced version conflict detection
            if ($expectedVersion > 0 && $expectedVersion != $currentStateVersion) {
                echo json_encode([
                    'success' => false,
                    'error' => 'State conflict detected. Refreshing...',
                    'currentGameState' => $gameState,
                    'stateVersion' => $currentStateVersion,
                    'expectedVersion' => $expectedVersion
                ]);
                exit;
            }
            
            if ($gameState['currentQuestion'] < count($questions) - 1) {
                $gameState['currentQuestion']++;
                $gameState['phase'] = 'question_shown';
                // Reset per-question state explicitly
                $gameState['buzzers'] = [];
                $gameState['answers'] = [];
                $gameState['spokenPlayers'] = [];
                $gameState['firstBuzzer'] = null;
                $gameState['buzzLocked'] = false;
            } else {
                $gameState['gameStarted'] = false;
                $gameState['phase'] = 'finished';
            }
            $gameState['timestamp'] = time();
            
            safeJsonWrite($gameStateFile, $gameState);
            
            // Increment state version
            $newVersion = $currentStateVersion + 1;
            file_put_contents($stateVersionFile, $newVersion);
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $newVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to advance question: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'showOptions') {
        try {
            $gameState['phase'] = 'options_shown';
            $gameState['timestamp'] = time();
            
            safeJsonWrite($gameStateFile, $gameState);
            
            // Increment state version
            $newVersion = $currentStateVersion + 1;
            file_put_contents($stateVersionFile, $newVersion);
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $newVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to show options: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'revealCorrect') {
        try {
            $gameState['phase'] = 'reveal';
            $gameState['timestamp'] = time();
            
            safeJsonWrite($gameStateFile, $gameState);
            
            // Increment state version
            $newVersion = $currentStateVersion + 1;
            file_put_contents($stateVersionFile, $newVersion);
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $newVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to reveal answer: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'markSpoken') {
        try {
            if (!isset($gameState['spokenPlayers'])) {
                $gameState['spokenPlayers'] = [];
            }
            
            $key = $gameState['currentQuestion'] . '_' . $input['playerId'];
            if (!in_array($key, $gameState['spokenPlayers'])) {
                $gameState['spokenPlayers'][] = $key;
            }
            
            $gameState['timestamp'] = time();
            
            safeJsonWrite($gameStateFile, $gameState);
            
            // Increment state version
            $newVersion = $currentStateVersion + 1;
            file_put_contents($stateVersionFile, $newVersion);
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $newVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to mark player as spoken: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'softReset') {
        try {
            $gameState = [
                'gameStarted' => false,
                'currentQuestion' => 0,
                'phase' => 'waiting',
                'buzzers' => [],
                'answers' => [],
                'spokenPlayers' => [],
                'firstBuzzer' => null,
                'buzzLocked' => false,
                'timestamp' => time()
            ];
            
            safeJsonWrite($gameStateFile, $gameState);
            
            // Increment state version
            $newVersion = $currentStateVersion + 1;
            file_put_contents($stateVersionFile, $newVersion);
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $newVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to soft reset: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'resetGame') {
        try {
            $gameState = [
                'gameStarted' => false,
                'currentQuestion' => 0,
                'phase' => 'waiting',
                'buzzers' => [],
                'answers' => [],
                'spokenPlayers' => [],
                'firstBuzzer' => null,
                'buzzLocked' => false,
                'timestamp' => time()
            ];
            
            safeJsonWrite($gameStateFile, $gameState);
            safeJsonWrite($playersFile, []);
            
            // Increment state version
            $newVersion = $currentStateVersion + 1;
            file_put_contents($stateVersionFile, $newVersion);
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $newVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to reset game: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'getGameData') {
        try {
            // Get current state version
            $currentStateVersion = (int)file_get_contents($stateVersionFile);
            
            // Refresh data to get latest state
            $gameState = safeJsonRead($gameStateFile) ?: [];
            $questions = safeJsonRead($questionsFile) ?: [];
            $players = safeJsonRead($playersFile) ?: [];
            
            // Enhanced answer statistics with better accuracy
            $currQ = isset($gameState['currentQuestion']) ? $gameState['currentQuestion'] : 0;
            $answers = isset($gameState['answers']) ? $gameState['answers'] : [];
            
            // Get unique players who answered this question
            $answeredMap = [];
            foreach ($answers as $ans) {
                if (isset($ans['question']) && $ans['question'] === $currQ && isset($ans['playerId'])) {
                    $answeredMap[$ans['playerId']] = $ans;
                }
            }
            $answeredPlayerIds = array_keys($answeredMap);
            
            // Active players count
            $activePlayers = array_values(array_filter($players, function ($p) {
                return !isset($p['active']) || $p['active'];
            }));
            $activeCount = count($activePlayers);
            $answersCount = count($answeredPlayerIds);
            $allAnswered = ($activeCount > 0) && ($answersCount >= $activeCount);
            
            // Player names for admin display
            $nameById = [];
            foreach ($players as $p) {
                $nameById[$p['id']] = $p['nickname'];
            }
            
            $answeredNames = array_values(array_map(function($id) use ($nameById) {
                return isset($nameById[$id]) ? $nameById[$id] : $id;
            }, $answeredPlayerIds));
            
            $activeIds = array_values(array_map(function($p){ return $p['id']; }, $activePlayers));
            $notAnsweredIds = array_values(array_diff($activeIds, $answeredPlayerIds));
            $notAnsweredNames = array_values(array_map(function($id) use ($nameById) {
                return isset($nameById[$id]) ? $nameById[$id] : $id;
            }, $notAnsweredIds));
            
            // Enhanced payload with debugging info
            $payload = [
                'success'   => true,
                'gameState' => $gameState,
                'questions' => $questions,
                'players'   => $players,
                'stateVersion' => $currentStateVersion,
                'serverTime' => time(),
                'answerStats' => [
                    'currentQuestion'     => $currQ,
                    'answersCount'        => $answersCount,
                    'activeCount'         => $activeCount,
                    'allAnswered'         => $allAnswered,
                    'answeredPlayerIds'   => $answeredPlayerIds,
                    'answeredNames'       => $answeredNames,
                    'notAnsweredPlayerIds'=> $notAnsweredIds,
                    'notAnsweredNames'    => $notAnsweredNames,
                    'answeredDetails'     => array_values($answeredMap)
                ]
            ];
            
            echo json_encode($payload);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to get game data: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'sendMessage') {
        try {
            // Store message for players to see
            $message = [
                'id' => uniqid(),
                'text' => $input['message'],
                'timestamp' => time(),
                'type' => $input['type'] ?? 'info' // 'info', 'warning', 'success'
            ];
            
            $messages = safeJsonRead($messagesFile) ?: [];
            $messages[] = $message;
            
            // Keep only last 10 messages
            $messages = array_slice($messages, -10);
            
            safeJsonWrite($messagesFile, $messages);
            
            // Increment state version
            $newVersion = $currentStateVersion + 1;
            file_put_contents($stateVersionFile, $newVersion);
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $newVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to send message: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'getNotes') {
        try {
            $notes = safeJsonRead($notesFile) ?: [
                'content' => '',
                'updatedAt' => 0
            ];
            
            echo json_encode([
                'success' => true,
                'notes' => $notes
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to get notes: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'saveNotes') {
        try {
            $notes = [
                'content' => $input['content'],
                'updatedAt' => time()
            ];
            
            safeJsonWrite($notesFile, $notes);
            
            // Increment state version for synchronization
            $newVersion = $currentStateVersion + 1;
            file_put_contents($stateVersionFile, $newVersion);
            
            echo json_encode([
                'success' => true,
                'stateVersion' => $newVersion
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to save notes: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'saveSession') {
        try {
            $sessionId = $input['id'] ?? uniqid();
            $sessionName = $input['name'] ?? 'Session ' . date('Y-m-d H:i');
            
            // Get current state
            $gameState = safeJsonRead($gameStateFile) ?: [];
            $questions = safeJsonRead($questionsFile) ?: [];
            $players = safeJsonRead($playersFile) ?: [];
            
            $session = [
                'id' => $sessionId,
                'name' => $sessionName,
                'gameState' => $gameState,
                'questions' => $questions,
                'players' => $players,
                'timestamp' => time()
            ];
            
            safeJsonWrite($sessionsDir . '/' . $sessionId . '.json', $session);
            
            echo json_encode(['success' => true, 'session' => $session]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to save session: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'getSessions') {
        try {
            $sessions = [];
            $files = glob($sessionsDir . '/*.json');
            
            foreach ($files as $file) {
                $content = json_decode(file_get_contents($file), true);
                if ($content) {
                    $sessions[] = [
                        'id' => basename($file, '.json'),
                        'name' => $content['name'] ?? 'Session ' . date('Y-m-d H:i', $content['timestamp']),
                        'timestamp' => $content['timestamp'],
                        'playerCount' => count($content['players'] ?? []),
                        'questionCount' => count($content['questions'] ?? [])
                    ];
                }
            }
            
            // Sort by timestamp (newest first)
            usort($sessions, function($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });
            
            echo json_encode(['success' => true, 'sessions' => $sessions]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to get sessions: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'loadSession') {
        try {
            $sessionId = $input['id'];
            $sessionFile = $sessionsDir . '/' . $sessionId . '.json';
            
            if (file_exists($sessionFile)) {
                $session = json_decode(file_get_contents($sessionFile), true);
                
                // Save session data to current files with safe writing
                safeJsonWrite($gameStateFile, $session['gameState']);
                safeJsonWrite($questionsFile, $session['questions']);
                safeJsonWrite($playersFile, $session['players']);
                
                // Increment state version
                $newVersion = $currentStateVersion + 1;
                file_put_contents($stateVersionFile, $newVersion);
                
                echo json_encode([
                    'success' => true, 
                    'stateVersion' => $newVersion,
                    'session' => $session
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Session not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to load session: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($input['action'] === 'deleteSession') {
        try {
            $sessionId = $input['id'];
            $sessionFile = $sessionsDir . '/' . $sessionId . '.json';
            
            if (file_exists($sessionFile)) {
                unlink($sessionFile);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Session not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to delete session: ' . $e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Admin Dashboard</title>
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
            padding: 1rem;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .dashboard {
            background-color: var(--bg-gray-800);
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header h1 {
            font-size: 2rem;
            font-weight: bold;
            color: var(--cyan-400);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .game-status {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .header-controls {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .status-badge {
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-waiting {
            background-color: var(--bg-gray-700);
            color: var(--text-gray-300);
        }

        .status-active {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--green-500);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-finished {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--red-500);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid var(--bg-gray-700);
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.75rem 1rem;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: var(--transition);
            color: var(--text-gray-400);
        }

        .tab.active {
            color: var(--cyan-400);
            border-bottom-color: var(--cyan-400);
        }

        .btn {
            padding: 0.75rem 1rem;
            border: none;
            border-radius: 0.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn i {
            font-size: 1rem;
        }

        .btn-primary {
            background-color: var(--cyan-600);
            color: var(--text-white);
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

        .btn-danger {
            background-color: var(--red-600);
            color: var(--text-white);
        }

        .btn-danger:hover {
            background-color: var(--red-500);
        }

        .btn-warning {
            background-color: var(--yellow-500);
            color: var(--bg-dark);
        }

        .btn-purple {
            background-color: var(--purple-500);
            color: var(--text-white);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn:disabled:hover {
            transform: none;
            box-shadow: none;
        }

        .content {
            max-height: 70vh;
            overflow-y: auto;
        }

        .question-editor {
            background-color: var(--bg-gray-900);
            padding: 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-white);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-input, .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border-radius: 0.5rem;
            background-color: var(--bg-gray-700);
            border: 1px solid #4b5563;
            color: var(--text-white);
            font-size: 0.875rem;
            font-family: inherit;
            resize: vertical;
            transition: var(--transition);
        }

        .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--cyan-500);
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
        }

        .form-textarea {
            min-height: 200px;
            font-family: 'Monaco', 'Menlo', monospace;
        }

        .game-controls {
            background-color: var(--bg-gray-900);
            padding: 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .current-question {
            background-color: var(--bg-gray-900);
            padding: 1.5rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            transition: var(--transition);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .question-text {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-white);
        }

        .buzzers-section {
            background-color: var(--bg-gray-800);
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }

        .buzzer-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            background-color: var(--bg-gray-700);
            border-radius: 0.375rem;
            transition: var(--transition);
        }

        .buzzer-item:hover {
            transform: translateX(5px);
        }

        .buzzer-player {
            font-weight: 600;
            color: var(--text-white);
        }

        .buzzer-time {
            font-size: 0.75rem;
            color: var(--text-gray-400);
        }

        .player-spoken {
            background-color: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .answers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .answer-summary {
            background-color: var(--bg-gray-900);
            padding: 1rem;
            border-radius: 0.5rem;
        }

        .answer-option {
            font-weight: 600;
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            border-radius: 0.25rem;
        }

        .answer-correct {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--green-500);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .answer-incorrect {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--red-500);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .player-count {
            font-size: 0.875rem;
            color: var(--text-gray-300);
        }

        .hidden {
            display: none;
        }

        .back-link {
            position: fixed;
            top: 1rem;
            left: 1rem;
            background-color: var(--bg-gray-700);
            color: var(--text-gray-400);
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            text-decoration: none;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .back-link:hover {
            background-color: var(--bg-gray-800);
            color: var(--text-white);
        }

        .sample-json {
            background-color: var(--bg-gray-800);
            padding: 1rem;
            border-radius: 0.375rem;
            border-left: 4px solid var(--cyan-400);
            margin-top: 1rem;
        }

        .sample-json pre {
            color: var(--text-gray-300);
            font-size: 0.75rem;
            overflow-x: auto;
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
        }

        .help-panel.active {
            transform: translateY(0);
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

        /* Action feedback */
        .action-feedback {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: var(--bg-gray-800);
            color: var(--text-white);
            padding: 1.5rem 2rem;
            border-radius: 0.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 200;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .action-feedback.active {
            opacity: 1;
            pointer-events: all;
        }

        .action-feedback.success {
            border-left: 4px solid var(--green-500);
        }

        .action-feedback.error {
            border-left: 4px solid var(--red-500);
        }

        .action-feedback i {
            margin-right: 0.5rem;
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
        
        /* Sessions modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
        }

        .modal.hidden {
            display: none;
        }

        .modal-content {
            background-color: var(--bg-gray-800);
            border-radius: 0.5rem;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--bg-gray-700);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--bg-gray-700);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        
        .session-card {
            background-color: var(--bg-gray-900);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid var(--bg-gray-700);
            transition: var(--transition);
        }

        .session-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            border-color: var(--cyan-500);
        }
        
        /* Player list dropdown */
        #playerList {
            position: absolute;
            top: 100%;
            left: 0;
            width: 200px;
            background-color: var(--bg-gray-900);
            border-radius: 0.25rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 10;
            max-height: 300px;
            overflow-y: auto;
            padding: 0.5rem;
        }

        /* Notes Panel Styles */
        .notes-panel {
            position: fixed;
            top: 1rem;
            right: 1rem;
            width: 350px;
            max-height: 80vh;
            z-index: 100;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
        }

        .notes-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background-color: var(--bg-gray-900);
            border-radius: 0.5rem 0.5rem 0 0;
        }

        .notes-content, .notes-editor {
            padding: 1rem;
            background-color: var(--bg-gray-800);
            border-radius: 0 0 0.5rem 0.5rem;
            max-height: calc(80vh - 80px);
            overflow-y: auto;
        }

        .formatting-toolbar {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            background-color: var(--bg-gray-900);
            border-radius: 0.25rem;
            flex-wrap: wrap;
        }

        .formatting-toolbar button {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            background-color: var(--bg-gray-700);
            color: var(--text-gray-300);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .formatting-toolbar button:hover {
            background-color: var(--bg-gray-600);
            color: var(--text-white);
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .game-status {
                width: 100%;
            }
            
            .header-controls {
                width: 100%;
                justify-content: flex-start;
            }
            
            .notes-panel {
                width: 90%;
                right: 5%;
            }
            
            .help-panel {
                width: 90%;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <a href="quiz.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Quiz</a>
    <div class="container">
        <div class="dashboard">
            <div class="header">
                <h1><i class="fas fa-chalkboard-teacher"></i> Quiz Admin Dashboard</h1>
                <div class="game-status">
                    <div class="status-badge" id="gameStatusBadge">
                        <?php if (!$gameState['gameStarted']): ?>
                            <span class="status-waiting">Waiting</span>
                        <?php elseif ($gameState['phase'] === 'finished'): ?>
                            <span class="status-finished">Finished</span>
                        <?php else: ?>
                            <span class="status-active">Active</span>
                        <?php endif; ?>
                    </div>
                    <div class="status-badge" style="cursor: pointer; position: relative;" onclick="togglePlayerList()">
                        <span id="playerCount"><?= count($players) ?></span> Players
                        <i class="fas fa-chevron-down" id="playerListToggle" style="margin-left: 0.5rem; font-size: 0.8rem;"></i>
                        <div id="playerList" class="hidden">
                            <!-- Players will be populated here -->
                        </div>
                    </div>
                </div>
                <div class="header-controls">
                    <button id="notesBtn" class="btn btn-purple">
                        <i class="fas fa-sticky-note"></i> Notes
                    </button>
                    <button id="saveSessionBtn" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Session
                    </button>
                    <button id="loadSessionBtn" class="btn btn-warning">
                        <i class="fas fa-history"></i> Load Session
                    </button>
                    <button id="templateBtn" class="btn btn-purple">
                        <i class="fas fa-th-large"></i> Templates
                    </button>
                </div>
            </div>
            
            <div class="tabs">
                <div class="tab active" onclick="showTab('control', this)">Game Control</div>
                <div class="tab" onclick="showTab('questions', this)">Manage Questions</div>
                <div class="tab" onclick="showTab('results', this)">Results</div>
            </div>

            <!-- Game Control Tab -->
            <div id="controlTab" class="content">
                <div class="game-controls">
                    <h3 style="margin-bottom: 1rem; color: var(--text-white); display: flex; align-items: center; justify-content: space-between;">
                        <span>Game Controls</span>
                        <span class="phase-indicator phase-question" id="phaseIndicator">Question Phase</span>
                    </h3>
                    <div class="progress-container">
                        <div class="progress-bar" id="quizProgress" style="width: <?= $gameState['gameStarted'] && count($questions) > 0 ? min(100, ($gameState['currentQuestion'] / (count($questions) - 1)) * 100) : 0 ?>%"></div>
                    </div>
                    <div id="gameControlButtons">
                        <!-- Buttons will be populated by JavaScript -->
                    </div>
                </div>
                <div id="currentQuestionContainer">
                    <!-- Current question will be populated by JavaScript -->
                </div>
            </div>

            <!-- Questions Management Tab -->
            <div id="questionsTab" class="content hidden">
                <div class="question-editor">
                    <h3 style="color: var(--text-white); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-question-circle"></i> Manage Questions
                    </h3>
                    <div class="form-group">
                        <label class="form-label">
                            Paste Questions JSON:
                            <button class="btn btn-purple btn-sm" onclick="loadSampleQuestions()" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; margin: 0;">
                                <i class="fas fa-rocket"></i> Load Sample
                            </button>
                        </label>
                        <textarea id="questionsJson" class="form-textarea" placeholder="Paste your questions JSON here..."><?= htmlspecialchars(json_encode($questions, JSON_PRETTY_PRINT)) ?></textarea>
                    </div>
                    <button class="btn btn-primary" onclick="updateQuestions()"><i class="fas fa-save"></i> Update Questions</button>
                    <div class="sample-json">
                        <strong><i class="fas fa-info-circle"></i> Sample JSON Format:</strong>
                        <pre>[
  {
    "question": "What is phishing?",
    "options": ["Deceptive emails to steal info", "Fishing for real tuna"],
    "correct": 0,
    "image": "",
    "explanation": "Phishing is a cybersecurity attack where criminals send fake emails..."
  }
]
Note: 
- "correct" = index of right answer (0 = first option, 1 = second option, etc.)
- "image" = optional image URL
- "explanation" = teaching notes for admin (helps ESL teachers explain cybersecurity terms)</pre>
                    </div>
                </div>
            </div>

            <!-- Results Tab -->
            <div id="resultsTab" class="content hidden">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem;">
                    <h3 style="color: var(--text-white);">Results History</h3>
                    <div>
                        <button id="prevQuestionBtn" class="btn btn-sm" style="margin-right: 0.5rem; padding: 0.5rem 1rem; background-color: var(--bg-gray-700); color: var(--text-gray-300);">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <span id="resultsQuestionIndicator" style="color: var(--cyan-400); margin: 0 0.5rem;">Question 1 of 10</span>
                        <button id="nextQuestionBtn" class="btn btn-sm" style="padding: 0.5rem 1rem; background-color: var(--bg-gray-700); color: var(--text-gray-300);">
                            Next <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                <div id="resultsContainer">
                    <!-- Results will be populated here -->
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
            <div class="help-title">Quiz Admin Help</div>
            <button class="help-close" id="helpClose">&times;</button>
        </div>
        <div class="help-content">
            <div class="help-section">
                <h3><i class="fas fa-gamepad"></i> Game Flow</h3>
                <p>1. Start the quiz with the green "Start Quiz" button</p>
                <p>2. Students see the question and can buzz in</p>
                <p>3. Click "Show Answer Options" when ready for students to answer</p>
                <p>4. Click "Reveal Correct Answer" to show the right answer</p>
                <p>5. Click "Next Question" to move forward</p>
                <div class="help-tip">
                    <strong>Pro Tip:</strong> Use "Mark as Spoken" after a student answers to prevent them from buzzing again on the same question.
                </div>
            </div>
            <div class="help-section">
                <h3><i class="fas fa-users"></i> Player Management</h3>
                <p>- Students join by entering their name on the quiz page</p>
                <p>- "Soft Reset" keeps players but resets the game</p>
                <p>- "Full Reset" clears everything including players</p>
                <div class="help-tip">
                    <strong>Issue:</strong> If a student doesn't appear in the buzz list, ask them to refresh their browser and rejoin.
                </div>
            </div>
            <div class="help-section">
                <h3><i class="fas fa-question"></i> Question Management</h3>
                <p>- Paste valid JSON in the editor to update questions</p>
                <p>- Use the sample format as a guide</p>
                <p>- Click "Load Sample" to populate with cybersecurity questions</p>
                <div class="help-tip">
                    <strong>Important:</strong> When questions are updated, answer options are automatically shuffled while keeping the correct answer valid.
                </div>
            </div>
            <div class="help-section">
                <h3><i class="fas fa-bolt"></i> Troubleshooting</h3>
                <p><strong>If the UI doesn't update:</strong> This system automatically handles most state conflicts. Just wait a moment - it will sync.</p>
                <p><strong>If students can't buzz:</strong> Check they're on the quiz page and have a stable connection.</p>
                <p><strong>If answers aren't recording:</strong> Ensure the "Options Shown" phase is active.</p>
            </div>
        </div>
    </div>

    <!-- Network Status -->
    <div class="network-status" id="networkStatus">
        <i class="fas fa-circle-notch fa-spin"></i> Syncing with server...
    </div>

    <!-- Message System -->
    <div class="message-system" id="messageSystem"></div>

    <!-- Action Feedback -->
    <div class="action-feedback" id="actionFeedback"></div>

    <!-- Notes Panel -->
    <div id="notesPanel" class="notes-panel" style="display: none;">
        <div class="notes-header">
            <h3 style="color: var(--cyan-400); display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-sticky-note"></i> Class Notes
            </h3>
            <div>
                <button id="toggleNotesEdit" class="btn btn-sm" style="padding: 0.25rem 0.5rem; font-size: 0.875rem; margin-right: 0.5rem; background-color: var(--bg-gray-700); color: var(--text-gray-300);">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button id="exportNotesPdf" class="btn btn-sm" style="padding: 0.25rem 0.5rem; font-size: 0.875rem; background-color: var(--bg-gray-700); color: var(--text-gray-300);">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
                <button id="closeNotes" style="background: none; border: none; color: var(--text-gray-400); font-size: 1.25rem; cursor: pointer;">&times;</button>
            </div>
        </div>
        <div id="notesContent" class="notes-content"></div>
        <div id="notesEditor" class="notes-editor hidden">
            <!-- Formatting Toolbar -->
            <div class="formatting-toolbar">
                <button id="insertLinkBtn" title="Insert Link">
                    <i class="fas fa-link"></i> Link
                </button>
                <button id="boldBtn" title="Bold Text">
                    <i class="fas fa-bold"></i>
                </button>
                <button id="italicBtn" title="Italic Text">
                    <i class="fas fa-italic"></i>
                </button>
                <button id="bulletBtn" title="Bullet Point">
                    <i class="fas fa-list-ul"></i>
                </button>
                <button id="headingBtn" title="Heading">
                    <i class="fas fa-heading"></i>
                </button>
                <div style="border-left: 1px solid var(--bg-gray-700); margin: 0 0.5rem;"></div>
                <span style="color: var(--text-gray-400); font-size: 0.75rem; align-self: center;">Quick Format Tools</span>
            </div>
            
            <textarea id="notesTextarea" class="form-textarea" style="min-height: 200px; width: 100%;"></textarea>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.5rem;">
                <span style="color: var(--text-gray-400); font-size: 0.75rem;">💡 Tip: Use toolbar for quick formatting or type markdown directly</span>
                <button id="saveNotes" class="btn btn-primary" style="padding: 0.5rem 1rem;">
                    <i class="fas fa-save"></i> Save Notes
                </button>
            </div>
        </div>
    </div>

    <!-- Sessions Modal -->
    <div id="sessionsModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Saved Sessions</h2>
                <button class="close">&times;</button>
            </div>
            <div class="modal-body" id="sessionsList">
                <!-- Sessions will be loaded here -->
            </div>
            <div class="modal-footer">
                <button id="closeSessionsModal" class="btn btn-danger">Close</button>
            </div>
        </div>
    </div>

    <!-- PDF Export Dependencies -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <!-- html2canvas no longer needed - using text-based PDF generation for better text selection -->
    <!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script> -->
    
    <script>
        // Enhanced Admin Dashboard with Critical Bug Fixes
        let currentTab = 'control';
        let gameData = null;
        let currentStateVersion = 0;
        let lastSuccessfulFetch = null;
        let reconnectAttempts = 0;
        let maxReconnectAttempts = 5;
        let reconnectTimeout = null;
        let updateInterval = null;
        let lastNetworkStatus = 'online';

        // DOM elements
        const helpToggle = document.getElementById('helpToggle');
        const helpPanel = document.getElementById('helpPanel');
        const helpClose = document.getElementById('helpClose');
        const networkStatus = document.getElementById('networkStatus');
        const messageSystem = document.getElementById('messageSystem');
        const actionFeedback = document.getElementById('actionFeedback');
        const phaseIndicator = document.getElementById('phaseIndicator');

        // Notes functionality
        let isNotesEditing = false;
        let currentNotesContent = '';
        const notesPanel = document.getElementById('notesPanel');
        const notesContent = document.getElementById('notesContent');
        const notesEditor = document.getElementById('notesEditor');
        const notesTextarea = document.getElementById('notesTextarea');
        const toggleNotesEdit = document.getElementById('toggleNotesEdit');
        const saveNotes = document.getElementById('saveNotes');
        const exportNotesPdf = document.getElementById('exportNotesPdf');
        const closeNotes = document.getElementById('closeNotes');

        // Sessions functionality
        let currentResultsQuestion = 0;

        document.addEventListener('DOMContentLoaded', function() {
            initializeEventListeners();
            startAutoUpdate();
            updateGameData();
            loadMessages();
            fetchNotes();
        });

        function initializeEventListeners() {
            // Header button listeners
            document.getElementById('notesBtn').addEventListener('click', () => {
                notesPanel.style.display = notesPanel.style.display === 'block' ? 'none' : 'block';
                if (notesPanel.style.display === 'block') {
                    fetchNotes();
                }
            });

            document.getElementById('saveSessionBtn').addEventListener('click', saveCurrentSession);
            document.getElementById('loadSessionBtn').addEventListener('click', showSessionsModal);
            document.getElementById('templateBtn').addEventListener('click', () => {
                window.open('template_manager.php', 'templateManager', 'width=1200,height=800,scrollbars=yes,resizable=yes');
            });

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
            toggleNotesEdit.addEventListener('click', toggleNotesEditMode);
            saveNotes.addEventListener('click', saveNotesContent);
            exportNotesPdf.addEventListener('click', exportNotesToPdf);
            closeNotes.addEventListener('click', () => {
                notesPanel.style.display = 'none';
            });

            // Notes toolbar event listeners
            document.getElementById('insertLinkBtn').addEventListener('click', insertLink);
            document.getElementById('boldBtn').addEventListener('click', () => insertMarkdown('**', '**', 'bold text'));
            document.getElementById('italicBtn').addEventListener('click', () => insertMarkdown('*', '*', 'italic text'));
            document.getElementById('bulletBtn').addEventListener('click', insertBulletPoint);
            document.getElementById('headingBtn').addEventListener('click', insertHeading);

            // Results navigation
            document.getElementById('prevQuestionBtn').addEventListener('click', () => {
                if (currentResultsQuestion > 0) {
                    currentResultsQuestion--;
                    updateResults();
                }
            });
            document.getElementById('nextQuestionBtn').addEventListener('click', () => {
                if (gameData && gameData.questions && currentResultsQuestion < gameData.questions.length - 1) {
                    currentResultsQuestion++;
                    updateResults();
                }
            });

            // Sessions modal
            document.querySelector('#sessionsModal .close').addEventListener('click', () => {
                document.getElementById('sessionsModal').classList.add('hidden');
            });
            document.getElementById('closeSessionsModal').addEventListener('click', () => {
                document.getElementById('sessionsModal').classList.add('hidden');
            });

            // Template manager message listener
            window.addEventListener('message', function(event) {
                if (event.data && event.data.type === 'loadTemplate') {
                    document.getElementById('questionsJson').value = 
                        JSON.stringify(event.data.questions, null, 2);
                    
                    updateQuestions();
                    showActionFeedback('success', 'Template loaded and applied successfully!');
                    showTab('control');
                }
            });
        }

        function updateControlButtons() {
            const buttonsContainer = document.getElementById('gameControlButtons');
            if (!buttonsContainer || !gameData || !gameData.gameState) return;
            
            const { gameState } = gameData;
            let buttonsHTML = '';

            if (!gameState.gameStarted || gameState.phase === 'finished') {
                buttonsHTML = `
                    <button class="btn btn-success" onclick="startGame()"><i class="fas fa-play"></i> Start Quiz</button>
                    <button class="btn btn-primary" onclick="softReset()"><i class="fas fa-sync-alt"></i> Soft Reset</button>
                    <button class="btn btn-danger" onclick="resetGame()"><i class="fas fa-trash"></i> Full Reset</button>
                `;
            } else {
                const isQuestionPhase = gameState.phase === 'question_shown';
                const isOptionsPhase = gameState.phase === 'options_shown';
                const isRevealPhase = gameState.phase === 'reveal';

                buttonsHTML = `
                    <button class="btn btn-primary" onclick="nextQuestion()"><i class="fas fa-arrow-right"></i> Next Question</button>
                    <button class="btn btn-warning" onclick="showOptions()" ${!isQuestionPhase ? 'disabled title="Only available during the Question phase"' : ''}>
                        <i class="fas fa-list"></i> Show Options
                    </button>
                    <button class="btn btn-purple" onclick="revealCorrect()" ${isRevealPhase ? 'disabled title="Answer already revealed"' : ''}>
                        <i class="fas fa-eye"></i> Reveal Answer
                    </button>
                    <button class="btn btn-primary" onclick="softReset()"><i class="fas fa-sync-alt"></i> Soft Reset</button>
                    <button class="btn btn-danger" onclick="resetGame()"><i class="fas fa-trash"></i> Full Reset</button>
                `;
            }
            buttonsContainer.innerHTML = buttonsHTML;
        }

        function startAutoUpdate() {
            if (updateInterval) {
                clearInterval(updateInterval);
            }
            updateInterval = setInterval(() => {
                if (!helpPanel.classList.contains('active')) {
                    updateGameData();
                }
            }, 2000);
        }

        async function updateGameData() {
            try {
                const cacheBuster = new Date().getTime();
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ 
                        action: 'getGameData',
                        cacheBuster: cacheBuster,
                        currentStateVersion: currentStateVersion
                    }),
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error(`Network error: ${response.status}`);
                }

                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    
                    if (!lastSuccessfulFetch || 
                        JSON.stringify(lastSuccessfulFetch) !== JSON.stringify(result)) {
                        gameData = result;
                        lastSuccessfulFetch = {...result};
                        reconnectAttempts = 0;
                        updateUI();
                        
                        if (lastNetworkStatus !== 'online') {
                            setNetworkStatus('online', 'Connected to server');
                        }
                    }
                } else {
                    console.error('Server reported error:', result.error);
                    showActionFeedback('error', 'Server error: ' + (result.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error updating game data:', error);
                
                if (lastNetworkStatus === 'online') {
                    setNetworkStatus('offline', 'Connection lost. Trying to reconnect...');
                }
                
                reconnectAttempts++;
                
                if (reconnectAttempts <= maxReconnectAttempts) {
                    const delay = Math.min(1000 * Math.pow(2, reconnectAttempts), 10000);
                    clearTimeout(reconnectTimeout);
                    reconnectTimeout = setTimeout(updateGameData, delay);
                } else {
                    setNetworkStatus('offline', `Cannot connect after ${maxReconnectAttempts} attempts. Please refresh.`);
                }
            }
        }

        function updateUI() {
            updateGameStatus();
            updatePlayerCount();
            updateCurrentQuestion();
            updateControlButtons();
            updateBuzzers();
            updateResults();
            updatePhaseIndicator();
            
            if (!document.getElementById('playerList').classList.contains('hidden')) {
                renderPlayerList();
            }
        }

        function updatePhaseIndicator() {
            if (!gameData || !gameData.gameState) return;
            const phase = gameData.gameState.phase;
            let text, className;
            
            switch(phase) {
                case 'question_shown':
                    text = 'Question Phase';
                    className = 'phase-question';
                    break;
                case 'options_shown':
                    text = 'Answer Phase';
                    className = 'phase-options';
                    break;
                case 'reveal':
                    text = 'Reveal Phase';
                    className = 'phase-reveal';
                    break;
                default:
                    text = 'Waiting';
                    className = 'phase-question';
            }
            phaseIndicator.textContent = text;
            phaseIndicator.className = 'phase-indicator ' + className;
        }

        function updateGameStatus() {
            const badge = document.getElementById('gameStatusBadge');
            const { gameState } = gameData;
            
            if (!gameState.gameStarted) {
                badge.innerHTML = '<span class="status-waiting">Waiting</span>';
            } else if (gameState.phase === 'finished') {
                badge.innerHTML = '<span class="status-finished">Finished</span>';
            } else {
                badge.innerHTML = '<span class="status-active">Active</span>';
            }

            if (gameState.gameStarted && gameData.questions && gameData.questions.length > 0) {
                // Fix division by zero for single-question quizzes
                const progress = gameData.questions.length > 1 
                    ? Math.min(100, (gameState.currentQuestion / (gameData.questions.length - 1)) * 100)
                    : (gameState.currentQuestion === 0 ? 0 : 100);
                document.getElementById('quizProgress').style.width = `${progress}%`;
            }
        }

        function updatePlayerCount() {
            document.getElementById('playerCount').textContent = gameData.players.length;
        }

        // Cache to prevent unnecessary DOM updates
        let lastQuestionState = null;
        function updateCurrentQuestion() {
            const container = document.getElementById('currentQuestionContainer');
            const { gameState, questions, answerStats } = gameData;
            
            // Create state signature to detect changes
            const currentQuestionState = {
                gameStarted: gameState.gameStarted,
                currentQuestion: gameState.currentQuestion,
                phase: gameState.phase,
                questionsLength: questions.length,
                answerStats: answerStats ? {
                    answersCount: answerStats.answersCount,
                    activeCount: answerStats.activeCount,
                    allAnswered: answerStats.allAnswered,
                    notAnsweredCount: answerStats.notAnsweredPlayerIds ? answerStats.notAnsweredPlayerIds.length : 0
                } : null
            };

            // Only update if something changed
            if (lastQuestionState && 
                JSON.stringify(currentQuestionState) === JSON.stringify(lastQuestionState)) {
                return;
            }

            lastQuestionState = JSON.parse(JSON.stringify(currentQuestionState));

            if (!gameState.gameStarted) {
                container.innerHTML = `
                    <div class="current-question" style="text-align: center; padding: 2rem; color: var(--text-gray-400);">
                        <i class="fas fa-info-circle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                        <h3 style="margin-bottom: 1rem;">Quiz Not Started</h3>
                        <p>Click "Start Quiz" to begin the game</p>
                    </div>
                `;
                return;
            }

            const currentQuestion = questions[gameState.currentQuestion];
            if (!currentQuestion) {
                container.innerHTML = `
                    <div class="current-question" style="text-align: center; padding: 2rem; color: var(--text-gray-400);">
                        <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                        <h3 style="margin-bottom: 1rem;">No Questions Available</h3>
                        <p>Please add questions in the "Manage Questions" tab</p>
                    </div>
                `;
                return;
            }

            // Enhanced answer statistics display
            const answerStatsHtml = answerStats ? `
                <div style="margin-bottom: 1rem; padding: 0.75rem; background-color: var(--bg-gray-900); border-radius: 0.5rem; border: 2px solid ${answerStats.allAnswered ? 'var(--green-500)' : 'var(--cyan-400)'};">
                    <strong style="color: var(--text-white);">Answer Progress:</strong>
                    <span style="color: var(--cyan-400); margin-left: 0.5rem;">${answerStats.answersCount} / ${answerStats.activeCount}</span>
                    ${answerStats.allAnswered ? '<span style="color: var(--green-500); margin-left: 1rem;"><i class="fas fa-check-circle"></i> All answered!</span>' : ''}
                    ${answerStats.notAnsweredNames && answerStats.notAnsweredNames.length > 0 ? `
                        <div style="margin-top: 0.5rem; color: var(--text-gray-300); font-size: 0.875rem;">
                            <i class="fas fa-clock"></i> Still waiting: ${answerStats.notAnsweredNames.join(', ')}
                        </div>
                    ` : ''}
                </div>
            ` : '';

            container.innerHTML = `
                <div class="current-question">
                    <h3 style="color: var(--text-white); margin-bottom: 1rem;">
                        Current Question: ${gameState.currentQuestion + 1} of ${questions.length}
                    </h3>
                    <div class="question-text">
                        ${currentQuestion.question}
                    </div>
                    ${currentQuestion.image ? `
                        <img src="${currentQuestion.image}" alt="Question visual" style="max-width: 100%; max-height: 200px; border-radius: 0.5rem; margin: 1rem 0;">
                    ` : ''}
                    ${currentQuestion.explanation ? `
                        <div style="background-color: var(--bg-gray-700); padding: 1rem; border-radius: 0.5rem; margin: 1rem 0; border-left: 4px solid var(--cyan-400);">
                            <strong style="color: var(--cyan-400);"><i class="fas fa-book"></i> Teaching Explanation:</strong>
                            <div style="color: var(--text-gray-300); margin-top: 0.5rem;">${currentQuestion.explanation}</div>
                        </div>
                    ` : ''}
                    <div style="margin-bottom: 1rem; display: flex; align-items: center;">
                        <strong>Current Phase:</strong> 
                        <span style="color: var(--cyan-400); display: flex; align-items: center; gap: 0.5rem; margin-left: 0.5rem;">
                            ${gameState.phase === 'question_shown' ? '<i class="fas fa-microphone"></i> Question Shown - Buzzers Active' : 
                              gameState.phase === 'options_shown' ? '<i class="fas fa-list"></i> Answer Options Shown - Waiting for Responses' : 
                              '<i class="fas fa-eye"></i> Correct Answer Revealed'}
                        </span>
                    </div>
                    ${gameState.phase === 'options_shown' ? answerStatsHtml : ''}
                    ${gameState.phase === 'question_shown' ? `
                        <div style="margin-bottom: 1rem; padding: 0.75rem; background-color: rgba(245, 158, 11, 0.1); border-radius: 0.5rem; border: 1px solid rgba(245, 158, 11, 0.3);">
                            <strong style="color: var(--yellow-500);"><i class="fas fa-lightbulb"></i> Next Step:</strong>
                            <span style="color: var(--text-gray-300);"> Wait for students to buzz, then click "Show Answer Options"</span>
                        </div>
                    ` : ''}
                    ${gameState.phase === 'options_shown' && answerStats && answerStats.allAnswered ? `
                        <div style="margin-bottom: 1rem; padding: 0.75rem; background-color: rgba(16, 185, 129, 0.1); border-radius: 0.5rem; border: 1px solid rgba(16, 185, 129, 0.3);">
                            <strong style="color: var(--green-500);"><i class="fas fa-check-circle"></i> Ready!</strong>
                            <span style="color: var(--text-gray-300);"> All students have answered. Click "Next Question" when ready.</span>
                        </div>
                    ` : gameState.phase === 'options_shown' ? `
                        <div style="margin-bottom: 1rem; padding: 0.75rem; background-color: rgba(16, 185, 129, 0.1); border-radius: 0.5rem; border: 1px solid rgba(16, 185, 129, 0.3);">
                            <strong style="color: var(--green-500);"><i class="fas fa-sync-alt"></i> In Progress:</strong>
                            <span style="color: var(--text-gray-300);"> Students are answering. Click "Next Question" when ready.</span>
                        </div>
                    ` : ''}
                    ${gameState.phase === 'reveal' ? `
                        <div style="margin-bottom: 1rem; padding: 0.75rem; background-color: rgba(16, 185, 129, 0.1); border-radius: 0.5rem; border: 1px solid rgba(16, 185, 129, 0.3);">
                            <strong style="color: var(--green-500);"><i class="fas fa-eye"></i> Answer Revealed:</strong>
                            <div style="color: var(--text-gray-300); margin-top: 0.5rem;">
                                The correct answer is: 
                                <strong style="color: var(--green-500);">${currentQuestion.options[currentQuestion.correct]}</strong>
                            </div>
                        </div>
                    ` : ''}
                    <!-- Buzzers Section -->
                    <div class="buzzers-section">
                        <h4 style="margin-bottom: 0.5rem; color: var(--text-white); display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-bullhorn"></i> Players Who Buzzed
                            <span style="font-size: 0.875rem; color: var(--text-gray-400);">(Click to mark as spoken)</span>
                        </h4>
                        <div id="buzzersContainer">
                            <!-- Buzzers will be populated here -->
                        </div>
                    </div>
                </div>
            `;
        }

        function updateBuzzers() {
            const container = document.getElementById('buzzersContainer');
            if (!container) return;
            
            const { gameState, players } = gameData;
            const currentQuestionBuzzers = gameState.buzzers.filter(
                buzzer => buzzer.question === gameState.currentQuestion
            );
            
            container.innerHTML = '';
            
            if (currentQuestionBuzzers.length === 0) {
                container.innerHTML = '<p style="color: var(--text-gray-400); font-style: italic; display: flex; align-items: center; gap: 0.5rem;"><i class="fas fa-inbox"></i> No buzzers yet</p>';
                return;
            }

            // Sort by timestamp
            currentQuestionBuzzers.sort((a, b) => a.timestamp - b.timestamp);
            
            currentQuestionBuzzers.forEach((buzzer, index) => {
                const buzzerItem = document.createElement('div');
                const playerKey = gameState.currentQuestion + '_' + buzzer.playerId;
                const hasSpoken = gameState.spokenPlayers && gameState.spokenPlayers.includes(playerKey);
                
                buzzerItem.className = `buzzer-item ${hasSpoken ? 'player-spoken' : ''}`;
                
                // Format timestamp
                const secondsAgo = Math.floor((Date.now()/1000 - buzzer.timestamp));
                const timeDisplay = secondsAgo < 60 ? 
                    `${secondsAgo} sec ago` : 
                    `${Math.floor(secondsAgo/60)} min ago`;
                
                buzzerItem.innerHTML = `
                    <div>
                        <span class="buzzer-player">${index + 1}. ${buzzer.nickname}</span>
                        <div class="buzzer-time" style="display: flex; align-items: center; gap: 0.25rem;">
                            <i class="fas fa-clock"></i> ${timeDisplay}
                        </div>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="markSpoken('${buzzer.playerId}')" 
                                ${hasSpoken ? 'disabled' : ''}>
                            ${hasSpoken ? '<i class="fas fa-check"></i> Spoken' : '<i class="fas fa-microphone"></i> Mark as Spoken'}
                        </button>
                    </div>
                `;
                container.appendChild(buzzerItem);
            });
        }

        function updateResults() {
            if (currentTab !== 'results') return;
            
            const container = document.getElementById('resultsContainer');
            const { gameState, questions, players } = gameData;
            
            // Update question indicator
            if (questions && questions.length > 0) {
                document.getElementById('resultsQuestionIndicator').textContent = 
                    `Question ${currentResultsQuestion + 1} of ${questions.length}`;
                
                document.getElementById('prevQuestionBtn').disabled = (currentResultsQuestion === 0);
                document.getElementById('nextQuestionBtn').disabled = (currentResultsQuestion === questions.length - 1);
            }

            if (!questions || questions.length === 0) {
                container.innerHTML = '<p style="color: var(--text-gray-400); display: flex; align-items: center; gap: 0.5rem;"><i class="fas fa-info-circle"></i> No questions available</p>';
                return;
            }

            const question = questions[currentResultsQuestion];
            const questionAnswers = gameState.answers.filter(answer => 
                answer.question === currentResultsQuestion
            );

            container.innerHTML = '';
            const resultDiv = document.createElement('div');
            resultDiv.className = 'answer-summary';

            const answerCounts = {};
            question.options.forEach((option, index) => {
                answerCounts[index] = 0;
            });

            questionAnswers.forEach(answer => {
                if (answerCounts.hasOwnProperty(answer.answer)) {
                    answerCounts[answer.answer]++;
                }
            });

            // Get who answered correctly/incorrectly
            const correctPlayers = [];
            const incorrectPlayers = [];
            
            players.forEach(player => {
                const playerAnswer = questionAnswers.find(a => 
                    a.playerId === player.id && a.question === currentResultsQuestion
                );
                if (playerAnswer) {
                    const isCorrect = playerAnswer.answer === question.correct;
                    const playerInfo = {
                        name: player.nickname,
                        answer: question.options[playerAnswer.answer],
                        isCorrect: isCorrect
                    };
                    if (isCorrect) {
                        correctPlayers.push(playerInfo);
                    } else {
                        incorrectPlayers.push(playerInfo);
                    }
                }
            });

            resultDiv.innerHTML = `
                <h4 style="margin-bottom: 1rem; color: var(--text-white); display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-question-circle"></i> ${question.question}
                </h4>
                <div class="answers-grid">
                    ${question.options.map((option, index) => `
                        <div class="answer-option ${index === question.correct ? 'answer-correct' : 'answer-incorrect'}">
                            ${option}
                            <div class="player-count" style="display: flex; align-items: center; gap: 0.25rem; margin-top: 0.25rem;">
                                <i class="fas ${index === question.correct ? 'fa-check' : 'fa-times'}"></i>
                                ${answerCounts[index]} ${answerCounts[index] === 1 ? 'player' : 'players'}
                            </div>
                        </div>
                    `).join('')}
                </div>
                <div style="margin-top: 1.5rem; padding: 1rem; background-color: var(--bg-gray-900); border-radius: 0.5rem;">
                    <h4 style="color: var(--cyan-400); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-users"></i> Participant Responses
                    </h4>
                    ${correctPlayers.length > 0 ? `
                    <div style="margin-bottom: 1rem;">
                        <h5 style="color: var(--green-500); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-check-circle"></i> Correct Answers (${correctPlayers.length})
                        </h5>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                            ${correctPlayers.map(p => `
                                <span style="background-color: rgba(16, 185, 129, 0.1); color: var(--green-500); padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.875rem;">
                                    ${p.name}
                                </span>
                            `).join('')}
                        </div>
                    </div>
                    ` : ''}
                    ${incorrectPlayers.length > 0 ? `
                    <div>
                        <h5 style="color: var(--red-500); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-times-circle"></i> Incorrect Answers (${incorrectPlayers.length})
                        </h5>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                            ${incorrectPlayers.map(p => `
                                <span style="background-color: rgba(239, 68, 68, 0.1); color: var(--red-500); padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.875rem;">
                                    ${p.name}: ${p.answer}
                                </span>
                            `).join('')}
                        </div>
                    </div>
                    ` : ''}
                    ${players.length > questionAnswers.length ? `
                    <div style="margin-top: 1rem; color: var(--text-gray-400); display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-clock"></i> ${players.length - questionAnswers.length} players haven't answered yet
                    </div>
                    ` : ''}
                </div>
                ${question.explanation ? `
                <div style="margin-top: 1.5rem; background-color: var(--bg-gray-700); padding: 1rem; border-radius: 0.5rem; border-left: 4px solid var(--cyan-400);">
                    <h4 style="color: var(--cyan-400); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-book"></i> Teaching Explanation
                    </h4>
                    <p style="color: var(--text-gray-300); line-height: 1.5;">${question.explanation}</p>
                </div>
                ` : ''}
            `;
            container.appendChild(resultDiv);
        }

        // Game control functions with enhanced error handling
        async function startGame() {
            try {
                showActionFeedback('success', 'Starting quiz...', false);
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'startGame',
                        currentStateVersion: currentStateVersion
                    })
                });
                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    showActionFeedback('success', 'Quiz started successfully!');
                    updateGameData();
                } else if (result.error && result.error.includes('State conflict')) {
                    await updateGameData();
                    setTimeout(startGame, 500);
                } else {
                    throw new Error(result.error || 'Failed to start game');
                }
            } catch (error) {
                console.error('Error starting game:', error);
                showActionFeedback('error', 'Error starting game: ' + error.message);
            }
        }

        async function nextQuestion() {
            try {
                showActionFeedback('success', 'Advancing to next question...', false);
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'nextQuestion', 
                        currentStateVersion: currentStateVersion
                    })
                });
                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    showActionFeedback('success', 'Moved to next question!');
                    updateGameData();
                } else if (result.error && result.error.includes('State conflict')) {
                    await updateGameData();
                    setTimeout(nextQuestion, 500);
                } else {
                    throw new Error(result.error || 'Failed to advance question');
                }
            } catch (error) {
                console.error('Error advancing question:', error);
                showActionFeedback('error', 'Error advancing question: ' + error.message);
            }
        }

        async function showOptions() {
            try {
                showActionFeedback('success', 'Showing answer options...', false);
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'showOptions',
                        currentStateVersion: currentStateVersion
                    })
                });
                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    showActionFeedback('success', 'Answer options shown to students!');
                    updateGameData();
                } else if (result.error && result.error.includes('State conflict')) {
                    await updateGameData();
                    setTimeout(showOptions, 500);
                } else {
                    throw new Error(result.error || 'Failed to show options');
                }
            } catch (error) {
                console.error('Error showing options:', error);
                showActionFeedback('error', 'Error showing options: ' + error.message);
            }
        }

        async function revealCorrect() {
            try {
                showActionFeedback('success', 'Revealing correct answer...', false);
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'revealCorrect',
                        currentStateVersion: currentStateVersion
                    })
                });
                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    showActionFeedback('success', 'Correct answer revealed!');
                    updateGameData();
                } else if (result.error && result.error.includes('State conflict')) {
                    await updateGameData();
                    setTimeout(revealCorrect, 500);
                } else {
                    throw new Error(result.error || 'Failed to reveal answer');
                }
            } catch (error) {
                console.error('Error revealing answer:', error);
                showActionFeedback('error', 'Error revealing answer: ' + error.message);
            }
        }

        async function markSpoken(playerId) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'markSpoken', 
                        playerId: playerId,
                        currentStateVersion: currentStateVersion
                    })
                });
                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    showActionFeedback('success', 'Player marked as spoken');
                    updateGameData();
                } else if (result.error && result.error.includes('State conflict')) {
                    await updateGameData();
                    setTimeout(() => markSpoken(playerId), 500);
                } else {
                    throw new Error(result.error || 'Failed to mark player as spoken');
                }
            } catch (error) {
                console.error('Error marking player as spoken:', error);
                showActionFeedback('error', 'Error marking player as spoken: ' + error.message);
            }
        }

        async function softReset() {
            if (!confirm('Soft reset will start a new round but keep all players. Continue?')) {
                return;
            }
            try {
                showActionFeedback('success', 'Resetting game...', false);
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'softReset',
                        currentStateVersion: currentStateVersion
                    })
                });
                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    showActionFeedback('success', 'Game soft reset successfully - players retained');
                    updateGameData();
                } else if (result.error && result.error.includes('State conflict')) {
                    await updateGameData();
                    setTimeout(softReset, 500);
                } else {
                    throw new Error(result.error || 'Failed to soft reset game');
                }
            } catch (error) {
                console.error('Error soft resetting game:', error);
                showActionFeedback('error', 'Error soft resetting game: ' + error.message);
            }
        }

        async function resetGame() {
            if (!confirm('Full reset will clear all progress and remove all players. Continue?')) {
                return;
            }
            try {
                showActionFeedback('success', 'Resetting game...', false);
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'resetGame',
                        currentStateVersion: currentStateVersion
                    })
                });
                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    showActionFeedback('success', 'Game reset successfully');
                    updateGameData();
                } else if (result.error && result.error.includes('State conflict')) {
                    await updateGameData();
                    setTimeout(resetGame, 500);
                } else {
                    throw new Error(result.error || 'Failed to reset game');
                }
            } catch (error) {
                console.error('Error resetting game:', error);
                showActionFeedback('error', 'Error resetting game: ' + error.message);
            }
        }

        async function updateQuestions() {
            const questionsJson = document.getElementById('questionsJson').value;
            try {
                // Validate JSON first
                JSON.parse(questionsJson);
                showActionFeedback('success', 'Updating questions...', false);
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'updateQuestions', 
                        questionsJson: questionsJson,
                        currentStateVersion: currentStateVersion
                    })
                });
                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    showActionFeedback('success', 'Questions updated successfully!');
                    updateGameData();
                } else if (result.error && result.error.includes('State conflict')) {
                    await updateGameData();
                    setTimeout(updateQuestions, 500);
                } else {
                    throw new Error(result.error || 'Failed to update questions');
                }
            } catch (error) {
                console.error('Error updating questions:', error);
                showActionFeedback('error', 'Invalid JSON format: ' + error.message);
            }
        }

        function loadSampleQuestions() {
            const sampleQuestions = [
                {
                    "question": "What is phishing?",
                    "options": [
                        "Deceptive emails or sites that try to steal information like logins.",
                        "Fishing for real tuna with enterprise-grade hooks."
                    ],
                    "correct": 0,
                    "image": "",
                    "explanation": "Phishing is a cybersecurity attack where criminals send fake emails, texts, or create fake websites that look legitimate to trick people into giving away sensitive information like passwords, credit card numbers, or personal details."
                },
                {
                    "question": "What is multi-factor authentication (MFA)?",
                    "options": [
                        "An extra login factor (e.g., app code, key) to protect accounts if passwords leak.",
                        "Asking a colleague to say \"please\" twice before logging in."
                    ],
                    "correct": 0,
                    "image": "",
                    "explanation": "MFA adds extra security layers beyond just a password. Even if someone steals your password, they still need the second factor - like a code from your phone app, a text message, or a physical security key."
                }
            ];
            document.getElementById('questionsJson').value = JSON.stringify(sampleQuestions, null, 2);
            showActionFeedback('success', 'Sample questions loaded!');
        }

        function showTab(tabName, clickedElement = null) {
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            if (clickedElement) {
                clickedElement.classList.add('active');
            } else {
                const tabs = document.querySelectorAll('.tab');
                tabs.forEach((tab, index) => {
                    if ((tabName === 'control' && index === 0) ||
                        (tabName === 'questions' && index === 1) ||
                        (tabName === 'results' && index === 2)) {
                        tab.classList.add('active');
                    }
                });
            }
            
            // Show/hide content
            document.getElementById('controlTab').classList.toggle('hidden', tabName !== 'control');
            document.getElementById('questionsTab').classList.toggle('hidden', tabName !== 'questions');
            document.getElementById('resultsTab').classList.toggle('hidden', tabName !== 'results');
            currentTab = tabName;
            
            if (tabName === 'results') {
                updateResults();
            }
        }

        // Message system functions
        async function sendMessage(type, message) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'sendMessage',
                        type: type,
                        message: message,
                        currentStateVersion: currentStateVersion
                    })
                });
                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    showActionFeedback('success', 'Message sent to students!');
                }
            } catch (error) {
                console.error('Error sending message:', error);
                showActionFeedback('error', 'Error sending message');
            }
        }

        function loadMessages() {
            setInterval(() => {
                if (Math.random() > 0.7) {
                    addMessage(
                        'info', 
                        `Did you know? ${['Phishing attacks increased by 61% in 2023', '81% of data breaches involve weak or stolen passwords', 'The first computer virus was created in 1986'][Math.floor(Math.random() * 3)]}`
                    );
                }
            }, 30000);
        }

        function addMessage(type, text) {
            const messageEl = document.createElement('div');
            messageEl.className = `message ${type}`;
            messageEl.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas ${type === 'info' ? 'fa-info-circle' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-check-circle'}"></i>
                    <span>${text}</span>
                </div>
            `;
            messageSystem.appendChild(messageEl);
            
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

        // Network status handling
        function setNetworkStatus(status, message) {
            if (status === lastNetworkStatus && status === 'online') return;
            networkStatus.textContent = message || (status === 'online' ? 
                'Connected to server' : 'Connection lost. Reconnecting...');
            networkStatus.className = 'network-status ' + status + ' active';
            setTimeout(() => {
                networkStatus.classList.remove('active');
            }, 3000);
            lastNetworkStatus = status;
        }

        // Action feedback system
        function showActionFeedback(type, message, autoHide = true) {
            actionFeedback.className = `action-feedback ${type} active`;
            actionFeedback.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                ${message}
            `;
            if (autoHide) {
                setTimeout(() => {
                    actionFeedback.classList.remove('active');
                }, 3000);
            }
        }

        // Notes functionality
        async function fetchNotes() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'getNotes' })
                });
                const result = await response.json();
                if (result.success) {
                    currentNotesContent = result.notes.content;
                    renderMarkdown(currentNotesContent);
                }
            } catch (error) {
                console.error('Error fetching notes:', error);
            }
        }

        function toggleNotesEditMode() {
            isNotesEditing = !isNotesEditing;
            if (isNotesEditing) {
                notesContent.classList.add('hidden');
                notesEditor.classList.remove('hidden');
                notesTextarea.value = currentNotesContent;
                toggleNotesEdit.innerHTML = '<i class="fas fa-eye"></i> Preview';
            } else {
                notesContent.classList.remove('hidden');
                notesEditor.classList.add('hidden');
                toggleNotesEdit.innerHTML = '<i class="fas fa-edit"></i> Edit';
                renderMarkdown(notesTextarea.value);
            }
        }

        async function saveNotesContent() {
            const newContent = notesTextarea.value;
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'saveNotes', 
                        content: newContent,
                        currentStateVersion: currentStateVersion
                    })
                });
                const result = await response.json();
                if (result.success) {
                    currentStateVersion = parseInt(result.stateVersion);
                    currentNotesContent = newContent;
                    renderMarkdown(newContent);
                    toggleNotesEditMode();
                    showActionFeedback('success', 'Notes saved successfully!');
                } else {
                    showActionFeedback('error', 'Failed to save notes');
                }
            } catch (error) {
                console.error('Error saving notes:', error);
                showActionFeedback('error', 'Network error saving notes');
            }
        }

        function renderMarkdown(text) {
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
                .replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank" style="color:var(--cyan-400); text-decoration:underline;">$1</a>')
                .replace(/\n/g, '<br><br>');
            
            html = html.replace(/(<li.*?>.*?<\/li>)+/g, '<ul style="margin: 0.5rem 0; padding-left: 1.5rem;">$&</ul>');
            notesContent.innerHTML = html;
        }

        async function exportNotesToPdf() {
            showActionFeedback('success', 'Preparing PDF...', false);
            try {
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a4'
                });
                
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
                pdf.text('Class Notes - Quiz Session', margin, yPosition);
                yPosition += 10;
                
                // Underline
                pdf.setDrawColor(8, 145, 178);
                pdf.line(margin, yPosition, pageWidth - margin, yPosition);
                yPosition += 15;
                
                // Date/time info
                pdf.setFontSize(10);
                pdf.setFont(undefined, 'normal');
                pdf.setTextColor(102, 102, 102);
                pdf.text(`Generated: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}`, 
                    margin, yPosition);
                yPosition += 15;
                
                // Convert HTML to plain text with proper link handling
                const notesHTML = notesContent.innerHTML;
                
                // Extract links separately to preserve them properly
                const links = [];
                let notesText = notesHTML
                    // First: Extract and mark links
                    .replace(/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/gi, (match, url, text) => {
                        const linkId = links.length;
                        links.push({ url, text });
                        return `{LINK_${linkId}}`;
                    })
                    // Then process other HTML
                    .replace(/<br\s*\/?>/gi, '\n')
                    .replace(/<\/p>/gi, '\n\n')
                    .replace(/<p[^>]*>/gi, '')
                    .replace(/<\/h([1-6])>/gi, '\n')
                    .replace(/<h([1-6])[^>]*>/gi, (match, level) => {
                        const prefix = '='.repeat(parseInt(level));
                        return `\n${prefix} `;
                    })
                    .replace(/<\/li>/gi, '\n')
                    .replace(/<li[^>]*>/gi, '• ')
                    .replace(/<\/ul>/gi, '\n')
                    .replace(/<ul[^>]*>/gi, '\n')
                    .replace(/<strong[^>]*>(.*?)<\/strong>/gi, '**$1**')
                    .replace(/<b[^>]*>(.*?)<\/b>/gi, '**$1**')
                    .replace(/<em[^>]*>(.*?)<\/em>/gi, '*$1*')
                    .replace(/<i[^>]*>(.*?)<\/i>/gi, '*$1*')
                    .replace(/<[^>]*>/g, '')
                    .replace(/&nbsp;/g, ' ')
                    .replace(/&amp;/g, '&')
                    .replace(/&lt;/g, '<')
                    .replace(/&gt;/g, '>')
                    .replace(/&quot;/g, '"')
                    .replace(/\n\s*\n\s*\n/g, '\n\n')
                    .trim();
                
                if (!notesText || notesText === '') {
                    // If no content, add placeholder
                    notesText = 'No class notes have been added yet.';
                }
                
                // Process content with proper link handling
                const sections = notesText.split('\n\n');
                
                sections.forEach(section => {
                    if (section.trim() === '') return;
                    
                    checkPageSpace(15);
                    
                    // Check for heading markers
                    if (section.match(/^={1,6}\s/)) {
                        const level = (section.match(/^={1,6}/) || ['='])[0].length;
                        const headingText = section.replace(/^={1,6}\s*/, '').trim();
                        
                        pdf.setFontSize(16 - level);
                        pdf.setFont(undefined, 'bold');
                        pdf.setTextColor(8, 145, 178);
                        
                        const headingHeight = addWrappedText(headingText, margin, yPosition, contentWidth, 16 - level);
                        yPosition += headingHeight + 5;
                        
                        pdf.setFontSize(11);
                        pdf.setFont(undefined, 'normal');
                        pdf.setTextColor(0, 0, 0);
                    } else {
                        // Process regular content with link handling
                        let currentY = yPosition;
                        
                        // Split by link markers and process each part
                        const parts = section.split(/(\{LINK_\d+\})/g);
                        
                        parts.forEach(part => {
                            if (part.match(/\{LINK_(\d+)\}/)) {
                                // This is a link placeholder
                                const linkId = parseInt(part.match(/\{LINK_(\d+)\}/)[1]);
                                const link = links[linkId];
                                
                                if (link) {
                                    checkPageSpace(8);
                                    
                                    // Add clickable link in blue
                                    pdf.setTextColor(8, 145, 178);
                                    pdf.setFont(undefined, 'normal');
                                    
                                    const linkText = `${link.text} (${link.url})`;
                                    const wrappedLink = pdf.splitTextToSize(linkText, contentWidth - 15);
                                    
                                    wrappedLink.forEach((line, idx) => {
                                        // Make each line clickable
                                        pdf.textWithLink(line, margin, currentY, { url: link.url });
                                        currentY += 4;
                                    });
                                    
                                    pdf.setTextColor(0, 0, 0);
                                }
                            } else if (part.trim()) {
                                // Regular text with formatting
                                checkPageSpace(8);
                                
                                // Handle bold/italic
                                if (part.includes('**') || part.includes('*')) {
                                    const formatted = part.split(/(\*\*[^*]+\*\*|\*[^*]+\*)/g);
                                    
                                    formatted.forEach(segment => {
                                        if (!segment.trim()) return;
                                        
                                        if (segment.startsWith('**') && segment.endsWith('**')) {
                                            pdf.setFont(undefined, 'bold');
                                            const boldText = segment.slice(2, -2);
                                            const height = addWrappedText(boldText, margin, currentY, contentWidth, 11);
                                            currentY += height;
                                            pdf.setFont(undefined, 'normal');
                                        } else if (segment.startsWith('*') && segment.endsWith('*')) {
                                            pdf.setFont(undefined, 'italic');
                                            const italicText = segment.slice(1, -1);
                                            const height = addWrappedText(italicText, margin, currentY, contentWidth, 11);
                                            currentY += height;
                                            pdf.setFont(undefined, 'normal');
                                        } else {
                                            const height = addWrappedText(segment, margin, currentY, contentWidth, 11);
                                            currentY += height;
                                        }
                                    });
                                } else {
                                    const height = addWrappedText(part, margin, currentY, contentWidth, 11);
                                    currentY += height;
                                }
                            }
                        });
                        
                        yPosition = currentY + 4;
                    }
                });
                
                // Footer
                checkPageSpace(15);
                yPosition += 10;
                
                pdf.setFillColor(241, 245, 249);
                pdf.rect(margin, yPosition - 5, contentWidth, 15, 'F');
                
                pdf.setFontSize(9);
                pdf.setTextColor(102, 102, 102);
                pdf.text('This PDF maintains all text formatting and link references from the original notes.', 
                    margin + 5, yPosition);
                yPosition += 5;
                pdf.text('Text is fully selectable and copyable for study purposes.', 
                    margin + 5, yPosition);
                
                pdf.save("class-notes.pdf");
                showActionFeedback('success', 'PDF exported successfully with selectable text!');
                
            } catch (error) {
                console.error('Error generating PDF:', error);
                showActionFeedback('error', 'Error generating PDF: ' + error.message);
            }
        }

        // Notes Toolbar Helper Functions
        function insertLink() {
            const url = prompt('Enter URL:');
            if (!url) return;
            
            const linkText = prompt('Enter link text (optional):', url);
            const displayText = linkText || url;
            
            insertMarkdown('[', '](' + url + ')', displayText);
        }

        function insertMarkdown(startMark, endMark, placeholder) {
            const textarea = notesTextarea;
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selectedText = textarea.value.substring(start, end);
            
            const replacement = selectedText || placeholder;
            const newText = startMark + replacement + endMark;
            
            textarea.setRangeText(newText, start, end, 'end');
            textarea.focus();
            
            if (!selectedText) {
                textarea.setSelectionRange(start + startMark.length, start + startMark.length + placeholder.length);
            }
        }

        function insertBulletPoint() {
            const textarea = notesTextarea;
            const start = textarea.selectionStart;
            const beforeCursor = textarea.value.substring(0, start);
            const lastLineStart = beforeCursor.lastIndexOf('\n') + 1;
            const isAtLineStart = lastLineStart === start || beforeCursor.substring(lastLineStart).trim() === '';
            
            const bulletText = isAtLineStart ? '- ' : '\n- ';
            textarea.setRangeText(bulletText, start, start, 'end');
            textarea.focus();
        }

        function insertHeading() {
            const level = prompt('Heading level (1-3):', '2');
            if (!level || isNaN(level) || level < 1 || level > 3) return;
            
            const hashes = '#'.repeat(parseInt(level));
            insertMarkdown(`${hashes} `, '', 'Heading text');
        }

        // Player list functionality
        function togglePlayerList() {
            const playerList = document.getElementById('playerList');
            const toggleIcon = document.getElementById('playerListToggle');
            
            if (playerList.classList.contains('hidden')) {
                playerList.classList.remove('hidden');
                toggleIcon.classList.remove('fa-chevron-down');
                toggleIcon.classList.add('fa-chevron-up');
                renderPlayerList();
            } else {
                playerList.classList.add('hidden');
                toggleIcon.classList.remove('fa-chevron-up');
                toggleIcon.classList.add('fa-chevron-down');
            }
        }

        function renderPlayerList() {
            const playerList = document.getElementById('playerList');
            const { players } = gameData;
            
            if (!players || players.length === 0) {
                playerList.innerHTML = '<div style="padding: 0.5rem; color: var(--text-gray-400);">No players connected</div>';
                return;
            }

            const sortedPlayers = [...players].sort((a, b) => 
                new Date(a.joinedAt) - new Date(b.joinedAt)
            );
            
            let html = '';
            sortedPlayers.forEach((player, index) => {
                const time = new Date(player.joinedAt);
                const formattedTime = time.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                html += `
                <div style="padding: 0.5rem; border-bottom: 1px solid var(--bg-gray-700);">
                    <div style="font-weight: 600; color: var(--text-white); display: flex; align-items: center; gap: 0.5rem;">
                        ${index + 1}. ${player.nickname}
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-gray-400); display: flex; align-items: center; gap: 0.25rem;">
                        <i class="fas fa-clock"></i> Joined: ${formattedTime}
                    </div>
                </div>
                `;
            });
            playerList.innerHTML = html;
        }

        // Session management functions
        async function saveCurrentSession() {
            const sessionName = prompt('Enter a name for this session:', 'Session ' + new Date().toLocaleString());
            
            if (sessionName) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            action: 'saveSession', 
                            name: sessionName 
                        })
                    });
                    const data = await response.json();
                    if (data.success) {
                        showActionFeedback('success', 'Session saved successfully!');
                    } else {
                        showActionFeedback('error', 'Error saving session: ' + (data.error || 'Unknown error'));
                    }
                } catch (error) {
                    showActionFeedback('error', 'Network error: ' + error.message);
                }
            }
        }

        async function loadSession(sessionId) {
            if (!confirm('Loading this session will replace your current game state. Continue?')) {
                return;
            }
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'loadSession', 
                        id: sessionId 
                    })
                });
                const data = await response.json();
                if (data.success) {
                    currentStateVersion = parseInt(data.stateVersion);
                    showActionFeedback('success', 'Session loaded successfully!');
                    updateGameData();
                } else {
                    showActionFeedback('error', 'Error loading session: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                showActionFeedback('error', 'Network error: ' + error.message);
            }
        }

        async function deleteSession(sessionId) {
            if (!confirm('Are you sure you want to delete this session? This cannot be undone.')) {
                return;
            }
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'deleteSession', 
                        id: sessionId 
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showActionFeedback('success', 'Session deleted successfully!');
                    showSessionsModal();
                } else {
                    showActionFeedback('error', 'Error deleting session: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                showActionFeedback('error', 'Network error: ' + error.message);
            }
        }

        async function showSessionsModal() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'getSessions' })
                });
                const data = await response.json();
                if (data.success) {
                    renderSessionsModal(data.sessions);
                }
            } catch (error) {
                showActionFeedback('error', 'Network error: ' + error.message);
            }
        }

        function renderSessionsModal(sessions) {
            const sessionsList = document.getElementById('sessionsList');
            sessionsList.innerHTML = '';
            
            if (sessions.length === 0) {
                sessionsList.innerHTML = `
                    <div style="text-align: center; padding: 2rem; color: var(--text-gray-400);">
                        <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                        <h3>No saved sessions found</h3>
                        <p>Start a quiz and save your progress to see sessions here</p>
                    </div>
                `;
                document.getElementById('sessionsModal').classList.remove('hidden');
                return;
            }
            
            sessions.forEach(session => {
                const sessionEl = document.createElement('div');
                sessionEl.className = 'session-card';
                const sessionDate = new Date(session.timestamp * 1000).toLocaleString();
                
                sessionEl.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3 style="color: var(--text-white); display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-history"></i> ${session.name}
                        </h3>
                        <span class="status-badge" style="background-color: var(--bg-gray-700); padding: 0.25rem 0.5rem; border-radius: 0.25rem;">
                            ${sessionDate}
                        </span>
                    </div>
                    <p style="color: var(--text-gray-300); margin: 0.5rem 0; min-height: 2.5rem;">
                        ${session.playerCount} players | ${session.questionCount} questions
                    </p>
                    <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                        <button class="btn btn-primary load-session" data-id="${session.id}">
                            <i class="fas fa-play"></i> Load Session
                        </button>
                        <button class="btn btn-danger delete-session" data-id="${session.id}">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                `;
                sessionsList.appendChild(sessionEl);
            });
            
            // Add event listeners
            document.querySelectorAll('.load-session').forEach(btn => {
                btn.addEventListener('click', function() {
                    const sessionId = this.getAttribute('data-id');
                    loadSession(sessionId);
                    document.getElementById('sessionsModal').classList.add('hidden');
                });
            });
            
            document.querySelectorAll('.delete-session').forEach(btn => {
                btn.addEventListener('click', function() {
                    const sessionId = this.getAttribute('data-id');
                    deleteSession(sessionId);
                });
            });
            
            document.getElementById('sessionsModal').classList.remove('hidden');
        }

        // Auto-refresh notes periodically
        setInterval(fetchNotes, 30000);
    </script>
</body>
</html>
