# Phase D local validation and secure checkpoint

Date: 2026-10-07. Recommendation: **CONDITIONAL GO for a confirmed isolated Meta sandbox; NO-GO for production.** Local validation/checkpointing does not complete Phase D: the real-Meta boundary in [the bootstrap guide](phase-d-sandbox-bootstrap.md) remains open. No branch push, deployment, production database/environment change, Meta mutation or real-recipient message occurred. Chat fallback remains available and production Flow offers default disabled.

Branch: `feature/whatsapp-flow-vendor-onboarding`. Starting HEAD: `82756c49c8bfe10d48620df99cb135406ea085ad`. Five checkpoints separate foundation, authentication/API integration, Flow, verification and documentation/runtime hygiene. They form one reviewed dependent series; intermediate commits are not release candidates. The final generated report records the resolved commit SHAs; this tracked report cannot contain its own future commit hash. Inspect with `git log --format='%H %s' 82756c49c8bfe10d48620df99cb135406ea085ad..HEAD`.

## Redacted target preflight

| Target/property | Classification and evidence |
|---|---|
| Local application | LOCAL; user explicitly confirmed development installation; URL `http://localhost/mytijaara-admin` |
| Existing configured application database | LOCAL development target, not used or migrated; validation uses newly created schemas only |
| Unit/service databases | LOCAL; SQLite `:memory:` explicitly selected, isolated schemas created by fixture code |
| MySQL/MariaDB databases | LOCAL; hard-coded loopback `127.0.0.1:3306`, generated `mytijaara_isolation_<16 hex>` / `mytijaara_auth_isolation_<16 hex>` names, DDL-only imports, owned-prefix teardown |
| Staging application/database | Unavailable; no staging deployment workflow confirmed |
| Configured WABA/phone-number/App IDs | UNKNOWN; configured values redacted; no positive TEST ownership evidence; no requests made with their credentials |
| Sandbox Flow ID | Not configured; no remote draft exists from this work |
| Test IDs inside HTTP fakes | Synthetic WABA `456`, sending phone ID `123`, Flow `987`; never real API resources |
| Approved live recipients | None; fake fixture identities have no outbound network path and are not a live allowlist |
| Graph / Flow / data-exchange | v26.0 / 7.3 / documented 3.0 |
| Default feature / fallback / mode | Disabled / available / draft; rollout 0; local tests selectively override with HTTP/queue/mail fakes |
| Tunnel | None discovered or started; ngrok/cloudflared absent from PATH, no standard configuration/process found |
| Existing deployment workflow | PRODUCTION; main-branch/manual production deployment target and production application URL; untouched and unexecuted |

Each loopback MySQL fixture prints its exact generated database name before CREATE. No password, token or key is printed. A generated external evidence manifest lists the actual disposable schemas used. Representative installation SQL supplies CREATE/ALTER DDL only; no INSERT or customer/admin data is imported. Existing databases are not read for vendor testing or copied. Disposable schemas are destroyed by their owner fixtures; no evidence destruction was attempted against ordinary development, staging or production databases.

## Final source/security review

Review scope: the full Phase A–C worktree delta from starting HEAD, all new implementation/test/config files, and Phase D corrections. Automated scans cover configured-secret matches, PEM/private-key markers, token-like literals, local Windows paths and prohibited artifact extensions; reviewers also inspect authentication, consent, webhook, media, synchronization and core boundary logic. Synthetic test credentials, numeric resource fixtures and policy placeholders are explicitly test data, not configured secrets. Existing inherited repository artifacts are not newly introduced or represented as reviewed production data.

