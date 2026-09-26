# ---------------------------------------------------------------
# Étape 1 : build du bundle JS (src/main.js -> app.js) avec esbuild
# ---------------------------------------------------------------
FROM node:20-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY src ./src
RUN npm run build   # génère app.js à la racine

# ---------------------------------------------------------------
# Étape 2 : image finale, PHP + Apache
# ---------------------------------------------------------------
FROM php:8.2-apache
WORKDIR /var/www/html

# Activer le module headers (pour le cache) et autoriser le .htaccess
RUN a2enmod headers rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# app.js compilé à l'étape précédente
COPY --from=build /app/app.js ./app.js

# fichiers de l'application + .htaccess
COPY api.php index.php config.php bingo.css .htaccess version.json ./
COPY sons ./sons

# dossier data/ pour l'état des parties (persisté via volume)
RUN mkdir -p data \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod 775 /var/www/html/data

EXPOSE 80