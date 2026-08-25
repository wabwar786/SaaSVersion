FROM php:8.2-apache

# Extensions the app needs
RUN docker-php-ext-install pdo_mysql mysqli \
 && a2enmod rewrite headers

# Serve from public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html
COPY . /var/www/html

# Everything not a real file -> router.php (so /login.html?b=slug, /dashboard.html etc. work)
RUN printf '<Directory /var/www/html/public>\n  AllowOverride All\n  Require all granted\n  FallbackResource /router.php\n</Directory>\n' > /etc/apache2/conf-available/z-router.conf \
 && a2enconf z-router \
 && mkdir -p storage/logs storage/sessions && chown -R www-data:www-data storage

ENV AIO_CONFIG=config/cloud.php
COPY tools/docker-entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
