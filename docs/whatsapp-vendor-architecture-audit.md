# MyTijaara WhatsApp Vendor Concierge - Architecture Audit

**Date:** 2026-09-09
**Auditor:** Claude Code (Principal Software Architect)
**Status:** Phase 1 Complete - Audit & Documentation

---

## 1. Existing Architecture Overview

### 1.1 Laravel Stack
| Component | Version | Notes |
|-----------|---------|-------|
| Laravel | ^12.0 | Latest major version |
| PHP | ^8.2-8.4 | Modern PHP with typed properties |
| Database | MySQL 8.x | Spatial extensions (Eloquent Spatial) |
| Queue | Database (default) | `QUEUE_CONNECTION=database` |
| Cache | Database | `CACHE_DRIVER=database` |
| Broadcasting | Reverb/Pusher | Real-time capabilities |
| Auth | Laravel Passport | OAuth2 server |

### 1.2 Project Structure
```
mytijaara-admin/
├── app/                          # Core application
│   ├── Http/Controllers/         # Web + API controllers
│   │   ├── Admin/                # Admin panel controllers
│   │   ├── Api/V1/               # Customer-facing API v1
│   │   ├── Api/V2/               # Customer-facing API v2
│   │   └── Vendor/               # Vendor panel controllers
│   ├── Models/                   # 149 Eloquent models
│   ├── Repositories/             # 26 repository classes
│   ├── Services/                 # 21 service classes
│   ├── CentralLogics/            # Shared business logic
│   ├── Jobs/                     # 2 queue jobs
│   ├── Events/                   # 1 event
│   ├── Traits/                   # Reusable traits
│   ├── Enums/                    # PHP enums
│   ├── Contracts/                # Interfaces
│   ├── Policies/                 # Authorization
│   └── Mail/                     # Mailable classes
├── Modules/                      # Modular addons (nwidart/laravel-modules)
│   ├── AI/                       # AI Assistant module (existing)
│   ├── Builder/                  # Website builder
│   ├── ReelsModule/              # Social reels
│   └── TaxModule/                # Tax compliance
├── routes/
│   ├── web.php                   # Public web routes
│   ├── admin.php                 # Admin panel routes
│   ├── vendor.php                # Vendor panel routes
│   ├── api/v1/api.php            # Customer API v1
│   └── api/v2/api.php            # Customer API v2
├── database/migrations/          # 266 migrations
├── config/                       # Configuration
└── resources/                    # Views, lang, assets
```

### 1.3 Module Architecture (nwidart/laravel-modules)
- Each module is self-contained with: `app/`, `config/`, `database/`, `lang/`, `resources/`, `routes/`, `tests/`
- Modules register via `module.json` and ServiceProvider
- AI module already exists as reference implementation

---

## 2. Existing Vendor Onboarding Architecture

### 2.1 Current Vendor Registration Flow (Web)

**Entry Point:** `/vendor/apply` → `VendorController@create`

```
1. General Info Form (vendor-views.auth.general-info)
   ↓
2. VendorController@store (POST)
   - Validates: f_name, l_name, name, address, lat/long, email, phone, password, zone, module
   - Creates Vendor (status=null/pending)
   - Creates Store (status=0/pending, store_business_model=none)
   - Sends registration emails to vendor + admin
   ↓
3. Business Plan Selection (vendor-views.auth.register-step-2)
   - Commission-based OR Subscription-based
   ↓
4. Payment / Free Trial
   - Multiple payment gateways supported
   - Subscription packages via SubscriptionPackage model
   ↓
5. Final Step (vendor-views.auth.register-complete)
   - Vendor receives confirmation
   - Admin must approve via Admin Panel
```

### 2.2 Admin Approval Workflow

**Admin Routes:** `/admin/store/pending-requests` → `Admin\VendorController@pending_requests`

```
Admin Actions:
├── Approve (status=1)
│   - Vendor.status = 1
│   - Store.status = 1
│   - StoreSubscription activated (if applicable)
│   - Sends approval email via VendorSelfRegistration('approved')
└── Reject (status=0)
    - Vendor.status = 0
    - Vendor.rejection_note = reason
    - Sends rejection email via VendorSelfRegistration('denied')
```

### 2.3 Key Models

