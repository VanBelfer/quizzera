# Quizerka Roadmap - Evolution into Teaching Platform

> Vision: Transform Quizerka from a quiz app into a comprehensive **Teaching & Learning Platform** with slides, tasks, media, and interactive exercises.

---

## 🎯 Current State (v1.0 - Quiz Module)

### ✅ Completed Features
- [x] Real-time quiz with buzzer and multiple choice
- [x] Admin game control (start, options, reveal, next)
- [x] Player feedback (correct/incorrect toasts)
- [x] Auto-advance when all players answer
- [x] End screen with performance stats
- [x] PDF export for vocabulary and notes
- [x] Markdown notes with live editing
- [x] Notes sharing with update notifications
- [x] Session save/load
- [x] Responsive design

---

## 🎓 Phase 0.5: Content Delivery Modes (PRIORITY)

> **Critical for the platform vision**: Support content delivery with or without real-time admin control.

### Delivery Mode Types
The platform must support multiple delivery paradigms:

| Mode | Admin Required | Student Control | Use Case |
|------|----------------|-----------------|----------|
| **Live/Synchronized** | ✅ Yes | ❌ None | Real-time classroom, teacher controls pace |
| **Self-Paced** | ❌ No | ✅ Full | Homework, self-study, asynchronous learning |
| **Guided** | ⚠️ Optional | ⚠️ Partial | Admin can monitor but student advances themselves |
| **Assessment** | ❌ No | ⚠️ Limited | Timed tests, no backtracking |

### Implementation
```javascript
lesson.settings = {
    deliveryMode: 'self-paced' | 'live' | 'guided' | 'assessment',
    allowBackNavigation: true,
    autoAdvance: false,
    requireCompletion: true,  // Must complete section to proceed
    showProgress: true,
    adminOverride: true       // Admin can take control anytime
}
```

### Tasks
- [ ] Add `deliveryMode` to lesson settings schema
- [ ] Implement student-controlled navigation for self-paced mode
- [ ] Add "Take Control" / "Release Control" toggle for admin in guided mode
- [ ] Create progress tracking that works without admin presence
- [ ] Support seamless switching between modes mid-lesson

---

## 🚀 Phase 1: Core Platform Infrastructure

### 1.1 Multi-Module Architecture
Transform from single quiz to modular platform:

```
/modules/
├── quiz/           # Current quiz functionality
├── slides/         # Presentation slides
├── tasks/          # Interactive tasks/exercises
├── media/          # Media gallery
└── whiteboard/     # Drawing/annotation
```

**Implementation:**
- [ ] Create module loader system
- [ ] Add module switcher in admin UI
- [ ] Implement module registry in PHP
- [ ] Create base Module class for JS

### 1.2 Session/Lesson Management
```
Lesson
├── Section 1: Introduction (slides)
├── Section 2: Main Content (slides + quiz)
├── Section 3: Practice (tasks)
└── Section 4: Summary (media + notes)
```

**Database changes:**
```sql
CREATE TABLE lessons (
    id TEXT PRIMARY KEY,
    title TEXT,
    description TEXT,
    created_at DATETIME
);

CREATE TABLE lesson_sections (
    id TEXT PRIMARY KEY,
    lesson_id TEXT,
    module_type TEXT,  -- 'quiz', 'slides', 'tasks', 'media'
    content JSON,
    order_index INTEGER
);
```

---

## 📚 Phase 1.5: Content Blocks (Mixed Content Sections)

> **Design Principle**: Sections contain mixed content blocks, not rigid module boundaries. This enables rich, flowing educational content.

### Block Types
Instead of switching between separate modules, a single section can contain multiple block types:

```
Section: "Introduction to Phishing"
├── Block 1: Text (markdown content explaining concept)
├── Block 2: Video (YouTube embed demonstrating attack)
├── Block 3: Interactive (embedded 2-question mini-quiz)
├── Block 4: Text (summary points)
└── Block 5: Task (fill-in-the-blanks exercise)
```

