/**
 * Markdown Renderer Component
 * Simple markdown to HTML converter
 */

export class MarkdownRenderer {
    constructor(options = {}) {
        this.options = {
            sanitize: true,
            linkTarget: '_blank',
            ...options
        };
    }

    /**
     * Render markdown to HTML
     * @param {string} text - Markdown text
     * @returns {string} - HTML string
     */
    render(text) {
        if (!text) return '';

        let html = this.options.sanitize ? this.escapeHtml(text) : text;

        // Headers (# ## ###)
        html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');

        // Bold (**text** or __text__)
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__(.+?)__/g, '<strong>$1</strong>');

        // Italic (*text* or _text_)
        html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
        html = html.replace(/_(.+?)_/g, '<em>$1</em>');

        // Strikethrough (~~text~~)
        html = html.replace(/~~(.+?)~~/g, '<del>$1</del>');

        // Code (inline `code`)
        html = html.replace(/`(.+?)`/g, '<code>$1</code>');

        // Links [text](url)
        const linkTarget = this.options.linkTarget;
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, 
            `<a href="$2" target="${linkTarget}" rel="noopener noreferrer">$1</a>`);

        // Unordered lists (- item or * item)
        html = html.replace(/^[\-\*] (.+)$/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>');

        // Ordered lists (1. item)
        html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
        // Note: This is a simplified approach, won't handle nested lists well

        // Horizontal rule (---, ***, ___)
        html = html.replace(/^([-*_]){3,}$/gm, '<hr>');

        // Line breaks (double newline = paragraph)
        html = html.replace(/\n\n/g, '</p><p>');
        
        // Single newline = <br>
        html = html.replace(/\n/g, '<br>');

        // Wrap in paragraph if not starting with block element
        if (!html.startsWith('<h') && !html.startsWith('<ul') && !html.startsWith('<ol')) {
            html = '<p>' + html + '</p>';
        }

        // Clean up empty paragraphs
        html = html.replace(/<p><\/p>/g, '');
        html = html.replace(/<p>(<h[123]>)/g, '$1');
        html = html.replace(/(<\/h[123]>)<\/p>/g, '$1');

        return html;
    }

    /**
     * Render markdown and insert into element
     * @param {HTMLElement} element - Target element
     * @param {string} text - Markdown text
     */
    renderTo(element, text) {
        if (element) {
            element.innerHTML = this.render(text);
        }
    }

    /**
     * Escape HTML entities
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Convert HTML back to basic markdown (for editing)
     */
    toMarkdown(html) {
        let text = html;

        // Convert common HTML to markdown
        text = text.replace(/<h1>(.+?)<\/h1>/gi, '# $1\n');
        text = text.replace(/<h2>(.+?)<\/h2>/gi, '## $1\n');
        text = text.replace(/<h3>(.+?)<\/h3>/gi, '### $1\n');
        text = text.replace(/<strong>(.+?)<\/strong>/gi, '**$1**');
        text = text.replace(/<em>(.+?)<\/em>/gi, '*$1*');
        text = text.replace(/<code>(.+?)<\/code>/gi, '`$1`');
        text = text.replace(/<a[^>]+href="([^"]+)"[^>]*>(.+?)<\/a>/gi, '[$2]($1)');
        text = text.replace(/<br\s*\/?>/gi, '\n');
        text = text.replace(/<\/p>\s*<p>/gi, '\n\n');
        text = text.replace(/<\/?p>/gi, '');
        text = text.replace(/<li>(.+?)<\/li>/gi, '- $1\n');
        text = text.replace(/<\/?[uo]l>/gi, '');

        return text.trim();
    }
}

// Factory function
export function createMarkdownRenderer(options = {}) {
    return new MarkdownRenderer(options);
}

// Default instance
export const markdown = new MarkdownRenderer();

// Quick render function
export function renderMarkdown(text, options = {}) {
    const renderer = new MarkdownRenderer(options);
    return renderer.render(text);
}
