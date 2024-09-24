FROM dunglas/frankenphp:1-php8.3 AS frankenphp_upstream

FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    vim \
	acl \
#    supervisor \
	&& rm -rf /var/lib/apt/lists/*

# add additional extensions here:
RUN install-php-extensions \
    @composer \
	intl \
	zip \
	opcache \
    apcu \
	pdo_pgsql
#    pcntl \
#    bcmath \
#    amqp

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY ./.docker/franken/php/conf.d/ /usr/local/etc/php/conf.d/

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:2019/metrics || exit 1
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]

# Dev Image
FROM frankenphp_base AS frankenphp_dev

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--watch" ]
