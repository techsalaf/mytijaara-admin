# Recommendation implementation progress

## First release: P0 reliability — 27 September 2026

Everything in this release is module-owned. Core files and mobile/web API contracts are unchanged.

### Campaign truthfulness

Campaign creation remains available as a draft. The simulated Launch endpoint now returns an explicit explanation without queuing messages, changing campaign status or fabricating counts. The list labels historical counts unverified and replaces Launch with a draft-only notice. A real campaign outbox, consent checks, frequency caps and provider receipt accounting remain a later implementation; this release does not claim bulk campaigns work.

### Inbound processing checkpoints

New incoming messages receive a unique receipt with `received`, `processing`, `completed`, `needs_review` or `reviewed` phase. Existing historical messages have no trustworthy checkpoint and are not retrospectively replayed. Receipt data contains IDs and processing metadata, not an extra copy of customer content or credentials.

Messages are serialized per contact as well as per Meta message ID. A retry before business processing reuses its original message row. A changed conversation/newer inbound prevents applying an old answer to a new step. Media enqueue errors now propagate instead of silently dropping processing. An interrupted mutation/send is flagged for human review rather than blindly repeated; this is conservative recovery, not an exactly-once guarantee across Meta and the database.

Operations Centre surfaces interrupted/retry-pending receipts, including older unresolved receipts after newer chats arrive. It disables ordinary nudges for those cases until their effects are inspected.

- `php artisan whatsapp:replay-inbound LOCAL_MESSAGE_ID` previews eligibility.
- Add `--execute` only after review to queue a safe, recent, pre-processing checkpoint. It refuses historical/ambiguous/credential-redacted messages and changed conversation state.
- `php artisan whatsapp:review-inbound LOCAL_MESSAGE_ID` previews manual acknowledgement.
- Add `--reason="Review outcome without secrets" --execute` after checking actual application/product changes and delivery evidence. This records an audit and marks the receipt reviewed; it does not replay it or claim processing completed.

No historical user message is automatically replayed by deployment.

### NVIDIA transport and health evidence

The modern NVIDIA adapter now uses the installed SDK's chat-completions/tool gateway, matching the NVIDIA endpoint, instead of OpenAI Responses. Existing configured credentials, models and the product-image path remain in place.

Provider evidence separates endpoint access, model discovery, text inference and actual tool execution. Rotating credentials clears previous verification. Admin provider rows expose that evidence. A harmless probe tool reads no vendor data and makes no business mutations:

`php artisan ai:test-models CONNECTION_ID --model="ENABLED_MODEL_ID" --tools`

Omit `--tools` for a text-only check. Use one explicit model to conserve quota. A text-only answer cannot pass a tool probe. Failed probes remain recorded as failures; the endpoint check alone never marks text/tool inference verified.

## Following priorities

The ordered backlog remains in [the review](ai-provider-expansion-and-review.md):

1. P1: canonical readiness, product moderation/correction cards, consistent navigation, verified photo fallback and daily shop actions.
2. P2: scalable operational queries/telemetry, opt-in nudges/digests, reviewed translations/voice input, model maintenance/privacy.

These later features are pending, not implicitly included in the P0 release. Complete each with regression tests and an explicit rollout record.

Validation before release: full local suite with disposable MySQL enabled passed 238 tests / 1,209 assertions. Final checkpoint/provider checks passed 19 tests / 72 assertions, including busy-contact retry and audited manual acknowledgement. Existing PHPUnit deprecation notices remain.


## Second release: readiness, review status and draft safety — 27 September 2026

