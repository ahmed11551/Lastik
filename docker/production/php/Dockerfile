# AUTOMETRIA ERP Engine - Protected Production Dockerfile
# @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.

FROM php:8.4-fpm-alpine AS base

# 1. Системные зависимости и PostgreSQL drivers
RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    bash \
    curl \
    fcgi

# 2. Установка стандартных расширений PHP
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    bcmath \
    intl \
    opcache \
    zip \
    pcntl

# 3. Установка SourceGuardian Loader (C-extension)
ARG PHP_SG_IXED=ixed.8.4.lin
RUN set -eux; \
    mkdir -p /tmp/sg; \
    cd /tmp/sg; \
    curl -fsSL -o loaders.tar.gz https://www.sourceguardian.com/loaders/download/loaders.linux-x86_64.tar.gz; \
    tar xzf loaders.tar.gz; \
    EXT_DIR="$(php -r 'echo ini_get("extension_dir");')"; \
    if [ ! -f "${PHP_SG_IXED}" ]; then \
      echo "Available SourceGuardian loaders:"; ls -la; \
      echo "Missing ${PHP_SG_IXED}"; exit 1; \
    fi; \
    cp "${PHP_SG_IXED}" "${EXT_DIR}/sourceguardian.so"; \
    echo "extension=sourceguardian.so" > /usr/local/etc/php/conf.d/10-sourceguardian.ini; \
    php -m | grep -i sourceguardian; \
    rm -rf /tmp/sg

# 4. Opcache для зашифрованных дистрибутивов
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=1"; \
      echo "opcache.validate_timestamps=0"; \
      echo "opcache.memory_consumption=256"; \
      echo "opcache.max_accelerated_files=20000"; \
    } >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini

WORKDIR /var/www/autometria

# 5. Копирование скомпилированного байт-кода (результат build-encoded-dist → ./dist)
COPY ./dist /var/www/autometria

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN if [ -f composer.json ]; then \
      composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist; \
    fi

RUN mkdir -p \
        storage/framework/licensing \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/public \
        bootstrap/cache \
    && chown -R www-data:www-data /var/www/autometria \
    && chmod -R ug+rwx storage bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
