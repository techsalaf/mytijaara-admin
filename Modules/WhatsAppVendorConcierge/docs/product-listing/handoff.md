# Product listing repair — 26 September 2026

Starting SHA: `706db67903ea064551a33d8d22c6bdbb82078ad4`. Final deployment SHA and live acceptance are recorded in the task handoff. All implementation changes are module-owned. No mobile/web API contracts, core controllers, or core models changed.

## Proven incident

Read production conversation 5, messages 554–561 and 839–846, media 29, categories, modules, item count, queue/failed-job records and the corresponding September 25 logs through the current production SSH connection. Private contact details and expiring media URLs are intentionally omitted here.

- Menu message 554 selected Add Products; message 555 promised extraction. Image webhook 556 persisted its Meta media ID and media row 29.
- Download completed at 14:34:22 UTC on September 25. Stored file is a valid 14,826-byte JPEG, 375×500. Bytes still pass the existing MediaPolicyService. There is no evidence of expired-token/URL or MIME/size failure in this incident.
- Messages 558 and 560 are distinct vendor messages containing the same labelled answers. The persisted context retained name, description, price 13000 and stock 30. Category was null: only demo categories existed, and “Watches” matched none.
- The old image branch never invoked a vision provider or attached image bytes. General text intent routing did call NVIDIA: an overloaded Nano request fell back successfully to Super. That was not image analysis.
- PhotoToProductService required all four core fields and returned the same generic exception for missing category. ConversationManager retried confirmation on every text reply, including unrelated shop requests. The draft remained awaiting_details; the general AI router ran before draft parsing. The saved partial fields prove persistence worked; no evidence implicates a failed conversation lock.
- Conversation ownership was ai_active. Queue count was zero at inspection; the only two failed jobs were old September 14 HTTP 401 failures, not this listing. Only the pre-existing demo item existed; no actual product had been created. The draft existed only in conversation context.

## New draft workflow

`whatsapp_product_drafts` stores conversation/contact/store/module ownership, status, current step, data, per-field sources, vision status/provider/model/results, errors, message IDs, stalled turns, attention flag, item ID and timestamps. These are conversation drafts, not a replacement item model.

Add Products resumes an existing draft. Legacy photo drafts are recovered without changing their vendor-provided values. Image → name → parent category → optional subcategory → description → conditional store category → applicable unit → price → discount → applicable stock → module fields → variations → optional extra details → additional images → review → explicit Confirm and create.

Valid answers persist immediately. Back, Edit FIELD, Options SEARCH, Examples, Save draft, Resume product, Continue without AI and Cancel are supported. Support remains a separate human-support link. Other dashboard menu actions save the draft so later unrelated text is not captured. Three failed turns flag admin attention and replace the repeated question with recovery choices. Deterministic draft routing runs before general intent AI. Explicit labels and the “This is … I sell it for ₦…” pattern preserve defensible multi-field input; arbitrary prose is not trusted as structured financial data.

Final confirmation locks the draft inside a transaction and calls the existing module `CoreAdapters/ProductMutationService`. It checks ownership, current store/module status, canonical ranges, category hierarchy, subscription limits and owned image bytes. Images use Helpers::upload and review uses the existing ProductReview/TempProduct adapter. Item ID plus transactional draft status makes confirmation idempotent. Message IDs prevent replaying a draft answer.

## Field-capability map (verified against current host)

Sources: config/module.php; Vendor/ItemController::store; admin product partials; ProductMutationService/ProductReview. Host logic is controller-based, not a shared item-create service. The existing maintained adapter is extended rather than invoking a web controller with fabricated authentication/session state.

| Module | Applicable fields |
|---|---|
| All four item modules | Name ≤191, description ≤1000, module category/subcategory, own store category where configured, price within Helpers::getDecimalPlaces()…999999999999.999, discount below price, review/status, tags, primary/additional images |
| Grocery | Required photo; unit, stock; up to three existing attributes and 30 combinations with per-variant price/stock; optional brand, organic, nutrition/allergy labels |
| Shop/ecommerce | Required photo; unit, stock; inventory attributes/variations; optional brand |
| Food | Photo optional; vegetarian flag, daily availability, owned add-ons; up to three food option groups with required/min/max constraints and option prices; no stock/unit assumptions; optional nutrition/allergy labels |
| Pharmacy | Required photo; unit, stock, inventory variations; explicit prescription flag; optional common condition, basic flag, manufacturer, unit value, generic name |
| Parcel, Rental, Ride-share, Service | Excluded from the Item listing workflow; use their canonical module screens |

Tax choices are shown only for the host's active product-wise tax mode and contain existing tax IDs/rates. No rates, commission, delivery charge, legal policy or financial default is seeded. Discounts in the chat wizard are percentages; the canonical adapter continues to support amount discounts through its existing callers. Full dashboard remains available for videos and advanced fields not collected in this chat flow. These are known channel limits, not silent data defaults or changed API contracts.

## Vision verification

