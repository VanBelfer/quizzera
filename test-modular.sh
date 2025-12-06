#!/bin/bash
# Test script for Quizzera modular frontend
# Run from project root: ./test-modular.sh

# Don't exit on error - we want to run all tests
set +e

BASE_URL="${1:-http://localhost:8080}"
API_URL="$BASE_URL/api.php"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

PASSED=0
FAILED=0

# Helper functions
pass() {
    echo -e "${GREEN}✓${NC} $1"
    ((PASSED++))
}

fail() {
    echo -e "${RED}✗${NC} $1"
    ((FAILED++))
}

info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

section() {
    echo ""
    echo -e "${YELLOW}━━━ $1 ━━━${NC}"
}

# Test if a URL returns 200
test_url() {
    local url="$1"
    local desc="$2"
    
    status=$(curl -s -o /dev/null -w "%{http_code}" "$url")
    if [ "$status" = "200" ]; then
        pass "$desc (HTTP $status)"
        return 0
    else
        fail "$desc (HTTP $status)"
        return 1
    fi
}

# Test if file contains expected content
test_content() {
    local url="$1"
    local pattern="$2"
    local desc="$3"
    
    content=$(curl -s "$url")
    if echo "$content" | grep -q "$pattern"; then
        pass "$desc"
        return 0
    else
        fail "$desc - pattern not found: $pattern"
        return 1
    fi
}

# API test helper
api_test() {
    local action="$1"
    local data="$2"
    local expected="$3"
    local desc="$4"
    
    response=$(curl -s -X POST "$API_URL" \
        -H "Content-Type: application/json" \
        -d "$data")
    
    if echo "$response" | grep -q "$expected"; then
        pass "API: $desc"
        echo "$response"
        return 0
    else
        fail "API: $desc"
        echo "Response: $response"
        return 1
    fi
}

echo ""
echo "╔═══════════════════════════════════════════════════════════╗"
echo "║        QUIZZERA MODULAR FRONTEND TEST SUITE               ║"
echo "╠═══════════════════════════════════════════════════════════╣"
echo "║  Base URL: $BASE_URL"
echo "╚═══════════════════════════════════════════════════════════╝"

# ============================================================
section "1. CSS Modules Availability"
# ============================================================

test_url "$BASE_URL/assets/css/variables.css" "variables.css"
test_url "$BASE_URL/assets/css/base.css" "base.css"
test_url "$BASE_URL/assets/css/buttons.css" "buttons.css"
test_url "$BASE_URL/assets/css/forms.css" "forms.css"
test_url "$BASE_URL/assets/css/animations.css" "animations.css"

# Components
test_url "$BASE_URL/assets/css/components/help-panel.css" "components/help-panel.css"
test_url "$BASE_URL/assets/css/components/notifications.css" "components/notifications.css"
test_url "$BASE_URL/assets/css/components/modal.css" "components/modal.css"
test_url "$BASE_URL/assets/css/components/progress.css" "components/progress.css"

# Admin CSS
test_url "$BASE_URL/assets/css/admin/dashboard.css" "admin/dashboard.css"
test_url "$BASE_URL/assets/css/admin/tabs.css" "admin/tabs.css"
test_url "$BASE_URL/assets/css/admin/game-controls.css" "admin/game-controls.css"

# Player CSS
test_url "$BASE_URL/assets/css/player/login.css" "player/login.css"
test_url "$BASE_URL/assets/css/player/buzzer.css" "player/buzzer.css"
test_url "$BASE_URL/assets/css/player/options.css" "player/options.css"

# ============================================================
section "2. JavaScript Modules Availability"
# ============================================================

# Core
test_url "$BASE_URL/assets/js/core/api.js" "core/api.js"
test_url "$BASE_URL/assets/js/core/state.js" "core/state.js"
test_url "$BASE_URL/assets/js/core/utils.js" "core/utils.js"

