<?php
// template_manager.php - Quiz Template Manager
// Handles template and image management

$templateDir = __DIR__ . '/templates';
$imageDir = __DIR__ . '/images';

// Ensure directories exist
if (!file_exists($templateDir)) mkdir($templateDir, 0755, true);
if (!file_exists($imageDir)) mkdir($imageDir, 0755, true);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Check if it's a file upload
    if (isset($_FILES['image'])) {
        $file = $_FILES['image'];
        
        // Validate image
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($file['type'], $allowedTypes)) {
            echo json_encode(['success' => false, 'error' => 'Invalid image type']);
            exit;
        }
        
        // Check file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'Image too large (max 5MB)']);
            exit;
        }
        
        // Generate safe filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeName = 'image_' . uniqid() . '.' . $extension;
        
        // Move to images directory
        if (move_uploaded_file($file['tmp_name'], $imageDir . '/' . $safeName)) {
            echo json_encode([
                'success' => true, 
                'url' => 'images/' . $safeName,
                'name' => $safeName
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to upload image']);
        }
        exit;
    }
    
    // Handle JSON requests
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    
    switch ($input['action']) {
        case 'getTemplates':
            $templates = [];
            $files = glob($templateDir . '/*.json');
            
            foreach ($files as $file) {
                $content = json_decode(file_get_contents($file), true);
                $templates[] = [
                    'id' => basename($file, '.json'),
                    'name' => $content['name'] ?? basename($file, '.json'),
                    'description' => $content['description'] ?? '',
                    'questionCount' => count($content['questions'] ?? []),
                    'createdAt' => filectime($file),
                    'updatedAt' => filemtime($file)
                ];
            }
            
            // Sort by updated date (newest first)
            usort($templates, function($a, $b) {
                return $b['updatedAt'] - $a['updatedAt'];
            });
            
            echo json_encode(['success' => true, 'templates' => $templates]);
            break;
            
        case 'saveTemplate':
            $templateId = $input['id'] ?? uniqid('tpl_');
            $templateName = trim($input['name'] ?? 'Untitled Template');
            $templateDescription = trim($input['description'] ?? '');
            $questions = $input['questions'] ?? [];
            
            if (empty($templateName)) {
                echo json_encode(['success' => false, 'error' => 'Template name is required']);
                break;
            }
            
            $existingFile = $templateDir . '/' . $templateId . '.json';
            $existingData = file_exists($existingFile) ? 
                json_decode(file_get_contents($existingFile), true) : null;
            
            $template = [
                'id' => $templateId,
                'name' => $templateName,
                'description' => $templateDescription,
                'questions' => $questions,
                'createdAt' => $existingData['createdAt'] ?? time(),
                'updatedAt' => time()
            ];
            
            if (file_put_contents($templateDir . '/' . $templateId . '.json', 
                json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                echo json_encode(['success' => true, 'template' => $template]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save template']);
            }
            break;
            
        case 'loadTemplate':
            $templateId = $input['id'] ?? '';
            
            if (empty($templateId)) {
                echo json_encode(['success' => false, 'error' => 'Template ID is required']);
                break;
            }
            
            // Sanitize ID to prevent directory traversal
            $templateId = basename($templateId);
            $templateFile = $templateDir . '/' . $templateId . '.json';
            
            if (file_exists($templateFile)) {
                $template = json_decode(file_get_contents($templateFile), true);
                echo json_encode(['success' => true, 'template' => $template]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Template not found']);
            }
            break;
            
        case 'deleteTemplate':
            $templateId = $input['id'] ?? '';
            
            if (empty($templateId)) {
                echo json_encode(['success' => false, 'error' => 'Template ID is required']);
                break;
            }
            
            // Sanitize ID to prevent directory traversal
            $templateId = basename($templateId);
            $templateFile = $templateDir . '/' . $templateId . '.json';
            
            if (file_exists($templateFile)) {
                if (unlink($templateFile)) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to delete template']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Template not found']);
            }
            break;
            
        case 'getImages':
            $images = [];
            $files = glob($imageDir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
            
            foreach ($files as $file) {
                $images[] = [
                    'name' => basename($file),
                    'url' => 'images/' . basename($file),
                    'size' => filesize($file),
                    'createdAt' => filectime($file)
                ];
            }
            
            // Sort by creation date (newest first)
            usort($images, function($a, $b) {
                return $b['createdAt'] - $a['createdAt'];
            });
            
            echo json_encode(['success' => true, 'images' => $images]);
            break;
            
        case 'deleteImage':
            $imageName = $input['name'] ?? '';
            
            if (empty($imageName)) {
                echo json_encode(['success' => false, 'error' => 'Image name is required']);
                break;
            }
            
            // Sanitize name to prevent directory traversal
            $imageName = basename($imageName);
            $imageFile = $imageDir . '/' . $imageName;
            
            if (file_exists($imageFile)) {
                if (unlink($imageFile)) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to delete image']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Image not found']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Template Manager</title>
    
    <!-- CSS Modules -->
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/buttons.css">
    <link rel="stylesheet" href="assets/css/forms.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="stylesheet" href="assets/css/components/modal.css">
    <link rel="stylesheet" href="assets/css/components/notifications.css">
    <link rel="stylesheet" href="assets/css/components/cards.css">
    
    <!-- External Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Template Manager specific styles */
        body {
            padding: 1rem;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .dashboard {
            background-color: var(--bg-gray-800);
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .header h1 {
            font-size: 2rem;
            font-weight: bold;
            color: var(--cyan-400);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .back-link {
            position: fixed;
            top: 1rem;
            left: 1rem;
            background-color: var(--bg-gray-700);
            color: var(--text-gray-400);
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            text-decoration: none;
            font-size: 0.875rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.25rem;
            z-index: 50;
        }
        
        .back-link:hover {
            background-color: var(--bg-gray-800);
            color: var(--text-white);
        }
        
        .template-controls {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .template-controls h2 {
            color: var(--text-white);
        }
        
        .templates-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .template-card {
            background-color: var(--bg-gray-900);
            border-radius: 0.5rem;
            padding: 1.5rem;
            transition: var(--transition);
            border: 1px solid var(--bg-gray-700);
        }
        
        .template-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            border-color: var(--cyan-500);
        }
        
        .template-card h3 {
            color: var(--text-white);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .template-card p {
            color: var(--text-gray-300);
            margin: 0.5rem 0;
            min-height: 2.5rem;
        }
        
        .template-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        
        .status-badge {
            background-color: var(--bg-gray-700);
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            color: var(--text-gray-300);
        }
        
        /* Image Gallery */
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .image-item {
            background-color: var(--bg-gray-900);
            border-radius: 0.5rem;
            overflow: hidden;
            position: relative;
            border: 1px solid var(--bg-gray-700);
        }
        
        .image-item:hover {
            border-color: var(--cyan-500);
        }
        
        .image-preview {
            width: 100%;
            height: 100px;
            object-fit: cover;
            display: block;
        }
        
        .image-info {
            padding: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-gray-300);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .image-actions {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            display: flex;
            gap: 0.25rem;
        }
        
        .image-action-btn {
            background-color: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            transition: var(--transition);
        }
        
        .image-item:hover .image-action-btn {
            opacity: 1;
        }
        
        .image-action-btn:hover {
            background-color: var(--cyan-600);
        }
        
        /* Upload Area */
        .image-upload-area {
            border: 2px dashed var(--bg-gray-700);
            border-radius: 0.5rem;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background-color: var(--bg-gray-900);
        }
        
        .image-upload-area:hover,
        .image-upload-area.dragover {
            border-color: var(--cyan-500);
            background-color: rgba(6, 182, 212, 0.05);
        }
        
        .image-upload-area i {
            font-size: 2rem;
            color: var(--text-gray-400);
            margin-bottom: 1rem;
        }
        
        .image-upload-area p {
            color: var(--text-gray-300);
            margin: 0.5rem 0;
        }
        
        .image-upload-area .hint {
            color: var(--text-gray-400);
            font-size: 0.8rem;
        }
        
        .image-gallery-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 1.5rem 0 1rem;
        }
        
        .image-gallery-controls h3 {
            color: var(--text-white);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-gray-400);
            grid-column: 1 / -1;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            color: var(--text-gray-300);
            margin-bottom: 0.5rem;
        }
        
        /* Search */
        .search-input {
            width: 300px;
            max-width: 100%;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .template-controls {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .search-input {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <a href="admin-modular.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Admin</a>

    <div class="container">
        <div class="dashboard">
            <div class="header">
                <h1><i class="fas fa-th-large"></i> Quiz Template Manager</h1>
                <button id="newTemplateBtn" class="btn btn-success">
                    <i class="fas fa-plus"></i> New Template
                </button>
            </div>
            
            <div class="template-controls">
                <h2>Your Quiz Templates</h2>
                <input type="text" id="templateSearch" class="form-input search-input" placeholder="Search templates...">
            </div>
            
            <div class="templates-list" id="templatesList">
                <!-- Templates will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Template Editor Modal -->
    <div id="templateEditor" class="modal hidden">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h2 id="templateEditorTitle">Create New Template</h2>
                <button class="modal-close" id="closeEditorBtn">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Template Name</label>
                    <input type="text" id="templateName" class="form-input" placeholder="Enter template name">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea id="templateDescription" class="form-textarea" placeholder="Enter template description" style="min-height: 80px;"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Questions JSON</label>
                    <textarea id="templateQuestions" class="form-textarea" placeholder="Paste questions JSON here..." style="min-height: 250px; font-family: monospace;"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Add Images to Questions</label>
                    <div class="image-upload-area" id="imageUploadArea">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Drag & drop images here or click to upload</p>
                        <p class="hint">Supports JPG, PNG, GIF (max 5MB)</p>
                        <input type="file" id="imageUploadInput" accept="image/*" style="display: none;">
                    </div>
                    
                    <div class="image-gallery-controls">
                        <h3>Your Images</h3>
                        <button id="refreshImagesBtn" class="btn btn-sm btn-secondary">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    <div class="image-gallery" id="imageGallery">
                        <!-- Images will be loaded here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button id="cancelTemplate" class="btn btn-danger">Cancel</button>
                <button id="saveTemplate" class="btn btn-success">Save Template</button>
            </div>
        </div>
    </div>

    <!-- Message System -->
    <div class="message-system" id="messageSystem"></div>

    <script type="module">
        // Template Manager Application
        
        let currentTemplateId = null;
        let currentCursorPosition = null;
        let allTemplates = [];
        
        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            loadTemplates();
            loadImages();
            setupEventListeners();
        });
        
        function setupEventListeners() {
            // New template button
            document.getElementById('newTemplateBtn').addEventListener('click', () => openTemplateEditor());
            
            // Save/Cancel buttons
            document.getElementById('saveTemplate').addEventListener('click', saveTemplate);
            document.getElementById('cancelTemplate').addEventListener('click', closeTemplateEditor);
            document.getElementById('closeEditorBtn').addEventListener('click', closeTemplateEditor);
            
            // Image upload
            const uploadArea = document.getElementById('imageUploadArea');
            const uploadInput = document.getElementById('imageUploadInput');
            
            uploadArea.addEventListener('click', () => uploadInput.click());
            uploadInput.addEventListener('change', (e) => handleImageUpload(e.target.files[0]));
            
            // Drag and drop
            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.classList.add('dragover');
            });
            
            uploadArea.addEventListener('dragleave', () => {
                uploadArea.classList.remove('dragover');
            });
            
            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    handleImageUpload(e.dataTransfer.files[0]);
                }
            });
            
            // Refresh images
            document.getElementById('refreshImagesBtn').addEventListener('click', loadImages);
            
            // Track cursor position in questions textarea
            const questionsTextarea = document.getElementById('templateQuestions');
            questionsTextarea.addEventListener('click', () => {
                currentCursorPosition = questionsTextarea.selectionStart;
            });
            questionsTextarea.addEventListener('keyup', () => {
                currentCursorPosition = questionsTextarea.selectionStart;
            });
            
            // Search templates
            document.getElementById('templateSearch').addEventListener('input', (e) => {
                filterTemplates(e.target.value);
            });
            
            // Close modal on backdrop click
            document.getElementById('templateEditor').addEventListener('click', (e) => {
                if (e.target.classList.contains('modal')) {
                    closeTemplateEditor();
                }
            });
        }
        
        // Load templates from server
        async function loadTemplates() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'getTemplates' })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    allTemplates = data.templates;
                    renderTemplates(data.templates);
                } else {
                    showMessage('error', 'Error loading templates: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                showMessage('error', 'Network error: ' + error.message);
            }
        }
        
        // Filter templates by search term
        function filterTemplates(searchTerm) {
            const term = searchTerm.toLowerCase();
            const filtered = allTemplates.filter(t => 
                t.name.toLowerCase().includes(term) || 
                (t.description && t.description.toLowerCase().includes(term))
            );
            renderTemplates(filtered);
        }
        
        // Render templates list
        function renderTemplates(templates) {
            const container = document.getElementById('templatesList');
            
            if (templates.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <h3>No templates found</h3>
                        <p>Click "New Template" to create your first quiz template</p>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = templates.map(template => `
                <div class="template-card" data-id="${template.id}">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3><i class="fas fa-file-alt"></i> ${escapeHtml(template.name)}</h3>
                        <span class="status-badge">${template.questionCount} questions</span>
                    </div>
                    <p>${escapeHtml(template.description) || 'No description provided'}</p>
                    <div class="template-actions">
                        <button class="btn btn-primary btn-sm load-template" data-id="${template.id}">
                            <i class="fas fa-play"></i> Use
                        </button>
                        <button class="btn btn-warning btn-sm edit-template" data-id="${template.id}">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn btn-danger btn-sm delete-template" data-id="${template.id}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `).join('');
            
            // Add event listeners
            container.querySelectorAll('.load-template').forEach(btn => {
                btn.addEventListener('click', () => loadTemplateIntoQuiz(btn.dataset.id));
            });
            
            container.querySelectorAll('.edit-template').forEach(btn => {
                btn.addEventListener('click', () => editTemplate(btn.dataset.id));
            });
            
            container.querySelectorAll('.delete-template').forEach(btn => {
                btn.addEventListener('click', () => deleteTemplate(btn.dataset.id));
            });
        }
        
        // Open template editor
        function openTemplateEditor(template = null) {
            currentTemplateId = template ? template.id : null;
            
            document.getElementById('templateEditorTitle').textContent = 
                template ? 'Edit Template' : 'Create New Template';
            
            document.getElementById('templateName').value = template?.name || '';
            document.getElementById('templateDescription').value = template?.description || '';
            document.getElementById('templateQuestions').value = template ? 
                JSON.stringify(template.questions, null, 2) : 
                JSON.stringify([{
                    "question": "What is phishing?",
                    "options": ["Deceptive emails to steal information", "Fishing for real fish"],
                    "correct": 0,
                    "image": "",
                    "explanation": "Phishing is a cybersecurity attack using fake emails to steal personal information."
                }], null, 2);
            
            document.getElementById('templateEditor').classList.remove('hidden');
            loadImages();
        }
        
        // Close template editor
        function closeTemplateEditor() {
            document.getElementById('templateEditor').classList.add('hidden');
            currentTemplateId = null;
        }
        
        // Save template
        async function saveTemplate() {
            const name = document.getElementById('templateName').value.trim();
            
            if (!name) {
                showMessage('error', 'Please enter a template name');
                return;
            }
            
            let questions;
            try {
                questions = JSON.parse(document.getElementById('templateQuestions').value);
            } catch (e) {
                showMessage('error', 'Invalid JSON format: ' + e.message);
                return;
            }
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'saveTemplate',
                        id: currentTemplateId,
                        name: name,
                        description: document.getElementById('templateDescription').value,
                        questions: questions
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    closeTemplateEditor();
                    loadTemplates();
                    showMessage('success', 'Template saved successfully!');
                } else {
                    showMessage('error', 'Error saving template: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                showMessage('error', 'Network error: ' + error.message);
            }
        }
        
        // Edit template
        async function editTemplate(templateId) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'loadTemplate', id: templateId })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    openTemplateEditor(data.template);
                } else {
                    showMessage('error', 'Error loading template: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                showMessage('error', 'Network error: ' + error.message);
            }
        }
        
        // Delete template
        async function deleteTemplate(templateId) {
            if (!confirm('Are you sure you want to delete this template? This cannot be undone.')) {
                return;
            }
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'deleteTemplate', id: templateId })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    loadTemplates();
                    showMessage('success', 'Template deleted successfully!');
                } else {
                    showMessage('error', 'Error deleting template: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                showMessage('error', 'Network error: ' + error.message);
            }
        }
        
        // Load template into quiz (send to parent window)
        async function loadTemplateIntoQuiz(templateId) {
            if (!confirm('Loading this template will replace your current questions. Continue?')) {
                return;
            }
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'loadTemplate', id: templateId })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Send to parent window (admin panel)
                    if (window.opener) {
                        window.opener.postMessage({
                            type: 'loadTemplate',
                            questions: data.template.questions
                        }, '*');
                        window.close();
                    } else {
                        // If opened in same window, store in localStorage
                        localStorage.setItem('pendingTemplate', JSON.stringify(data.template.questions));
                        showMessage('success', 'Template loaded! Return to admin panel to apply it.');
                    }
                } else {
                    showMessage('error', 'Error loading template: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                showMessage('error', 'Network error: ' + error.message);
            }
        }
        
        // Load images
        async function loadImages() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'getImages' })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    renderImageGallery(data.images);
                }
            } catch (error) {
                console.error('Error loading images:', error);
            }
        }
        
        // Render image gallery
        function renderImageGallery(images) {
            const container = document.getElementById('imageGallery');
            
            if (images.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-image"></i>
                        <p>No images uploaded yet</p>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = images.map(image => `
                <div class="image-item">
                    <img src="${image.url}" class="image-preview" alt="${escapeHtml(image.name)}">
                    <div class="image-info">${escapeHtml(image.name)}</div>
                    <div class="image-actions">
                        <button class="image-action-btn insert-image" title="Insert into question" data-url="${image.url}">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button class="image-action-btn copy-url" title="Copy URL" data-url="${image.url}">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
            `).join('');
            
            // Add event listeners
            container.querySelectorAll('.insert-image').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    insertImageUrl(btn.dataset.url);
                });
            });
            
            container.querySelectorAll('.copy-url').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    navigator.clipboard.writeText(btn.dataset.url)
                        .then(() => showMessage('success', 'URL copied to clipboard!'))
                        .catch(() => showMessage('error', 'Failed to copy URL'));
                });
            });
        }
        
        // Handle image upload
        async function handleImageUpload(file) {
            if (!file) return;
            
            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!allowedTypes.includes(file.type)) {
                showMessage('error', 'Invalid image type. Use JPG, PNG, or GIF.');
                return;
            }
            
            // Validate file size (5MB max)
            if (file.size > 5 * 1024 * 1024) {
                showMessage('error', 'Image too large. Maximum size is 5MB.');
                return;
            }
            
            const formData = new FormData();
            formData.append('image', file);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    insertImageUrl(data.url);
                    loadImages();
                    showMessage('success', 'Image uploaded successfully!');
                } else {
                    showMessage('error', 'Error uploading image: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                showMessage('error', 'Network error: ' + error.message);
            }
            
            // Reset input
            document.getElementById('imageUploadInput').value = '';
        }
        
        // Insert image URL into questions JSON
        function insertImageUrl(url) {
            const textarea = document.getElementById('templateQuestions');
            const value = textarea.value;
            const pos = currentCursorPosition ?? textarea.selectionStart;
            
            // Try to find nearest "image": "" field
            const lines = value.split('\n');
            let charCount = 0;
            let insertLine = -1;
            
            for (let i = 0; i < lines.length; i++) {
                if (charCount + lines[i].length >= pos) {
                    insertLine = i;
                    break;
                }
                charCount += lines[i].length + 1;
            }
            
            if (insertLine !== -1) {
                // Look for image field near cursor
                for (let i = insertLine; i >= Math.max(0, insertLine - 10); i--) {
                    if (lines[i].includes('"image":')) {
                        lines[i] = lines[i].replace(/"image":\s*"[^"]*"/, `"image": "${url}"`);
                        textarea.value = lines.join('\n');
                        showMessage('success', 'Image URL inserted!');
                        return;
                    }
                    if (lines[i].trim() === '{' || lines[i].includes('"question":')) {
                        break;
                    }
                }
            }
            
            // Fallback: insert at cursor
            textarea.value = value.substring(0, pos) + `"image": "${url}"` + value.substring(pos);
            showMessage('info', 'Image URL inserted at cursor position');
        }
        
        // Show message notification
        function showMessage(type, message) {
            const container = document.getElementById('messageSystem');
            
            const msg = document.createElement('div');
            msg.className = `message message-${type}`;
            msg.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${escapeHtml(message)}</span>
            `;
            
            container.appendChild(msg);
            
            setTimeout(() => {
                msg.classList.add('fade-out');
                setTimeout(() => msg.remove(), 300);
            }, 3000);
        }
        
        // Escape HTML
        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    </script>
</body>
</html>
