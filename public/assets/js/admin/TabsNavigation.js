/**
 * Admin Tabs Navigation Module
 */

export class TabsNavigation {
    constructor(options = {}) {
        this.options = {
            tabsSelector: '.tab',
            contentPrefix: 'Tab',
            activeClass: 'active',
            onTabChange: null,
            ...options
        };

        this.tabs = [];
        this.currentTab = null;
    }

    init() {
        this.tabs = Array.from(document.querySelectorAll(this.options.tabsSelector));
        
        this.tabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                const tabName = tab.dataset.tab || tab.textContent.toLowerCase().trim();
                this.showTab(tabName, tab);
            });
        });

        // Activate first tab by default
        if (this.tabs.length > 0) {
            const firstTab = this.tabs[0];
            const tabName = firstTab.dataset.tab || firstTab.textContent.toLowerCase().trim();
            this.showTab(tabName, firstTab);
        }
    }

    /**
     * Show a specific tab
     * @param {string} tabName - Name of the tab to show
     * @param {HTMLElement} clickedTab - The tab element that was clicked
     */
    showTab(tabName, clickedTab = null) {
        // Update tab buttons
        this.tabs.forEach(tab => {
            tab.classList.remove(this.options.activeClass);
        });

        if (clickedTab) {
            clickedTab.classList.add(this.options.activeClass);
        } else {
            // Find the tab by name
            const tab = this.tabs.find(t => 
                (t.dataset.tab || t.textContent.toLowerCase().trim()) === tabName
            );
            if (tab) {
                tab.classList.add(this.options.activeClass);
            }
        }

        // Hide all content sections
        const contentSections = document.querySelectorAll('[id$="Tab"]');
        contentSections.forEach(section => {
            section.classList.add('hidden');
        });

        // Show the selected content
        const contentId = tabName + this.options.contentPrefix;
        const content = document.getElementById(contentId);
        if (content) {
            content.classList.remove('hidden');
        }

        this.currentTab = tabName;

        // Callback
        if (this.options.onTabChange) {
            this.options.onTabChange(tabName);
        }
    }

    /**
     * Get current active tab
     */
    getCurrentTab() {
        return this.currentTab;
    }

    /**
     * Programmatically switch to a tab
     */
    switchTo(tabName) {
        this.showTab(tabName);
    }
}

// Factory function
export function initTabsNavigation(options = {}) {
    const tabs = new TabsNavigation(options);
    tabs.init();
    return tabs;
}
