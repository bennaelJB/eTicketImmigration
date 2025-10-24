FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first (better Docker layer caching)
COPY composer.json composer.lock ./

# Install dependencies without scripts (avoids .env errors during build)
RUN composer install --no-interaction --no-dev --prefer-dist --no-scripts --no-autoloader

# Copy all application files
COPY . .

# Complete composer installation
RUN composer dump-autoload --optimize

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create .env if it doesn't exist (copy from .env.example)
RUN if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi

# Expose port
EXPOSE 80

# Use shell form to allow environment variable substitution
CMD ["sh", "-c", "php artisan config:clear && php artisan serve --host=0.0.0.0 --port=80"]
