FROM composer/composer:2-bin AS composer

ARG PHP_VERSION
FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm

RUN apt update && apt -y install \
    unzip

# Install PHP extensions
RUN install-php-extensions \
    opcache \
    mbstring \
    intl \
    dom \
    ctype \
    curl \
    phar \
    openssl \
    xml \
    xmlwriter \
    simplexml \
    pdo

# Install Composer
COPY --from=composer /composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER 1

# Set up work directory
ADD . /app

RUN composer update --prefer-dist --no-interaction