# Components
test_url "$BASE_URL/assets/js/components/HelpPanel.js" "components/HelpPanel.js"
test_url "$BASE_URL/assets/js/components/NetworkStatus.js" "components/NetworkStatus.js"
test_url "$BASE_URL/assets/js/components/MessageSystem.js" "components/MessageSystem.js"
test_url "$BASE_URL/assets/js/components/MarkdownRenderer.js" "components/MarkdownRenderer.js"
test_url "$BASE_URL/assets/js/components/Modal.js" "components/Modal.js"
test_url "$BASE_URL/assets/js/components/ActionFeedback.js" "components/ActionFeedback.js"

# Admin modules
test_url "$BASE_URL/assets/js/admin/GameControl.js" "admin/GameControl.js"
test_url "$BASE_URL/assets/js/admin/QuestionEditor.js" "admin/QuestionEditor.js"
test_url "$BASE_URL/assets/js/admin/SessionManager.js" "admin/SessionManager.js"
test_url "$BASE_URL/assets/js/admin/NotesEditor.js" "admin/NotesEditor.js"
test_url "$BASE_URL/assets/js/admin/TabsNavigation.js" "admin/TabsNavigation.js"

# Player modules
test_url "$BASE_URL/assets/js/player/BuzzerPhase.js" "player/BuzzerPhase.js"
test_url "$BASE_URL/assets/js/player/OptionsPhase.js" "player/OptionsPhase.js"
test_url "$BASE_URL/assets/js/player/EndScreen.js" "player/EndScreen.js"
test_url "$BASE_URL/assets/js/player/AudioManager.js" "player/AudioManager.js"
test_url "$BASE_URL/assets/js/player/KeyboardShortcuts.js" "player/KeyboardShortcuts.js"
test_url "$BASE_URL/assets/js/player/ScreenManager.js" "player/ScreenManager.js"

# Main apps
test_url "$BASE_URL/assets/js/AdminApp.js" "AdminApp.js (entry point)"
test_url "$BASE_URL/assets/js/PlayerApp.js" "PlayerApp.js (entry point)"

# ============================================================
section "3. HTML Pages"
# ============================================================

test_url "$BASE_URL/index-modular.php" "Player interface (modular)"
test_url "$BASE_URL/admin-modular.php" "Admin interface (modular)"
test_url "$BASE_URL/index.php" "Player interface (legacy)"
test_url "$BASE_URL/admin.php" "Admin interface (legacy)"

# ============================================================
section "4. Module Import Syntax Check"
# ============================================================

test_content "$BASE_URL/index-modular.php" 'type="module"' "Player has ES module script tag"
test_content "$BASE_URL/admin-modular.php" 'type="module"' "Admin has ES module script tag"
test_content "$BASE_URL/index-modular.php" "PlayerApp.js" "Player imports PlayerApp.js"
test_content "$BASE_URL/admin-modular.php" "AdminApp.js" "Admin imports AdminApp.js"

# ============================================================
section "5. CSS Variables Check"
# ============================================================

test_content "$BASE_URL/assets/css/variables.css" "bg-dark" "CSS has --bg-dark variable"
test_content "$BASE_URL/assets/css/variables.css" "cyan-400" "CSS has --cyan-400 variable"
test_content "$BASE_URL/assets/css/variables.css" "transition" "CSS has --transition variable"

# ============================================================
section "6. JS Export Syntax Check"
# ============================================================

test_content "$BASE_URL/assets/js/core/api.js" "export class ApiClient" "api.js exports ApiClient"
test_content "$BASE_URL/assets/js/core/state.js" "export class StateManager" "state.js exports StateManager"
test_content "$BASE_URL/assets/js/components/HelpPanel.js" "export class HelpPanel" "HelpPanel.js exports class"
test_content "$BASE_URL/assets/js/admin/GameControl.js" "export class GameControl" "GameControl.js exports class"
test_content "$BASE_URL/assets/js/player/BuzzerPhase.js" "export class BuzzerPhase" "BuzzerPhase.js exports class"

# ============================================================
section "7. API Backend Tests"
# ============================================================

