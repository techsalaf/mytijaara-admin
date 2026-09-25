# Operations review — 25 September 2026

Reviewed baseline: 2da00c28. This is a corrective review, not confirmation that every claim in the earlier implementation prompt was delivered.

## Confirmed defects and corrections

- Pending-store review intersected the explicit module filter with the dashboard's current module. Three submitted Shop applications were hidden while Grocery was selected. `getNewStores` now retains explicit module, zone and search filters without silently forcing Grocery. Laravel already compiled the previous `where(status, null)` to IS NULL; that was not the underlying defect.
- Canonical WhatsApp sends were missing from the message table. Diagnostics consequently treated successful replies as silence. Gateway results now persist outbound evidence, deduplicate provider IDs, normalize interactive types and record failures without inventing provider IDs. A reusable dry-run-first log reconciliation command imports historical acceptance evidence without sending messages or fabricating delivery/read receipts or message bodies.
- Healthy AI connections were excluded from model selection. Healthy connections and recoverable cooldown states now reach availability/circuit-breaker checks; disabled/auth-failed connections remain excluded.
- Contact profile names were overwritten with null. Names now survive messages without profile data.
- Submitted applicants could return to stale draft steps. Pending applicants now receive application status instead, with support commands still available.
- Older stuck-application actions merely changed status and falsely reported successful recovery. They now open the common preview workflow.

## UI and recovery

Inbox: consistent name/phone search, limited sidebar message loading, responsive layout, clearer ownership and reply-window status, dated message history, escaped dynamic replies, and polling that respects typing/reading. Human replies require takeover, an open 24-hour window and provider acceptance.

Operations: shared UI/CLI classification, filters before pagination, current counts, readable reasons, support ownership, review links, Bootstrap 4 controls and responsive toolbars. Recovery requires a short-lived single-use preview bound to administrator/action/IDs/conversation state. CLI defaults to dry run. Shared service rechecks eligibility under a contact lock, preserves open support cases, applies contact-wide cooldown and daily limits, audits outcomes, and never treats a Meta rejection as success.

Unsafe historical inbound replay is explicitly excluded: those messages lack a reliable processing checkpoint and replay could repeat completed business actions. Operators can inspect, resend an eligible current question or request support.

Configuration: WHATSAPP_NUDGE_COOLDOWN_MINUTES (45), WHATSAPP_MAX_NUDGES_PER_DAY (3).

Commands:
- `php artisan whatsapp:concierge-diagnose`
- `php artisan whatsapp:concierge-recover --conversation=ID --action=renudge_current_step` (preview; add --force only after review)
- `php artisan whatsapp:concierge-recover --conversation=ID --action=assign_human`
- `php artisan whatsapp:reconcile-delivery-logs` (preview; --force imports acceptance records, sends nothing)

## Boundary

Only existing core file changed in this revision: `app/Http/Controllers/Admin/VendorController.php`, `getNewStores`, between GEMINI-MYTJ START/END markers (approximately lines 806–845). It corrects cross-module review for pending/denied applications while retaining access-zone scoping. All concierge behavior remains inside Modules/WhatsAppVendorConcierge. This is not a claim that historical core customizations elsewhere in the repository are absent.

## Validation and limits

Local concierge regression suite: 186 tests / 998 assertions passed. Final targeted operations tests: 10 / 40 passed, including pending-applicant guard, changed-preview rejection, historical reconciliation and real Blade rendering. Host/isolation suite: 37 tests / 199 assertions passed. Core boundary check passed. Existing PHPUnit deprecation notices remain.

Production audit confirmed historical missing reply records, DeepSeek insufficient-balance failures and the healthy-provider selection defect. Both production cache-directory write probes passed after the server migration. No credential or application data reset is part of this change.

Still unverified or incomplete relative to the full original prompt: browser-driven production recovery; mobile viewport verification; per-job/lock/exhausted-queue telemetry; full AI incident correlation; named-agent assignment and technical-case lifecycle; exact message-body preview; automatic administrator alert thresholds, health trends and recovery-rate charts. Desktop inbox and Operations Centre fixtures were visually checked after the hidden in-app browser successfully attached. Existing scheduled health snapshots and human escalation are retained, but must not be represented as all these capabilities. No blanket production-GO certification is made from test results alone.
