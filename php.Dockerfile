FROM php:8.0-zts

RUN apt-get update && apt-get install -y \
    git \
    libzip-dev \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install \
    zip \
    pdo_pgsql \
    pdo_mysql

# parallel install
RUN git clone https://github.com/krakjoe/parallel.git \
    && cd parallel \
    && phpize \
    && ./configure --enable-parallel \
    && make \
    && make install && docker-php-ext-enable parallel.so;

# xdebug install
RUN pecl install xdebug-3.1.2 && docker-php-ext-enable xdebug

# composer install
COPY --from=composer:2.2.4 /usr/bin/composer /usr/bin/composer

WORKDIR /app
