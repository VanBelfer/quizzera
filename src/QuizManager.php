<?php
/**
 * QuizManager.php - Game Logic Handler
 * Clean methods replacing spaghetti code with proper separation of concerns
 */

require_once __DIR__ . '/Database.php';

class QuizManager {
    private Database $db;
    private string $sessionId;

    public function __construct(Database $db, string $sessionId = 'default') {
        $this->db = $db;
        $this->sessionId = $sessionId;
        $this->ensureSessionExists();
    }

    /**
     * Ensure the session exists in the database
     */
    private function ensureSessionExists(): void {
        $existing = $this->db->fetchOne(
            "SELECT id FROM quiz_sessions WHERE id = ?",
            [$this->sessionId]
        );

        if (!$existing) {
            $this->db->query(
                "INSERT INTO quiz_sessions (id, name) VALUES (?, ?)",
                [$this->sessionId, 'Quiz Session ' . date('Y-m-d H:i')]
            );
            
            // Initialize default game state
            $this->initializeGameState();
            
            // Initialize state version
            $this->db->query(
                "INSERT OR IGNORE INTO state_version (session_id, version) VALUES (?, 1)",
                [$this->sessionId]
            );
            
            // Initialize default notes
            $this->db->query(
                "INSERT OR IGNORE INTO notes (session_id, content) VALUES (?, ?)",
                [$this->sessionId, "# Class Notes\n*Start adding your notes here...*\n\n## Useful Links\n- [Cybersecurity Basics](https://www.cisa.gov/cybersecurity)\n- [Phishing Examples](https://www.us-cert.gov/ncas/tips/ST04-014)"]
            );
            
            // Initialize default questions if none exist
            $questionCount = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM questions WHERE session_id = ?",
                [$this->sessionId]
            )['count'];
            
            if ($questionCount == 0) {
                $this->initializeDefaultQuestions();
            }
        }
    }

    /**
     * Initialize default game state
     */
    private function initializeGameState(): void {
        $defaults = [
            'gameStarted' => '0',
            'currentQuestion' => '0',
            'phase' => 'waiting',
            'firstBuzzer' => '',
            'buzzLocked' => '0',
            'timestamp' => (string)time()
        ];

        foreach ($defaults as $key => $value) {
            $this->db->query(
                "INSERT OR REPLACE INTO game_state (session_id, key, value) VALUES (?, ?, ?)",
                [$this->sessionId, $key, $value]
            );
        }
    }

    /**
     * Initialize default cybersecurity questions
     */
    private function initializeDefaultQuestions(): void {
        $defaultQuestions = [
            [
                "question" => "What is phishing?\n\nThis is a common cyber attack that targets users through deceptive communication.",
                "options" => [
                    "Deceptive emails or sites that try to steal information like logins.",
                    "A legitimate way to catch fish online.",
                    "Fishing for real tuna with enterprise-grade hooks."
                ],
                "correct" => 0,
                "explanation" => "Phishing is a cybersecurity attack where criminals send fake emails or create fake websites to trick people into giving away sensitive information."
            ],
            [
                "question" => "What is multi-factor authentication (MFA)?",
                "options" => [
                    "An extra login factor (e.g., app code, key) to protect accounts.",
                    "Asking a colleague to say 'please' twice before logging in."
                ],
                "correct" => 0,
                "explanation" => "MFA adds extra security layers beyond just a password. Even if someone steals your password, they still need the second factor."
            ],
            [
                "question" => "What should you do if you receive a suspicious email?\n\nThe email claims to be from your bank and asks you to click a link urgently.",
                "options" => [
                    "Click the link to check if it's real.",
                    "Reply with your account details.",
                    "Delete the email or report it to IT.",
                    "Forward it to all your colleagues."
                ],
                "correct" => 2,
                "explanation" => "Never click suspicious links or reply with personal info. Report it to IT or delete it."
            ]
        ];

        foreach ($defaultQuestions as $index => $q) {
            $this->db->query(
                "INSERT INTO questions (session_id, question_order, question_text, options, correct_index, image_url, explanation) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$this->sessionId, $index, $q['question'], json_encode($q['options']), $q['correct'], '', $q['explanation']]
            );
        }
    }

    /**
     * Get a game state value
     */
    public function getState(string $key, $default = null) {
        $result = $this->db->fetchOne(
            "SELECT value FROM game_state WHERE session_id = ? AND key = ?",
            [$this->sessionId, $key]
        );
        return $result ? $result['value'] : $default;
    }

    /**
     * Set a game state value
     */
    public function setState(string $key, string $value): void {
        $this->db->query(
            "INSERT OR REPLACE INTO game_state (session_id, key, value) VALUES (?, ?, ?)",
            [$this->sessionId, $key, $value]
        );
    }

    /**
     * Get full game state as array
     */
    public function getFullGameState(): array {
        $rows = $this->db->fetchAll(
            "SELECT key, value FROM game_state WHERE session_id = ?",
            [$this->sessionId]
        );

        $state = [
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

        foreach ($rows as $row) {
            $key = $row['key'];
            $value = $row['value'];
            
            if ($key === 'gameStarted' || $key === 'buzzLocked') {
                $state[$key] = $value === '1';
            } elseif ($key === 'currentQuestion' || $key === 'timestamp') {
                $state[$key] = (int)$value;
            } elseif ($key === 'firstBuzzer') {
                $state[$key] = $value ?: null;
            } else {
                $state[$key] = $value;
            }
        }

        // Get buzzers for current question
        $currentQuestion = $state['currentQuestion'];
        $state['buzzers'] = $this->getBuzzers($currentQuestion);
        $state['answers'] = $this->getAnswers($currentQuestion);
        $state['spokenPlayers'] = $this->getSpokenPlayers($currentQuestion);

        return $state;
    }

    /**
     * Get current state version (for conflict detection)
     */
    public function getStateVersion(): int {
        $result = $this->db->fetchOne(
            "SELECT version FROM state_version WHERE session_id = ?",
            [$this->sessionId]
        );
        return $result ? (int)$result['version'] : 1;
    }

    /**
     * Increment and return new state version
     */
    public function incrementStateVersion(): int {
        $this->db->query(
            "UPDATE state_version SET version = version + 1 WHERE session_id = ?",
            [$this->sessionId]
        );
        return $this->getStateVersion();
    }

    // ==================== PLAYER MANAGEMENT ====================

    /**
     * Join game with nickname
     */
    public function joinGame(string $nickname): array {
        // Check if nickname already exists in this session
        $existing = $this->db->fetchOne(
            "SELECT id FROM players WHERE session_id = ? AND nickname = ?",
            [$this->sessionId, $nickname]
        );

        if ($existing) {
            return [
                'success' => true,
                'playerId' => $existing['id'],
                'existing' => true
            ];
        }

        $playerId = uniqid();
        $this->db->query(
            "INSERT INTO players (id, session_id, nickname) VALUES (?, ?, ?)",
            [$playerId, $this->sessionId, $nickname]
        );

        return [
            'success' => true,
            'playerId' => $playerId,
            'existing' => false
        ];
    }

    /**
     * Get all players in session
     */
    public function getPlayers(): array {
        return $this->db->fetchAll(
            "SELECT id, nickname, joined_at as joinedAt, active FROM players WHERE session_id = ? ORDER BY joined_at",
            [$this->sessionId]
        );
    }

    /**
     * Get active players count
     */
    public function getActivePlayersCount(): int {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM players WHERE session_id = ? AND active = 1",
            [$this->sessionId]
        );
        return (int)$result['count'];
    }

    /**
     * Get player by ID
     */
    public function getPlayer(string $playerId): ?array {
        return $this->db->fetchOne(
            "SELECT id, nickname, joined_at as joinedAt, active FROM players WHERE id = ? AND session_id = ?",
            [$playerId, $this->sessionId]
        );
    }

    /**
     * Clear all players
     */
    public function clearPlayers(): void {
        $this->db->query("DELETE FROM players WHERE session_id = ?", [$this->sessionId]);
    }

    // ==================== QUESTION MANAGEMENT ====================

    /**
     * Get all questions
     */
    public function getQuestions(): array {
        $rows = $this->db->fetchAll(
            "SELECT question_order, question_text, options, correct_index, image_url, explanation, original_correct_text, shuffle_verified 
             FROM questions WHERE session_id = ? ORDER BY question_order",
            [$this->sessionId]
        );

        return array_map(function($row) {
            return [
                'question' => $row['question_text'],
                'options' => json_decode($row['options'], true),
                'correct' => (int)$row['correct_index'],
                'image' => $row['image_url'],
                'explanation' => $row['explanation'],
                '_originalCorrectText' => $row['original_correct_text'],
                '_shuffleVerified' => (bool)$row['shuffle_verified']
            ];
        }, $rows);
    }

    /**
     * Get question by index
     */
    public function getQuestion(int $index): ?array {
        $row = $this->db->fetchOne(
            "SELECT question_text, options, correct_index, image_url, explanation 
             FROM questions WHERE session_id = ? AND question_order = ?",
            [$this->sessionId, $index]
        );

        if (!$row) return null;

        return [
            'question' => $row['question_text'],
            'options' => json_decode($row['options'], true),
            'correct' => (int)$row['correct_index'],
            'image' => $row['image_url'],
            'explanation' => $row['explanation']
        ];
    }

    /**
     * Get total question count
     */
    public function getQuestionCount(): int {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM questions WHERE session_id = ?",
            [$this->sessionId]
        );
        return (int)$result['count'];
    }

    /**
     * Update questions with shuffling
     */
    public function updateQuestions(array $newQuestions): array {
        if (empty($newQuestions)) {
            throw new Exception('Question array cannot be empty');
        }

        // Validate all questions first
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

        $this->db->beginTransaction();
        try {
            // Clear existing questions
            $this->db->query("DELETE FROM questions WHERE session_id = ?", [$this->sessionId]);

            foreach ($newQuestions as $index => $q) {
                // Store original correct answer text
                $correctAnswerText = $q['options'][$q['correct']];
                
                // Shuffle options
                $originalIndices = array_keys($q['options']);
                shuffle($originalIndices);
                
                $shuffledOptions = [];
                $newCorrectIndex = -1;
                
                foreach ($originalIndices as $position => $originalIndex) {
                    $shuffledOptions[] = $q['options'][$originalIndex];
                    if ($originalIndex === $q['correct']) {
                        $newCorrectIndex = $position;
                    }
                }
                
                // Verify shuffle integrity
                if ($newCorrectIndex === -1 || $shuffledOptions[$newCorrectIndex] !== $correctAnswerText) {
                    $newCorrectIndex = array_search($correctAnswerText, $shuffledOptions);
                    if ($newCorrectIndex === false) {
                        throw new Exception("Critical error: Could not maintain correct answer integrity for question: " . substr($q['question'], 0, 50));
                    }
                }
                
                $this->db->query(
                    "INSERT INTO questions (session_id, question_order, question_text, options, correct_index, image_url, explanation, original_correct_text, shuffle_verified) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $this->sessionId, 
                        $index, 
                        $q['question'], 
                        json_encode($shuffledOptions), 
                        $newCorrectIndex,
                        $q['image'] ?? '',
                        $q['explanation'] ?? '',
                        $correctAnswerText,
                        $shuffledOptions[$newCorrectIndex] === $correctAnswerText ? 1 : 0
                    ]
                );
            }

            $this->db->commit();
            return ['success' => true, 'questionsUpdated' => count($newQuestions)];
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    // ==================== BUZZER MANAGEMENT ====================

    /**
     * Record a buzzer press (atomic operation - no race conditions)
     */
    public function recordBuzzer(string $playerId, int $questionIndex): array {
        // Verify phase is correct
        $phase = $this->getState('phase');
        if ($phase !== 'question_shown') {
            return ['success' => false, 'reason' => 'invalid_phase'];
        }

        // Get player info
        $player = $this->getPlayer($playerId);
        if (!$player) {
            return ['success' => false, 'reason' => 'player_not_found'];
        }

        try {
            // Atomic insertion with UNIQUE constraint handles race conditions
            $this->db->query(
                "INSERT INTO buzzers (session_id, player_id, question_index, timestamp) VALUES (?, ?, ?, ?)",
                [$this->sessionId, $playerId, $questionIndex, microtime(true)]
            );
            
            $this->setState('timestamp', (string)time());
            
            return [
                'success' => true,
                'nickname' => $player['nickname']
            ];
        } catch (PDOException $e) {
            // UNIQUE constraint violation = already buzzed
            if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                return ['success' => false, 'reason' => 'already_buzzed'];
            }
            throw $e;
        }
    }

    /**
     * Get buzzers for a question
     */
    public function getBuzzers(int $questionIndex): array {
        $rows = $this->db->fetchAll(
            "SELECT b.player_id as playerId, p.nickname, b.question_index as question, b.timestamp 
             FROM buzzers b 
             JOIN players p ON b.player_id = p.id 
             WHERE b.session_id = ? AND b.question_index = ? 
             ORDER BY b.timestamp",
            [$this->sessionId, $questionIndex]
        );
        
        return array_map(function($row) {
            return [
                'playerId' => $row['playerId'],
                'nickname' => $row['nickname'],
                'question' => (int)$row['question'],
                'timestamp' => (float)$row['timestamp']
            ];
        }, $rows);
    }

    /**
     * Clear buzzers for current session
     */
    public function clearBuzzers(int $questionIndex = null): void {
        if ($questionIndex !== null) {
            $this->db->query(
                "DELETE FROM buzzers WHERE session_id = ? AND question_index = ?",
                [$this->sessionId, $questionIndex]
            );
        } else {
            $this->db->query("DELETE FROM buzzers WHERE session_id = ?", [$this->sessionId]);
        }
    }

    // ==================== ANSWER MANAGEMENT ====================

    /**
     * Submit an answer
     */
    public function submitAnswer(string $playerId, int $questionIndex, int $answerIndex): array {
        $phase = $this->getState('phase');
        if ($phase !== 'options_shown') {
            return ['success' => false, 'reason' => 'invalid_phase'];
        }

        $question = $this->getQuestion($questionIndex);
        if (!$question) {
            return ['success' => false, 'reason' => 'question_not_found'];
        }

        $isCorrect = ($answerIndex === $question['correct']);

        try {
            // Use INSERT OR REPLACE to handle answer changes
            $this->db->query(
                "INSERT OR REPLACE INTO answers (session_id, player_id, question_index, answer_index, is_correct, timestamp) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$this->sessionId, $playerId, $questionIndex, $answerIndex, $isCorrect ? 1 : 0, microtime(true)]
            );

            $this->setState('timestamp', (string)time());

            return [
                'success' => true,
                'isCorrect' => $isCorrect,
                'correctAnswer' => $question['options'][$question['correct']]
            ];
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Get answers for a question
     */
    public function getAnswers(int $questionIndex): array {
        $rows = $this->db->fetchAll(
            "SELECT player_id as playerId, question_index as question, answer_index as answer, is_correct as isCorrect, timestamp 
             FROM answers WHERE session_id = ? AND question_index = ?",
            [$this->sessionId, $questionIndex]
        );
        
        return array_map(function($row) {
            return [
                'playerId' => $row['playerId'],
                'question' => (int)$row['question'],
                'answer' => (int)$row['answer'],
                'isCorrect' => (bool)$row['isCorrect'],
                'timestamp' => (float)$row['timestamp']
            ];
        }, $rows);
    }

    /**
     * Get all answers for a player
     */
    public function getPlayerAnswers(string $playerId): array {
        return $this->db->fetchAll(
            "SELECT question_index as question, answer_index as answer, is_correct as isCorrect, timestamp 
             FROM answers WHERE session_id = ? AND player_id = ? ORDER BY question_index",
            [$this->sessionId, $playerId]
        );
    }

    /**
     * Get ALL answers for ALL questions (for Results tab)
     */
    public function getAllAnswers(): array {
        $rows = $this->db->fetchAll(
            "SELECT player_id as playerId, question_index as question, answer_index as answer, is_correct as isCorrect, timestamp 
             FROM answers WHERE session_id = ? ORDER BY question_index, timestamp",
            [$this->sessionId]
        );
        
        return array_map(function($row) {
            return [
                'playerId' => $row['playerId'],
                'question' => (int)$row['question'],
                'answer' => (int)$row['answer'],
                'isCorrect' => (bool)$row['isCorrect'],
                'timestamp' => (float)$row['timestamp']
            ];
        }, $rows);
    }

    /**
     * Clear answers
     */
    public function clearAnswers(int $questionIndex = null): void {
        if ($questionIndex !== null) {
            $this->db->query(
                "DELETE FROM answers WHERE session_id = ? AND question_index = ?",
                [$this->sessionId, $questionIndex]
            );
        } else {
            $this->db->query("DELETE FROM answers WHERE session_id = ?", [$this->sessionId]);
        }
    }

    // ==================== SPOKEN PLAYERS ====================

    /**
     * Mark player as spoken for a question
     */
    public function markSpoken(string $playerId, int $questionIndex): bool {
        try {
            $this->db->query(
                "INSERT OR IGNORE INTO spoken_players (session_id, player_id, question_index) VALUES (?, ?, ?)",
                [$this->sessionId, $playerId, $questionIndex]
            );
            $this->setState('timestamp', (string)time());
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get spoken players for a question (returns keys like "questionIndex_playerId")
     */
    public function getSpokenPlayers(int $questionIndex = null): array {
        if ($questionIndex !== null) {
            $rows = $this->db->fetchAll(
                "SELECT question_index, player_id FROM spoken_players WHERE session_id = ? AND question_index = ?",
                [$this->sessionId, $questionIndex]
            );
        } else {
            $rows = $this->db->fetchAll(
                "SELECT question_index, player_id FROM spoken_players WHERE session_id = ?",
                [$this->sessionId]
            );
        }
        
        return array_map(function($row) {
            return $row['question_index'] . '_' . $row['player_id'];
        }, $rows);
    }

    /**
     * Clear spoken players
     */
    public function clearSpokenPlayers(int $questionIndex = null): void {
        if ($questionIndex !== null) {
            $this->db->query(
                "DELETE FROM spoken_players WHERE session_id = ? AND question_index = ?",
                [$this->sessionId, $questionIndex]
            );
        } else {
            $this->db->query("DELETE FROM spoken_players WHERE session_id = ?", [$this->sessionId]);
        }
    }

    // ==================== GAME FLOW ====================

    /**
     * Start the game
     */
    public function startGame(): array {
        $this->setState('gameStarted', '1');
        $this->setState('currentQuestion', '0');
        $this->setState('phase', 'question_shown');
        $this->setState('firstBuzzer', '');
        $this->setState('buzzLocked', '0');
        $this->setState('timestamp', (string)time());
        
        // Clear per-question data
        $this->clearBuzzers();
        $this->clearAnswers();
        $this->clearSpokenPlayers();
        
        return ['success' => true];
    }

    /**
     * Move to next question
     */
    public function nextQuestion(int $expectedVersion = 0): array {
        $currentVersion = $this->getStateVersion();
        
        if ($expectedVersion > 0 && $expectedVersion !== $currentVersion) {
            return [
                'success' => false,
                'error' => 'State conflict detected',
                'stateVersion' => $currentVersion
            ];
        }

        $currentQuestion = (int)$this->getState('currentQuestion', '0');
        $totalQuestions = $this->getQuestionCount();

        if ($currentQuestion < $totalQuestions - 1) {
            $newQuestion = $currentQuestion + 1;
            $this->setState('currentQuestion', (string)$newQuestion);
            $this->setState('phase', 'question_shown');
            $this->setState('firstBuzzer', '');
            $this->setState('buzzLocked', '0');
            $this->setState('timestamp', (string)time());
            
            // Clear per-question data for the new question
            $this->clearBuzzers($newQuestion);
            $this->clearAnswers($newQuestion);
            $this->clearSpokenPlayers($newQuestion);
        } else {
            $this->setState('gameStarted', '0');
            $this->setState('phase', 'finished');
        }

        return ['success' => true];
    }

    /**
     * Show options for current question
     */
    public function showOptions(): array {
        $this->setState('phase', 'options_shown');
        $this->setState('timestamp', (string)time());
        return ['success' => true];
    }

    /**
     * Reveal correct answer
     */
    public function revealCorrect(): array {
        $this->setState('phase', 'reveal');
        $this->setState('timestamp', (string)time());
        return ['success' => true];
    }

    /**
     * Soft reset (keep players, reset game state)
     */
    public function softReset(): array {
        $this->initializeGameState();
        $this->clearBuzzers();
        $this->clearAnswers();
        $this->clearSpokenPlayers();
        return ['success' => true];
    }

    /**
     * Full reset (clear everything)
     */
    public function resetGame(): array {
        $this->initializeGameState();
        $this->clearPlayers();
        $this->clearBuzzers();
        $this->clearAnswers();
        $this->clearSpokenPlayers();
        return ['success' => true];
    }

    // ==================== MESSAGES ====================

    /**
     * Send a message to players
     */
    public function sendMessage(string $text, string $type = 'info'): array {
        $messageId = uniqid();
        
        $this->db->query(
            "INSERT INTO messages (id, session_id, text, type, timestamp) VALUES (?, ?, ?, ?, ?)",
            [$messageId, $this->sessionId, $text, $type, time()]
        );

        // Keep only last 10 messages
        $this->db->query(
            "DELETE FROM messages WHERE session_id = ? AND id NOT IN (
                SELECT id FROM messages WHERE session_id = ? ORDER BY timestamp DESC LIMIT 10
            )",
            [$this->sessionId, $this->sessionId]
        );

        return ['success' => true, 'messageId' => $messageId];
    }

    /**
     * Get messages
     */
    public function getMessages(): array {
        return $this->db->fetchAll(
            "SELECT id, text, type, timestamp FROM messages WHERE session_id = ? ORDER BY timestamp DESC LIMIT 10",
            [$this->sessionId]
        );
    }

    // ==================== NOTES ====================

    /**
     * Get notes
     */
    public function getNotes(): array {
        $result = $this->db->fetchOne(
            "SELECT content, updated_at as updatedAt FROM notes WHERE session_id = ?",
            [$this->sessionId]
        );
        
        return $result ?: ['content' => '', 'updatedAt' => 0];
    }

    /**
     * Save notes
     */
    public function saveNotes(string $content): array {
        $this->db->query(
            "INSERT OR REPLACE INTO notes (session_id, content, updated_at) VALUES (?, ?, datetime('now'))",
            [$this->sessionId, $content]
        );
        return ['success' => true];
    }

    // ==================== SESSION SAVE/LOAD ====================

    /**
     * Save current session
     */
    public function saveSession(string $name = null, string $id = null): array {
        $sessionId = $id ?? uniqid();
        $sessionName = $name ?? 'Session ' . date('Y-m-d H:i');

        $sessionData = [
            'gameState' => $this->getFullGameState(),
            'questions' => $this->getQuestions(),
            'players' => $this->getPlayers(),
            'notes' => $this->getNotes(),
            'messages' => $this->getMessages()
        ];

        $this->db->query(
            "INSERT OR REPLACE INTO saved_sessions (id, name, session_data, created_at) VALUES (?, ?, ?, datetime('now'))",
            [$sessionId, $sessionName, json_encode($sessionData)]
        );

        // Backup database when saving session
        $this->db->backup();

        return [
            'success' => true,
            'session' => [
                'id' => $sessionId,
                'name' => $sessionName,
                'timestamp' => time()
            ]
        ];
    }

    /**
     * Get saved sessions list
     */
    public function getSavedSessions(): array {
        $rows = $this->db->fetchAll(
            "SELECT id, name, session_data, created_at FROM saved_sessions ORDER BY created_at DESC"
        );

        return array_map(function($row) {
            $data = json_decode($row['session_data'], true);
            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'timestamp' => strtotime($row['created_at']),
                'playerCount' => count($data['players'] ?? []),
                'questionCount' => count($data['questions'] ?? [])
            ];
        }, $rows);
    }

    /**
     * Load a saved session
     */
    public function loadSession(string $sessionId): array {
        $row = $this->db->fetchOne(
            "SELECT session_data, name FROM saved_sessions WHERE id = ?",
            [$sessionId]
        );

        if (!$row) {
            return ['success' => false, 'error' => 'Session not found'];
        }

        $data = json_decode($row['session_data'], true);

        $this->db->beginTransaction();
        try {
            // Restore game state
            foreach ($data['gameState'] as $key => $value) {
                if (!in_array($key, ['buzzers', 'answers', 'spokenPlayers'])) {
                    $this->setState($key, is_bool($value) ? ($value ? '1' : '0') : (string)$value);
                }
            }

            // Restore questions
            $this->db->query("DELETE FROM questions WHERE session_id = ?", [$this->sessionId]);
            foreach ($data['questions'] as $index => $q) {
                $this->db->query(
                    "INSERT INTO questions (session_id, question_order, question_text, options, correct_index, image_url, explanation) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$this->sessionId, $index, $q['question'], json_encode($q['options']), $q['correct'], $q['image'] ?? '', $q['explanation'] ?? '']
                );
            }

            // Restore players
            $this->db->query("DELETE FROM players WHERE session_id = ?", [$this->sessionId]);
            foreach ($data['players'] as $p) {
                $this->db->query(
                    "INSERT INTO players (id, session_id, nickname, joined_at, active) VALUES (?, ?, ?, ?, ?)",
                    [$p['id'], $this->sessionId, $p['nickname'], $p['joinedAt'] ?? date('Y-m-d H:i:s'), $p['active'] ?? 1]
                );
            }

            // Restore notes
            if (isset($data['notes'])) {
                $this->saveNotes($data['notes']['content'] ?? '');
            }

            $this->db->commit();
            return ['success' => true, 'sessionName' => $row['name']];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete a saved session
     */
    public function deleteSession(string $sessionId): array {
        $this->db->query("DELETE FROM saved_sessions WHERE id = ?", [$sessionId]);
        return ['success' => true];
    }

    // ==================== STATISTICS ====================

    /**
     * Get answer statistics for admin view
     */
    public function getAnswerStats(int $questionIndex): array {
        $players = $this->getPlayers();
        $answers = $this->getAnswers($questionIndex);
        
        $activePlayers = array_filter($players, fn($p) => $p['active'] ?? true);
        $activeCount = count($activePlayers);
        
        $answeredMap = [];
        foreach ($answers as $ans) {
            $answeredMap[$ans['playerId']] = $ans;
        }
        
        $answeredPlayerIds = array_keys($answeredMap);
        $answersCount = count($answeredPlayerIds);
        
        $nameById = [];
        foreach ($players as $p) {
            $nameById[$p['id']] = $p['nickname'];
        }
        
        $answeredNames = array_map(fn($id) => $nameById[$id] ?? $id, $answeredPlayerIds);
        
        $activeIds = array_map(fn($p) => $p['id'], $activePlayers);
        $notAnsweredIds = array_diff($activeIds, $answeredPlayerIds);
        $notAnsweredNames = array_map(fn($id) => $nameById[$id] ?? $id, $notAnsweredIds);

        return [
            'currentQuestion' => $questionIndex,
            'answersCount' => $answersCount,
            'activeCount' => $activeCount,
            'allAnswered' => ($activeCount > 0) && ($answersCount >= $activeCount),
            'answeredPlayerIds' => $answeredPlayerIds,
            'answeredNames' => array_values($answeredNames),
            'notAnsweredPlayerIds' => array_values($notAnsweredIds),
            'notAnsweredNames' => array_values($notAnsweredNames),
            'answeredDetails' => array_values($answeredMap)
        ];
    }

    /**
     * Get player summary for end-of-quiz view
     */
    public function getPlayerSummary(string $playerId): array {
        $questions = $this->getQuestions();
        $playerAnswers = $this->getPlayerAnswers($playerId);
        
        $summary = [
            'totalQuestions' => count($questions),
            'answeredQuestions' => count($playerAnswers),
            'correctAnswers' => 0,
            'incorrectAnswers' => 0,
            'unansweredQuestions' => [],
            'questionBreakdown' => []
        ];

        $answeredQuestions = [];
        foreach ($playerAnswers as $answer) {
            $answeredQuestions[$answer['question']] = $answer;
        }

        foreach ($questions as $index => $question) {
            if (isset($answeredQuestions[$index])) {
                $answer = $answeredQuestions[$index];
                $isCorrect = (bool)$answer['isCorrect'];
                
                if ($isCorrect) {
                    $summary['correctAnswers']++;
                } else {
                    $summary['incorrectAnswers']++;
                }

                $summary['questionBreakdown'][] = [
                    'questionIndex' => $index,
                    'question' => $question['question'],
                    'playerAnswer' => $question['options'][$answer['answer']] ?? 'Unknown',
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

        return $summary;
    }

    // ==================== MULTI-SESSION SUPPORT ====================

    /**
     * Get all active quiz sessions
     */
    public function getActiveSessions(): array {
        return $this->db->fetchAll(
            "SELECT id, name, created_at, is_active FROM quiz_sessions WHERE is_active = 1 ORDER BY created_at DESC"
        );
    }

    /**
     * Create a new quiz session
     */
    public function createSession(string $name): string {
        $newSessionId = uniqid('session_');
        $this->db->query(
            "INSERT INTO quiz_sessions (id, name) VALUES (?, ?)",
            [$newSessionId, $name]
        );
        return $newSessionId;
    }

    /**
     * Switch to a different session
     */
    public function switchSession(string $sessionId): bool {
        $exists = $this->db->fetchOne(
            "SELECT id FROM quiz_sessions WHERE id = ?",
            [$sessionId]
        );

        if ($exists) {
            $this->sessionId = $sessionId;
            return true;
        }
        return false;
    }

    /**
     * Get current session ID
     */
    public function getSessionId(): string {
        return $this->sessionId;
    }
}
