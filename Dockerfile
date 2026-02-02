# Use a version of PHP with Apache pre-installed
FROM php:8.2-apache

# Install MySQL extensions (needed for most library systems)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy your code into the web server folder
COPY . /var/www/html/

# Make sure Apache has permissions to read your files
RUN chown -R www-data:www-data /var/www/html/

# Tell Render to use port 80
EXPOSE 80
