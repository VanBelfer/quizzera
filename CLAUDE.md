# Quizerka - Claude Context Document

> **TL;DR for Claude**: Interactive quiz platform with PHP/SQLite backend and vanilla JS ES6 modules frontend. See Quick Reference below for essential context.

I host it on my vps.

---

## 🚀 Quick Reference (Read This First!)

### Essential Files
| File | Purpose |
|------|---------|
| `public/api.php` | ALL backend API endpoints |
| `src/QuizManager.php` | Core game logic |
| `public/assets/js/PlayerApp.js` | Player main entry |
| `public/assets/js/AdminApp.js` | Admin main entry |
| `public/assets/js/core/api.js` | ApiClient class |
| `public/assets/js/core/state.js` | StateManager with polling |

### Game State Structure
```javascript
gameState: {
  gameStarted: boolean,
  currentQuestion: number,
  phase: 'waiting' | 'question_shown' | 'options_shown' | 'reveal' | 'finished',
  answers: [{playerId, question, answer, isCorrect, timestamp}]  // ARRAY!
}
```

### Key Patterns
- **Polling**: StateManager polls every 500ms (player) / 1000ms (admin)
- **Change detection**: `stateVersion` integer increments on state change
- **Phase order check**: `finished` → `!gameStarted` → active phases
- **Notes API**: Returns `{content, updatedAt}` object

### Common API Actions
```bash
# Player
joinGame, getGameState, pressBuzzer, submitAnswer, getPlayerSummary, getNotes

# Admin  
startGame, showOptions, revealCorrect, nextQuestion, getGameData, saveNotes
```

### CSS Variables (variables.css)
```css
--cyan-400/500/600  /* Primary */    --green-500, --red-500  /* Success/Error */
--bg-gray-700/800/900  /* Backgrounds */    --text-white, --text-gray-300  /* Text */
```

### Testing
```bash
curl -s -X POST -H "Content-Type: application/json" \
  -d '{"action":"getGameState"}' http://localhost:8080/api.php
```

---

## ✅ MIGRATION COMPLETED - SQLite Backend + Modular Frontend

The application has been migrated from JSON file storage to SQLite database with modular PHP architecture and now features a fully modularized frontend with ES Modules.

### New Structure
```
/workspaces/quizzera/
├── data/
│   └── quiz.db            # SQLite database (replaces .json files)
├── public/                # Web-accessible files
│   ├── index.php          # Student Interface (legacy - inline CSS/JS)
│   ├── admin.php          # Admin Interface (legacy - inline CSS/JS)
│   ├── index-modular.php  # Student Interface (modular - ES Modules)
│   ├── admin-modular.php  # Admin Interface (modular - ES Modules)
│   ├── api.php            # Central API controller
│   └── assets/            
│       ├── css/           # Modular CSS
│       │   ├── variables.css       # CSS custom properties
│       │   ├── base.css            # Reset, typography
│       │   ├── buttons.css         # Button components
│       │   ├── forms.css           # Form inputs
│       │   ├── animations.css      # Keyframe animations
│       │   ├── components/         # Shared UI components
│       │   │   ├── help-panel.css
│       │   │   ├── notifications.css
│       │   │   ├── modal.css
│       │   │   ├── progress.css
│       │   │   ├── badges.css
│       │   │   └── cards.css
│       │   ├── admin/              # Admin-specific styles
│       │   │   ├── dashboard.css
│       │   │   ├── tabs.css
│       │   │   ├── game-controls.css
│       │   │   ├── question-editor.css
│       │   │   ├── notes-editor.css
│       │   │   └── results.css
│       │   └── player/             # Player-specific styles
│       │       ├── login.css
│       │       ├── buzzer.css
│       │       ├── options.css
│       │       ├── end-screen.css
│       │       └── notes-panel.css
│       └── js/            # Modular JavaScript (ES Modules)
│           ├── AdminApp.js         # Admin entry point
│           ├── PlayerApp.js        # Player entry point
│           ├── core/               # Core utilities
│           │   ├── api.js          # API client with retry logic
│           │   ├── state.js        # State management & polling
│           │   └── utils.js        # DOM helpers, formatTime, etc.
│           ├── components/         # Shared JS components
│           │   ├── HelpPanel.js
│           │   ├── NetworkStatus.js
│           │   ├── MessageSystem.js
│           │   ├── MarkdownRenderer.js
│           │   ├── Modal.js
│           │   └── ActionFeedback.js
│           ├── admin/              # Admin-specific modules
│           │   ├── GameControl.js
│           │   ├── QuestionEditor.js
│           │   ├── SessionManager.js
│           │   ├── NotesEditor.js
│           │   └── TabsNavigation.js
│           └── player/             # Player-specific modules
│               ├── BuzzerPhase.js
│               ├── OptionsPhase.js
│               ├── EndScreen.js
│               ├── AudioManager.js
│               ├── KeyboardShortcuts.js
│               └── ScreenManager.js
├── src/                   # Backend logic
│   ├── Database.php       # SQLite connection with WAL mode
│   └── QuizManager.php    # Game logic (buzzers, scoring, states)
├── migrate.php            # JSON to SQLite migration script
├── admin.php              # (OLD - legacy)
└── quiz.php               # (OLD - legacy)
```

