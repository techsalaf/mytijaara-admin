# WhatsApp Vendor Concierge production runbook

## Deploy

1. Set `APP_URL` to the HTTPS dashboard origin. Credential links refuse any non-HTTPS production origin.
2. Set the WhatsApp values in `.env`; retain `WHATSAPP_MEDIA_DISK=local` unless an equally private object-store disk is configured.
3. Configure and approve the four status templates in `whatsapp-vendor-meta-template-register.md`.
4. Run `php artisan module:migrate WhatsAppVendorConcierge`, then `php artisan config:cache` and `php artisan queue:restart`.
5. Run workers for `whatsapp.process_incoming`, `whatsapp.send_message`, `whatsapp.process_media`, `whatsapp.process_document`, `whatsapp.process_onboarding`, and `whatsapp.run_ai_conversation`. Set queue `retry_after` above the longest job timeout (at least 150 seconds) before enabling workers.
6. Schedule `php artisan schedule:run` every minute. The module includes `whatsapp:cleanup-media` and `whatsapp:process-stuck-sessions`; run each with `--dry-run` first after deployment.

## Sandbox acceptance

Test GET verification, valid and invalid HMAC signatures, duplicate message delivery, an outbound status sequence sent→delivered→read, a status receipt arriving before the outbound record, credential expiry/reissue/single use, a pasted chat password, rejected location, a private KYC upload, and an out-of-window approval notification. Confirm a vendor can only operate its own store and each confirmation button can be used once.

## Monitor and recover

Monitor failed jobs, retry count, webhook 401s, template rejections, queue lag, private-disk storage use, status notification failures, and action-expiry rates. Do not replay arbitrary raw webhook data. Replay a failed job only after confirming its message/action ID and its current state. Use the notification delivery table to identify duplicates; never manually resend a status update until the Meta delivery state is understood.

## Rollback

Application rollback is safe only after preserving the new tables. Disable WhatsApp queue workers, deploy the prior application, and leave the added tables intact. Do not run down migrations in production: credential/action/delivery records are audit evidence. Re-enable the prior queue configuration only after checking failed jobs were not left in flight.
