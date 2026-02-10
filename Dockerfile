FROM php:8.4-cli

# Install all system dependencies in one step.
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    libevent-dev \
    libicu-dev \
    unzip \
    rrdtool \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions.
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    dom \
    opcache

# Install PECL extensions.
RUN pecl install raphf && docker-php-ext-enable raphf
RUN pecl install pecl_http && docker-php-ext-enable http

# Install Composer.
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory.
WORKDIR /app

# Copy project files.
COPY . .

# Install dependencies via Composer.
RUN composer install --no-dev --optimize-autoloader

# Create necessary directories.
RUN mkdir -p logs charts rrd cache && \
    chmod -R 777 logs charts rrd cache

# Set execute permissions for PHP scripts.
RUN chmod +x trader.php analyzer.php notifier.php

# Set execute permissions for migration scripts.
RUN chmod +x tasks/docker/wait-for-migrations.sh tasks/docker/run-migrations.sh
