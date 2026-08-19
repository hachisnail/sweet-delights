FROM php:8.2-fpm

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /var/www/html

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000
