# Concierge provider expansion and vendor experience review — 27 September 2026

## Delivered in this change

All application changes are inside `Modules/WhatsAppVendorConcierge`. No core controllers, mobile APIs, checkout contracts or vendor data are changed.

Three optional provider definitions, encrypted credential entry, model discovery and adapter routing are added. The migration is additive and idempotent: it creates definitions, not accounts, credentials or active paid subscriptions. Existing connections are not replaced. Rollback preserves account/history records.

| Option | Configuration | Cost boundary and current scope |
| --- | --- | --- |
| OpenRouter free models | API key; select the separate **OpenRouter (free models only)** option | Discovers tool-capable `:free` models or the free router only when input/output prices are explicitly zero. Paid model IDs are rejected again at invocation. Free model availability changes. |
| Cloudflare Workers AI | Account ID plus account-scoped Workers AI token (Read and Write permissions); confirm Workers Free plan | 10,000 neurons/day shared across the account. Workers Free stops at the allowance; paid plans can charge overages. This integration does not query billing-plan status: the confirmation is an operator attestation. Disable it before upgrading. Initial supported model: `@cf/meta/llama-3.3-70b-instruct-fp8-fast`. |
| Cerebras | API key and available trial balance/credits | Current documentation describes a $5, 30-day trial after payment verification, not a permanent free tier. Models start disabled in manual mode. Discovery intersects authenticated model availability with public pricing metadata. Initially verified tool support includes `gpt-oss-120b`. Usage is priced rather than falsely labelled free. |

The new adapters use chat completions and the SDK tool loop. They do not add image transport or change the existing product-photo analysis provider. Official hosts are enforced for these three options. Cloudflare account IDs survive API-key rotation. Model disappearance or failed discovery disables stale models for these new connections. Existing general OpenRouter accounts retain their separate identity.

Related fixes: OpenRouter authentication now checks `/key` and cannot fall back to a public model list; OpenRouter tool support uses provider metadata rather than name guesses. Model discovery now reports `models_discovered` or `unverified`, never claims successful inference. The pre-existing `ai:test-models` command referenced a nonexistent module AiManager and was not registered: it now uses the actual adapters, accepts one explicit connection ID and reports real text-probe results without vendor mutations.

## Activation

1. Open **WhatsApp → AI Providers → Connect**. Select the provider and enter credentials in the encrypted admin form. Leave custom endpoint empty.
2. For Cloudflare, enter the 32-character account ID and confirm Workers Free. For Cerebras, keep manual selection until you have checked the trial terms/balance. Nothing here purchases credits.
3. Save and discover. Check the displayed status and enabled models. Use a small selection to preserve free quotas. Do not set a local budget to zero to mean “free only”: zero disables all requests under the existing budget logic.
4. From the application directory run `php artisan ai:test-models CONNECTION_ID` after enabling the intended model. This consumes provider quota and checks text inference only. Run a harmless shop-details conversation to verify real vendor tool access. Use AI Routing policy targets if your policy has explicit targets; adding a connection does not rewrite those targets.
5. Test a controlled unavailable-provider case and confirm the next eligible connection answers. Keep deterministic onboarding/product forms available during provider outages. A free allowance is not an uptime guarantee.

No credentials were supplied for these new integrations in this request. Mock HTTP/tool tests demonstrate adapter behavior, not authenticated production availability for your accounts. No outbound vendor campaign is sent by this change.

## Architecture reviewed

The module already has more than a chatbot: webhook intake and queued processing; resumable onboarding sessions/events; deterministic navigation; persistent product drafts, media validation and review; AI agents with vendor-scoped tools; confirmed pending actions routed through core adapters; approval notifications; subscriptions/wallet reads; launch readiness; external human-support links; recovery previews/audits; an operations dashboard; provider routing, usage records and circuit breakers.

The review follows these paths through `ProcessIncomingWhatsAppMessage`, `ConversationManager`, `ConversationOrchestrator`, `VendorOnboardingService`, `ProductListingFlow`, `ProductListingPresenter`, `ProductVisionService`, `VendorConciergeAgent`, `PendingActionService`, `CoreAdapters`, `AiRouterService`, provider adapters, provider/admin controllers, readiness/diagnostic/recovery services, routes, tests and module runbooks. This is a source review, not a claim that every branch has been exercised against live Meta or every mobile client.

## Recommended work, in priority order

### P0 — Reliability and honest operational status

1. **Replace simulated campaign launch.** `ResumeCampaignController::launch` has its dispatch loop commented out yet sets `sent_count` to the audience size and reports success. Disable launch or label it draft-only until a real per-recipient outbox is connected. Separate queued, Meta-accepted, delivered and failed counts; include opt-outs, duplicate suppression, frequency caps and approved-template checks. Acceptance: no message is counted as sent without a provider result; retries do not double-send.
2. **Separate receipt from completed inbound processing.** `ProcessIncomingWhatsAppMessage::handle` returns when an inbound row exists, but logs that row before media/state processing. A failure in between can make queue retries discard unfinished work. Add processing state/checkpoints and idempotent side effects, with a replay command scoped to unfinished messages. Acceptance: inject a failure after receipt, retry it, receive one correct reply and no duplicate product/application.
3. **Unify provider transport and health.** `NvidiaNimAdapter` currently creates the SDK OpenAI driver, whose installed gateway posts to `/responses`; the existing product-vision path uses NVIDIA chat completions directly. Verify/repair the modern NVIDIA path separately, preserving the working fallback. Record credential check, discovery, text test and tool test independently. The new providers use tested chat-completions paths. Acceptance: an HTTP-level test for each installed adapter plus one real account probe; no public catalogue marks authentication healthy.

