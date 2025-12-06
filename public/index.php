<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interactive Quiz</title>
    
    <!-- CSS Modules -->
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link rel="stylesheet" href="assets/css/forms.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    
    <!-- Shared Components CSS -->
    <link rel="stylesheet" href="assets/css/components/help-panel.css">
    <link rel="stylesheet" href="assets/css/components/notifications.css">
    <link rel="stylesheet" href="assets/css/components/progress.css">
    <link rel="stylesheet" href="assets/css/components/cards.css">
    
    <!-- Player-specific CSS -->
    <link rel="stylesheet" href="assets/css/player/login.css">
    <link rel="stylesheet" href="assets/css/player/buzzer.css">
    <link rel="stylesheet" href="assets/css/player/options.css">
    <link rel="stylesheet" href="assets/css/player/end-screen.css">
    <link rel="stylesheet" href="assets/css/player/notes-panel.css">
    
    <!-- External Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <!-- Login Screen -->
        <div id="loginScreen" class="screen login-screen">
            <h1>Interactive Quiz</h1>
            <p class="subtitle">Enter your name to join the quiz</p>
            
            <form id="loginForm">
                <input type="text" id="nicknameInput" class="form-input" placeholder="Enter your name..." required autocomplete="off">
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Join Quiz
                </button>
            </form>
        </div>

        <!-- Waiting Screen -->
        <div id="waitingScreen" class="screen waiting-screen hidden">
            <h1>Waiting for Quiz to Start</h1>
            <div class="spinner"></div>
            <p class="subtitle">The teacher will start the quiz soon...</p>
            
            <div class="progress-container">
                <div class="progress-bar" id="quizProgress"></div>
            </div>
        </div>

        <!-- Quiz Screen -->
        <div id="quizScreen" class="screen hidden">
            <div class="question-display">
                <div class="question-number" id="questionNumber">
                    <i class="fas fa-question-circle"></i> Question 1 of 10
                </div>
                <div class="question-text" id="questionText">Question will appear here</div>
                <img id="questionImage" class="question-image hidden" src="" alt="Question image">
            </div>

            <div id="statusMessage" class="status-message status-waiting">
                Waiting for question...
            </div>

            <!-- Buzzer Phase -->
            <div id="buzzerPhase" class="hidden">
                <button id="buzzerBtn" class="buzzer-btn">
                    <span class="buzzer-text">BUZZ!</span>
                    <span class="buzzer-icon"><i class="fas fa-bell"></i></span>
                </button>
                <p class="buzzer-hint">Be the first to buzz in when you know the answer!</p>
            </div>

            <!-- Options Phase -->
            <div id="optionsPhase" class="hidden">
                <div class="options-grid" id="optionsGrid">
                    <!-- Options populated by JavaScript -->
                </div>
            </div>
            
            <!-- Reveal Phase -->
            <div id="revealPhase" class="hidden">
                <div class="reveal-card correct">
                    <h4><i class="fas fa-check-circle"></i> Correct Answer</h4>
                    <p id="correctAnswerText"></p>
                </div>
                
                <div id="explanationSection" class="reveal-card explanation hidden">
                    <h4><i class="fas fa-book"></i> Explanation</h4>
                    <p id="questionExplanation"></p>
                </div>
            </div>
        </div>

        <!-- Quiz End Screen -->
        <div id="endScreen" class="screen hidden">
            <div class="end-screen-content">
                <h1 class="trophy-title">
                    <i class="fas fa-trophy"></i> Quiz Complete!
                </h1>
                <p class="congrats-text">
                    Congratulations! You have completed the quiz.
                </p>
                
                <div id="performanceSummary" class="performance-summary">
                    <h3><i class="fas fa-chart-bar"></i> Your Performance</h3>
                    <div id="performanceStats" class="performance-stats">
                        <!-- Stats populated by JavaScript -->
                    </div>
                </div>
                
                <div id="detailedBreakdown" class="detailed-breakdown">
                    <h3><i class="fas fa-list-alt"></i> Question-by-Question Breakdown</h3>
                    <div id="questionBreakdown">
                        <!-- Breakdown populated by JavaScript -->
                    </div>
                </div>
                
                <div class="end-screen-actions">
                    <button id="downloadVocabularyBtn" class="btn btn-success">
                        <i class="fas fa-download"></i> Download Vocabulary (PDF)
                    </button>
                    <button id="downloadNotesBtn" class="btn btn-primary">
                        <i class="fas fa-sticky-note"></i> Download Notes (PDF)
                    </button>
                    <button id="newQuizBtn" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Wait for New Quiz
                    </button>
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
            <div class="help-title">Quiz Help</div>
            <button class="help-close" id="helpClose">&times;</button>
        </div>
        
        <div class="help-content">
            <div class="help-section">
                <h3><i class="fas fa-gamepad"></i> How to Play</h3>
                <p><strong>1. When you see a question:</strong> If you know the answer, press the BUZZ button as fast as you can!</p>
                <p><strong>2. If you buzz first:</strong> The teacher will call on you to answer</p>
                <p><strong>3. When options appear:</strong> Select your answer - you'll get immediate feedback!</p>
                
                <div class="help-tip">
                    <strong>Keyboard Shortcuts:</strong> Press Space or Enter to buzz, use 1-4 keys to select answers.
                </div>
            </div>
            
            <div class="help-section">
                <h3><i class="fas fa-star"></i> Scoring</h3>
                <p>- Points are awarded for correct answers</p>
                <p>- Buzzing first gives you a chance to answer</p>
                <p>- Fast and accurate answers earn the most points!</p>
            </div>
            
            <div class="help-section">
                <h3><i class="fas fa-bolt"></i> Tips</h3>
                <p>- Stay focused on the question</p>
                <p>- Don't buzz unless you're confident</p>
                <p>- Read all options before selecting</p>
            </div>
        </div>
    </div>

    <!-- Student Notes Button -->
    <div class="student-notes-toggle" id="studentNotesBtn">
        <i class="fas fa-sticky-note"></i>
    </div>

    <!-- Student Notes Panel -->
    <div id="studentNotesPanel" class="student-notes-panel hidden">
        <div class="notes-header">
            <h3><i class="fas fa-sticky-note"></i> Class Notes</h3>
            <button id="closeStudentNotes" class="close-btn">&times;</button>
        </div>
        <div id="studentNotesContent" class="notes-content">
            <!-- Notes loaded by JavaScript -->
        </div>
    </div>

    <!-- Network Status Indicator -->
    <div class="network-status" id="networkStatus">
        <i class="fas fa-circle-notch fa-spin"></i> Connecting...
    </div>

    <!-- Message System Container -->
    <div class="message-system" id="messageSystem"></div>

    <!-- Action Feedback Container -->
    <div class="action-feedback" id="actionFeedback"></div>

    <!-- External Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
    <!-- App Entry Point (ES Module) -->
    <script type="module" src="assets/js/PlayerApp.js"></script>
</body>
</html>
