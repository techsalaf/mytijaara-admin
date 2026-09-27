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