| Model | Table | Key Fields |
|-------|-------|------------|
| `Vendor` | `vendors` | id, f_name, l_name, phone, email, password, status (0/1/null), rejection_note, auth_token |
| `Store` | `stores` | id, name, phone, email, logo, lat/long, address, vendor_id, zone_id, module_id, status (0/1), store_business_model (commission/subscription/none), package_id |
| `StoreSubscription` | `store_subscriptions` | store_id, package_id, expiry_date, validity, max_order, max_product, status, is_trial |
| `SubscriptionPackage` | `subscription_packages` | module_type, price, validity, features |

### 2.4 Vendor Authentication
- **Web:** Session-based via `vendor` middleware
- **API (Vendor App):** Passport tokens + `vendor.api` middleware + `actch:vendor_app`
- **Token:** `Vendor.auth_token` (Passport access token)

---

## 3. Existing Database Entities (Relevant)

### 3.1 Core Vendor/Store Tables
```sql
vendors:
  - id, f_name, l_name, phone, email, password, status, rejection_note, auth_token, login_remember_token

stores:
  - id, name, phone, email, logo, latitude, longitude, address, vendor_id, zone_id, module_id,
    status, store_business_model (enum: none,commission,subscription,unsubscribed),
    package_id, pickup_zone_id (JSON), delivery_time, slug, tin, tin_certificate_image

store_subscriptions:
  - id, store_id, package_id, expiry_date, validity, max_order, max_product,
    pos, mobile_app, chat, review, self_delivery, status, is_trial

subscription_packages:
  - id, module_type, name, price, validity, max_order, max_product, features (JSON)
```

### 3.2 Existing Communication Tables (Can be Extended)
```sql
conversations:
  - id, sender_id, sender_type, receiver_id, receiver_type, last_message_id, last_message_time, unread_message_count

messages:
  - id, conversation_id, sender_id, message (text), file, is_seen

ai_conversations (Module AI):
  - id, user_id, guest_id, module_id, zone_id, title, status (active/archived)

ai_messages (Module AI):
  - id, conversation_id, role (user/assistant/tool), content, tool_name, metadata (JSON)
```

---

## 4. Existing Vendor APIs & Services

### 4.1 Vendor Panel API (routes/vendor.php + routes/api/v1/api.php)
```php
// Vendor Web Panel (vendor.php)
Route::prefix('vendor-panel')->middleware(['vendor', 'maintenance', 'actch:admin_panel'])

// Vendor Mobile App API (api/v1/api.php)
Route::prefix('vendor')->middleware('actch:vendor_app')
  POST   /login
  POST   /forgot-password
  POST   /verify-token
  PUT    /reset-password
  POST   /register

Route::prefix('vendor')->middleware(['vendor.api', 'actch:vendor_app'])
  GET    /profile
  POST   /update-active-status
  GET    /earning-info
  GET    /notifications
  GET    /wallet-payment-list
  POST   /make-collected-cash-payment
  ...
```

### 4.2 Key Services (app/Services/)
| Service | Responsibility |
|---------|----------------|
| `StoreLogic` | Store creation, scheduling, geospatial queries |
| `CategoryLogic` | Category management |
| `ProductLogic` | Product CRUD, variants |
| `OrderLogic` | Order processing |
| `CouponLogic` | Coupon management |
| `ZoneService` | Zone/delivery area management |
| `NotificationService` | Push/email/SMS notifications |

### 4.3 CentralLogics Helpers (app/CentralLogics/Helpers.php)
- 15KB of shared utilities
- File upload, translations, settings, emails, push notifications
- **Critical:** `Helpers::upload()`, `Helpers::send_push_notif_to_device()`, `Helpers::get_business_settings()`

---

## 5. Existing Authentication & Authorization

### 5.1 Guards & Providers
```php
// config/auth.php (inferred)
guards:
  - web (session)
  - admin (session + custom)
  - vendor (session + custom)
  - vendor.api (passport token)
  - api (passport token)

providers:
  - users (App\Models\User)
  - vendors (App\Models\Vendor)
  - admins (App\Models\Admin)
```

### 5.2 Middleware
- `vendor` - Web vendor panel auth
- `vendor.api` - API vendor auth (Passport)
- `actch:vendor_app` - App Check for mobile
- `module:xxx` - Module access control
- `subscription:xxx` - Subscription feature gates

---

## 6. Existing Queue Infrastructure

### 6.1 Current Configuration
```php
// config/queue.php
'default' => env('QUEUE_CONNECTION', 'sync'), // Currently 'database' in .env

connections:
  - sync
  - database (jobs table)
  - redis (configured but not default)
  - sqs, beanstalkd
```

