FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        linux-headers-amd64 \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS linux-headers-amd64 \
    && rm -rf /var/lib/apt/lists/*

RUN printf '%s\n' \
      'xdebug.mode=debug' \
      'xdebug.start_with_request=yes' \
      'xdebug.client_host=host.docker.internal' \
      'xdebug.client_port=9003' \
    >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /workspace
