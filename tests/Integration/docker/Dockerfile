# Образ интеграционного набора: настоящий Swoole, собранный с --enable-swoole-pgsql, и pdo_pgsql.
# Всё специфичное для образа — в prepare.sh; матрица задаётся в run.sh.
ARG BASE_IMAGE=phpswoole/swoole:6.2.3-php8.5-zts
FROM ${BASE_IMAGE}

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY prepare.sh /usr/local/bin/prepare-integration-image
RUN chmod +x /usr/local/bin/prepare-integration-image && prepare-integration-image

WORKDIR /app
