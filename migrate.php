<?php
/**
 * Migration Script: JSON to SQLite
 * 
 * Converts existing JSON files to SQLite database
 * Run once to migrate data: php migrate.php
 */

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/QuizManager.php';

echo "=== Quiz App Migration: JSON → SQLite ===\n\n";

// JSON file paths (original locations)
$jsonFiles = [
    'gameState' => __DIR__ . '/quiz_state.json',
    'questions' => __DIR__ . '/quiz_questions.json',
    'players' => __DIR__ . '/quiz_players.json',
    'notes' => __DIR__ . '/quiz_notes.json',
    'messages' => __DIR__ . '/quiz_messages.json',
    'stateVersion' => __DIR__ . '/quiz_state_version.txt',
    'sessionsDir' => __DIR__ . '/sessions'
];

// Check which files exist
echo "Checking existing JSON files...\n";
$existingFiles = [];
foreach ($jsonFiles as $key => $path) {
    if ($key === 'sessionsDir') {
        if (is_dir($path)) {
            $sessionFiles = glob($path . '/*.json');
            if (count($sessionFiles) > 0) {
                $existingFiles[$key] = $sessionFiles;
                echo "  ✓ Sessions directory: " . count($sessionFiles) . " sessions found\n";
            }
        }
    } elseif (file_exists($path)) {
        $existingFiles[$key] = $path;
        echo "  ✓ $key: exists\n";
    } else {
        echo "  - $key: not found (will use defaults)\n";
    }
}

if (empty($existingFiles)) {
    echo "\nNo existing data to migrate. Database will be initialized with defaults.\n";
}

// Initialize database (creates tables)
echo "\nInitializing SQLite database...\n";
try {
    $db = Database::getInstance();
    echo "  ✓ Database connected\n";
    
    // Initialize QuizManager (creates default session)
    $game = new QuizManager($db, 'default');
    echo "  ✓ Default session created\n";
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Migrate questions
if (isset($existingFiles['questions'])) {
    echo "\nMigrating questions...\n";
    $questionsJson = file_get_contents($existingFiles['questions']);
    $questions = json_decode($questionsJson, true);
    
    if ($questions && is_array($questions) && count($questions) > 0) {
        try {
            // Clear existing questions first
            $db->query("DELETE FROM questions WHERE session_id = 'default'");
            
            foreach ($questions as $index => $q) {
                $db->query(
                    "INSERT INTO questions (session_id, question_order, question_text, options, correct_index, image_url, explanation, original_correct_text, shuffle_verified) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        'default',
                        $index,
                        $q['question'],
                        json_encode($q['options']),
                        $q['correct'],
                        $q['image'] ?? '',
                        $q['explanation'] ?? '',
                        $q['_originalCorrectText'] ?? $q['options'][$q['correct']],
                        $q['_shuffleVerified'] ?? 1
                    ]
                );
            }
            echo "  ✓ Migrated " . count($questions) . " questions\n";
        } catch (Exception $e) {
            echo "  ✗ Error migrating questions: " . $e->getMessage() . "\n";
        }
    }
}

// Migrate players
if (isset($existingFiles['players'])) {
    echo "\nMigrating players...\n";
    $playersJson = file_get_contents($existingFiles['players']);
    $players = json_decode($playersJson, true);
    
    if ($players && is_array($players) && count($players) > 0) {
        try {
            // Clear existing players first
            $db->query("DELETE FROM players WHERE session_id = 'default'");
            
            foreach ($players as $player) {
                $db->query(
                    "INSERT OR IGNORE INTO players (id, session_id, nickname, joined_at, active) VALUES (?, ?, ?, ?, ?)",
                    [
                        $player['id'],
                        'default',
                        $player['nickname'],
                        $player['joinedAt'] ?? date('Y-m-d H:i:s'),
                        $player['active'] ?? 1
                    ]
                );
            }
            echo "  ✓ Migrated " . count($players) . " players\n";
        } catch (Exception $e) {
            echo "  ✗ Error migrating players: " . $e->getMessage() . "\n";
        }
    }
}

