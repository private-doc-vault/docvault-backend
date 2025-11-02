/**
 * DocVault Search JavaScript
 * Handles advanced document search with filters and results display
 */

import axios from 'axios';
import 'bootstrap';
import savedSearches from './saved-searches';
import searchHistory from './search-history';
import SearchAutocomplete from './search-autocomplete';

class DocumentSearch {
    constructor() {
        this.results = [];
        this.currentPage = 1;
        this.totalPages = 1;
        this.limit = 20;
        this.searchQuery = '';
        this.filters = {
            categoryId: null,
            tags: [],
            dateFrom: null,
            dateTo: null,
            language: null,
            minConfidence: null,
            fileSizeMin: null,
            fileSizeMax: null
        };
        this.init();
    }

    async init() {
        // Get search query from URL if present
        const urlParams = new URLSearchParams(window.location.search);
        this.searchQuery = urlParams.get('q') || '';

        this.setupEventListeners();
        await this.loadCategories();
        await this.loadTags();

        // Set initial query in input
        const searchInput = document.getElementById('search-query');
        if (searchInput) {
            searchInput.value = this.searchQuery;
        }

        // Perform initial search if query exists
        if (this.searchQuery) {
            await this.performSearch();
        }

        // Set up callbacks for saved searches and history
        savedSearches.onExecute((results) => {
            this.results = results.hits || results;
            this.renderResults();
        });

        searchHistory.onExecute((entry) => {
            this.performSearch();
        });

        // Initialize autocomplete
        this.autocomplete = new SearchAutocomplete('#search-query', '#search-autocomplete-suggestions');
        this.autocomplete.onSelect((suggestion) => {
            // When a suggestion is selected, trigger search
            this.searchQuery = suggestion.text;
            this.currentPage = 1;
            this.performSearch();
        });

        // Bind export links
        document.querySelectorAll('.export-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const format = e.currentTarget.dataset.format;
                this.exportResults(format);
            });
        });
    }

    setupEventListeners() {
        // Search button
        const searchBtn = document.getElementById('search-btn');
        const searchInput = document.getElementById('search-query');

        if (searchBtn && searchInput) {
            const doSearch = () => {
                this.searchQuery = searchInput.value.trim();
                this.currentPage = 1;
                this.performSearch();
                this.updateUrlParams();
            };

            searchBtn.addEventListener('click', doSearch);
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    doSearch();
                }
            });
        }

        // Clear search
        const clearBtn = document.getElementById('clear-search-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                this.clearSearch();
            });
        }

        // Category filter
        const categoryFilter = document.getElementById('category-filter');
        if (categoryFilter) {
            categoryFilter.addEventListener('change', (e) => {
                this.filters.categoryId = e.target.value || null;
                this.currentPage = 1;
                this.performSearch();
            });
        }

        // Tag filters
        const tagContainer = document.getElementById('tag-filter');
        if (tagContainer) {
            tagContainer.addEventListener('change', (e) => {
                if (e.target.classList.contains('tag-filter-checkbox')) {
                    const checkboxes = document.querySelectorAll('.tag-filter-checkbox:checked');
                    this.filters.tags = Array.from(checkboxes).map(cb => cb.value);
                    this.currentPage = 1;
                    this.performSearch();
                }
            });
        }

        // Date filters
        const dateFromInput = document.getElementById('date-from');
        const dateToInput = document.getElementById('date-to');

        if (dateFromInput) {
            dateFromInput.addEventListener('change', (e) => {
                this.filters.dateFrom = e.target.value || null;
                this.currentPage = 1;
                this.performSearch();
            });
        }

        if (dateToInput) {
            dateToInput.addEventListener('change', (e) => {
                this.filters.dateTo = e.target.value || null;
                this.currentPage = 1;
                this.performSearch();
            });
        }

        // Language filter
        const languageFilter = document.getElementById('language-filter');
        if (languageFilter) {
            languageFilter.addEventListener('change', (e) => {
                this.filters.language = e.target.value || null;
                this.currentPage = 1;
                this.performSearch();
            });
        }

        // OCR confidence filter
        const confidenceFilter = document.getElementById('confidence-filter');
        const confidenceValue = document.getElementById('confidence-value');
        if (confidenceFilter && confidenceValue) {
            confidenceFilter.addEventListener('input', (e) => {
                const value = e.target.value;
                confidenceValue.textContent = value + '%';
                this.filters.minConfidence = parseInt(value) / 100; // Convert to 0-1 range
            });

            confidenceFilter.addEventListener('change', () => {
                this.currentPage = 1;
                this.performSearch();
            });
        }

        // File size filters
        const fileSizeMin = document.getElementById('file-size-min');
        const fileSizeMax = document.getElementById('file-size-max');

        if (fileSizeMin) {
            fileSizeMin.addEventListener('change', (e) => {
                const value = e.target.value;
                this.filters.fileSizeMin = value ? parseInt(value) * 1024 : null; // Convert KB to bytes
                this.currentPage = 1;
                this.performSearch();
            });
        }

        if (fileSizeMax) {
            fileSizeMax.addEventListener('change', (e) => {
                const value = e.target.value;
                this.filters.fileSizeMax = value ? parseInt(value) * 1024 * 1024 : null; // Convert MB to bytes
                this.currentPage = 1;
                this.performSearch();
            });
        }

        // Advanced search toggle
        const advancedToggle = document.getElementById('advanced-search-toggle');
        const advancedPanel = document.getElementById('advanced-search-panel');

        if (advancedToggle && advancedPanel) {
            advancedToggle.addEventListener('click', () => {
                advancedPanel.classList.toggle('d-none');
                const icon = advancedToggle.querySelector('i');
                if (icon) {
                    icon.classList.toggle('bi-chevron-down');
                    icon.classList.toggle('bi-chevron-up');
                }
            });
        }
    }

    async loadCategories() {
        try {
            const response = await axios.get('/api/categories', {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            const categories = response.data.categories || response.data;
            this.renderCategoryFilter(categories);
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
    }

    async loadTags() {
        try {
            const response = await axios.get('/api/tags', {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            const tags = response.data.tags || response.data;
            this.renderTagFilter(tags);
        } catch (error) {
            console.error('Failed to load tags:', error);
        }
    }

    renderCategoryFilter(categories) {
        const select = document.getElementById('category-filter');
        if (!select) return;

        select.innerHTML = '<option value="">All Categories</option>';
        categories.forEach(category => {
            const option = document.createElement('option');
            option.value = category.id;
            option.textContent = category.name;
            select.appendChild(option);
        });
    }

    renderTagFilter(tags) {
        const container = document.getElementById('tag-filter');
        if (!container) return;

        container.innerHTML = tags.map(tag => `
            <div class="form-check">
                <input class="form-check-input tag-filter-checkbox" type="checkbox"
                       value="${tag.id}" id="tag-filter-${tag.id}">
                <label class="form-check-label" for="tag-filter-${tag.id}">
                    ${this.escapeHtml(tag.name)} <span class="text-muted small">(${tag.usageCount || 0})</span>
                </label>
            </div>
        `).join('');
    }

    async performSearch() {
        const resultsContainer = document.getElementById('search-results');
        if (!resultsContainer) return;

        // Show loading state
        resultsContainer.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-3">Searching documents...</p>
            </div>
        `;

        try {
            const params = new URLSearchParams({
                q: this.searchQuery || '',
                limit: this.limit,
                offset: (this.currentPage - 1) * this.limit,
                highlight: 'true'
            });

            if (this.filters.categoryId) {
                params.append('category', this.filters.categoryId);
            }

            if (this.filters.tags.length > 0) {
                this.filters.tags.forEach(tag => params.append('tags[]', tag));
            }

            if (this.filters.dateFrom) {
                params.append('dateFrom', this.filters.dateFrom);
            }

            if (this.filters.dateTo) {
                params.append('dateTo', this.filters.dateTo);
            }

            if (this.filters.language) {
                params.append('language', this.filters.language);
            }

            if (this.filters.minConfidence !== null) {
                params.append('minConfidence', this.filters.minConfidence.toString());
            }

            if (this.filters.fileSizeMin) {
                params.append('fileSizeMin', this.filters.fileSizeMin.toString());
            }

            if (this.filters.fileSizeMax) {
                params.append('fileSizeMax', this.filters.fileSizeMax.toString());
            }

            const response = await axios.get(`/api/search/meilisearch?${params}`, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            // Meilisearch response format
            this.results = response.data.hits || [];
            this.totalResults = response.data.estimatedTotalHits || 0;
            this.totalPages = Math.ceil(this.totalResults / this.limit);
            this.processingTime = response.data.processingTimeMs || 0;

            this.renderResults();
            this.renderPagination();
            this.updateResultsCount(this.totalResults);
            this.showSearchTime(this.processingTime);
            this.showExportButton();
        } catch (error) {
            console.error('Search failed:', error);
            resultsContainer.innerHTML = `
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    Failed to perform search. Please try again.
                    ${error.response?.data?.message ? `<br><small>${error.response.data.message}</small>` : ''}
                </div>
            `;
        }
    }

    renderResults() {
        const container = document.getElementById('search-results');
        if (!container) return;

        if (this.results.length === 0) {
            container.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-search fs-1 text-muted"></i>
                    <h5 class="mt-3">No results found</h5>
                    <p class="text-muted">Try adjusting your search query or filters</p>
                </div>
            `;
            return;
        }

        container.innerHTML = this.results.map(doc => {
            // Use Meilisearch's _formatted fields for better highlighting
            const formatted = doc._formatted || {};
            const filename = formatted.originalName || formatted.filename || doc.originalName || doc.filename;
            const excerpt = formatted.excerpt || doc.excerpt;
            const ocrText = formatted.ocrText || doc.ocrText;

            return `
            <div class="card mb-3 search-result-card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-auto">
                            <i class="bi bi-file-earmark-${this.getFileIcon(doc.mimeType)} fs-1 text-primary"></i>
                        </div>
                        <div class="col">
                            <h5 class="card-title">
                                <a href="/documents/${doc.id}" class="text-decoration-none">
                                    ${filename || 'Untitled'}
                                </a>
                            </h5>

                            <div class="mb-2">
                                ${doc.category ? `<span class="badge bg-secondary me-1">${this.escapeHtml(doc.category.name || doc.category)}</span>` : ''}
                                ${doc.tags && doc.tags.length > 0 ? doc.tags.map(tag => `
                                    <span class="badge bg-info text-dark me-1">${this.escapeHtml(tag.name || tag)}</span>
                                `).join('') : ''}
                            </div>

                            ${excerpt ? `
                                <p class="card-text text-muted small mb-2">
                                    ${excerpt}
                                </p>
                            ` : ''}

                            ${ocrText && ocrText.substring(0, 200) ? `
                                <p class="card-text small">
                                    <strong>Extracted text:</strong>
                                    ${ocrText.substring(0, 200)}...
                                </p>
                            ` : ''}

                            <div class="d-flex gap-3 text-muted small">
                                <span><i class="bi bi-calendar"></i> ${this.formatDate(doc.createdAt)}</span>
                                <span><i class="bi bi-hdd"></i> ${this.formatBytes(doc.fileSize)}</span>
                                ${doc.owner ? `<span><i class="bi bi-person"></i> ${this.escapeHtml(doc.owner.firstName || doc.owner.email || doc.owner)}</span>` : ''}
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="btn-group-vertical" role="group">
                                <a href="/documents/${doc.id}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i> View
                                </a>
                                <button class="btn btn-sm btn-outline-secondary download-btn" data-id="${doc.id}">
                                    <i class="bi bi-download"></i> Download
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            `;
        }).join('');

        // Attach download listeners
        document.querySelectorAll('.download-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = e.currentTarget.dataset.id;
                this.downloadDocument(id);
            });
        });
    }

    renderPagination() {
        const container = document.getElementById('pagination-container');
        if (!container || this.totalPages <= 1) {
            if (container) container.innerHTML = '';
            return;
        }

        const pages = [];
        const maxVisible = 5;

        // Previous button
        pages.push(`
            <li class="page-item ${this.currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${this.currentPage - 1}">Previous</a>
            </li>
        `);

        // Page numbers
        let startPage = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(this.totalPages, startPage + maxVisible - 1);

        if (endPage - startPage < maxVisible - 1) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }

        if (startPage > 1) {
            pages.push(`<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`);
            if (startPage > 2) {
                pages.push(`<li class="page-item disabled"><span class="page-link">...</span></li>`);
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            pages.push(`
                <li class="page-item ${i === this.currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>
            `);
        }

        if (endPage < this.totalPages) {
            if (endPage < this.totalPages - 1) {
                pages.push(`<li class="page-item disabled"><span class="page-link">...</span></li>`);
            }
            pages.push(`<li class="page-item"><a class="page-link" href="#" data-page="${this.totalPages}">${this.totalPages}</a></li>`);
        }

        // Next button
        pages.push(`
            <li class="page-item ${this.currentPage === this.totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${this.currentPage + 1}">Next</a>
            </li>
        `);

        container.innerHTML = `<ul class="pagination justify-content-center mb-0">${pages.join('')}</ul>`;

        // Attach pagination event listeners
        container.querySelectorAll('.page-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = parseInt(e.target.dataset.page);
                if (page && page !== this.currentPage && page >= 1 && page <= this.totalPages) {
                    this.currentPage = page;
                    this.performSearch();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        });
    }

    updateResultsCount(total) {
        const element = document.getElementById('results-count');
        if (element) {
            const start = (this.currentPage - 1) * this.limit + 1;
            const end = Math.min(this.currentPage * this.limit, total);

            if (total > 0) {
                element.innerHTML = `
                    Found <strong>${total}</strong> document${total !== 1 ? 's' : ''}
                    ${this.searchQuery ? ` matching "<strong>${this.escapeHtml(this.searchQuery)}</strong>"` : ''}
                    <span class="text-muted">(showing ${start}-${end})</span>
                `;
            } else {
                element.textContent = 'No results found';
            }
        }
    }

    showSearchTime(timeMs) {
        const element = document.getElementById('search-time');
        if (element && timeMs !== undefined) {
            element.innerHTML = `
                <small class="text-muted">
                    <i class="bi bi-lightning-charge"></i> ${timeMs}ms
                    <span class="badge bg-light text-dark ms-1">Powered by Meilisearch</span>
                </small>
            `;
            element.classList.remove('d-none');
        }
    }

    /**
     * Show export button when results are available
     */
    showExportButton() {
        const exportActions = document.getElementById('export-actions');
        if (exportActions && this.totalResults > 0) {
            exportActions.classList.remove('d-none');
        } else if (exportActions) {
            exportActions.classList.add('d-none');
        }
    }

    /**
     * Export search results
     */
    async exportResults(format) {
        // Build export URL with current search parameters
        const params = new URLSearchParams({
            q: this.searchQuery || '',
            format: format
        });

        if (this.filters.categoryId) {
            params.append('category', this.filters.categoryId);
        }

        if (this.filters.tags.length > 0) {
            this.filters.tags.forEach(tag => params.append('tags[]', tag));
        }

        if (this.filters.dateFrom) {
            params.append('dateFrom', this.filters.dateFrom);
        }

        if (this.filters.dateTo) {
            params.append('dateTo', this.filters.dateTo);
        }

        if (this.filters.language) {
            params.append('language', this.filters.language);
        }

        if (this.filters.minConfidence !== null) {
            params.append('minConfidence', this.filters.minConfidence.toString());
        }

        if (this.filters.fileSizeMin) {
            params.append('fileSizeMin', this.filters.fileSizeMin.toString());
        }

        if (this.filters.fileSizeMax) {
            params.append('fileSizeMax', this.filters.fileSizeMax.toString());
        }

        // Create download link
        const exportUrl = `/api/search/export?${params}`;

        // Trigger download
        const link = document.createElement('a');
        link.href = exportUrl;
        link.download = `search-results.${format}`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Show notification
        this.showNotification(`Exporting results as ${format.toUpperCase()}...`, 'info');
    }

    /**
     * Show notification
     */
    showNotification(message, type = 'info') {
        const container = document.getElementById('notification-container');
        if (!container) {
            console.log(message);
            return;
        }

        const alertClass = {
            'success': 'alert-success',
            'error': 'alert-danger',
            'warning': 'alert-warning',
            'info': 'alert-info'
        }[type] || 'alert-info';

        const alert = document.createElement('div');
        alert.className = `alert ${alertClass} alert-dismissible fade show`;
        alert.setAttribute('role', 'alert');
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        container.appendChild(alert);

        setTimeout(() => {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 150);
        }, 3000);
    }

    updateUrlParams() {
        const url = new URL(window.location);
        if (this.searchQuery) {
            url.searchParams.set('q', this.searchQuery);
        } else {
            url.searchParams.delete('q');
        }
        window.history.pushState({}, '', url);
    }

    clearSearch() {
        // Clear inputs
        const searchInput = document.getElementById('search-query');
        const categoryFilter = document.getElementById('category-filter');
        const dateFromInput = document.getElementById('date-from');
        const dateToInput = document.getElementById('date-to');
        const languageFilter = document.getElementById('language-filter');
        const confidenceFilter = document.getElementById('confidence-filter');
        const confidenceValue = document.getElementById('confidence-value');
        const fileSizeMin = document.getElementById('file-size-min');
        const fileSizeMax = document.getElementById('file-size-max');

        if (searchInput) searchInput.value = '';
        if (categoryFilter) categoryFilter.value = '';
        if (dateFromInput) dateFromInput.value = '';
        if (dateToInput) dateToInput.value = '';
        if (languageFilter) languageFilter.value = '';
        if (confidenceFilter) {
            confidenceFilter.value = 70;
            if (confidenceValue) confidenceValue.textContent = '70%';
        }
        if (fileSizeMin) fileSizeMin.value = '';
        if (fileSizeMax) fileSizeMax.value = '';

        // Clear tag checkboxes
        document.querySelectorAll('.tag-filter-checkbox').forEach(cb => cb.checked = false);

        // Reset state
        this.searchQuery = '';
        this.filters = {
            categoryId: null,
            tags: [],
            dateFrom: null,
            dateTo: null,
            language: null,
            minConfidence: null,
            fileSizeMin: null,
            fileSizeMax: null
        };
        this.currentPage = 1;

        // Clear URL params
        window.history.pushState({}, '', window.location.pathname);

        // Clear results
        const resultsContainer = document.getElementById('search-results');
        if (resultsContainer) {
            resultsContainer.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-search fs-1 text-muted"></i>
                    <p class="text-muted mt-3">Enter a search query to find documents</p>
                </div>
            `;
        }

        const resultsCount = document.getElementById('results-count');
        if (resultsCount) {
            resultsCount.textContent = '';
        }
    }

    async downloadDocument(id) {
        try {
            const response = await axios.get(`/api/documents/${id}/download`, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                },
                responseType: 'blob'
            });

            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', response.headers['content-disposition']?.split('filename=')[1] || `document-${id}`);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
        } catch (error) {
            console.error('Download failed:', error);
            this.showError('Failed to download document');
        }
    }

    highlightText(text, query) {
        if (!query || !text) return text;

        const words = query.split(/\s+/).filter(w => w.length > 0);
        let highlighted = text;

        words.forEach(word => {
            const regex = new RegExp(`(${this.escapeRegex(word)})`, 'gi');
            highlighted = highlighted.replace(regex, '<mark>$1</mark>');
        });

        return highlighted;
    }

    escapeRegex(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    getFileIcon(mimeType) {
        if (!mimeType) return 'text';
        if (mimeType.includes('pdf')) return 'pdf';
        if (mimeType.includes('image')) return 'image';
        if (mimeType.includes('word')) return 'word';
        if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) return 'excel';
        if (mimeType.includes('text')) return 'text';
        return 'file';
    }

    formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    getAuthToken() {
        return localStorage.getItem('auth_token') ||
               sessionStorage.getItem('auth_token') ||
               document.querySelector('meta[name="jwt-token"]')?.content || '';
    }

    showError(message) {
        const container = document.getElementById('notification-container');
        if (!container) {
            console.error(message);
            return;
        }

        const alert = document.createElement('div');
        alert.className = 'alert alert-danger alert-dismissible fade show';
        alert.setAttribute('role', 'alert');
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        container.appendChild(alert);

        setTimeout(() => {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 150);
        }, 5000);
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    new DocumentSearch();
});