### 6.2 Existing Jobs
| Job | Purpose |
|-----|---------|
| `MonthlyOrderReminderJob` | Sends push notifications for reorders |
| `DispatchDriverLocationJob` | Driver location updates |

### 6.3 Queue Usage Patterns
- Jobs use `ShouldQueue` with `tries=3`, `backoff=300`
- Dispatched via `dispatch()` helper
- Failed jobs logged to `storage/logs`

---

## 7. Existing Notification Infrastructure

### 7.1 Email (Mailable Classes)
- `VendorSelfRegistration` - Vendor registration status emails
- `StoreRegistration` - Admin notification of new store
- Template system via `EmailTemplate` model + `Helpers::text_variable_data_format()`

### 7.2 Push Notifications
- Firebase FCM via `Helpers::send_push_notif_to_device()`
- `NotificationMessage` model for templated messages
- `UserNotification` / `StoreNotification` for persistence
- `StoreNotificationSettings` for preferences

### 7.3 SMS
- Twilio, Nexmo, 2Factor, MSG91, AlphaNet gateways
- `SmsGateway` trait + `SMS_module.php`
- OTP verification via `PhoneVerification` model

---

## 8. Existing Admin Approval Workflow

### 8.1 Admin VendorController Methods
| Method | Route | Purpose |
|--------|-------|---------|
| `pending_requests()` | GET /admin/store/pending-requests | List pending stores |
| `deny_requests()` | GET /admin/store/deny-requests | List rejected stores |
| `updateVendorApplication()` | Private | Approve/reject logic |
| `update_application()` | POST /admin/store/update-application | Bulk update |

### 8.2 Approval Logic (updateVendorApplication)
```php
$store = Store::findOrFail($request->id);
$store->vendor->status = $request->status;        // 1=approve, 0=reject
$store->vendor->rejection_note = $request->rejection_note;
$store->vendor->save();

if ($request->status) {
    $store->status = 1;
    // Activate subscription if applicable
    if ($store->store_sub_update_application) {
        $add_days = $store->store_sub_update_application->is_trial 
            ? free_trial_days : validity;
        $store->store_sub_update_application->update([
            'expiry_date' => now()->addDays($add_days),
            'status' => 1,
        ]);
        $store->store_business_model = 'subscription';
    }
}
$store->save();
// Send email notification
```

---

## 9. What Can Be Reused

| Component | Reusable? | Notes |
|-----------|-----------|-------|
| **Vendor/Store Models** | ✅ YES | Core entities - extend with WhatsApp fields |
| **Vendor Registration Logic** | ✅ YES | Refactor into `VendorOnboardingService` |
| **Admin Approval Workflow** | ✅ YES | WhatsApp applications feed same pipeline |
| **Subscription System** | ✅ YES | Packages, trials, payments unchanged |
| **Zone/Module System** | ✅ YES | Geospatial validation, module gating |
| **Email/Notification System** | ✅ YES | Add WhatsApp notification channel |
| **Queue Infrastructure** | ✅ YES | Add WhatsApp-specific jobs |
| **AI Module (Modules/AI)** | ✅ YES | Agent architecture, tools, conversation persistence |
| **File Upload/Storage** | ✅ YES | `Helpers::upload()`, `Storage` model |
| **Translation System** | ✅ YES | Multi-language support ready |

---

## 10. What Needs to Be Created

### 10.1 New Module: `WhatsAppVendorConcierge`
```
Modules/WhatsAppVendorConcierge/
├── app/
│   ├── Http/Controllers/Api/     # Webhook + API
│   ├── Jobs/                     # Queue jobs
│   ├── Models/                   # WhatsApp-specific models
│   ├── Services/                 # Core business logic
│   │   ├── WhatsAppGateway.php   # Meta Cloud API client
│   │   ├── VendorOnboardingService.php  # Reusable onboarding
│   │   ├── ConversationManager.php      # State machine
│   │   └── AiConciergeService.php       # AI orchestration
│   ├── Agents/                   # AI Agents (like AI module)
│   │   ├── Tools/                # Function calling tools
│   │   └── ConciergeAgent.php
│   ├── Events/                   # WhatsApp events
│   ├── Listeners/                # Event handlers
│   └── Notifications/            # WhatsApp notification channel
├── config/config.php
├── database/migrations/          # WhatsApp tables
├── routes/
│   ├── web.php                   # Webhook endpoints
│   └── api.php                   # Internal APIs
├── resources/lang/               # Multi-language templates
└── tests/
```

