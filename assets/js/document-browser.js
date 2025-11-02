/**
 * DocVault Document Browser JavaScript
 * Handles document browsing with grid/list views
 */

import axios from 'axios';
import 'bootstrap';

class DocumentBrowser {
    constructor() {
        this.documents = [];
        this.currentPage = 1;
        this.totalPages = 1;
        this.limit = 12;
        this.viewMode = 'grid'; // 'grid' or 'list'
        this.sortBy = 'createdAt';
        this.sortOrder = 'desc';
        this.filters = {
            categoryId: null,
            tags: [],
            search: ''
        };
        this.init();
    }

    async init() {
        this.loadViewPreference();
        this.setupEventListeners();
        await this.loadCategories();
        await this.loadTags();
        await this.loadDocuments();
    }

    loadViewPreference() {
        const savedView = localStorage.getItem('document_view_mode');
        if (savedView) {
            this.viewMode = savedView;
            this.updateViewButtons();
        }
    }

    saveViewPreference() {
        localStorage.setItem('document_view_mode', this.viewMode);
    }

    async loadDocuments() {
        try {
            const params = new URLSearchParams({
                page: this.currentPage,
                limit: this.limit,
                sort: this.sortBy,
                order: this.sortOrder
            });

            if (this.filters.categoryId) {
                params.append('categoryId', this.filters.categoryId);
            }

            if (this.filters.tags.length > 0) {
                this.filters.tags.forEach(tag => params.append('tags[]', tag));
            }

            if (this.filters.search) {
                params.append('search', this.filters.search);
            }

            const response = await axios.get(`/api/documents?${params}`, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            this.documents = response.data.documents || response.data;
            this.totalPages = Math.ceil((response.data.total || this.documents.length) / this.limit);

            this.renderDocuments();
            this.renderPagination();
            this.updateResultsCount(response.data.total || this.documents.length);
        } catch (error) {
            console.error('Failed to load documents:', error);
            this.showError('Failed to load documents. Please try again.');
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

    renderDocuments() {
        const container = document.getElementById('documents-container');
        if (!container) return;

        if (this.documents.length === 0) {
            container.innerHTML = `
                <div class="col-12 text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted"></i>
                    <p class="text-muted mt-3">No documents found</p>
                    <a href="/documents/upload" class="btn btn-primary">
                        <i class="bi bi-upload"></i> Upload Documents
                    </a>
                </div>
            `;
            return;
        }

        if (this.viewMode === 'grid') {
            this.renderGridView(container);
        } else {
            this.renderListView(container);
        }
    }

    renderGridView(container) {
        container.className = 'row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4';
        container.innerHTML = this.documents.map(doc => `
            <div class="col">
                <div class="card document-card h-100" data-document-id="${doc.id}">
                    <div class="card-body">
                        <div class="document-icon text-center mb-3">
                            <i class="bi bi-file-earmark-${this.getFileIcon(doc.mimeType)} fs-1 text-primary"></i>
                        </div>
                        <h6 class="card-title text-truncate" title="${this.escapeHtml(doc.filename || doc.originalName)}">
                            ${this.escapeHtml(doc.filename || doc.originalName)}
                        </h6>
                        <div class="mb-2">
                            ${this.getProcessingStatusBadge(doc.processingStatus)}
                            ${doc.category ? `<span class="badge bg-secondary ms-1">${this.escapeHtml(doc.category.name)}</span>` : ''}
                        </div>
                        <p class="card-text small text-muted mb-1">
                            <i class="bi bi-calendar"></i> ${this.formatDate(doc.createdAt)}
                        </p>
                        <p class="card-text small text-muted mb-2">
                            <i class="bi bi-hdd"></i> ${this.formatBytes(doc.fileSize)}
                        </p>
                        ${doc.tags && doc.tags.length > 0 ? `
                            <div class="mb-2">
                                ${doc.tags.slice(0, 3).map(tag => `
                                    <span class="badge bg-info text-dark me-1 mb-1">${this.escapeHtml(tag.name)}</span>
                                `).join('')}
                                ${doc.tags.length > 3 ? `<span class="badge bg-light text-dark">+${doc.tags.length - 3}</span>` : ''}
                            </div>
                        ` : ''}
                    </div>
                    <div class="card-footer bg-transparent">
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary flex-fill view-btn" data-id="${doc.id}">
                                <i class="bi bi-eye"></i> View
                            </button>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                        data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item download-btn" href="#" data-id="${doc.id}">
                                        <i class="bi bi-download"></i> Download
                                    </a></li>
                                    <li><a class="dropdown-item edit-btn" href="#" data-id="${doc.id}">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item text-danger delete-btn" href="#" data-id="${doc.id}">
                                        <i class="bi bi-trash"></i> Delete
                                    </a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');

        this.attachDocumentEventListeners();
    }

    renderListView(container) {
        container.className = 'list-group';
        container.innerHTML = this.documents.map(doc => `
            <div class="list-group-item list-group-item-action document-list-item" data-document-id="${doc.id}">
                <div class="d-flex w-100 align-items-center">
                    <div class="me-3">
                        <i class="bi bi-file-earmark-${this.getFileIcon(doc.mimeType)} fs-2 text-primary"></i>
                    </div>
                    <div class="flex-fill">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <h6 class="mb-0">${this.escapeHtml(doc.filename || doc.originalName)}</h6>
                            <div class="btn-group btn-group-sm" role="group">
                                ${doc.processingStatus === 'failed' ? `
                                    <button class="btn btn-outline-warning retry-btn" data-id="${doc.id}" title="Retry processing">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </button>
                                ` : ''}
                                <button class="btn btn-outline-primary view-btn" data-id="${doc.id}">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-outline-secondary download-btn" data-id="${doc.id}">
                                    <i class="bi bi-download"></i>
                                </button>
                                <button class="btn btn-outline-danger delete-btn" data-id="${doc.id}">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-2">
                            ${this.getProcessingStatusBadge(doc.processingStatus)}
                            ${doc.category ? `<span class="badge bg-secondary ms-1">${this.escapeHtml(doc.category.name)}</span>` : ''}
                            ${doc.tags && doc.tags.length > 0 ? doc.tags.map(tag => `
                                <span class="badge bg-info text-dark ms-1">${this.escapeHtml(tag.name)}</span>
                            `).join('') : ''}
                        </div>
                        <div class="small text-muted">
                            <span class="me-3"><i class="bi bi-calendar"></i> ${this.formatDate(doc.createdAt)}</span>
                            <span class="me-3"><i class="bi bi-hdd"></i> ${this.formatBytes(doc.fileSize)}</span>
                            ${doc.owner ? `<span><i class="bi bi-person"></i> ${this.escapeHtml(doc.owner.firstName || doc.owner.email)}</span>` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `).join('');

        this.attachDocumentEventListeners();
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
                    this.loadDocuments();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        });
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
                    ${this.escapeHtml(tag.name)} <span class="text-muted">(${tag.usageCount || 0})</span>
                </label>
            </div>
        `).join('');
    }

    updateResultsCount(total) {
        const element = document.getElementById('results-count');
        if (element) {
            const start = (this.currentPage - 1) * this.limit + 1;
            const end = Math.min(this.currentPage * this.limit, total);
            element.textContent = total > 0 ? `Showing ${start}-${end} of ${total} documents` : 'No documents found';
        }
    }

    setupEventListeners() {
        // View mode toggle
        const gridBtn = document.getElementById('grid-view-btn');
        const listBtn = document.getElementById('list-view-btn');

        if (gridBtn) {
            gridBtn.addEventListener('click', () => {
                this.viewMode = 'grid';
                this.saveViewPreference();
                this.updateViewButtons();
                this.renderDocuments();
            });
        }

        if (listBtn) {
            listBtn.addEventListener('click', () => {
                this.viewMode = 'list';
                this.saveViewPreference();
                this.updateViewButtons();
                this.renderDocuments();
            });
        }

        // Sort options
        const sortSelect = document.getElementById('sort-select');
        if (sortSelect) {
            sortSelect.addEventListener('change', (e) => {
                const [sortBy, sortOrder] = e.target.value.split(':');
                this.sortBy = sortBy;
                this.sortOrder = sortOrder;
                this.currentPage = 1;
                this.loadDocuments();
            });
        }

        // Category filter
        const categoryFilter = document.getElementById('category-filter');
        if (categoryFilter) {
            categoryFilter.addEventListener('change', (e) => {
                this.filters.categoryId = e.target.value || null;
                this.currentPage = 1;
                this.loadDocuments();
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
                    this.loadDocuments();
                }
            });
        }

        // Search
        const searchInput = document.getElementById('search-input');
        const searchBtn = document.getElementById('search-btn');

        if (searchBtn && searchInput) {
            const performSearch = () => {
                this.filters.search = searchInput.value.trim();
                this.currentPage = 1;
                this.loadDocuments();
            };

            searchBtn.addEventListener('click', performSearch);
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });
        }

        // Clear filters
        const clearBtn = document.getElementById('clear-filters-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                this.filters = { categoryId: null, tags: [], search: '' };
                this.currentPage = 1;

                if (categoryFilter) categoryFilter.value = '';
                if (searchInput) searchInput.value = '';
                document.querySelectorAll('.tag-filter-checkbox').forEach(cb => cb.checked = false);

                this.loadDocuments();
            });
        }

