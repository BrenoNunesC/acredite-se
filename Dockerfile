FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY . /var/www/html/
RUN mkdir -p /var/www/html/storage/infograficos /var/www/html/storage/outbox \
 && chown -R www-data:www-data /var/www/html/storage
