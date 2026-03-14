#!/bin/bash

# 1. Map the Nginx root to /public
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default
service nginx reload

# 2. Ensure critical storage directories exist (Azure workaround for "View path not found")
echo "📂 Ensuring storage directory structure..."
mkdir -p /home/site/wwwroot/storage/app/public
mkdir -p /home/site/wwwroot/storage/framework/cache/data
mkdir -p /home/site/wwwroot/storage/framework/sessions
mkdir -p /home/site/wwwroot/storage/framework/views
mkdir -p /home/site/wwwroot/storage/logs

# 3. Fix permissions (Fast)
echo "🔐 Setting permissions..."
chmod -R 775 /home/site/wwwroot/storage /home/site/wwwroot/bootstrap/cache
chown -R www-data:www-data /home/site/wwwroot/storage /home/site/wwwroot/bootstrap/cache

# 4. Increase PHP-FPM worker limits
sed -i 's/^pm.max_children = .*/pm.max_children = 20/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.start_servers = .*/pm.start_servers = 4/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 6/g' /usr/local/etc/php-fpm.d/www.conf

# 5. Clear ALL caches to ensure fresh environment variables are used
# We avoid artisan config:cache because AppServiceProvider uses DB settings to override config.
echo "🧹 Clearing caches..."
php /home/site/wwwroot/artisan config:clear
php /home/site/wwwroot/artisan cache:clear
php /home/site/wwwroot/artisan view:clear
php /home/site/wwwroot/artisan route:clear

# 6. Run migrations synchronously
echo "⏳ Running migrations..."
php /home/site/wwwroot/artisan migrate --force

# 7. Start the Laravel Scheduler loop (Daemon mode)
# Using schedule:work ensures everyThirtySeconds() tasks are hit precisely.
echo "🚀 Starting Laravel Scheduler worker..."
touch /home/site/wwwroot/storage/logs/scheduler.log
nohup php /home/site/wwwroot/artisan schedule:work >> /home/site/wwwroot/storage/logs/scheduler.log 2>&1 &

echo "🚀 Startup script finished! Handing over to php-fpm."