- Readiness now reuses customer product visibility (approval, category, module and plan), checks module-specific stock, valid coordinates, subscription expiry/quota, merchant availability and fulfilment options. Payout setup requires both bank and account. Blocking actions take priority over cosmetic tasks. A complete checklist is explicitly not a guarantee of live checkout availability.
- Vendor menus distinguish merchant availability from administrative approval. Product review and shop readiness are deterministic commands (`product status`, `shop readiness`) and list options, available without AI inference.
- Product review lists are explicitly store-scoped and capped at 10 recent products. Cards distinguish existing approved products from pending/rejected edits, show canonical correction notes and last-update time, and link to the authenticated vendor edit page (including the canonical temporary-product flag). No product/draft/photo is changed by reading status. Older items remain accessible in the dashboard; field-specific WhatsApp correction editing is not implemented in this release.
- Product cancellation now requires confirmation. Three buttons offer cancellation, continued editing, or saving. A per-request nonce invalidates previous cancellation controls. Saved product data remains intact.
- AI product reads distinguish pending approval from enabled listings and clamp limits. Existing confirmed stock/price mutation tools remain in use.
- Selling help welcomes online-only businesses with a fulfilment address and removes unsupported blanket promises about customer counts and payout frequency.
- Orchestration logs contain correlation IDs and response length rather than raw AI output/phone numbers. Credential and document steps are excluded from AI extraction.

Validation: full SQLite concierge suite passed 242 tests / 1,200 assertions (three MySQL-only cases skipped); final focused tests passed 26 tests / 134 assertions. Deployment CI supplies the MySQL gates. Existing PHPUnit deprecations remain. The prior P0 deployment passed its gates and the real NVIDIA tool probe passed on the configured production model, without vendor messages or business mutations.

Remaining: full universal navigation coverage, a second live-verified image account, expanded daily-action discovery, and the P2 operations/preferences/digests/accessibility work remain tracked in the review. These are not represented as shipped by this release.


## Daily commands and alert navigation follow-up — 27 September 2026

`My shop` opens the authenticated dashboard card and explains two explicit, AI-independent commands:

- `Set price #123 13000` prepares a price confirmation in naira.
- `Set stock #123 30` prepares a whole-unit stock confirmation (zero is allowed).

The product ID is shown in review cards. Only the linked approved vendor's product can be selected; malformed amounts, foreign products and variant products do not create pending updates. Existing `PendingActionService` supplies the expiring Confirm/Cancel controls and canonical mutation/moderation/authorization checks. No inventory changes until confirmation. Complex variations and modules without stock use the existing dashboard. Delivery copy explains that store-managed delivery is arranged by the store, not a platform rider dispatch.

`STOP`, `PAUSE ALERTS`, `RESUME ALERTS`, and `ALERTS` now bypass AI and active product forms. Three reply buttons let vendors pause/resume or contact support while preserving the draft and active concierge. These safe commands remain usable at the credential step without retaining other input. `START`/`RESUME` no longer silently opt vendors back into alerts. Existing notification event aliases now honor their category opt-outs.

This does not introduce automated nudges, weekly digests, new provider accounts, or paid services. Those remaining recommendations need their own tested rollout; secondary vision and reviewed language/voice coverage are not claimed complete.
Validation for the follow-up: 30 focused tests / 187 assertions passed, covering unchanged inventory before confirmation, ownership, variants, malformed inputs, draft preservation, safe credential-step buttons and category opt-outs.

## Consent and provider maintenance follow-up — 28 September 2026

Recovery reminders and weekly-digest preferences are separate, explicitly consented choices. Both default to off. A vendor can type `ENABLE NUDGES`, `DISABLE NUDGES`, `ENABLE WEEKLY DIGEST`, or `DISABLE WEEKLY DIGEST`; every change is retained in the consent audit log. The recovery centre will not send a current-step reminder unless that vendor opted in, in addition to the existing active-session, quiet-hours, support-case, 24-hour-window, cooldown, and daily-limit checks. No digest sender or campaign sender is enabled by this work.

`php artisan whatsapp:refresh-ai-models` refreshes active provider catalogues without sending messages or running inference. It skips a connection refreshed in the prior 24 hours unless `--force` is supplied, preserves manual selections through the existing discovery service, and runs daily at 02:40. A catalogue refresh is deliberately not treated as a provider health or tool-execution verification.