| Requirement | Finding/evidence |
|---|---|
| 1–3: secrets, real vendor/media, dumps/runtime artifacts | No configured secrets/private keys/real uploads in checkpoint. Generated test keys live in temporary private paths and are removed. Reports/JUnit/media/database artifacts remain external or ignored. `.rnd` is removed from Git tracking while its working file is preserved and ignored. No local Windows paths in staged source/docs. |
| 4: resource identities | Runtime WABA, phone-number and Flow IDs come from configuration; permanent Flow options are endpoint-driven. Numeric examples occur only in synthetic fixtures/tests/schema examples. |
| 5–7: rollout/fallback/publication | Defaults disabled, fallback true, draft mode/0 rollout; sync requires explicit `--execute --publish`. Dry-run/status/local tests assert no Meta mutation. |
| 8–9: privacy | Dedicated signed nfm routing and allowlisted DTO bypass conversation/AI jobs; encrypted sensitive storage/queue; safe Meta/media errors; credentials use hash-only purpose tokens and fragment links; request collectors disabled on sensitive module endpoints. Actual local logger-event, record and diagnostics inspection is tested. |
| 10–11: media/optional KYC | Required logo and cover, JPEG/PNG content validation/decode; optional TIN and image document; no invented canonical fields. |
| 12–14: access boundaries | Pending login/existing bearer/restricted normal-route use rejected; password reset/setup never approves; store/vendor/token locks and status revocation tested on actual MySQL. |
| 15–16: legal evidence | Exact manifest/locale/wording/hash; archive bytes rehashed; two immutable evidence rows inside registration transaction; unique/FK RESTRICT constraints, database triggers and ORM guards; no legacy backfill. |
| 17: migration assumptions | Reviewed SQLite/MySQL drivers checked before evidence DDL; additive tables, supported key types and named short index; MySQL DDL is not transactionally reversible. Trigger permissions/schema compatibility require production preflight. Destructive evidence down migrations are disabled. |
| 18: module boundaries | Core impact manifest covers canonical/auth/consent/private-storage changes; module adapters retain canonical ownership; boundary checker validates protected owners and absence of forbidden host dependencies. |

Stage inspection uses explicit file groups, not blanket `git add .`. The source scan is heuristic, supplemented by the reviewed diff and behavioral gates; it is not a claim to have authenticated an external Meta resource.

## Defects fixed and local bootstrap additions

1. **Archived-policy integrity gap:** policy row hashes were checked structurally but archived bytes were not rehashed. `RegistrationPolicyService` now reads an explicitly configured archive disk, validates relative object references, hashes a bounded stream, and rejects missing/changed/oversized objects with a generic safe validation correction. No storage exception, document bytes or object reference is echoed.
2. Evidence migration now rejects unsupported database drivers before creating partial schema. SQLite injected-trigger collision proves transactional DDL rollback. MySQL implicit DDL commits are disclosed; recovery requires a preservation-first repair, not an unsafe down migration.
3. Added synthetic document artifacts and a guarded idempotent console fixture installer. It accepts only explicitly named disposable loopback schemas/testing memory databases, uses private archive objects, records exact hashes/locale/UTC effective state and returns the server-owned presentation manifest. Existing records/objects are never overwritten. Current English version configuration starts unset and is explicitly provisioned for tests.
4. Added actual local log/storage/funnel privacy inspection; duplicate completion remains one distinct session. Expanded the complete seven-screen journey to commission and subscription, including the emitted final reply params, eligible-only package data, duplicate submission, pending/unpaid state and approval denial.
5. Pinned Flow JSON LF line endings and synthetic policy fixture bytes in Git attributes so checkpoint/checkout does not alter asset/content hashes. Added wrong-locale and future/retired policy correction checks, migration upgrade/backfill/index/FK tests and exact disposable-schema preflight output. Sanitized delivery documents and excluded runtime randomness from source control.

## Local scenarios and results

All results here are application/service/HTTP-contract tests with synthetic inputs and mocked external delivery. They are not observed WhatsApp client journeys.

