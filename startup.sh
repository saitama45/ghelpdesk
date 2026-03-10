# Inside startup.sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
# ... (rest of the Nginx reload commands)

# Copy the custom Nginx config to the system folder
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default

# Reload Nginx to apply changes
service nginx reload

# Run Laravel optimizations
php /home/site/wwwroot/artisan config:cache
php /home/site/wwwroot/artisan route:cache