/**
 * DocVault Document Upload JavaScript
 * Handles drag-and-drop document uploads using Dropzone.js
 */

import Dropzone from 'dropzone';
import 'dropzone/dist/min/dropzone.min.css';
import axios from 'axios';

// IMPORTANT: Disable Dropzone auto-discovery BEFORE any Dropzone instances are created
Dropzone.autoDiscover = false;

class DocumentUploader {
    constructor() {
        this.dropzone = null;
        this.categoryId = null;
        this.tags = [];
        this.init();
    }

    init() {
        this.initializeDropzone();
        this.setupEventListeners();
        this.loadCategories();
        this.loadTags();
    }

    initializeDropzone() {
        const dropzoneElement = document.getElementById('document-dropzone');
        if (!dropzoneElement) return;

        this.dropzone = new Dropzone('#document-dropzone', {
            url: '/api/documents/upload',
            method: 'post',
            paramName: 'file',
            maxFilesize: 100, // MB
            maxFiles: 50,
            parallelUploads: 3,
            uploadMultiple: false,
            addRemoveLinks: true,
            acceptedFiles: '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt',
            dictDefaultMessage: '<i class="bi bi-cloud-upload fs-1"></i><br>Drop files here or click to upload',
            dictFileTooBig: 'File is too big ({{filesize}}MB). Max filesize: {{maxFilesize}}MB.',
            dictInvalidFileType: 'You can\'t upload files of this type.',
            dictResponseError: 'Server responded with {{statusCode}} code.',
            dictMaxFilesExceeded: 'You can only upload {{maxFiles}} files at once.',
            autoProcessQueue: false,

            // Session-based authentication - no headers needed
            headers: {},

            init: function() {
                const dropzone = this;

                // Process queue on upload button click
                document.getElementById('upload-btn')?.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (dropzone.getQueuedFiles().length > 0) {
                        dropzone.processQueue();
                    }
                });

                // Clear completed files
                document.getElementById('clear-btn')?.addEventListener('click', (e) => {
                    e.preventDefault();
                    dropzone.removeAllFiles(true);
                });
            },

            sending: (file, xhr, formData) => {
                // Add metadata to each upload
                if (this.categoryId) {
                    formData.append('categoryId', this.categoryId);
                }
                if (this.tags.length > 0) {
                    this.tags.forEach(tag => {
                        formData.append('tags[]', tag);
                    });
                }
            },

            success: (file, response) => {
                console.log('Upload successful:', response);
                this.showNotification('success', `${file.name} uploaded successfully`);
                this.updateUploadStats();
            },

            error: (file, errorMessage, xhr) => {
                console.error('Upload failed:', errorMessage);
                let message = typeof errorMessage === 'string'
                    ? errorMessage
                    : errorMessage.message || 'Upload failed';
                this.showNotification('error', `Failed to upload ${file.name}: ${message}`);
            },

            queuecomplete: () => {
                console.log('All files uploaded');
                this.showNotification('info', 'All uploads complete');
                setTimeout(() => {
                    this.dropzone.removeAllFiles(true);
                    this.updateUploadStats();
                }, 2000);
            }
        });
    }

    async loadCategories() {
        try {
            const response = await axios.get('/api/categories');
            const categories = response.data.categories || response.data;
            this.renderCategories(categories);
        } catch (error) {
            console.error('Failed to load categories:', error);
        }
    }

    async loadTags() {
        try {
            const response = await axios.get('/api/tags');
            const tags = response.data.tags || response.data;
            this.renderTags(tags);
        } catch (error) {
            console.error('Failed to load tags:', error);
        }
    }

    renderCategories(categories) {
        const select = document.getElementById('category-select');
        if (!select) return;

        select.innerHTML = '<option value="">No Category</option>';

        categories.forEach(category => {
            const option = document.createElement('option');
            option.value = category.id;
            option.textContent = category.name;
            select.appendChild(option);
        });

        select.addEventListener('change', (e) => {
            this.categoryId = e.target.value || null;
        });
    }

    renderTags(tags) {
        const container = document.getElementById('tags-container');
        if (!container) return;

        container.innerHTML = '';

        tags.forEach(tag => {
            const checkbox = document.createElement('div');
            checkbox.className = 'form-check form-check-inline';
            checkbox.innerHTML = `
                <input class="form-check-input tag-checkbox" type="checkbox"
                       id="tag-${tag.id}" value="${tag.id}">
                <label class="form-check-label" for="tag-${tag.id}">
                    ${tag.name}
                </label>
            `;
            container.appendChild(checkbox);
        });

        // Update tags array when checkboxes change
        container.addEventListener('change', (e) => {
            if (e.target.classList.contains('tag-checkbox')) {
                this.updateSelectedTags();
            }
        });
    }

    updateSelectedTags() {
        const checkboxes = document.querySelectorAll('.tag-checkbox:checked');
        this.tags = Array.from(checkboxes).map(cb => cb.value);
    }

    async updateUploadStats() {
        try {
            const response = await axios.get('/api/admin/stats');
            const stats = response.data;

            // Update stats display if elements exist
            const totalElement = document.getElementById('total-uploads');
            if (totalElement) {
                totalElement.textContent = stats.totalDocuments || 0;
            }

            const todayElement = document.getElementById('today-uploads');
            if (todayElement) {
                todayElement.textContent = stats.documentsToday || 0;
            }
        } catch (error) {
            console.error('Failed to update stats:', error);
        }
    }

    showNotification(type, message) {
        const container = document.getElementById('notification-container');
        if (!container) {
            console.log(`[${type}] ${message}`);
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

        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 150);
        }, 5000);
    }

    setupEventListeners() {
        // Batch upload mode toggle
        const batchToggle = document.getElementById('batch-mode-toggle');
        if (batchToggle) {
            batchToggle.addEventListener('change', (e) => {
                if (e.target.checked) {
                    this.enableBatchMode();
                } else {
                    this.disableBatchMode();
                }
            });
        }
    }

    enableBatchMode() {
        if (!this.dropzone) return;

        this.dropzone.options.url = '/api/documents/batch-upload';
        this.dropzone.options.uploadMultiple = true;
        this.dropzone.options.parallelUploads = 50;

        const indicator = document.getElementById('batch-mode-indicator');
        if (indicator) {
            indicator.classList.remove('d-none');
        }
    }

    disableBatchMode() {
        if (!this.dropzone) return;

        this.dropzone.options.url = '/api/documents/upload';
        this.dropzone.options.uploadMultiple = false;
        this.dropzone.options.parallelUploads = 3;

        const indicator = document.getElementById('batch-mode-indicator');
        if (indicator) {
            indicator.classList.add('d-none');
        }
    }
}

// Initialize uploader when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new DocumentUploader();
});
