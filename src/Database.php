<?php
/**
 * Database.php - SQLite Database Handler with WAL Mode
 * Solves spin-lock and race condition problems from JSON file storage
 */

class Database {
    private $pdo;
    private static $instance = null;

    public function __construct() {
        $dbPath = __DIR__ . '/../data/quiz.db';
        
        // Create data directory if it doesn't exist
        $dataDir = dirname($dbPath);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        
        // Connect to SQLite with proper error handling
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // CRITICAL: Enable WAL mode for high concurrency (prevents locking issues)
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA synchronous = NORMAL;');
        $this->pdo->exec('PRAGMA busy_timeout = 5000;'); // Wait up to 5 seconds for locks
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
        
        $this->initTables();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * Initialize all database tables
     */
    private function initTables() {
        // Quiz sessions (supports multiple concurrent quizzes)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS quiz_sessions (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_active INTEGER DEFAULT 1
            )
        ");

        // Game state (key-value pairs per session)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS game_state (
                session_id TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                PRIMARY KEY (session_id, key),
                FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE
            )
        ");

        // Players
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS players (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                nickname TEXT NOT NULL,
                joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                active INTEGER DEFAULT 1,
                FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE
            )
        ");

        // Questions
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS questions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                question_order INTEGER NOT NULL,
                question_text TEXT NOT NULL,
                options TEXT NOT NULL,
                correct_index INTEGER NOT NULL,
                image_url TEXT DEFAULT '',
                explanation TEXT DEFAULT '',
                original_correct_text TEXT DEFAULT '',
                shuffle_verified INTEGER DEFAULT 0,
                FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE
            )
        ");

        // Buzzers (high-precision timestamps for fair competition)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS buzzers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                player_id TEXT NOT NULL,
                question_index INTEGER NOT NULL,
                timestamp REAL NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
                UNIQUE(session_id, player_id, question_index)
            )
        ");

        // Answers
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS answers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                player_id TEXT NOT NULL,
                question_index INTEGER NOT NULL,
                answer_index INTEGER NOT NULL,
                is_correct INTEGER NOT NULL,
                timestamp REAL NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
                UNIQUE(session_id, player_id, question_index)
            )
        ");

        // Spoken players (for oral answer tracking)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS spoken_players (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                player_id TEXT NOT NULL,
                question_index INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
                UNIQUE(session_id, player_id, question_index)
            )
        ");

        // Notes (class notes/markdown)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS notes (
                session_id TEXT PRIMARY KEY,
                content TEXT DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE
            )
        ");

        // Messages (teacher to students)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS messages (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                text TEXT NOT NULL,
                type TEXT DEFAULT 'info',
                timestamp INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE
            )
        ");

        // State version (for conflict detection)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS state_version (
                session_id TEXT PRIMARY KEY,
                version INTEGER DEFAULT 1,
                FOREIGN KEY (session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE
            )
        ");

        // Saved sessions (backup/restore functionality)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS saved_sessions (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                session_data TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create indexes for better performance
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_players_session ON players(session_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_questions_session ON questions(session_id, question_order)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_buzzers_session ON buzzers(session_id, question_index)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_answers_session ON answers(session_id, question_index)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_messages_session ON messages(session_id)");
    }

    /**
     * Execute a query with parameters
     */
    public function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch all results from a query
     */
    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Fetch single row from a query
     */
    public function fetchOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Get the last inserted ID
     */
    public function lastInsertId(): string {
        return $this->pdo->lastInsertId();
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool {
        return $this->pdo->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): bool {
        return $this->pdo->rollBack();
    }

    /**
     * Get PDO instance for advanced operations
     */
    public function getPdo(): PDO {
        return $this->pdo;
    }

    /**
     * Create automatic backup of database
     */
    public function backup(string $backupPath = null): bool {
        $dbPath = __DIR__ . '/../data/quiz.db';
        $backupPath = $backupPath ?? __DIR__ . '/../data/backups/quiz_' . date('Y-m-d_H-i-s') . '.db';
        
        $backupDir = dirname($backupPath);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Use SQLite's backup API via VACUUM INTO
        try {
            $this->pdo->exec("VACUUM INTO '$backupPath'");
            return true;
        } catch (PDOException $e) {
            // Fallback to file copy
            return copy($dbPath, $backupPath);
        }
    }
}
