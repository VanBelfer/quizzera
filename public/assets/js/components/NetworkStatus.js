/**
 * Network Status Component
 * Shows connection status indicator
 */

export class NetworkStatus {
    constructor(options = {}) {
        this.options = {
            selector: '#networkStatus',
            onlineClass: 'online',
            offlineClass: 'offline',
            activeClass: 'active',
            hideDelay: 3000,
            ...options
        };

        this.element = null;
        this.hideTimeout = null;
        this.currentStatus = 'online';

        this.init();
    }

    init() {
        this.element = document.querySelector(this.options.selector);

        if (!this.element) {
            console.warn('NetworkStatus: Element not found');
            return;
        }

        // Listen for browser online/offline events
        window.addEventListener('online', () => this.setStatus('online', 'Connected'));
        window.addEventListener('offline', () => this.setStatus('offline', 'Connection lost'));
    }

    /**
     * Set network status
     * @param {string} status - 'online' | 'offline' | 'syncing'
     * @param {string} message - Message to display
     * @param {boolean} autoHide - Whether to auto-hide after delay
     */
    setStatus(status, message, autoHide = true) {
        if (!this.element) return;

        this.currentStatus = status;

        // Clear previous classes
        this.element.classList.remove(
            this.options.onlineClass, 
            this.options.offlineClass
        );

        // Set new status
        if (status === 'online') {
            this.element.classList.add(this.options.onlineClass);
            this.element.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
        } else if (status === 'offline') {
            this.element.classList.add(this.options.offlineClass);
            this.element.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
        } else if (status === 'syncing') {
            this.element.innerHTML = `<i class="fas fa-circle-notch fa-spin"></i> ${message}`;
        }

        // Show the element
        this.element.classList.add(this.options.activeClass);

        // Auto-hide if enabled and online
        if (autoHide && status === 'online') {
            this.scheduleHide();
        } else {
            this.cancelHide();
        }
    }

    show(message = 'Syncing...') {
        this.setStatus('syncing', message, false);
    }

    hide() {
        if (this.element) {
            this.element.classList.remove(this.options.activeClass);
        }
    }

    scheduleHide() {
        this.cancelHide();
        this.hideTimeout = setTimeout(() => this.hide(), this.options.hideDelay);
    }

    cancelHide() {
        if (this.hideTimeout) {
            clearTimeout(this.hideTimeout);
            this.hideTimeout = null;
        }
    }

    getStatus() {
        return this.currentStatus;
    }

    destroy() {
        this.cancelHide();
        this.hide();
    }
}

// Factory function
export function initNetworkStatus(options = {}) {
    return new NetworkStatus(options);
}
