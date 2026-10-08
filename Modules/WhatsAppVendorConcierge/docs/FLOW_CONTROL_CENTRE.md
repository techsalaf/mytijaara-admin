# WhatsApp Flow Control Centre

Admin URL: `/admin/whatsapp/flows`. Uses the host Blade admin layout, Bootstrap and existing Concierge navigation. All implementation belongs to this module. The existing inbox, chat onboarding, templates, providers and support workflow remain available.

## Authorization and safe operation

The existing authenticated admin boundary remains required. Super-admin role 1 has all Flow permissions. Other active roles need both host `store` and `contact_messages` access and explicit module grants. Routes enforce permissions even when buttons are hidden. Publication, key configuration, policy publication, recipient sends, recovery and settings have typed confirmations. Five-minute encrypted review proofs bind the administrator, environment, account, phone, Flow, definition hash and nonce. CSRF and HTTPS apply. Server-side checks run again when queued operations execute, including revoked permissions and changed configuration.

Lifecycle operations use domain services, not shell/Artisan command execution. Jobs contain only an operation UUID. Database idempotency constraints, compare-and-set state transitions and a shared cache lease serialize lifecycle work. No arbitrary commands, SQL, unrestricted logs or credential values are available. `queued` is not proof of delivery or worker health. Read-only stale operations can be retried; mutations require renewed review and remote inspection after an ambiguous outcome.

Administrative audits and presentation revisions have database UPDATE/DELETE rejection triggers. The migration deliberately refuses destructive rollback. Audit records contain administrator ID, action, target, UTC time, safe changes and normalized error metadata; no IP, user agent, raw submission, signed media URL, token or private document contents.

## Definition lifecycle

Preview displays the canonical seven-screen definition and field mapping. Dynamic choices are endpoint-owned, not hard-coded database IDs. The preview is illustrative: real WhatsApp rendering still needs a controlled device.

Presentation editing permits bounded plain screen titles, labels and helper text only. Routing, required fields, security bindings, consent statements and legal links are locked. Each edit creates a hash-addressed immutable JSON revision on private storage. Selecting a reviewed revision disables dispatch, clears the runtime pointer and retains published synchronization history; live unconsumed drafts must finish or expire first. Create/recover draft is idempotent by definition name; upload checks actual remote bytes before skipping. Remote comparison downloads only the verified Meta asset host `mmg.whatsapp.net`, follows no redirects, sends no bearer token to signed URLs, limits bytes and reports changed paths without remote values.

Publishing requires local and remote validation, a matching reviewed asset and healthy remote status. It never activates dispatch. Meta business integrity failures are explained as account-level remediation; retries do not bypass them. Obsolete Flow retirement accepts only recorded IDs and protects current/last working published pointers and live sessions.

## Activation and policies

Activation requires observations less than ten minutes old: owned connected phone, healthy published Flow, valid matching encryption signature, signed encrypted endpoint ping and structural remote-definition match. Observations bind the account, phone and definition version/hash. Public percentage rollout additionally requires explicit confirmation that controlled live onboarding was completed. Keep production disabled until that journey passes.

Policy publication creates a new private immutable UTF-8 archive with exact SHA-256 and a server-owned immutable record. It does not select that version automatically. Policy selection verifies both archives and consent-presentation binding and disables dispatch. Existing evidence remains unchanged. English is the currently reviewed locale. While the module is enabled, its adapter applies selected versions to the shared registration policy service; deploy matching host policy configuration before removing the module. Terms remain the shared Terms, including section 5; no separate vendor policy is introduced. The administrator must supply approved wording; no production policy text is generated here.

## Applications and media recovery

Applications expose masked identity, state, observed screen, allowlisted field names, safe events and media processing status. Raw drafts, token hashes and documents are not displayed. Recovery can expire abandoned unregistered drafts, assign human support or record review. Registered media processing resumes the existing canonical attempt; it does not create another vendor. Credential resend requires a still-pending unapproved account, bound sender, an open trusted Meta messaging window, no completed credential setup and cooldown. Durable credential completion is stored independently of analytics retention. Inbox handoff and its audit are transactional.

Media reconciliation is bounded and private, checks owning session under a database lock and removes only unowned images older than 24 hours. Completed vendor ownership is protected. No force-complete or arbitrary media deletion is exposed.

Cleanup is disabled by default. Administrators may explicitly choose hourly/daily cadence, expired-draft grace period and optional analytics retention. The module scheduler checks every fifteen minutes, but the existing scheduler/worker must actually run. Each pass is bounded to 50 drafts, 500 old analytics events and 250 media objects. Legal evidence, policy archives, administrative audits and definition revisions are never retention-deleted. Deleting analytics reduces historical funnel coverage. Image/document limits cannot exceed the reviewed canonical contract.

## Diagnostics and test centre

Saved observations include remote status/errors, encryption, same-app signed encrypted ping and WABA subscription counts. App-level webhook field subscriptions need a separate app credential boundary and are reported as unverified rather than inferred. Queue counts, stale operations, cleanup/policy-integrity results and bounded read-only token eligibility audit are redacted. A support JSON report contains safe operational metadata only. Token audit examines at most 500 owners and 500 employees; it never revokes tokens.

Recipient preflight checks explicit allowlisting, existing vendor identity, contact state, trusted inbound timestamp/open service window and published readiness. Existing vendors cannot be overwritten. A successful preflight is short-lived and one-use; eligibility is checked again before dispatch. A resumable application must originate from the recipient's inbound conversation. Closed windows require the existing approved template process; this screen does not bypass Meta messaging rules.

The previously approved controlled recipient is already an existing vendor. Do not use it to overwrite its account or choose a different real number without explicit approval. Current account-level Meta publication restrictions cannot be repaired by this dashboard.

## Migration and release

`2026_10_08_000006_create_flow_control_centre.php` adds five module tables, structured sync errors and durable credential completion. Apply only after a consistent backup and regression checks. It does not enable dispatch or add permissions to non-root roles. Private definition/archive directories must remain outside all served roots. Credentials and RSA keys remain deployment-managed and never browser-editable.

Release: validate code/tests and backup; apply the module migration through the approved deployment; verify authenticated routes and denied unauthorized requests; confirm runtime disabled/rollout zero/chat fallback enabled; refresh read-only observations; review policies; perform the controlled end-to-end journey before activation. An immutable audit database rollback is not the operational rollback. Emergency disable first, preserve evidence, select the prior reviewed definition, inspect its published sync, choose its known working published pointer through guarded settings and re-run readiness. Never delete acceptance evidence or published archives.

## Official Meta references

Access date: 2026-10-08. The official developer Flow API page was rate-limited during this review; do not treat an unavailable page as successful fresh verification. Existing verified Flow JSON 7.3, data API 3.0 and Graph v26.0 contracts are retained.

- https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/reference/flowsapi
- https://www.postman.com/meta/whatsapp-business-platform/request/uje0iad/deprecate-flow
- https://www.postman.com/meta/whatsapp-business-platform/request/ze1wmk6/get-flow
- https://www.postman.com/meta/whatsapp-business-platform/request/dxiu763/publish-flow

A read-only authenticated production asset-list inspection confirmed `FLOW_JSON / flow.json` uses `mmg.whatsapp.net`. No signed URL or token was retained in this document. Publication is distinct from activation; account business-health restrictions remain authoritative.
