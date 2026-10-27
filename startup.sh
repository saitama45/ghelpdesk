#!/bin/bash

# 1. Map the Nginx root to /public, and keep nginx DOWN until PHP-FPM can answer
# Azure moves traffic to a new container as soon as its port responds; until then the
# previous container keeps serving. With nginx up from the start, the 2026-10-27
# restart answered at 02:04:50 but PHP-FPM only started at 02:05:54, after this
# script: 57 requests in between got "connect() failed", a 502 for the user.
# Step 10 starts nginx again once PHP-FPM is listening.
echo "🔨 Configuring Nginx..."
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default
service nginx stop

# 2. Ensure critical storage directories exist (Fixes "View path not found")
echo "📂 Creating storage subdirectories..."
mkdir -p /home/site/wwwroot/storage/app/public
mkdir -p /home/site/wwwroot/storage/framework/cache/data
mkdir -p /home/site/wwwroot/storage/framework/sessions
mkdir -p /home/site/wwwroot/storage/framework/views
mkdir -p /home/site/wwwroot/storage/logs

# 2.1 Create storage symlink
# Only when it is missing or broken (the same test storage:link makes itself). It
# lives on /home, so it survives restarts, and each artisan call costs ~5s here.
if [ ! -e /home/site/wwwroot/public/storage ]; then
    echo "🔗 Creating storage symlink..."
    php /home/site/wwwroot/artisan storage:link
fi

# 3. Fix permissions: moved to step 9, which runs in the background

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
; Never re-check source files. /home/site/wwwroot is Azure's NETWORK storage, not local
; disk, and with validate_timestamps=1 / revalidate_freq=60 PHP re-stat()ed every
; compiled file over that share once a minute. The production log matched it: every
; ~61s, every request in flight froze ~3s and finished together, 401s included.
; Code only changes on deploy, and every deploy restarts the container (that is also
; how this script's migrations run), so the restart is what clears the cache.
opcache.validate_timestamps=0
; Same reason: each FPM worker re-resolved its include paths (composer's '/..' paths
; included) over the share every 120s, the default TTL.
realpath_cache_size=4096K
realpath_cache_ttl=3600
EOF

# 4.3 Name the route and its duration in the PHP-FPM access log
# The default format logs every request as `"GET /index.php" 200`, which cannot say
# which page was slow. REQUEST_URI is the original URL (query string included, so
# search terms show up in the log stream); %{mili}d is the time PHP spent on it.
# Only tokens documented in php-fpm's sample www.conf are used, because a bad pool
# config would stop PHP-FPM from starting at all.
cat <<'EOF' > /usr/local/etc/php-fpm.d/zz-access-log.conf
[www]
access.format = "%R - %u %t \"%m %{REQUEST_URI}e\" %s %{mili}d ms %{megabytes}M MB %C%%"
EOF

# 5. Clear Application Caches
# We do NOT run config:cache because we need dynamic DB settings in AppServiceProvider.
# Each artisan call boots the framework from the network share (~5s each in the
# 2026-10-27 log), so no call here may be redundant:
# - config:clear only deletes bootstrap/cache/config.php, which exists only if someone
#   ran config:cache, so it is skipped when there is nothing to clear.
# - route:clear and view:clear are not called: route:cache and view:cache (step 5.1)
#   run them first.
# cache:clear on the redis store empties the WHOLE Redis database the cache uses
# (flushdb, not just the cache's keys). Sessions are in Redis too (SESSION_DRIVER=redis,
# database REDIS_DB), so REDIS_CACHE_DB must differ from REDIS_DB. With both 0, every
# restart logged everyone out (2026-10-27).
echo "🧹 Clearing caches..."
if [ -f /home/site/wwwroot/bootstrap/cache/config.php ]; then
    php /home/site/wwwroot/artisan config:clear
fi
php /home/site/wwwroot/artisan cache:clear

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

# 9. Fix permissions, in the background
# This step took 18s in the 2026-10-27 log: the recursive chmod walks every file under
# storage over the network share. Permissions on /home persist across restarts, so the
# site need not wait for it, and running it after the artisan steps now also covers
# the files those steps just created.
echo "🔐 Setting storage permissions (in the background)..."
nohup sh -c 'chmod -R 777 /home/site/wwwroot/storage; chmod -R 775 /home/site/wwwroot/bootstrap/cache' > /dev/null 2>&1 &

# 10. Start nginx as soon as PHP-FPM is listening
# PHP-FPM is started by the App Service startup command right AFTER this script
# ('bash startup.sh; php-fpm;'), so wait for its port in the background. bash, not
# sh: /dev/tcp is a bash feature. If PHP-FPM is still not up after two minutes,
# start nginx anyway, so the container shows its error instead of never answering.
nohup bash -c '
    for i in $(seq 1 600); do
        (: < /dev/tcp/127.0.0.1/9000) 2> /dev/null && break
        sleep 0.2
    done
    if (: < /dev/tcp/127.0.0.1/9000) 2> /dev/null; then
        echo "🌐 PHP-FPM is listening, starting nginx..."
    else
        echo "⚠️ PHP-FPM is still not listening after 2 minutes, starting nginx anyway..."
    fi
    service nginx start
' &

echo "🚀 Startup script finished! PHP-FPM taking over."