### Supported Block Types
| Block Type | Interactive | Completion Criteria |
|------------|-------------|---------------------|
| `text` | No | View / scroll to end |
| `video` | No | Watch X% or duration |
| `image` | No | View |
| `quiz` | Yes | Submit answers |
| `task` | Yes | Complete exercise |
| `embed` | Varies | Custom |
| `code` | Optional | Run / submit |
| `audio` | No | Listen X% |

### Content Block Schema
```sql
CREATE TABLE content_blocks (
    id TEXT PRIMARY KEY,
    section_id TEXT,
    block_type TEXT,  -- 'text', 'video', 'image', 'quiz', 'task', 'embed', 'code', 'audio'
    content JSON,
    order_index INTEGER,
    required BOOLEAN DEFAULT false,  -- Must interact to proceed
    completion_criteria JSON  -- e.g., {"type": "view", "duration": 10} or {"type": "score", "min": 70}
);
```

### Tasks
- [ ] Design ContentBlock base class with common interface
- [ ] Create BlockRenderer component that delegates to type-specific renderers
- [ ] Implement block completion tracking
- [ ] Add inline quiz/task embedding within content flow
- [ ] Support "read-only" content blocks (no interaction required)
- [ ] Create block editor UI for admin (drag-drop ordering)

---

## 📊 Phase 2: Slides/Presentation Module

### 2.1 Slide Types
- **Text Slide**: Title, subtitle, body text with markdown
- **Image Slide**: Full-screen or split with text
- **Video Slide**: YouTube embed or local video
- **Code Slide**: Syntax-highlighted code blocks
- **Comparison Slide**: Side-by-side content
- **List Slide**: Bullet points with reveal animation

### 2.2 Admin Features
- [ ] Visual slide editor (WYSIWYG)
- [ ] Slide templates gallery
- [ ] Drag-and-drop reordering
- [ ] Slide preview mode
- [ ] Import from Markdown/JSON

### 2.3 Player Features
- [ ] Synchronized slide view (teacher controls)
- [ ] Slide navigation (when allowed)
- [ ] Fullscreen mode
- [ ] Note-taking per slide

### 2.4 Technical Implementation
```javascript
// Slide data structure
{
    id: "slide_001",
    type: "text",
    layout: "title-body",
    content: {
        title: "Introduction to Cybersecurity",
        body: "Markdown content here...",
        notes: "Speaker notes (admin only)"
    },
    transitions: {
        enter: "fade",
        exit: "slide-left"
    }
}
```

---

## 🚦 Phase 2.5: Progress & Gating System

> **Enables self-paced learning**: Students can progress independently with optional checkpoints and requirements.

### Progress Tracking (Works Without Admin)
```javascript
studentProgress = {
    odlessonId: "lesson_001",
    startedAt: "2024-01-15T10:00:00Z",
    sections: {
        "section_001": { 
            status: "completed", 
            score: 85, 
            completedAt: "2024-01-15T10:15:00Z",
            attempts: 1
        },
        "section_002": { 
            status: "in_progress", 
            currentBlock: 3,
            blockProgress: { "block_1": true, "block_2": true, "block_3": false }
        },
        "section_003": { status: "locked" }
    },
    overallProgress: 45  // percentage
}
```

### Gating Rules
| Gate Type | Description | Example |
|-----------|-------------|---------|
| Sequential | Complete previous to unlock next | Section 2 requires Section 1 |
| Score-based | Minimum score required | Need 70%+ to proceed |
| Time-based | Available after specific date/time | Unlocks on Monday |
| Prerequisite | Requires completing other lessons | Lesson B requires Lesson A |
| Manual | Admin unlocks manually | Teacher approval needed |

### Branching Content (Adaptive Learning)
```javascript
section.branching = {
    evaluateAfter: "completion",  // or "score"
    rules: [
        { condition: "score < 50", goto: "section_remedial_basic" },
        { condition: "score < 70", goto: "section_remedial_advanced" },
        { condition: "score >= 70", goto: "section_next" }
    ]
}
```

### Tasks
- [ ] Create StudentProgress tracking service
- [ ] Implement localStorage persistence for anonymous progress
- [ ] Add section locking/unlocking logic
- [ ] Build progress visualization (progress bar, section map)
- [ ] Implement branching content router
- [ ] Add "resume where you left off" functionality

