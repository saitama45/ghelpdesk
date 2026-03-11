#!/bin/bash

# 1. Map the Nginx root to /public
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default
service nginx reload

# 2. Fix permissions for storage and cache (Fast and Crucial)
chmod -R 775 /home/site/wwwroot/storage /home/site/wwwroot/bootstrap/cache

# 3. Run migrations on your Azure SQL Database (Can be slow, so we do it before caching)
# We only do this once to ensure DB is ready.
php /home/site/wwwroot/artisan migrate --force

# 4. Clear old caches to avoid state issues
php /home/site/wwwroot/artisan config:clear
php /home/site/wwwroot/artisan cache:clear

# 5. Optimize Laravel (Caching)
# If these are slow, they can be moved to background or handled by GitHub Actions
php /home/site/wwwroot/artisan config:cache
php /home/site/wwwroot/artisan route:cache
php /home/site/wwwroot/artisan view:cache

# 6. Start the Laravel Scheduler loop in the background
(while true; do
  php /home/site/wwwroot/artisan schedule:run >> /home/site/wwwroot/storage/logs/scheduler.log 2>&1
  sleep 60
done) &

echo "🚀 Startup completed successfully!"