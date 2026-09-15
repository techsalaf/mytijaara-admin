# Core isolation remediation — implementation evidence

Status: **in progress; not a production GO**. This report supersedes earlier blanket completion/readiness statements for the architectural remediation. Passing preflight is not proof of workflow parity.

## Requested wrap-up checkpoint

The user requested a quick wrap-up because of the remaining token budget. This is a saved implementation checkpoint, **not completion of every requirement**.

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

Product/order adapters are module-owned maintained ports. They are **not certified equivalents of every upstream workflow yet**. Added protections include ownership rechecks under locks, stale-preview rejection, subscription product limits, explicit variation-stock validation, moderation staging, cancellation configuration, delivery verification, and calls to existing stock/refund/accounting helpers. Financial and module-specific parity still requires the checks listed below.

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

## Outstanding completion gates

- Complete full module rerun after final edits; report exact pass/fail/skip/risky counts.
- Complete product media ownership/copy handling, relation and module-specific product parity, and remaining order financial/stock/rider side-effect checks, including concurrent MySQL mutations.
- Runtime host ordinary product/order workflows without module classes; broader HTTP authentication/CSRF behavior and optional-listener failure cases.
- Published Rental/provider branches without altering Rental ownership or production configuration.
- Final Flutter/React endpoint comparison with file/line evidence, complete diff inventory and exact retained patch explanation.
- Final migration-impact inventory (no new application migration has been introduced by the isolation work so far).
- Exact official-source upgrade rehearsal once the matching licensed release exists locally.

Do not infer a production GO from any one passing row above. The report will be updated as the remaining implementation and evidence are completed.