// Migrate game state
if (isset($existingFiles['gameState'])) {
    echo "\nMigrating game state...\n";
    $stateJson = file_get_contents($existingFiles['gameState']);
    $state = json_decode($stateJson, true);
    
    if ($state && is_array($state)) {
        try {
            // Update game state values
            $stateMapping = [
                'gameStarted' => isset($state['gameStarted']) ? ($state['gameStarted'] ? '1' : '0') : '0',
                'currentQuestion' => (string)($state['currentQuestion'] ?? 0),
                'phase' => $state['phase'] ?? 'waiting',
                'firstBuzzer' => $state['firstBuzzer'] ?? '',
                'buzzLocked' => isset($state['buzzLocked']) ? ($state['buzzLocked'] ? '1' : '0') : '0',
                'timestamp' => (string)($state['timestamp'] ?? time())
            ];
            
            foreach ($stateMapping as $key => $value) {
                $db->query(
                    "INSERT OR REPLACE INTO game_state (session_id, key, value) VALUES (?, ?, ?)",
                    ['default', $key, $value]
                );
            }
            
            // Migrate buzzers
            if (isset($state['buzzers']) && is_array($state['buzzers'])) {
                $db->query("DELETE FROM buzzers WHERE session_id = 'default'");
                foreach ($state['buzzers'] as $buzzer) {
                    $db->query(
                        "INSERT OR IGNORE INTO buzzers (session_id, player_id, question_index, timestamp) VALUES (?, ?, ?, ?)",
                        ['default', $buzzer['playerId'], $buzzer['question'], $buzzer['timestamp']]
                    );
                }
                echo "  ✓ Migrated " . count($state['buzzers']) . " buzzers\n";
            }
            
            // Migrate answers
            if (isset($state['answers']) && is_array($state['answers'])) {
                $db->query("DELETE FROM answers WHERE session_id = 'default'");
                foreach ($state['answers'] as $answer) {
                    $db->query(
                        "INSERT OR REPLACE INTO answers (session_id, player_id, question_index, answer_index, is_correct, timestamp) VALUES (?, ?, ?, ?, ?, ?)",
                        ['default', $answer['playerId'], $answer['question'], $answer['answer'], $answer['isCorrect'] ? 1 : 0, $answer['timestamp']]
                    );
                }
                echo "  ✓ Migrated " . count($state['answers']) . " answers\n";
            }
            
            // Migrate spoken players
            if (isset($state['spokenPlayers']) && is_array($state['spokenPlayers'])) {
                $db->query("DELETE FROM spoken_players WHERE session_id = 'default'");
                foreach ($state['spokenPlayers'] as $spoken) {
                    // Format: "questionIndex_playerId"
                    $parts = explode('_', $spoken, 2);
                    if (count($parts) === 2) {
                        $db->query(
                            "INSERT OR IGNORE INTO spoken_players (session_id, player_id, question_index) VALUES (?, ?, ?)",
                            ['default', $parts[1], (int)$parts[0]]
                        );
                    }
                }
                echo "  ✓ Migrated " . count($state['spokenPlayers']) . " spoken players\n";
            }
            
            echo "  ✓ Game state migrated\n";
        } catch (Exception $e) {
            echo "  ✗ Error migrating game state: " . $e->getMessage() . "\n";
        }
    }
}

// Migrate notes
if (isset($existingFiles['notes'])) {
    echo "\nMigrating notes...\n";
    $notesJson = file_get_contents($existingFiles['notes']);
    $notes = json_decode($notesJson, true);
    
    if ($notes && isset($notes['content'])) {
        try {
            $db->query(
                "INSERT OR REPLACE INTO notes (session_id, content, updated_at) VALUES (?, ?, datetime('now'))",
                ['default', $notes['content']]
            );
            echo "  ✓ Notes migrated\n";
        } catch (Exception $e) {
            echo "  ✗ Error migrating notes: " . $e->getMessage() . "\n";
        }
    }
}

// Migrate messages
if (isset($existingFiles['messages'])) {
    echo "\nMigrating messages...\n";
    $messagesJson = file_get_contents($existingFiles['messages']);
    $messages = json_decode($messagesJson, true);
    
    if ($messages && is_array($messages)) {
        try {
            $db->query("DELETE FROM messages WHERE session_id = 'default'");
            foreach ($messages as $message) {
                $db->query(
                    "INSERT INTO messages (id, session_id, text, type, timestamp) VALUES (?, ?, ?, ?, ?)",
                    [$message['id'], 'default', $message['text'], $message['type'] ?? 'info', $message['timestamp']]
                );
            }
            echo "  ✓ Migrated " . count($messages) . " messages\n";
        } catch (Exception $e) {
            echo "  ✗ Error migrating messages: " . $e->getMessage() . "\n";
        }
    }
}

// Migrate state version
if (isset($existingFiles['stateVersion'])) {
    echo "\nMigrating state version...\n";
    $version = (int)file_get_contents($existingFiles['stateVersion']);
    try {
        $db->query(
            "INSERT OR REPLACE INTO state_version (session_id, version) VALUES (?, ?)",
            ['default', $version]
        );
        echo "  ✓ State version set to $version\n";
    } catch (Exception $e) {
        echo "  ✗ Error migrating state version: " . $e->getMessage() . "\n";
    }
}

// Migrate saved sessions
if (isset($existingFiles['sessionsDir'])) {
    echo "\nMigrating saved sessions...\n";
    $sessionFiles = $existingFiles['sessionsDir'];
    
    foreach ($sessionFiles as $sessionFile) {
        $sessionId = basename($sessionFile, '.json');
        $sessionJson = file_get_contents($sessionFile);
        $sessionData = json_decode($sessionJson, true);
        
        if ($sessionData) {
            try {
                $db->query(
                    "INSERT OR REPLACE INTO saved_sessions (id, name, session_data, created_at) VALUES (?, ?, ?, datetime(?, 'unixepoch'))",
                    [
                        $sessionId,
                        $sessionData['name'] ?? 'Session ' . $sessionId,
                        $sessionJson,
                        $sessionData['timestamp'] ?? time()
                    ]
                );
                echo "  ✓ Session: " . ($sessionData['name'] ?? $sessionId) . "\n";
            } catch (Exception $e) {
                echo "  ✗ Error migrating session $sessionId: " . $e->getMessage() . "\n";
            }
        }
    }
}

echo "\n=== Migration Complete ===\n\n";
echo "Database location: " . __DIR__ . "/data/quiz.db\n";
echo "Backup location: " . __DIR__ . "/data/backups/\n\n";

echo "Next steps:\n";
echo "1. Update your web server to serve from /public directory\n";
echo "2. Test the application at public/index.php (student) and public/admin.php (admin)\n";
echo "3. Optionally backup and remove old JSON files:\n";
echo "   - quiz_state.json\n";
echo "   - quiz_questions.json\n";
echo "   - quiz_players.json\n";
echo "   - quiz_notes.json\n";
echo "   - quiz_messages.json\n";
echo "   - quiz_state_version.txt\n";
echo "   - sessions/ directory\n";
echo "\n";
