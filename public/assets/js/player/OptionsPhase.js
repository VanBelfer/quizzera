/**
 * Player Options Phase Module
 * Handles answer selection and submission
 */

import { api as defaultApi } from '../core/api.js';
import { showError } from '../components/MessageSystem.js';

export class OptionsPhase {
    constructor(options = {}) {
        this.options = {
            containerSelector: '#optionsPhase',
            gridSelector: '#optionsGrid',
            onAnswered: null,
            onAnswerSubmit: null,
            getPlayerId: null,
            ...options
        };

        // Use provided API or fallback to default singleton
        this.api = this.options.api || defaultApi;

        this.container = null;
        this.grid = null;
        this.playerId = null;
        this.selectedAnswer = null;
        this.hasAnswered = false;
        this.correctAnswer = null;
    }

    init() {
        this.container = document.querySelector(this.options.containerSelector);
        this.grid = document.querySelector(this.options.gridSelector);
    }

    /**
     * Set player ID
     */
    setPlayerId(id) {
        this.playerId = id;
    }

    /**
     * Show options phase with question data
     */
    show(gameState, questionData) {
        if (!this.container || !this.grid) return;

        this.container.classList.remove('hidden');

        // Resolve player ID if not set
        if (!this.playerId && this.options.getPlayerId) {
            this.playerId = this.options.getPlayerId();
        }

        // Check if player already answered this question
        // answers is an array of {playerId, question, answer, isCorrect}
        const answers = gameState.answers || [];
        const playerAnswer = answers.find(a => 
            a.playerId === this.playerId && a.question === gameState.currentQuestion
        );

        if (playerAnswer) {
            this.hasAnswered = true;
            this.selectedAnswer = playerAnswer.answer;
        }

        this.renderOptions(questionData.options, gameState.phase === 'reveal');
    }

    /**
     * Render option buttons
     */
    renderOptions(options, isReveal = false) {
        if (!this.grid || !options) return;

        const letters = ['A', 'B', 'C', 'D', 'E', 'F'];

        let html = '';
        options.forEach((option, index) => {
            let classes = 'option-btn';
            let disabled = this.hasAnswered || isReveal;

            // Apply states
            if (this.selectedAnswer === index) {
                classes += ' selected';
            }

            if (isReveal) {
                if (index === this.correctAnswer) {
                    classes += ' correct-answer';
                } else if (this.selectedAnswer === index && this.selectedAnswer !== this.correctAnswer) {
                    classes += ' incorrect';
                }
            }

            html += `
                <button class="${classes}" 
                        data-index="${index}"
                        ${disabled ? 'disabled' : ''}
                        onclick="window.optionsPhase.selectAnswer(${index}, this).catch(console.error)">
                    <span class="option-letter">${letters[index]}</span>
                    ${this.escapeHtml(option)}
                </button>
            `;
        });

        this.grid.innerHTML = html;
    }

    /**
     * Select an answer
     */
    async selectAnswer(answerIndex, buttonElement) {
        // Resolve player ID from option if not set directly
        if (!this.playerId && this.options.getPlayerId) {
            this.playerId = this.options.getPlayerId();
        }

        if (this.hasAnswered || !this.playerId) {
            if (!this.playerId) console.error('OptionsPhase: No player ID found');
            return;
        }

        // Visual feedback immediately
        const buttons = this.grid.querySelectorAll('.option-btn');
        buttons.forEach(btn => {
            btn.classList.remove('selected');
            btn.disabled = true;
        });

        if (buttonElement) {
            buttonElement.classList.add('selected');
        }

        this.selectedAnswer = answerIndex;

        try {
            const result = await this.api.submitAnswer(this.playerId, answerIndex);

            if (result.success) {
                this.hasAnswered = true;

                // Show immediate feedback if available
                if (result.isCorrect !== undefined) {
                    this.showFeedback(result.isCorrect, buttonElement);
                }

                if (this.options.onAnswered) {
                    this.options.onAnswered(result);
                }
            } else {
                // Re-enable buttons on failure
                buttons.forEach(btn => btn.disabled = false);
                showError(result.error || 'Failed to submit answer');
            }
        } catch (error) {
            console.error('Submit answer error:', error);
            buttons.forEach(btn => btn.disabled = false);
        }
    }

    /**
     * Show immediate feedback on answer
     */
    showFeedback(isCorrect, buttonElement) {
        if (!buttonElement) return;

        buttonElement.classList.add(isCorrect ? 'correct' : 'incorrect');
    }

    /**
     * Reveal correct answer
     */
    revealAnswer(correctIndex) {
        this.correctAnswer = correctIndex;

        const buttons = this.grid?.querySelectorAll('.option-btn');
        if (!buttons) return;

        buttons.forEach((btn, index) => {
            btn.disabled = true;

            if (index === correctIndex) {
                btn.classList.add('correct-answer');
            } else if (this.selectedAnswer === index) {
                btn.classList.add('incorrect');
            }
        });
    }

    /**
     * Hide options phase
     */
    hide() {
        if (this.container) {
            this.container.classList.add('hidden');
        }
    }

    /**
     * Reset for new question
     */
    reset() {
        this.selectedAnswer = null;
        this.hasAnswered = false;
        this.correctAnswer = null;

        if (this.grid) {
            this.grid.innerHTML = '';
        }
    }

    /**
     * Check if player has answered
     */
    hasPlayerAnswered() {
        return this.hasAnswered;
    }

    /**
     * Get selected answer index
     */
    getSelectedAnswer() {
        return this.selectedAnswer;
    }

    /**
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Factory function
export function initOptionsPhase(options = {}) {
    const phase = new OptionsPhase(options);
    phase.init();

    // Make globally accessible for button onclick handlers
    window.optionsPhase = phase;

    return phase;
}
