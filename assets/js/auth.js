/**
 * DocVault Authentication JavaScript
 * Handles login, registration, and password reset
 */

import 'bootstrap';

class AuthManager {
    constructor() {
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.setupValidation();
    }

    setupEventListeners() {
        // Password visibility toggle
        const passwordToggles = document.querySelectorAll('.password-toggle');
        passwordToggles.forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                const input = e.target.closest('.input-group').querySelector('input');
                const icon = e.target;

                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            });
        });

        // Password strength indicator
        const passwordInput = document.getElementById('registration_password');
        if (passwordInput) {
            passwordInput.addEventListener('input', (e) => {
                this.updatePasswordStrength(e.target.value);
            });
        }

        // Password confirmation matching
        const confirmPasswordInput = document.getElementById('registration_confirmPassword');
        if (confirmPasswordInput && passwordInput) {
            confirmPasswordInput.addEventListener('input', () => {
                this.validatePasswordMatch(passwordInput.value, confirmPasswordInput.value);
            });
        }

        // Form submission with loading state
        const forms = document.querySelectorAll('form[data-auth-form]');
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                this.handleFormSubmit(e, form);
            });
        });
    }

    setupValidation() {
        // Enable HTML5 validation with custom styling
        const forms = document.querySelectorAll('.needs-validation');
        Array.from(forms).forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    }

    updatePasswordStrength(password) {
        const indicator = document.getElementById('password-strength');
        const strengthText = document.getElementById('password-strength-text');

        if (!indicator || !strengthText) return;

        let strength = 0;
        let text = '';
        let className = '';

        if (password.length === 0) {
            indicator.style.width = '0%';
            strengthText.textContent = '';
            indicator.className = 'progress-bar';
            return;
        }

        // Calculate strength
        if (password.length >= 8) strength++;
        if (password.length >= 12) strength++;
        if (/[a-z]/.test(password)) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^a-zA-Z0-9]/.test(password)) strength++;

        // Update display
        if (strength <= 2) {
            text = 'Weak';
            className = 'bg-danger';
            indicator.style.width = '33%';
        } else if (strength <= 4) {
            text = 'Medium';
            className = 'bg-warning';
            indicator.style.width = '66%';
        } else {
            text = 'Strong';
            className = 'bg-success';
            indicator.style.width = '100%';
        }

        indicator.className = `progress-bar ${className}`;
        strengthText.textContent = text;
        strengthText.className = `small ${className.replace('bg-', 'text-')}`;
    }

    validatePasswordMatch(password, confirmPassword) {
        const confirmInput = document.getElementById('registration_confirmPassword');
        if (!confirmInput) return;

        if (confirmPassword && password !== confirmPassword) {
            confirmInput.setCustomValidity('Passwords do not match');
            confirmInput.classList.add('is-invalid');
        } else {
            confirmInput.setCustomValidity('');
            confirmInput.classList.remove('is-invalid');
            if (confirmPassword) {
                confirmInput.classList.add('is-valid');
            }
        }
    }

    handleFormSubmit(e, form) {
        const submitBtn = form.querySelector('button[type="submit"]');
        if (!submitBtn) return;

        // Show loading state
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading...';

        // Reset after 5 seconds (in case form submission is handled by Symfony)
        setTimeout(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }, 5000);
    }

    static showNotification(type, message) {
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

        setTimeout(() => {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 150);
        }, 5000);
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    new AuthManager();

    // Show success/error messages from flash messages
    const flashMessages = document.querySelectorAll('[data-flash-message]');
    flashMessages.forEach(msg => {
        const type = msg.dataset.flashType || 'info';
        const message = msg.textContent.trim();
        if (message) {
            AuthManager.showNotification(type, message);
        }
    });
});
