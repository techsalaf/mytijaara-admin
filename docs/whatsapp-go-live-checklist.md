# WhatsApp Vendor Concierge — Go-Live Checklist & Pre-Flight Manual

Complete operational checklist and prerequisite guide before enabling the WhatsApp Vendor Concierge in production.

---

## 1. Operational Checklist

### A. Meta Developer Setup
- [ ] Meta Developer Account created at [developers.facebook.com](https://developers.facebook.com).
- [ ] App of type **Business** created (`MyTijaara Vendor Concierge`).
- [ ] WhatsApp product added to the app.
- [ ] App Mode switched from **In Development** to **Live**.

### B. Meta WhatsApp Business Account (WABA)
- [ ] Meta Business Portfolio verified with official business documents (CAC Registration, utility bill).
- [ ] Payment method attached to WABA for conversation billing beyond the 1,000 monthly free tier.
- [ ] Two-factor authentication enabled on all Meta Business Manager admin accounts.

### C. Phone Number
- [ ] Dedicated SIM card or virtual number acquired specifically for MyTijaara WhatsApp Business.
- [ ] Number is NOT registered on regular WhatsApp or WhatsApp Business mobile app (must be deregistered from mobile client before connecting to Cloud API).
- [ ] Phone number verified via SMS/voice OTP in the Meta App Dashboard.
- [ ] Verified business display name approved by Meta (must match CAC/brand name).

### D. API Credentials
- [ ] System User with `Admin` role created in Meta Business Manager.
- [ ] Permanent System User Token generated with `whatsapp_business_messaging` and `whatsapp_business_management` (never expires).
- [ ] `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APP_ID`, and `WHATSAPP_APP_SECRET` populated in `.env`.
- [ ] `WHATSAPP_VERIFY_TOKEN` generated as a secure random 32+ character string and set in `.env`.

### E. AI Service
- [ ] OpenAI or Anthropic API key generated with billing enabled.
- [ ] Set `WHATSAPP_AI_PROVIDER=openai` (or `anthropic`) in `.env`.
- [ ] Set monthly usage limit / spend cap on OpenAI/Anthropic console to avoid surprise billing spikes.

### F. Server Infrastructure
- [ ] Queue connection configured (`QUEUE_CONNECTION_WHATSAPP=database` or `redis`).
- [ ] Supervisor process supervisor installed and running for `whatsapp.*` queues.
- [ ] PHP memory limit set to at least 256M.
- [ ] `storage/app/public` symlinked to `public/storage` via `php artisan storage:link`.

### G. Domain & Network
- [ ] Valid SSL certificate active on `https://dashboard.mytijaara.com`.
- [ ] Webhook URL `https://dashboard.mytijaara.com/webhooks/whatsapp` verified in Meta App Dashboard.
- [ ] Subscribed to `messages` and `message_template_status_update` webhook fields.

### H. Laravel Commands
Execute the following on the production server:
```bash
# 1. Run module migrations
php artisan module:migrate WhatsAppVendorConcierge

# 2. Clear and optimize configuration & routes
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3. Restart queue workers
php artisan queue:restart
```

### I. WhatsApp Flows & Templates
- [ ] Template `vendor_application_approved` submitted in Meta WhatsApp Manager for utility messaging.
- [ ] Template `vendor_application_denied` submitted in Meta WhatsApp Manager.
- [ ] Template `vendor_onboarding_welcome` approved.

### J. End-to-End Testing Scenarios
- [ ] **Scenario 1 (New Vendor):** Send "Hello" from an unregistered test phone. Confirm welcome message and interactive menu.
- [ ] **Scenario 2 (Onboarding):** Complete all 7 onboarding steps. Verify pending `Vendor` and `Store` records are created in MyTijaara admin.
- [ ] **Scenario 3 (Admin Approval):** Approve the test store in MyTijaara Admin Panel. Confirm approval notification arrives on WhatsApp.
- [ ] **Scenario 4 (Admin Denial):** Reject an application with note. Confirm denial message arrives with the exact rejection note.
- [ ] **Scenario 5 (AI Concierge):** As approved vendor, message "Show my products" and "Pause my shop". Confirm confirmation dialog.
- [ ] **Scenario 6 (Human Support):** Message "Talk to support". Confirm support ticket reference and admin alert email dispatch.
- [ ] **Scenario 7 (Idempotency):** Replay an identical webhook POST. Confirm duplicate is ignored without side effects.

### K. Production Launch
- [ ] Remove test numbers from Meta App Dashboard test recipient list.
- [ ] Update public marketing materials, QR codes, and vendor landing page with the WhatsApp link: `https://wa.me/{YOUR_PHONE_NUMBER}?text=I%20want%20to%20sell%20on%20MyTijaara`.
- [ ] Announce WhatsApp onboarding to pilot vendor group.

---

## 2. Things Rasheed Needs To Know Before Going Live

### 1. The 24-Hour Customer Service Window Rule (Crucial)
- When a vendor sends a message to your WhatsApp number, Meta opens a **24-hour conversation window**.
- Within this 24-hour window, you can send **free-form text, interactive buttons, lists, and images** without paying template fees.
- Once 24 hours elapse with no incoming message from the vendor, the window closes.
- **Consequence:** If an admin takes more than 24 hours to review an application in the admin panel, the system cannot send a free-form WhatsApp approval message. It **must** use an approved Meta Message Template. Ensure your approval templates are pre-approved in Meta WhatsApp Manager.

### 2. WhatsApp Number Quality Rating & Blocking
- Meta assigns every WhatsApp Business number a **Quality Rating** (Green = High, Yellow = Medium, Red = Low).
- If vendors block your number or report it as spam, your rating drops to Red, and Meta may restrict your outbound messaging limits from 1,000 to 250 per day or suspend the account.
- **Rule:** Never use this WhatsApp number for unsolicited cold promotional blasts. Only send messages related to their shop onboarding, orders, and direct support.

### 3. Nigerian Data Protection Regulation (NDPR) Compliance
- Vendor business certificates (CAC), utility bills, and personal identity cards collected via WhatsApp constitute personal data under NDPR.
- **Storage:** Stored in `storage/app/public/whatsapp/`. Ensure directory permissions are restricted to `www-data` and not directory-browsable.
- **Retention:** Run `php artisan whatsapp:cleanup-media` periodically to purge media files older than 90 days once verification is complete.

### 4. Meta Business Verification Document Matching
- When verifying your Meta Business Portfolio, the legal business name and address in Meta **must match the CAC certificate and bank statement exactly**, character for character. A single mismatched abbreviation will cause Meta review rejection.

### 5. AI Spend & Token Budgets
- An open LLM prompt can be exploited if an abusive user loops rapid complex messages.
- Always set a hard monthly billing spend cap in your OpenAI or Anthropic developer console (e.g. $25/month). The module includes rate limiting per phone number, but cloud caps are your ultimate safety net.

### 6. Queue Worker Failure Recovery
- If your queue worker crashes or your server restarts, inbound messages will queue up in the `jobs` database table.
- Use Supervisor to guarantee automatic restarts, and periodically run `php artisan queue:failed` to ensure no unprocessed vendor messages are stranded.