### P1 — Vendor trust and completing useful tasks

4. **Make readiness reflect actual customer orderability.** `VendorReadinessService` already exists: improve it instead of adding another checklist. Its available-product check uses active status and stock but does not explicitly require moderation approval; payout completeness accepts either bank name or account number, and the profile description promises verified coordinates without checking them. Use canonical module-aware orderability rules, including delivery setup and subscription state. Show one actionable next step. Acceptance: a pending/rejected product cannot imply the shop is ready to sell.
5. **Moderation status and correction cards.** Extend existing product drafts/read tools with “Submitted / Under review / Approved / Needs correction”, reason, timestamp and a button to edit the affected field. Preserve the original draft and photos. This directly addresses vendor uncertainty after submission.
6. **Universal task navigation.** Reuse existing controls and saved drafts: Resume, Edit details, Save for later, Cancel with confirmation, Support. Use up to three reply buttons or a list for longer choices. Test typed commands and old button IDs at every stage, especially photos/password/review. Keep human support in the separate support chat without suppressing ordinary concierge responses.
7. **Reliable photo fallback.** `ProductVisionService` currently depends on one NVIDIA model. Add a separately verified image provider only after testing actual image bytes, extraction schema and timeout behavior. During an outage, offer manual entry immediately; never make a photo-analysis failure erase a saved price or stock count.
8. **Practical daily shop actions.** Existing tools cover products, orders, sales and availability. Improve discovery with a short “My shop” menu and confirmed single-item stock/price updates. Keep order actions behind canonical permissions/state transitions and show store-managed delivery instructions; do not invent rider dispatch while no rider network exists.

### P2 — Scale and a calmer admin experience

9. **Actionable operations queue.** Extend existing diagnostics with “Waiting for us / Waiting for vendor / Needs admin decision”, age, root cause and one previewable recovery action. `ConciergeDiagnosticService::scan` currently loads all conversations and performs per-conversation reads; move filters/pagination into SQL and batch latest-message/event queries. Add queue-age, failed-job, lock-age and provider-quota signals rather than treating every silence as vendor abandonment.
10. **Measured, opt-in nudges and digests.** Resume the exact saved step; include progress and a clear button. Add vendor-local quiet hours, notification preferences and weekly sales/low-stock summaries. Measure completion after a nudge, not just send volume. Build on existing recovery audits and notification preferences rather than another sender.
11. **Inclusive language and accessible input.** Extend existing language preference handling with reviewed translations and optional voice-note transcription followed by vendor confirmation. Keep messages short, spaced and consistently formatted. Describe a fulfilment/pickup address without implying an online-only seller needs a walk-in shop.
12. **Provider maintenance and privacy.** Refresh discovered models regularly, preserve manual selections, surface retired models and quota resets, and avoid routing sensitive credentials/documents into model prompts. Existing privacy redaction and authorization should gain regression tests whenever new inputs/tools are added. Consolidate legacy and modern provider configuration gradually so the admin has one reliable source of truth.

Suggested sequence: inbound replay safety and campaign truthfulness first; readiness/moderation and task navigation next; photo redundancy and operator telemetry afterward. These are recommendations, not features silently claimed as implemented by this provider patch.

## Sources checked

- [Cerebras limits and current trial](https://inference-docs.cerebras.ai/support/rate-limits)
- [Cerebras OpenAI compatibility](https://inference-docs.cerebras.ai/resources/openai)
- [Cerebras authenticated models](https://inference-docs.cerebras.ai/api-reference/models/list-models)
- [Cloudflare pricing](https://developers.cloudflare.com/workers-ai/platform/pricing/)
- [Cloudflare chat compatibility](https://developers.cloudflare.com/workers-ai/configuration/open-ai-compatibility/)
- [Cloudflare supported tool model](https://developers.cloudflare.com/workers-ai/models/llama-3.3-70b-instruct-fp8-fast/)
- [OpenRouter pricing](https://openrouter.ai/pricing/)
- [OpenRouter free variants](https://openrouter.ai/docs/guides/routing/model-variants/free)

Provider terms/catalogues change; check the linked dashboards before enabling an account.

## Validation record

- Full local concierge run: 230 tests, 1,139 assertions, no failures/errors; 3 disposable-MySQL tests skipped in the SQLite run.
- Final targeted provider/setup/discovery run after the last additions: 20 tests, 63 assertions, no failures/errors. Includes real SDK HTTP-level tool round trips for all three providers, credential rotation, idempotent seeding, strict free-model filtering, quota errors, stale-model disabling and the registered inference probe.
- Local host architecture run: 37 tests, 102 assertions, no failures/errors; 15 MySQL-gated cases skipped. The deployment workflow runs these gates with MySQL enabled.
- Existing PHPUnit deprecations remain. Real inference with the new providers requires the account credentials and activation steps above.
