<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Admin Dashboard</title>
    
    <!-- CSS Modules -->
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link rel="stylesheet" href="assets/css/forms.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    
    <!-- Shared Components CSS -->
    <link rel="stylesheet" href="assets/css/components/help-panel.css">
    <link rel="stylesheet" href="assets/css/components/notifications.css">
    <link rel="stylesheet" href="assets/css/components/modal.css">
    <link rel="stylesheet" href="assets/css/components/progress.css">
    <link rel="stylesheet" href="assets/css/components/badges.css">
    <link rel="stylesheet" href="assets/css/components/cards.css">
    
    <!-- Admin-specific CSS -->
    <link rel="stylesheet" href="assets/css/admin/dashboard.css">
    <link rel="stylesheet" href="assets/css/admin/tabs.css">
    <link rel="stylesheet" href="assets/css/admin/game-controls.css">
    <link rel="stylesheet" href="assets/css/admin/question-editor.css">
    <link rel="stylesheet" href="assets/css/admin/notes-editor.css">
    <link rel="stylesheet" href="assets/css/admin/results.css">
    
    <!-- External Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Quiz</a>
    
    <div class="container">
        <div class="dashboard">
            <!-- Header -->
            <div class="header">
                <h1><i class="fas fa-chalkboard-teacher"></i> Quiz Admin Dashboard</h1>
                <div class="game-status">
                    <div class="status-badge" id="gameStatusBadge">
                        <span class="status-waiting">Waiting</span>
                    </div>
                    <div class="status-badge player-badge" id="playerBadge">
                        <span id="playerCount">0</span> Players
                        <i class="fas fa-chevron-down" id="playerListToggle"></i>
                        <div id="playerList" class="player-list hidden"></div>
                    </div>
                </div>
                <div class="header-controls">
                    <button id="notesBtn" class="btn btn-purple">
                        <i class="fas fa-sticky-note"></i> Notes
                    </button>
                    <button id="saveSessionBtn" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Session
                    </button>
                    <button id="loadSessionBtn" class="btn btn-warning">
                        <i class="fas fa-history"></i> Load Session
                    </button>
                    <button id="templateBtn" class="btn btn-purple">
                        <i class="fas fa-th-large"></i> Templates
                    </button>
                </div>
            </div>
            
            <!-- Tabs Navigation -->
            <div class="tabs">
                <div class="tab active" data-tab="control">Game Control</div>
                <div class="tab" data-tab="questions">Manage Questions</div>
                <div class="tab" data-tab="results">Results</div>
            </div>

            <!-- Game Control Tab -->
            <div id="controlTab" class="content">
                <div class="game-controls">
                    <h3 class="section-header">
                        <span>Game Controls</span>
                        <span class="phase-indicator phase-waiting" id="phaseIndicator">Waiting</span>
                    </h3>
                    <div class="progress-container">
                        <div class="progress-bar" id="quizProgress"></div>
                    </div>
                    <div id="gameControlButtons" class="control-buttons">
                        <!-- Buttons populated by JavaScript -->
                    </div>
                </div>
                <div id="currentQuestionContainer" class="question-container">
                    <!-- Current question populated by JavaScript -->
                </div>
            </div>

            <!-- Questions Management Tab -->
            <div id="questionsTab" class="content hidden">
                <div class="question-editor">
                    <h3 class="section-header">
                        <i class="fas fa-question-circle"></i> Manage Questions
                    </h3>
                    <div class="form-group">
                        <label class="form-label">
                            Questions JSON:
                            <button id="loadSampleBtn" class="btn btn-purple btn-sm">
                                <i class="fas fa-rocket"></i> Load Sample
                            </button>
                        </label>
                        <textarea id="questionsJson" class="form-textarea" placeholder="Paste your questions JSON here..."></textarea>
                    </div>
                    <button id="updateQuestionsBtn" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Questions
                    </button>
                    <div class="sample-json">
                        <strong><i class="fas fa-info-circle"></i> Sample JSON Format:</strong>
                        <pre>[
  {
    "question": "What is phishing?",
    "options": ["Deceptive emails to steal info", "Fishing for real tuna"],
    "correct": 0,
    "image": "",
    "explanation": "Phishing is a cybersecurity attack..."
  }
]
Note: 
- "correct" = index of right answer (0 = first option)
- "image" = optional image URL
- "explanation" = teaching notes for admin</pre>
                    </div>
                </div>
            </div>

            <!-- Results Tab -->
            <div id="resultsTab" class="content hidden">
                <div class="results-header">
                    <h3>Results History</h3>
                    <div class="results-navigation">
                        <button id="prevQuestionBtn" class="btn btn-sm btn-secondary">
                            <i class="fas fa-arrow-left"></i> Previous
                        </button>
                        <span id="resultsQuestionIndicator" class="question-indicator">Question 1 of 10</span>
                        <button id="nextQuestionBtn" class="btn btn-sm btn-secondary">
                            Next <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                <div id="resultsContainer" class="results-container">
                    <!-- Results populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Help System -->
    <div class="help-toggle" id="helpToggle">
        <i class="fas fa-question"></i>
    </div>
    <div class="help-panel" id="helpPanel">
        <div class="help-header">
            <div class="help-title">Quiz Admin Help</div>
            <button class="help-close" id="helpClose">&times;</button>
        </div>
        <div class="help-content">
            <div class="help-section">
                <h3><i class="fas fa-gamepad"></i> Game Flow</h3>
                <p>1. Start the quiz with the green "Start Quiz" button</p>
                <p>2. Students see the question and can buzz in</p>
                <p>3. Click "Show Answer Options" when ready for students to answer</p>
                <p>4. Click "Reveal Correct Answer" to show the right answer</p>
                <p>5. Click "Next Question" to move forward</p>
                <div class="help-tip">
                    <strong>Pro Tip:</strong> Use "Mark as Spoken" after a student answers to prevent them from buzzing again on the same question.
                </div>
            </div>
            <div class="help-section">
                <h3><i class="fas fa-users"></i> Player Management</h3>
                <p>- Students join by entering their name on the quiz page</p>
                <p>- "Soft Reset" keeps players but resets the game</p>
                <p>- "Full Reset" clears everything including players</p>
            </div>
            <div class="help-section">
                <h3><i class="fas fa-question"></i> Question Management</h3>
                <p>- Paste valid JSON in the editor to update questions</p>
                <p>- Use the sample format as a guide</p>
                <p>- Click "Load Sample" to populate with cybersecurity questions</p>
            </div>
            <div class="help-section">
                <h3><i class="fas fa-bolt"></i> Troubleshooting</h3>
                <p><strong>If the UI doesn't update:</strong> Wait a moment - it will sync automatically.</p>
                <p><strong>If students can't buzz:</strong> Check they're on the quiz page with a stable connection.</p>
            </div>
        </div>
    </div>

    <!-- Network Status Indicator -->
    <div class="network-status" id="networkStatus">
        <i class="fas fa-circle-notch fa-spin"></i> Syncing...
    </div>

    <!-- Message System Container -->
    <div class="message-system" id="messageSystem"></div>

    <!-- Action Feedback Container -->
    <div class="action-feedback" id="actionFeedback"></div>

    <!-- Notes Panel -->
    <div id="notesPanel" class="notes-panel hidden">
        <div class="notes-header">
            <h3><i class="fas fa-sticky-note"></i> Class Notes</h3>
            <div class="notes-actions">
                <button id="toggleNotesEdit" class="btn btn-sm btn-secondary">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <button id="exportNotesPdf" class="btn btn-sm btn-secondary">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
                <button id="closeNotes" class="close-btn">&times;</button>
            </div>
        </div>
        <div id="notesContent" class="notes-content"></div>
        <div id="notesEditor" class="notes-editor hidden">
            <div class="formatting-toolbar">
                <button id="insertLinkBtn" title="Insert Link"><i class="fas fa-link"></i></button>
                <button id="boldBtn" title="Bold"><i class="fas fa-bold"></i></button>
                <button id="italicBtn" title="Italic"><i class="fas fa-italic"></i></button>
                <button id="bulletBtn" title="List"><i class="fas fa-list-ul"></i></button>
                <button id="headingBtn" title="Heading"><i class="fas fa-heading"></i></button>
            </div>
            <textarea id="notesTextarea" class="form-textarea"></textarea>
            <div class="notes-footer">
                <span class="hint">💡 Use markdown formatting</span>
                <button id="saveNotes" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Notes
                </button>
            </div>
        </div>
    </div>

    <!-- Sessions Modal -->
    <div id="sessionsModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Saved Sessions</h2>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body" id="sessionsList"></div>
            <div class="modal-footer">
                <button id="closeSessionsModal" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <!-- Save Session Modal -->
    <div id="saveSessionModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Save Session</h2>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Session Name</label>
                    <input type="text" id="sessionNameInput" class="form-input" placeholder="Enter session name...">
                </div>
            </div>
            <div class="modal-footer">
                <button id="cancelSaveSession" class="btn btn-secondary">Cancel</button>
                <button id="confirmSaveSession" class="btn btn-success">Save</button>
            </div>
        </div>
    </div>

    <!-- External Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
    <!-- App Entry Point (ES Module) -->
    <script type="module" src="assets/js/AdminApp.js"></script>
</body>
</html>
