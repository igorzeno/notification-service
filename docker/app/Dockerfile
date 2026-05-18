FROM php:8.4-fpm-alpine

# Устанавливаем системные зависимости
RUN apk update && apk add --no-cache \
        bash \
        curl \
        zip \
        unzip \
        supervisor \
        postgresql-client \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        autoconf \
        gcc \
        g++ \
        make \
        git \
        linux-headers \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        bcmath \
        mbstring \
        pcntl \
        sockets \
        zip

# Устанавливаем PECL расширения
RUN pecl install redis && \
    docker-php-ext-enable redis

# Устанавливаем Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY ./docker/app/php.ini /usr/local/etc/php/conf.d/php.ini

# 1. Создаем пользователя
RUN addgroup -g 1000 appuser && \
    adduser -u 1000 -G appuser -D -h /home/appuser appuser

# 2. Создаем ВСЕ необходимые директории ОДНОЙ командой
RUN mkdir -p /var/www/backend/storage/framework/sessions \
    /var/www/backend/storage/framework/views \
    /var/www/backend/storage/framework/cache/data \
    /var/www/backend/storage/framework/testing \
    /var/www/backend/storage/logs \
    /var/www/backend/storage/app/public \
    /var/www/backend/storage/app/backups \
    /var/www/backend/storage/telescope \
    /var/www/backend/bootstrap/cache \
    /etc/supervisor/conf.d \
    /var/log/supervisor \
    /var/run/supervisor

# 3. Меняем владельца ВСЕХ директорий
RUN chown -R appuser:appuser /var/www/backend \
    /var/log/supervisor \
    /var/run/supervisor

# 5. Копируем код с правильным владельцем
COPY --chown=appuser:appuser . /var/www/backend

# 4. Ставим права (775 для директорий, 777 для cache/sessions)
RUN chmod -R 775 /var/www/backend/storage /var/www/backend/bootstrap/cache \
    && chmod -R 777 /var/www/backend/storage/framework/cache/data \
    /var/www/backend/storage/framework/sessions

# 6. Настройки Supervisor
COPY ./docker/start-container /usr/local/bin/start-container
RUN chmod +x /usr/local/bin/start-container

COPY ./docker/.bashrc /home/appuser/.bashrc
RUN chmod 644 /home/appuser/.bashrc

USER appuser

WORKDIR /var/www/backend
ENTRYPOINT ["start-container"]
