# Core isolation remediation — implementation evidence

Status: **local implementation and regression gates passed; production qualification outstanding**. This report supersedes earlier blanket completion/readiness statements for the architectural remediation. Production remains NO-GO until the staging checks below are observed.

## Resumed verification — 2026-09-21

The delivery-accounting failure was a disposable-fixture omission: the host helper requires referral and loyalty settings. Those settings are now seeded without modifying host financial rules. Real delivery, rollback after accounting, and a competing PHP cancellation process are covered.

The resumed implementation also connects the photo-to-product message handler to owned-media validation and explicit confirmation; enforces required descriptions, non-food photos, owned store categories and the host price range; rejects arbitrary product-image paths; preserves category ancestry and default module detail records; handles replacement images across storage disks; rejects missing secure credentials; selects Rental provider decision emails and preferences; and keeps submission persistence independent of confirmation transport failure. None of these changes adds a dependency from the core to WhatsApp.

Worker releases now drain the old worker lock and hold an exclusive code-release lock through apply, migration, cache and recovery operations. New worker invocations take a shared lease. Source enforcement also checks literal query-builder writes and imported model aliases.

Verified evidence after the earlier checkpoint:

| Evidence | Result |
| --- | --- |
| `module-final-complete.*` | **134 tests, 758 assertions; 0 failures, 0 errors, 0 skips**; 50 PHPUnit deprecations; final combined run including MySQL-family financial cases |
| `module-final.*` | 130 tests, 655 assertions; no failures/errors/skips; 50 PHPUnit deprecations |
| `architecture-verified.*` | 35 tests, 174 assertions; no failures/errors/skips; 1 PHPUnit deprecation |
| `rental-registration.*` | 1 test, 12 assertions; no failures/errors/skips; 1 PHPUnit deprecation |
| `product-review-final.*` | 20 tests, 98 assertions; verifies the final product-detail and image-disk corrections |
| `decision-mail-final.*` | 1 test, 84 assertions; store/Rental approve/deny, preferences and repeat suppression |
| `module-release-complete.*` | 132 tests, 749 assertions; no failures/errors/skips; predates the final mandatory product-field validation |
| `canonical-product-validation.*` | 26 tests, 212 assertions; no failures/errors/skips; final product requirements and registration/mail regressions |

The final combined concierge run completed successfully. The 35-test architecture suite and the subsequently added one-test Rental case were run separately; together they cover 36 tests and 186 assertions, not an unobserved combined run. Earlier failed logs remain as history and are superseded by successful runs of the same cases. The intermediate mail-test failure came from host business-setting memoization surviving between test applications; the fixture now uses the host's supported per-key configuration override. See [the adapter trace](whatsapp-core-adapter-parity.md) and [staging acceptance record](whatsapp-staging-release-qualification.md) for tested behavior, remaining operational gates and the worker/rollback protocol. Production has not been accessed, pushed to, or deployed.

Final application code is committed locally as `af39f831`, following release/isolation commit `8e8bd23e`. Eighteen changed module PHP files pass syntax checks. The final boundary scan passes and the explicit core-reference search returns zero matches. No application migration was added; generated test translations were removed. The complete change inventory is recorded in `final-diff-stat.txt` and `final-diff-names.txt`, and the eight retained generic core paths are captured in `retained-core-patch.diff` and `scripts/core-patches.json`.

The final module command was `php vendor/phpunit/phpunit/phpunit Modules/WhatsAppVendorConcierge/tests --log-junit docs/remediation-evidence/module-final-complete.xml`, with `APP_ENV=testing`, `APP_DEBUG=false`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `LOG_CHANNEL=single` and `ISOLATION_MYSQL=1`. The architecture run used the same environment with `tests/Architecture`; the subsequent Rental run selected its new published-registration test. These tests use disposable fixtures and do not read or change production data.

## Historical requested wrap-up checkpoint

The following paragraphs preserve an earlier checkpoint and its then-unresolved failures. They are **historical**, not the current test verdict; the resumed evidence above supersedes them.

Additional verified evidence: `mysql-orders.*` passed one real MySQL-family cancellation test with 9 assertions, covering variant stock, admin/customer wallet balances and repeat suppression. The final focused product run (`product-final-check.*`) passed 13 tests with 62 assertions, including media ownership/storage and review relation/translation preservation. All 22 checked adapter/tool PHP files passed syntax checks (`adapter-lint.txt`). `mysql-delivery.*` failed its delivery test after one assertion because the canonical accounting helper returned failure; the local log reports a missing setting value. The precise missing prerequisite and a successful delivery-accounting run remain unresolved. Do not remove or weaken that failing gate.

