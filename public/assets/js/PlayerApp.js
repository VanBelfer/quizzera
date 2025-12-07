/**
 * PlayerApp - Main entry point for Quiz Player Interface
 * Initializes all modules and coordinates the player/student interface
 */

// Core modules
import { ApiClient } from './core/api.js';
import { StateManager } from './core/state.js';
import { onReady, debounce, escapeHtml, escapeHtmlWithBreaks, formatTime } from './core/utils.js';

// Shared components
import { HelpPanel } from './components/HelpPanel.js';
import { NetworkStatus } from './components/NetworkStatus.js';
import { MessageSystem, showSuccess, showError } from './components/MessageSystem.js';
import { MarkdownRenderer } from './components/MarkdownRenderer.js';
import { ActionFeedback } from './components/ActionFeedback.js';

// Make showSuccess/showError available globally for OptionsPhase
window.showSuccess = showSuccess;
window.showError = showError;

// Player modules
import { BuzzerPhase } from './player/BuzzerPhase.js';
import { OptionsPhase } from './player/OptionsPhase.js';
import { EndScreen } from './player/EndScreen.js';
import { AudioManager } from './player/AudioManager.js';
import { KeyboardShortcuts } from './player/KeyboardShortcuts.js';
import { ScreenManager } from './player/ScreenManager.js';

class PlayerApp {
    constructor() {
        // Initialize API client (relative path works in any subfolder)
        this.api = new ApiClient('api.php');

        // Initialize state manager with faster polling for players
        this.state = new StateManager(this.api, {
            pollingInterval: 500, // Faster polling for responsive gameplay
            autoStart: false      // Don't start until logged in
        });

        // Initialize message system for notifications
        this.messages = new MessageSystem();

        // Initialize action feedback for visual confirmations
        this.feedback = new ActionFeedback();

        // Player info
        this.playerId = localStorage.getItem('quizPlayerId');
        this.playerNickname = localStorage.getItem('quizPlayerNickname');

        // Track player's answers for this session
        this.myAnswers = {};

        // Current question tracking
        this.currentQuestionIndex = -1;

        // Module instances
        this.modules = {};

        // Bind methods
        this.handleStateChange = this.handleStateChange.bind(this);
        this.handleError = this.handleError.bind(this);
    }

    /**
     * Initialize the application
     */
    async init() {
        try {
            // Setup network status indicator
            this.networkStatus = new NetworkStatus();

            // Setup help panel
            this.helpPanel = new HelpPanel({
                toggleSelector: '#helpToggle',
                panelSelector: '#helpPanel',
                closeSelector: '#helpClose'
            });

            // Initialize audio manager
            this.modules.audio = new AudioManager({
                basePath: '/assets/sounds/'
            });

            // Initialize screen manager
            this.modules.screenManager = new ScreenManager({
                screens: {
                    login: '#loginScreen',
                    waiting: '#waitingScreen',
                    quiz: '#quizScreen',
                    end: '#endScreen'
                }
            });

            // Initialize buzzer phase handler
            this.modules.buzzer = new BuzzerPhase({
                api: this.api,
                buzzerBtn: '#buzzerBtn',
                containerSelector: '#buzzerPhase',
                audio: this.modules.audio,
                feedback: this.feedback,
                getPlayerId: () => this.playerId
            });

            // Initialize options phase handler
            this.modules.options = new OptionsPhase({
                api: this.api,
                containerSelector: '#optionsPhase',
                gridSelector: '#optionsGrid',
                audio: this.modules.audio,
                feedback: this.feedback,
                messages: this.messages,
                getPlayerId: () => this.playerId,
                onAnswerSubmit: (questionIndex, answerIndex) => {
                    this.myAnswers[questionIndex] = answerIndex;
                }
            });

            // Initialize end screen handler
            this.modules.endScreen = new EndScreen({
                containerSelector: '#endScreen',
                performanceSelector: '#performanceStats',
                breakdownSelector: '#questionBreakdown',
                downloadVocabBtn: '#downloadVocabularyBtn',
                downloadNotesBtn: '#downloadNotesBtn',
                newQuizBtn: '#newQuizBtn',
                api: this.api,
                markdownRenderer: new MarkdownRenderer(),
                onNewQuiz: () => this.handleNewQuiz()
            });

            // Initialize keyboard shortcuts
            this.modules.keyboard = new KeyboardShortcuts({
                buzzer: this.modules.buzzer,
                options: this.modules.options,
                enabled: true
            });

            // Call init() on all modules that have it
            Object.values(this.modules).forEach(module => {
                if (module.init) {
                    module.init();
                }
            });

            // Make modules globally accessible for onclick handlers
            window.optionsPhase = this.modules.options;
            window.buzzerPhase = this.modules.buzzer;

            // Subscribe to state changes
            this.state.subscribe(this.handleStateChange);

            // Setup global error handler
            window.addEventListener('unhandledrejection', (event) => {
                this.handleError(event.reason);
            });

            // Setup login form
            this.setupLoginForm();

            // Check if already logged in
            if (this.playerId && this.playerNickname) {
                await this.handleReturningPlayer();
            } else {
                this.modules.screenManager.show('login');
            }

            // Setup notes toggle
            this.setupNotesToggle();

            console.log('PlayerApp initialized successfully');

        } catch (error) {
            this.handleError(error);
        }
    }

