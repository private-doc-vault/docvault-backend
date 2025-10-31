/**
 * DocVault Document Preview JavaScript
 * Handles document preview modal with zoom and navigation
 */

import axios from 'axios';
import 'bootstrap';
import { Modal } from 'bootstrap';

class DocumentPreview {
    constructor(documentId) {
        this.documentId = documentId;
        this.document = null;
        this.allDocuments = [];
        this.currentIndex = 0;
        this.zoomLevel = 1;
        this.rotation = 0;
        this.modal = null;
        this.init();
    }

    async init() {
        await this.loadDocument();
        await this.loadAllDocuments();
        this.setupEventListeners();
        this.renderDocument();
    }

    async loadDocument() {
        try {
            const response = await axios.get(`/api/documents/${this.documentId}`, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            this.document = response.data.document || response.data;
            this.updateDocumentInfo();
        } catch (error) {
            console.error('Failed to load document:', error);
            this.showError('Failed to load document details');
        }
    }

    async loadAllDocuments() {
        try {
            const response = await axios.get('/api/documents?limit=1000', {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            this.allDocuments = response.data.documents || response.data;
            this.currentIndex = this.allDocuments.findIndex(doc => doc.id === this.documentId);
        } catch (error) {
            console.error('Failed to load documents for navigation:', error);
        }
    }

    renderDocument() {
        const infoContainer = document.getElementById('document-info');
        if (!infoContainer || !this.document) return;

        const doc = this.document;

        infoContainer.innerHTML = `
            <div class="row">
                <div class="col-md-8">
                    <h2 class="h4 mb-3">${this.escapeHtml(doc.filename || doc.originalName)}</h2>

                    <div class="mb-3">
                        ${doc.category ? `<span class="badge bg-secondary me-2">${this.escapeHtml(doc.category.name)}</span>` : ''}
                        ${doc.tags && doc.tags.length > 0 ? doc.tags.map(tag => `
                            <span class="badge bg-info text-dark me-1">${this.escapeHtml(tag.name)}</span>
                        `).join('') : ''}
                    </div>

                    <dl class="row">
                        <dt class="col-sm-3">File Type</dt>
                        <dd class="col-sm-9">
                            <i class="bi bi-file-earmark-${this.getFileIcon(doc.mimeType)} text-primary"></i>
                            ${this.escapeHtml(doc.mimeType || 'Unknown')}
                        </dd>

                        <dt class="col-sm-3">File Size</dt>
                        <dd class="col-sm-9">${this.formatBytes(doc.fileSize)}</dd>

                        <dt class="col-sm-3">Uploaded</dt>
                        <dd class="col-sm-9">${this.formatDate(doc.createdAt)}</dd>

                        ${doc.owner ? `
                            <dt class="col-sm-3">Owner</dt>
                            <dd class="col-sm-9">${this.escapeHtml(doc.owner.firstName || doc.owner.email)}</dd>
                        ` : ''}

                        ${doc.description ? `
                            <dt class="col-sm-3">Description</dt>
                            <dd class="col-sm-9">${this.escapeHtml(doc.description)}</dd>
                        ` : ''}
                    </dl>

                    ${doc.ocrText ? `
                        <div class="card mt-3">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="bi bi-file-text"></i> Extracted Text (OCR)
                                </h6>
                            </div>
                            <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                <pre class="mb-0 small">${this.escapeHtml(doc.ocrText.substring(0, 1000))}${doc.ocrText.length > 1000 ? '...' : ''}</pre>
                            </div>
                        </div>
                    ` : ''}
                </div>

                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Actions</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary" id="preview-btn">
                                    <i class="bi bi-eye"></i> Preview
                                </button>
                                <button class="btn btn-outline-primary" id="download-btn">
                                    <i class="bi bi-download"></i> Download
                                </button>
                                <button class="btn btn-outline-secondary" id="edit-btn">
                                    <i class="bi bi-pencil"></i> Edit Details
                                </button>
                                <button class="btn btn-outline-warning" id="share-btn">
                                    <i class="bi bi-share"></i> Share
                                </button>
                                <hr>
                                <button class="btn btn-outline-danger" id="delete-btn">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>

                    ${this.renderNavigationButtons()}
                </div>
            </div>
        `;

        this.attachActionListeners();
    }

    renderNavigationButtons() {
        if (this.allDocuments.length <= 1) return '';

        const hasPrev = this.currentIndex > 0;
        const hasNext = this.currentIndex < this.allDocuments.length - 1;

        return `
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0">Navigation</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <button class="btn btn-outline-secondary" id="prev-doc-btn" ${!hasPrev ? 'disabled' : ''}>
                            <i class="bi bi-arrow-left"></i> Previous
                        </button>
                        <span class="align-self-center small text-muted">
                            ${this.currentIndex + 1} / ${this.allDocuments.length}
                        </span>
                        <button class="btn btn-outline-secondary" id="next-doc-btn" ${!hasNext ? 'disabled' : ''}>
                            Next <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    updateDocumentInfo() {
        // Update page title
        document.title = `${this.document.filename || this.document.originalName} - DocVault`;

        // Update breadcrumb if it exists
        const breadcrumb = document.getElementById('document-name-breadcrumb');
        if (breadcrumb) {
            breadcrumb.textContent = this.document.filename || this.document.originalName;
        }
    }

    setupEventListeners() {
        // Preview modal events
        const previewModalEl = document.getElementById('previewModal');
        if (previewModalEl) {
            this.modal = new Modal(previewModalEl);

            // Zoom controls
            document.getElementById('zoom-in-btn')?.addEventListener('click', () => this.zoomIn());
            document.getElementById('zoom-out-btn')?.addEventListener('click', () => this.zoomOut());
            document.getElementById('zoom-reset-btn')?.addEventListener('click', () => this.resetZoom());

            // Rotation
            document.getElementById('rotate-left-btn')?.addEventListener('click', () => this.rotateLeft());
            document.getElementById('rotate-right-btn')?.addEventListener('click', () => this.rotateRight());

            // Fullscreen
            document.getElementById('fullscreen-btn')?.addEventListener('click', () => this.toggleFullscreen());

            // Keyboard navigation
            previewModalEl.addEventListener('shown.bs.modal', () => {
                document.addEventListener('keydown', this.handleKeyboard.bind(this));
            });

            previewModalEl.addEventListener('hidden.bs.modal', () => {
                document.removeEventListener('keydown', this.handleKeyboard.bind(this));
            });
        }

        // Navigation between documents
        document.addEventListener('click', (e) => {
            if (e.target.id === 'prev-doc-btn') {
                this.navigateToPrevious();
            } else if (e.target.id === 'next-doc-btn') {
                this.navigateToNext();
            }
        });
    }

    attachActionListeners() {
        // Preview button
        document.getElementById('preview-btn')?.addEventListener('click', () => {
            this.openPreview();
        });

        // Download button
        document.getElementById('download-btn')?.addEventListener('click', () => {
            this.downloadDocument();
        });

        // Edit button
        document.getElementById('edit-btn')?.addEventListener('click', () => {
            this.editDocument();
        });

        // Share button
        document.getElementById('share-btn')?.addEventListener('click', () => {
            this.shareDocument();
        });

        // Delete button
        document.getElementById('delete-btn')?.addEventListener('click', () => {
            this.deleteDocument();
        });
    }

    async openPreview() {
        if (!this.modal) return;

        const previewContent = document.getElementById('preview-content');
        if (!previewContent) return;

        // Show loading state
        previewContent.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-muted mt-3">Loading preview...</p>
            </div>
        `;

        this.modal.show();

        try {
            const doc = this.document;
            const mimeType = doc.mimeType || '';

            if (mimeType.includes('image')) {
                await this.renderImagePreview(previewContent);
            } else if (mimeType.includes('pdf')) {
                this.renderPdfPreview(previewContent);
            } else if (mimeType.includes('text')) {
                await this.renderTextPreview(previewContent);
            } else {
                previewContent.innerHTML = `
                    <div class="text-center py-5">
                        <i class="bi bi-file-earmark fs-1 text-muted"></i>
                        <p class="text-muted mt-3">Preview not available for this file type</p>
                        <button class="btn btn-primary" onclick="document.getElementById('download-btn').click(); bootstrap.Modal.getInstance(document.getElementById('previewModal')).hide();">
                            <i class="bi bi-download"></i> Download to View
                        </button>
                    </div>
                `;
            }

            this.updateZoomDisplay();
        } catch (error) {
            console.error('Preview failed:', error);
            previewContent.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-exclamation-triangle fs-1 text-warning"></i>
                    <p class="text-muted mt-3">Failed to load preview</p>
                </div>
            `;
        }
    }

    async renderImagePreview(container) {
        const url = `/api/documents/${this.documentId}/download`;

        container.innerHTML = `
            <div class="preview-wrapper" style="overflow: auto; max-height: 70vh;">
                <img id="preview-image"
                     src="${url}"
                     alt="${this.escapeHtml(this.document.filename)}"
                     style="max-width: 100%; transform: scale(${this.zoomLevel}) rotate(${this.rotation}deg); transition: transform 0.2s;"
                     class="img-fluid">
            </div>
        `;
    }

    renderPdfPreview(container) {
        const url = `/api/documents/${this.documentId}/download`;

        container.innerHTML = `
            <div class="preview-wrapper" style="height: 70vh;">
                <iframe src="${url}#toolbar=1"
                        style="width: 100%; height: 100%; border: none;"
                        title="${this.escapeHtml(this.document.filename)}"></iframe>
            </div>
        `;
    }

    async renderTextPreview(container) {
        try {
            const response = await axios.get(`/api/documents/${this.documentId}/download`, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                },
                responseType: 'text'
            });

            container.innerHTML = `
                <div class="preview-wrapper" style="overflow: auto; max-height: 70vh;">
                    <pre class="p-3 bg-light" style="font-size: ${12 * this.zoomLevel}px; transition: font-size 0.2s;">${this.escapeHtml(response.data)}</pre>
                </div>
            `;
        } catch (error) {
            throw error;
        }
    }

    zoomIn() {
        this.zoomLevel = Math.min(this.zoomLevel + 0.25, 3);
        this.updatePreviewTransform();
    }

    zoomOut() {
        this.zoomLevel = Math.max(this.zoomLevel - 0.25, 0.5);
        this.updatePreviewTransform();
    }

    resetZoom() {
        this.zoomLevel = 1;
        this.rotation = 0;
        this.updatePreviewTransform();
    }

    rotateLeft() {
        this.rotation = (this.rotation - 90) % 360;
        this.updatePreviewTransform();
    }

    rotateRight() {
        this.rotation = (this.rotation + 90) % 360;
        this.updatePreviewTransform();
    }

    updatePreviewTransform() {
        const image = document.getElementById('preview-image');
        if (image) {
            image.style.transform = `scale(${this.zoomLevel}) rotate(${this.rotation}deg)`;
        }

        const textPreview = document.querySelector('.preview-wrapper pre');
        if (textPreview) {
            textPreview.style.fontSize = `${12 * this.zoomLevel}px`;
        }

        this.updateZoomDisplay();
    }

    updateZoomDisplay() {
        const zoomDisplay = document.getElementById('zoom-level');
        if (zoomDisplay) {
            zoomDisplay.textContent = `${Math.round(this.zoomLevel * 100)}%`;
        }
    }

    toggleFullscreen() {
        const modal = document.querySelector('.modal-dialog');
        if (!modal) return;

        if (modal.classList.contains('modal-fullscreen')) {
            modal.classList.remove('modal-fullscreen');
            modal.classList.add('modal-xl');
        } else {
            modal.classList.remove('modal-xl');
            modal.classList.add('modal-fullscreen');
        }
    }

    handleKeyboard(e) {
        if (!this.modal) return;

        switch(e.key) {
            case 'Escape':
                this.modal.hide();
                break;
            case '+':
            case '=':
                e.preventDefault();
                this.zoomIn();
                break;
            case '-':
                e.preventDefault();
                this.zoomOut();
                break;
            case '0':
                e.preventDefault();
                this.resetZoom();
                break;
            case 'ArrowLeft':
                e.preventDefault();
                this.rotateLeft();
                break;
            case 'ArrowRight':
                e.preventDefault();
                this.rotateRight();
                break;
        }
    }

    navigateToPrevious() {
        if (this.currentIndex > 0) {
            const prevDoc = this.allDocuments[this.currentIndex - 1];
            window.location.href = `/documents/${prevDoc.id}`;
        }
    }

    navigateToNext() {
        if (this.currentIndex < this.allDocuments.length - 1) {
            const nextDoc = this.allDocuments[this.currentIndex + 1];
            window.location.href = `/documents/${nextDoc.id}`;
        }
    }

    async downloadDocument() {
        try {
            const response = await axios.get(`/api/documents/${this.documentId}/download`, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                },
                responseType: 'blob'
            });

            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', this.document.filename || this.document.originalName || `document-${this.documentId}`);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);

            this.showSuccess('Document downloaded successfully');
        } catch (error) {
            console.error('Download failed:', error);
            this.showError('Failed to download document');
        }
    }

    editDocument() {
        // TODO: Implement edit functionality
        this.showInfo('Edit functionality coming soon');
    }

    shareDocument() {
        const url = window.location.href;

        if (navigator.share) {
            navigator.share({
                title: this.document.filename || this.document.originalName,
                text: `Check out this document: ${this.document.filename}`,
                url: url
            }).catch(err => console.log('Share cancelled'));
        } else {
            // Fallback: copy to clipboard
            navigator.clipboard.writeText(url).then(() => {
                this.showSuccess('Link copied to clipboard');
            }).catch(() => {
                this.showError('Failed to copy link');
            });
        }
    }

    async deleteDocument() {
        if (!confirm('Are you sure you want to delete this document? This action cannot be undone.')) {
            return;
        }

        try {
            await axios.delete(`/api/documents/${this.documentId}`, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            this.showSuccess('Document deleted successfully');

            // Redirect to documents page after a short delay
            setTimeout(() => {
                window.location.href = '/documents';
            }, 1500);
        } catch (error) {
            console.error('Delete failed:', error);
            this.showError('Failed to delete document');
        }
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
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
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

    showInfo(message) {
        this.showNotification('info', message);
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

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    const documentIdElement = document.getElementById('document-id');
    if (documentIdElement) {
        const documentId = documentIdElement.dataset.documentId;
        new DocumentPreview(documentId);
    }
});
