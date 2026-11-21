FROM php:8.5-alpine

RUN apk update && apk add --no-cache $PHPIZE_DEPS linux-headers

RUN --mount=type=bind,from=ghcr.io/php/pie:bin,source=/pie,target=/usr/local/bin/pie \
    pie install --no-cache xdebug/xdebug

COPY --from=composer:2.11 /usr/bin/composer /usr/bin/composer