The last full module run (`module-complete-check.*`) recorded 122 tests, 556 assertions, one error and two failures. Subsequent fixes address product-media ownership fixtures, the invalid onboarding-session state write, transaction ownership, product media copying and translation preservation; that entire suite has not yet been rerun after all those changes. Latest focused product results are recorded in `product-final-check.*` when complete.

The installation schema fixture imports only CREATE/ALTER statements from `installation/backup/database.sql`, using a fresh loopback database. It never imports bundled data. MySQL 9's `utf8mb4_0900_ai_ci` collation is mapped to `utf8mb4_unicode_ci` for local MariaDB compatibility; this is a test-fixture adaptation, not a production migration.

`client-contracts.txt` records local Flutter/React source references and the API diff. API routes and vendor login/registration controller are unchanged from the starting commit; the vendor API controller change restores its active-status implementation. Local Flutter constants still point at the script developer's demo backend, so this source inspection does not certify installed client builds.

Resume with: resolve the delivery fixture/helper failure; run product, notification and confirmation regressions; rerun the complete module and architecture suites with `ISOLATION_MYSQL=1`; complete host product/order and concurrent-mutation tests; finish module-specific product parity, published Rental branch checks, and the retained-line/upgrade review. No new application migration has been added by this remediation.

## Recovery and scope

- Starting commit: `b87aa24269a0074a546c79e224691508222fd0f0`.
- Working branch: `refactor/whatsapp-core-isolation`.
- Recovery tag: `preservation/whatsapp-before-isolation-20260915`.
- Local work only. No production connection, deployment, push, table removal or production-media operation was performed.
- `bf5ca49f` is the first repository import, not a certified upstream release. No exact licensed admin archive was found locally. A vendor-release upgrade rehearsal remains unverified.
- Rental files and `config/system-addons.php` are outside this remediation. The release package preserves the server's add-on configuration; its ownership needs a separate decision.

## Implemented boundaries

The public web registration method has been restored from repository history before the service extraction. Its validation, spatial predicate, multilingual arrays, notification preferences, schedules and business-plan handling remain owned by the host. Module DTO conversion, session mapping and media preparation now live under `Modules/WhatsAppVendorConcierge`.

The ordinary mobile and web store-active methods have been restored to their previous implementations. WhatsApp uses its own adapter. The Category model's name accessor/fillable changes were restored, and the host pending-vendor view no longer queries a WhatsApp table.

Generic approval safety remains in the host: POST decisions, route-authoritative identifiers, reasons, transactional decisions, repeat suppression and approval-only subscription activation. Normal vendor model events run; a scoped decision context lets the optional module observer defer to the explicit domain event without silencing unrelated observers. Optional module listener failures are contained. Notification jobs are queued after commit and reject superseded decisions.

Product/order adapters are module-owned maintained ports. They are **not certified equivalents of every upstream workflow**. Added protections include ownership rechecks under locks, stale-preview rejection, subscription product limits, explicit variation-stock validation, moderation staging, cancellation configuration, delivery verification, and calls to existing stock/refund/accounting helpers. Local tests verify representative financial and module-specific effects; the remaining staging combinations are listed in the adapter coverage document.

## Retained core patches

The machine-readable source of ownership, purpose, tests and upgrade handling is `scripts/core-patches.json`. Existing host paths retained for generic approval safety are:

1. `app/Http/Controllers/Admin/VendorController.php`: generic decisions/status events and repeat protection.
2. `routes/admin.php`: POST application decisions and non-mutating legacy GET handling.
3. `resources/views/admin-views/vendor/pending_requests.blade.php`: matching CSRF-protected forms.
4. `resources/views/admin-views/vendor/deny_requests.blade.php`: matching approval form.
5. `resources/views/admin-views/vendor/view/partials/_header.blade.php`: matching decision controls.
6. `resources/lang/en/messages.php`: generic decision labels. Test-generated translation additions must be removed before the final diff.

Two new generic host files remain: `VendorApplicationDecisionService` and `VendorApplicationStatusChanged`. Neither imports the optional module. The unrelated Rental configuration difference is separately recorded and is not counted as a WhatsApp patch.

## Recorded verification

