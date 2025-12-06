/**
 * API Client Module
 * Handles all communication with the server API
 */

export class ApiClient {
    constructor(baseUrl = 'api.php') {
        this.baseUrl = baseUrl;
        this.defaultHeaders = {
            'Content-Type': 'application/json'
        };
        this.retryAttempts = 3;
        this.retryDelay = 1000;
    }

    /**
     * Make a POST request to the API
     * @param {string} action - The API action to call
     * @param {Object} data - Additional data to send
     * @returns {Promise<Object>} - The API response
     */
    async post(action, data = {}) {
        const payload = { action, ...data };

        for (let attempt = 1; attempt <= this.retryAttempts; attempt++) {
            try {
                const response = await fetch(this.baseUrl, {
                    method: 'POST',
                    headers: this.defaultHeaders,
                    body: JSON.stringify(payload)
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();
                return result;

            } catch (error) {
                console.error(`API request failed (attempt ${attempt}/${this.retryAttempts}):`, error);

                if (attempt === this.retryAttempts) {
                    throw error;
                }

                // Wait before retrying
                await this.sleep(this.retryDelay * attempt);
            }
        }
    }

    /**
     * Convenience method for game state (player view)
     */
    async getGameState() {
        return this.post('getGameState');
    }

    /**
     * Admin: Get full game data with answerStats
     */
    async getGameData() {
        return this.post('getGameData');
    }

    /**
     * Join a game
     */
    async joinGame(nickname) {
        return this.post('joinGame', { nickname });
    }

    /**
     * Press the buzzer
     */
    async pressBuzzer(playerId) {
        return this.post('pressBuzzer', { playerId });
    }

    /**
     * Submit an answer
     */
    async submitAnswer(playerId, answerIndex) {
        return this.post('submitAnswer', { playerId, answer: answerIndex });
    }

    /**
     * Get player summary (for end screen)
     */
    async getPlayerSummary(playerId) {
        return this.post('getPlayerSummary', { playerId });
    }

    /**
     * Admin: Start game
     */
    async startGame() {
        return this.post('startGame');
    }

    /**
     * Admin: Next question
     */
    async nextQuestion() {
        return this.post('nextQuestion');
    }

    /**
     * Admin: Show options
     */
    async showOptions() {
        return this.post('showOptions');
    }

    /**
     * Admin: Reveal correct answer
     */
    async revealCorrect() {
        return this.post('revealCorrect');
    }

    /**
     * Admin: Mark player as spoken
     */
    async markSpoken(playerId) {
        return this.post('markSpoken', { playerId });
    }

    /**
     * Admin: Soft reset (current question)
     */
    async softReset() {
        return this.post('softReset');
    }

    /**
     * Admin: Full reset
     */
    async resetGame() {
        return this.post('resetGame');
    }

    /**
     * Admin: Update questions
     * @param {Array|string} questions - Array of questions or JSON string
     */
    async updateQuestions(questions) {
        const questionsJson = typeof questions === 'string'
            ? questions
            : JSON.stringify(questions);
        return this.post('updateQuestions', { questionsJson });
    }

    /**
     * Admin: Get notes
     */
    async getNotes() {
        return this.post('getNotes');
    }

    /**
     * Player: Get student notes (same as getNotes but named differently for clarity)
     */
    async getStudentNotes() {
        return this.post('getNotes');
    }

    /**
     * Admin: Save notes
     */
    async saveNotes(content) {
        return this.post('saveNotes', { content });
    }

    /**
     * Admin: Save session
     */
    async saveSession(name) {
        return this.post('saveSession', { name });
    }

    /**
     * Admin: Get saved sessions
     */
    async getSessions() {
        return this.post('getSessions');
    }

    /**
     * Admin: Load session
     */
    async loadSession(sessionId) {
        return this.post('loadSession', { sessionId });
    }

    /**
     * Admin: Delete session
     */
    async deleteSession(sessionId) {
        return this.post('deleteSession', { sessionId });
    }

    /**
     * Helper: sleep for ms
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Default export singleton instance
export const api = new ApiClient();
