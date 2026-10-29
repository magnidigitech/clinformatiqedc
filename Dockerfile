FROM dunglas/frankenphp:latest

# Install PostgreSQL PDO and required PHP extensions
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    pdo \
    intl \
    zip \
    opcache

# Set FrankenPHP server configuration
ENV SERVER_NAME=":80"

# Copy application files to the document root
COPY . /app

# Ensure uploads directory exists and is writable
RUN mkdir -p /app/uploads && chmod -R 777 /app/uploads

EXPOSE 80