---

## 📝 Phase 3: Tasks/Exercises Module

### 3.1 Task Types
- **Fill in the Blanks**: Text with missing words
- **Matching**: Connect items (drag & drop)
- **Ordering**: Arrange items in sequence
- **Short Answer**: Free text response
- **Code Exercise**: Write/complete code
- **Drawing/Labeling**: Annotate images

### 3.2 Features
- [ ] Instant feedback mode
- [ ] Retry attempts limit
- [ ] Hints system
- [ ] Time limits (optional)
- [ ] Grading/scoring
- [ ] Export results

### 3.3 Example Task Structure
```javascript
{
    type: "fill_blanks",
    instruction: "Complete the definition:",
    content: "{{Phishing}} is a type of {{social engineering}} attack...",
    blanks: [
        { id: 1, answer: "Phishing", hints: ["Starts with P", "Type of cyber attack"] },
        { id: 2, answer: "social engineering", hints: ["Manipulating people"] }
    ],
    points: 10
}
```

---

## 🎬 Phase 4: Media Module

### 4.1 Supported Media
- Images (gallery view, lightbox)
- Videos (YouTube, Vimeo, local)
- Audio clips
- PDFs (embedded viewer)
- External links (iframe embeds)

### 4.2 Features
- [ ] Media library management
- [ ] Drag-and-drop upload
- [ ] Auto-thumbnails
- [ ] Categories/tags
- [ ] Search/filter
- [ ] Embed in other modules

### 4.3 Storage Options
```php
// Local storage
/data/media/{session_id}/{filename}

// Or integrate with:
- Cloudinary
- AWS S3
- Local NAS
```

---

## 🎨 Phase 5: Enhanced UI/UX

### 5.1 Theme System
- [ ] Dark/Light mode toggle
- [ ] Custom color schemes
- [ ] Font size controls
- [ ] High contrast mode

### 5.2 Accessibility
- [ ] Keyboard navigation
- [ ] Screen reader support
- [ ] ARIA labels
- [ ] Focus indicators

### 5.3 Mobile Optimization
- [ ] Touch-friendly controls
- [ ] Swipe navigation
- [ ] Responsive breakpoints
- [ ] PWA support (offline mode)

---

## 👤 Phase 6: Identity & Progress Persistence

> **Principle**: Learning should work without complex authentication. Provide multiple identity levels based on needs.

### Identity Tiers

| Tier | Persistence | Cross-Device | Admin Visibility | Setup |
|------|-------------|--------------|------------------|-------|
| **Anonymous** | Session only | ❌ No | ❌ No | None |
| **Named Guest** | localStorage | ❌ No | ✅ Yes (name only) | Enter name |
| **Code-Based** | Server-side | ✅ Yes | ✅ Yes | Enter resume code |
| **Registered** | Full account | ✅ Yes | ✅ Full | Email/password |

### Resume Code System (Recommended Default)
```javascript
// Simple identity without auth
student = {
    id: "generated_uuid",
    displayName: "John",
    resumeCode: "QUIZ-ABC123",  // 8-char code for cross-device resume
    createdAt: "2024-01-15T10:00:00Z",
    lastActiveAt: "2024-01-15T14:30:00Z"
}
```

**Flow:**
1. Student enters name → gets UUID + resume code
2. Progress saved to server with UUID
3. On new device: enter resume code → retrieve progress
4. Optional: email the resume code for safekeeping

### Tasks
- [ ] Implement localStorage-based progress for anonymous/named users
- [ ] Create resume code generation and validation
- [ ] Add "Email my resume code" option
- [ ] Build progress sync between localStorage and server
- [ ] Support multiple students on shared device (profile switcher)

---

## 🛠 Technical Improvements

### Backend
- [ ] RESTful API structure
- [ ] API versioning
- [ ] Request validation
- [ ] Error handling middleware
- [ ] Logging system

### Frontend
- [ ] Service Worker (offline support)
- [ ] State persistence (localStorage)
- [ ] Lazy loading modules
- [ ] Bundle optimization

