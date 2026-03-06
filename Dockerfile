FROM php:8.4-cli

# Install all system dependencies in one step.
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    libevent-dev \
    libicu-dev \
    unzip \
    rrdtool \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions.
RUN docker-php-ext-configure gd --with-freetype
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
