ARG PHP_IMAGE
FROM ${PHP_IMAGE}

RUN apt-get update \
    && apt-get install --yes --no-install-recommends make unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
