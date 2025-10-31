/**
 * Search History Component
 *
 * Handles:
 * - Loading recent search history
 * - Displaying search history dropdown
 * - Re-executing past searches
 * - Clearing search history
 */

import axios from 'axios';
import { showNotification, formatDateTime } from './utils';

class SearchHistoryManager {
    constructor() {
        this.history = [];
        this.onExecuteCallback = null;
        this.limit = 20; // Default limit

        this.init();
    }

    /**
     * Initialize the search history manager
     */
    init() {
        document.addEventListener('DOMContentLoaded', () => {
            this.bindEvents();
            this.loadHistory();
        });
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        // History dropdown toggle
        const historyToggle = document.getElementById('search-history-toggle');
        if (historyToggle) {
            historyToggle.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleHistoryDropdown();
            });
        }

        // Clear history button
        const clearHistoryBtn = document.getElementById('clear-history-btn');
        if (clearHistoryBtn) {
            clearHistoryBtn.addEventListener('click', () => this.clearHistory());
        }

        // Refresh history button
        const refreshHistoryBtn = document.getElementById('refresh-history-btn');
        if (refreshHistoryBtn) {
            refreshHistoryBtn.addEventListener('click', () => this.loadHistory());
        }
    }

    /**
     * Load search history from API
     */
    async loadHistory(limit = this.limit) {
        try {
            const response = await axios.get(`/api/saved-searches/history?limit=${limit}`);
            this.history = response.data;
            this.renderHistory();
        } catch (error) {
            console.error('Failed to load search history:', error);
            // Don't show error notification for history load - it's not critical
        }
    }

    /**
     * Render search history
     */
    renderHistory() {
        const container = document.getElementById('search-history-list');
        if (!container) return;

        if (this.history.length === 0) {
            container.innerHTML = `
                <div class="dropdown-item text-center text-muted py-3">
                    <i class="bi bi-clock-history"></i>
                    <p class="mb-0 small">No search history</p>
                </div>
            `;
            return;
        }

        const html = this.history.map(entry => `
            <a href="#" class="dropdown-item history-item py-2" data-entry-id="${entry.id}">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="fw-semibold">${this.escapeHtml(entry.query)}</div>
                        ${this.renderFilters(entry.filters)}
                        <small class="text-muted">
                            ${entry.resultCount} results • ${formatDateTime(entry.createdAt)}
                        </small>
                    </div>
                    <i class="bi bi-arrow-repeat text-muted ms-2"></i>
                </div>
            </a>
        `).join('');

        container.innerHTML = html + `
            <div class="dropdown-divider"></div>
            <div class="dropdown-item text-center">
                <button id="clear-history-btn" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash"></i> Clear History
                </button>
            </div>
        `;

        // Bind click events
        container.querySelectorAll('.history-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const entryId = e.currentTarget.dataset.entryId;
                const entry = this.history.find(h => h.id === entryId);
                if (entry) {
                    this.executeHistoryEntry(entry);
                }
            });
        });

        // Re-bind clear button (it was just recreated)
        const clearBtn = document.getElementById('clear-history-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => this.clearHistory());
        }

        // Update badge count
        this.updateHistoryBadge();
    }

    /**
     * Render filters for a history entry
     */
    renderFilters(filters) {
        if (!filters || Object.keys(filters).length === 0) {
            return '';
        }

        const filterParts = [];

        if (filters.category) {
            filterParts.push(`<span class="badge bg-secondary">${this.escapeHtml(filters.category)}</span>`);
        }

        if (filters.dateFrom || filters.dateTo) {
            const dateRange = [filters.dateFrom, filters.dateTo].filter(Boolean).join(' - ');
            filterParts.push(`<span class="badge bg-info">${dateRange}</span>`);
        }

        if (filters.tags && filters.tags.length > 0) {
            filterParts.push(`<span class="badge bg-success">${filters.tags.length} tags</span>`);
        }

        return filterParts.length > 0
            ? `<div class="mb-1">${filterParts.join(' ')}</div>`
            : '';
    }

    /**
     * Toggle history dropdown visibility
     */
    toggleHistoryDropdown() {
        const dropdown = document.getElementById('search-history-dropdown');
        if (dropdown) {
            dropdown.classList.toggle('show');
        }

        // Reload history when opening
        if (dropdown && dropdown.classList.contains('show')) {
            this.loadHistory();
        }
    }

    /**
     * Execute a history entry (re-run the search)
     */
    async executeHistoryEntry(entry) {
        // Close dropdown
        const dropdown = document.getElementById('search-history-dropdown');
        if (dropdown) {
            dropdown.classList.remove('show');
        }

        // Populate search form
        this.populateSearchForm(entry);

        // Trigger search if callback is set
        if (this.onExecuteCallback) {
            this.onExecuteCallback(entry);
        }

        showNotification('Search loaded from history', 'info');
    }

    /**
     * Populate search form with history entry
     */
    populateSearchForm(entry) {
        // Set query
        const searchInput = document.getElementById('search-query');
        if (searchInput) {
            searchInput.value = entry.query;
        }

        // Set filters
        const filters = entry.filters || {};

        if (filters.category) {
            const categorySelect = document.getElementById('category-filter');
            if (categorySelect) {
                categorySelect.value = filters.category;
            }
        }

        if (filters.dateFrom) {
            const dateFromInput = document.getElementById('date-from');
            if (dateFromInput) {
                dateFromInput.value = filters.dateFrom;
            }
        }

        if (filters.dateTo) {
            const dateToInput = document.getElementById('date-to');
            if (dateToInput) {
                dateToInput.value = filters.dateTo;
            }
        }

        // Trigger search form submit programmatically
        const searchForm = document.getElementById('search-form');
        if (searchForm) {
            searchForm.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    }

    /**
     * Clear all search history
     */
    async clearHistory() {
        if (!confirm('Are you sure you want to clear your search history?')) {
            return;
        }

        try {
            await axios.delete('/api/saved-searches/history/clear');

            this.history = [];
            this.renderHistory();

            showNotification('Search history cleared', 'success');
        } catch (error) {
            console.error('Failed to clear history:', error);
            showNotification('Failed to clear history', 'error');
        }
    }

    /**
     * Update history badge count
     */
    updateHistoryBadge() {
        const badge = document.getElementById('history-count-badge');
        if (badge) {
            if (this.history.length > 0) {
                badge.textContent = this.history.length;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
            }
        }
    }

    /**
     * Set callback for when history entry is executed
     */
    onExecute(callback) {
        this.onExecuteCallback = callback;
    }

    /**
     * Record a new search in history (called after executing a search)
     * Note: This is typically handled by the backend, but we can
     * optimistically add it to the local cache
     */
    addToHistory(query, filters, resultCount) {
        const newEntry = {
            id: `temp-${Date.now()}`,
            query,
            filters,
            resultCount,
            createdAt: new Date().toISOString()
        };

        // Add to beginning of array
        this.history.unshift(newEntry);

        // Trim to limit
        if (this.history.length > this.limit) {
            this.history = this.history.slice(0, this.limit);
        }

        this.renderHistory();
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Export singleton instance
export default new SearchHistoryManager();
