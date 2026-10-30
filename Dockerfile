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

WORKDIR /app

# Remove default FrankenPHP welcome page and public folder
RUN rm -rf /app/* /app/.* 2>/dev/null || true

# Copy application files to document root
COPY . /app

# Replace FrankenPHP default Caddyfile with custom Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile

# Ensure uploads directory exists and is writable
RUN mkdir -p /app/uploads && chmod -R 777 /app/uploads

# Create symlink public -> /app so both /app and /app/public resolve to our app
RUN ln -s /app /app/public

ENV SERVER_NAME="http://:80"

EXPOSE 80 3000
