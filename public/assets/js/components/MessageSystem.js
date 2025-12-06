/**
 * Message System / Toast Notifications Component
 */

export class MessageSystem {
    constructor(options = {}) {
        this.options = {
            containerSelector: '#messageSystem',
            duration: 5000,
            maxMessages: 5,
            ...options
        };

        this.container = null;
        this.messages = [];

        this.init();
    }

    init() {
        this.container = document.querySelector(this.options.containerSelector);

        if (!this.container) {
            // Create container if it doesn't exist
            this.container = document.createElement('div');
            this.container.id = 'messageSystem';
            this.container.className = 'message-system';
            document.body.appendChild(this.container);
        }
    }

    /**
     * Add a message
     * @param {string} type - 'info' | 'success' | 'warning' | 'error'
     * @param {string} text - Message text
     * @param {number} duration - How long to show (0 = permanent)
     */
    add(type, text, duration = this.options.duration) {
        const id = Date.now() + Math.random();
        
        const messageEl = document.createElement('div');
        messageEl.className = `message ${type}`;
        messageEl.dataset.id = id;
        messageEl.innerHTML = this.getIcon(type) + text;

        // Add close button
        const closeBtn = document.createElement('button');
        closeBtn.innerHTML = '&times;';
        closeBtn.style.cssText = 'float:right;background:none;border:none;color:inherit;font-size:1.2em;cursor:pointer;margin-left:0.5rem;';
        closeBtn.addEventListener('click', () => this.remove(id));
        messageEl.insertBefore(closeBtn, messageEl.firstChild);

        // Enforce max messages
        while (this.messages.length >= this.options.maxMessages) {
            this.remove(this.messages[0].id);
        }

        this.container.appendChild(messageEl);
        this.messages.push({ id, element: messageEl });

        // Auto-remove after duration
        if (duration > 0) {
            setTimeout(() => this.remove(id), duration);
        }

        return id;
    }

    /**
     * Remove a message by ID
     */
    remove(id) {
        const index = this.messages.findIndex(m => m.id === id);
        if (index > -1) {
            const message = this.messages[index];
            message.element.style.animation = 'fadeOut 0.3s ease forwards';
            setTimeout(() => {
                message.element.remove();
            }, 300);
            this.messages.splice(index, 1);
        }
    }

    /**
     * Clear all messages
     */
    clear() {
        this.messages.forEach(m => m.element.remove());
        this.messages = [];
    }

    /**
     * Shorthand methods
     */
    info(text, duration) {
        return this.add('info', text, duration);
    }

    success(text, duration) {
        return this.add('success', text, duration);
    }

    warning(text, duration) {
        return this.add('warning', text, duration);
    }

    error(text, duration) {
        return this.add('error', text, duration);
    }

    /**
     * Get icon for message type
     */
    getIcon(type) {
        const icons = {
            info: '<i class="fas fa-info-circle" style="margin-right:0.5rem;"></i>',
            success: '<i class="fas fa-check-circle" style="margin-right:0.5rem;"></i>',
            warning: '<i class="fas fa-exclamation-triangle" style="margin-right:0.5rem;"></i>',
            error: '<i class="fas fa-times-circle" style="margin-right:0.5rem;"></i>'
        };
        return icons[type] || '';
    }

    destroy() {
        this.clear();
    }
}

// Singleton instance
let instance = null;

export function initMessageSystem(options = {}) {
    if (!instance) {
        instance = new MessageSystem(options);
    }
    return instance;
}

// Quick access functions
export function showMessage(type, text, duration) {
    const system = initMessageSystem();
    return system.add(type, text, duration);
}

export function showInfo(text, duration) {
    return showMessage('info', text, duration);
}

export function showSuccess(text, duration) {
    return showMessage('success', text, duration);
}

export function showWarning(text, duration) {
    return showMessage('warning', text, duration);
}

export function showError(text, duration) {
    return showMessage('error', text, duration);
}
