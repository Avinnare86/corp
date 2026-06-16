# Корпоративный портал — образ приложения (PHP 8.3 + Apache).
# Корень сайта — public/, фронт-контроллер public/index.php (mod_rewrite через public/.htaccess).
FROM php:8.3-apache

# Системные зависимости и PHP-расширения:
#  - libpq/zip/oniguruma — для pdo_pgsql, zip, mbstring
#  - LibreOffice (writer/calc) + шрифты — для конвертации Word→PDF (визы, описи, гарантийные письма)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libonig-dev libsqlite3-dev unzip \
        libreoffice-writer libreoffice-calc \
        fonts-liberation fonts-crosextra-carlito fonts-crosextra-caladea \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_sqlite zip mbstring \
    && a2enmod rewrite \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/docroot.conf \
    && a2enconf docroot \
    && rm -rf /var/lib/apt/lists/*

# Корень Apache → public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

WORKDIR /var/www/html
COPY . /var/www/html
RUN mkdir -p storage/uploads/docs storage/tmp \
    && chown -R www-data:www-data storage

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
