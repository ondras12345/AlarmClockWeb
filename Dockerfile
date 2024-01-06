FROM php:8.3-apache
ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update -qq && \
    apt-get install -y unzip
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install -n
COPY . .
COPY config-sample.php config.php

EXPOSE 80

CMD ["apache2-foreground"]