Installed laravel/ai source includes attachment mapping in OpenAI/Gemini/Groq gateways and AgentPrompt::withAttachments. That package capability alone does not prove any chosen model supports vision. The product extraction service currently permits the explicitly verified, enabled NVIDIA `nvidia/nemotron-3-nano-omni-30b-a3b-reasoning` at its official HTTPS endpoint, using the existing encrypted connection credential. No key or image bytes are logged.

Official API evidence: https://docs.nvidia.com/nim/vision-language-models/1.7.0/examples/nemotron-3-nano-omni-30b-a3b-reasoning/api.html and https://build.nvidia.com/nvidia/nemotron-3-nano-omni-30b-a3b-reasoning/modelcard . Explicit image_url content is supported. A synthetic red-shape live request returned HTTP 200 and “A large red rectangle is centered on a white background.” Usage: 291 input + 13 output = 304 tokens. This proves real image transport/understanding for that model; it does not prove the full WhatsApp journey.

Validated media bytes are attached as a MIME-specific data URL. Extraction is bounded to name, description, canonical category ID and confidence. JSON/schema failure, invented category, transport error or absent model falls back to manual steps. Price, stock, prescription and inventory never come from extraction. Suggestions remain unconfirmed until the vendor supplies the value; even high confidence cannot create a category or publish a product. Product-specific vision metadata is visible in the admin draft page.

## Taxonomy and lookup import

Dataset: resources/data/launch-taxonomy.json, 76 category/subcategory records: Grocery 23, Shop 27, Food 16, Pharmacy 10. Parcel/Rental skipped. The importer uses a module/stable-key mapping table plus exact scoped parent/name matching for existing records, preserving IDs and existing images/status/names. Naming conflicts abort an apply transaction. Cross-module records cannot be reused. Reruns match previously imported IDs.

Commands (dry run is the default):

```sh
php artisan whatsapp:launch-taxonomy --lookups --manifest=storage/app/private/maintenance/launch-taxonomy-preview.json
php artisan whatsapp:launch-taxonomy --validate-only --module=3
php artisan whatsapp:launch-taxonomy --apply --lookups --manifest=storage/app/private/maintenance/launch-taxonomy-applied.json
```

`--lookups` includes seven unit labels and three attribute names, not invented variation inventory. Before apply the importer exports existing categories, category translations, stable-key mappings, units and attributes to a private timestamped backup. Mutations use a transaction and cache lock; the successful import is audited. No truncation/deletion/rename/overwrite. New categories use the existing schema-required `def.png` sentinel, not generated images. Admin Operations Centre → Product drafts & missing category images lists missing images. Images still require manual completion before visual launch readiness.

## Other launch data (production inspection)

| Finding | Classification / action |
|---|---|
| Units 0; attributes 0 | Recommended before launch; verified lookup import prepares common labels |
| Categories only 4 demo records | Required before launch; additive taxonomy import |
| Add-ons 0; store categories 0; tags 0 | Optional/store-specific; vendors create actual applicable data, no fake defaults |
| Order cancellation reasons 0 | Requires business decision before launch; Admin → Business settings → Orders → cancellation reasons |
| Refund reasons 5 | Already configured; review their wording/policy, do not overwrite |
| Zones 7 (6 active), module-zone mappings 34 | Configured; business must verify geographic coverage; no spatial edits |
| Tax and system-tax setup counts 0 | Business decision; Admin → Tax module settings; do not assume zero tax is legally correct |
| New-product approval flag enabled | Configured; confirm product-approval mail/status switch and moderation workflow during acceptance |
| Notification settings 63 | Configured; each real channel/template still needs its normal delivery verification |
| Store-managed delivery enabled; order confirmation deliveryman; no platform riders | Review before switching delivery policy off; existing external store delivery path retained |
| WhatsApp proactive recovery | Approved, purpose-appropriate template and opt-in are still needed outside the reply window; no unrelated template substituted |
| Commission/payout/legal settings | Unsafe to auto-create; owner review in normal business/payment settings |

## Admin and operations

`/admin/whatsapp/product-drafts` is protected by the existing admin/session/store + contact-messages permissions. Draft details include progress, image, source attribution, errors, vision and recent messages. Open Inbox provides the existing human assignment controls. Confirmed resume/re-send actions require an open reply window and active AI ownership. Cancel preserves audit history. Admin cannot create a product or edit canonical fields around vendor confirmation.

Migration: `2026_09_26_200000_create_product_listing_drafts.php` creates only whatsapp_product_drafts and whatsapp_taxonomy_keys. Deploy through the normal gated workflow, run module migrations, clear/rebuild configuration and restart/drain workers through the existing release script. No destructive down migration is appropriate in production.

Rollback: preserve both new tables and import backups. Revert module code to the starting SHA via the normal release process after worker drain. Keep canonical category records once vendors can reference them; do not automatically delete imported IDs. Use the manifest to assess references before any reviewed manual taxonomy reversal. Keep drafts/audits for recovery. Do not reset the production database.

## Validation / handoff

