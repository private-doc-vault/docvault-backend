# PHP-FPM Dockerfile for DocVault Backend
# Optimized for QNAP TS-431P2 with limited resources

FROM php:8.4-fpm-alpine

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    postgresql-dev \
    icu-dev \
    oniguruma-dev \
    supervisor \
    nginx \
    $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        zip \
        pdo \
        pdo_pgsql \
        intl \
        mbstring \
        opcache \
        pcntl \
        bcmath

# Install Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Configure PHP for production
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=64'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.revalidate_freq=5'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'realpath_cache_size=4096K'; \
    echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/99-opcache.ini

# Set PHP memory and execution limits for QNAP
RUN { \
    echo 'memory_limit=256M'; \
    echo 'upload_max_filesize=50M'; \
    echo 'post_max_size=50M'; \
    echo 'max_execution_time=300'; \
    echo 'max_input_vars=3000'; \
    echo 'date.timezone=UTC'; \
    } > /usr/local/etc/php/conf.d/99-custom.ini

# Configure PHP-FPM for low resource usage
RUN { \
    echo '[www]'; \
    echo 'listen = 9000'; \
    echo 'pm = dynamic'; \
    echo 'pm.max_children = 5'; \
    echo 'pm.start_servers = 2'; \
    echo 'pm.min_spare_servers = 1'; \
    echo 'pm.max_spare_servers = 3'; \
    echo 'pm.process_idle_timeout = 10s'; \
    echo 'pm.max_requests = 1000'; \
    } > /usr/local/etc/php-fpm.d/zz-docker.conf

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create application user
RUN adduser -u 1000 -D -S -G www-data www-data || true

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Copy application files
COPY --chown=www-data:www-data . /var/www/html/

# Install dependencies as www-data user
USER www-data
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Clear cache after dependencies are installed
USER root

# Create required directories
RUN mkdir -p /var/www/html/var/cache /var/www/html/var/log /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/var /var/www/html/storage

# Health check - PHP-FPM runs on port 9000 and uses FastCGI protocol
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD pgrep php-fpm > /dev/null || exit 1

# Expose port
EXPOSE 9000

# Start PHP-FPM in foreground mode
USER www-data
CMD ["php-fpm", "-F"]