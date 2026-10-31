FROM php:8.5-apache AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev tini \
    && docker-php-ext-install -j2 pdo_pgsql pcntl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html

FROM php-base AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction
COPY app app
COPY database database
RUN composer dump-autoload --no-dev --optimize --no-scripts

FROM node:24-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json .npmrc ./
RUN npm ci --include=dev
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY resources ./resources
COPY app ./app
COPY vite.config.js ./
RUN npm run build

FROM php-base
ENV APP_NAME=Brightloop APP_ENV=production APP_DEBUG=false DB_CONNECTION=pgsql \
    SESSION_DRIVER=database SESSION_SECURE_COOKIE=true CACHE_STORE=database \
    QUEUE_CONNECTION=database LOG_CHANNEL=stderr MAIL_MAILER=resend PORT=8080
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY --from=assets /build/public/build ./public/build
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && a2enmod rewrite headers \
    && printf 'Listen ${PORT}\n' > /etc/apache2/ports.conf \
    && printf '%s\n' 'ServerName localhost' '<VirtualHost *:${PORT}>' \
       'DocumentRoot /var/www/html/public' '<Directory /var/www/html/public>' \
       'AllowOverride All' 'Options -Indexes' 'Require all granted' '</Directory>' \
       'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on' \
       'Header always set X-Content-Type-Options "nosniff"' \
       'Header always set X-Frame-Options "SAMEORIGIN"' \
       'Header always set Referrer-Policy "strict-origin-when-cross-origin"' \
       'Header always set Strict-Transport-Security "max-age=31536000"' \
       "Header always set Content-Security-Policy \"base-uri 'self'; object-src 'none'; frame-ancestors 'self'; upgrade-insecure-requests\"" \
       'ErrorLog /proc/self/fd/2' 'CustomLog /proc/self/fd/1 combined' '</VirtualHost>' \
       > /etc/apache2/sites-available/000-default.conf \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x antideploy-start.sh
USER www-data
EXPOSE 8080
ENTRYPOINT ["/usr/bin/tini", "-g", "--", "/var/www/html/antideploy-start.sh"]