Commands used:

```sh
php vendor/phpunit/phpunit/phpunit Modules/WhatsAppVendorConcierge/tests/Hardening/ProductListingFlowTest.php
ISOLATION_MYSQL=1 php vendor/phpunit/phpunit/phpunit Modules/WhatsAppVendorConcierge/tests/Integration/MySqlProductListingTest.php
DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array ISOLATION_MYSQL=1 php vendor/phpunit/phpunit/phpunit Modules/WhatsAppVendorConcierge/tests
ISOLATION_MYSQL=1 php vendor/phpunit/phpunit/phpunit tests/Architecture
php scripts/check-core-boundary.php HEAD
```

SQLite workflow tests and disposable loopback MariaDB test passed the original recovery/create/duplicate confirmation, module mapping, image-byte request and taxonomy idempotency checks. Later expanded regression and CI results belong in the final handoff. Existing media/webhook/security suites cover type/size/download/receipt authorization; mocked HTTP tests do not substitute for the live image test above.

Final acceptance must include the owner using Tijaara Mart on real WhatsApp: Add Products → recovered draft → valid canonical category/subcategory → remaining fields → review → Confirm and create → verify item ID and moderation state in dashboard. Owner has agreed to run this after deployment. Until that happens, do not label end-to-end WhatsApp acceptance complete.


Additional module audit: Rental uses `vehicle_categories` and `vehicle_brands`, not Item categories; both are empty. Its provider controller requires brand/category, model/type, seating, fuel/transmission and a vehicle thumbnail. Complete these through Rental administration if Rental is included in launch. Parcel has zero `parcel_categories`; those records include per-kilometre/minimum charges, so do not auto-populate them without an owner pricing decision. The launch Item taxonomy intentionally does not write either schema.

Verified local full module result: 204 tests / 1,095 assertions passed on PHP 8.5.9 with disposable MariaDB enabled. Existing PHPUnit deprecations remain. Four additional vision failure tests and final focused tests are reported separately; CI uses PHP 8.3/MySQL 8.

Final local checks: host 37 tests / 199 assertions; final workflow + vision checks 14 tests / 66 assertions; core-boundary check passed; both new admin Blade templates compile and pass PHP lint. Tests use mocks except the explicitly documented NVIDIA smoke and disposable MariaDB integration. No real WhatsApp acceptance claim is made before the owner test.

Concurrency follow-up: busy draft inputs are deferred as `ResumeProductDraftMessage` on the existing incoming-message worker queue, independently of webhook receipt deduplication. The stored reply is retried with bounded backoff; changed step/ownership flags admin attention rather than applying an old answer to a new question. Images persist before the external vision call.

## Deployed production evidence

Implementation SHA: `a5749796c9964ee2a1dff55e0bf24bada18b75fe` (main repair: `938ed5e03c3573c906563f07ebf163b766fdace6`). GitHub deployment run [36263716628](https://github.com/techsalaf/mytijaara-admin/actions/runs/36263716628) passed Gate Tests, isolation and deployment. PHP 8.3/MySQL 8 CI: 209 module tests / 1,110 assertions; 37 host tests / 199 assertions; core-boundary passed. Existing PHPUnit deprecation notices remain. Production bootstraps successfully and the deferred-reply job class is present. At final inspection: zero queued jobs, two historical failed jobs described above.

Production import: 76 categories/subcategories, seven units and three attributes created (86 total), two unsupported modules skipped, no conflicts. Repeated dry run matched all 86 records. Total categories are now 80, including the four preserved existing records. Private manifests: `storage/app/private/maintenance/launch-taxonomy-preview.json`, `launch-taxonomy-applied.json` and `launch-taxonomy-repeat.json` in the same directory. Pre-import backup on the local storage disk: `private/maintenance/taxonomy-20260926-194515-PwkOQNrX.json`. There are 78 category records with missing/default images; complete their artwork manually. Units/attributes/category deficits in the earlier inspection table are now remediated; the other business decisions remain outstanding.

Recovered Tijaara Mart draft ID 1 is active at `category_id`, retaining Thinkplus Ear Pod 3, price 13000, stock 30 and the original owned image. No product was created on the owner's behalf. The actual product image was submitted to the verified vision service; NVIDIA returned HTTP 503 on the first attempt and one controlled retry. The earlier live synthetic-image test passed, but successful extraction of this specific product image is not claimed. Manual listing remains available.

The owner has been asked to run the real WhatsApp acceptance journey against this release. Its completion, canonical item ID and moderation result remain pending; automated tests do not close that acceptance criterion. Admin templates were compiled/linted but have not received authenticated browser visual acceptance. No unconditional production GO is claimed while these checks and required launch decisions remain outstanding.

For the exact changed-file inventory use `git diff --name-status 706db67903ea064551a33d8d22c6bdbb82078ad4 a5749796c9964ee2a1dff55e0bf24bada18b75fe`. All 23 paths are inside `Modules/WhatsAppVendorConcierge`; no core source file changed.
