# Staging release qualification

Status: **not executed**. This is the remaining acceptance record for the core-isolation branch, not a production deployment instruction. Use staging credentials, test recipients and sandbox payments. Never copy secrets into evidence.

Record the tested commit, PHP/database versions, installed add-on versions, worker launchers, client build identifiers, timestamps and a pass/fail result for each check. A local passing suite does not fill in these results.

| Gate | Exercise | Required observation |
| --- | --- | --- |
| Target runtime | Run the `Host and concierge isolation` workflow for the reviewed commit | Both architecture and module suites pass on PHP 8.3/MySQL 8, with no skipped MySQL tests; retain JUnit artifacts |
| Host independence | Disable WhatsApp through the normal module configuration on staging; exercise vendor web registration, vendor login, stock/status, order processing and availability | Ordinary host flows succeed with no module-table or module-class resolution failures; restore the module before concierge checks |
| Release lease | Start the actual worker launcher with a bounded test job, then rehearse the reviewed release script | The active job finishes before replacement; new workers do not start during replacement; the deployed worker resumes afterward. Independently drain any launcher that does not use the release lock |
| Code rollback | On disposable staging code, induce a controlled failure after code apply, then inspect the journal | Code hashes restore, maintenance stays enabled, runtime/config/upload files remain untouched. Inspect schema compatibility before reopening; code rollback does not undo migrations |
| Registration | Register a test vendor through WhatsApp, upload logo/cover/KYC, use the secure credential link, edit/review and submit twice | Required fields persist; one application is created; no reusable password appears in chat; interrupted sessions resume |
| Decisions | Approve one pending fixture and deny a separate fixture with a reason; repeat each decision and revisit the previous page | Correct final state and list membership; one current-status notification; no accidental denial from refresh; subscription activation only on approval |
| Meta delivery | Use the configured approved template names/languages and inspect provider callbacks | Meta accepts the actual templates; failures are visible without failing the admin decision; retry does not send stale/conflicting statuses |
| Support and replies | After denial, select Talk to Support; send a greeting from denied and unrelated test numbers; simulate unavailable AI | Support handoff works; webhook processing continues; fallback responds; no global silence or permanent vendor lockout |
| Product publication | Create from an owned photo, required description and categories; confirm; edit a moderated product and publish through the admin UI | Nothing changes before confirmation; review preserves translations, advanced fields and images; store/customer clients show the approved result; cross-vendor media/items are rejected |
| Orders and payments | Exercise a test cancellation/refund, verified delivery and sandbox payment callback/replay | Stock, wallets, accounting, payment status and rider/customer/store state agree; retries do not duplicate money or inventory effects |
| Installed clients | Use the actual store, customer, rider and React builds against staging | Existing login/API requests still work; product/store visibility, availability and order state match the tested backend |

Before the next 6amTech upgrade, obtain the exact licensed source version, compare the eight retained generic core paths in `scripts/core-patches.json`, reconcile module adapters with any changed host rules, rerun both suites and rehearse rollback. The repository's initial import is not an official upstream certification.

Production release remains withheld until the applicable checks above pass and the deployment is separately authorized. See [adapter coverage and limits](whatsapp-core-adapter-parity.md) and [local evidence](core-isolation-remediation-2026-09-15.md).
