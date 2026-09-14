# Core customization and client compatibility audit

Date: 2026-09-14. Reviewed application HEAD: `b87aa242`.

## Decision

Do not perform a blanket rollback. There are real architectural coupling and behavioral differences to correct, but rolling back the implementation wholesale would also remove approval and notification safeguards. Perform a targeted isolation and compatibility remediation before claiming ecosystem or upstream-update compatibility.

This is a source/history audit, not a production certification. No production state, application implementation, app checkout, or Git history was changed during this audit. The only intended working-tree addition is this report. No app integration tests or upstream upgrade rehearsal were executed in this audit.

## Baseline and limits

`bf5ca49f` is the first full application import found in this repository. An initial WhatsApp module commit, `03805985`, predates that import. Therefore this comparison identifies incremental changes after the full import; it does **not** establish that the imported application was an untouched 6amTech release. The exact licensed release archive is needed for a complete stock-versus-custom comparison.

Git history is the primary evidence for what changed. Searching current code alone cannot recover deleted logic or distinguish stock files from custom ones. These commands reproduce the principal inventory:

```powershell
git diff --name-status bf5ca49f..b87aa242 -- app routes config database bootstrap composer.json composer.lock resources
git diff --numstat bf5ca49f..b87aa242 -- app routes config database bootstrap composer.json composer.lock resources
git log --oneline bf5ca49f..b87aa242 -- app routes config resources
git diff bf5ca49f..b87aa242 -- app/Http/Controllers/VendorController.php
```

Net inventory in those core paths: **18 paths, comprising 7 additions, 10 modifications and 1 deletion; 1,046 added lines and 211 removed lines**. These are net differences, not a claim that every historical intermediate edit remains present.

## Complete net core inventory

Paths below are relative to the admin repository.

| Path | Change (+/- lines) | What changed and exposure |
| --- | --- | --- |
| `app/DTOs/VendorApplicationDTO.php` | Added, 155/0 | Web and WhatsApp registration input mapping; explicitly references WhatsApp session/contact types. |
| `app/Events/VendorApplicationStatusChanged.php` | Added, 33/0 | Approval/denial event and notification version information. |
| `app/Services/VendorApplicationService.php` | Added, 295/0 | Registration validation, vendor/store persistence, media, translations, schedules and email. Called by ordinary web registration and WhatsApp onboarding; directly depends on module media classes. |
| `app/Services/VendorApplicationDecisionService.php` | Added, 44/0 | Transactional admin decisions, row locking, repeat-decision suppression, store/vendor status synchronization, subscription activation and event dispatch. |
| `app/Services/StoreAvailabilityService.php` | Added, 22/0 | Ownership-scoped, locked update of store `active`. Called from vendor API, vendor panel and module actions. |
| `app/Services/ProductMutationService.php` | Added, 284/0 | Custom product writes. Observed callers are module actions/preflight/tests, not the ordinary product controllers. Writes shared item data. |
| `app/Services/OrderMutationService.php` | Added, 159/0 | Custom order transitions and side effects. Observed callers are module actions/preflight/tests, not the ordinary order controllers. Writes shared order data. |
| `app/Http/Controllers/VendorController.php` | Modified, 8/100 | Replaces the public web registration persistence workflow with the DTO/service; retains initial request validation and redirect/error response structure. Largest replacement of existing core logic. |
| `app/Http/Controllers/Admin/VendorController.php` | Modified, 24/22 | Validates and delegates application decisions to the new decision service. |
| `app/Http/Controllers/Api/V1/Vendor/VendorController.php` | Modified, 1/2 | Store open/close operation delegates to the new availability service. Direct store-app API exposure. |
| `app/Http/Controllers/Vendor/BusinessSettingsController.php` | Modified, 1/2 | Vendor-panel open/close operation delegates to the same service. |
| `app/Models/Category.php` | Modified, 2/1 | Adds `name` to mass-assignable fields; name accessor now returns an empty string for null. Shared model behavior. |
| `routes/admin.php` | Modified, 2/1 | Application decisions move to POST; old GET URL redirects to pending applications. Admin integrations using GET must adapt. |
| `resources/views/admin-views/vendor/pending_requests.blade.php` | Modified, 8/5 | Approval/denial controls updated, including POST/CSRF decision forms. |
| `resources/views/admin-views/vendor/deny_requests.blade.php` | Modified, 2/4 | Approval action updated to POST/CSRF form. |
| `resources/views/admin-views/vendor/view/partials/_header.blade.php` | Modified, 4/6 | Application decision controls updated to POST/CSRF forms. |
| `resources/lang/en/messages.php` | Modified, 2/0 | Adds decision-related translations. |
| `config/system-addons.php` | Deleted from tracking, 0/68 | Associated with Rental installation and an ignore rule, commit `050fc3e6`; distinguish from concierge extraction. Git deletion alone does not prove absence on a deployed server. |

