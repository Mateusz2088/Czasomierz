FROM php:8.2-apache

# Instalacja rozszerzeń systemowych + PDO MySQL
RUN apt-get update && apt-get install -y \
        libzip-dev \
        unzip \
        git \
        default-mysql-client \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        opcache \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer (opcjonalnie, ale przydatny)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache – nasłuch na 80 wewnątrz kontenera
EXPOSE 80

WORKDIR /var/www/html