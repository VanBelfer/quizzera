/**
 * Admin Session Manager Module
 * Handles saving, loading, and deleting quiz sessions
 */

import { api as defaultApi } from '../core/api.js';
import { showSuccess, showError } from '../components/MessageSystem.js';
import { Modal } from '../components/Modal.js';

export class SessionManager {
    constructor(options = {}) {
        this.options = {
            modalId: 'sessionsModal',
            listId: 'sessionsList',
            onLoad: null,
            ...options
        };

        // Use provided API or fallback to default singleton
        this.api = this.options.api || defaultApi;
        
        this.modal = null;
        this.listContainer = null;
        this.sessions = [];
    }

    init() {
        const modalEl = document.getElementById(this.options.modalId);
        if (modalEl) {
            this.modal = new Modal(modalEl);
        }
        
        this.listContainer = document.getElementById(this.options.listId);
        
        // Bind save session button
        const saveBtn = document.getElementById('saveSessionBtn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveSession());
        }
        
        // Bind load session button
        const loadBtn = document.getElementById('loadSessionBtn');
        if (loadBtn) {
            loadBtn.addEventListener('click', () => this.showModal());
        }
    }

    /**
     * Save current session
     */
    async saveSession() {
        const name = prompt('Enter a name for this session:', `Quiz ${new Date().toLocaleDateString()}`);
        
        if (!name) return;

        try {
            const result = await this.api.saveSession(name);
            
            if (result.success) {
                showSuccess(`Session "${name}" saved`);
                return result;
            } else {
                showError(result.error || 'Failed to save session');
            }
        } catch (error) {
            showError('Network error');
            console.error('Save session error:', error);
        }
    }

    /**
     * Show sessions modal
     */
    async showModal() {
        try {
            const result = await this.api.getSessions();
            
            if (result.success) {
                this.sessions = result.sessions || [];
                this.renderSessionsList();
                if (this.modal) {
                    this.modal.open();
                }
            } else {
                showError('Failed to load sessions');
            }
        } catch (error) {
            showError('Network error');
            console.error('Get sessions error:', error);
        }
    }

    /**
     * Render sessions list in modal
     */
    renderSessionsList() {
        if (!this.listContainer) return;

        if (this.sessions.length === 0) {
            this.listContainer.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: var(--text-gray-400);">
                    <i class="fas fa-folder-open" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                    <p>No saved sessions found</p>
                </div>
            `;
            return;
        }

        let html = '';
        
        this.sessions.forEach(session => {
            const date = new Date(session.created_at || session.savedAt);
            const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
            
            html += `
                <div class="session-card" data-session-id="${session.id}">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <h4 style="color: var(--text-white); margin-bottom: 0.5rem;">
                                ${this.escapeHtml(session.name)}
                            </h4>
                            <p style="font-size: 0.875rem; color: var(--text-gray-400);">
                                <i class="fas fa-clock"></i> ${dateStr}
                            </p>
                            ${session.questionCount ? `
                                <p style="font-size: 0.875rem; color: var(--text-gray-400);">
                                    <i class="fas fa-question-circle"></i> ${session.questionCount} questions
                                </p>
                            ` : ''}
                            ${session.playerCount ? `
                                <p style="font-size: 0.875rem; color: var(--text-gray-400);">
                                    <i class="fas fa-users"></i> ${session.playerCount} players
                                </p>
                            ` : ''}
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button class="btn btn-primary btn-sm" onclick="window.sessionManager.loadSession('${session.id}')">
                                <i class="fas fa-download"></i> Load
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="window.sessionManager.deleteSession('${session.id}')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });

        this.listContainer.innerHTML = html;
    }

    /**
     * Load a session
     */
    async loadSession(sessionId) {
        if (!confirm('Load this session? Current game state will be replaced.')) {
            return;
        }

        try {
            const result = await this.api.loadSession(sessionId);
            
            if (result.success) {
                showSuccess('Session loaded');
                
                if (this.modal) {
                    this.modal.close();
                }
                
                if (this.options.onLoad) {
                    this.options.onLoad(result);
                }
            } else {
                showError(result.error || 'Failed to load session');
            }
        } catch (error) {
            showError('Network error');
            console.error('Load session error:', error);
        }
    }

    /**
     * Delete a session
     */
    async deleteSession(sessionId) {
        if (!confirm('Delete this session? This cannot be undone.')) {
            return;
        }

        try {
            const result = await this.api.deleteSession(sessionId);
            
            if (result.success) {
                showSuccess('Session deleted');
                // Refresh the list
                this.sessions = this.sessions.filter(s => s.id !== sessionId);
                this.renderSessionsList();
            } else {
                showError(result.error || 'Failed to delete session');
            }
        } catch (error) {
            showError('Network error');
            console.error('Delete session error:', error);
        }
    }

    /**
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Factory function
export function initSessionManager(options = {}) {
    const manager = new SessionManager(options);
    manager.init();
    
    // Make globally accessible for button handlers
    window.sessionManager = manager;
    
    return manager;
}
