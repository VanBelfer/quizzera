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
     * Setup notes toggle button
     */
    setupNotesToggle() {
        const notesBtn = document.getElementById('studentNotesBtn');
        const notesPanel = document.getElementById('studentNotesPanel');
        const closeBtn = document.getElementById('closeStudentNotes');

        if (notesBtn && notesPanel) {
            notesBtn.addEventListener('click', () => {
                notesPanel.classList.toggle('hidden');
                this.loadStudentNotes();
            });

            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    notesPanel.classList.add('hidden');
                });
            }
        }
    }

    /**
     * Load student notes from server
     */
    async loadStudentNotes() {
        const contentEl = document.getElementById('studentNotesContent');
        if (!contentEl) return;

        try {
            const result = await this.api.getNotes();
            if (result.success && result.notes) {
                const renderer = new MarkdownRenderer();
                contentEl.innerHTML = renderer.render(result.notes.content || '');
            }
        } catch (error) {
            contentEl.innerHTML = '<p class="error">Failed to load notes</p>';
        }
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
        if (!gameState?.gameStarted) {
            this.handleWaitingState();
        } else if (gameState.phase === 'finished') {
            this.handleFinishedState(newState);
        } else {
            this.handleActiveGameState(newState, oldState);
        }

        // Update progress bar
        this.updateProgress(gameState, questions);

        // Update status message
        this.updateStatusMessage(gameState);
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

        // Generate performance summary
        this.modules.endScreen.showResults({
            questions: state.questions,
            answers: this.myAnswers,
            allAnswers: state.gameState?.answers || {},
            players: state.players,
            playerId: this.playerId
        });
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
