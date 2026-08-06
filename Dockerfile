FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    $PHPIZE_DEPS \
    postgresql-dev \
    libpng-dev \
    libzip-dev \
    linux-headers \
    zip \
    unzip \
    git \
    curl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# pcntl required by Laravel Horizon (signal handling / supervisors)
RUN docker-php-ext-configure pcntl --enable-pcntl \
    && docker-php-ext-install pdo_pgsql bcmath zip opcache pcntl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-pgsql || \
    composer install --no-dev --no-scripts --no-autoloader

COPY . .

RUN composer dump-autoload --optimize && composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-pgsql || \
    composer dump-autoload --optimize

RUN mkdir -p /var/www/html/storage /var/www/html/bootstrap/cache && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
