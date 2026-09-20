#!/bin/bash
set -euo pipefail

# Called from the reviewed incoming package. One SSH session owns both locks
# until code, caches and migrations are coherent. Never starts a worker itself.
APP_ROOT="$1"
JOURNAL="$2"
INCOMING="$3"
MIGRATE="$4"
SEED="$5"
cd "$APP_ROOT"
exec 9>"$APP_ROOT/storage/framework/code-release.lock"
/usr/bin/flock -x -w 240 9
php artisan down --retry=60
php artisan queue:restart
# Also drain the pre-isolation cron worker, which only knows this older lock.
exec 8>"$APP_ROOT/storage/framework/whatsapp-worker.lock"
/usr/bin/flock -x -w 240 8

rollback_code() {
    trap - ERR
    php "$INCOMING/scripts/safe-code-release.php" rollback "$APP_ROOT" "$JOURNAL" || true
    echo 'Release failed. Maintenance remains enabled; inspect code journal and schema before recovery.' >&2
}
trap rollback_code ERR
php "$INCOMING/scripts/safe-code-release.php" apply "$APP_ROOT" "$JOURNAL"
if [ "$MIGRATE" = 'true' ]; then
    php artisan migrate --force
    php artisan module:migrate --all --force
fi
if [ "$SEED" = 'true' ]; then
    php artisan db:seed --force
fi
php artisan optimize:clear
php artisan queue:restart
php artisan up
trap - ERR
