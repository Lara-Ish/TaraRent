FROM php:8.2-apache

# Install PostgreSQL, GD, Zip, and cURL development libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pdo_mysql \
        gd \
        zip \
        curl \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite for clean routing
RUN a2enmod rewrite headers

# Configure Apache DocumentRoot
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides
RUN echo '<Directory /var/www/html/>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/override.conf \
    && a2enconf override

# Custom PHP settings for file uploads
RUN echo "upload_max_filesize = 10M\n\
post_max_size = 12M\n\
memory_limit = 256M\n\
max_execution_time = 60\n\
display_errors = Off\n\
log_errors = On" > /usr/local/etc/php/conf.d/custom-php.ini

# Copy all project files into Apache web root
COPY . /var/www/html/

# Create required upload folders and set permissions
RUN mkdir -p /var/www/html/uploads/receipts \
             /var/www/html/uploads/avatars \
             /var/www/html/uploads/id_verifications \
             /var/www/html/uploads/live_captures \
             /var/www/html/uploads/reports \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/uploads

# Start Apache on Render's dynamic port
CMD sed -i "s/80/$PORT/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && apache2-foreground