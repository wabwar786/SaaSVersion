FROM php:8.2-apache

# Extensions the app needs
RUN docker-php-ext-install pdo_mysql mysqli

# Force exactly ONE MPM (mod_php requires prefork). Disable event/worker if present, then remove any MPM symlinks (bulletproof). Fixes "More than one MPM loaded".
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
 && a2enmod mpm_prefork rewrite headers

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