info "Testing API endpoints..."

# Reset game first
api_test "resetGame" '{"action":"resetGame"}' '"success":true' "Reset game"

# Get game state
api_test "getGameState" '{"action":"getGameState"}' '"success":true' "Get game state"

# Join game as player
PLAYER_RESPONSE=$(curl -s -X POST "$API_URL" \
    -H "Content-Type: application/json" \
    -d '{"action":"joinGame","nickname":"TestPlayer"}')

if echo "$PLAYER_RESPONSE" | grep -q '"success":true'; then
    pass "API: Join game as TestPlayer"
    PLAYER_ID=$(echo "$PLAYER_RESPONSE" | grep -o '"playerId":"[^"]*"' | cut -d'"' -f4)
    info "Player ID: $PLAYER_ID"
else
    fail "API: Join game as TestPlayer"
fi

# Start game
api_test "startGame" '{"action":"startGame"}' '"success":true' "Start game"

# Get state after start
STATE_RESPONSE=$(curl -s -X POST "$API_URL" \
    -H "Content-Type: application/json" \
    -d '{"action":"getGameState"}')

if echo "$STATE_RESPONSE" | grep -q '"gameStarted":true'; then
    pass "API: Game is now started"
else
    fail "API: Game should be started"
fi

if echo "$STATE_RESPONSE" | grep -q '"phase":"question_shown"'; then
    pass "API: Phase is question_shown"
else
    fail "API: Phase should be question_shown"
fi

# Press buzzer
if [ -n "$PLAYER_ID" ]; then
    api_test "pressBuzzer" "{\"action\":\"pressBuzzer\",\"playerId\":\"$PLAYER_ID\"}" '"success":true' "Press buzzer"
fi

# Show options
api_test "showOptions" '{"action":"showOptions"}' '"success":true' "Show options"

# Submit answer
if [ -n "$PLAYER_ID" ]; then
    api_test "submitAnswer" "{\"action\":\"submitAnswer\",\"playerId\":\"$PLAYER_ID\",\"answer\":0}" '"success":true' "Submit answer"
fi

# Reveal correct
api_test "revealCorrect" '{"action":"revealCorrect"}' '"success":true' "Reveal correct answer"

# Next question
api_test "nextQuestion" '{"action":"nextQuestion"}' '"success":true' "Next question"

# Soft reset
api_test "softReset" '{"action":"softReset"}' '"success":true' "Soft reset"

# Final reset
api_test "resetGame" '{"action":"resetGame"}' '"success":true' "Final reset (cleanup)"

# ============================================================
section "8. Database Check"
# ============================================================

if [ -f "data/quiz.db" ]; then
    pass "SQLite database exists"
    
    # Check tables
    TABLES=$(sqlite3 data/quiz.db ".tables" 2>/dev/null || echo "")
    if echo "$TABLES" | grep -q "game_state"; then
        pass "game_state table exists"
    else
        fail "game_state table missing"
    fi
    
    if echo "$TABLES" | grep -q "questions"; then
        pass "questions table exists"
    else
        fail "questions table missing"
    fi
    
    if echo "$TABLES" | grep -q "players"; then
        pass "players table exists"
    else
        fail "players table missing"
    fi
else
    fail "SQLite database not found"
fi

# ============================================================
# Summary
# ============================================================
echo ""
echo "╔═══════════════════════════════════════════════════════════╗"
echo "║                    TEST SUMMARY                           ║"
echo "╠═══════════════════════════════════════════════════════════╣"
echo -e "║  ${GREEN}PASSED: $PASSED${NC}"
echo -e "║  ${RED}FAILED: $FAILED${NC}"
echo "╚═══════════════════════════════════════════════════════════╝"

if [ $FAILED -eq 0 ]; then
    echo ""
    echo -e "${GREEN}🎉 All tests passed! Ready for deployment.${NC}"
    exit 0
else
    echo ""
    echo -e "${RED}⚠️  Some tests failed. Please check the issues above.${NC}"
    exit 1
fi
