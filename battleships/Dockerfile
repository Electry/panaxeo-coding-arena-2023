FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
  git \
  curl \
  libonig-dev \
  libpng-dev \
  libxml2-dev \
  libmemcached-dev \
  libmemcached-tools \
  libz-dev \
  zlib1g-dev \
  libssl-dev \
  zip \
  unzip

# RUN apt-get clean && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install opcache

RUN pecl install memcached
# RUN pecl install xdebug-3.3.0alpha3

RUN docker-php-ext-enable memcached
# RUN docker-php-ext-enable xdebug

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app/battleships

CMD ["php-fpm", "-R"]
