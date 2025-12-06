/**
 * Help Panel Component
 * Sliding help panel from bottom-left
 */

export class HelpPanel {
    constructor(options = {}) {
        this.options = {
            toggleSelector: '#helpToggle',
            panelSelector: '#helpPanel',
            closeSelector: '#helpClose',
            activeClass: 'active',
            ...options
        };

        this.toggle = null;
        this.panel = null;
        this.closeBtn = null;
        this.isOpen = false;

        this.init();
    }

    init() {
        this.toggle = document.querySelector(this.options.toggleSelector);
        this.panel = document.querySelector(this.options.panelSelector);
        this.closeBtn = document.querySelector(this.options.closeSelector);

        if (!this.toggle || !this.panel) {
            console.warn('HelpPanel: Required elements not found');
            return;
        }

        this.bindEvents();
    }

    bindEvents() {
        // Toggle button
        this.toggle.addEventListener('click', () => this.open());

        // Close button
        if (this.closeBtn) {
            this.closeBtn.addEventListener('click', () => this.close());
        }

        // Click outside to close
        document.addEventListener('click', (e) => {
            if (this.isOpen && 
                !this.panel.contains(e.target) && 
                !this.toggle.contains(e.target)) {
                this.close();
            }
        });

        // Escape key to close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
        });
    }

    open() {
        this.panel.classList.add(this.options.activeClass);
        this.isOpen = true;
        this.toggle.setAttribute('aria-expanded', 'true');
    }

    close() {
        this.panel.classList.remove(this.options.activeClass);
        this.isOpen = false;
        this.toggle.setAttribute('aria-expanded', 'false');
    }

    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    destroy() {
        // Clean up event listeners if needed
        this.panel?.classList.remove(this.options.activeClass);
    }
}

// Auto-init if elements exist
export function initHelpPanel(options = {}) {
    return new HelpPanel(options);
}
