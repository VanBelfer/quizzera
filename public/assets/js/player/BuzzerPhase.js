/**
 * Player Buzzer Phase Module
 * Handles buzzer button and related UI
 */

import { api as defaultApi } from '../core/api.js';
import { showError } from '../components/MessageSystem.js';

export class BuzzerPhase {
    constructor(options = {}) {
        this.options = {
            buttonId: 'buzzerBtn',
            containerId: 'buzzerPhase',
            onBuzzed: null,
            debounceMs: 500,
            ...options
        };

        // Use provided API or fallback to default singleton
        this.api = this.options.api || defaultApi;

        this.button = null;
        this.container = null;
        this.playerId = null;
        this.lastBuzzTime = 0;
        this.hasBuzzed = false;
    }

    init() {
        this.button = document.getElementById(this.options.buttonId);
        this.container = document.getElementById(this.options.containerId);

        if (this.button) {
            this.button.addEventListener('click', () => this.pressBuzzer());

            // Touch events for mobile
            this.button.addEventListener('touchstart', (e) => {
                e.preventDefault();
                this.pressBuzzer();
            });
        }
    }

    /**
     * Set player ID
     */
    setPlayerId(id) {
        this.playerId = id;
    }

    /**
     * Press the buzzer
     */
    async pressBuzzer() {
        // Resolve player ID from option if not set directly
        if (!this.playerId && this.options.getPlayerId) {
            this.playerId = this.options.getPlayerId();
        }

        if (!this.playerId) {
            showError('Not joined to game');
            return;
        }

        // Debounce
        const now = Date.now();
        if (now - this.lastBuzzTime < this.options.debounceMs) {
            return;
        }
        this.lastBuzzTime = now;

        // Visual feedback
        if (this.button) {
            this.button.classList.add('pressed');
            this.button.disabled = true;
        }

        try {
            const result = await this.api.pressBuzzer(this.playerId);

            if (result.success) {
                this.hasBuzzed = true;

                if (this.options.onBuzzed) {
                    this.options.onBuzzed(result);
                }
            } else {
                // Re-enable button on failure
                if (this.button) {
                    this.button.disabled = false;
                    this.button.classList.remove('pressed');
                }

                if (result.error && result.error !== 'Already buzzed') {
                    showError(result.error);
                }
            }
        } catch (error) {
            console.error('Buzzer error:', error);

            if (this.button) {
                this.button.disabled = false;
                this.button.classList.remove('pressed');
            }
        }
    }

    /**
     * Show buzzer phase UI
     */
    show(gameState) {
        if (!this.container) return;

        this.container.classList.remove('hidden');

        // Check if player already buzzed
        const buzzers = gameState.buzzers || [];
        this.hasBuzzed = buzzers.some(b => b.playerId === this.playerId);

        if (this.button) {
            this.button.disabled = this.hasBuzzed;

            if (this.hasBuzzed) {
                this.button.textContent = 'Buzzed!';
                this.button.classList.add('pressed');
            } else {
                this.button.textContent = 'BUZZ!';
                this.button.classList.remove('pressed');
            }
        }
    }

    /**
     * Hide buzzer phase UI
     */
    hide() {
        if (this.container) {
            this.container.classList.add('hidden');
        }
    }

    /**
     * Reset buzzer state (for new question)
     */
    reset() {
        this.hasBuzzed = false;
        this.lastBuzzTime = 0;

        if (this.button) {
            this.button.disabled = false;
            this.button.textContent = 'BUZZ!';
            this.button.classList.remove('pressed');
        }
    }

    /**
     * Check if player has buzzed
     */
    hasPlayerBuzzed() {
        return this.hasBuzzed;
    }
    /**
     * Disable buzzer button
     */
    disable() {
        if (this.button) {
            this.button.disabled = true;
        }
    }

    /**
     * Enable buzzer button
     */
    enable() {
        if (this.button && !this.hasBuzzed) {
            this.button.disabled = false;
        }
    }
}

// Factory function
export function initBuzzerPhase(options = {}) {
    const buzzer = new BuzzerPhase(options);
    buzzer.init();
    return buzzer;
}
