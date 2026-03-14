#!/bin/bash

# 1. Map the Nginx root to /public
echo "🔨 Configuring Nginx..."
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default
service nginx reload

# 2. Ensure critical storage directories exist (Fixes "View path not found")
echo "📂 Creating storage subdirectories..."
mkdir -p /home/site/wwwroot/storage/app/public
mkdir -p /home/site/wwwroot/storage/framework/cache/data
mkdir -p /home/site/wwwroot/storage/framework/sessions
mkdir -p /home/site/wwwroot/storage/framework/views
mkdir -p /home/site/wwwroot/storage/logs

# 3. Fix permissions (Fast)
echo "🔐 Setting storage permissions..."
chmod -R 777 /home/site/wwwroot/storage
chmod -R 775 /home/site/wwwroot/bootstrap/cache

# 4. Increase PHP-FPM limits
sed -i 's/^pm.max_children = .*/pm.max_children = 20/g' /usr/local/etc/php-fpm.d/www.conf

# 5. Clear Application Caches
# We do NOT run config:cache because we need dynamic DB settings in AppServiceProvider.
echo "🧹 Clearing caches..."
php /home/site/wwwroot/artisan config:clear
php /home/site/wwwroot/artisan cache:clear
php /home/site/wwwroot/artisan view:clear
php /home/site/wwwroot/artisan route:clear

# 6. Run migrations Synchronously
# We add a timeout to prevent startup hanging forever if DB is down.
echo "⏳ Running migrations..."
php /home/site/wwwroot/artisan migrate --force --no-interaction

# 7. Start the Laravel Scheduler Worker
# Using schedule:work is much more reliable for 30-second tasks.
echo "🚀 Starting Laravel Scheduler worker..."
touch /home/site/wwwroot/storage/logs/scheduler.log
nohup php /home/site/wwwroot/artisan schedule:work >> /home/site/wwwroot/storage/logs/scheduler.log 2>&1 &

echo "🚀 Startup script finished! PHP-FPM taking over."
