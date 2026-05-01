FROM php:8.2-apache
RUN docker-php-ext-install sockets
RUN printf "upload_max_filesize=64M\npost_max_size=70M\nmemory_limit=128M\n" \
    > /usr/local/etc/php/conf.d/cs-uploads.ini
COPY www/ /var/www/html/
