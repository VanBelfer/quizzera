# Copilot Coding Agent Instructions for Quizzera

> **Trust these instructions.** Only search if information here is incomplete or found to be incorrect.

## Repository Overview

**Quizzera** is an interactive multiplayer quiz platform for classroom use, featuring real-time buzzer functionality, teacher controls, and student scoring. It uses a PHP/SQLite backend with a vanilla JavaScript ES6 modules frontend.

| Property | Value |
|----------|-------|
| **Languages** | PHP (55%), JavaScript (20%), CSS (7%), Shell (1%) |
| **Backend** | PHP 8.x with SQLite (WAL mode) |
| **Frontend** | Vanilla JS ES6 modules (no bundler) |
| **Database** | `data/quiz.db` (SQLite) |

---

## Project Structure

```
quizzera/
├── public/                    # Web root (serve this directory)
│   ├── index-modular.php      # Player interface (PRIMARY)
│   ├── admin-modular.php      # Admin/teacher interface (PRIMARY)
│   ├── index.php              # Player interface (legacy duplicate)
│   ├── admin.php              # Admin interface (legacy duplicate)
│   ├── api.php                # Central API controller (ALL backend endpoints)
│   └── assets/
│       ├── css/               # Modular CSS files
│       │   ├── variables.css  # CSS custom properties (--cyan-400, --bg-gray-*, etc.)
│       │   ├── base.css, buttons.css, forms.css, animations.css
│       │   ├── components/    # Shared: help-panel, notifications, modal, progress, badges, cards
│       │   ├── admin/         # Admin-specific: dashboard, tabs, game-controls, question-editor, notes-editor, results
│       │   └── player/        # Player-specific: login, buzzer, options, end-screen, notes-panel
│       └── js/
│           ├── PlayerApp.js   # Player entry point
│           ├── AdminApp.js    # Admin entry point
│           ├── core/          # api.js (ApiClient), state.js (StateManager), utils.js
│           ├── components/    # Shared: HelpPanel, NetworkStatus, MessageSystem, MarkdownRenderer, Modal, ActionFeedback
│           ├── admin/         # GameControl, QuestionEditor, SessionManager, NotesEditor, TabsNavigation
│           └── player/        # BuzzerPhase, OptionsPhase, EndScreen, AudioManager, KeyboardShortcuts, ScreenManager
├── src/                       # Backend PHP classes
│   ├── Database.php           # SQLite connection with WAL mode
│   └── QuizManager.php        # Core game logic (buzzers, scoring, state management)
├── data/
│   └── quiz.db                # SQLite database (auto-created if missing)
├── test-modular.sh            # Comprehensive test script
├── CLAUDE.md                  # Detailed architecture documentation (reference this for deep context)
├── ROADMAP.md                 # Future development plans
├── migrate.php                # LEGACY - migration already completed, do not use
└── legacy_dont_edit/          # LEGACY - do not modify files in this directory
```

---

## Build & Run Instructions

### Prerequisites
- PHP 8.2+ with SQLite extension enabled
- No composer/npm dependencies required - this is a zero-dependency project

### Start Development Server
```bash
php -S localhost:8080 -t public/
```

### Access the Application
- **Player interface**: http://localhost:8080/index-modular.php
- **Admin interface**: http://localhost:8080/admin-modular.php

### Database
The SQLite database (`data/quiz.db`) is auto-initialized by `src/Database.php` on first run. Ensure PHP has write access to the `data/` directory.

---

## Testing & Validation

### Primary Test Script
**Always run the test script to validate changes:**
```bash
./test-modular.sh
# Or with custom URL:
./test-modular.sh http://localhost:8080
```

This script validates:
- All CSS and JS module availability (HTTP 200 checks)
- HTML pages load correctly
- ES module import syntax
- CSS variables presence
- JS export syntax
- Full API endpoint flow (reset → join → start → buzz → answer → reveal → next)
- SQLite database tables exist

### Manual API Testing
```bash
# Get game state
curl -s -X POST -H "Content-Type: application/json" \
  -d '{"action":"getGameState"}' http://localhost:8080/api.php

# Join as player
curl -s -X POST -H "Content-Type: application/json" \
  -d '{"action":"joinGame","nickname":"TestPlayer"}' http://localhost:8080/api.php

# Reset game
curl -s -X POST -H "Content-Type: application/json" \
  -d '{"action":"resetGame"}' http://localhost:8080/api.php
```

### Key API Actions
| Player Actions | Admin Actions |
|----------------|---------------|
| `joinGame`, `getGameState`, `pressBuzzer`, `submitAnswer`, `getPlayerSummary`, `getNotes` | `startGame`, `showOptions`, `revealCorrect`, `nextQuestion`, `resetGame`, `softReset`, `getGameData`, `saveNotes` |

---

## Architecture Notes

### Game State & Phases
```javascript
gameState: {
  gameStarted: boolean,
  currentQuestion: number,
  phase: 'waiting' | 'question_shown' | 'options_shown' | 'reveal' | 'finished',
  answers: [{playerId, question, answer, isCorrect, timestamp}]
}
```
**Phase order check**: `finished` → `!gameStarted` → active phases

### Polling Intervals
- Player: 500ms
- Admin: 1000ms
- Change detection via `stateVersion` integer

### CSS Theming
All colors use CSS custom properties defined in `variables.css`:
- Primary: `--cyan-400/500/600`
- Success/Error: `--green-500`, `--red-500`
- Backgrounds: `--bg-gray-700/800/900`

---

## Important Warnings

1. **Do NOT run `composer install`** - This project has no composer.json
2. **Do NOT run `npm install`** - No Node.js dependencies exist
3. **Do NOT modify `legacy_dont_edit/`** - Contains deprecated code
4. **Do NOT use `migrate.php`** - Migration is already complete
5. **`index.php` and `admin.php` are legacy duplicates** - Prefer `-modular` versions
6. **The `data/` directory must be writable** by PHP for SQLite

---

## For More Context

Refer to `CLAUDE.md` in the repository root for:
- Detailed game state structure
- Complete API action list
- Historical context on the JSON→SQLite migration
- Full directory tree with file purposes
