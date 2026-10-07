# Phase C0 read-only trace (2026-10-07)

Recorded before implementation. No live database or production account inspected.

| Path | Finding |
| --- | --- |
| API owner/employee login | VendorLoginController uses session-guard attempt, custom plaintext auth_token, none/unsubscribed branches precede approval. Employee shares owner's store decision. |
| Web login | LoginController submit returns none payment view before password verification; login_attemp directly attempts session guard. |
| API reset/OTP | VendorPasswordResetController uses shared password_resets, plaintext four-digit OTP, no consumption-time expiry or purpose guard, demo bypass, no bearer revocation. |
| Web reset | LoginController stores raw vendor link alongside admin reset rows; GET checks expiry, POST does not. Generic else resets vendor for non-admin purposes. |
| Concierge setup | CredentialTokenService hashes 256-bit tokens, locks started session, expires in 15 minutes, consumes once into prepared draft password hash. No vendor activation/token issuance. Keep separate. |
| Subscription APIs | Public package list plus unauthenticated business_plan/cancel/check-product-limits; arbitrary store_id. payment/api and package-renew refer to methods absent from controller. |
| Web subscription | Public registration business-plan/secondStep/payment/back accept arbitrary store_id. Bind registration session or authenticated owner before access. |
| Payment completion | Helpers::subscription_plan_chosen changes store status based on caller type; payment must not reverse suspension or approval. |
| Token validation | VendorTokenIsValid checks only token existence; unknown vendorType falls through. VendorMiddleware checks vendor truthiness or employee store status, not same eligibility rule. |
| Approval/rejection | VendorApplicationDecisionService locks store then vendor; null vendor.status pending, 0 rejected with rejection_note, 1 approved. Sets store status and subscription status. |
| Suspension/deletion | Admin VendorController status toggles store.status; destroy hard-deletes vendor/store. Models do not use SoftDeletes. Defensive deleted_at handling required if schema evolves. |
| Store active | API active_status toggles active and returns store_opened/store_temporarily_closed. This is availability, not account approval. |
| Subscription | commission permits normal access; none means plan not completed; subscription requires active nonexpired store_sub; unsubscribed means expired plan. mobile_app is an API entitlement. Existing web middleware allows some ongoing-order access on expired plans; C0 will deny full access and provide limited setup instead where eligible. |
| Refresh/logout | No vendor bearer refresh endpoint found. App logout clears local token and sends fcm_token=@; host web logout ends guard session. Need server API logout. |
| OAuth/impersonation | SocialAuthController/Passport createToken are customer paths. No vendor loginUsingId, login-as or impersonation issuance found. Admin routes use admin guard. |
| Routes/aliases | bootstrap/app.php maps vendor and vendor.api; host vendor groups and Rental/Reels/AI module groups reuse them. Subscription group is omission requiring correction. |
| Store app | auth_service.manageLogin saves subscribed.token as normal token when no package, calls update-fcm-token/profile before subscription UI. Package-present path opens payment without storing token. business_repo sends store_id. Existing single-token contract cannot safely support restricted access unchanged. |

Policy matrix intended by C0: pending null/status0 may set/reset password without activation and may use owner-only subscription setup if model none; rejected status0 denies all setup/access; approved status1 plus suspended store0 denies access; approved active store1 commission or paid/nonexpired entitled subscription permits full access; approved store1 none/unsubscribed/expired may use limited owner-only subscription setup. Unknown, missing, deleted states fail closed. Store active=0 alone is temporary closure and does not remove authentication. Password creation never changes approval. Renewal of store0 is denied because schema cannot distinguish suspension from subscription-driven deactivation safely.