### 10.2 New Database Tables (Migrations)
| Table | Purpose |
|-------|---------|
| `whatsapp_contacts` | WhatsApp user ↔ MyTijaara mapping |
| `whatsapp_conversations` | Conversation state + context |
| `whatsapp_messages` | Message log (idempotency) |
| `whatsapp_media` | Media downloads + cleanup |
| `whatsapp_flows` | Flow definitions + versions |
| `onboarding_sessions` | Onboarding progress tracking |
| `onboarding_events` | Analytics funnel events |

### 10.3 WhatsApp Cloud API Integration
- Meta Graph API client (GuzzleHTTP)
- Webhook verification + signature validation
- Message sending (text, interactive, media, templates)
- Flow creation + response handling
- Media download + secure storage

### 10.4 AI Concierge Layer
- Tool/Function calling architecture (like AI module)
- Vendor authorization per tool
- Confirmation flows for write operations
- Structured output parsing

---

## 11. What Needs Refactoring

| Area | Change Required |
|------|-----------------|
| `VendorController@store` | Extract to `VendorOnboardingService::createApplication()` |
| `Admin\VendorController@updateVendorApplication` | Make service-callable for WhatsApp approvals |
| `StoreLogic::insert_schedule()` | Ensure callable from service layer |
| `Helpers::send_push_notif_to_device()` | Add WhatsApp notification channel |
| AI Module Agents | Extend `PlatformAssistantAgent` for vendor tools |

---

## 12. Potential Architectural Conflicts

| Conflict | Risk | Mitigation |
|----------|------|------------|
| **Duplicate Vendor Creation** | WhatsApp + Web both create vendors | Idempotent contact lookup by phone; unique constraints |
| **Auth Token Confusion** | Passport tokens vs WhatsApp session | Separate `whatsapp_session_token` field on Vendor |
| **Concurrent Onboarding** | Vendor starts on web, continues on WhatsApp | `onboarding_sessions` with `source` field; resume logic |
| **Admin Panel Sync** | WhatsApp apps must appear in admin | Use existing `Store` + `Vendor` models; add `application_source` |
| **Queue Overload** | Webhook bursts | Separate queue `whatsapp` with dedicated workers |
| **AI Cost/Rate Limits** | Unbounded LLM calls | Token budgets, caching, fallback to deterministic flows |

---

## 13. Recommended Implementation Architecture

### 13.1 High-Level Data Flow
```
┌─────────────┐     ┌──────────────────┐     ┌──────────────────┐
│   WhatsApp  │────▶│  Webhook (GET/POST)│────▶│  Validate & Persist│
│  (Meta)     │     │  /api/whatsapp   │     │  Event to Queue    │
└─────────────┘     └──────────────────┘     └────────┬─────────┘
                                                      │
                    ┌─────────────────────────────────┘
                    ▼
         ┌─────────────────────┐
         │  ProcessIncomingJob │
         └──────────┬──────────┘
                    │
        ┌───────────┼───────────┐
        ▼           ▼           ▼
┌─────────────┐ ┌──────────┐ ┌────────────┐
│New Contact? │ │AI Intent │ │Flow/State  │
│→Match/Reg   │ │Classifier│ │Machine     │
└─────────────┘ └──────────┘ └────────────┘
        │           │           │
        └───────────┼───────────┘
                    ▼
         ┌─────────────────────┐
         │ VendorOnboardingSvc │◀── Reuses existing logic
         └──────────┬──────────┘
                    │
        ┌───────────┼───────────┐
        ▼           ▼           ▼
   Create Vendor  Create Store  Queue Admin
   (pending)      (pending)     Notification
```

### 13.2 Module Integration Points
```php
// Modules/WhatsAppVendorConcierge/app/Providers/WhatsAppVendorConciergeServiceProvider.php
public function boot(): void {
    $this->loadMigrationsFrom(...);
    $this->loadRoutesFrom(module_path('WhatsAppVendorConcierge', 'routes/web.php'));
    $this->publishes([...]);
}

// Register in config/modules.php or via admin panel activation
```

---

## 14. Risks & Mitigations

