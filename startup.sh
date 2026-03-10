#!/bin/bash

# Copy our custom Nginx config to the system location
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default

# Reload Nginx to apply the new root (/public)
service nginx reload

# Run Laravel house-keeping
php /home/site/wwwroot/artisan migrate --force
php /home/site/wwwroot/artisan config:cache
php /home/site/wwwroot/artisan route:cache