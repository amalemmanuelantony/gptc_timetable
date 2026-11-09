FROM php:8.2-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql mbstring \
    && a2enmod headers
WORKDIR /var/www/html
COPY . /var/www/html
EXPOSE 80