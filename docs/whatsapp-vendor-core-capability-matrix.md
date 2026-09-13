# Core capability reuse matrix

Verified against starting commit `354c357` on 2026-09-13. Paths are repository-relative. Identified code is the extraction target, not a claim that an existing reusable service already exists.

| Capability | Existing core support | Canonical implementation | Core change required | WhatsApp adapter required | Risks |
|---|---|---|---|---|---|
| Vendor application | Yes, controller-owned | `app/Http/Controllers/VendorController.php::store`, web general-info view | Extract full schema/persistence; align UI/server validation | Full step mapping, review and media | Cover, last name, rental fields currently omitted |
| Credentials | Vendor password hashing | VendorController, auth password reset controllers | Shared password rules | Dedicated session token service | Replay, chat/queue/log disclosure |
| Approval/denial | Yes | `Admin/VendorController::updateVendorApplication` | Domain transition event, preserve existing notifications | One idempotent listener | Competing observer; approval vs suspension |
| Suspension/reactivation | Yes | `Admin/VendorController::status`, Store.status | Domain event | Window-aware delivery | No-op toggles and duplicate sends |
| Store open/close | Yes | `Vendor/BusinessSettingsController::active_status`, API vendor `active_status` | Extract availability service using Store.active | Pending Confirm/Cancel | Approval/status/schedule are separate |
| Products | Yes, complex | Vendor and API `ItemController::store/update`, ProductLogic, Helpers | Extract validation/persistence including variants, subscription/moderation | Draft and deterministic confirmation | Direct tool writes bypass core requirements |
| Orders | Yes, complex | Vendor OrderController; API vendor `update_order_status`; OrderLogic | Shared transitions with payment/rider side effects | Scoped read/prepare/confirm | Core uses canceled, not cancelled; delivery OTP |
| Sales/earnings | Yes | Vendor DashboardController, StoreEarningReportController; Vendor.order_transaction | Shared query definitions | Read adapter | Paid != delivered != vendor earnings |
| Wallet/payout | Yes | Vendor WalletController; StoreWallet, WithdrawRequest, DisbursementDetails | Shared read service; retain withdrawal authorization | Read/status/deep links first | Financial mutations need stronger verification |
| Subscription | Yes | VendorController.secondStep/business_plan; Vendor SubscriptionController; Helpers.subscription_plan_chosen | Shared package eligibility/payment entry | Explicit package and secure continuation | Never activate paid subscription on selection |
| Schedules | Yes | StoreLogic.insert_schedule; Vendor BusinessSettingsController.add_schedule/remove_schedule | Shared validated schedule service | Structured input | Current custom text silently becomes default schedule |
| Zone/module | Yes | Zone.whereContains; ModuleZone; VendorController.check_module_type | Shared location validation | Pin, detected zone confirmation | First-row defaults invent geography |
| Public branding | Yes | Helpers.upload/update, Store storage metadata | Shared content validation | Await valid media before advancing | Extensionless Meta download, race |
| Private KYC | Public storage currently | Store.tin/tin_certificate_image and general-info view | Private document service, authorized download, retention and migration | Private upload reference | Must update existing admin access before replacing paths |
| Support | Chat exists, no audited case lifecycle | Vendor ConversationController, Conversation/Message/UserInfo | Core case assignment/SLA/notes/reopen | Handoff ownership and transcript | Existing email is not a ticket |
| Reviews | Yes | Vendor ReviewController, Store reviews | Scoped query reuse | Read adapter | Deleted items and data minimization |
| Launch readiness | Underlying state exists | Store/items/schedules/subscription/wallet | Query service, no duplicate business state | Checklist and secure links | Recommendation != platform activation requirement |
| Photo-to-product | Media + Item core exist | Item workflows above | Canonical draft/schema | Await validation, suggested fields, preview | Current photo branch only stores context |
| Proactive operations | Email/push preferences exist | Helpers notification settings, user_notifications | Domain events + WhatsApp preferences/quiet hours/caps | Templates, unsubscribe | Opt-in, retry and template approval |
| AI limits | AI module/provider config exists | laravel/ai, business OpenAI config | Shared metering/budget infrastructure where practical | Turn limits, circuit breaker, safe menu | Flags currently not enforced |
| Maintenance | Laravel scheduler exists | Module provider, config/queue.php | No duplicate scheduler | Implement bounded commands | Named commands absent; timeout mismatch |
