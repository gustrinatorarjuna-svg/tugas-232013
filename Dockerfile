FROM php:8.2-cli

# Install ekstensi mysqli
RUN docker-php-ext-install mysqli

# Copy semua file
COPY . /var/www/html/

WORKDIR /var/www/html

# Buat folder upload
RUN mkdir -p thumbnail video \
    && chmod -R 755 thumbnail video

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080"]