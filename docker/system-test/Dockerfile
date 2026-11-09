FROM php:8.1-cli@sha256:76e563191d1ade120313a8736df24154d21da5155c0756f147c0b01bd19d9087

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        unzip \
        libonig-dev \
        libicu-dev \
    && docker-php-ext-install -j"$(nproc)" mbstring bcmath intl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