### DevOps
- [ ] Docker containerization
- [ ] CI/CD pipeline
- [ ] Automated testing
- [ ] Performance monitoring

---

## 📅 Suggested Implementation Order

### Sprint 1 (Foundation)
1. Module loader architecture
2. Lesson/section database schema
3. Admin module switcher UI

### Sprint 2 (Slides)
1. Basic slide renderer
2. Text and image slides
3. Admin slide editor
4. Synchronized viewing

### Sprint 3 (Tasks)
1. Fill-in-blanks task type
2. Matching task type
3. Instant feedback system
4. Task results tracking

### Sprint 4 (Media & Polish)
1. Media upload/gallery
2. Video embeds
3. Theme system
4. Mobile optimization

---

## 🧩 Modularity & Extension Principles

> **Core Architecture Goal**: Adding new block types, task types, or modules should NEVER break existing functionality.

### Extension Points

| Extension Type | How to Add | Existing Code Impact |
|----------------|------------|----------------------|
| New Block Type | Add renderer in `/js/blocks/` | None - BlockRenderer auto-discovers |
| New Task Type | Add handler in `/js/tasks/` | None - TaskRunner auto-discovers |
| New Module | Add folder in `/modules/` | None - ModuleLoader auto-discovers |
| New Delivery Mode | Add mode handler | None - ModeManager delegates |

### Block Type Registration
```javascript
// Adding a new block type (e.g., "poll")
// 1. Create: /public/assets/js/blocks/PollBlock.js
export class PollBlock extends BaseBlock {
    static type = 'poll';
    static schema = { question: 'string', options: 'array' };
    
    render(container) { /* ... */ }
    getCompletionStatus() { /* ... */ }
}

// 2. Block is auto-registered - no changes to existing code needed
```

### Task Type Registration  
```javascript
// Adding a new task type (e.g., "drag-drop")
// 1. Create: /public/assets/js/tasks/DragDropTask.js
export class DragDropTask extends BaseTask {
    static type = 'drag_drop';
    
    render(container) { /* ... */ }
    validate() { /* ... */ }
    getScore() { /* ... */ }
}

// 2. Task is auto-registered - no changes to existing code needed
```

### Plugin Architecture (Future)
```
/plugins/
├── plugin-manifest.json
├── kahoot-import/
│   ├── manifest.json
│   └── importer.js
├── google-classroom/
│   └── ...
└── custom-theme/
    └── ...
```

### Testing New Extensions
When adding any new block/task/module:
1. Existing `test-modular.sh` must still pass
2. Add type-specific tests in `/tests/{type}/`
3. Verify no console errors on all existing content

### Tasks
- [ ] Implement BaseBlock class with required interface
- [ ] Create BlockRegistry with auto-discovery
- [ ] Implement BaseTask class with required interface
- [ ] Create TaskRegistry with auto-discovery
- [ ] Add extension validation (schema checking)
- [ ] Document extension API for contributors

---

## 💡 Quick Wins (Low Effort, High Impact)

1. **Slide mode for existing questions** - Display questions as presentation slides
2. **Timer per question** - Add countdown timer option
3. **Sound effects** - Audio feedback for correct/incorrect
4. **Leaderboard** - Real-time ranking during quiz
5. **QR Code join** - Generate QR for quick student join
6. **Markdown slides** - Quick slides from markdown files
7. **Import quiz from CSV** - Bulk question import

---

## 🔗 Integration Ideas

- **Google Classroom** - Import/export
- **LTI** - LMS integration
- **Kahoot import** - Convert Kahoot quizzes
- **Notion** - Sync content from Notion pages
- **GitHub** - Version control for content

---

## Notes for Implementation

When starting a new module:
1. Create folder in `/public/assets/js/modules/{name}/`
2. Define module interface extending BaseModule
3. Add CSS in `/public/assets/css/modules/{name}/`
4. Register in ModuleLoader
5. Add API endpoints in `api.php`
6. Create PHP handler in `/src/Modules/`

```javascript
// Base module interface
class BaseModule {
    constructor(options) {}
    init() {}
    activate() {}    // When module becomes active
    deactivate() {}  // When switching away
    render() {}
    destroy() {}
}
```
