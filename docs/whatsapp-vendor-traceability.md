# WhatsApp Vendor Concierge Traceability

Starting point: `dcc4fac76f87a4eac9239fef4028c6b5f354c774` on `main` (2026-09-13).

This matrix is the delivery record for the current hardening programme. A status of
**partial** means a code path exists but does not meet every requirement in the
implementation prompt; it is not a production-readiness claim.

| Requirement | Current owner / evidence | Gap and plan | Tests | Result |
|---|---|---|---|---|
| Credential token lockout | `CredentialTokenService`, `SecurePasswordController` | Enforce atomic configured failed-attempt limit and keyed rate limit | `CredentialSecurityTest` | In progress |
| Fail-closed KYC documents | `ProcessVendorDocument`, `WhatsAppMedia` | Validate actual stored bytes and document state; never mark missing data processed | `DocumentSecurityTest` | In progress |
| Media policy | `ProcessWhatsAppMedia`, `KycDocumentController` | Add purpose-specific validation/quarantine and access audit | Media policy tests | Partial |
| Receipt ordering | `WhatsAppMessage::applyReceipt` | Correct duplicate/older timestamp handling | `ReceiptSecurityTest` | In progress |
| Canonical application service | `VendorController`, `VendorOnboardingService` | Extract shared core DTO/service and migrate both callers | Parity integration tests | Not implemented |
| Cover-photo parity | `VendorController`, `VendorOnboardingService` | Add conditional conversational capture and review state | Onboarding integration tests | Partial |
| Field parity | `VendorController::store` | Record and reconcile each canonical field | Parity matrix tests | Partial |
| Subscription lifecycle | `VendorController`, subscription services | Reconcile verified payment callbacks and WhatsApp continuation | Payment tests | Partial |
| Canonical notifications | admin vendor controller, observer, status job | One post-commit event and leased delivery claim | Concurrency tests | Partial |
| Template policy | `SendVendorStatusNotification`, gateway | Parameter/component validation and locale-aware fallback | Template-window tests | Partial |
| Product operations | vendor product controllers/tools | Extract canonical mutation service before enabling tools | Authorization/action tests | Disabled pending service |
| Photo-to-product | AI tools / media pipeline | Build reviewed draft flow only | Draft workflow tests | Not implemented |
| Order operations | order controllers/tools | Reuse core state machine with pending confirmations | State-transition tests | Disabled pending service |
| Launch checklist | core store/subscription/product state | Read-only computed readiness service | Checklist tests | Not implemented |
| Wallet and payouts | wallet/withdrawal core | Add least-privilege read adapter | Read authorization tests | Not implemented |
| Subscription command centre | subscription core | Add read/recovery adapter | Subscription tests | Not implemented |
| Reviews and insights | review/order/item core | Define and retrieve canonical metrics | Metrics tests | Partial read tools |
| Preferences and proactive notices | contact/conversation data | Add consent, quiet-hours and caps | Preference tests | Not implemented |
| Language experience | `ConversationManager`, agent | Persist selection; remove heuristic switching | Locale tests | Partial |
| AI controls | `RunVendorAiConversation` | Enforce limits, metering and fallbacks | Budget tests | Partial |
| Support cases | handoff mail flow | Build canonical support-case lifecycle | Support case tests | Partial |
| Observability and maintenance | commands/jobs/runbook | Add safe metrics, recovery and dry-run coverage | Command tests | Partial |
| Strict preflight | `whatsapp:preflight` | Check all release dependencies without secret disclosure | `PreflightTest` | Partial |

## Scoped verification constraint

`php artisan route:list` is currently blocked before routes are enumerated because
`app/Http/Controllers/RiderRegistrationController.php` references the missing
`Modules\\RideShare\\Interface\\UserManagement\\Service\\DriverLevelServiceInterface`.
The only post-`e8c7c5e1` functional addition is the Rental module; it does not
provide that RideShare interface. This is unrelated to the WhatsApp module and is
recorded here rather than changed as part of its hardening work.
