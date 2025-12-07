/**
 * Player End Screen Module
 * Handles quiz completion screen with results and PDF downloads
 */

export class EndScreen {
    constructor(options = {}) {
        this.options = {
            containerSelector: '#endScreen',
            performanceSelector: '#performanceStats',
            breakdownSelector: '#questionBreakdown',
            downloadVocabBtn: '#downloadVocabularyBtn',
            downloadNotesBtn: '#downloadNotesBtn',
            newQuizBtn: '#newQuizBtn',
            ...options
        };

        this.api = this.options.api;
        this.markdownRenderer = this.options.markdownRenderer;
        this.onNewQuiz = this.options.onNewQuiz;
        
        this.container = null;
        this.playerId = null;
        this.summary = null;
        this.questions = null;
    }

    init() {
        this.container = document.querySelector(this.options.containerSelector);
        this.setupEventListeners();
    }

    /**
     * Setup button event listeners
     */
    setupEventListeners() {
        // Download Vocabulary PDF
        const vocabBtn = document.querySelector(this.options.downloadVocabBtn);
        if (vocabBtn) {
            vocabBtn.addEventListener('click', () => this.downloadVocabularyPDF());
        }

        // Download Notes PDF
        const notesBtn = document.querySelector(this.options.downloadNotesBtn);
        if (notesBtn) {
            notesBtn.addEventListener('click', () => this.downloadNotesPDF());
        }

        // New Quiz button
        const newQuizBtn = document.querySelector(this.options.newQuizBtn);
        if (newQuizBtn) {
            newQuizBtn.addEventListener('click', () => {
                if (this.onNewQuiz) this.onNewQuiz();
            });
        }
    }

    /**
     * Set player ID
     */
    setPlayerId(id) {
        this.playerId = id;
    }

    /**
     * Show end screen and fetch results
     */
    async show() {
        if (!this.container) return;

        this.container.classList.remove('hidden');

        // Fetch player summary
        if (this.playerId) {
            await this.fetchSummary();
        }
    }

    /**
     * Fetch player summary from server
     */
    async fetchSummary() {
        try {
            const result = await this.api.getPlayerSummary(this.playerId);
            console.log('Player summary result:', result);
            
            if (result.success && result.summary) {
                this.summary = result.summary;
                this.questions = result.allQuestions || [];
                this.render();
            } else {
                console.error('Failed to get player summary:', result);
                this.renderError('Could not load your results.');
            }
        } catch (error) {
            console.error('Fetch summary error:', error);
            this.renderError('Error loading results.');
        }
    }

    /**
     * Render error message
     */
    renderError(message) {
        const statsEl = document.querySelector(this.options.performanceSelector);
        if (statsEl) {
            statsEl.innerHTML = `<p class="error-message">${message}</p>`;
        }
    }

    /**
     * Render the end screen content
     */
    render() {
        if (!this.summary) return;

        this.renderPerformanceStats();
        this.renderQuestionBreakdown();
    }

    /**
     * Render performance statistics
     */
    renderPerformanceStats() {
        const statsEl = document.querySelector(this.options.performanceSelector);
        if (!statsEl || !this.summary) return;

        const { correctAnswers = 0, incorrectAnswers = 0, totalQuestions = 0, answeredQuestions = 0 } = this.summary;
        const percentage = totalQuestions > 0 ? Math.round((correctAnswers / totalQuestions) * 100) : 0;

        // Determine grade/emoji
        let grade = '';
        let gradeClass = '';
        if (percentage >= 90) { grade = '🏆 Excellent!'; gradeClass = 'grade-excellent'; }
        else if (percentage >= 70) { grade = '⭐ Great job!'; gradeClass = 'grade-great'; }
        else if (percentage >= 50) { grade = '👍 Good effort!'; gradeClass = 'grade-good'; }
        else { grade = '💪 Keep practicing!'; gradeClass = 'grade-practice'; }

        statsEl.innerHTML = `
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-value">${correctAnswers}/${totalQuestions}</div>
                    <div class="stat-label">Correct Answers</div>
                </div>
                <div class="stat-card ${percentage >= 50 ? 'success' : 'warning'}">
                    <div class="stat-value">${percentage}%</div>
                    <div class="stat-label">Score</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-value">${answeredQuestions}</div>
                    <div class="stat-label">Questions Answered</div>
                </div>
                <div class="stat-card ${gradeClass}">
                    <div class="stat-value grade">${grade}</div>
                </div>
            </div>
        `;
    }

