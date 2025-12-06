/**
 * Admin Game Control Module
 * Handles game flow: start, next question, show options, reveal, reset
 */

import { api as defaultApi } from '../core/api.js';
import { showSuccessFeedback, showErrorFeedback } from '../components/ActionFeedback.js';
import { showSuccess, showError } from '../components/MessageSystem.js';

export class GameControl {
    constructor(options = {}) {
        this.options = {
            buttonsContainerId: 'gameControlButtons',
            onStateChange: null,
            ...options
        };

        // Use provided API or fallback to default singleton
        this.api = this.options.api || defaultApi;
        this.buttonsContainer = null;
    }

    init() {
        this.buttonsContainer = document.getElementById(this.options.buttonsContainerId);
    }

    /**
     * Start the game
     */
    async startGame() {
        try {
            const result = await this.api.startGame();
            if (result.success) {
                showSuccessFeedback('Game Started!');
                this.notifyStateChange();
            } else {
                showErrorFeedback(result.error || 'Failed to start game');
            }
            return result;
        } catch (error) {
            showErrorFeedback('Network error');
            console.error('Start game error:', error);
        }
    }

    /**
     * Go to next question
     */
    async nextQuestion() {
        try {
            const result = await this.api.nextQuestion();
            if (result.success) {
                showSuccessFeedback('Next Question');
                this.notifyStateChange();
            } else {
                showErrorFeedback(result.error || 'Failed to advance');
            }
            return result;
        } catch (error) {
            showErrorFeedback('Network error');
            console.error('Next question error:', error);
        }
    }

    /**
     * Show answer options to players
     */
    async showOptions() {
        try {
            const result = await this.api.showOptions();
            if (result.success) {
                showSuccessFeedback('Options Shown');
                this.notifyStateChange();
            } else {
                showErrorFeedback(result.error || 'Failed to show options');
            }
            return result;
        } catch (error) {
            showErrorFeedback('Network error');
            console.error('Show options error:', error);
        }
    }

    /**
     * Reveal correct answer
     */
    async revealCorrect() {
        try {
            const result = await this.api.revealCorrect();
            if (result.success) {
                showSuccessFeedback('Answer Revealed');
                this.notifyStateChange();
            } else {
                showErrorFeedback(result.error || 'Failed to reveal');
            }
            return result;
        } catch (error) {
            showErrorFeedback('Network error');
            console.error('Reveal correct error:', error);
        }
    }

    /**
     * Soft reset (current question only)
     */
    async softReset() {
        if (!confirm('Reset current question? This will clear buzzers and answers for this question.')) {
            return;
        }

        try {
            const result = await this.api.softReset();
            if (result.success) {
                showSuccess('Question reset');
                this.notifyStateChange();
            } else {
                showError(result.error || 'Failed to reset');
            }
            return result;
        } catch (error) {
            showError('Network error');
            console.error('Soft reset error:', error);
        }
    }

    /**
     * Full game reset
     */
    async resetGame() {
        if (!confirm('Reset entire game? All progress will be lost!')) {
            return;
        }

        try {
            const result = await this.api.resetGame();
            if (result.success) {
                showSuccess('Game reset completely');
                this.notifyStateChange();
            } else {
                showError(result.error || 'Failed to reset game');
            }
            return result;
        } catch (error) {
            showError('Network error');
            console.error('Reset game error:', error);
        }
    }

    /**
     * Mark player as spoken (for buzzer queue)
     */
    async markSpoken(playerId) {
        try {
            const result = await this.api.markSpoken(playerId);
            if (result.success) {
                this.notifyStateChange();
            }
            return result;
        } catch (error) {
            console.error('Mark spoken error:', error);
        }
    }

    /**
     * Update UI buttons based on game state
     */
    updateButtons(gameState) {
        if (!this.buttonsContainer || !gameState) return;

        let html = '';

        if (!gameState.gameStarted || gameState.phase === 'finished') {
            html = `
                <button class="btn btn-success" onclick="window.gameControl.startGame()">
                    <i class="fas fa-play"></i> Start Game
                </button>
            `;
        } else {
            // Game is active
            if (gameState.phase === 'question_shown') {
                html = `
                    <button class="btn btn-primary" onclick="window.gameControl.showOptions()">
                        <i class="fas fa-list"></i> Show Options
                    </button>
                `;
            } else if (gameState.phase === 'options_shown') {
                html = `
                    <button class="btn btn-success" onclick="window.gameControl.revealCorrect()">
                        <i class="fas fa-check"></i> Reveal Answer
                    </button>
                `;
            } else if (gameState.phase === 'reveal') {
                html = `
                    <button class="btn btn-primary" onclick="window.gameControl.nextQuestion()">
                        <i class="fas fa-forward"></i> Next Question
                    </button>
                `;
            }

            // Always show reset buttons during game
            html += `
                <button class="btn btn-warning" onclick="window.gameControl.softReset()">
                    <i class="fas fa-undo"></i> Reset Question
                </button>
                <button class="btn btn-danger" onclick="window.gameControl.resetGame()">
                    <i class="fas fa-trash"></i> Reset Game
                </button>
            `;
        }

        this.buttonsContainer.innerHTML = html;
    }

    /**
     * Notify parent of state change
     */
    notifyStateChange() {
        if (this.options.onStateChange) {
            this.options.onStateChange();
        }
    }
}

// Factory function
export function initGameControl(options = {}) {
    const control = new GameControl(options);
    control.init();

    // Make globally accessible for button onclick handlers
    window.gameControl = control;

    return control;
}
