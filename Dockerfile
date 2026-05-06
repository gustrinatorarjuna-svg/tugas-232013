FROM php:8.2-apache

# Install ekstensi mysqli
RUN docker-php-ext-install mysqli

# Copy semua file ke folder Apache
COPY . /var/www/html/

# Beri izin folder upload
RUN mkdir -p /var/www/html/thumbnail /var/www/html/video \
    && chmod -R 755 /var/www/html/thumbnail \
    && chmod -R 755 /var/www/html/video

EXPOSE 80