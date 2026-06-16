# Корпоративный портал — образ приложения: PHP-FPM 8.3 + nginx в одном контейнере,
# наружу HTTP :80 (за обратным прокси Traefik). Внутри LibreOffice — для конвертации
# Word/Excel → PDF (визы, описи, гарантийные письма, предпросмотр СЭД).
FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        libpq-dev libzip-dev libonig-dev libsqlite3-dev \
        libreoffice-writer libreoffice-calc \
        fonts-liberation fonts-crosextra-carlito fonts-crosextra-caladea \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_sqlite zip mbstring \
    && rm -rf /var/lib/apt/lists/*

# nginx: наш сайт вместо дефолтного
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
RUN rm -f /etc/nginx/sites-enabled/default

WORKDIR /var/www/html
COPY . /var/www/html
# Резервная копия шаблонов ВНЕ тома storage (том app_storage перекрывает storage/ при монтировании).
# entrypoint восстановит их в storage/templates, если там пусто.
RUN cp -a storage/templates /opt/templates-default \
    && mkdir -p storage/uploads/docs storage/tmp \
    && chown -R www-data:www-data storage

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
