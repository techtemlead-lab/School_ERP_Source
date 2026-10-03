FROM php:8.3-apache@sha256:c2c73159da2f7a19167a2849bd9785ca9ad4f5eeff01f85295ef861a5e9bcf4d
RUN apt-get update && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libonig-dev libcurl4-openssl-dev && docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j$(nproc) pdo_mysql mbstring gd zip curl && a2enmod rewrite remoteip && rm -rf /var/lib/apt/lists/*
RUN printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/erp.conf && a2enconf erp
ENV TZ=Asia/Dhaka
WORKDIR /var/www/html

RUN printf 'RemoteIPHeader X-Real-IP\nRemoteIPInternalProxy 172.16.0.0/12\nRemoteIPInternalProxy 10.0.0.0/8\nRemoteIPInternalProxy 192.168.0.0/16\n' > /etc/apache2/conf-available/erp-proxy.conf && a2enconf erp-proxy

RUN docker-php-ext-install opcache && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/erp-security.conf && a2enconf erp-security
COPY deployment/php-production.ini /usr/local/etc/php/conf.d/erp-production.ini
COPY . /var/www/html/
RUN find /var/www/html -type d -exec chmod 755 {} + && find /var/www/html -type f -exec chmod 644 {} +

COPY deployment/entrypoint.sh /opt/erp/entrypoint.sh
COPY deployment/uploads.htaccess /opt/erp/uploads.htaccess
RUN chmod 755 /opt/erp/entrypoint.sh
ENTRYPOINT ["/opt/erp/entrypoint.sh"]
CMD ["apache2-foreground"]
