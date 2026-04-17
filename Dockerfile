# Use the official PHP image with Apache
FROM php:8.2-apache
EXPOSE 80
# Install necessary PHP extensions
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libonig-dev \
    libpng-dev \
    zlib1g-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd curl mysqli mbstring pdo pdo_mysql zip

# copy contents into directory
COPY . /var/www/html

# Set appropriate permissions
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

# Set working directory
WORKDIR /var/www/html