| Scenario | Local result |
|---|---|
| Commission seven-screen journey, mandatory branding, optional KYC skipped, exact controls | PASS: endpoint progress, staged verified images, emitted final params, one pending application, two evidence rows, no pending unrestricted access |
| Subscription seven-screen journey | PASS: eligible package only, canonical pending `none` model/package, unpaid state, one application/evidence pair; restricted access boundaries separately exercised |
| Rental pickup/package/plan conditionals | PASS: shared registration and Flow eligibility gates revalidate live module/zone/package/pickup IDs |
| Missing owner/email/location/delivery, invalid ranges/units, required logo/cover | PASS: correction without registration |
| Valid optional KYC / absent optional KYC | PASS: only supported optional image payload; no document requirement invented |
| MIME spoof/disguised extension, byte/dimension/decode limits, failed/partial download | PASS: actual MIME, encrypted SHA/HMAC/decryption and bounded transfer checks; no completed registration on incomplete media |
| Media retry, abandoned/orphan cleanup, other completed attempt protection | PASS: own-session staging, integrity verification, resumable publication, safe public-object checks and reconciliation |
| Consent stale/missing explicit control/presentation mismatch | PASS: correction and no new accounts/evidence |
| Wrong locale, future/retired publication, changed/missing/unconfigured archive | PASS: safe correction/fail-closed manifest |
| Policy/evidence immutability and restrictive deletion | PASS: model and database guards; restrictive schema and actual MySQL evidence trigger checks |
| Registration transaction rollback, duplicate evidence, old vendor preservation | PASS: no partial accounts/evidence; exactly two rows after idempotent completion; upgrade does not alter legacy vendor or backfill acceptance |
| Sender/token/expiry/replay/message/Flow identity/malformed/unexpected payload | PASS: binding, allowlisting, receipt uniqueness and single successful use |
| Simultaneous completion and normalized identities | PASS: actual MySQL lock contention/receipt uniqueness; normalized duplicate/race mapping and auth issuance/status locking |
| Pending login/bearer, restricted ordinary routes, setup expiry/replay/reset | PASS: approval stays authoritative; restricted purpose routes only; setup/reset cannot activate |
| Approval and suspension | PASS: intended access after approval; revocation rejects subsequent owner/employee requests |
| Meta/media/data-exchange failures and correction/reopen/reoffer | PASS: safe retry, preserved draft, policy controls reset, committed application resumed without recreation |
| Chat fallback and disabled feature | PASS: existing chat retained; unavailable/unsupported/disabled offers do not replace fallback |
| Sync draft discovery/upload/errors/publication protection | PASS with fake HTTP: deterministic reconciliation, readable sanitized errors, current published pointer retained, no default publication |
| Local Flow JSON/schema/fixture validation | PASS; invalid fixture rejected; no Meta request |
| RSA2048/OAEP, AES-GCM, signatures and ping | PASS using generated test-only keys and real crypto primitives; invalid signature/tag rejected |
| Privacy inspection | PASS: actual local logger events exclude token/response/media/document markers; encrypted raw draft excludes personal fields; no nfm conversation row or generic/AI job; diagnostics/events contain safe operational data only |
| Funnel/idempotency | PASS: offered/opened/progress/submission/correction/media failure/completion/setup/fallback/expiry events exercised; distinct-session completion count unaffected by duplicates |

Synthetic vendor/store/token/media/receipt/event records are created only inside fixture databases and removed with those fixtures. Successful registration has one pending vendor/store and two consent rows; setup changes credentials but preserves pending application status. Private/public test disks are synthetic fakes. No operational real-vendor records were created.

## Exact sequential verification

| Gate | Result |
|---|---|
| Final focused Flow/transport/migration upgrade | **72 tests / 366 assertions PASS**, 1 deprecation |
| Full Concierge release regression | **365 tests / 1864 assertions PASS**, no skips, 50 deprecations; latest wrong-locale/future/retired test supplemented in final focused suite |
| Full architecture | **169 tests / 842 assertions PASS**, no skips, 1 deprecation |
| API registration (architecture subset) | **36 / 211 PASS** |
| Authentication (architecture subsets) | Application **42 / 183**, actual MySQL **44 / 193**, both PASS |
| Subscription (architecture subset) | **6 / 24 PASS** |
| Actual MySQL host registration / Flow concurrency | **12 / 80** and **1 / 7**, PASS |
| Shared registration / credentials explicit suite | **44 / 234 PASS** (shared 36/168, credentials 8/66), 1 deprecation |
| Upgrade / synthetic installer (final focused subset) | **3 / 20 PASS** |
| Full feasible default application suite | **50 tests / 13 assertions**, 46 errors / 1 failure; same **47** baseline failing identities, **zero new**, 1 deprecation |
| PHP lint | **95 impacted PHP files**, zero errors |
| Pint | All new PHP files and previously scoped changed legacy methods checked; PASS |
| Core boundary / whitespace | Boundary exit 0, git diff --check PASS |

