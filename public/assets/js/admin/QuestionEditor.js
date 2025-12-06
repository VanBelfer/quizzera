/**
 * Admin Question Editor Module
 * Handles question JSON editing and validation
 */

import { api as defaultApi } from '../core/api.js';
import { showSuccess, showError } from '../components/MessageSystem.js';

export class QuestionEditor {
    constructor(options = {}) {
        this.options = {
            textareaId: 'questionsJson',
            saveButtonId: 'saveQuestions',
            sampleButtonId: 'loadSample',
            validationId: 'jsonValidation',
            onSave: null,
            ...options
        };

        // Use provided API or fallback to default singleton
        this.api = this.options.api || defaultApi;
        
        this.textarea = null;
        this.saveButton = null;
        this.sampleButton = null;
        this.validationEl = null;
    }

    init() {
        this.textarea = document.getElementById(this.options.textareaId);
        this.saveButton = document.getElementById(this.options.saveButtonId);
        this.sampleButton = document.getElementById(this.options.sampleButtonId);
        this.validationEl = document.getElementById(this.options.validationId);

        if (this.textarea) {
            this.textarea.addEventListener('input', () => this.validateJson());
        }

        if (this.saveButton) {
            this.saveButton.addEventListener('click', () => this.save());
        }

        if (this.sampleButton) {
            this.sampleButton.addEventListener('click', () => this.loadSample());
        }
    }

    /**
     * Validate JSON in textarea
     */
    validateJson() {
        if (!this.textarea) return { valid: false, error: 'No textarea' };

        const text = this.textarea.value.trim();
        
        if (!text) {
            this.showValidation(null);
            return { valid: false, error: 'Empty' };
        }

        try {
            const questions = JSON.parse(text);
            
            if (!Array.isArray(questions)) {
                throw new Error('Must be an array');
            }

            // Validate each question
            const errors = [];
            questions.forEach((q, i) => {
                if (!q.question) errors.push(`Question ${i + 1}: missing "question" field`);
                if (!Array.isArray(q.options)) errors.push(`Question ${i + 1}: "options" must be an array`);
                if (typeof q.correct !== 'number') errors.push(`Question ${i + 1}: "correct" must be a number`);
            });

            if (errors.length > 0) {
                this.showValidation(false, errors.join(', '));
                return { valid: false, error: errors.join(', ') };
            }

            this.showValidation(true, `Valid JSON: ${questions.length} questions`);
            return { valid: true, questions };

        } catch (error) {
            this.showValidation(false, `JSON Error: ${error.message}`);
            return { valid: false, error: error.message };
        }
    }

    /**
     * Show validation message
     */
    showValidation(valid, message = '') {
        if (!this.validationEl) return;

        if (valid === null) {
            this.validationEl.className = 'validation-message';
            this.validationEl.textContent = '';
            this.validationEl.style.display = 'none';
        } else {
            this.validationEl.className = `validation-message ${valid ? 'valid' : 'invalid'}`;
            this.validationEl.textContent = message;
            this.validationEl.style.display = 'block';
        }
    }

    /**
     * Save questions to server
     */
    async save() {
        const validation = this.validateJson();
        
        if (!validation.valid) {
            showError('Please fix JSON errors before saving');
            return;
        }

        try {
            const result = await this.api.updateQuestions(validation.questions);
            
            if (result.success) {
                showSuccess(`Saved ${validation.questions.length} questions`);
                if (this.options.onSave) {
                    this.options.onSave(validation.questions);
                }
            } else {
                showError(result.error || 'Failed to save questions');
            }
        } catch (error) {
            showError('Network error');
            console.error('Save questions error:', error);
        }
    }

    /**
     * Load sample questions
     */
    loadSample() {
        const sample = [
            {
                "question": "What is phishing?",
                "options": [
                    "Deceptive emails designed to steal personal information",
                    "A type of fishing sport",
                    "A computer virus",
                    "A social media platform"
                ],
                "correct": 0,
                "image": "",
                "explanation": "Phishing is a cybersecurity attack where criminals send fake emails or messages that appear to be from trusted sources, trying to trick people into revealing sensitive information like passwords or credit card numbers."
            },
            {
                "question": "What does 'malware' stand for?",
                "options": [
                    "Malfunctioning hardware",
                    "Malicious software",
                    "Male-oriented software",
                    "Managed software"
                ],
                "correct": 1,
                "image": "",
                "explanation": "Malware is short for 'malicious software' - any program designed to harm, exploit, or otherwise compromise a computer system without consent."
            },
            {
                "question": "What is a strong password?",
                "options": [
                    "Your birthday",
                    "The word 'password'",
                    "A mix of letters, numbers, and symbols",
                    "Your pet's name"
                ],
                "correct": 2,
                "image": "",
                "explanation": "Strong passwords combine uppercase letters, lowercase letters, numbers, and special symbols. They should be at least 12 characters long and avoid personal information."
            }
        ];

        if (this.textarea) {
            this.textarea.value = JSON.stringify(sample, null, 2);
            this.validateJson();
        }
    }

    /**
     * Set questions in textarea
     */
    setQuestions(questions) {
        if (this.textarea && questions) {
            this.textarea.value = JSON.stringify(questions, null, 2);
            this.validateJson();
        }
    }

    /**
     * Get current questions from textarea
     */
    getQuestions() {
        const validation = this.validateJson();
        return validation.valid ? validation.questions : null;
    }
}

// Factory function
export function initQuestionEditor(options = {}) {
    const editor = new QuestionEditor(options);
    editor.init();
    return editor;
}
