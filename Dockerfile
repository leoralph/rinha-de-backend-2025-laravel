FROM dunglas/frankenphp:1.8-php8.4-alpine

RUN docker-php-ext-install pcntl

WORKDIR /app

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY ./composer.json ./composer.lock /app/
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

COPY . /app

RUN composer dump-autoload --optimize

ENTRYPOINT ["php", "artisan", "octane:start", "--host=0.0.0.0"]
