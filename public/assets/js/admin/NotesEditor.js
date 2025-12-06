/**
 * Admin Notes Editor Module
 * Handles markdown notes editing and preview
 */

import { api as defaultApi } from '../core/api.js';
import { showSuccess, showError } from '../components/MessageSystem.js';
import { renderMarkdown } from '../components/MarkdownRenderer.js';

export class NotesEditor {
    constructor(options = {}) {
        this.options = {
            panelId: 'notesPanel',
            contentId: 'notesContent',
            editorId: 'notesEditor',
            textareaId: 'notesTextarea',
            toggleBtnId: 'notesBtn',
            editBtnId: 'toggleNotesEdit',
            saveBtnId: 'saveNotes',
            closeBtnId: 'closeNotes',
            ...options
        };

        // Use provided API or fallback to default singleton
        this.api = this.options.api || defaultApi;

        this.panel = null;
        this.content = null;
        this.editor = null;
        this.textarea = null;
        this.isEditing = false;
        this.currentNotes = '';
    }

    init() {
        console.log('NotesEditor.init() called, toggleBtnId:', this.options.toggleBtnId);

        this.panel = document.getElementById(this.options.panelId);
        this.content = document.getElementById(this.options.contentId);
        this.editor = document.getElementById(this.options.editorId);
        this.textarea = document.getElementById(this.options.textareaId);

        // Bind events
        const toggleBtn = document.getElementById(this.options.toggleBtnId);
        console.log('toggleBtn element:', toggleBtn);

        const editBtn = document.getElementById(this.options.editBtnId);
        const saveBtn = document.getElementById(this.options.saveBtnId);
        const closeBtn = document.getElementById(this.options.closeBtnId);

        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                console.log('Notes button clicked!');
                this.togglePanel();
            });
        } else {
            console.error('Notes toggle button not found! ID:', this.options.toggleBtnId);
        }

        if (editBtn) {
            editBtn.addEventListener('click', () => this.toggleEditMode());
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.save());
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', () => this.hidePanel());
        }

        // Formatting toolbar
        this.initToolbar();

        // Load notes initially
        this.fetchNotes();
    }

    /**
     * Initialize formatting toolbar buttons
     */
    initToolbar() {
        const buttons = {
            insertLinkBtn: () => this.insertMarkdown('[', '](url)', 'link text'),
            boldBtn: () => this.insertMarkdown('**', '**', 'bold text'),
            italicBtn: () => this.insertMarkdown('*', '*', 'italic text'),
            bulletBtn: () => this.insertAtLineStart('- '),
            headingBtn: () => this.insertAtLineStart('## ')
        };

        Object.entries(buttons).forEach(([id, handler]) => {
            const btn = document.getElementById(id);
            if (btn) {
                btn.addEventListener('click', handler);
            }
        });
    }

    /**
     * Toggle panel visibility
     */
    togglePanel() {
        if (!this.panel) return;

        if (this.panel.classList.contains('hidden')) {
            this.showPanel();
        } else {
            this.hidePanel();
        }
    }

    showPanel() {
        if (this.panel) {
            this.panel.classList.remove('hidden');
            // Ensure display is cleared to let CSS handle it (unless overriding)
            this.panel.style.display = '';
        }
    }

    hidePanel() {
        if (this.panel) {
            this.panel.classList.add('hidden');
            this.panel.style.display = '';
        }
    }

    /**
     * Toggle between view and edit mode
     */
    toggleEditMode() {
        this.isEditing = !this.isEditing;

        if (this.isEditing) {
            // Switch to edit mode
            if (this.content) this.content.classList.add('hidden');
            if (this.editor) this.editor.classList.remove('hidden');
            if (this.textarea) this.textarea.value = this.currentNotes;
        } else {
            // Switch to view mode
            if (this.editor) this.editor.classList.add('hidden');
            if (this.content) this.content.classList.remove('hidden');
            this.renderNotes();
        }

        // Update button icon
        const editBtn = document.getElementById(this.options.editBtnId);
        if (editBtn) {
            const icon = editBtn.querySelector('i');
            if (icon) {
                icon.className = this.isEditing ? 'fas fa-eye' : 'fas fa-edit';
            }
        }
    }

    /**
     * Fetch notes from server
     */
    async fetchNotes() {
        try {
            const result = await this.api.getNotes();

            if (result.success) {
                this.currentNotes = result.notes || '';
                this.renderNotes();
            }
        } catch (error) {
            console.error('Fetch notes error:', error);
        }
    }

    /**
     * Save notes to server
     */
    async save() {
        if (!this.textarea) return;

        this.currentNotes = this.textarea.value;

        try {
            const result = await this.api.saveNotes(this.currentNotes);

            if (result.success) {
                showSuccess('Notes saved');
                this.toggleEditMode(); // Switch back to view mode
            } else {
                showError(result.error || 'Failed to save notes');
            }
        } catch (error) {
            showError('Network error');
            console.error('Save notes error:', error);
        }
    }

    /**
     * Render notes as markdown
     */
    renderNotes() {
        if (this.content) {
            if (this.currentNotes) {
                this.content.innerHTML = renderMarkdown(this.currentNotes);
            } else {
                this.content.innerHTML = '<p style="color: var(--text-gray-400);">No notes yet. Click edit to add notes.</p>';
            }
        }
    }

    /**
     * Insert markdown formatting around selection
     */
    insertMarkdown(before, after, placeholder) {
        if (!this.textarea) return;

        const start = this.textarea.selectionStart;
        const end = this.textarea.selectionEnd;
        const text = this.textarea.value;
        const selected = text.substring(start, end) || placeholder;

        const newText = text.substring(0, start) + before + selected + after + text.substring(end);
        this.textarea.value = newText;

        // Set cursor position
        const newStart = start + before.length;
        const newEnd = newStart + selected.length;
        this.textarea.setSelectionRange(newStart, newEnd);
        this.textarea.focus();
    }

    /**
     * Insert text at the start of current line
     */
    insertAtLineStart(prefix) {
        if (!this.textarea) return;

        const start = this.textarea.selectionStart;
        const text = this.textarea.value;

        // Find the start of the current line
        let lineStart = start;
        while (lineStart > 0 && text[lineStart - 1] !== '\n') {
            lineStart--;
        }

        const newText = text.substring(0, lineStart) + prefix + text.substring(lineStart);
        this.textarea.value = newText;

        // Move cursor
        this.textarea.setSelectionRange(start + prefix.length, start + prefix.length);
        this.textarea.focus();
    }

    /**
     * Set notes content
     */
    setNotes(notes) {
        this.currentNotes = notes || '';
        this.renderNotes();
    }

    /**
     * Get current notes
     */
    getNotes() {
        return this.currentNotes;
    }
}

// Factory function
export function initNotesEditor(options = {}) {
    const editor = new NotesEditor(options);
    editor.init();
    return editor;
}
