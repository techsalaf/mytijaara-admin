#!/bin/bash
set -euo pipefail

# Shared-hosting worker: cron starts it every minute; flock prevents overlap.
APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${WHATSAPP_WORKER_PHP:-/usr/local/bin/php}"
cd "$APP_ROOT"
exec /usr/bin/flock -n "$APP_ROOT/storage/framework/whatsapp-worker.lock" \
    "$PHP_BIN" artisan queue:work database \
    --queue=whatsapp.process_incoming,whatsapp.send_message,whatsapp.run_ai_conversation,whatsapp.process_media,whatsapp.process_document,whatsapp.process_onboarding \
    --sleep=1 --tries=3 --timeout=120 --max-time=55 --memory=192
