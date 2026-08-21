# Local development image: PHP 8.4 CLI + Phalcon 5 + pdo_mysql + imagick.
# Production is shared hosting deployed via FTP; this image only exists so the API can run locally.
FROM php:8.4-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libpcre2-dev libmagickwand-dev \
 && docker-php-ext-install pdo_mysql \
 && pecl install phalcon-5.19.0 imagick-3.8.1 \
 && docker-php-ext-enable phalcon imagick \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
EXPOSE 8080

# .htrouter.php serves existing files (photos) directly and routes everything else to index.php
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app", "/app/.htrouter.php"]
