FROM dunglas/frankenphp:1-php8.4 AS frankenphp_upstream

### Base Image ###############################################################
FROM frankenphp_upstream AS frankenphp_base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    libnss3-tools \
    vim \
	acl \
	&& rm -rf /var/lib/apt/lists/*

# Create non-root user with configurable UID/GID
ARG USER_ID=1000
ARG GROUP_ID=1000

RUN groupadd -g ${GROUP_ID} www && \
    useradd -u ${USER_ID} -g www -m -s /bin/bash www && \
    chown -R www:www /app /data /config

# add additional extensions here:
RUN install-php-extensions \
    @composer \
	intl \
	zip \
	opcache \
    apcu \
	pdo_pgsql

ENV COMPOSER_HOME=/home/www/.composer
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

USER www

CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--watch" ]

### Prod Image ###############################################################
FROM frankenphp_base AS frankenphp_prod

ARG APP_VERSION=dev
ENV APP_VERSION=$APP_VERSION

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

RUN php bin/console tailwind:build --minify; \
    php bin/console asset-map:compile;

RUN chown -R www:www var public/images 2>/dev/null || true

USER www
