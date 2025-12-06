/**
 * Modal Component
 */

export class Modal {
    constructor(element, options = {}) {
        this.element = typeof element === 'string' 
            ? document.querySelector(element) 
            : element;
        
        this.options = {
            closeOnBackdrop: true,
            closeOnEscape: true,
            onOpen: null,
            onClose: null,
            ...options
        };

        this.isOpen = false;
        this.previousFocus = null;

        if (this.element) {
            this.init();
        }
    }

    init() {
        // Find close buttons
        const closeButtons = this.element.querySelectorAll('[data-modal-close], .modal-close, .close');
        closeButtons.forEach(btn => {
            btn.addEventListener('click', () => this.close());
        });

        // Backdrop click
        if (this.options.closeOnBackdrop) {
            this.element.addEventListener('click', (e) => {
                if (e.target === this.element) {
                    this.close();
                }
            });
        }

        // Escape key
        if (this.options.closeOnEscape) {
            this.escapeHandler = (e) => {
                if (e.key === 'Escape' && this.isOpen) {
                    this.close();
                }
            };
            document.addEventListener('keydown', this.escapeHandler);
        }
    }

    open() {
        if (this.isOpen) return;

        this.previousFocus = document.activeElement;
        this.element.classList.remove('hidden');
        this.isOpen = true;

        // Focus first focusable element
        const focusable = this.element.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable) {
            setTimeout(() => focusable.focus(), 100);
        }

        // Prevent body scroll
        document.body.style.overflow = 'hidden';

        if (this.options.onOpen) {
            this.options.onOpen(this);
        }

        // Dispatch event
        this.element.dispatchEvent(new CustomEvent('modal:open'));
    }

    close() {
        if (!this.isOpen) return;

        this.element.classList.add('hidden');
        this.isOpen = false;

        // Restore body scroll
        document.body.style.overflow = '';

        // Restore focus
        if (this.previousFocus) {
            this.previousFocus.focus();
        }

        if (this.options.onClose) {
            this.options.onClose(this);
        }

        // Dispatch event
        this.element.dispatchEvent(new CustomEvent('modal:close'));
    }

    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    /**
     * Set modal content dynamically
     */
    setContent(html) {
        const body = this.element.querySelector('.modal-body');
        if (body) {
            body.innerHTML = html;
        }
    }

    /**
     * Set modal title
     */
    setTitle(title) {
        const header = this.element.querySelector('.modal-header h2, .modal-header h3');
        if (header) {
            header.textContent = title;
        }
    }

    destroy() {
        if (this.escapeHandler) {
            document.removeEventListener('keydown', this.escapeHandler);
        }
        this.close();
    }
}

/**
 * Create and show a simple confirmation modal
 */
export function confirm(message, options = {}) {
    return new Promise((resolve) => {
        const { title = 'Confirm', confirmText = 'OK', cancelText = 'Cancel' } = options;

        // Create modal HTML
        const modalHtml = `
            <div class="modal" id="confirmModal">
                <div class="modal-content" style="max-width:400px;">
                    <div class="modal-header">
                        <h3>${title}</h3>
                    </div>
                    <div class="modal-body">
                        <p>${message}</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" data-action="cancel">${cancelText}</button>
                        <button class="btn btn-primary" data-action="confirm">${confirmText}</button>
                    </div>
                </div>
            </div>
        `;

        // Add to DOM
        const container = document.createElement('div');
        container.innerHTML = modalHtml;
        document.body.appendChild(container);

        const modalEl = container.querySelector('.modal');
        const modal = new Modal(modalEl, { closeOnBackdrop: false, closeOnEscape: false });

        // Handle buttons
        modalEl.querySelector('[data-action="confirm"]').addEventListener('click', () => {
            modal.close();
            container.remove();
            resolve(true);
        });

        modalEl.querySelector('[data-action="cancel"]').addEventListener('click', () => {
            modal.close();
            container.remove();
            resolve(false);
        });

        modal.open();
    });
}

// Factory function
export function initModal(selector, options = {}) {
    return new Modal(selector, options);
}
