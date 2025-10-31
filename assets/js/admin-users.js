/**
 * Admin User Management Interface
 * Handles user listing, editing, and deletion for administrators
 */

import '../styles/app.css';
import 'bootstrap';

class UserManagement {
    constructor() {
        this.currentPage = 1;
        this.currentLimit = 20;
        this.currentFilters = {};
        this.editModal = null;
        this.deleteModal = null;

        this.init();
    }

    init() {
        // Initialize Bootstrap modals
        this.editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
        this.deleteModal = new bootstrap.Modal(document.getElementById('deleteUserModal'));

        // Bind event listeners
        this.bindEvents();

        // Load initial users
        this.loadUsers();
    }

    bindEvents() {
        // Apply filters
        document.getElementById('applyFilters')?.addEventListener('click', () => {
            this.currentPage = 1;
            this.applyFilters();
        });

        // Search on Enter key
        document.getElementById('userSearch')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.currentPage = 1;
                this.applyFilters();
            }
        });

        // Save user changes
        document.getElementById('saveUserBtn')?.addEventListener('click', () => {
            this.saveUser();
        });

        // Confirm delete user
        document.getElementById('confirmDeleteBtn')?.addEventListener('click', () => {
            this.deleteUser();
        });
    }

    applyFilters() {
        const search = document.getElementById('userSearch')?.value;
        const role = document.getElementById('roleFilter')?.value;
        const isActive = document.getElementById('statusFilter')?.value;

        this.currentFilters = {};

        if (search) this.currentFilters.search = search;
        if (role) this.currentFilters.role = role;
        if (isActive) this.currentFilters.isActive = isActive;

        this.loadUsers();
    }

    async loadUsers(page = this.currentPage) {
        const tableBody = document.getElementById('usersTableBody');
        const loadingIndicator = document.getElementById('loadingIndicator');
        const emptyState = document.getElementById('emptyState');

        // Show loading state
        tableBody.innerHTML = '';
        loadingIndicator?.classList.remove('d-none');
        emptyState?.classList.add('d-none');

        try {
            const params = new URLSearchParams({
                page: page,
                limit: this.currentLimit,
                ...this.currentFilters
            });

            const response = await fetch(`/api/admin/users?${params}`, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            loadingIndicator?.classList.add('d-none');

            if (!data.users || data.users.length === 0) {
                emptyState?.classList.remove('d-none');
                return;
            }

            this.renderUsers(data.users);
            this.renderPagination(data.page, data.pages, data.total);

        } catch (error) {
            console.error('Error loading users:', error);
            loadingIndicator?.classList.add('d-none');
            this.showError('Failed to load users. Please try again.');
        }
    }

    renderUsers(users) {
        const tableBody = document.getElementById('usersTableBody');

        tableBody.innerHTML = users.map(user => `
            <tr data-user-id="${user.id}">
                <td>
                    <div class="d-flex align-items-center">
                        <div class="avatar-circle bg-primary text-white me-2">
                            ${this.getInitials(user.firstName, user.lastName)}
                        </div>
                        <div>
                            <div class="fw-bold">${this.escapeHtml(user.fullName || 'N/A')}</div>
                        </div>
                    </div>
                </td>
                <td>${this.escapeHtml(user.email)}</td>
                <td>${this.renderRoles(user.roles)}</td>
                <td>${this.renderStatus(user.isActive, user.isVerified)}</td>
                <td>
                    <small class="text-muted">
                        ${user.lastLoginAt ? this.formatDate(user.lastLoginAt) : 'Never'}
                    </small>
                </td>
                <td>
                    <small class="text-muted">${this.formatDate(user.createdAt)}</small>
                </td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="userManagement.editUser('${user.id}')" title="Edit User">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-outline-danger" onclick="userManagement.confirmDeleteUser('${user.id}', '${this.escapeHtml(user.fullName || user.email)}')" title="Delete User">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    renderRoles(roles) {
        const roleLabels = {
            'ROLE_USER': '<span class="badge bg-secondary">User</span>',
            'ROLE_ADMIN': '<span class="badge bg-primary">Admin</span>',
            'ROLE_SUPER_ADMIN': '<span class="badge bg-danger">Super Admin</span>'
        };

        return roles.map(role => roleLabels[role] || `<span class="badge bg-light text-dark">${role}</span>`).join(' ');
    }

    renderStatus(isActive, isVerified) {
        if (!isActive) {
            return '<span class="badge bg-danger">Inactive</span>';
        }
        if (!isVerified) {
            return '<span class="badge bg-warning">Unverified</span>';
        }
        return '<span class="badge bg-success">Active</span>';
    }

    renderPagination(currentPage, totalPages, totalUsers) {
        const pagination = document.getElementById('usersPagination');

        if (totalPages <= 1) {
            pagination.innerHTML = '';
            return;
        }

        let html = '';

        // Previous button
        html += `
            <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="userManagement.loadUsers(${currentPage - 1}); return false;">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
        `;

        // Page numbers
        const maxPages = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxPages / 2));
        let endPage = Math.min(totalPages, startPage + maxPages - 1);

        if (endPage - startPage < maxPages - 1) {
            startPage = Math.max(1, endPage - maxPages + 1);
        }

        if (startPage > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" onclick="userManagement.loadUsers(1); return false;">1</a></li>`;
            if (startPage > 2) {
                html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            html += `
                <li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="userManagement.loadUsers(${i}); return false;">${i}</a>
                </li>
            `;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
            html += `<li class="page-item"><a class="page-link" href="#" onclick="userManagement.loadUsers(${totalPages}); return false;">${totalPages}</a></li>`;
        }

        // Next button
        html += `
            <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="userManagement.loadUsers(${currentPage + 1}); return false;">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        `;

        pagination.innerHTML = html;
    }

    async editUser(userId) {
        try {
            const response = await fetch(`/api/admin/users/${userId}`, {
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const user = await response.json();

            // Populate form
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editFirstName').value = user.firstName || '';
            document.getElementById('editLastName').value = user.lastName || '';
            document.getElementById('editEmail').value = user.email;
            document.getElementById('editIsActive').checked = user.isActive;
            document.getElementById('editIsVerified').checked = user.isVerified;

            // Set roles
            document.getElementById('roleAdmin').checked = user.roles.includes('ROLE_ADMIN');
            document.getElementById('roleSuperAdmin').checked = user.roles.includes('ROLE_SUPER_ADMIN');

            // Hide error
            document.getElementById('editUserError')?.classList.add('d-none');

            // Show modal
            this.editModal.show();

        } catch (error) {
            console.error('Error loading user details:', error);
            this.showError('Failed to load user details. Please try again.');
        }
    }

    async saveUser() {
        const userId = document.getElementById('editUserId').value;
        const saveBtn = document.getElementById('saveUserBtn');
        const spinner = document.getElementById('saveUserSpinner');
        const errorDiv = document.getElementById('editUserError');

        // Get form values
        const firstName = document.getElementById('editFirstName').value;
        const lastName = document.getElementById('editLastName').value;
        const email = document.getElementById('editEmail').value;
        const isActive = document.getElementById('editIsActive').checked;
        const isVerified = document.getElementById('editIsVerified').checked;

        // Build roles array
        const roles = ['ROLE_USER'];
        if (document.getElementById('roleAdmin').checked) roles.push('ROLE_ADMIN');
        if (document.getElementById('roleSuperAdmin').checked) roles.push('ROLE_SUPER_ADMIN');

        // Show loading state
        saveBtn.disabled = true;
        spinner?.classList.remove('d-none');
        errorDiv?.classList.add('d-none');

        try {
            const response = await fetch(`/api/admin/users/${userId}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${this.getAuthToken()}`
                },
                body: JSON.stringify({
                    firstName,
                    lastName,
                    email,
                    isActive,
                    isVerified,
                    roles
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to update user');
            }

            // Success - close modal and reload users
            this.editModal.hide();
            this.loadUsers();
            this.showSuccess('User updated successfully');

        } catch (error) {
            console.error('Error saving user:', error);
            errorDiv.textContent = error.message;
            errorDiv?.classList.remove('d-none');
        } finally {
            saveBtn.disabled = false;
            spinner?.classList.add('d-none');
        }
    }

    confirmDeleteUser(userId, userName) {
        document.getElementById('deleteUserId').value = userId;
        document.getElementById('deleteUserName').textContent = userName;
        document.getElementById('deleteUserError')?.classList.add('d-none');
        this.deleteModal.show();
    }

    async deleteUser() {
        const userId = document.getElementById('deleteUserId').value;
        const deleteBtn = document.getElementById('confirmDeleteBtn');
        const spinner = document.getElementById('deleteUserSpinner');
        const errorDiv = document.getElementById('deleteUserError');

        // Show loading state
        deleteBtn.disabled = true;
        spinner?.classList.remove('d-none');
        errorDiv?.classList.add('d-none');

        try {
            const response = await fetch(`/api/admin/users/${userId}`, {
                method: 'DELETE',
                headers: {
                    'Authorization': `Bearer ${this.getAuthToken()}`
                }
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to delete user');
            }

            // Success - close modal and reload users
            this.deleteModal.hide();
            this.loadUsers();
            this.showSuccess('User deleted successfully');

        } catch (error) {
            console.error('Error deleting user:', error);
            errorDiv.textContent = error.message;
            errorDiv?.classList.remove('d-none');
        } finally {
            deleteBtn.disabled = false;
            spinner?.classList.add('d-none');
        }
    }

    getAuthToken() {
        // In a real application, this would retrieve the JWT token from storage
        // For now, we'll assume it's stored in localStorage
        return localStorage.getItem('authToken') || '';
    }

    getInitials(firstName, lastName) {
        const first = firstName?.charAt(0)?.toUpperCase() || '';
        const last = lastName?.charAt(0)?.toUpperCase() || '';
        return first + last || '?';
    }

    formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text?.toString().replace(/[&<>"']/g, m => map[m]) || '';
    }

    showError(message) {
        // Simple alert for now - could be replaced with toast notifications
        alert(message);
    }

    showSuccess(message) {
        // Simple alert for now - could be replaced with toast notifications
        alert(message);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.userManagement = new UserManagement();
});

// Add avatar circle styles
const style = document.createElement('style');
style.textContent = `
    .avatar-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 14px;
    }
`;
document.head.appendChild(style);
