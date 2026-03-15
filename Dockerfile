FROM php:8.2-cli

WORKDIR /app

RUN set -eux \
    ; apt-get update \
    ; apt-get install -y --no-install-recommends \
        git \
        unzip \
        libcurl4-openssl-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
    ; docker-php-ext-install \
        curl \
        mbstring \
        pdo_mysql \
        xml \
        zip \
    ; pecl install redis \
    ; docker-php-ext-enable redis \
    ; apt-get clean \
    ; rm -rf /var/lib/apt/lists/*

COPY composer /app

RUN set -eux \
    ; curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    ; composer install --no-interaction --prefer-dist --optimize-autoloader \
    ; if [ ! -f .env ]; then cp .env.example .env; fi \
    ; if ! grep -q "^APP_KEY=base64:" .env; then php artisan key:generate --force; fi \
    ; chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host", "0.0.0.0", "--port", "8000"]
