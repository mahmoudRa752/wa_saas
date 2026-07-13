# Dockerfile
FROM php:8.2-apache

# Install system dependencies and required PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli pdo pdo_mysql gd zip opcache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy code to working directory
COPY . /var/www/html/

# Install composer dependencies (optimized for production)
RUN composer install --no-interaction --optimize-autoloader --no-dev || true

# Set correct file ownership for Apache
RUN chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80
