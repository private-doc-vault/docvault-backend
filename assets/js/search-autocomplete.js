/**
 * Search Autocomplete Component
 *
 * Provides real-time search suggestions as the user types
 * Features:
 * - Debounced API calls to prevent excessive requests
 * - Keyboard navigation (arrow keys, enter, escape)
 * - Click to select suggestions
 * - Minimum character threshold (2 chars)
 * - Category badges for suggestions
 * - Loading states
 */

import axios from 'axios';
import { debounce } from './utils';

class SearchAutocomplete {
    constructor(inputSelector, suggestionsSelector) {
        this.input = document.querySelector(inputSelector);
        this.suggestionsContainer = document.querySelector(suggestionsSelector);
        this.suggestions = [];
        this.selectedIndex = -1;
        this.minChars = 2;
        this.isLoading = false;
        this.onSelectCallback = null;

        if (this.input && this.suggestionsContainer) {
            this.init();
        }
    }

    /**
     * Initialize autocomplete
     */
    init() {
        // Create debounced search function
        this.debouncedSearch = debounce((query) => this.fetchSuggestions(query), 300);

        // Bind event listeners
        this.input.addEventListener('input', (e) => this.handleInput(e));
        this.input.addEventListener('keydown', (e) => this.handleKeydown(e));
        this.input.addEventListener('focus', (e) => this.handleFocus(e));

        // Close suggestions on outside click
        document.addEventListener('click', (e) => {
            if (!this.input.contains(e.target) && !this.suggestionsContainer.contains(e.target)) {
                this.hideSuggestions();
            }
        });
    }

    /**
     * Handle input event
     */
    handleInput(e) {
        const query = e.target.value.trim();

        if (query.length < this.minChars) {
            this.hideSuggestions();
            return;
        }

        this.showLoading();
        this.debouncedSearch(query);
    }

    /**
     * Handle focus event - show suggestions if query exists
     */
    handleFocus(e) {
        const query = e.target.value.trim();
        if (query.length >= this.minChars && this.suggestions.length > 0) {
            this.showSuggestions();
        }
    }

    /**
     * Handle keyboard navigation
     */
    handleKeydown(e) {
        if (!this.suggestionsContainer.classList.contains('show')) {
            return;
        }

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.selectedIndex = Math.min(this.selectedIndex + 1, this.suggestions.length - 1);
                this.updateSelection();
                break;

            case 'ArrowUp':
                e.preventDefault();
                this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                this.updateSelection();
                break;

            case 'Enter':
                e.preventDefault();
                if (this.selectedIndex >= 0 && this.selectedIndex < this.suggestions.length) {
                    this.selectSuggestion(this.suggestions[this.selectedIndex]);
                }
                break;

            case 'Escape':
                e.preventDefault();
                this.hideSuggestions();
                break;
        }
    }

    /**
     * Fetch suggestions from API
     */
    async fetchSuggestions(query) {
        this.isLoading = true;

        try {
            const response = await axios.get(`/api/search/suggest?q=${encodeURIComponent(query)}`);

            this.suggestions = response.data.suggestions || [];
            this.selectedIndex = -1;

            if (this.suggestions.length > 0) {
                this.renderSuggestions();
                this.showSuggestions();
            } else {
                this.renderNoResults();
                this.showSuggestions();
            }
        } catch (error) {
            console.error('Autocomplete failed:', error);
            this.hideSuggestions();
        } finally {
            this.isLoading = false;
        }
    }

    /**
     * Render suggestions
     */
    renderSuggestions() {
        const html = this.suggestions.map((suggestion, index) => `
            <a href="#" class="dropdown-item autocomplete-item ${index === this.selectedIndex ? 'active' : ''}"
               data-index="${index}">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-search me-2 text-muted"></i>
                        <span>${this.escapeHtml(suggestion.text)}</span>
                    </div>
                    ${suggestion.category ? `
                        <span class="badge bg-secondary">${this.escapeHtml(suggestion.category.name || suggestion.category)}</span>
                    ` : ''}
                </div>
            </a>
        `).join('');

        this.suggestionsContainer.innerHTML = html;

        // Attach click handlers
        this.suggestionsContainer.querySelectorAll('.autocomplete-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const index = parseInt(e.currentTarget.dataset.index);
                this.selectSuggestion(this.suggestions[index]);
            });
        });
    }

    /**
     * Render no results message
     */
    renderNoResults() {
        this.suggestionsContainer.innerHTML = `
            <div class="dropdown-item text-muted text-center py-2">
                <small>No suggestions found</small>
            </div>
        `;
    }

    /**
     * Show loading state
     */
    showLoading() {
        this.suggestionsContainer.innerHTML = `
            <div class="dropdown-item text-center py-2">
                <div class="spinner-border spinner-border-sm text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
        this.showSuggestions();
    }

    /**
     * Update visual selection
     */
    updateSelection() {
        const items = this.suggestionsContainer.querySelectorAll('.autocomplete-item');
        items.forEach((item, index) => {
            if (index === this.selectedIndex) {
                item.classList.add('active');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('active');
            }
        });
    }

    /**
     * Select a suggestion
     */
    selectSuggestion(suggestion) {
        // Update input value
        this.input.value = suggestion.text;

        // Hide suggestions
        this.hideSuggestions();

        // Trigger callback if set
        if (this.onSelectCallback) {
            this.onSelectCallback(suggestion);
        }

        // Trigger input event to perform search
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /**
     * Show suggestions dropdown
     */
    showSuggestions() {
        this.suggestionsContainer.classList.add('show');
    }

    /**
     * Hide suggestions dropdown
     */
    hideSuggestions() {
        this.suggestionsContainer.classList.remove('show');
        this.selectedIndex = -1;
    }

    /**
     * Set callback for when suggestion is selected
     */
    onSelect(callback) {
        this.onSelectCallback = callback;
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Export for use in other modules
export default SearchAutocomplete;