Relevant core history: `6cdc4f6c`, `e2d66d8a`, `b02e6149`, `050fc3e6`, `679034e2`, and `46438a69`. The principal service extraction was `679034e2`; recent decision safeguards were `46438a69`. Commit titles are provenance, not evidence that advertised parity or completeness was achieved.

No net changes in this baseline comparison were found in core API route definitions under `routes/api`, core database migrations, Composer manifests/lockfile, bootstrap, `app/Providers/ConfigServiceProvider.php`, or the Store/Vendor/Order/Item model files. This does not imply their behavior cannot be affected by module observers or writes.

Outside the inventory above, changed non-module/non-documentation paths are `.env.example`, `.gitattributes`, `.github/workflows/deploy.yml`, `.gitignore`, `modules_statuses.json`, and `scripts/whatsapp-worker.sh`. Deployment, module enablement, environment examples and worker configuration also belong in an upgrade checklist. The `Modules` tree additionally contains Rental installation changes; these must not all be attributed to concierge development.

## Confirmed differences and risks

### 1. Core web registration depends on the optional WhatsApp module

`VendorApplicationService` imports `WhatsAppMedia` and constructor-injects the module's `MediaPolicyService`. The DTO also references module model types. Since the public web controller now resolves this service, deleting or failing to deploy module classes can break ordinary web registration. Merely disabling a module may leave its classes autoloadable; failure on disable alone has not been demonstrated.

This violates the intended direction of dependency. The optional module should adapt to the host application, while ordinary host registration should remain usable without WhatsApp code or tables.

### 2. Web registration translation data is lost

The earlier controller passed the original request's translated `name` and `address` arrays to the translation helper. `fromWebRequest()` now retains only the default-language name/address, and the service uses `array_fill()` to repeat them for every requested language. Distinct translations submitted through ordinary web registration are consequently replaced with the default text.

### 3. Some existing registration email preference checks were dropped

The earlier controller checked both mail settings and `Helpers::getNotificationStatusData(...)` or `Helpers::getRentalNotificationStatusData(...)`. The new `sendRegistrationEmails()` retains mail settings but omits these additional notification-preference checks. Registration emails can therefore be attempted in situations the earlier workflow suppressed. This affects both normal and Rental registration behavior.

### 4. Zone validation no longer fails the same way

The new service skips spatial validation for SQLite and catches any exception in the spatial query, logs a warning and proceeds. The earlier controller did not swallow that query failure. In production a spatial-query failure can therefore allow registration to continue without the intended location validation. This is a source-confirmed change in failure handling, not evidence it has occurred in production.

### 5. Product/order services are additional implementations, not demonstrated canonical replacements

The ordinary product and order controllers do not call the newly added mutation services in the searched code. WhatsApp uses another implementation against the same tables. Calling these services “canonical” does not prove parity with upstream workflows, accounting, inventory, delivery notifications, module-specific fields or add-ons.

Their namespace can be moved into the module with relatively little host impact, but relocation alone cannot validate their business behavior. The same orders/products are consumed by the store, rider, customer and web clients.

### 6. Approval safeguards change shared business behavior intentionally

The decision service coordinates vendor/store status, suppresses repeated identical decisions and activates subscriptions only on approval. Its use of `saveQuietly()` also bypasses ordinary vendor model observers for that write; the explicit status event replaces the concierge observer path. Any other observer-based add-on must be checked.

Keep the protection against GET-triggered decisions, repeated notification side effects and inconsistent statuses while reducing the customization footprint. The previous rejected/approved message sequence alone does not prove which request caused each transition; establishing that requires correlated request and application records.

### 7. Module code can affect core behavior without editing a core file

The module provider registers a global observer on `App\Models\Vendor` and a listener for the new application-status event. Module action services write shared application records. An inventory of edited core files is necessary but insufficient to establish isolation or compatibility.

### 8. Deployment changes also affect upgrade and rollback safety

