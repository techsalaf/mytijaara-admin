# WhatsApp Vendor hardening implementation report

Starting commit: `354c357aa6b7b25be5c64ada2e882e1928f862b9`. Audit commit: `14906a71`.

## Implemented in the current branch

The internal operations API now requires a current session-admin identity plus existing Store and Contact Messages permissions. Public callers and customer API tokens cannot browse conversations, onboarding data, or send messages. The signing diagnostic is restricted to local/testing and no longer returns a calculated HMAC.

Inbound webhook work is encrypted in the queue. Credential-step input is redacted before it is serialized, stored as a message, attached to metadata, or logged as an event. Credentials use hashed, revocable database tokens with a 15-minute expiry, one successful consumption, no-store/no-referrer pages, CSRF and throttling. HTTPS continuation is queued after commit.

Meta status receipts use a distinct job. They do not create contacts or inbound messages, preserve provider timestamps, accept failed states, resist duplicated/out-of-order receipts, and retain only error codes. Onboarding events now query `onboarding_session_id`.

Location, module, zone and module-zone checks fail closed. Submission no longer assigns default Lagos coordinates, a first zone, or a first module. KYC downloads default to Laravel's private `local` disk; an admin-only document route serves processed private files. Original KYC is no longer copied into the public store asset directory.

AI tools no longer execute direct product/order writes or accept an LLM-provided `confirm` boolean as authorization. Those operations safely direct the vendor to the existing dashboard until canonical mutation services are extracted. Store availability now has a shared core `StoreAvailabilityService`; the WhatsApp path creates a vendor/contact/conversation-bound, random, expiring, one-use pending action that requires the exact Confirm button.

Admin status notification producers converge on `SendVendorStatusNotification`; persistent delivery keys deduplicate observer and controller dispatches. Text is sent only in the service window; otherwise a mapped utility template is used.

## Migrations

- `2026_09_13_000001_create_whatsapp_credential_tokens_table.php`
- `2026_09_13_000002_create_whatsapp_pending_actions_table.php`
- `2026_09_13_000003_create_whatsapp_notification_deliveries_table.php`

## Verification

`DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array php artisan test Modules/WhatsAppVendorConcierge/tests/Hardening --compact` passes: 15 tests, 88 assertions. PHP lint passes for every changed PHP file.

`php artisan route:list --path=whatsapp --except-vendor --json` is blocked by an unrelated pre-existing RideShare binding: `Modules\\RideShare\\Interface\\UserManagement\\Service\\DriverLevelServiceInterface` is missing. It must be repaired separately before a full route-list gate can pass.

## Remaining release blockers

This is not a production GO decision. The remaining work includes extraction of the full web vendor application service; cover photo, rental pickup-zone and explicit subscription payment parity; asynchronous validated-media continuation; core Product/Order mutation services; support cases, launch checklist and insights; full MySQL spatial/concurrency tests; static analysis; and real Meta sandbox/template tests. See `whatsapp-vendor-hardening-and-expansion-audit.md` and the core capability matrix for the verified details.
