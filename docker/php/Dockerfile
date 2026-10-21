# syntax=docker/dockerfile:1.7
#
# Imagen de osTicket.
#
#   docker build --target dev  -t osticket:dev  .
#   docker build --target prod -t osticket:prod .
#
# IMPORTANTE (php2shell): la versión base DEBE incluir el parche de
# CVE-2026-109618 / CVE-2026-109619 publicado el 2026-10-21:
#   PHP 8.2.35 · 8.3.36 · 8.4.27 · 8.5.12
# El valor por defecto ya es una versión corregida; si lo cambias, el
# entrypoint y tools/security/php-cve-check.php abortan la ejecución.
ARG PHP_VERSION=8.4.27
# Versión de Xdebug (sólo etapa dev). Vacío = la última compatible.
ARG XDEBUG_VERSION=
FROM php:${PHP_VERSION}-fpm-alpine AS base

LABEL org.opencontainers.image.title="osTicket" \
      org.opencontainers.image.description="osTicket help desk" \
      org.opencontainers.image.source="https://github.com/osTicket/osTicket" \
      org.opencontainers.image.licenses="GPL-2.0-or-later"

# ---------------------------------------------------------------------------
# Dependencias del sistema y extensiones de PHP
# ---------------------------------------------------------------------------
RUN set -eux; \
    apk add --no-cache \
        ca-certificates \
        curl \
        freetype \
        icu-libs \
        libintl \
        libjpeg-turbo \
        libpng \
        libwebp \
        libzip \
        oniguruma \
        tzdata \
    ; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        gettext-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        oniguruma-dev \
    ; \
    docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    ; \
    docker-php-ext-install -j"$(nproc)" \
        gettext \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        zip \
    ; \
    apk del --no-network .build-deps; \
    rm -rf /var/cache/apk/* /tmp/*

# Configuración base compartida (segura para desarrollo y producción)
COPY docker/php/conf.d/00-osticket.ini /usr/local/etc/php/conf.d/00-osticket.ini

COPY docker/php/docker-entrypoint.sh /usr/local/bin/osticket-entrypoint
RUN chmod +x /usr/local/bin/osticket-entrypoint

WORKDIR /var/www/html

# Comprobación de vida del propio FPM: útil con `docker run` y como base
# para los healthchecks de compose.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'

ENTRYPOINT ["osticket-entrypoint"]
# -F: no demonizar. El proceso principal tiene que seguir vivo o Docker
# reinicia el contenedor en bucle (ver docker-entrypoint.sh, sección del pool).
CMD ["php-fpm", "-F"]

# ---------------------------------------------------------------------------
# Desarrollo: Xdebug + OPcache con revalidación
# ---------------------------------------------------------------------------
FROM base AS dev

ARG XDEBUG_VERSION

RUN set -eux; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers; \
    if [ -n "$XDEBUG_VERSION" ]; then \
        pecl install "xdebug-$XDEBUG_VERSION"; \
    else \
        pecl install xdebug; \
    fi; \
    docker-php-ext-enable xdebug; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear

COPY docker/php/conf.d/90-xdebug.ini /usr/local/etc/php/conf.d/90-xdebug.ini
COPY docker/php/conf.d/10-dev.ini /usr/local/etc/php/conf.d/10-dev.ini

# Xdebug 3 sólo reconoce XDEBUG_MODE / XDEBUG_TRIGGER como variables de
# entorno; el resto de ajustes viven en 90-xdebug.ini.
ENV XDEBUG_MODE=develop,debug

# ---------------------------------------------------------------------------
# Producción: sin Xdebug, OPcache inmutable
# ---------------------------------------------------------------------------
FROM base AS prod

COPY docker/php/conf.d/10-prod.ini /usr/local/etc/php/conf.d/10-prod.ini

# El código se copia en la imagen: en producción no se monta el repositorio.
COPY --chown=www-data:www-data . /var/www/html
