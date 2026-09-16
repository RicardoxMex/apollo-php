# syntax=docker/dockerfile:1

# ─────────────────────────────────────────────────────────────────────
# Sport Tournament Pro — API (Apollo framework, PHP 8.3 + MySQL)
#
# Decisión de servidor: se usa el servidor embebido de PHP
# (`php -S ... -t public`), el mismo que `composer start`, suficiente para
# el MVP (mono-instancia, tráfico bajo, sin webserver extra que mantener).
# El front controller `public/index.php` recibe todas las rutas /api/*.
# Para más tráfico: php-fpm + nginx (ver docs/deploy.md, sección alternativa).
#
# Build:  docker build -t sport-tournament/api:local .
# Run:    docker compose up -d  (recomendado; ver docker-compose.yml)
# ─────────────────────────────────────────────────────────────────────
FROM php:8.3-cli

# Extensiones requeridas:
#  - pdo_mysql  → conexión a MySQL (la app)
#  - gd, zip    → PhpSpreadsheet (plantillas .xlsx)
#  - pcntl      → Workerman (servicio realtime opcional)
# pdo_sqlite ya viene en la imagen base (tests/harness SQLite).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer (solo build time; no queda como dependencia en runtime)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 1) Dependencias (capa cacheable mientras no cambien los lockfiles)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress \
        --prefer-dist --optimize-autoloader --no-scripts

# 2) Código de la aplicación
COPY . .

# 3) Autoload definitivo (post-autoload-dump ya ve core/Support/helpers.php)
RUN composer dump-autoload --no-dev --optimize

# Carpetas de escritura (runtime: logs; storage: uploads). Si en compose se
# montan volúmenes nombrados, heredan estos permisos al crearse.
RUN mkdir -p runtime/logs/mail storage/uploads \
    && chown -R www-data:www-data runtime storage \
    && chmod -R u+rwX runtime storage

ENV APP_ENV=production \
    APP_DEBUG=false

EXPOSE 8000

USER www-data

# Health check del contenedor: 200/ok = healthy; 503/degraded = unhealthy.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=5 \
    CMD php -r '$r=@file_get_contents("http://127.0.0.1:8000/api/health"); exit($r===false?1:0);'

# Ver nota de decisión arriba: servidor embebido, no apto para alta concurrencia.
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
