# Gym Studio - immagine PHP+Apache pronta per qualsiasi hosting con supporto Docker
# (su hosting condiviso classico NON serve: si carica il codice via FTP,
#  vedi DEPLOY.md. Questo Dockerfile serve per VPS/cloud dove Docker e' disponibile.)
FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite \
    && sed -ri -e 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html/storage

EXPOSE 80