### Frontend Architecture

**ES Modules (No Bundler)**
- Native browser ES module imports
- Each component is a self-contained class
- Clean separation of concerns

**CSS Architecture**
- CSS Custom Properties for theming (`variables.css`)
- Component-based CSS files
- Admin and Player specific styles separated

**Key Components**
- `ApiClient` - Handles all server communication with retry logic
- `StateManager` - Centralized state with polling and observers
- `MessageSystem` - Toast notifications
- `ActionFeedback` - Visual confirmations

### How to Deploy
1. Point your web server to `/public` directory
2. Ensure PHP has write access to `/data` directory
3. **Modular version** (recommended):
   - Student: `http://yoursite/index-modular.php`
   - Admin: `http://yoursite/admin-modular.php`
4. **Legacy version** (inline CSS/JS):
   - Student: `http://yoursite/index.php`
   - Admin: `http://yoursite/admin.php`

### Features
- **No more spin-lock crashes** - SQLite handles concurrency natively with WAL mode
- **Multi-session support** - Run multiple quizzes simultaneously
- **Automatic backups** - Database backed up when saving sessions
- **Zero race conditions** - Atomic operations for buzzers and answers

---

## Original Issues (RESOLVED)

The Critical Flaws in the Current Code
1. The "Spin-Lock" Problem (The biggest risk)
Your code uses manual file locking (while (file_exists($lockFile))... usleep).

The Scenario: Student A buzzes. The server creates quiz_state.json.lock.

The Crash: If the PHP script crashes, times out, or gets killed while writing (which happens often on cheaper VPSs under load), the .lock file is never deleted.

The Result: The game freezes permanently. Every other student who tries to buzz enters an infinite loop waiting for that lock to disappear, spiking your CPU to 100% until the server crashes.

2. Disk I/O Bottleneck
Every time a student’s browser polls for an update (every 1.5 seconds), your server opens, reads, and closes a file from the hard disk.

Math: 30 students = ~20 reads per second.

Result: On a standard VPS, this latency adds up. Students will experience "lag" where they press the buzzer, but nothing happens for 2-3 seconds, ruining the competitive aspect of a quiz.

3. Race Conditions (Missed Buzzers)
Even with your locking logic, file systems are slower than RAM. Two students buzzing at the exact same millisecond often leads to one overwriting the other, or one request failing because the lock wait time exceeded 5 seconds.

Better Alternatives (Ranked by Difficulty)
Since you are hosting on a VPS, you have full control. You should move away from text files (.json) for data storage immediately.

Quick Fix (SQLite)
Why: SQLite is a file-based database, but it handles all the locking and concurrency logic internally and reliably. You don't need to install a separate server; it's built into PHP.

Effort: Low. You just change your safeJsonRead/Write functions to standard SQL queries.

Reliability: High for up to ~50-100 concurrent users.

