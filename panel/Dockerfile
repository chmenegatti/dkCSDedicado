FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip \
        unrar-free \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install sockets zip
RUN printf "upload_max_filesize=64M\npost_max_size=70M\nmemory_limit=128M\n" \
    > /usr/local/etc/php/conf.d/cs-uploads.ini
COPY www/ /var/www/html/
