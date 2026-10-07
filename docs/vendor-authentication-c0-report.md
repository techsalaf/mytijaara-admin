# Phase C0 vendor authentication hardening

Date: 2026-10-07. Local, uncommitted implementation. No production database or account was accessed. Phase C implementation remains gated on the final C0 checks; verification of public Meta documentation is read-only.

## 1. Root cause
The old API login's `none`/`unsubscribed` subscription branches issued the ordinary `auth_token` before verifying approval. The web registration branch could present subscription setup before checking a password. Middleware treated bearer existence as authorization. Subscription payment could change store status using a caller-supplied `type`. Vendor reset tokens shared plaintext legacy admin storage, lacked consumption-time expiry/single-use guarantees and did not revoke bearer access. The combination let a password/reset/payment bypass pending-application approval.

## 2. Affected paths and trace
See [pre-change trace](vendor-authentication-c0-trace.md). Owner and employee API/web login, custom bearer issuance, owner/employee middleware, web sessions, registration/subscription routes, payment callbacks, administrative approval/rejection/suspension, Rental administrative routes, API/web password reset and OTP verification, logout, and the standalone Concierge draft credential-setup route were inspected. No vendor OAuth, Passport/Sanctum issuance, bearer refresh, or impersonation route was found; customer OAuth/Passport paths are separate. Supplementary module search found Rental status/approval adapters; those now revoke persisted access under locks and use CSRF-protected POST forms.

## 3. Eligibility matrix
| Current state | Full API/web access | Owner subscription setup |
| --- | --- | --- |
| Vendor missing/deleted; store missing/deleted/mismatched | Denied | Denied |
| Vendor pending (`status=null`), store0, business model none | Denied | Valid credentials and restricted authorization only |
| Vendor pending with other model/state | Denied | Denied |
| Rejected/inactive vendor0 | Denied | Denied |
| Approved vendor1, suspended/inactive store0 | Denied | Denied |
| Approved vendor1/store1, commission | Allowed | Full-token owned-store path |
| Approved vendor1/store1, none/unsubscribed/expired subscription | Denied | Valid owner credentials and restricted authorization |
| Approved vendor1/store1, active nonexpired subscription | Allowed; API requires mobile_app entitlement | Owned-store path |
| Unknown business model or disabled Rental/Service addon | Denied | Denied |
| Employee belonging to another owner/store, inactive/deleted | Denied | Denied |
| Temporarily closed `store.active=0` | Approval/entitlement decision unchanged | Decision unchanged |

`active` is store availability, not application approval. Store0 cannot distinguish administrative suspension from automatic expiry; reactivation requires administrative action rather than permitting payment to reverse suspension. Reset/setup never approves or activates an account.

## 4. Files changed
The delivery file inventory is attached below. A/B registration changes already existed and are distinguished from the C0 security work by the policy/services/routes/tests described here. Source changes are registered in `scripts/core-patches.json`.

## 5. Canonical interface
`VendorAuthenticationEligibility::evaluate(?Vendor, ?Store, string channel, ?VendorEmployee): VendorAccessDecision` returns `eligible`, stable `reason`, message category and `preActivationAllowed`. Only api/web channels are accepted. It is shared by API/web login, both guarded access paths, restricted authorization, registration session proof and the audit command. Failures return a generic public message and stable code rather than internal state. The policy is host-owned and works with Concierge disabled.

## 6. Full-token issuance and ongoing access
Credentials are checked before access decisions and rechecked inside the transaction. Lock order is store, vendor, employee/token. A 120-character cryptographically random bearer is issued only on eligible API login. Web login does not issue a bearer. Failed credentials yield no setup token or session proof. Middleware checks current authoritative approval/store/entitlement on every request, rejects unknown principal types/scopes, and clears only the presented stale bearer (not a concurrently replaced bearer). Status/application changes invalidate owner/employee access and web session nonces before persisting the decision. API and web logout revoke the presented principal's bearer. Query exceptions around plaintext bearer lookup/issuance are redacted.

## 7. Restricted-token protocol
The opt-in login header is `X-Vendor-Auth-Version: 2`. Successful credential verification for setup-only eligibility returns `code` plus `pre_activation` containing token, purpose, TTL900, owned store ID, package/module metadata; there is no top-level normal bearer. Older clients receive HTTP403 using their existing errors envelope and `subscription_setup_required` rather than a misleading normal-login result.

Setup token: 256 random bits; only purpose-bound HMAC-SHA256 stored; 15-minute expiry; vendor/store binding and keyed subject fingerprint (normalized email/phone, creation timestamp, ID); one successful use. It can authorize only owner POST `/api/v1/vendor/business_plan` for its bound store. It cannot reach normal APIs, profile, FCM, orders, finance/cancellation/quotation, or be exchanged/padded into full access. Wallet payment is denied for restricted setup. A successful commission selection or payment initiation consumes authorization. Failed correction responses retain it until expiry. Payment completion remains dependent on administrative approval and never activates store status.

## 8. Reset and credential setup
API password reset uses a six-digit OTP, account-bound keyed HMAC, purpose `password_reset_api`, TTL10 minutes, request/attempt rate limits and route throttling. Web link uses `vr1_` plus 256 random bits, purpose `password_reset_web`, TTL15 minutes, trusted HTTPS APP_URL. Invalid/expired prefixed vendor links do not enter legacy raw admin-token lookup. Vendor reset pages use no-store and no-referrer headers. Legacy vendor reset rows are intentionally rejected; no legacy backfill. Legacy admin rows are explicitly admin-only.

