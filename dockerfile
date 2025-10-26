# Utilise l'image PHP officielle avec PDO MySQL
FROM php:8.2-cli

# Active l'extension PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Copie tout le backend
COPY . /app
WORKDIR /app

# Crée le dossier uploads
RUN mkdir -p uploads

# Expose le port utilisé
EXPOSE 10000

# Commande pour lancer le serveur PHP intégré
CMD ["php", "-S", "0.0.0.0:10000", "-t", "."]
