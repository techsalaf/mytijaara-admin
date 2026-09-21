# Concierge compatibility adapters and release checks

The host remains authoritative. All WhatsApp adapters live in `Modules/WhatsAppVendorConcierge/app/Services/CoreAdapters`; ordinary controllers do not call them. These are maintained ports of the checked-in host, not a certified copy of a licensed vendor release.

## Operation coverage

| Operation | Host reference | Module behavior and evidence |
| --- | --- | --- |
| Registration | `VendorController::store` | Host method restored. Module keeps secure credential hashes, spatial eligibility, module/zone and package checks, distinct translations, media, schedules and notification preferences. Submitted-session replay does not create another store; a confirmation transport failure does not undo persistence. Tests: `MySqlHostRegistrationTest`, `VendorApplicationParityTest`, `WhatsAppVendorConciergeTest`. |
| Approval/denial | Generic `VendorApplicationDecisionService`; host/Rental mail preferences | Normal model observers, transactional vendor/store state, rejection reason, approval-only subscription activation and repeated-decision suppression. Optional module-table or queue failure cannot undo a decision. Store and Rental decision emails honor their respective preferences. Tests: `ApprovalIsolationTest`, `AdminApprovalNotificationTest`, `ApplicationDecisionMailTest`. |
| Product creation | `Vendor/ItemController::store` | Ownership, locked subscription limits, required descriptions, configured price range, required non-food photos, owned store categories when enabled, category ancestry, discount validation, zero default stock, owned image publication, default grocery/ecommerce/pharmacy detail records, translations and moderation. Requirements are checked before confirmation and again before persistence. Tests: `VendorOperationsTest`, `VendorToolsConfirmationTest`. |
| Product edits | `Vendor/ItemController::update`, `stock_update`, `status`, temporary-product review | Price/details stage for review when required. Existing tags, module details, taxes, gallery and all translations are preserved in review copies. Replacement images use the current upload disk; unchanged images retain the historical disk. Variation inventory must explicitly reconcile with the total. Tests: `VendorOperationsTest`. |
| Order transitions | `Vendor/OrderController::status`, API pickup/verification guards, `OrderLogic`, `ProductLogic` | Ownership rechecked under row lock; terminal-state, cancellation-policy, reason, self-delivery and OTP guards. Calls host refund, stock, accounting and payment helpers; preserves timestamps, item/store/customer/rider counts and notifications. Tests: `MySqlOrderParityTest`, `VendorOperationsTest`. |
| Store availability | Original vendor web/API active-status methods | Host endpoints restored. Module owns its separate authorized pause/resume adapter. Tests: `MySqlHostRegistrationTest`, `VendorToolsConfirmationTest`. |

The product interface still exposes its existing basic listing fields. It does not newly expose every advanced vendor-portal editing control. Existing advanced fields are retained when the concierge changes supported fields. Review-record preservation tests are not a substitute for exercising every downstream admin publication screen.

Registration intentionally distinguishes a new public web request from a verified WhatsApp session retry: public duplicate-phone validation is unchanged; the module can reuse an unapproved account and a submitted session. Reusable passwords are not accepted as WhatsApp adapter input. Missing secure credentials now fail rather than silently generating an unknown password.

## Financial evidence

The MySQL-family integration fixture imports actual installation DDL into a random loopback database, never bundled data. Cancellation verifies variant stock, customer wallet credit, admin wallet debit and no duplicate refund. A separate PHP process competes for the same locked order and must observe the committed cancellation. Delivery verifies OTP enforcement, commission/store accounting, payment status, timestamps and rider/store/customer/item counts. A database trigger injects an order-write failure after accounting; all financial and counter changes must roll back before a successful retry.

This is representative contention coverage, not exhaustive testing of every payment gateway, coupon, referral, tax, subscription and third-party add-on combination.

## Release and worker protocol

1. CI gates the release on architecture and concierge suites. The package excludes `.env*`, persistent storage, runtime caches and server-managed add-on configuration.
2. `safe-code-release.php prepare` records hashes and backs up affected code outside the application. Only reviewed hashes permit removal of obsolete relocated classes.
3. `apply-code-release.sh` takes the exclusive code-release lock, enables maintenance, signals queue restart, and waits for the existing WhatsApp worker lock. Timeout aborts before code replacement and leaves maintenance enabled.
4. The WhatsApp worker takes a shared code-release lock for its lifetime. During a release, new cron invocations exit without starting work. The old worker lock also drains pre-isolation versions of this worker.
5. Code apply, optional migrations, cache clearing, queue restart and maintenance exit run in the same locked SSH session. Failure attempts journal rollback and leaves maintenance enabled.
6. A code rollback never reverses a database migration. Keep the incoming package and journal, inspect schema compatibility, then recover caches/workers before opening traffic.

Other worker launchers must either use the same release lease or be independently drained. The local environment has not established the production worker inventory. Shell syntax and code-journal recovery are verified locally; the Linux worker-lock protocol still needs a staging lifecycle exercise.

## Remaining release qualification

Do not represent local passing tests as an observed production deployment. Production remains **NO-GO for an unconditional release** until these environment-specific checks are recorded:

- Run the gated suites on the intended PHP/MySQL versions (local evidence uses PHP 8.5.9 and MariaDB 10.4; CI targets PHP 8.3/MySQL 8).
- Rehearse the release/worker drain and rollback on staging with the actual worker launch configuration and filesystem permissions.
- Exercise real Meta template languages, approval/denial/support replies, webhook-to-worker processing, secure credential links and a representative payment callback on staging.
- Rehearse representative product moderation/publication and actual Flutter/React builds. Source contracts and controller persistence tests pass; installed-client behavior has not been observed.

The published-Rental registration test uses a process-local published-flag fixture without changing Rental files. It proves the selected branch's pickup and mail behavior, not the installed add-on's activation or license state. An exact licensed upstream archive was unavailable, so an official-source upgrade rehearsal remains unavailable rather than passed. Before the next upstream upgrade, compare that archive against the eight explicitly documented generic core patches, reapply only necessary safety hooks, rerun both suites, and rehearse code/schema recovery on staging.
