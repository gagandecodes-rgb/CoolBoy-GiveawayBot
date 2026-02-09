# Use official PHP with Apache
FROM php:8.2-apache

# Enable required PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql

# Enable Apache mod_rewrite (good practice)
RUN a2enmod rewrite

# Copy bot files to Apache root
COPY . /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