    /**
     * Setup login form handler
     */
    setupLoginForm() {
        const form = document.getElementById('loginForm');
        const input = document.getElementById('nicknameInput');

        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const nickname = input?.value.trim();
            if (!nickname) {
                this.messages.warning('Please enter your name');
                return;
            }

            try {
                const result = await this.api.joinGame(nickname);

                if (result.success) {
                    this.playerId = result.playerId;
                    this.playerNickname = nickname;

                    // Store in localStorage for returning players
                    localStorage.setItem('quizPlayerId', this.playerId);
                    localStorage.setItem('quizPlayerNickname', nickname);

                    this.feedback.show('success', `Welcome, ${nickname}!`);

                    // Start polling and show waiting screen
                    this.state.startPolling();
                    this.modules.screenManager.show('waiting');

                    // Load initial notes
                    await this.loadPlayerNotes();

                    // Check current game state
                    await this.state.refresh();
                } else {
                    this.messages.error(result.error || 'Failed to join game');
                }
            } catch (error) {
                this.handleError(error);
            }
        });
    }

    /**
     * Handle returning player (already has stored credentials)
     */
    async handleReturningPlayer() {
        try {
            // Verify the player still exists
            const result = await this.api.joinGame(this.playerNickname);

            if (result.success) {
                this.playerId = result.playerId;

                // Update stored ID in case it changed
                localStorage.setItem('quizPlayerId', this.playerId);

                // Start polling
                this.state.startPolling();
                this.modules.screenManager.show('waiting');

                // Load initial notes
                await this.loadPlayerNotes();

                this.feedback.show('success', `Welcome back, ${this.playerNickname}!`);
            } else {
                // Clear stored data and show login
                this.clearPlayerData();
                this.modules.screenManager.show('login');
            }
        } catch (error) {
            this.clearPlayerData();
            this.modules.screenManager.show('login');
        }
    }

    /**
     * Clear player data from localStorage
     */
    clearPlayerData() {
        localStorage.removeItem('quizPlayerId');
        localStorage.removeItem('quizPlayerNickname');
        this.playerId = null;
        this.playerNickname = null;
        this.myAnswers = {};
    }

    /**
     * Handle state changes from StateManager
     */
    handleStateChange(newState, oldState) {
        const gameState = newState.gameState;
        const questions = newState.questions || [];
        const players = newState.players || [];

        // Session Validation: Check if we were kicked/reset
        if (this.playerId && gameState?.gameStarted) {
            // Only check if we are supposedly logged in
            const isPlayerActive = players.some(p => p.id === this.playerId);
            if (!isPlayerActive) {
                console.warn('Player session invalid (kicked or reset), logging out...');
                this.handleLogout();
                return; // Stop processing state
            }
        }

        // Detect question change
        const questionChanged = gameState?.currentQuestion !== this.currentQuestionIndex;
        if (questionChanged) {
            this.currentQuestionIndex = gameState?.currentQuestion || 0;
            this.modules.options.reset();
            this.modules.buzzer.reset();  // Reset buzzer state for new question
        }

        // Handle different game phases
        // Check 'finished' phase FIRST because gameStarted is set to false when finished
        if (gameState?.phase === 'finished') {
            this.handleFinishedState(newState);
        } else if (!gameState?.gameStarted) {
            this.handleWaitingState();
        } else {
            this.handleActiveGameState(newState, oldState);
        }

        // Update progress bar
        this.updateProgress(gameState, questions);

        // Update status message
        this.updateStatusMessage(gameState);

        // Check for notes updates when stateVersion changes
        const newVersion = newState.stateVersion;
        if (this.lastStateVersion !== undefined && newVersion !== this.lastStateVersion) {
            this.checkNotesUpdate();
        }
        this.lastStateVersion = newVersion;
    }

    /**
     * Handle waiting state (game not started)
     */
    handleWaitingState() {
        if (this.playerId) {
            this.modules.screenManager.show('waiting');
        }
    }

    /**
     * Handle finished state (quiz complete)
     */
    handleFinishedState(state) {
        this.modules.screenManager.show('end');

        // Set player ID and show end screen with results
        this.modules.endScreen.setPlayerId(this.playerId);
        this.modules.endScreen.show();
    }

    /**
     * Handle active game state
     */
    handleActiveGameState(state, oldState) {
        const gameState = state.gameState;
        const questions = state.questions || [];
        const currentQ = questions[gameState.currentQuestion];

        // Show quiz screen
        this.modules.screenManager.show('quiz');

        // Update question display
        this.updateQuestionDisplay(currentQ, gameState.currentQuestion, questions.length);

        // Handle phase transitions
        const phase = gameState.phase;
        const oldPhase = oldState?.gameState?.phase;

        if (phase === 'question_shown') {
            this.handleQuestionPhase(gameState);
        } else if (phase === 'options_shown') {
            // Ensure we pass the full question data so options can be rendered
            this.handleOptionsPhase(currentQ, gameState);
        } else if (phase === 'reveal') {
            this.handleRevealPhase(currentQ, gameState);
        }

        // Play transition sounds
        if (phase !== oldPhase) {
            this.playPhaseTransitionSound(phase);
        }
    }

    /**
     * Update question display
     */
    updateQuestionDisplay(question, index, total) {
        const numberEl = document.getElementById('questionNumber');
        const textEl = document.getElementById('questionText');
        const imageEl = document.getElementById('questionImage');

        if (numberEl) {
            numberEl.innerHTML = `<i class="fas fa-question-circle"></i> Question ${index + 1} of ${total}`;
        }

        if (textEl && question) {
            // Use innerHTML with escaped text to preserve line breaks
            textEl.innerHTML = escapeHtmlWithBreaks(question.question);
        }

        if (imageEl && question) {
            if (question.image) {
                imageEl.src = question.image;
                imageEl.classList.remove('hidden');
            } else {
                imageEl.classList.add('hidden');
            }
        }
    }

    /**
     * Handle question phase (buzzer active)
     */
    handleQuestionPhase(gameState) {
        // Show buzzer phase
        document.getElementById('buzzerPhase')?.classList.remove('hidden');
        document.getElementById('optionsPhase')?.classList.add('hidden');
        document.getElementById('revealPhase')?.classList.add('hidden');

        // Check if player already buzzed or is marked as spoken
        const hasBuzzed = gameState.buzzers?.some(b => b.playerId === this.playerId);
        const hasSpoken = gameState.spokenPlayers?.includes(this.playerId);

        if (hasBuzzed || hasSpoken) {
            this.modules.buzzer.disable('Already buzzed');
        } else {
            this.modules.buzzer.enable();
        }
    }

    /**
     * Handle options phase (answering)
     */
    handleOptionsPhase(question, gameState) {
        // Show options phase
        document.getElementById('buzzerPhase')?.classList.add('hidden');
        document.getElementById('optionsPhase')?.classList.remove('hidden');
        document.getElementById('revealPhase')?.classList.add('hidden');

        // Ensure options module is shown with data
        // show() will call renderOptions() internally, so we don't need to call it again
        this.modules.options.show(gameState, question);
    }

    /**
     * Handle reveal phase (showing correct answer)
     */
    handleRevealPhase(question, gameState) {
        // Show reveal phase
        document.getElementById('buzzerPhase')?.classList.add('hidden');
        document.getElementById('optionsPhase')?.classList.add('hidden');
        document.getElementById('revealPhase')?.classList.remove('hidden');

        // Show correct answer
        const correctEl = document.getElementById('correctAnswerText');
        if (correctEl && question) {
            correctEl.textContent = question.options[question.correct];
        }

        // Show explanation if available
        const explanationSection = document.getElementById('explanationSection');
        const explanationText = document.getElementById('questionExplanation');

        if (question?.explanation && explanationSection && explanationText) {
            explanationSection.classList.remove('hidden');
            explanationText.textContent = question.explanation;
        } else if (explanationSection) {
            explanationSection.classList.add('hidden');
        }

        // Play correct/incorrect sound based on player's answer
        // answers is an array of {playerId, question, answer, isCorrect}
        const answers = gameState.answers || [];
        const playerAnswer = answers.find(a => 
            a.playerId === this.playerId && a.question === gameState.currentQuestion
        );
        if (playerAnswer) {
            if (playerAnswer.answer === question.correct) {
                this.modules.audio.play('correct');
            } else {
                this.modules.audio.play('incorrect');
            }
        }
    }

    /**
     * Update progress bar
     */
    updateProgress(gameState, questions) {
        const progressBar = document.getElementById('quizProgress');
        if (!progressBar) return;

        if (gameState?.gameStarted && questions.length > 0) {
            const progress = ((gameState.currentQuestion + 1) / questions.length) * 100;
            progressBar.style.width = `${Math.min(100, progress)}%`;
        } else {
            progressBar.style.width = '0%';
        }
    }

    /**
     * Update status message
     */
    updateStatusMessage(gameState) {
        const statusEl = document.getElementById('statusMessage');
        if (!statusEl) return;

        const phase = gameState?.phase;
        const messages = {
            'waiting': { text: 'Waiting for teacher...', class: 'status-waiting' },
            'question_shown': { text: 'Buzz in if you know the answer!', class: 'status-buzzer' },
            'options_shown': { text: 'Select your answer', class: 'status-options' },
            'reveal': { text: 'Correct answer revealed', class: 'status-reveal' },
            'finished': { text: 'Quiz complete!', class: 'status-finished' }
        };

        const msgInfo = messages[phase] || messages.waiting;
        statusEl.textContent = msgInfo.text;
        statusEl.className = `status-message ${msgInfo.class}`;
    }

    /**
     * Play sound for phase transition
     */
    playPhaseTransitionSound(phase) {
        switch (phase) {
            case 'question_shown':
                this.modules.audio.play('tick');
                break;
            case 'options_shown':
                // No sound for options
                break;
            case 'reveal':
                // Sound handled in handleRevealPhase based on answer
                break;
        }
    }

    /**
     * Handle new quiz request
     */
    handleNewQuiz() {
        this.myAnswers = {};
        this.currentQuestionIndex = -1;
        this.modules.screenManager.show('waiting');
    }

    /**
     * Setup notes toggle button and panel
     */
    setupNotesToggle() {
        const toggleBtn = document.getElementById('notesToggle');
        const panel = document.getElementById('playerNotesPanel');
        const closeBtn = document.getElementById('notesClose');
        
        if (!toggleBtn || !panel) {
            console.log('Notes panel elements not found');
            return;
        }

        // Toggle panel visibility
        toggleBtn.addEventListener('click', () => {
            panel.classList.toggle('hidden');
            // Clear badge when opening panel
            const badge = document.getElementById('notesBadge');
            if (badge) {
                badge.classList.add('hidden');
            }
        });

        // Close button
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                panel.classList.add('hidden');
            });
        }

        // Close on clicking outside
        document.addEventListener('click', (e) => {
            if (!panel.classList.contains('hidden') && 
                !panel.contains(e.target) && 
                !toggleBtn.contains(e.target)) {
                panel.classList.add('hidden');
            }
        });

        console.log('Notes toggle setup complete');
    }

    /**
     * Load player notes from server
     */
    async loadPlayerNotes() {
        try {
            const result = await this.api.getStudentNotes();
            if (result.success && result.notes) {
                const content = result.notes.content || '';
                this.lastNotesContent = content;
                this.displayPlayerNotes(content);
                
                // Show toggle button if there are notes
                const toggleBtn = document.getElementById('notesToggle');
                if (toggleBtn) {
                    toggleBtn.style.display = content.trim() ? 'flex' : 'none';
                }
            }
        } catch (error) {
            console.error('Error loading player notes:', error);
        }
    }

    /**
     * Check for notes updates (called when stateVersion changes)
     */
    async checkNotesUpdate() {
        try {
            const result = await this.api.getStudentNotes();
            if (!result.success || !result.notes) return;

            const content = result.notes.content || '';

            // If notes changed, show notification
            if (this.lastNotesContent !== undefined && 
                content !== this.lastNotesContent &&
                content.trim()) {
                
                this.lastNotesContent = content;
                this.displayPlayerNotes(content);

                // Show badge on toggle button
                const badge = document.getElementById('notesBadge');
                if (badge) {
                    badge.classList.remove('hidden');
                }

                // Show toast notification
                this.messages.info('📝 Teacher updated the notes!');
                
                // Play sound (tick as notification)
                this.modules.audio?.play('tick');

                // Show toggle button if hidden
                const toggleBtn = document.getElementById('notesToggle');
                if (toggleBtn) {
                    toggleBtn.style.display = 'flex';
                }
            } else if (this.lastNotesContent === undefined) {
                // First load
                this.lastNotesContent = content;
                this.displayPlayerNotes(content);
                
                const toggleBtn = document.getElementById('notesToggle');
                if (toggleBtn && content.trim()) {
                    toggleBtn.style.display = 'flex';
                }
            }
        } catch (error) {
            console.error('Error checking notes update:', error);
        }
    }

    /**
     * Display notes in the panel
     */
    displayPlayerNotes(notesContent) {
        const contentEl = document.getElementById('playerNotesContent');
        if (!contentEl) return;

        if (notesContent && notesContent.trim()) {
            // Render markdown content
            const md = new MarkdownRenderer();
            contentEl.innerHTML = md.render(notesContent);
        } else {
            contentEl.innerHTML = '<p class="no-notes">No notes available yet.</p>';
        }
    }

    /**
     * Handle logout (manual or forced)
     */
    handleLogout() {
        this.clearPlayerData();
        this.state.stopPolling();
        this.modules.screenManager.show('login');
        this.feedback.show('info', 'Session ended');
    }

    /**
     * Handle errors
     */
    handleError(error) {
        console.error('PlayerApp error:', error);
        this.messages.error(error.message || 'An unexpected error occurred');
    }

    /**
     * Cleanup
     */
    destroy() {
        this.state.stopPolling();
        this.state.unsubscribe(this.handleStateChange);

        Object.values(this.modules).forEach(module => {
            if (module.destroy) {
                module.destroy();
            }
        });
    }
}

// Initialize app when DOM is ready
onReady(() => {
    window.playerApp = new PlayerApp();
    window.playerApp.init();
});

export { PlayerApp };
