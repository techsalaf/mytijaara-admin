# WhatsApp hardening and expansion audit

Starting point: `354c357aa6b7b25be5c64ada2e882e1928f862b9`, fetched with `git pull --ff-only origin main` on 2026-09-13 (already up to date). Clean checkout; implementation branch: `codex/whatsapp-hardening-expansion`.

## Verified architecture and priority findings

Laravel 12 with nwidart modules, Eloquent/MySQL spatial zones, Guzzle transport and laravel/ai. The module provider loads its routes directly. `bootstrap/app.php` defines `api` as bindings only. Admin identity uses the **session** `admin` guard; Passport authenticates customers, not administrators. Admin access additionally checks login state/session token and role modules via `Helpers::module_permission_check`.

Inbound HMAC verification reaches `ProcessIncomingWhatsAppMessage`, then contact/conversation/message persistence, media jobs and `ConversationManager`. Onboarding is deterministic; approved vendor turns use `RunVendorAiConversation` and 11 tools. Seven module tables store channel state; Vendor, Store, Item and Order are canonical core entities. Sharing models is not equivalent to sharing business rules: registration and mutations currently duplicate controller logic.

Verified critical defects at the starting commit:

1. All `/api/v1/whatsapp/*` operations are public, including an HMAC signing oracle. Session responses also serialize credential material in collected data.
2. Status and legacy Flow dispatch helpers return undispatched job instances. Statuses are not inbound messages. Message status updates have no `failed` match arm or provider timestamps.
3. Event lookup uses `session_id`, while the migration and relationship use `onboarding_session_id`.
4. Credential lookup scans the latest 50 sessions after a cache miss. Check, password save and token invalidation are not atomic. Only POST is throttled. URL generation uses the request URL generator. POST calls Meta synchronously.
5. Rejected chat passwords already reside in message `content`, `raw_text`, nested `metadata.meta_value`, and serialized inbound jobs. Gateway failure logging includes full outbound payloads (including credential links).
6. Vendor observer and admin controller dispatch competing approval notifications. Suspension uses Store.status; application approval uses Vendor.status. Neither notification path enforces the service window.
7. LLM boolean confirmations authorize direct Item/Order/Store mutations. Static shop-confirm buttons are replayable. Cancellation spelling differs: core uses `canceled`; the tool uses `cancelled`. Core delivery affects payments, transactions, counters, riders, verification and notifications; the tool only changes a string.
8. Media presence is treated as validation. Document processing can claim verified format for a nonexistent file. KYC is downloaded publicly and copied to public store branding storage. `WhatsAppMedia` also lacks its Storage import.
9. Location can invent Lagos coordinates and silently select zone/module 1 or the first row. Module-zone compatibility is not enforced. Subscription can silently select a package and create a subscription outside core payment handling.
10. History includes the current inbound message again. Persisted text is a string at `content.text`, which the AI extractor fails to handle. Short substring language detection misclassifies English. Dashboard menu methods pass stdClass into a job requiring WhatsAppMessage.
11. Human handoff can be overridden by onboarding/global-button routing; queued AI work does not check ownership state after refresh. Rate-limit configuration is declarative only. Scheduled cleanup/stuck commands do not exist. Queue retry_after is 90s but inbound/document jobs allow 120s. The inbound cache lock is 30s and never explicitly released.

## Canonical application parity

`app/Http/Controllers/VendorController.php::store`, `secondStep`, `business_plan`, module discovery and payment methods are the reference, together with `resources/views/vendor-views/auth/general-info.blade.php`. The UI requires **cover photo and last name** even though server validation permits/omits them. UI terms acceptance has no named input. Password confirmation and image aspect ratios are not enforced by the current server validator. Earlier docs claiming these are already canonical server rules are incorrect.

Current WhatsApp `OnboardingSession::getSteps()` has **16 states including welcome**, not seven. Config lists an older eight-state sequence. WhatsApp lacks required cover photo capture, rental pickup zones, explicit eligible package selection and payment continuation. Review says a logo is square-verified without checking bytes. Existing web creates business model `none` until plan/payment selection; WhatsApp diverges. Registration should become a shared application service with channel-specific bot protection, validated credentials, and the complete UI requirements. Never resolve parity by removing required web fields.

## Core reuse map

See `whatsapp-vendor-core-capability-matrix.md`. This is a source audit, not evidence of live Meta behavior or a completed security certification. Existing documentation's claims of complete implementation/testing and no critical issues are withdrawn by these findings. Detailed extraction and parity tests must precede replacing each core workflow.

## Implementation order and release gates

1. Contain public API access; repair receipts/event retrieval; isolate regression tests.
2. Dedicated atomic credential records, redaction before queue persistence, trusted HTTPS origin, queued continuation.
3. Single status-event producer, durable notification deduplication, centralized service-window policy.
4. Private document service and migration of existing public KYC; validated async media continuation.
5. Deterministic action confirmations and canonical mutation services, then enforced resource budgets.
6. Complete canonical onboarding (including cover, rental, payments); refactor read queries/language/history.
7. Core-backed launch, photo drafts, operational preferences and support cases.
8. Isolated automated tests, MySQL concurrency/spatial checks, sandbox delivery and deployment evidence.

Baseline isolated execution: `DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array QUEUE_CONNECTION=sync php artisan test Modules/WhatsAppVendorConcierge/tests --stop-on-failure` failed (10 failed, 42 pending): existing tests do not provision tables. PHPUnit also reports obsolete XML/doc-comment metadata. This is not a product pass/fail result. No live database migration or external account reconfiguration was performed.

Release assessment at starting commit: **NO-GO** for expansion/production certification. Existing configured services are assumed configured as instructed; remaining work is code and evidence, not a request to repeat setup.
