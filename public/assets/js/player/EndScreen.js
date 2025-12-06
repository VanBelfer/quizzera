/**
 * Player End Screen Module
 * Handles quiz completion screen with results
 */

import { api as defaultApi } from '../core/api.js';

export class EndScreen {
    constructor(options = {}) {
        this.options = {
            containerId: 'endScreen',
            summaryId: 'performanceSummary',
            breakdownId: 'answerBreakdown',
            scoreId: 'finalScore',
            ...options
        };

        // Use provided API or fallback to default singleton
        this.api = this.options.api || defaultApi;
        
        this.container = null;
        this.playerId = null;
        this.summary = null;
    }

    init() {
        this.container = document.getElementById(this.options.containerId);
    }

    /**
     * Set player ID
     */
    setPlayerId(id) {
        this.playerId = id;
    }

    /**
     * Show end screen and fetch results
     */
    async show() {
        if (!this.container) return;

        this.container.classList.remove('hidden');

        // Fetch player summary
        if (this.playerId) {
            await this.fetchSummary();
        }
    }

    /**
     * Fetch player summary from server
     */
    async fetchSummary() {
        try {
            const result = await this.api.getPlayerSummary(this.playerId);
            
            if (result.success && result.summary) {
                this.summary = result.summary;
                this.render();
            }
        } catch (error) {
            console.error('Fetch summary error:', error);
        }
    }

    /**
     * Render the end screen content
     */
    render() {
        if (!this.summary) return;

        this.renderScore();
        this.renderPerformanceSummary();
        this.renderAnswerBreakdown();
    }

    /**
     * Render score display
     */
    renderScore() {
        const scoreEl = document.getElementById(this.options.scoreId);
        if (!scoreEl || !this.summary) return;

        const { correctAnswers = 0, totalAnswered = 0 } = this.summary;
        const percentage = totalAnswered > 0 ? Math.round((correctAnswers / totalAnswered) * 100) : 0;

        scoreEl.innerHTML = `
            <div class="score-display">
                <div class="trophy-icon ${this.getTrophyClass(percentage)}">
                    <i class="fas fa-trophy"></i>
                </div>
                <div class="score-big">${correctAnswers}/${totalAnswered}</div>
                <div class="score-label">${percentage}% Correct</div>
            </div>
        `;
    }

    /**
     * Get trophy class based on percentage
     */
    getTrophyClass(percentage) {
        if (percentage >= 90) return 'gold';
        if (percentage >= 70) return 'silver';
        if (percentage >= 50) return 'bronze';
        return '';
    }

    /**
     * Render performance summary
     */
    renderPerformanceSummary() {
        const summaryEl = document.getElementById(this.options.summaryId);
        if (!summaryEl || !this.summary) return;

        const { correctAnswers = 0, incorrectAnswers = 0, totalAnswered = 0, buzzerPresses = 0 } = this.summary;

        summaryEl.innerHTML = `
            <div class="performance-summary">
                <h3><i class="fas fa-chart-bar"></i> Performance Summary</h3>
                <div class="performance-stat">
                    <span class="performance-stat-label">Correct Answers</span>
                    <span class="performance-stat-value success">${correctAnswers}</span>
                </div>
                <div class="performance-stat">
                    <span class="performance-stat-label">Incorrect Answers</span>
                    <span class="performance-stat-value danger">${incorrectAnswers}</span>
                </div>
                <div class="performance-stat">
                    <span class="performance-stat-label">Total Answered</span>
                    <span class="performance-stat-value">${totalAnswered}</span>
                </div>
                <div class="performance-stat">
                    <span class="performance-stat-label">Buzzer Presses</span>
                    <span class="performance-stat-value">${buzzerPresses}</span>
                </div>
            </div>
        `;
    }

    /**
     * Render answer breakdown
     */
    renderAnswerBreakdown() {
        const breakdownEl = document.getElementById(this.options.breakdownId);
        if (!breakdownEl || !this.summary || !this.summary.answers) return;

        let html = '<div class="answer-breakdown"><h3>Question Breakdown</h3>';

        this.summary.answers.forEach((answer, index) => {
            const isCorrect = answer.isCorrect;
            html += `
                <div class="breakdown-item ${isCorrect ? 'correct' : 'incorrect'}">
                    <span class="breakdown-question">Q${index + 1}: ${this.truncate(answer.question || 'Question', 50)}</span>
                    <span class="breakdown-result ${isCorrect ? 'correct' : 'incorrect'}">
                        <i class="fas ${isCorrect ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                    </span>
                </div>
            `;
        });

        html += '</div>';
        breakdownEl.innerHTML = html;
    }

    /**
     * Truncate text
     */
    truncate(text, maxLength) {
        if (!text || text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }

    /**
     * Hide end screen
     */
    hide() {
        if (this.container) {
            this.container.classList.add('hidden');
        }
    }

    /**
     * Reset
     */
    reset() {
        this.summary = null;
    }

    /**
     * Get summary data
     */
    getSummary() {
        return this.summary;
    }
}

// Factory function
export function initEndScreen(options = {}) {
    const screen = new EndScreen(options);
    screen.init();
    return screen;
}
