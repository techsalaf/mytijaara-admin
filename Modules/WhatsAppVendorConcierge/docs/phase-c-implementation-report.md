# Phase C delivery snapshot (before Phase D checkpoints)

Local implementation, uncommitted. Production onboarding defaults OFF. No Meta mutation or real-vendor message was performed. C0 authentication hardening passed before this phase resumed; see the host `docs/vendor-authentication-c0-report.md` for its evidence.

## Architecture and data flow

Incoming signed WhatsApp webhook -> dedicated `nfm_reply` recognition -> immutable allowlisted FlowSubmission -> encrypted queue job -> opaque token-hash/session/sender/Flow/version binding -> locked session + unique message receipt -> shared VendorSelfRegistrationService -> pending vendor/store + both immutable policy evidence rows + private prepared media in one registration transaction -> commit -> resumable media publication -> consume token and completion event -> after-commit credential notification. Setting a password never approves the application or grants pending-vendor access.

The encrypted data endpoint supplies live module/zone/package/rental options and exact policy manifests, accumulates encrypted drafts, and stages decrypted picker media. Completion delivers only correlation and definition identifiers, plus a submitted marker, in `nfm_reply`. Every domain field and ID is revalidated at registration. The endpoint has no sender claim; sender authorization comes from the signed final webhook. Tokens never enter conversation storage or AI inputs. Unused legacy base64/generic-object Flow helpers now fail closed.

Media publication runs after the pending application commits. A storage failure can therefore leave an incomplete pending application, but cannot mark the session completed or send setup success. Recovery resumes publication for that existing application and never recreates it. Private cleanup checks successful public objects and hashes before deleting owned staging copies.

## Screen and field matrix

| Screen | Canonical fields | Rules |
|---|---|---|
| OWNER | owner first name, surname, email | required |
| STORE | default/locale name and address, eligible module | required; no invented store type/categories |
| LOCATION_DELIVERY | latitude, longitude, eligible zone, min/max delivery time, unit; rental pickup zones | text coordinates bounded/spatially checked; rental fields conditional |
| PLAN_LOGO | commission/subscription plan, eligible package, logo | plan reflects enabled host models; package conditional; logo required |
| COVER | cover image | required |
| TIN | TIN, expiry date, document | optional; document constrained to JPEG/PNG |
| REVIEW | summary, exact immutable terms/privacy URLs and versions, separate agreement and acknowledgment | two explicit controls; presentation hash bound server-side |

Seven screens are required because current Meta allows only ONE PhotoPicker or DocumentPicker per screen. Three media roles therefore occupy separate screens. No officially supported GPS picker was found in the verified component catalogue; bounded latitude/longitude text inputs are the documented fallback. The server takes normalized phone identity from the WhatsApp sender. A password is never requested in the Flow.

Long canonical text fields use the officially documented TextArea with its default 600-character capacity; server limits remain 100 for owner/email/TIN, 250 for store name, and 500 for address. Email is validated server-side. The current TextInput table labels length settings as strings for dynamic bindings without an unambiguous literal example, so this definition omits optional length-setting properties rather than guessing their wire type. Coordinates and delivery values use documented TextInput defaults and server bounds. This definition has reviewed English UI only; other application locales fall back to chat until a separate translated definition is reviewed. Locale is displayed with policy versions.

## Verified Meta contract

Access date: **2026-10-07**. Official Meta pages were read in the browser where the fetch API was rate limited. Graph API v26.0 is the current listed release (2026-07-29). JSON 7.3 is supported. The changelog lists Data API 4.0 with optional JWT correlation signatures; endpoint and JSON implementation guides still document 3.0 wire encryption. This implementation deliberately uses documented supported **3.0**, not a guessed 4.0 cryptographic envelope.