    /**
     * Render question-by-question breakdown
     */
    renderQuestionBreakdown() {
        const breakdownEl = document.querySelector(this.options.breakdownSelector);
        if (!breakdownEl || !this.summary || !this.summary.questionBreakdown) return;

        let html = '<div class="breakdown-list">';

        this.summary.questionBreakdown.forEach((item, index) => {
            const isCorrect = item.isCorrect;
            const statusClass = isCorrect ? 'correct' : 'incorrect';
            const statusIcon = isCorrect ? 'fa-check-circle' : 'fa-times-circle';
            
            html += `
                <div class="breakdown-item ${statusClass}">
                    <div class="breakdown-header">
                        <span class="breakdown-number">Q${index + 1}</span>
                        <span class="breakdown-status">
                            <i class="fas ${statusIcon}"></i>
                        </span>
                    </div>
                    <div class="breakdown-question">${this.escapeHtml(item.question)}</div>
                    <div class="breakdown-answers">
                        <div class="your-answer ${statusClass}">
                            <strong>Your answer:</strong> ${this.escapeHtml(item.playerAnswer)}
                        </div>
                        ${!isCorrect ? `
                        <div class="correct-answer">
                            <strong>Correct:</strong> ${this.escapeHtml(item.correctAnswer)}
                        </div>
                        ` : ''}
                    </div>
                    ${item.explanation ? `
                    <div class="breakdown-explanation">
                        <i class="fas fa-lightbulb"></i> ${this.escapeHtml(item.explanation)}
                    </div>
                    ` : ''}
                </div>
            `;
        });

        html += '</div>';
        breakdownEl.innerHTML = html;
    }

    /**
     * Escape HTML for safe rendering
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Download vocabulary as PDF
     */
    async downloadVocabularyPDF() {
        try {
            // Get questions for vocabulary
            const questions = this.questions || [];
            if (questions.length === 0) {
                alert('No vocabulary data available.');
                return;
            }

            // Create vocabulary content
            let vocabContent = `
                <h1>Quiz Vocabulary</h1>
                <p class="pdf-date">Generated: ${new Date().toLocaleDateString()}</p>
            `;

            questions.forEach((q, index) => {
                vocabContent += `
                    <div class="vocab-item">
                        <h3>Question ${index + 1}</h3>
                        <p class="vocab-question">${this.escapeHtml(q.question)}</p>
                        <p class="vocab-answer"><strong>Answer:</strong> ${this.escapeHtml(q.options[q.correct])}</p>
                        ${q.explanation ? `<p class="vocab-explanation"><em>${this.escapeHtml(q.explanation)}</em></p>` : ''}
                    </div>
                `;
            });

            this.generatePDF(vocabContent, 'quiz-vocabulary.pdf', 'Quiz Vocabulary');
        } catch (error) {
            console.error('Error generating vocabulary PDF:', error);
            alert('Error generating PDF. Please try again.');
        }
    }

    /**
     * Download notes as PDF
     */
    async downloadNotesPDF() {
        try {
            // Fetch notes from server
            const result = await this.api.getStudentNotes();
            
            if (!result.success || !result.notes || !result.notes.content) {
                alert('No notes available to download.');
                return;
            }

            const notesContent = result.notes.content;
            
            // Render markdown to HTML
            let htmlContent = '';
            if (this.markdownRenderer) {
                htmlContent = this.markdownRenderer.render(notesContent);
            } else {
                htmlContent = `<pre>${this.escapeHtml(notesContent)}</pre>`;
            }

            const pdfContent = `
                <h1>Class Notes</h1>
                <p class="pdf-date">Generated: ${new Date().toLocaleDateString()}</p>
                <div class="notes-content">${htmlContent}</div>
            `;

            this.generatePDF(pdfContent, 'class-notes.pdf', 'Class Notes');
        } catch (error) {
            console.error('Error generating notes PDF:', error);
            alert('Error generating PDF. Please try again.');
        }
    }

