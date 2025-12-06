/**
 * Player Keyboard Shortcuts Module
 * Handles keyboard navigation and shortcuts
 */

export class KeyboardShortcuts {
    constructor(options = {}) {
        this.options = {
            onBuzzer: null,
            onAnswer: null,
            enabled: true,
            ...options
        };

        this.handlers = new Map();
    }

    /**
     * Initialize keyboard listeners
     */
    init() {
        document.addEventListener('keydown', (e) => this.handleKeyDown(e));
    }

    /**
     * Handle keydown events
     */
    handleKeyDown(e) {
        if (!this.options.enabled) return;

        // Ignore if typing in an input
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
            return;
        }

        // Space or Enter = Buzzer
        if (e.code === 'Space' || e.code === 'Enter') {
            e.preventDefault();
            if (this.options.onBuzzer) {
                this.options.onBuzzer();
            }
            return;
        }

        // Number keys 1-4 = Answer options
        const answerKeys = {
            'Digit1': 0, 'Numpad1': 0,
            'Digit2': 1, 'Numpad2': 1,
            'Digit3': 2, 'Numpad3': 2,
            'Digit4': 3, 'Numpad4': 3,
            'KeyA': 0, 'KeyB': 1, 'KeyC': 2, 'KeyD': 3
        };

        if (e.code in answerKeys) {
            e.preventDefault();
            if (this.options.onAnswer) {
                this.options.onAnswer(answerKeys[e.code]);
            }
            return;
        }

        // Custom handlers
        const handler = this.handlers.get(e.code);
        if (handler) {
            e.preventDefault();
            handler(e);
        }
    }

    /**
     * Register a custom keyboard shortcut
     * @param {string} code - KeyboardEvent.code (e.g., 'KeyH' for H key)
     * @param {Function} handler - Function to call
     */
    register(code, handler) {
        this.handlers.set(code, handler);
    }

    /**
     * Unregister a shortcut
     */
    unregister(code) {
        this.handlers.delete(code);
    }

    /**
     * Enable/disable shortcuts
     */
    setEnabled(enabled) {
        this.options.enabled = enabled;
    }

    /**
     * Set buzzer handler
     */
    setBuzzerHandler(handler) {
        this.options.onBuzzer = handler;
    }

    /**
     * Set answer handler
     */
    setAnswerHandler(handler) {
        this.options.onAnswer = handler;
    }

    /**
     * Get help text for shortcuts
     */
    getHelpText() {
        return `
            Keyboard Shortcuts:
            • Space/Enter - Press buzzer
            • 1/A - Select answer A
            • 2/B - Select answer B
            • 3/C - Select answer C
            • 4/D - Select answer D
        `;
    }
}

// Factory function
export function initKeyboardShortcuts(options = {}) {
    const shortcuts = new KeyboardShortcuts(options);
    shortcuts.init();
    return shortcuts;
}
