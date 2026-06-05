FROM php:8.2-fpm-bookworm

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
      ffmpeg \
      git \
      curl \
      unzip \
      libcurl4-openssl-dev \
      libxml2-dev \
      libonig-dev \
      libpq-dev \
      libzip-dev \
      zip \
      ca-certificates \
      cron \
    && docker-php-ext-install pdo_pgsql opcache zip curl mbstring xml \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Composer dependencies are installed at build time in a real CI pipeline.
# For this scaffold we keep the image ready for runtime mounting.
RUN mkdir -p /var/media /var/www/html/storage

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-radio.ini

CMD ["php-fpm"]
