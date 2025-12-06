/**
 * Action Feedback Component
 * Shows centered popup for action confirmations
 */

export class ActionFeedback {
    constructor(options = {}) {
        this.options = {
            selector: '#actionFeedback',
            activeClass: 'active',
            successClass: 'success',
            errorClass: 'error',
            defaultDuration: 2000,
            ...options
        };

        this.element = null;
        this.hideTimeout = null;

        this.init();
    }

    init() {
        this.element = document.querySelector(this.options.selector);

        if (!this.element) {
            // Create element if it doesn't exist
            this.element = document.createElement('div');
            this.element.id = 'actionFeedback';
            this.element.className = 'action-feedback';
            document.body.appendChild(this.element);
        }
    }

    /**
     * Show feedback
     * @param {string} type - 'success' | 'error'
     * @param {string} message - Message to display
     * @param {boolean|number} autoHide - true for default duration, number for custom ms
     */
    show(type, message, autoHide = true) {
        if (!this.element) return;

        // Clear previous timeout
        this.cancelHide();

        // Clear previous classes
        this.element.classList.remove(
            this.options.successClass,
            this.options.errorClass
        );

        // Set content and type
        let icon = 'fa-info-circle';
        if (type === 'success') icon = 'fa-check-circle';
        else if (type === 'error') icon = 'fa-times-circle';

        this.element.innerHTML = `<i class="fas ${icon}"></i> ${message}`;
        this.element.classList.add(type);

        // Show
        this.element.classList.add(this.options.activeClass);

        // Auto hide
        if (autoHide) {
            const duration = typeof autoHide === 'number' ? autoHide : this.options.defaultDuration;
            this.scheduleHide(duration);
        }
    }

    success(message, autoHide = true) {
        this.show('success', message, autoHide);
    }

    error(message, autoHide = true) {
        this.show('error', message, autoHide);
    }

    hide() {
        if (this.element) {
            this.element.classList.remove(this.options.activeClass);
        }
    }

    scheduleHide(duration) {
        this.hideTimeout = setTimeout(() => this.hide(), duration);
    }

    cancelHide() {
        if (this.hideTimeout) {
            clearTimeout(this.hideTimeout);
            this.hideTimeout = null;
        }
    }

    destroy() {
        this.cancelHide();
        this.hide();
    }
}

// Singleton instance
let instance = null;

export function initActionFeedback(options = {}) {
    if (!instance) {
        instance = new ActionFeedback(options);
    }
    return instance;
}

// Quick access functions
export function showFeedback(type, message, autoHide) {
    const feedback = initActionFeedback();
    feedback.show(type, message, autoHide);
}

export function showSuccessFeedback(message, autoHide) {
    showFeedback('success', message, autoHide);
}

export function showErrorFeedback(message, autoHide) {
    showFeedback('error', message, autoHide);
}
