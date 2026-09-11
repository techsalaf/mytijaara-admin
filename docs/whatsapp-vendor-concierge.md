# MyTijaara WhatsApp Vendor Concierge — Technical Specification

## 1. System Overview
The WhatsApp Vendor Concierge is a native Laravel module (`Modules/WhatsAppVendorConcierge`) that exposes the MyTijaara multivendor marketplace to vendors over WhatsApp using the official Meta WhatsApp Cloud API (v21.0).

WhatsApp serves purely as a conversational client interface. All vendor records, store settings, products, categories, orders, and subscriptions exist directly inside the primary MyTijaara MySQL database.

```
┌─────────────────────────────────────────────────────────────┐
│                 Meta WhatsApp Cloud API                     │
└──────────────────────────┬──────────────────────────────────┘
                           │ Webhook (POST) / API (Outbound)
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                 WebhookController (Fast 200 OK)             │
│  - Signature Verification (HMAC-SHA256)                     │
│  - Dispatch to Queue (ProcessIncomingWhatsAppMessage)       │
└──────────────────────────┬──────────────────────────────────┘
                           ▼
┌─────────────────────────────────────────────────────────────┐
│             ProcessIncomingWhatsAppMessage (Async Job)       │
│  - Deduplication via whatsapp_message_id                    │
│  - Contact & Conversation Resolution                        │
│  - Inbound Message Logging                                  │
│  - Media Download Queue (if media attached)                 │
└──────────────────────────┬──────────────────────────────────┘
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                  ConversationManager                        │
│               (State Machine Dispatcher)                    │
│  ├── Welcome / Main Menu                                    │
│  ├── Onboarding Flow (VendorOnboardingService)              │
│  ├── AI Concierge (RunVendorAiConversation)                 │
│  └── Human Handoff (Support Escalation)                     │
└──────────────────────────┬──────────────────────────────────┘
                           ▼
┌─────────────────────────────────────────────────────────────┐
│           VendorOnboardingService / AI Agent Tools          │
│  - Reuses existing StoreLogic & Vendor registration         │
│  - Creates Vendor & Store records (pending admin approval)  │
│  - AI Agent with 11 specialized vendor tools                │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Inbound Webhook Pipeline & Idempotency
- **GET `/webhooks/whatsapp`**: Meta verification challenge. Validates `hub.verify_token` against `WHATSAPP_VERIFY_TOKEN` and echoes `hub.challenge` as plain text. Handles PHP's dot-to-underscore parameter normalization.
- **POST `/webhooks/whatsapp`**: Event payload receiver. Validates HMAC-SHA256 signature in header `X-Hub-Signature-256` using `WHATSAPP_APP_SECRET`. Responds immediately with HTTP 200 OK (`{"status":"received"}`) and dispatches `ProcessIncomingWhatsAppMessage` to the queue.
- **Deduplication**: Uses Meta's `whatsapp_message_id` with a database unique index. If Meta re-delivers the same message webhook, the job identifies the existing record and aborts immediately.

---

## 3. Database Entities & Relationships
1. **`whatsapp_contacts`**: Maps WhatsApp phone numbers to MyTijaara `vendor_id` and `user_id`.
2. **`whatsapp_conversations`**: Tracks conversational state (`new`, `welcome`, `onboarding_active`, `active_vendor`, `human_handoff`).
3. **`whatsapp_messages`**: Comprehensive message audit log (inbound/outbound, message types, delivery status).
4. **`whatsapp_media`**: Tracks media items (IDs, MIME types, local disk paths, checksums).
5. **`whatsapp_flows`**: Meta Flow JSON definitions and screen mappings.
6. **`onboarding_sessions`**: Multi-step vendor registration session data.
7. **`onboarding_events`**: Transition log for conversion funnel analytics.

---

## 4. Vendor Onboarding Lifecycle
1. **Step 1: Business Basics**: Captures business name and description.
2. **Step 2: Category Selection**: Presents interactive picker matching MyTijaara active categories.
3. **Step 3: Location Pin**: Captures WhatsApp GPS location pin, resolving geographic `zone_id`.
4. **Step 4: Contact Info**: Auto-fills WhatsApp number and captures business email.
5. **Step 5: Operating Hours**: Captures daily schedule.
6. **Step 6: Document Upload**: Receives business registration (CAC) or identity documents.
7. **Step 7: Review & Submit**: Summarizes details. Upon vendor submission, creates pending `Vendor` and `Store` in MyTijaara core.
8. **Admin Approval Loopback**:
   - `VendorApprovalObserver` monitors `App\Models\Vendor`.
   - When an administrator approves the store in the admin panel, an automated approval notification is dispatched to the vendor over WhatsApp.
   - If denied, the rejection notice with the administrator's reason is sent.

---

## 5. AI Concierge & Vendor Management
For approved vendors, conversations route to `RunVendorAiConversation` and `VendorConciergeAgent`.
The agent invokes 11 controlled tools:
- `GetVendorProfileTool`: View business details and operational status.
- `GetProductsTool`: Query products and identify low-stock items.
- `CreateProductTool`: Add new product (requires confirmation).
- `UpdateProductTool`: Edit price/stock/details (requires confirmation).
- `GetOrdersTool`: Query orders by timeframe (today, this week, this month).
- `GetOrderDetailsTool`: Fetch full order line items and customer information.
- `UpdateOrderStatusTool`: Transition orders (requires confirmation for delivered/cancelled).
- `GetSalesTool`: Daily, weekly, monthly sales reports.
- `GetStoreAnalyticsTool`: Performance overview and top-selling items.
- `PauseShopTool`: Pause shop (requires confirmation).
- `ResumeShopTool`: Open shop (requires confirmation).

---

## 6. Photo-to-Product Workflow
When an approved vendor sends an image over WhatsApp:
1. `handleAiMessage` detects the media type as `image`.
2. The image is downloaded and indexed in `whatsapp_media`.
3. The conversation records `last_product_media_id` and prompts the vendor for name and price.
4. When the vendor supplies details, the AI agent drafts the listing with the image path attached.
5. Vendor confirms via interactive reply before publishing to the live shop.

---

## 7. Human Handoff & Support Escalation
When a vendor requests human support:
1. `ConversationManager` switches state to `human_handoff`.
2. Sends the vendor their tracking reference: `WHATSAPP-{id}`.
3. Dispatches `VendorSupportEscalationMail` to the platform support inbox containing vendor details, phone number, and direct WhatsApp reply link.
4. Logs an escalation event in `onboarding_events`.