Both reset paths atomically lock identity/token, verify purpose/expiry/current subject/single-use, change only password, rotate owner web nonces, revoke owner/employee bearers and employee web nonce where present, consume the used record and revoke other outstanding security tokens. Pending applicants can intentionally set/reset a password; they still cannot authenticate. Account identity changes invalidate outstanding tokens. Delivery failure revokes new reset authorization without logging tokens or mail credentials.

Existing module `CredentialTokenService` remains a separate 256-bit hashed, expiring, single-use draft-password preparation mechanism. Real-route tests verify it issues no full token, no guard session and no host vendor/store record. Phase C will add the approved post-registration setup purpose without changing approval semantics.

Migration file: `database/migrations/2026_10_07_000001_create_vendor_security_tokens_table.php`. Created locally, not applied to a configured host or production database. Tests invoke it only on disposable SQLite/MySQL fixtures. Ephemeral credential rows cascade on vendor deletion to prevent ID reuse; this is not legal consent evidence (that later ledger must not cascade).

## 9. Audit and revocation operations
`php artisan vendor:tokens-audit` is read-only. `--revoke` alone is dry-run. `--revoke --execute` explicitly applies ineligible-token revocation; `--chunk=200` controls bounded processing. The execution path re-evaluates current eligibility under locks and compares the exact token before clearing it. Repeated execution is idempotent. Output contains principal IDs/types/reasons/business model/counts; no bearer values. Token age and original issuance path are UNKNOWN because the legacy schema has no provenance. Current none-model state is not proof of historical bypass issuance. No real-token revocation command was executed.

## 10. Store-app compatibility
Read-only inspection of `<local-path-redacted>`: `auth_service.manageLogin` treats the old subscribed token as normal auth, stores it globally, and calls FCM/profile before package selection; the package-present branch does not save that token. `business_repo` uses the global API token and posts store_id. The verification UI accepts only four OTP characters.

Required app update: six-digit reset OTP; opt-in version header; parse pre_activation separately; keep the short-lived restricted token separate from the normal auth slot; send it only to the one allowlisted owned-store POST; skip profile/FCM/orders before full approval; redirect/payment continuation then fresh eligible login. No pre-activation-to-full exchange. Approved eligible logins retain the established normal response envelope. The app repository was inspected, not edited or released; compatibility for the new protocol is not claimed for the current binary. Legacy clients fail safely on setup-only accounts.

## 11. Verification
Final sequential results (external XML/log evidence: `<external-verification-directory>`):

| Gate | Result |
| --- | --- |
| Architecture, authentication/reset/routes/middleware and disposable MySQL races | PASS: 168 tests, 835 assertions; 1 PHPUnit deprecation |
| Concierge, including credential and payment integration | PASS: 294 tests, 1,504 assertions; 50 PHPUnit deprecations |
| API registration | PASS: 36 tests, 211 assertions |
| Shared registration | PASS: 77 tests, 369 assertions |
| Credential routes | PASS: 8 tests, 66 assertions |
| Subscription lifecycle isolated rerun | PASS: 4 tests, 23 assertions |
| PHP lint | PASS: all 56 changed/new PHP files |
| Pint | PASS: new/rewritten files and scoped changed legacy methods |
| Boundary checker / whitespace | PASS |
| Configured static analysis | No PHPStan/Psalm configuration found |
| Full default host suite | 50 tests, 13 assertions, 46 errors, 1 failure, 1 deprecation; SAME 47 affected testcase identities/error types as untouched baseline, zero new failures |

The default suite is not green: existing fixture/provider/schema failures remain. See `c0-host-comparison.json`, `c0-architecture-final.xml`, `c0-concierge-final.xml`, `c0-host-final.xml`. Security/integration gates pass; this baseline limitation is explicitly retained. Full feasible default-suite failures are compared by testcase identity and error type against the established untouched baseline; they are not counted as passing tests.

## 12. Remaining risks and limits
Legacy ordinary bearer columns remain plaintext for existing API compatibility; C0 does not claim a bearer-at-rest hashing migration. TLS, protected logs/backups and later rotation remain operational requirements. Password strength checks depend on the host's configured compromised-password service. Administrative account reset is outside vendor C0 scope. The exact production schema/status data and currently issued tokens were not audited. Existing module draft credential preparation is not yet the post-registration Flow setup service. Some test infrastructure emits PHP/PHPUnit deprecations. No remote/sandbox integration or mobile binary was run.

## 13. Safe rollout order (future approval required)
Review local code/migration and test evidence; rehearse against disposable staging schema/data; coordinate a store-app release that accepts the six-digit OTP and understands the opt-in protocol (a transition build can accept both four/six digit formats, without relaxing the backend verifier); apply the security migration before deploying routes/services; deploy backend hardening with Flow feature still disabled, accepting temporary setup/reset incompatibility for legacy binaries rather than keeping the bypass; release/enable the compatible app protocol after backend hardening; inspect token audit read-only and review unknown provenance/counts; authorize any real revocation separately; verify eligible owners/employees/remembered sessions/subscription callback and pending denial; only then separately approve Flow sandbox synchronization and later publication/rollout. Never restore the vulnerable authentication/subscription path as a rollback. Restore app compatibility by keeping the opt-in feature unavailable while preserving denial; investigate entitlement/data corrections through approved admin operations.

## 14. Phase gate and restrictions
C0 security and integration gates passed; Phase C resumed automatically on 2026-10-07. Phase C's existing chat fallback remains present. Nothing was committed, pushed, deployed, migrated in production, revoked against production, changed in a production environment, published/activated in Meta or sent to real vendors.