| Risk | Severity | Mitigation |
|------|----------|------------|
| **Meta API Changes** | Medium | Abstract behind `WhatsAppGateway` interface; version pinning |
| **Webhook Duplicates** | High | Idempotency keys on `whatsapp_messages.whatsapp_message_id` (unique) |
| **Media Storage Costs** | Low | Auto-cleanup job; stream download → store → delete temp |
| **AI Hallucination** | Medium | Tool-based architecture; confirmation required for writes |
| **Rate Limits (Meta)** | Medium | Exponential backoff; queue with `rateLimited` middleware |
| **Phone Number Changes** | Low | WhatsApp Business API ties to business, not device |
| **Nigerian Data Protection** | High | Consent logging; data minimization; retention policies |

---

## 15. External Dependencies

| Dependency | Purpose | Status |
|------------|---------|--------|
| **Meta WhatsApp Cloud API** | Core messaging | REQUIRED - Need credentials |
| **Laravel AI (laravel/ai)** | AI orchestration | ✅ Already installed v0.6.2 |
| **OpenAI/Anthropic API** | LLM provider | REQUIRED - Need API key |
| **GuzzleHTTP** | HTTP client | ✅ Already in composer.json |
| **Firebase FCM** | Push fallback | ✅ Already configured |
| **Intervention Image** | Image processing | ✅ Already installed |

---

## 16. Environment Variables Required

```env
# WhatsApp Cloud API (Meta)
WHATSAPP_ACCESS_TOKEN=                    # System User token or App token
WHATSAPP_PHONE_NUMBER_ID=                 # From Meta Developer Console
WHATSAPP_BUSINESS_ACCOUNT_ID=             # WABA ID
WHATSAPP_APP_ID=                          # Meta App ID
WHATSAPP_APP_SECRET=                      # Meta App Secret
WHATSAPP_VERIFY_TOKEN=                    # Random string for webhook verify
WHATSAPP_API_VERSION=v21.0                # Meta Graph API version

# AI Provider (choose one)
OPENAI_API_KEY=                           # Already in .env.example
ANTHROPIC_API_KEY=                        # Alternative

# Optional: Webhook URL (for Meta config)
WHATSAPP_WEBHOOK_URL=https://api.mytijaara.com/api/webhooks/whatsapp

# Queue (separate worker recommended)
QUEUE_CONNECTION_WHATSAPP=database        # or redis
```

---

## 17. Implementation Phases (Recommended)

### Phase 1: Foundation (Week 1-2)
- [ ] Create `WhatsAppVendorConcierge` module skeleton
- [ ] Database migrations (7 tables)
- [ ] Webhook routes + verification + signature validation
- [ ] `WhatsAppGateway` service (Meta API client)
- [ ] `ProcessIncomingWhatsAppMessage` job
- [ ] Contact matching (phone → Vendor/Store)

### Phase 2: Onboarding Flow (Week 2-3)
- [ ] WhatsApp Flow definition (7 screens)
- [ ] `VendorOnboardingService` (extract from VendorController)
- [ ] `ConversationManager` state machine
- [ ] Admin approval integration (reuse existing)
- [ ] Approval/rejection WhatsApp notifications

### Phase 3: AI Concierge (Week 3-4)
- [ ] `ConciergeAgent` extending AI module patterns
- [ ] Tool definitions (10-15 vendor tools)
- [ ] Authorization + confirmation middleware
- [ ] Structured output parsing
- [ ] Fallback deterministic flows

### Phase 4: Vendor Management (Week 4-5)
- [ ] Product CRUD via WhatsApp
- [ ] Order/Sales queries
- [ ] Shop pause/resume
- [ ] Document upload + OCR assist
- [ ] Human handoff escalation

### Phase 5: Analytics & Hardening (Week 5-6)
- [ ] Event tracking (`onboarding_events`)
- [ ] Conversion funnel dashboard
- [ ] Idempotency tests
- [ ] Load testing webhook
- [ ] Documentation + Go-live checklist

---

## 18. Next Steps

1. **Approve this audit** → Proceed to Phase 1 implementation
2. **Provide Meta credentials** → WhatsApp Cloud API access
3. **Confirm AI provider** → OpenAI vs Anthropic (laravel/ai supports both)
4. **Review admin panel changes** → Add `application_source` column to stores?

---

*This audit represents ~8 hours of codebase exploration. All findings are based on actual code inspection, not assumptions.*