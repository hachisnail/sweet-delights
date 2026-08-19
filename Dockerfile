FROM php:8.2-apache

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Change document root to public folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess to work and grant permissions
RUN echo "<Directory ${APACHE_DOCUMENT_ROOT}>\n\tAllowOverride All\n\tRequire all granted\n</Directory>" > /etc/apache2/conf-available/override.conf \
    && a2enconf override

WORKDIR /var/www/html

# Expose port 80
EXPOSE 80
