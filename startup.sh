#!/bin/bash

# 1. Map the Nginx root to /public
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default
service nginx reload

# 2. Fix permissions (Fast)
chmod -R 775 /home/site/wwwroot/storage /home/site/wwwroot/bootstrap/cache

# 3. Run migrations and Caching in the BACKGROUND
# This allows the web server to start IMMEDIATELY so Azure sees it as "Healthy"
(
  echo "⏳ Background tasks starting..."
  php /home/site/wwwroot/artisan migrate --force
  php /home/site/wwwroot/artisan config:cache
  php /home/site/wwwroot/artisan route:cache
  php /home/site/wwwroot/artisan view:cache
  echo "✅ Background tasks finished!"
) &

# 4. Start the Laravel Scheduler loop
# We'll add a check to make sure it doesn't overlap too much
(while true; do
  php /home/site/wwwroot/artisan schedule:run >> /home/site/wwwroot/storage/logs/scheduler.log 2>&1
  sleep 60
done) &

echo "🚀 Startup script finished! Handing over to php-fpm."