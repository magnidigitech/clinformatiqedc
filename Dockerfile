FROM dunglas/frankenphp:latest

# Install PostgreSQL PDO and required PHP extensions
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    pdo \
    intl \
    zip \
    opcache

# Use production php.ini with increased limits
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 50M/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/post_max_size = 8M/post_max_size = 50M/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/memory_limit = 128M/memory_limit = 256M/' "$PHP_INI_DIR/php.ini"

# Set FrankenPHP server configuration
ENV SERVER_NAME=":80"

# Copy application files to the document root
COPY . /app

# Ensure uploads directory exists and is writable
RUN mkdir -p /app/uploads && chmod -R 777 /app/uploads

EXPOSE 80
