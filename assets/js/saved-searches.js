/**
 * Saved Searches Management Component
 *
 * Handles:
 * - Listing saved searches
 * - Creating new saved searches
 * - Executing saved searches
 * - Editing saved searches
 * - Deleting saved searches
 * - Public/private toggle
 */

import axios from 'axios';
import { showNotification } from './utils';

class SavedSearchManager {
    constructor() {
        this.savedSearches = [];
        this.currentSearch = null;
        this.onExecuteCallback = null;

        this.init();
    }

    /**
     * Initialize the saved searches manager
     */
    init() {
        // Bind event listeners
        document.addEventListener('DOMContentLoaded', () => {
            this.bindEvents();
            this.loadSavedSearches();
        });
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        // Save current search button
        const saveSearchBtn = document.getElementById('save-search-btn');
        if (saveSearchBtn) {
            saveSearchBtn.addEventListener('click', () => this.showSaveDialog());
        }

        // Saved searches dropdown toggle
        const savedSearchesToggle = document.getElementById('saved-searches-toggle');
        if (savedSearchesToggle) {
            savedSearchesToggle.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleSavedSearchesPanel();
            });
        }

        // Save dialog submit
        const saveDialogSubmit = document.getElementById('save-search-submit');
        if (saveDialogSubmit) {
            saveDialogSubmit.addEventListener('click', () => this.saveCurrentSearch());
        }

        // Edit dialog submit
        const editDialogSubmit = document.getElementById('edit-search-submit');
        if (editDialogSubmit) {
            editDialogSubmit.addEventListener('click', () => this.updateSavedSearch());
        }
    }

    /**
     * Load all saved searches from API
     */
    async loadSavedSearches() {
        try {
            const response = await axios.get('/api/saved-searches');
            this.savedSearches = response.data;
            this.renderSavedSearchesList();
        } catch (error) {
            console.error('Failed to load saved searches:', error);
            showNotification('Failed to load saved searches', 'error');
        }
    }

    /**
     * Render saved searches list
     */
    renderSavedSearchesList() {
        const container = document.getElementById('saved-searches-list');
        if (!container) return;

        if (this.savedSearches.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="bi bi-bookmark"></i>
                    <p>No saved searches yet</p>
                </div>
            `;
            return;
        }

        const html = this.savedSearches.map(search => `
            <div class="saved-search-item mb-2" data-search-id="${search.id}">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2">
                            <a href="#" class="saved-search-link fw-semibold text-decoration-none"
                               data-search-id="${search.id}">
                                ${this.escapeHtml(search.name)}
                            </a>
                            ${search.isPublic ? '<span class="badge bg-info text-white">Public</span>' : ''}
                            ${!search.isOwner ? '<span class="badge bg-secondary">Shared</span>' : ''}
                        </div>
                        <small class="text-muted d-block">
                            ${this.escapeHtml(search.query)}
                            ${search.usageCount > 0 ? `• Used ${search.usageCount} times` : ''}
                        </small>
                        ${search.description ? `<small class="text-muted">${this.escapeHtml(search.description)}</small>` : ''}
                    </div>
                    ${search.isOwner ? `
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary edit-search-btn"
                                    data-search-id="${search.id}"
                                    title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-outline-danger delete-search-btn"
                                    data-search-id="${search.id}"
                                    title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    ` : ''}
                </div>
            </div>
        `).join('');

        container.innerHTML = html;

        // Bind click events
        container.querySelectorAll('.saved-search-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const searchId = e.currentTarget.dataset.searchId;
                this.executeSavedSearch(searchId);
            });
        });

        container.querySelectorAll('.edit-search-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const searchId = e.currentTarget.dataset.searchId;
                this.showEditDialog(searchId);
            });
        });

        container.querySelectorAll('.delete-search-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const searchId = e.currentTarget.dataset.searchId;
                this.deleteSavedSearch(searchId);
            });
        });
    }

    /**
     * Toggle saved searches panel visibility
     */
    toggleSavedSearchesPanel() {
        const panel = document.getElementById('saved-searches-panel');
        if (panel) {
            panel.classList.toggle('d-none');
        }
    }

    /**
     * Show save dialog for current search
     */
    showSaveDialog() {
        const modal = new bootstrap.Modal(document.getElementById('save-search-modal'));

        // Pre-fill with current search query if available
        const searchInput = document.getElementById('search-query');
        if (searchInput) {
            document.getElementById('save-search-query').value = searchInput.value;
        }

        modal.show();
    }

    /**
     * Save current search
     */
    async saveCurrentSearch() {
        const name = document.getElementById('save-search-name').value.trim();
        const query = document.getElementById('save-search-query').value.trim();
        const description = document.getElementById('save-search-description').value.trim();
        const isPublic = document.getElementById('save-search-public').checked;

        if (!name || !query) {
            showNotification('Name and query are required', 'error');
            return;
        }

        // Gather current filters
        const filters = this.getCurrentFilters();

        try {
            const response = await axios.post('/api/saved-searches', {
                name,
                query,
                description: description || null,
                isPublic,
                filters
            });

            showNotification('Search saved successfully', 'success');

            // Hide modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('save-search-modal'));
            modal.hide();

            // Reset form
            document.getElementById('save-search-form').reset();

            // Reload list
            await this.loadSavedSearches();
        } catch (error) {
            console.error('Failed to save search:', error);
            const errorMsg = error.response?.data?.error || 'Failed to save search';
            showNotification(errorMsg, 'error');
        }
    }

    /**
     * Get current filter values from search form
     */
    getCurrentFilters() {
        const filters = {};

        const categorySelect = document.getElementById('category-filter');
        if (categorySelect && categorySelect.value) {
            filters.category = categorySelect.value;
        }

        const dateFromInput = document.getElementById('date-from');
        if (dateFromInput && dateFromInput.value) {
            filters.dateFrom = dateFromInput.value;
        }

        const dateToInput = document.getElementById('date-to');
        if (dateToInput && dateToInput.value) {
            filters.dateTo = dateToInput.value;
        }

        // Add any other filter fields here

        return filters;
    }

    /**
     * Execute a saved search
     */
    async executeSavedSearch(searchId) {
        try {
            const response = await axios.get(`/api/saved-searches/${searchId}/execute`);

            // Update UI with search results
            if (this.onExecuteCallback) {
                this.onExecuteCallback(response.data.results);
            }

            // Update search form with saved search parameters
            const savedSearch = this.savedSearches.find(s => s.id === searchId);
            if (savedSearch) {
                this.populateSearchForm(savedSearch);
            }

            showNotification('Saved search executed', 'success');

            // Close panel
            this.toggleSavedSearchesPanel();
        } catch (error) {
            console.error('Failed to execute saved search:', error);
            showNotification('Failed to execute search', 'error');
        }
    }

    /**
     * Populate search form with saved search parameters
     */
    populateSearchForm(savedSearch) {
        // Set query
        const searchInput = document.getElementById('search-query');
        if (searchInput) {
            searchInput.value = savedSearch.query;
        }

        // Set filters
        const filters = savedSearch.filters || {};

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
    }

    /**
     * Show edit dialog for saved search
     */
    async showEditDialog(searchId) {
        const savedSearch = this.savedSearches.find(s => s.id === searchId);
        if (!savedSearch) return;

        this.currentSearch = savedSearch;

        // Populate edit form
        document.getElementById('edit-search-id').value = savedSearch.id;
        document.getElementById('edit-search-name').value = savedSearch.name;
        document.getElementById('edit-search-query').value = savedSearch.query;
        document.getElementById('edit-search-description').value = savedSearch.description || '';
        document.getElementById('edit-search-public').checked = savedSearch.isPublic;

        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('edit-search-modal'));
        modal.show();
    }

    /**
     * Update saved search
     */
    async updateSavedSearch() {
        const searchId = document.getElementById('edit-search-id').value;
        const name = document.getElementById('edit-search-name').value.trim();
        const query = document.getElementById('edit-search-query').value.trim();
        const description = document.getElementById('edit-search-description').value.trim();
        const isPublic = document.getElementById('edit-search-public').checked;

        if (!name || !query) {
            showNotification('Name and query are required', 'error');
            return;
        }

        try {
            await axios.put(`/api/saved-searches/${searchId}`, {
                name,
                query,
                description: description || null,
                isPublic
            });

            showNotification('Search updated successfully', 'success');

            // Hide modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('edit-search-modal'));
            modal.hide();

            // Reload list
            await this.loadSavedSearches();
        } catch (error) {
            console.error('Failed to update search:', error);
            const errorMsg = error.response?.data?.error || 'Failed to update search';
            showNotification(errorMsg, 'error');
        }
    }

    /**
     * Delete saved search
     */
    async deleteSavedSearch(searchId) {
        if (!confirm('Are you sure you want to delete this saved search?')) {
            return;
        }

        try {
            await axios.delete(`/api/saved-searches/${searchId}`);
            showNotification('Search deleted successfully', 'success');

            // Reload list
            await this.loadSavedSearches();
        } catch (error) {
            console.error('Failed to delete search:', error);
            showNotification('Failed to delete search', 'error');
        }
    }

    /**
     * Set callback for when search is executed
     */
    onExecute(callback) {
        this.onExecuteCallback = callback;
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
export default new SavedSearchManager();
