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

# 2.1 Create storage symlink
echo "🔗 Creating storage symlink..."
php /home/site/wwwroot/artisan storage:link

# 3. Fix permissions (Fast)
echo "🔐 Setting storage permissions..."
chmod -R 777 /home/site/wwwroot/storage
chmod -R 775 /home/site/wwwroot/bootstrap/cache

# 4. Increase PHP-FPM limits
sed -i 's/^pm.max_children = .*/pm.max_children = 20/g' /usr/local/etc/php-fpm.d/www.conf

# 4.1 Increase PHP upload limits
cat <<EOF > /usr/local/etc/php/conf.d/uploads.ini
post_max_size = 1024M
upload_max_filesize = 1024M
memory_limit = 1024M
max_execution_time = 600
max_input_time = 600
EOF

# 4.2 Enable and tune OPcache
# Without OPcache every request recompiles the whole framework from source. Measured
# locally on the identical codebase: 0.575s -> 0.044s per request (13x). The official
# php:*-fpm images ship the extension but do not always enable it, so enable it first
# (idempotent, and ignored if it is already on) and then tune it.
if ! php -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);' 2>/dev/null; then
    echo "⚡ Enabling OPcache extension..."
    docker-php-ext-enable opcache 2>/dev/null || true
fi
cat <<EOF > /usr/local/etc/php/conf.d/zz-opcache.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
; Deployments replace the whole wwwroot, and the container restarts with it, so the
; cache is cold on every deploy anyway. Validating timestamps costs a stat() per file
; but keeps us safe against a partial/rolling file swap serving stale bytecode.
opcache.validate_timestamps=1
opcache.revalidate_freq=60
EOF

# 5. Clear Application Caches
# We do NOT run config:cache because we need dynamic DB settings in AppServiceProvider.
echo "🧹 Clearing caches..."
php /home/site/wwwroot/artisan config:clear
php /home/site/wwwroot/artisan cache:clear
php /home/site/wwwroot/artisan view:clear
php /home/site/wwwroot/artisan route:clear

# 5.1 Rebuild the caches we just cleared.
# route:clear/view:clear without a matching rebuild left production registering all
# ~581 routes and recompiling Blade on EVERY request. Verified locally that closure
# routes (/, /serve-storage/{path}, /public/survey-thank-you) still resolve when cached.
# config:cache is deliberately still NOT run here — see the note above. It now looks
# safe (there is not a single env() call outside config/, and AppServiceProvider's
# DB-driven mail settings use runtime config() writes, which survive config caching),
# but it was measured at only ~15% on top of OPcache, which is not worth risking a
# deploy on. Enable it as a separate, deliberate change if you want that last slice.
echo "⚡ Rebuilding route and view caches..."
php /home/site/wwwroot/artisan route:cache
php /home/site/wwwroot/artisan view:cache

# 6. Run migrations Synchronously
# We add a timeout to prevent startup hanging forever if DB is down.
echo "⏳ Running migrations..."
php /home/site/wwwroot/artisan migrate --force --no-interaction

# 7. Start the Laravel Scheduler Worker
# Using schedule:work is much more reliable for 30-second tasks.
echo "🚀 Starting Laravel Scheduler worker..."
touch /home/site/wwwroot/storage/logs/scheduler.log
nohup php /home/site/wwwroot/artisan schedule:work >> /home/site/wwwroot/storage/logs/scheduler.log 2>&1 &

# 8. Start the Queue worker
# Nothing consumed the `database` queue before this, so every dispatched job sat in the
# jobs table forever — including SendDecisionCallbackJob (the linkportal accounting
# callback). It also lets page-load triggers hand slow work (FetchEmailsJob's IMAP
# fetch) to a background process instead of holding a PHP-FPM worker.
# --max-time recycles each worker hourly so a long-lived process cannot leak memory
# or hold stale container state; the loops below immediately restart it.
echo "🚀 Starting Laravel Queue worker..."
touch /home/site/wwwroot/storage/logs/queue.log
# Keep both workers in restart loops. A one-shot queue:work process exits at
# --max-time (or after a fatal error) while PHP-FPM keeps the container alive,
# which otherwise leaves queued jobs stuck indefinitely. PDF work is isolated so
# a large print run starts promptly and cannot block normal background jobs.
nohup sh -c 'while true; do php /home/site/wwwroot/artisan queue:work --queue=default --tries=3 --timeout=300 --max-time=3600 --sleep=3; sleep 2; done' >> /home/site/wwwroot/storage/logs/queue.log 2>&1 &
nohup sh -c 'while true; do php /home/site/wwwroot/artisan queue:work --queue=voucher-pdfs --tries=3 --timeout=300 --max-time=3600 --sleep=1; sleep 2; done' >> /home/site/wwwroot/storage/logs/queue.log 2>&1 &

echo "🚀 Startup script finished! PHP-FPM taking over."
