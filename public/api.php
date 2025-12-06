<?php
/**
 * api.php - Central API Controller
 * Handles all AJAX requests for both admin and student interfaces
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/QuizManager.php';

// Set JSON response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Only POST requests are allowed']);
    exit;
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request - action required']);
    exit;
}

try {
    // Initialize database and quiz manager
    $db = Database::getInstance();
    
    // Support for multiple sessions - use 'default' if not specified
    $sessionId = $input['sessionId'] ?? 'default';
    $game = new QuizManager($db, $sessionId);
    
    $action = $input['action'];
    $response = ['success' => false, 'error' => 'Unknown action'];

    switch ($action) {
        // ==================== STUDENT ACTIONS ====================
        
        case 'joinGame':
            if (empty($input['nickname'])) {
                $response = ['success' => false, 'error' => 'Nickname is required'];
                break;
            }
            $result = $game->joinGame(trim($input['nickname']));
            // Increment state version so admin polling picks up new players
            if ($result['success'] && !$result['existing']) {
                $game->incrementStateVersion();
            }
            $response = array_merge($result, [
                'stateVersion' => $game->getStateVersion()
            ]);
            break;

        case 'getGameState':
            $gameState = $game->getFullGameState();
            $currentQuestion = (int)$gameState['currentQuestion'];
            $questions = $game->getQuestions();
            $players = $game->getPlayers();
            
            $currentQuestionData = null;
            if ($gameState['gameStarted'] && isset($questions[$currentQuestion])) {
                $currentQuestionData = $questions[$currentQuestion];
            }
            
            $playerAnswerHistory = [];
            if (!empty($input['playerId'])) {
                $playerAnswerHistory = $game->getPlayerAnswers($input['playerId']);
            }
            
            $response = [
                'success' => true,
                'gameState' => $gameState,
                'currentQuestionData' => $currentQuestionData,
                'questions' => $questions,
                'players' => $players,
                'totalQuestions' => count($questions),
                'stateVersion' => $game->getStateVersion(),
                'messages' => $game->getMessages(),
                'playerAnswerHistory' => $playerAnswerHistory,
                'serverTime' => time()
            ];
            break;

        case 'pressBuzzer':
            if (empty($input['playerId'])) {
                $response = ['success' => false, 'error' => 'Player ID is required'];
                break;
            }
            
            $currentQuestion = (int)$game->getState('currentQuestion', '0');
            $result = $game->recordBuzzer($input['playerId'], $currentQuestion);
            
            if ($result['success']) {
                $newVersion = $game->incrementStateVersion();
                $response = ['success' => true, 'stateVersion' => $newVersion];
            } else {
                $response = ['success' => true, 'stateVersion' => $game->getStateVersion()];
            }
            break;

        case 'submitAnswer':
            if (empty($input['playerId']) || !isset($input['answer'])) {
                $response = ['success' => false, 'error' => 'Player ID and answer are required'];
                break;
            }
            
            $currentQuestion = (int)$game->getState('currentQuestion', '0');
            $result = $game->submitAnswer($input['playerId'], $currentQuestion, (int)$input['answer']);
            
            if ($result['success']) {
                $newVersion = $game->incrementStateVersion();
                $response = array_merge($result, ['stateVersion' => $newVersion]);
            } else {
                // Pass through the error from QuizManager
                $response = array_merge($result, ['stateVersion' => $game->getStateVersion()]);
            }
            break;

        case 'getPlayerSummary':
            if (empty($input['playerId'])) {
                $response = ['success' => false, 'error' => 'Player ID is required'];
                break;
            }
            
            $summary = $game->getPlayerSummary($input['playerId']);
            $response = [
                'success' => true,
                'summary' => $summary,
                'allQuestions' => $game->getQuestions()
            ];
            break;

        // ==================== ADMIN ACTIONS ====================
        
        case 'updateQuestions':
            if (empty($input['questionsJson'])) {
                $response = ['success' => false, 'error' => 'Questions JSON is required'];
                break;
            }
            
            $newQuestions = json_decode($input['questionsJson'], true);
            if (!$newQuestions || !is_array($newQuestions)) {
                $response = ['success' => false, 'error' => 'Invalid JSON format'];
                break;
            }
            
            $result = $game->updateQuestions($newQuestions);
            $newVersion = $game->incrementStateVersion();
            $response = array_merge($result, ['stateVersion' => $newVersion]);
            break;

        case 'startGame':
            $result = $game->startGame();
            $newVersion = $game->incrementStateVersion();
            $response = array_merge($result, ['stateVersion' => $newVersion]);
            break;

        case 'nextQuestion':
            $expectedVersion = (int)($input['currentStateVersion'] ?? 0);
            $result = $game->nextQuestion($expectedVersion);
            
            if ($result['success']) {
                $newVersion = $game->incrementStateVersion();
                $response = ['success' => true, 'stateVersion' => $newVersion];
            } else {
                $response = array_merge($result, ['currentGameState' => $game->getFullGameState()]);
            }
            break;

        case 'showOptions':
            $result = $game->showOptions();
            $newVersion = $game->incrementStateVersion();
            $response = array_merge($result, ['stateVersion' => $newVersion]);
            break;

        case 'revealCorrect':
            $result = $game->revealCorrect();
            $newVersion = $game->incrementStateVersion();
            $response = array_merge($result, ['stateVersion' => $newVersion]);
            break;

        case 'markSpoken':
            if (empty($input['playerId'])) {
                $response = ['success' => false, 'error' => 'Player ID is required'];
                break;
            }
            
            $currentQuestion = (int)$game->getState('currentQuestion', '0');
            $game->markSpoken($input['playerId'], $currentQuestion);
            $newVersion = $game->incrementStateVersion();
            $response = ['success' => true, 'stateVersion' => $newVersion];
            break;

        case 'softReset':
            $result = $game->softReset();
            $newVersion = $game->incrementStateVersion();
            $response = array_merge($result, ['stateVersion' => $newVersion]);
            break;

        case 'resetGame':
            $result = $game->resetGame();
            $newVersion = $game->incrementStateVersion();
            $response = array_merge($result, ['stateVersion' => $newVersion]);
            break;

        case 'getGameData':
            $gameState = $game->getFullGameState();
            $currentQuestion = (int)$gameState['currentQuestion'];
            
            $response = [
                'success' => true,
                'gameState' => $gameState,
                'questions' => $game->getQuestions(),
                'players' => $game->getPlayers(),
                'stateVersion' => $game->getStateVersion(),
                'serverTime' => time(),
                'answerStats' => $game->getAnswerStats($currentQuestion)
            ];
            break;

        case 'sendMessage':
            if (empty($input['message'])) {
                $response = ['success' => false, 'error' => 'Message is required'];
                break;
            }
            
            $type = $input['type'] ?? 'info';
            $result = $game->sendMessage($input['message'], $type);
            $newVersion = $game->incrementStateVersion();
            $response = array_merge($result, ['stateVersion' => $newVersion]);
            break;

        // ==================== NOTES ====================
        
        case 'getNotes':
            $notes = $game->getNotes();
            $response = ['success' => true, 'notes' => $notes];
            break;

        case 'saveNotes':
            if (!isset($input['content'])) {
                $response = ['success' => false, 'error' => 'Content is required'];
                break;
            }
            
            $result = $game->saveNotes($input['content']);
            $newVersion = $game->incrementStateVersion();
            $response = array_merge($result, ['stateVersion' => $newVersion]);
            break;

        // ==================== SESSION MANAGEMENT ====================
        
        case 'saveSession':
            $sessionName = $input['name'] ?? null;
            $sessionId = $input['id'] ?? null;
            $result = $game->saveSession($sessionName, $sessionId);
            $response = $result;
            break;

        case 'getSessions':
            $sessions = $game->getSavedSessions();
            $response = ['success' => true, 'sessions' => $sessions];
            break;

        case 'loadSession':
            if (empty($input['id'])) {
                $response = ['success' => false, 'error' => 'Session ID is required'];
                break;
            }
            
            $result = $game->loadSession($input['id']);
            if ($result['success']) {
                $newVersion = $game->incrementStateVersion();
                $response = array_merge($result, ['stateVersion' => $newVersion]);
            } else {
                $response = $result;
            }
            break;

        case 'deleteSession':
            if (empty($input['id'])) {
                $response = ['success' => false, 'error' => 'Session ID is required'];
                break;
            }
            
            $response = $game->deleteSession($input['id']);
            break;

        // ==================== MULTI-SESSION SUPPORT ====================
        
        case 'getActiveSessions':
            $sessions = $game->getActiveSessions();
            $response = ['success' => true, 'sessions' => $sessions];
            break;

        case 'createSession':
            if (empty($input['name'])) {
                $response = ['success' => false, 'error' => 'Session name is required'];
                break;
            }
            
            $newSessionId = $game->createSession($input['name']);
            $response = ['success' => true, 'sessionId' => $newSessionId];
            break;

        case 'switchSession':
            if (empty($input['targetSessionId'])) {
                $response = ['success' => false, 'error' => 'Target session ID is required'];
                break;
            }
            
            $switched = $game->switchSession($input['targetSessionId']);
            $response = ['success' => $switched, 'sessionId' => $input['targetSessionId']];
            break;

        default:
            $response = ['success' => false, 'error' => 'Unknown action: ' . $action];
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