The workflow changed from release directories and a symlink swap to in-place `rsync`, without `--delete`. It also gained migration detection, module migrations and queue restarts. Consequently, deleting or relocating a class in Git does not by itself remove the old deployed file, and the current workflow does not provide the earlier atomic release switch. A cleanup must explicitly account for obsolete deployed code and a recoverable release; blindly adding deletion to rsync would risk server-managed files. These workflow changes are separate from API contracts but materially relevant to future updates.

## Client contract tracing

| Client/surface | Source evidence | What can be concluded |
| --- | --- | --- |
| Store Flutter app | `lib/util/app_constants.dart` defines `/api/v1/vendor/update-active-status`; `features/auth/domain/repositories/auth_repository.dart` posts to it. | Directly reaches the changed API controller. URL/method and normal success response remain the same. New service scopes the store to its owner and locks the write. Runtime equivalence, failures and simultaneous requests were not tested. |
| Store Flutter registration | Constants/repository use `/api/v1/auth/vendor/register`. | Routes to `Api\V1\Auth\VendorLoginController@register`, which has no net changes in this comparison; it does not use the replaced public web controller. |
| React/Next.js web registration | `src/api-manage/ApiRoutes.js` defines `api/v1/auth/vendor/register`. | Same unchanged API registration handler. This does not establish that all React flows are unaffected by shared data changes. |
| Rider app | Constants define `/api/v1/delivery-man/update-active-status`. | Different endpoint from the edited vendor availability operation. Rider order behavior still depends on shared order transitions. |
| Customer app / React storefront | Read shared stores, categories, products and orders. | Unchanged route definitions cannot prove unchanged data or business effects. Category null-to-empty-string behavior is one concrete shared change. |
| Blade public registration | Core `VendorController` calls the new registration service. | Directly exposed to the registration differences identified above. |
| Admin approval UI | Three modified Blade views use the modified admin decision route. | Intentionally changed HTTP contract for admin decisions. No matching mobile consumer was found in the searched client code. |

All three local Flutter `app_constants.dart` files currently point to `https://6ammart-admin.6amtech.com`. React obtains its API base URL from `NEXT_PUBLIC_BASE_URL`. These source checkouts are not proof of the configuration or version of deployed app builds. No claim is made here about the deployed clients' API host, shared database, runtime behavior or production compatibility.

## Safest remediation sequence

1. Preserve the current commit and database/media backups before implementation. Obtain the exact licensed 6amMart version used for the original deployment and the intended upgrade; compare both against the current application. Do not reset to the first import as a substitute for an upstream baseline.
2. Restore ordinary registration's upstream behavior, including translated arrays, notification preferences and spatial validation. Remove its dependency on WhatsApp media/DTO classes. Prepare WhatsApp-specific media and session data inside the module.
3. Relocate module-only product/order orchestration into the module. Prefer supported upstream services/helpers through explicit adapters. Where the script exposes no reusable service, document the unavoidable integration or maintained port and verify all side effects; do not invoke controllers internally as a shortcut or assume copied logic stays current.
4. Keep a small, documented host integration surface for safe application decisions and any truly shared operation. Preserve CSRF/POST, transaction, repeat-decision and notification isolation safeguards. Choose the final location of the decision/event/availability code only after checking actual extension points and add-on consumers.
5. Review the global observer, event listener, migrations, scheduler and worker lifecycle. Verify host flows with the module disabled and, separately, with optional module classes absent. Verify module-owned tables are not required by ordinary host requests.
6. Create an executable compatibility suite using the actual deployed client API versions and a representative MySQL staging database. Cover vendor registration and login; multilingual web registration; approval/denial and repeated requests; subscription/Rental behavior; store availability; product creation/editing/visibility; order transitions, stock, payment/refund/accounting and rider/customer notifications; notification preferences; module-off operation.
7. Maintain an explicit patch manifest for every retained core edit and rehearse the intended 6amTech upgrade in staging. Compare API request/response contracts and database outcomes before and after both the cleanup and the upgrade. Roll out with a tested rollback plan; do not drop module tables to achieve isolation.

## Acceptance position

The evidence does **not** justify saying “no effect on the apps” or “safe for future script updates.” It also does not demonstrate that all deployed apps are currently broken. The supported conclusion is: direct API edits are limited, but module isolation has been weakened and specific core registration regressions exist. Resolve those differences and validate the actual clients and upgrade path before granting a compatibility GO.
