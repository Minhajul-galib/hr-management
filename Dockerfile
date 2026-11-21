FROM serversideup/php:8.4-fpm-nginx

USER root

# Install Node.js
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean

# Install extensions
RUN install-php-extensions pdo_pgsql gd

WORKDIR /var/www/html

# Copy project files
COPY --chown=www-data:www-data . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Install JS dependencies and build
RUN npm install && npm run build

# Cache Laravel config
RUN php artisan storage:link || true

# .dockerignore keeps runtime data (uploads, logs, caches) out of the image, which can
# leave these folders missing. Create them so they exist and belong to www-data -
# the uploads volume mounted on storage/app inherits that ownership.
RUN mkdir -p storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs bootstrap/cache

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data
