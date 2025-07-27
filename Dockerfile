FROM dunglas/frankenphp:1.8-php8.4-alpine

RUN apk add --no-cache autoconf build-base

RUN docker-php-ext-install pcntl

RUN pecl install redis \
    && docker-php-ext-enable redis

WORKDIR /app

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY ./composer.json ./composer.lock /app/
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

COPY . /app

RUN composer dump-autoload --optimize
RUN php artisan optimize

ENTRYPOINT ["php", "artisan", "octane:start", "--host=0.0.0.0", "--max-requests=1000000000"]
