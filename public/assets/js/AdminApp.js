/**
 * AdminApp - Main entry point for Admin Dashboard
 * Initializes all modules and coordinates the admin interface
 */

// Core modules
import { ApiClient } from './core/api.js';
import { StateManager } from './core/state.js';
import { onReady, debounce, escapeHtml, formatTime } from './core/utils.js';

// Shared components
import { HelpPanel } from './components/HelpPanel.js';
import { NetworkStatus } from './components/NetworkStatus.js';
import { MessageSystem } from './components/MessageSystem.js';
import { MarkdownRenderer } from './components/MarkdownRenderer.js';
import { Modal } from './components/Modal.js';
import { ActionFeedback } from './components/ActionFeedback.js';

// Admin modules
import { GameControl } from './admin/GameControl.js';
import { QuestionEditor } from './admin/QuestionEditor.js';
import { SessionManager } from './admin/SessionManager.js';
import { NotesEditor } from './admin/NotesEditor.js';
import { TabsNavigation } from './admin/TabsNavigation.js';

class AdminApp {
    constructor() {
        // Initialize API client (relative path works in any subfolder)
        this.api = new ApiClient('api.php');

        // Initialize state manager with polling (don't auto-start)
        this.state = new StateManager(this.api, {
            pollingInterval: 1000,
            autoStart: false  // We'll start after subscribing
        });

        // Initialize message system for notifications
        this.messages = new MessageSystem();

        // Initialize action feedback for confirmations
        this.feedback = new ActionFeedback();

        // Module instances (will be initialized in init())
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

            // Initialize tabs navigation
            this.modules.tabs = new TabsNavigation({
                tabsSelector: '.tabs .tab',
                contentPrefix: 'Tab',
                onTabChange: (tabId) => this.onTabChange(tabId)
            });

            // Initialize game control module
            this.modules.gameControl = new GameControl({
                api: this.api,
                state: this.state,
                messages: this.messages,
                feedback: this.feedback,
                onAction: (action) => this.handleGameAction(action)
            });

            // Initialize question editor
            this.modules.questionEditor = new QuestionEditor({
                api: this.api,
                messages: this.messages,
                textareaSelector: '#questionsJson',
                onUpdate: () => this.state.refresh()
            });

            // Initialize session manager
            this.modules.sessionManager = new SessionManager({
                api: this.api,
                messages: this.messages,
                feedback: this.feedback,
                saveBtn: '#saveSessionBtn',
                loadBtn: '#loadSessionBtn',
                onLoad: () => this.state.refresh()
            });

            // Initialize notes editor
            this.modules.notesEditor = new NotesEditor({
                api: this.api,
                messages: this.messages,
                toggleBtnId: 'notesBtn',
                markdownRenderer: new MarkdownRenderer()
            });

            // Call init() on all modules
            Object.values(this.modules).forEach(module => {
                if (module.init) {
                    module.init();
                }
            });

            // Make modules globally accessible for onclick handlers
            window.gameControl = this.modules.gameControl;
            window.sessionManager = this.modules.sessionManager;

            // Subscribe to state changes
            this.state.subscribe(this.handleStateChange);

            // Start polling now that we're subscribed
            this.state.startPolling();

            // Setup global error handler
            window.addEventListener('unhandledrejection', (event) => {
                this.handleError(event.reason);
            });

            // Initial state fetch
            await this.state.refresh();

            // Setup UI event listeners
            this.setupEventListeners();

            console.log('AdminApp initialized successfully');

        } catch (error) {
            this.handleError(error);
        }
    }

    /**
     * Setup UI event listeners
     */
    setupEventListeners() {
        // Template button - opens template manager
        const templateBtn = document.getElementById('templateBtn');
        if (templateBtn) {
            templateBtn.addEventListener('click', () => {
                window.open('template_manager.php', 'templateManager',
                    'width=1200,height=800,scrollbars=yes,resizable=yes');
            });
        }

        // Listen for messages from template manager
        window.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'loadTemplate') {
                this.loadTemplateQuestions(event.data.questions);
            }
        });

        // Player list toggle
        const playerBadge = document.getElementById('playerBadge');
        if (playerBadge) {
            playerBadge.addEventListener('click', () => this.togglePlayerList());
        }

        // Results navigation
        const prevBtn = document.getElementById('prevQuestionBtn');
        const nextBtn = document.getElementById('nextQuestionBtn');

        if (prevBtn) {
            prevBtn.addEventListener('click', () => this.navigateResults(-1));
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => this.navigateResults(1));
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl+S to save session
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                this.modules.sessionManager.showSaveModal();
            }
            // Ctrl+O to load session
            if (e.ctrlKey && e.key === 'o') {
                e.preventDefault();
                this.modules.sessionManager.showLoadModal();
            }
        });
    }

    /**
     * Load questions from template manager
     */
    async loadTemplateQuestions(questions) {
        if (!questions || !Array.isArray(questions)) {
            this.messages.error('Invalid template data');
            return;
        }

        try {
            // Update the questions textarea
            const textarea = document.getElementById('questionsJson');
            if (textarea) {
                textarea.value = JSON.stringify(questions, null, 2);
            }

            // Save to server
            const result = await this.api.updateQuestions(questions);

            if (result.success) {
                this.messages.success(`Template loaded! ${questions.length} questions added.`);
                this.feedback.show('Template Applied!', 'success');
                await this.state.refresh();

                // Switch to questions tab to show the loaded questions
                this.modules.tabs.showTab('questions');
            } else {
                this.messages.error(result.error || 'Failed to apply template');
            }
        } catch (error) {
            this.handleError(error);
        }
    }

    /**
     * Handle state changes from StateManager
     */
    handleStateChange(newState, oldState) {
        // Update game status badge
        this.updateGameStatusBadge(newState.gameState);

        // Update player count
        this.updatePlayerCount(newState.players);

        // Update progress bar
        this.updateProgress(newState.gameState, newState.questions);

        // Update current question display
        this.updateCurrentQuestion(newState);

        // Update game control buttons
        this.modules.gameControl.updateButtons(newState.gameState);

        // Update phase indicator
        this.updatePhaseIndicator(newState.gameState?.phase);

        // Update results if on results tab
        if (this.modules.tabs.activeTab === 'results') {
            this.updateResultsView(newState);
        }

        // Check for new buzzers
        if (newState.gameState?.buzzers?.length > (oldState?.gameState?.buzzers?.length || 0)) {
            this.onNewBuzzer(newState.gameState.buzzers);
        }
    }

    /**
     * Update game status badge
     */
    updateGameStatusBadge(gameState) {
        const badge = document.getElementById('gameStatusBadge');
        if (!badge) return;

        let html = '';
        if (!gameState?.gameStarted) {
            html = '<span class="status-waiting">Waiting</span>';
        } else if (gameState.phase === 'finished') {
            html = '<span class="status-finished">Finished</span>';
        } else {
            html = '<span class="status-active">Active</span>';
        }

        badge.innerHTML = html;
    }

    /**
     * Update player count badge
     */
    updatePlayerCount(players) {
        const countEl = document.getElementById('playerCount');
        if (countEl && players) {
            countEl.textContent = players.length;
        }
    }

    /**
     * Update progress bar
     */
    updateProgress(gameState, questions) {
        const progressBar = document.getElementById('quizProgress');
        if (!progressBar || !questions) return;

        const totalQuestions = questions.length;
        const currentQuestion = gameState?.currentQuestion || 0;

        if (totalQuestions > 0 && gameState?.gameStarted) {
            const progress = Math.min(100, ((currentQuestion + 1) / totalQuestions) * 100);
            progressBar.style.width = `${progress}%`;
        } else {
            progressBar.style.width = '0%';
        }
    }

    /**
     * Update phase indicator
     */
    updatePhaseIndicator(phase) {
        const indicator = document.getElementById('phaseIndicator');
        if (!indicator) return;

        const phases = {
            'waiting': { text: 'Waiting', class: 'phase-waiting' },
            'question_shown': { text: 'Question Phase', class: 'phase-question' },
            'options_shown': { text: 'Options Phase', class: 'phase-options' },
            'reveal': { text: 'Reveal Phase', class: 'phase-reveal' },
            'finished': { text: 'Finished', class: 'phase-finished' }
        };

        const phaseInfo = phases[phase] || phases.waiting;
        indicator.textContent = phaseInfo.text;
        indicator.className = `phase-indicator ${phaseInfo.class}`;
    }

    /**
     * Update current question display
     */
    updateCurrentQuestion(state) {
        const container = document.getElementById('currentQuestionContainer');
        if (!container) return;

        const { gameState, questions } = state;

        if (!gameState?.gameStarted || !questions?.length) {
            container.innerHTML = `
                <div class="current-question-card">
                    <div class="question-preview">
                        <p style="color: var(--text-gray-400); text-align: center;">
                            <i class="fas fa-play-circle" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
                            Start the quiz to see questions here
                        </p>
                    </div>
                </div>
            `;
            return;
        }

        const currentQ = questions[gameState.currentQuestion];
        if (!currentQ) return;

        const buzzersHtml = this.renderBuzzersList(gameState.buzzers, state.players);
        const optionsHtml = this.renderOptionsPreview(currentQ, gameState);

        container.innerHTML = `
            <div class="current-question-card">
                <div class="question-preview">
                    <div class="question-number">
                        Question ${gameState.currentQuestion + 1} of ${questions.length}
                    </div>
                    <div class="question-text">${escapeHtml(currentQ.question)}</div>
                    ${currentQ.image ? `<img src="${escapeHtml(currentQ.image)}" class="question-image" alt="Question image">` : ''}
                </div>
                
                ${optionsHtml}
                
                <div class="buzzers-section">
                    <h4><i class="fas fa-bell"></i> Buzzers (${gameState.buzzers?.length || 0})</h4>
                    ${buzzersHtml}
                </div>
                
                ${currentQ.explanation ? `
                    <div class="explanation-section">
                        <h4><i class="fas fa-lightbulb"></i> Teaching Notes</h4>
                        <p>${escapeHtml(currentQ.explanation)}</p>
                    </div>
                ` : ''}
            </div>
        `;

        // Setup mark as spoken buttons
        this.setupSpokenButtons(container);
    }

    /**
     * Render buzzers list
     */
    renderBuzzersList(buzzers, players) {
        if (!buzzers?.length) {
            return '<p class="no-buzzers">No buzzers yet</p>';
        }

        const playerMap = {};
        players?.forEach(p => playerMap[p.id] = p);

        return `
            <ul class="buzzers-list">
                ${buzzers.map((buzz, index) => {
            const player = playerMap[buzz.playerId];
            const isFirst = index === 0;
            return `
                        <li class="buzzer-item ${isFirst ? 'first-buzzer' : ''}">
                            <span class="buzzer-rank">#${index + 1}</span>
                            <span class="buzzer-name">${escapeHtml(player?.nickname || buzz.playerId)}</span>
                            <span class="buzzer-time">${formatTime(buzz.timestamp)}</span>
                            <button class="btn btn-sm btn-mark-spoken" data-player-id="${buzz.playerId}">
                                Mark as Spoken
                            </button>
                        </li>
                    `;
        }).join('')}
            </ul>
        `;
    }

    /**
     * Render options preview for admin
     */
    renderOptionsPreview(question, gameState) {
        if (!question.options || gameState.phase === 'question_shown') {
            return '';
        }

        const answers = gameState.answers || {};

        return `
            <div class="options-preview">
                <h4><i class="fas fa-list"></i> Answer Options</h4>
                <div class="options-grid-admin">
                    ${question.options.map((opt, index) => {
            const isCorrect = index === question.correct;
            const answerCount = Object.values(answers).filter(a => a === index).length;
            return `
                            <div class="option-preview ${isCorrect && gameState.phase === 'reveal' ? 'correct' : ''}">
                                <span class="option-letter">${String.fromCharCode(65 + index)}</span>
                                <span class="option-text">${escapeHtml(opt)}</span>
                                <span class="answer-count">${answerCount} answers</span>
                            </div>
                        `;
        }).join('')}
                </div>
            </div>
        `;
    }

    /**
     * Setup mark as spoken buttons
     */
    setupSpokenButtons(container) {
        container.querySelectorAll('.btn-mark-spoken').forEach(btn => {
            btn.addEventListener('click', async () => {
                const playerId = btn.dataset.playerId;
                try {
                    await this.api.markSpoken(playerId);
                    this.messages.success('Player marked as spoken');
                    this.state.refresh();
                } catch (error) {
                    this.messages.error('Failed to mark player');
                }
            });
        });
    }

    /**
     * Toggle player list dropdown
     */
    togglePlayerList() {
        const list = document.getElementById('playerList');
        const toggle = document.getElementById('playerListToggle');

        if (list) {
            list.classList.toggle('hidden');
            if (toggle) {
                toggle.classList.toggle('fa-chevron-down');
                toggle.classList.toggle('fa-chevron-up');
            }

            // Update player list content
            if (!list.classList.contains('hidden')) {
                this.updatePlayerListContent();
            }
        }
    }

    /**
     * Update player list content
     */
    updatePlayerListContent() {
        const list = document.getElementById('playerList');
        if (!list) return;

        const players = this.state.state?.players || [];

        if (players.length === 0) {
            list.innerHTML = '<p class="no-players">No players yet</p>';
            return;
        }

        list.innerHTML = players.map(player => `
            <div class="player-item">
                <span class="player-name">${escapeHtml(player.nickname)}</span>
                <span class="player-joined">${player.joinedAt}</span>
            </div>
        `).join('');
    }

    /**
     * Handle tab change
     */
    onTabChange(tabId) {
        if (tabId === 'results') {
            this.updateResultsView(this.state.state);
        }
    }

    /**
     * Update results view
     */
    updateResultsView(state) {
        const container = document.getElementById('resultsContainer');
        if (!container) return;

        // Implementation of results view...
        // This would render the detailed results for each question
    }

    /**
     * Navigate through results
     */
    navigateResults(direction) {
        // Navigate through question results
        this.resultsQuestionIndex = (this.resultsQuestionIndex || 0) + direction;
        const questions = this.state.state?.questions || [];

        // Clamp to valid range
        this.resultsQuestionIndex = Math.max(0, Math.min(this.resultsQuestionIndex, questions.length - 1));

        this.updateResultsView(this.state.state);
    }

    /**
     * Handle new buzzer event
     */
    onNewBuzzer(buzzers) {
        if (buzzers.length > 0) {
            const players = this.state.state?.players || [];
            const firstBuzzer = buzzers[0];
            const player = players.find(p => p.id === firstBuzzer.playerId);

            this.feedback.show('info', `${player?.nickname || 'Someone'} buzzed first!`);

            // Play sound effect if available
            this.playBuzzerSound();
        }
    }

    /**
     * Play buzzer sound
     */
    playBuzzerSound() {
        try {
            const audio = new Audio('/assets/sounds/buzz.mp3');
            audio.volume = 0.5;
            audio.play().catch(() => {
                // Audio play failed, ignore
            });
        } catch (e) {
            // No sound available
        }
    }

    /**
     * Handle game action from GameControl
     */
    handleGameAction(action) {
        console.log('Game action:', action);
        this.state.refresh();
    }

    /**
     * Handle errors
     */
    handleError(error) {
        console.error('AdminApp error:', error);
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
    window.adminApp = new AdminApp();
    window.adminApp.init();
});

export { AdminApp };
