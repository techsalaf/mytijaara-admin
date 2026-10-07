# Phase D sandbox bootstrap

Status: local contracts exercised; real Meta validation pending. No staging target, positively identified test WABA, test sending number or approved recipient exists for this task. Existing configured Meta IDs/credentials are UNKNOWN and must not be reused on that basis. No tunnel was installed or started. No branch was pushed.

## Current official setup path

Verified 2026-10-07 against [Meta Get Started](https://developers.facebook.com/documentation/business-messaging/whatsapp/get-started) (updated June 16, 2026) and [WhatsApp app use case](https://developers.facebook.com/documentation/development/create-an-app/whatsapp-use-case).

Create a dedicated development app through Meta App Dashboard, select “Connect with customers through WhatsApp,” and select an isolated development business portfolio. The current path is Customize use case → Connect on WhatsApp → Quickstart → Start using the API → API Setup. Meta's current guide says a Messaging account **may** be created automatically with a new business portfolio. It does not guarantee automatic provisioning of both a test WABA and a test phone number for every account. Inspect the actual dashboard's test resources; automatic provisioning for this account is unverified. App Development mode alone does not classify an attached WABA as TEST.

In API Setup / WhatsApp Manager, find the explicitly labelled test account and Meta-provided test number. Record their exact IDs and the displayed test label. If only production assets appear, stop account selection and ask Meta support/dashboard to provision development resources. Do not attach the production WABA or register a real business sending number as a workaround. No existing credential has been positively identified as development-only, so API creation of a test WABA is not currently authorized or attempted.

## Minimum dashboard work

1. Sign in/register as a Meta developer, create/select the dedicated app and development portfolio, and complete any dashboard agreements yourself.
2. Open API Setup, provision/find the explicitly labelled test WABA and test phone; capture non-secret identity evidence. The guide's current “Messaging account ID” must correspond to the WABA resource used by the Flows API.
3. Place the dedicated test app secret, verification secret and appropriate test-scoped access token in local secret configuration. Do not return secrets in chat or commit them. Scope permissions to the isolated test assets; the Flow and messaging APIs need their respective `whatsapp_business_management` and `whatsapp_business_messaging` permissions. Additional business management operations can require `business_management`.
4. After the WABA/number are ready, choose a recipient you own/control, explicitly approve it for this test, and add it through API Setup's recipient controls. Complete Meta's verification challenge using that recipient. Send nothing until approval and verification both exist.
5. Once the restricted HTTPS callback is ready, configure only the dedicated test app's webhook verification URL/token and `messages` subscription; associate the dedicated app with the confirmed test WABA. Do not change existing production subscriptions.

Do not click the dashboard's “Send message” during resource discovery. Actual test messaging belongs to the approved end-to-end run.

## Return only these non-secret values

First return: test App ID, test WABA ID, test phone-number ID, Meta-provided test sending number, explicit test classification evidence, and the chosen temporary HTTPS callback base URL when ready. Also identify where the dedicated test secrets were configured locally without including their values.

After those resources are ready, return the explicitly approved recipient's E.164 number and confirmation that Meta's recipient verification completed. Recipient approval has not been requested yet because no test account is ready. Return the draft Flow ID after controlled creation, along with draft/published status. Each action gets a preflight containing the exact target IDs and recipient allowlist before any mutation or message.

## Isolated application and database

Use the local development application with a separately configured disposable database named `mytijaara_flow_sandbox_` followed by 16 random hexadecimal characters. Create it on confirmed loopback MySQL/MariaDB, never copy an existing database. The automated test harness already builds representative pre-change schema from DDL only and uses random disposable `mytijaara_isolation_*` and `mytijaara_auth_isolation_*` schemas. It does not import the bundled dump's INSERT/data statements.

Before provisioning a persistent callback sandbox, print and approve the LOCAL target identity: branch/HEAD, application URL, database host/name, test WABA/phone/Flow IDs, Graph version, schema/data version, allowlist, feature and fallback flags. Do not run bare `migrate` against the existing development database. Use a restricted database user and the explicit new schema. For an initially empty schema, use reviewed DDL-only provisioning; host historical migrations require the representative host schema and are not a substitute for it. Then apply reviewed host/module migration sets to this disposable schema and keep the migration ledger.

Provision durable private Flow/prepared-media/policy-archive directories and RSA keys outside the application directory, its parent and all served roots. Configure a dedicated policy archive disk with private visibility and immutable-object operations. Production storage protections need separately approved object-lock/permissions/backups; no retention period is invented here. The local fixture installer refuses ordinary schemas.

## Configuration names

Use the existing `resources/flows/flow.env.example` template. Keep real values outside Git. A test environment additionally needs:

```dotenv
APP_ENV=local
APP_URL=
APP_DEBUG=false
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
APP_KEY=
WHATSAPP_APP_ID=
WHATSAPP_APP_SECRET=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_VERIFY_TOKEN=
WHATSAPP_BUSINESS_ACCOUNT_ID=
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_WEBHOOK_URL=
WHATSAPP_FLOW_ENDPOINT_URL=
WHATSAPP_FLOW_PRIVATE_KEY_PATH=
WHATSAPP_FLOW_PRIVATE_KEY_PASSPHRASE=
WHATSAPP_FLOW_PRIVATE_ROOT=
VENDOR_REGISTRATION_PRIVATE_ROOT=
VENDOR_REGISTRATION_PUBLIC_ROOT=
REGISTRATION_POLICY_ARCHIVE_DISK=
REGISTRATION_TERMS_EN_VERSION=
REGISTRATION_PRIVACY_EN_VERSION=
SANDBOX_POLICY_DOCUMENT_ORIGIN=
WHATSAPP_VENDOR_FLOW_ID=
WHATSAPP_VENDOR_FLOW_MODE=draft
WHATSAPP_FLOW_GRAPH_VERSION=v26.0
WHATSAPP_FLOW_ONBOARDING_ENABLED=false
WHATSAPP_FLOW_TEST_PHONES=
WHATSAPP_FLOW_ROLLOUT_PERCENT=0
WHATSAPP_FLOW_CHAT_FALLBACK=true
```

The Flow definition is `vendor-onboarding-2026-10-07.1`, JSON 7.3, Data API 3.0. An approved English definition is currently available; other locales fall back to chat. Both current policy version settings start unset. Enable test offers only after the target gate passes, with an exact recipient allowlist and zero percentage rollout. Keep normal email, payment and AI integrations mocked/disabled in the callback sandbox; test only synthetic records.

## Synthetic immutable policy fixtures

`resources/flows/fixtures/policies/terms.en.txt` and `privacy.en.txt` contain plainly labelled development/test-only placeholder documents. They are **not MyTijaara's legal policy**. The guarded `fixtures/install_sandbox_policies.php` installer accepts only a bootstrapped local/test CLI with an explicitly named disposable loopback MySQL schema or testing SQLite `:memory:`.

After migrations and dedicated archive disk configuration, run in that isolated configuration:

```sh
php artisan tinker --execute="print json_encode(require module_path('WhatsAppVendorConcierge', 'resources/flows/fixtures/install_sandbox_policies.php'), JSON_PRETTY_PRINT);"
```

It creates `test-terms.en` and `test-privacy.en`, explicit `en` locale, exact SHA256 hashes, private archive objects, UTC effective publication times and current in-process manifest/acceptance presentation. Repeating it reuses matching immutable records and refuses changed bytes/records. Set the two current-version environment values to those exact identifiers for subsequent requests; a Tinker-only config change is not persistent.

For purely local testing, the document origin defaults to `https://example.test`. Before any real Flow, supply the reviewed HTTPS test document origin. The resulting links are `/immutable/<content-sha256>.txt`; serve only those two exact files/byte sequences, read-only, with correct content type. Set the origin before first installing records in the disposable callback schema. An existing record is immutable: a new origin requires a fresh disposable test schema/version, not a row update. The application rehashes archive bytes on every manifest/registration verification; missing, changed or oversized archives fail closed. Confirm served document bytes also match their archive hashes before test publication.

## Temporary callback option

No existing ngrok/cloudflared executable, process or standard configuration was discovered. The smallest proposed option is [Cloudflare Quick Tunnel](https://developers.cloudflare.com/tunnel/get-started/quick-tunnels/) to a dedicated, loopback-bound **restricted callback reverse proxy**, not the whole XAMPP application. This is temporary local development, not staging. Nothing has been exposed.

The proxy must permit only:

- GET/POST `/webhooks/whatsapp` (verification secret for GET; raw-body app-secret HMAC for POST);
- POST `/webhooks/whatsapp/flow-data` (raw-body HMAC plus Flow encryption);
- GET/POST `/whatsapp/flow/password` (purpose-bound fragment setup token, CSRF and rate limiting);
- GET/HEAD the two exact content-hash synthetic policy paths.

Return 404 for every other path, including admin, API, storage and source directories. Disable access/request-body logging, use only minimal safe error logs, cap body size, and preserve raw body/headers. Bind the application upstream and proxy to loopback. Disable Debugbar/Telescope and ordinary outbound integrations. Pin the HTTPS application origin; configure trusted forwarding only for the local proxy so secure credential links and session/CSRF cookies use HTTPS.

An example proxy layout (substitute reviewed local ports and exact policy file aliases) is:

```nginx
server {
    listen 127.0.0.1:8787;
    server_name _;
    client_max_body_size 384k;
    access_log off;
    # Configure a protected minimal error log, with no request bodies/credentials.
    location = /webhooks/whatsapp {
        proxy_pass http://127.0.0.1:8788;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto https;
    }
    location = /webhooks/whatsapp/flow-data {
        limit_except POST { deny all; }
        proxy_pass http://127.0.0.1:8788;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto https;
    }
    location = /whatsapp/flow/password {
        limit_except GET POST { deny all; }
        proxy_pass http://127.0.0.1:8788;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto https;
    }
    # Add two exact /immutable/<verified-hash>.txt locations with read-only aliases.
    # Never use a general public directory listing or a wildcard upstream location.
    location / { return 404; }
}
```

First verify loopback allowed paths, denied paths and invalid-signature rejection. Only after the isolated test targets are confirmed, install the official tunnel binary and run:

```sh
cloudflared tunnel --url http://127.0.0.1:8787
```

Quick Tunnel URLs change on restart and have no availability guarantee. Interactive email authentication is incompatible with non-interactive Meta callbacks; webhook signatures provide the callback authentication. Update only the dedicated test app/Flow endpoint when its temporary hostname changes. Stop the tunnel after tests. The local reverse proxy configuration and actual tunnel behavior remain unexecuted infrastructure work, not claimed validation.

## Automatable steps after target confirmation

Local definition/fixture checks, generated RSA2048 test-only keys, signed/encrypted ping checks, WABA/phone read-only inspection, deterministic draft reconciliation, creation/upload/remote validation, key registration and approved-recipient sending can be automated through the relevant APIs after the exact positive TEST classification and dedicated secrets exist. There is no authorized API target today. Dashboard developer registration, app/use-case setup, positive test-resource selection and recipient verification need the account owner.

Protect generated keys outside served roots; register only the public key on the confirmed PHONE_NUMBER_ID using Meta's `business_encryption` endpoint and verify key signature status. [Encryption requirements](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/whatsapp-business-encryption) and [endpoint protocol](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/implementingyourflowendpoint) remain the authoritative contracts.

Safe local preparation:

```sh
php artisan whatsapp:flow-validate --fixture=Modules/WhatsAppVendorConcierge/resources/flows/fixtures/valid_submission.json
php artisan whatsapp:flow-sync --action=local
php artisan whatsapp:flow-sync --action=create
```

After a complete redacted preflight and confirmed isolated WABA, controlled operations are:

```sh
php artisan whatsapp:flow-sync --action=create --execute
php artisan whatsapp:flow-sync --action=remote
```

These commands are **instructions, not actions performed here**. The Flow API uses WABA_ID for creation/listing, FLOW_ID for asset/status/publication, and PHONE_NUMBER_ID for encryption keys/messages. Upload triggers remote validation; there is no invented standalone validation endpoint. [Flows API](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/flowsapi), [sending](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/sendingaflow), [receiving](https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/receiveflowresponse).

Prefer draft mode for approved test senders. If confirmed test infrastructure requires publication, record its test WABA identity and use `--execute --publish` only there. Keep the previous working test Flow/pointer on failure. Do not publish repeatedly or overwrite a published definition. Remote rejection must be fixed locally and affected regressions rerun before proceeding.

## First end-to-end run

Verify actual endpoint ping/key status, Flow asset validation and health, recipient verification and active customer-service window. Initiate the approved recipient's inbound conversation with the **test** sending number; then offer the allowlisted draft Flow. Complete the seven screens with synthetic owner/store details, valid logo/cover and no KYC; explicitly select both controls after reviewing the exact synthetic documents and versions.

Confirm one pending vendor/store, two immutable consent rows, private staging then successful canonical publication, one completion in the funnel, and a purpose-bound credential link. Create the password once; replay/expiry and pending login remain rejected. Repeat for subscription/rental, corrections, resubmission, real picker download failures and chat fallback. Inspect only this isolated application's records/logs, never production logs/data. Test notifications outside the customer-service window require a separately configured approved template; no template has been invented.

## Remaining real-Meta boundary

Only these observations require external infrastructure: actual account/permission/Flow entitlement; Meta asset/remote validation; key registration and genuine signed/encrypted callbacks over HTTPS; Android/iOS picker/navigation rendering; real encrypted media/CDN payloads and retrieval; real draft/test-publication delivery and recipient allowlist enforcement; actual `nfm_reply` shape/delivery retries; customer-service-window/template behavior; live observed funnel and privacy/log inspection across tunnel, worker and Meta delivery; external transient failures and resource reconciliation. Local test results do not substitute for these observations. Phase D remains open until they run.
