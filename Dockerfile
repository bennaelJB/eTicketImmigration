FROM php:8.3-fpm

# Installer les dépendances système
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

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Définir le répertoire de travail
WORKDIR /var/www/html

# Copier uniquement composer.json + composer.lock pour le cache
COPY composer.json composer.lock ./

# Installer les dépendances sans lancer les scripts Laravel
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-scripts

# Copier tout le code source
COPY . .

# Générer l'autoload et lancer les scripts Laravel après copie
RUN composer dump-autoload --optimize
RUN php artisan package:discover

# Permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Créer le .env si inexistant
RUN if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi

# Exposer le port
EXPOSE 80

# Commande de lancement
CMD ["sh", "-c", "php artisan config:clear && php artisan serve --host=0.0.0.0 --port=80"]
