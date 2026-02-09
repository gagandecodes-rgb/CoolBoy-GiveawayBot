FROM php:8.2-apache

# Install PostgreSQL client dev headers so pdo_pgsql can compile
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
 && docker-php-ext-install pdo pdo_pgsql \
 && apt-get purge -y --auto-remove libpq-dev \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
