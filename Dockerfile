FROM php:8.1-fpm

# Instala pacotes e extensões PHP necessárias para Laravel
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    zip \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_mysql zip intl xml

# Instala Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Define diretório de trabalho
WORKDIR /var/www/html

# Copia os arquivos do projeto
COPY . .

# Instala dependências do Laravel
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Expõe porta usada pelo Artisan
EXPOSE 8080

# Comando de entrada
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