| Contract | Verified implementation choice |
|---|---|
| JSON/components | 7.3, SingleColumnLayout, <=50 components; typed dynamic data/examples; If conditional branches; endpoint-driven navigation |
| Option limits | dropdown <=200 without images; checkbox choices <=20; fail closed instead of truncating eligible IDs |
| Picker limits | one picker/screen, 1 file/role; configured 2 MiB versus documented 25 MiB/file ceiling; <=10 files/100 MiB completion ceiling |
| Picker payload | endpoint media_id/cdn_url/file_name/encryption_metadata; complete payload uses Cloud media metadata; our final payload does not carry media |
| Media decryption | SHA256 encrypted bytes; 10-byte HMAC-SHA256 truncation over IV+ciphertext; AES256CBC/PKCS7; plaintext SHA256 |
| Endpoint encryption | RSA2048 OAEP SHA256 + MGF1 SHA256; AES128GCM appended 16-byte tag; response bitwise-inverted IV |
| Health/errors | encrypted ping -> active; endpoint error notification -> acknowledged; bad cryptography HTTP421 |
| Final reply | interactive.nfm_reply.response_json is JSON string; Flow ID is not inherently supplied, so explicit custom completion identifiers are checked against bound server state |
| Create | POST WABA_ID/flows; no publish field/default publication |
| Metadata | POST FLOW_ID |
| Upload/validation | POST FLOW_ID/assets multipart file, name=flow.json, asset_type=FLOW_JSON; inspect validation_errors on upload and GET FLOW_ID; no invented /validate route |
| Publish | POST FLOW_ID/publish, only explicit --execute --publish |
| Send | POST PHONE_NUMBER_ID/messages; interactive flow, flow_message_version string "3", flow_action=data_exchange; draft/published mode |
| Encryption key | POST/GET PHONE_NUMBER_ID/whatsapp_business_encryption; separate approved operational step, not executed |

