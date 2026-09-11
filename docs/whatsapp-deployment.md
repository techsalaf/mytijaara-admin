# WhatsApp Vendor Concierge — Production Deployment Guide

Instructions for configuring servers, queue workers, process supervisors, and web servers for the WhatsApp Vendor Concierge module.

---

## 1. Prerequisites
- PHP 8.2 or 8.3 with extensions: `curl`, `mbstring`, `openssl`, `json`, `fileinfo`, `gd`/`imagick`, `pdo_mysql`.
- MySQL 8.x with spatial extensions.
- Redis (strongly recommended for production queues) or MySQL database queue.
- Valid SSL certificate (HTTPS is mandatory for Meta webhook validation).

---

## 2. Environment Configuration
Add the following configuration blocks to `.env`:

```env
# Meta WhatsApp Cloud API
WHATSAPP_API_VERSION=v21.0
WHATSAPP_PHONE_NUMBER_ID=your_phone_id
WHATSAPP_BUSINESS_ACCOUNT_ID=your_waba_id
WHATSAPP_ACCESS_TOKEN=your_permanent_system_user_token
WHATSAPP_APP_ID=your_app_id
WHATSAPP_APP_SECRET=your_app_secret
WHATSAPP_VERIFY_TOKEN=your_secure_random_string

# AI Concierge Provider
WHATSAPP_AI_PROVIDER=openai
WHATSAPP_AI_MODEL=gpt-4o
OPENAI_API_KEY=sk-...

# Queue Connection
QUEUE_CONNECTION_WHATSAPP=database
```

---

## 3. Database Migrations
Run the module migrations:

```bash
php artisan module:migrate WhatsAppVendorConcierge
```

To verify:
```bash
php artisan module:migrate-status WhatsAppVendorConcierge
```

---

## 4. Supervisor Configuration (Queue Workers)
The webhook controller responds to Meta in <200ms and pushes all processing to queue workers. Ensure worker processes are continuously managed via Supervisor.

Create `/etc/supervisor/conf.d/mytijaara-whatsapp-worker.conf`:

```ini
[program:mytijaara-whatsapp-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mytijaara/artisan queue:work database --queue=whatsapp.process_incoming,whatsapp.send_message,whatsapp.run_ai_conversation,whatsapp.process_media,whatsapp.process_document --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/mytijaara/storage/logs/supervisor-whatsapp.log
stopwaitsecs=3600
```

Apply supervisor configuration:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mytijaara-whatsapp-worker:*
```

---

## 5. Nginx Configuration
Ensure Nginx passes request headers and request body without truncation:

```nginx
location /webhooks/whatsapp {
    try_files $uri $uri/ /index.php?$query_string;
    proxy_set_header X-Hub-Signature-256 $http_x_hub_signature_256;
    proxy_read_timeout 60s;
    client_max_body_size 25M;
}
```

---

## 6. Verification and Health Check
1. **Endpoint Health Check:**
   ```bash
   curl -i https://dashboard.mytijaara.com/webhooks/whatsapp/health
   ```
   Expect HTTP 200 with JSON status `ok`.

2. **Meta Verification Simulation:**
   ```bash
   curl -i "https://dashboard.mytijaara.com/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=your_verify_token&hub_challenge=1158201446"
   ```
   Expect HTTP 200 with raw text `1158201446`.

3. **Queue Health:**
   ```bash
   php artisan queue:monitor database:whatsapp.process_incoming,database:whatsapp.send_message
   ```
