FROM php:8.2-apache

# Instalacja rozszerzeń systemowych + PDO MySQL + sockets/pcntl (serwer TCP) + supervisor
RUN apt-get update && apt-get install -y \
        libzip-dev \
        unzip \
        git \
        default-mysql-client \
        supervisor \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        opcache \
        sockets \
        pcntl \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer (opcjonalnie, ale przydatny)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Konfiguracja supervisora — uruchamia razem Apache i odbiornik TCP
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Katalog na WAL — zamontowany jako wolumen w docker-compose
RUN mkdir -p /var/lib/chronotrack/wal \
    && chown -R www-data:www-data /var/lib/chronotrack

# Apache – nasłuch na 80, serwer TCP – na 61611
EXPOSE 80 61611

WORKDIR /var/www/html

# Supervisor przejmuje rolę CMD (uruchamia apache + receiver.php)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]