    /**
     * Generate and download PDF using browser print
     */
    generatePDF(content, filename, title) {
        // Create a new window for printing
        const printWindow = window.open('', '_blank', 'width=800,height=600');
        
        if (!printWindow) {
            alert('Please allow pop-ups to download PDF.');
            return;
        }

        const html = `
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>${title}</title>
    <style>
        @page {
            size: A4;
            margin: 20mm;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #333;
            max-width: 100%;
            margin: 0;
            padding: 20px;
            background: white;
        }
        
        h1 {
            font-size: 24pt;
            color: #2563eb;
            margin-bottom: 5px;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 10px;
            page-break-after: avoid;
        }
        
        h2 {
            font-size: 16pt;
            color: #1e40af;
            margin-top: 20px;
            margin-bottom: 10px;
            page-break-after: avoid;
        }
        
        h3 {
            font-size: 13pt;
            color: #1e3a8a;
            margin-top: 15px;
            margin-bottom: 8px;
            page-break-after: avoid;
        }
        
        p {
            margin: 0 0 10px 0;
            orphans: 3;
            widows: 3;
        }
        
        .pdf-date {
            color: #666;
            font-size: 10pt;
            margin-bottom: 20px;
        }
        
        /* Vocabulary styles */
        .vocab-item {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8fafc;
            border-left: 4px solid #2563eb;
            page-break-inside: avoid;
        }
        
        .vocab-item h3 {
            margin-top: 0;
            color: #2563eb;
        }
        
        .vocab-question {
            font-size: 12pt;
            margin-bottom: 8px;
        }
        
        .vocab-answer {
            color: #059669;
        }
        
        .vocab-explanation {
            color: #666;
            font-size: 10pt;
            margin-top: 8px;
        }
        
        /* Notes styles */
        .notes-content {
            line-height: 1.8;
        }
        
        .notes-content ul, .notes-content ol {
            margin: 10px 0;
            padding-left: 25px;
        }
        
        .notes-content li {
            margin-bottom: 5px;
        }
        
        .notes-content a {
            color: #2563eb;
            text-decoration: underline;
        }
        
        .notes-content code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 10pt;
        }
        
        .notes-content pre {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 10pt;
            page-break-inside: avoid;
        }
        
        .notes-content blockquote {
            border-left: 3px solid #2563eb;
            margin: 15px 0;
            padding-left: 15px;
            color: #555;
            font-style: italic;
        }
        
        .notes-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            page-break-inside: avoid;
        }
        
        .notes-content th, .notes-content td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .notes-content th {
            background: #f1f5f9;
        }
        
        /* Print-specific */
        @media print {
            body {
                padding: 0;
            }
            
            a {
                color: #2563eb !important;
                text-decoration: underline !important;
            }
            
            /* Show URL after links */
            a[href^="http"]:after {
                content: " (" attr(href) ")";
                font-size: 9pt;
                color: #666;
            }
        }
    </style>
</head>
<body>
    ${content}
    <script>
        // Auto-print after content loads
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    <\/script>
</body>
</html>
        `;

        printWindow.document.write(html);
        printWindow.document.close();
    }

    /**
     * Hide end screen
     */
    hide() {
        if (this.container) {
            this.container.classList.add('hidden');
        }
    }

    /**
     * Reset
     */
    reset() {
        this.summary = null;
        this.questions = null;
    }

    /**
     * Get summary data
     */
    getSummary() {
        return this.summary;
    }
}

// Factory function
export function initEndScreen(options = {}) {
    const screen = new EndScreen(options);
    screen.init();
    return screen;
}
