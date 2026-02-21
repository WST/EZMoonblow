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
RUN printf '[www]\npm = dynamic\npm.max_children = 20\npm.start_servers = 4\npm.min_spare_servers = 2\npm.max_spare_servers = 8\npm.max_requests = 500\nrequest_terminate_timeout = 0\n' \
    > /usr/local/etc/php-fpm.d/zz-pool.conf

# Longer execution time for SSE and backtest endpoints.
RUN echo 'max_execution_time = 600' > /usr/local/etc/php/conf.d/limits.ini && \
    echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/limits.ini && \
    echo 'output_buffering = Off' >> /usr/local/etc/php/conf.d/limits.ini && \
    echo 'implicit_flush = On' >> /usr/local/etc/php/conf.d/limits.ini

WORKDIR /app

# Copy project files.
COPY . .

# Install dependencies via Composer.
RUN composer install --no-dev --optimize-autoloader

# Create necessary directories.
RUN mkdir -p logs charts rrd cache && \
    chmod -R 777 logs charts rrd cache

# Set execute permissions for migration scripts.
RUN chmod +x tasks/docker/wait-for-migrations.sh tasks/docker/run-migrations.sh
