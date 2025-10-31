/**
 * DocVault Dashboard JavaScript
 * Handles dashboard statistics and recent documents display
 */

import axios from 'axios';
import 'bootstrap';

class Dashboard {
    constructor() {
        this.init();
    }

    async init() {
        await this.loadStatistics();
        await this.loadRecentDocuments();
        this.setupEventListeners();
    }

    async loadStatistics() {
        try {
            const response = await axios.get('/api/admin/stats');
            const stats = response.data;

            this.updateStatCard('total-documents', stats.totalDocuments || 0);
            this.updateStatCard('total-users', stats.totalUsers || 0);
            this.updateStatCard('storage-used', this.formatBytes(stats.storageUsed || 0));
            this.updateStatCard('documents-today', stats.documentsToday || 0);
        } catch (error) {
            console.error('Failed to load statistics:', error);
        }
    }

    async loadRecentDocuments() {
        try {
            const response = await axios.get('/api/documents?limit=6&sort=createdAt&order=desc');
            const documents = response.data.documents || response.data;

            this.renderRecentDocuments(documents);
        } catch (error) {
            console.error('Failed to load recent documents:', error);
        }
    }

    updateStatCard(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    renderRecentDocuments(documents) {
        const container = document.getElementById('recent-documents');
        if (!container) return;

        if (!documents || documents.length === 0) {
            container.innerHTML = '<p class="text-muted">No documents yet</p>';
            return;
        }

        container.innerHTML = documents.map(doc => `
            <div class="col">
                <div class="card document-card h-100">
                    <div class="card-body">
                        <div class="document-icon text-center mb-3">
                            <i class="bi bi-file-earmark-${this.getFileIcon(doc.mimeType)} fs-1 text-primary"></i>
                        </div>
                        <h6 class="card-title text-truncate" title="${doc.filename}">${doc.filename}</h6>
                        <p class="card-text small text-muted">
                            <i class="bi bi-calendar"></i> ${this.formatDate(doc.createdAt)}
                        </p>
                        <p class="card-text small text-muted">
                            <i class="bi bi-hdd"></i> ${this.formatBytes(doc.fileSize)}
                        </p>
                    </div>
                    <div class="card-footer bg-transparent">
                        <a href="/documents/${doc.id}" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-eye"></i> View
                        </a>
                    </div>
                </div>
            </div>
        `).join('');
    }

    getFileIcon(mimeType) {
        if (!mimeType) return 'text';
        if (mimeType.includes('pdf')) return 'pdf';
        if (mimeType.includes('image')) return 'image';
        if (mimeType.includes('word')) return 'word';
        if (mimeType.includes('excel')) return 'excel';
        return 'text';
    }

    formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
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

    setupEventListeners() {
        // Mobile menu toggle
        const menuToggle = document.getElementById('menu-toggle');
        if (menuToggle) {
            menuToggle.addEventListener('click', () => {
                document.querySelector('.sidebar').classList.toggle('show');
            });
        }

        // Refresh button
        const refreshBtn = document.getElementById('refresh-stats');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                this.loadStatistics();
                this.loadRecentDocuments();
            });
        }
    }
}

// Initialize dashboard when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new Dashboard();
});