A new multi-case policy test initially failed because its Eloquent fixture was stale after processing; refreshing the fixture before resetting state fixed it. The final 72-test rerun passed. No application change was needed for that test correction. Earlier focused/full runs are retained as historical evidence; the full Concierge suite was rerun after expanding the commission/subscription journey.

JUnit/logs and baseline comparison are generated outside the repository. Suites overlap and are not summed. Actual MySQL tests are enabled and not skipped. Fake-storage suites run sequentially. PHPUnit deprecations remain visible. No PHPStan/Psalm configuration exists. The default host suite remains non-green; all 47 affected class/test/error identities match the unchanged baseline, with no new failure.

## Remaining limitations

No positive test-account identity, genuine Meta validation, real picker delivery/CDN behavior, tunnel/callback deployment or test-recipient delivery has been observed. The proposed reverse proxy/tunnel and immutable document hosting are preparation, not executed infrastructure. Browser/client compatibility, account permissions/health and customer-service-window/template delivery are external gates. JSON validation is a reviewed local subset, not Meta validation. Data API 3.0 remains the documented implementation; 4.0 was not guessed. Coordinates use text because no GPS picker was verified. Optional TIN documents are image-only. English UI only; other locales use chat fallback. Private root/object-lock/legal approvals and the coordinated store-app restricted-auth/six-digit-OTP release remain production prerequisites. Legacy web/API versioned evidence remains optional until its approved coordinated strict switch; Flow evidence is always required. Notification ambiguity can duplicate a message but not an application.

## Production migration and Meta checklist (Phase E only)

Confirm production identity separately, reviewed schema/foreign-key types, MariaDB/MySQL version and TRIGGER privileges, protected archive/media roots, application keys and backup/preservation controls. Rehearse additive migrations on a non-production representative schema and inspect trigger/index/FK results; preserve all evidence. MySQL DDL failures require inspection of partial tables/triggers and a reviewed repair before traffic; code rollback does not roll back schema. Never destroy evidence or fabricate legacy acceptance.

Approve and archive real legal documents/locale/version/object IDs/hash/wording, verify served bytes, deploy current manifests with worker/config reload and coordinated strict web/API clients. Approve store-app auth compatibility and appropriate notification templates. Use a separately confirmed production WABA only after Phase E authorization; register protected endpoint/public key, create a new deterministic Flow definition version, upload/validate/inspect health, explicitly publish, and keep the previous working production Flow pointer. No production command is authorized by this checklist.

## Canary and rollback plan

After live sandbox gates and separate Phase E approval, begin with approved internal test senders and zero broad rollout; confirm API/auth/client behavior, media publication, policy evidence and privacy. Increase a small deterministic percentage only after reviewed conversion/error thresholds and worker health. Exact production thresholds and rollout sizes require owner approval; none are invented here.

Rollback by disabling Flow offers or restoring the reviewed previous published ID/version while retaining chat fallback. Drain/resume queues safely, preserve session/consent/media journals, and reconcile committed incomplete applications; never rerun registration for notification failure. Restore compatible code/config and keep additive evidence tables. Do not overwrite a published asset or run destructive down migrations. Unknown targets remain blocked regardless of variable names.

The next external requirement is the account owner's dedicated Meta developer/test-resource setup described in the bootstrap guide. Phase D stays open. Production merge/deploy/migrate/publish/activation requires separate Phase E approval.
