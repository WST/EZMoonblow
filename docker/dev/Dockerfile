FROM php:8.4-fpm

# Install system dependencies.
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

# PHP-FPM pool tuning: more workers for concurrent SSE + normal requests.
# Run workers as host UID/GID so mounted files are accessible.
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN printf '[www]\nuser = %s\ngroup = %s\npm = dynamic\npm.max_children = 20\npm.start_servers = 4\npm.min_spare_servers = 2\npm.max_spare_servers = 8\npm.max_requests = 500\nrequest_terminate_timeout = 0\n' \
    "$HOST_UID" "$HOST_GID" > /usr/local/etc/php-fpm.d/zz-dev.conf

# Longer execution time for SSE and backtest endpoints.
RUN echo 'max_execution_time = 600' > /usr/local/etc/php/conf.d/dev-limits.ini && \
    echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/dev-limits.ini && \
    echo 'output_buffering = Off' >> /usr/local/etc/php/conf.d/dev-limits.ini && \
    echo 'implicit_flush = On' >> /usr/local/etc/php/conf.d/dev-limits.ini

WORKDIR /app