- [Components](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/components)
- [Media](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/media_upload)
- [Changelog](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/changelog)
- [Endpoint](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/implementingyourflowendpoint)
- [Public key](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/whatsapp-business-encryption)
- [Flows API](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/flowsapi)
- [Flow JSON](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/flowjson)
- [Send](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/sendingaflow)
- [Receive](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/receiveflowresponse)
- [Cloud media](https://developers.facebook.com/documentation/business-messaging/whatsapp/business-phone-numbers/media)
- [Graph versions](https://developers.facebook.com/docs/graph-api/changelog/versions)

## Security and threat model

- Correlation tokens use 256 bits of CSPRNG entropy, expire after 60 minutes, are hash-only in persistent storage, and bind sender, host onboarding session/contact, Flow ID and definition version. Redispatch rotates tokens. Successful consumption is single-use; duplicate message IDs are database unique and idempotent.
- Raw Flow JSON is excluded from normal conversational jobs, model context, admin summaries and general logs. Encrypted draft/manifest columns require durable protected application encryption keys. Queue payloads are encrypted. Setup token is delivered in a URL fragment, removed by the credential page before submission; it never appears in the request path/referrer.
- App-secret HMAC authentication covers raw webhook/data-endpoint bytes. WABA/phone IDs are checked on final webhook routing. TLS, production private-root readiness and a synchronized published asset hash gate production offers.
- Private media is authenticated, origin allowlisted, bounded in time/bytes/dimensions/pixels, decrypted and hash/HMAC checked, MIME inspected, fully decoded and re-encoded. Logo/cover allow JPEG/PNG only. URLs, keys and document contents are not logged. Global media-identity constraints reject cross-session reuse.
- Database locks serialize session completion and canonical normalized identity checks. Historical/import/admin identity writers are outside that canonical lock; raw host unique constraints and duplicate-race mapping remain in place. Do not describe this as a completed historical identity-index migration.
- Both immutable consent rows are written with registration; database triggers prohibit update/delete, and evidence foreign keys restrict deletion. No legacy evidence backfill. Published policy content, versions, storage objects and retention are supplied by separately approved legal operations, never invented here.
- Pending credentials remain unusable for authentication. Setup purpose, vendor/store binding, expiry, hash-only storage and single-use reset are enforced by the C0 service. Password reset does not change approval status.

## State transitions

- `flow_offered` -> 'flow_opened','failed_recoverable','failed_terminal'
- `flow_opened` -> 'flow_draft','correction_required','failed_recoverable','failed_terminal'
- `flow_draft` -> 'flow_draft','media_processing','flow_submitted','correction_required','failed_recoverable','failed_terminal'
- `media_processing` -> 'flow_draft','failed_recoverable','correction_required','failed_terminal'
- `flow_submitted` -> 'flow_draft','registration_processing','correction_required','failed_recoverable','failed_terminal'
- `registration_processing` -> 'registration_completed','correction_required','failed_recoverable','failed_terminal'
- `registration_completed` -> 'credential_setup_pending'
- `credential_setup_pending` -> (terminal)
- `correction_required` -> 'flow_draft','media_processing','flow_submitted','failed_terminal'
- `failed_recoverable` -> 'flow_draft','media_processing','flow_submitted','registration_processing','failed_terminal'
- `failed_terminal` -> (terminal)

Reopening refreshes correlation/expiry and the current policy manifest while preserving the same owned encrypted draft. BACK supports corrections before final submission; the final draft is sealed until a server validation correction or explicit token rotation. A server HMAC binds the displayed vendor details, owned media and policy presentation to the final request; both controls must be explicitly true in that request, and reoffering clears old agreement values; final validation repeats canonical rules. Only observable endpoint INIT/progress is counted as opened/progress, not inferred client activity. Funnel analytics contain session/event/screen/time only; diagnostics aggregate counts and safe status codes.

## Migrations created, not applied to production

1. database/migrations/2026_10_07_000001_create_vendor_security_tokens_table.php (C0 purpose-bound credential tokens).
2. database/migrations/2026_10_07_000002_create_registration_policy_evidence.php (immutable policies/acceptances and private prepared-media journal).
3. Modules/WhatsAppVendorConcierge/database/migrations/2026_10_07_000003_create_vendor_flow_sessions.php (sessions, receipts, media, events, sync journal).

Only disposable SQLite and explicitly enabled loopback random-name MySQL test schemas were migrated. Destructive down migrations for legal evidence/Flow journals require an approved preservation plan. Development/test policy content exists only in test fixtures. Production `registration-policies.current` starts empty. Legacy web/API evidence remains optional until the coordinated `require_versions` switch is enabled; the Flow always requires it.

## Diagnostics and recovery

`php artisan whatsapp:flow-operations` emits admin-safe ID/version/mode/sync status, recent receipt and state counts, expiry and funnel counts, orphan count. No private payloads, tokens or documents.

- `--retry`: preview committed incomplete applications/pending notifications; `--execute` resumes existing media publication or dispatches a notification.
- `--cleanup`: preview expired/abandoned and verified successful staging copies/orphans; `--execute` cleans only owned private objects. Never deletes public registered media or required evidence.
- Setup-link expiry: vendor replies SETUP through the trusted inbound sender path to receive a new purpose-bound link; no account recreation. Interactive/freeform responses require the Meta customer-service window. A delayed out-of-window notification stays retryable; an approved template must be configured operationally before such delivery is enabled.

## Sandbox test plan (requires separate approval for external actions)

1. Apply migrations in an isolated sandbox, provision durable private directories outside served roots and the application parent, trusted HTTPS URL, app secret and sandbox messaging credentials. Never reuse production recipients.
2. Install an approved immutable policy pair with exact content hashes/object references and locale/version config; use development fixture documents only in sandbox.
3. Generate/protect RSA2048 key outside web root and the application parent; register public key for sandbox PHONE_NUMBER_ID and verify signature status VALID. Exercise encrypted ping and bad-signature/ciphertext rejection.
4. Run local definition and fixture validation. Explicitly create/upload/inspect draft against sandbox WABA; check all returned validation errors and health status. Confirm runtime Flow remains unchanged.
5. Allowlist only sandbox test senders in draft mode. Walk all seven screens: ordinary commission, subscription eligible/ineligible package, rental pickup, no optional TIN, valid optional image, missing/replaced logo/cover, invalid coordinates and stale policies.
6. Inspect actual encrypted picker metadata/CDN host, the final nfm_reply shape, Android/iOS compatibility and older unsupported clients. Check no raw JSON/token enters chat/admin/AI/logs.
7. Resend identical message, submit replay/wrong sender/expired token, run two workers, fail media and public storage mid-attempt, recover existing application, expire abandoned drafts and clean orphans.
8. Set credential once, expire/replay it, request SETUP anew; confirm pending/rejected/suspended owner/employee authentication and password resets never bypass approval. Verify existing chat fallback.

## Publication and rollback runbook

These commands are provided for review; none was run against Meta here.

- `php artisan whatsapp:flow-validate --fixture=.../fixtures/valid_submission.json`
- `php artisan whatsapp:flow-sync --action=status` (local status).
- `php artisan whatsapp:flow-sync --action=create` (dry run, no Meta call).
- After separate approval: `--action=create --execute` discovers/reuses a deterministic-name draft, uploads the versioned asset, rejects validation errors. Synchronization locks the version row; an ambiguous remote create is reconciled by WABA listing rather than blindly recreated. Duplicate remote names require manual inspection.
- `--action=remote` performs read-only remote validation; asset upload is the documented mutation that invokes Meta validation.
- Publication requires BOTH `--execute --publish` and matching local asset hash. Never publishes by default. Runtime Flow ID is unchanged by synchronization. A failed replacement retains the prior published pointer.
- After sandbox verification and separate approval, activate the matching published ID/version through environment rollout configuration, starting with test allowlist/small percentage. Existing pending draft versions need to finish or be explicitly reopened.
- Roll back by disabling Flow offers or restoring the prior published ID/version and preserving chat fallback, immutable evidence and journals. Do not run destructive migration rollback. Published Flow changes require a new local definition version; do not overwrite the current published asset.

## Known limitations and release gates

Local validation enforces a reviewed schema subset and semantic contract; it does not replace Meta asset validation. No remote draft was created, validated or published. Manual sandbox/client compatibility, actual media/CDN behavior and Graph permissions must pass before activation. Data API 4.0 migration is intentionally not guessed. There is no native GPS picker in the verified catalogue. Optional TIN documents are images only; PDF support is not claimed. Endpoint option overflow safely refuses the Flow; chat remains available. No production legal fixtures, retention period, credential origin, keys or durable directory settings were invented. Notifications can duplicate after an ambiguous external acknowledgment, but never recreate registration records. The store app still requires the separately reviewed C0 restricted-auth/six-digit-OTP client release. No static analyzer is configured. The installed development Debugbar collector is disabled on WhatsApp webhooks, the encrypted endpoint and secure credential pages to prevent request-body capture.

## Verification

Tests ran sequentially, including all fake-storage suites. Results are not summed because focused reruns overlap full suites.

| Verification | Exact result |
|---|---|
| Final focused Flow integration/transport | 64 tests, 281 assertions; pass; 1 PHPUnit deprecation |
| Complete Concierge with loopback MySQL enabled | 355 tests, 1772 assertions; pass; 50 PHPUnit deprecations; before the final private-path, policy-wording and long-text additions, covered by the focused rerun |
| Shared registration and credential security | 44 tests, 234 assertions; pass; 1 deprecation (shared 36/168, credentials 8/66) |
| Complete architecture | 169 tests, 842 assertions; 168 passed, one source snapshot mismatch; corrected snapshot then focused source suite passed 5 tests/16 assertions |
| Architecture API registration | 36 tests, 211 assertions; pass within architecture run |
| Architecture authentication | MySQL 44/193 and application 42/183; pass |
| Architecture host registration / subscription | MySQL 12/80 and subscription 6/24; pass |
| Actual MySQL Flow concurrency | 1 test, 7 assertions; pass both isolated and in architecture |
| Full feasible default application suite | 50 tests, 13 assertions, 46 errors, 1 failure; all 47 affected test/error identities exactly match unchanged baseline; zero new failures |
| PHP lint | 92 impacted PHP files, zero errors |
| Pint | all new PHP files passed final check; ten changed legacy methods passed scoped formatting checks |
| Core boundary / whitespace | boundary checker exit 0; git diff --check passed; no staged files |

The default suite is not green: its existing harness/database prerequisite failures remain. The complete architecture suite was not rerun after its snapshot-only correction; its corrected source gate was rerun. PHPUnit deprecations remain visible rather than being suppressed. No PHPStan/Psalm configuration exists. Pint, boundary and whitespace checks passed as recorded above.

Machine-readable JUnit, logs and baseline comparison are in `<external-verification-directory>`, especially `phase-c-flow-final.xml`, `phase-c-concierge-release.xml`, `phase-c-shared-credentials.xml`, `phase-c-architecture-final.xml`, `phase-c-source-final.xml`, `phase-c-host-final.xml` and `phase-c-host-comparison.json`.

## Complete file impact

This inventory covers the combined uncommitted Phase A/B/C0/C work so reviewers can see every current repository impact. It does not attribute pre-existing work to Phase C. `.rnd` was already modified when this continuation began; it is runtime crypto state and is not implementation code. Test-generated translation changes were restored exactly to HEAD. Ignored documentation/config artifacts are listed separately.



Tracked modified files:

- `.rnd`
- `Modules/Rental/Http/Controllers/Web/Admin/ProviderController.php`
- `Modules/Rental/Resources/views/admin/provider/list.blade.php`
- `Modules/Rental/Resources/views/admin/provider/new-request-details.blade.php`
- `Modules/Rental/Resources/views/admin/provider/new-request.blade.php`
- `Modules/Rental/Routes/web/admin/admin.php`
- `Modules/WhatsAppVendorConcierge/app/DTOs/VendorApplicationDTO.php`
- `Modules/WhatsAppVendorConcierge/app/Http/Controllers/Api/WebhookController.php`
- `Modules/WhatsAppVendorConcierge/app/Http/Middleware/SecureCredentialPage.php`
- `Modules/WhatsAppVendorConcierge/app/Jobs/ProcessIncomingWhatsAppMessage.php`
- `Modules/WhatsAppVendorConcierge/app/Models/WhatsAppMessage.php`
- `Modules/WhatsAppVendorConcierge/app/Providers/WhatsAppVendorConciergeServiceProvider.php`
- `Modules/WhatsAppVendorConcierge/app/Services/ConversationManager.php`
- `Modules/WhatsAppVendorConcierge/app/Services/CoreAdapters/VendorApplicationService.php`
- `Modules/WhatsAppVendorConcierge/app/Services/InboundPrivacy.php`
- `Modules/WhatsAppVendorConcierge/app/Services/SubscriptionLifecycleService.php`
- `Modules/WhatsAppVendorConcierge/app/Services/VendorOnboardingService.php`
- `Modules/WhatsAppVendorConcierge/app/Services/WhatsAppGateway.php`
- `Modules/WhatsAppVendorConcierge/routes/web.php`
- `Modules/WhatsAppVendorConcierge/tests/Hardening/ApplicationFixtureTestCase.php`
- `Modules/WhatsAppVendorConcierge/tests/Hardening/ConversationNavigationTest.php`
- `Modules/WhatsAppVendorConcierge/tests/Hardening/CredentialSecurityTest.php`
- `Modules/WhatsAppVendorConcierge/tests/Hardening/SubscriptionLifecycleTest.php`
- `Modules/WhatsAppVendorConcierge/tests/Hardening/VendorApplicationParityTest.php`
- `Modules/WhatsAppVendorConcierge/tests/Unit/WhatsAppVendorConciergeTest.php`
- `app/CentralLogics/Helpers.php`
- `app/Http/Controllers/Admin/VendorController.php`
- `app/Http/Controllers/Api/V1/Auth/VendorLoginController.php`
- `app/Http/Controllers/Api/V1/Auth/VendorPasswordResetController.php`
- `app/Http/Controllers/Api/V1/Vendor/SubscriptionController.php`
- `app/Http/Controllers/LoginController.php`
- `app/Http/Controllers/VendorController.php`
- `app/Http/Middleware/VendorMiddleware.php`
- `app/Http/Middleware/VendorTokenIsValid.php`
- `app/Models/Vendor.php`
- `app/Models/VendorEmployee.php`
- `app/Services/VendorApplicationDecisionService.php`
- `bootstrap/app.php`
- `config/filesystems.php`
- `resources/views/vendor-views/auth/general-info.blade.php`
- `routes/api/v1/api.php`
- `routes/web.php`
- `scripts/core-patches.json`
- `tests/Architecture/MySqlHostRegistrationTest.php`
- `tests/Architecture/RegistrationSchema.php`
- `tests/Architecture/RegistrationSourceContractTest.php`
- `tests/Architecture/fixtures/Controllers-VendorController-store.txt`
- `tests/Architecture/fixtures/PublishedRental.php`

New untracked files:

- `Modules/WhatsAppVendorConcierge/app/Console/Commands/SyncVendorFlow.php`
- `Modules/WhatsAppVendorConcierge/app/Console/Commands/ValidateVendorFlow.php`
- `Modules/WhatsAppVendorConcierge/app/Console/Commands/VendorFlowOperations.php`
- `Modules/WhatsAppVendorConcierge/app/DTOs/FlowSubmission.php`
- `Modules/WhatsAppVendorConcierge/app/Http/Controllers/Api/FlowEndpointController.php`
- `Modules/WhatsAppVendorConcierge/app/Http/Controllers/Web/FlowPasswordController.php`
- `Modules/WhatsAppVendorConcierge/app/Jobs/ProcessVendorFlowSubmission.php`
- `Modules/WhatsAppVendorConcierge/app/Jobs/SendFlowRegistrationNotification.php`
- `Modules/WhatsAppVendorConcierge/app/Models/VendorFlowSession.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowDataExchangeService.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowDefinitionValidator.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowDiagnostics.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowEndpointCrypto.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowFieldMapper.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowMediaService.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowMetaClient.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowOnboardingService.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowOptions.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowStateMachine.php`
- `Modules/WhatsAppVendorConcierge/app/Services/FlowSubmissionProcessor.php`
- `Modules/WhatsAppVendorConcierge/config/flow.php`
- `Modules/WhatsAppVendorConcierge/database/migrations/2026_10_07_000003_create_vendor_flow_sessions.php`
- `Modules/WhatsAppVendorConcierge/resources/flows/fixtures/invalid_submission.json`
- `Modules/WhatsAppVendorConcierge/resources/flows/fixtures/valid_submission.json`
- `Modules/WhatsAppVendorConcierge/resources/flows/flow.env.example`
- `Modules/WhatsAppVendorConcierge/resources/flows/vendor_onboarding.json`
- `Modules/WhatsAppVendorConcierge/resources/flows/vendor_onboarding.schema.json`
- `Modules/WhatsAppVendorConcierge/resources/views/flow-password.blade.php`
- `Modules/WhatsAppVendorConcierge/tests/Hardening/SharedVendorRegistrationTest.php`
- `Modules/WhatsAppVendorConcierge/tests/Hardening/VendorFlowIntegrationTest.php`
- `Modules/WhatsAppVendorConcierge/tests/Hardening/VendorFlowTransportSecurityTest.php`
- `app/Console/Commands/AuditVendorAuthenticationTokens.php`
- `app/DTOs/RegistrationPolicyEvidence.php`
- `app/DTOs/VendorAccessDecision.php`
- `app/DTOs/VendorSelfRegistrationInput.php`
- `app/DTOs/VendorSelfRegistrationResult.php`
- `app/Http/Controllers/Api/V1/RegistrationPoliciesController.php`
- `app/Http/Middleware/VendorRegistrationSession.php`
- `app/Http/Middleware/VendorSubscriptionAccess.php`
- `app/Models/LegalPolicyVersion.php`
- `app/Models/VendorRegistrationConsent.php`
- `app/Services/PrivateRegistrationStorageGuard.php`
- `app/Services/RegistrationPolicyService.php`
- `app/Services/VendorAccessRevocationService.php`
- `app/Services/VendorAuthenticationEligibility.php`
- `app/Services/VendorAuthenticationService.php`
- `app/Services/VendorRegistrationNotifier.php`
- `app/Services/VendorSecurityTokenService.php`
- `app/Services/VendorSelfRegistrationService.php`
- `config/registration-policies.php`
- `database/migrations/2026_10_07_000001_create_vendor_security_tokens_table.php`
- `database/migrations/2026_10_07_000002_create_registration_policy_evidence.php`
- `tests/Architecture/ApiVendorRegistrationTest.php`
- `tests/Architecture/MySqlVendorAuthenticationSecurityTest.php`
- `tests/Architecture/MySqlVendorFlowConcurrencyTest.php`
- `tests/Architecture/VendorAuthenticationSecurityTest.php`
- `tests/Architecture/VendorSubscriptionSecurityTest.php`

Ignored delivery/support artifacts:

- `Modules/WhatsAppVendorConcierge/docs/phase-c-implementation-report.md`
- `docs/vendor-authentication-c0-report.md`
- `docs/vendor-authentication-c0-trace.md`

## Authorization boundary confirmation

Existing chat onboarding remains available behind fallback configuration; production Flow offers default disabled. No commit, push, deployment, production migration/environment change, Meta draft creation/upload/publication/activation, or message to a real vendor occurred. Only disposable local test databases were migrated. HEAD remains `82756c49c8bfe10d48620df99cb135406ea085ad`; index is empty. Implementation is left for review and separate approval before external or release actions.
