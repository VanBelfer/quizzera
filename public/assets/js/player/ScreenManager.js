/**
 * Player Screen Manager Module
 * Handles switching between different player screens
 */

export class ScreenManager {
    constructor(options = {}) {
        this.options = {
            screens: {
                login: 'loginScreen',
                waiting: 'waitingScreen',
                quiz: 'quizScreen',
                end: 'endScreen'
            },
            onScreenChange: null,
            ...options
        };

        this.currentScreen = null;
        this.screenElements = {};
    }

    /**
     * Initialize screen manager
     */
    init() {
        // Cache screen elements
        Object.entries(this.options.screens).forEach(([name, selector]) => {
            // Handle both '#id' selectors and plain 'id' strings
            if (selector.startsWith('#')) {
                this.screenElements[name] = document.querySelector(selector);
            } else {
                this.screenElements[name] = document.getElementById(selector);
            }
        });
    }

    /**
     * Show a specific screen
     * @param {string} screenName - Name of screen to show
     */
    show(screenName) {
        // Hide all screens
        Object.entries(this.screenElements).forEach(([name, element]) => {
            if (element) {
                element.classList.add('hidden');
            }
        });

        // Show target screen
        const targetScreen = this.screenElements[screenName];
        if (targetScreen) {
            targetScreen.classList.remove('hidden');
            this.currentScreen = screenName;

            // Callback
            if (this.options.onScreenChange) {
                this.options.onScreenChange(screenName);
            }
        } else {
            console.warn(`Screen "${screenName}" not found`);
        }
    }

    /**
     * Get current screen name
     */
    getCurrentScreen() {
        return this.currentScreen;
    }

    /**
     * Check if a specific screen is active
     */
    isActive(screenName) {
        return this.currentScreen === screenName;
    }

    /**
     * Show login screen
     */
    showLogin() {
        this.show('login');
    }

    /**
     * Show waiting screen
     */
    showWaiting() {
        this.show('waiting');
    }

    /**
     * Show quiz screen
     */
    showQuiz() {
        this.show('quiz');
    }

    /**
     * Show end screen
     */
    showEnd() {
        this.show('end');
    }
}

// Factory function
export function initScreenManager(options = {}) {
    const manager = new ScreenManager(options);
    manager.init();
    return manager;
}
