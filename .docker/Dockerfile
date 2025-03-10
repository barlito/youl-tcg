FROM dunglas/frankenphp:1-php8.3 AS frankenphp_upstream

### Base Image ###############################################################
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
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
ENV SERVER_NAME=":80"

COPY --link .docker/php/app.ini $PHP_INI_DIR/conf.d/
COPY --link .docker/franken/Caddyfile /etc/caddy/Caddyfile

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:2019/metrics || exit 1
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]

### Dev Image ################################################################
FROM frankenphp_base AS frankenphp_dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--watch" ]

### Prod Image ###############################################################
FROM frankenphp_base AS frankenphp_prod
ENV APP_ENV=prod
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link .docker/php/app.prod.ini $PHP_INI_DIR/conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link . ./

# cleanup
RUN rm -rf .docker .github

RUN set -eux; \
	mkdir -p var/cache var/log; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	composer dump-env prod; \
	composer run-script --no-dev post-install-cmd; \
	chmod +x bin/console; sync;
