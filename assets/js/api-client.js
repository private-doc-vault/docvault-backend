/**
 * API Client for DocVault
 * Handles JWT token management and API requests
 */

import axios from 'axios';

class ApiClient {
    constructor() {
        this.baseURL = '/api';
        this.token = null;
        this.refreshToken = null;
        this.isRefreshing = false;
        this.failedQueue = [];

        // Initialize axios instance
        this.client = axios.create({
            baseURL: this.baseURL,
            headers: {
                'Content-Type': 'application/json',
            }
        });

        // Load tokens from localStorage
        this.loadTokens();

        // Setup interceptors
        this.setupInterceptors();
    }

    /**
     * Load tokens from localStorage
     */
    loadTokens() {
        this.token = localStorage.getItem('jwt_token');
        this.refreshToken = localStorage.getItem('refresh_token');
    }

    /**
     * Save tokens to localStorage
     */
    saveTokens(token, refreshToken) {
        this.token = token;
        this.refreshToken = refreshToken;
        localStorage.setItem('jwt_token', token);
        localStorage.setItem('refresh_token', refreshToken);
    }

    /**
     * Clear tokens from localStorage
     */
    clearTokens() {
        this.token = null;
        this.refreshToken = null;
        localStorage.removeItem('jwt_token');
        localStorage.removeItem('refresh_token');
    }

    /**
     * Setup axios interceptors for token management
     */
    setupInterceptors() {
        // Request interceptor to add JWT token
        this.client.interceptors.request.use(
            (config) => {
                if (this.token) {
                    config.headers.Authorization = `Bearer ${this.token}`;
                }
                return config;
            },
            (error) => {
                return Promise.reject(error);
            }
        );

        // Response interceptor to handle token refresh
        this.client.interceptors.response.use(
            (response) => response,
            async (error) => {
                const originalRequest = error.config;

                // If 401 and we have a refresh token, try to refresh
                if (error.response?.status === 401 && this.refreshToken && !originalRequest._retry) {
                    if (this.isRefreshing) {
                        // Queue the request while refresh is in progress
                        return new Promise((resolve, reject) => {
                            this.failedQueue.push({ resolve, reject });
                        }).then(token => {
                            originalRequest.headers.Authorization = `Bearer ${token}`;
                            return this.client(originalRequest);
                        }).catch(err => {
                            return Promise.reject(err);
                        });
                    }

                    originalRequest._retry = true;
                    this.isRefreshing = true;

                    try {
                        const response = await axios.post(`${this.baseURL}/auth/refresh`, {
                            refresh_token: this.refreshToken
                        });

                        const { token, refresh_token } = response.data;
                        this.saveTokens(token, refresh_token);

                        // Process queued requests
                        this.processQueue(null, token);
                        this.failedQueue = [];

                        // Retry original request
                        originalRequest.headers.Authorization = `Bearer ${token}`;
                        return this.client(originalRequest);
                    } catch (refreshError) {
                        // Refresh failed, clear tokens and redirect to login
                        this.processQueue(refreshError, null);
                        this.failedQueue = [];
                        this.clearTokens();

                        // Redirect to login page
                        if (window.location.pathname !== '/login') {
                            window.location.href = '/login';
                        }

                        return Promise.reject(refreshError);
                    } finally {
                        this.isRefreshing = false;
                    }
                }

                return Promise.reject(error);
            }
        );
    }

    /**
     * Process queued requests after token refresh
     */
    processQueue(error, token = null) {
        this.failedQueue.forEach(prom => {
            if (error) {
                prom.reject(error);
            } else {
                prom.resolve(token);
            }
        });
    }

    /**
     * Login and save tokens
     */
    async login(email, password) {
        try {
            const response = await axios.post(`${this.baseURL}/auth/login`, {
                email,
                password
            });

            const { token, refresh_token } = response.data;
            this.saveTokens(token, refresh_token);

            return response.data;
        } catch (error) {
            throw error;
        }
    }

    /**
     * Logout and clear tokens
     */
    async logout() {
        try {
            if (this.token) {
                await this.client.post('/auth/logout');
            }
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            this.clearTokens();
        }
    }

    /**
     * Check if user is authenticated
     */
    isAuthenticated() {
        return !!this.token;
    }

    /**
     * Make a GET request
     */
    async get(url, config = {}) {
        return this.client.get(url, config);
    }

    /**
     * Make a POST request
     */
    async post(url, data, config = {}) {
        return this.client.post(url, data, config);
    }

    /**
     * Make a PUT request
     */
    async put(url, data, config = {}) {
        return this.client.put(url, data, config);
    }

    /**
     * Make a PATCH request
     */
    async patch(url, data, config = {}) {
        return this.client.patch(url, data, config);
    }

    /**
     * Make a DELETE request
     */
    async delete(url, config = {}) {
        return this.client.delete(url, config);
    }
}

// Create singleton instance
const apiClient = new ApiClient();

export default apiClient;
