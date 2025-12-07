# Copilot Coding Agent Instructions for "quizzera"

## Repository Overview
"quizzera" is an interactive quiz platform designed for multiplayer quiz games. It has a modularized architecture featuring a PHP/SQLite backend and a frontend built with vanilla JavaScript ES6 modules. The quiz application supports real-time interactions using player and admin interfaces.

### Repository Details
- **Owner**: VanBelfer
- **GitHub URL**: [VanBelfer/quizzera](https://github.com/VanBelfer/quizzera)
- **Default Branch**: `main`
- **Languages**:
  - PHP: 55.3%
  - JavaScript: 20.2%
  - Hack: 16.5%
  - CSS: 6.6%
  - Shell: 1.4%
- **Repository Size**: ~250 KB
- **Visibility**: Public

---

## Build and Validate Instructions

### Environment Setup
1. **Prerequisites**:
   - PHP 8.2 or higher
   - SQLite 3
   - A web server (e.g., Apache or NGINX) with `mod_rewrite` enabled.
   - Node.js (v16+) and npm for managing JavaScript dependencies and bundling assets (if required).

2. **Install PHP Dependencies**:
   Use `composer` to install dependencies.
   ```bash
   composer install
   ```

3. **Database Setup**:
   Ensure the `data/quiz.db` SQLite database file exists or initialize it using migrations (if applicable).

### Running and Validating Changes
1. **Start a Local Development Server**:
   ```bash
   php -S localhost:8080 -t public/
   ```
   This serves the `public/` directory as the document root.

2. **Access the Application**:
   - Player interface: [http://localhost:8080/index-modular.php](http://localhost:8080/index-modular.php)
   - Admin interface: [http://localhost:8080/admin-modular.php](http://localhost:8080/admin-modular.php)

3. **Test API Endpoints**:
   Utilize `curl` or Postman to validate the `public/api.php` endpoints. For example:
   ```bash
   curl -X POST -H "Content-Type: application/json" \
   -d '{"action":"getGameState"}' http://localhost:8080/api.php
   ```

4. **Run Tests**:
   Execute PHP Unit tests:
   ```bash
   ./vendor/bin/phpunit
   ```

5. **Lint Code**:
   - PHP: Run `phpcs` to ensure code standards compliance (PSR-12 recommended).
     ```bash
     ./vendor/bin/phpcs src/ --standard=PSR12
     ```
   - JavaScript: Use ESLint for JS validation:
     ```bash
     npx eslint public/assets/js/
     ```

---

## Project Layout Highlights
- **Backend (PHP)**:
  - `/data`: Contains the SQLite database (`quiz.db`).
  - `/src`: Core PHP code, e.g., `src/QuizManager.php` (game logic).
  - `/public/api.php`: Central API controller for handling requests.
- **Frontend (JavaScript/CSS)**:
  - `/public/assets/js`: Modularized ES6 JavaScript, main entry points are:
    - `PlayerApp.js` for player-side logic.
    - `AdminApp.js` for admin-side logic.
  - `/public/assets/css`: Contains CSS files and components (`variables.css` for shared styles).

- **Scripts**:
  - Testing: `./vendor/bin/phpunit`
  - Linting:
    - PHP: `./vendor/bin/phpcs`
    - JavaScript: `npx eslint`
  - Serve locally: `php -S localhost:8080 -t public/`

---

## Validation Steps
### Pre-Commit Validation
- Always run the linter for PHP and JavaScript before committing.
- Verify functionality with manual API calls or unit tests to avoid server errors.

### CI/CD and Workflows
No CI/CD workflows are currently defined in `.github/workflows/`. Consider checking dependencies manually and defining a pipeline in the future.

---

### Additional Notes
- Trust this document’s steps for testing and validation. Search should only be performed for missing validation cases or debugging.
- As of now, continuous integration setup (e.g., GitHub Actions) does not exist. Manual validation steps are required.