All results below are local. PHP is 8.5.9; the CI gate targets PHP 8.3. MySQL-family tests use a fresh random loopback MariaDB 10.4.32 database per test and remove only that created fixture. The CI workflow additionally targets MySQL 8.

| Evidence | Result | Scope and limit |
| --- | --- | --- |
| `remediation-evidence/baseline-focused.*` | 23 tests, 137 assertions passed | Pre-remediation focused baseline, not the entire module |
| `remediation-evidence/architecture-reviewed.*` | 24 tests, 93 assertions passed | Earlier host/module-absence and MySQL registration gate |
| `remediation-evidence/architecture-final-check.*` | 28 tests, 117 assertions passed | Adds negative boundary and recoverable release tests; predates the two new API-flow tests |
| `remediation-evidence/mysql-client-flows.*` | 2 tests, 22 assertions passed | Real MySQL persistence through API registration/login and both availability controllers with module autoload denied |
| `remediation-evidence/review-notifications.*` | 20 tests, 86 assertions passed | Products/moderation, maintenance, approval/denial queues, status delivery |
| `remediation-evidence/module-fixtures.*` | 120 tests, 480 assertions; 9 errors, 12 failures | Intermediate full run; retained to show failures rather than conceal them |
| `remediation-evidence/unit-reviewed.*` | 60 tests, 223 assertions; 5 errors, 1 risky | Intermediate unit run; missing subscription fixture subsequently addressed |

Raw logs also report PHPUnit deprecations. The host absence gate disables the module through an isolated status file and installs an autoloader that throws for every module class. It does not rename/delete the real module directory. Controller tests execute real controller methods; route inventory is checked separately. They do not constitute a complete HTTP middleware/browser/mobile-app rehearsal.

Commands used (with test-only SQLite/cache environment, and `ISOLATION_MYSQL=1` for the MySQL gate):

```text
php scripts/check-core-boundary.php
php vendor/phpunit/phpunit/phpunit tests/Architecture --log-junit docs/remediation-evidence/architecture-final-check.xml
php vendor/phpunit/phpunit/phpunit tests/Architecture/MySqlHostRegistrationTest.php --filter 'test_vendor_api_registration|test_vendor_login' --log-junit docs/remediation-evidence/mysql-client-flows.xml
php vendor/phpunit/phpunit/phpunit Modules/WhatsAppVendorConcierge/tests --log-junit docs/remediation-evidence/module-complete-check.xml
```

The boundary checker rejects host/other-addon imports of WhatsApp, unapproved changed or untracked core files, and detectable protected writes outside listed owners. This source guard complements integration tests; its write detection is not a complete PHP data-flow analysis.

Both workflow YAML files parsed locally with `js-yaml`; extracted shell steps passed Git Bash `bash -n`. The release scripts were exercised against disposable local fixtures, never a server.

## Recoverable release procedure

The proposed workflow uploads into a sibling incoming directory, then prepares a file journal outside the application root before entering maintenance. Preparation hashes and backs up affected code. Apply replaces files individually through temporary-file rename; it does not recursively delete the application. Five obsolete relocated PHP classes are removed only when their contents match the reviewed hashes in `scripts/obsolete-core-files.json`. Unknown local changes block preparation. CRLF differences also block removal and require explicit review; they are not silently discarded.

`.env*`, storage/uploads, bootstrap runtime cache, Git data and server add-on configuration are protected. Incoming, target and journal roots must be disjoint. The package excludes local agent work and test evidence. Existing module migrations, queue restart and worker scripts remain part of the deployment design.

If apply or a later step fails, the workflow attempts file rollback and leaves maintenance enabled. A code rollback does **not** undo a database migration. Inspect journal/source hashes and schema compatibility before clearing caches, restarting workers and bringing the host back up. Keep the incoming package and journal until verification and rollback retention have expired. This mechanism has local fixture coverage; production permissions/process supervision remain untested by design.

## Remaining release qualification

The prior local gaps have additional implementation and test evidence above. An unconditional production GO is still withheld: staging Meta/payment and installed-client smoke tests, the real Linux worker lifecycle/rollback exercise, and the target PHP 8.3/MySQL 8 CI run have not been observed. The exact licensed upstream archive is unavailable; no official-source upgrade rehearsal is claimed.

See [the release qualification checklist](whatsapp-core-adapter-parity.md#remaining-release-qualification). Production deployment and SSH remain outside the authorized scope. Local tests cannot establish that the configured production workers, Meta account, gateway callbacks and installed applications work perfectly.