Breaking the code into modules (Separation of Concerns) while simultaneously switching to SQLite is the best way to ensure your project remains maintainable as you add more ESL features.

Since you are on a VPS, we can set up a professional, lightweight directory structure. This separates your Data (SQLite), your Logic (PHP Classes), and your Presentation (HTML/JS).

Shutterstock

Here is the blueprint for modularizing your Quiz App to make it robust and developer-friendly.

1. The New Directory Structure
Instead of two giant files, your project should look like this. This makes it easy to find what you need to fix or upgrade.

Plaintext

/var/www/quiz-app/
├── data/
│   └── quiz.db            <-- (Replaces your .json files)
├── public/                <-- (Only this folder is exposed to the web)
│   ├── index.php          <-- Student Interface (View)
│   ├── admin.php          <-- Admin Interface (View)
│   ├── api.php            <-- Handles all AJAX requests (Controller)
│   └── assets/            <-- CSS, JS, Images
├── src/                   <-- (Your backend logic)
│   ├── Database.php       <-- Handles SQLite connection
│   └── QuizManager.php    <-- Game logic (buzzers, scoring, states)
└── config.php             <-- Settings
2. The Foundation: src/Database.php
This file solves your "Reliability" problem. It wraps SQLite connection and turns on "WAL Mode" (Write-Ahead Logging), which allows reading and writing simultaneously without crashing—perfect for buzzing students.

PHP

<?php
class Database {
    private $pdo;

    public function __construct() {
        $dbPath = __DIR__ . '/../data/quiz.db';
        // Connect to SQLite
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // CRITICAL: Enable WAL mode for high concurrency (prevents locking issues)
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA synchronous = NORMAL;');
        
        $this->initTables();
    }

    private function initTables() {
        // Create tables if they don't exist (replaces your JSON structures)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS players (
                id TEXT PRIMARY KEY,
                nickname TEXT,
                joined_at DATETIME
            );
            CREATE TABLE IF NOT EXISTS game_state (
                key TEXT PRIMARY KEY,
                value TEXT
            );
            CREATE TABLE IF NOT EXISTS buzzers (
                player_id TEXT,
                question_index INTEGER,
                timestamp REAL
            );
        ");
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
3. The Logic: src/QuizManager.php
This solves your "Development" problem. Instead of spaghetti code checking file locks, you have clean methods. If you want to change how scoring works later, you only edit this file.

PHP

<?php
class QuizManager {
    private $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    public function joinGame($nickname) {
        $id = uniqid();
        $this->db->query("INSERT INTO players (id, nickname, joined_at) VALUES (?, ?, datetime('now'))", 
            [$id, $nickname]);
        return $id;
    }

    public function recordBuzzer($playerId, $questionIndex) {
        // Atomic insertion - impossible to have race conditions like the file lock
        try {
            $this->db->query("INSERT INTO buzzers (player_id, question_index, timestamp) VALUES (?, ?, ?)", 
                [$playerId, $questionIndex, microtime(true)]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getGameState() {
        // Fetch current question, buzzers, etc. via SQL
        // Return structured array
    }
}
4. The Controller: public/api.php
This becomes very clean. It simply receives the request and tells the Manager what to do.

PHP

<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/QuizManager.php';

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

$db = new Database();
$game = new QuizManager($db);

switch ($input['action']) {
    case 'joinGame':
        echo json_encode(['success' => true, 'id' => $game->joinGame($input['nickname'])]);
        break;
        
    case 'buzz':
        $success = $game->recordBuzzer($input['playerId'], $input['currentQuestion']);
        echo json_encode(['success' => $success]);
        break;
}
Why this is better for you
Zero "File Lock" Crashes: SQLite handles the "Spin Lock" problem natively. If two students buzz at the exact same microsecond, SQLite queues them correctly without you writing a single line of code.

Easy to Extend: Want to add a "Team Mode"? You just add a team_id column to the players table and update QuizManager. You don't have to rewrite a massive JSON parser.

Data Persistence: If the server restarts, your SQLite file (quiz.db) is safe. JSON files often get corrupted if the server crashes while writing to them.