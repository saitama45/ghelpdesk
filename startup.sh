#!/bin/bash

# 1. Map the Nginx root to /public
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default
service nginx reload

# 2. Run migrations on your Azure SQL Database
php /home/site/wwwroot/artisan migrate --force

# 3. Optimize Laravel ON THE SERVER (This replaces the GitHub steps)
php /home/site/wwwroot/artisan config:cache
php /home/site/wwwroot/artisan route:cache
php /home/site/wwwroot/artisan view:cache

# 4. Fix permissions for storage (Crucial for Azure Linux)
chmod -R 775 /home/site/wwwroot/storage /home/site/wwwroot/bootstrap/cache

# 5. Start the Laravel Scheduler loop in the background
# This will run 'php artisan schedule:run' every 60 seconds
(while true; do
  php /home/site/wwwroot/artisan schedule:run >> /home/site/wwwroot/storage/logs/scheduler.log 2>&1
  sleep 60
done) &