        // Refresh button
        const refreshBtn = document.getElementById('refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadDocuments());
        }
    }

    attachDocumentEventListeners() {
        // View buttons
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const id = e.currentTarget.dataset.id;
                window.location.href = `/documents/${id}`;
            });
        });

        // Download buttons
        document.querySelectorAll('.download-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const id = e.currentTarget.dataset.id;
                this.downloadDocument(id);
            });
        });

        // Delete buttons
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const id = e.currentTarget.dataset.id;
                this.confirmDelete(id);
            });
        });

        // Retry buttons
        document.querySelectorAll('.retry-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const id = e.currentTarget.dataset.id;
                this.retryProcessing(id);
            });
        });
    }

    updateViewButtons() {
        const gridBtn = document.getElementById('grid-view-btn');
        const listBtn = document.getElementById('list-view-btn');

        if (gridBtn && listBtn) {
            if (this.viewMode === 'grid') {
                gridBtn.classList.add('active');
                listBtn.classList.remove('active');
            } else {
                listBtn.classList.add('active');
                gridBtn.classList.remove('active');
            }
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
        } catch (error) {
            console.error('Download failed:', error);
            this.showError('Failed to download document');
        }
    }

    confirmDelete(id) {
        if (confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
            this.deleteDocument(id);
        }
    }

    async deleteDocument(id) {
        try {
            await axios.delete(`/api/documents/${id}`, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            this.showSuccess('Document deleted successfully');
            this.loadDocuments();
        } catch (error) {
            console.error('Delete failed:', error);
            this.showError('Failed to delete document');
        }
    }

    async retryProcessing(id) {
        try {
            await axios.post(`/api/documents/${id}/retry-processing`, {}, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            this.showSuccess('Document processing retry initiated');
            this.loadDocuments();
        } catch (error) {
            console.error('Retry failed:', error);
            this.showError('Failed to retry processing');
        }
    }

    getProcessingStatusBadge(status) {
        const statusConfig = {
            'uploaded': { class: 'bg-secondary', icon: 'clock', text: 'Uploaded' },
            'pending': { class: 'bg-info', icon: 'clock-history', text: 'Pending' },
            'processing': { class: 'bg-warning', icon: 'hourglass-split', text: 'Processing' },
            'completed': { class: 'bg-success', icon: 'check-circle', text: 'Completed' },
            'failed': { class: 'bg-danger', icon: 'x-circle', text: 'Failed' }
        };

        const config = statusConfig[status] || statusConfig['uploaded'];

        return `<span class="badge ${config.class}">
            <i class="bi bi-${config.icon}"></i> ${config.text}
        </span>`;
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

    showSuccess(message) {
        this.showNotification('success', message);
    }

    showError(message) {
        this.showNotification('danger', message);
    }

    showNotification(type, message) {
        const container = document.getElementById('notification-container');
        if (!container) {
            console.log(`[${type}] ${message}`);
            return;
        }

        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
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

// Initialize document browser when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new DocumentBrowser();
});
