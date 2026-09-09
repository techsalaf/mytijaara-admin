# MyTijaara WhatsApp Vendor Concierge & Onboarding Module

A complete, enterprise-grade WhatsApp integration for MyTijaara multivendor platform. Provides automated vendor onboarding via WhatsApp Flows/conversations, admin approval synchronization, and an AI-powered conversational concierge for vendor store management.

---

## 📋 Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Features](#features)
3. [Installation & Setup](#installation--setup)
4. [Configuration](#configuration)
5. [WhatsApp Meta Setup](#whatsapp-meta-setup)
6. [Webhook Endpoints](#webhook-endpoints)
7. [Database Schema](#database-schema)
8. [Vendor Onboarding Flow](#vendor-onboarding-flow)
9. [AI Vendor Concierge & Tools](#ai-vendor-concierge--tools)
10. [Queues & Background Processing](#queues--background-processing)
11. [Security & Idempotency](#security--idempotency)
12. [Troubleshooting & Runbook](#troubleshooting--runbook)
13. [Testing](#testing)

---

## 🏗️ Architecture Overview

The module follows a clean, decoupled service architecture:

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
│  └── Human Handoff                                          │
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

## ✨ Features

- **WhatsApp Cloud API Integration**: Direct integration with Meta Graph API v21.0
- **Automated Vendor Onboarding**: Progressive, multi-step onboarding via WhatsApp chat
- **WhatsApp Flows Support**: Compatible with Meta native WhatsApp Flows for structured forms
- **AI Vendor Concierge**: Natural language store management using `laravel/ai`
- **11 Vendor Tools**: Profile, products, orders, sales, analytics, shop pause/resume
- **Multilingual**: Supports English, Hausa, Yoruba, Igbo, and Nigerian Pidgin
- **Admin Approval Integration**: Synchronized with existing MyTijaara admin panel
- **Robust Idempotency**: Meta webhook deduplication via unique message IDs
- **Async Queue Processing**: Non-blocking webhook responses (<500ms)
- **Media & Document Handling**: Auto-download and storage of vendor documents/images

---

## 🚀 Installation & Setup

### 1. Enable Module in Laravel

The module is located at `Modules/WhatsAppVendorConcierge`. It auto-registers via `WhatsAppVendorConciergeServiceProvider`.

Ensure `modules_statuses.json` has:
```json
{
    "WhatsAppVendorConcierge": true
}
```

### 2. Run Database Migrations

```bash
php artisan module:migrate WhatsAppVendorConcierge
```

### 3. Configure Environment Variables

Add to your `.env` file:

```env
# Meta WhatsApp Cloud API
WHATSAPP_API_VERSION=v21.0
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id
WHATSAPP_BUSINESS_ACCOUNT_ID=your_business_account_id
WHATSAPP_ACCESS_TOKEN=your_system_user_access_token
WHATSAPP_APP_ID=your_meta_app_id
WHATSAPP_APP_SECRET=your_meta_app_secret
WHATSAPP_VERIFY_TOKEN=generate_a_secure_random_string

# AI Concierge Provider (openai or anthropic)
WHATSAPP_AI_PROVIDER=openai
WHATSAPP_AI_MODEL=gpt-4o

# Queue Configuration
QUEUE_CONNECTION_WHATSAPP=database
```

### 4. Configure Queues

Start queue workers for WhatsApp queues:

```bash
php artisan queue:work --queue=whatsapp.process_incoming,whatsapp.send_message,whatsapp.run_ai_conversation,whatsapp.process_media,whatsapp.process_document
```

---

## ⚙️ Configuration

Full configuration options in `Modules/WhatsAppVendorConcierge/config/config.php`:

| Key | Description | Default |
|-----|-------------|---------|
| `api.version` | Meta Graph API version | `v21.0` |
| `messaging.supported_locales` | Supported languages | `['en', 'yo', 'ha', 'ig']` |
| `onboarding.session_ttl_minutes` | Onboarding session expiry | `10080` (7 days) |
| `ai.provider` | AI model provider | `openai` |
| `ai.model` | AI model name | `gpt-4o` |
| `security.require_verification_for_writes` | OTP check for sensitive actions | `true` |

---

## 📱 WhatsApp Meta Setup

### Step 1: Create Meta App
1. Go to [developers.facebook.com](https://developers.facebook.com)
2. Create Business App → Add **WhatsApp** product
3. Note your **Phone Number ID**, **WABA ID**, and **App Secret**

### Step 2: Generate System User Access Token
1. In Meta Business Manager → Users → System Users
2. Create System User with **Admin** role
3. Generate Token with permissions: `whatsapp_business_messaging`, `whatsapp_business_management`
4. Set token expiration to **Never**

### Step 3: Configure Webhook
1. In WhatsApp App Dashboard → Configuration → Webhook
2. **Callback URL:** `https://your-domain.com/webhooks/whatsapp`
3. **Verify Token:** Value of `WHATSAPP_VERIFY_TOKEN` in `.env`
4. Subscribe to webhook fields:
   - `messages` (inbound messages, status updates)
   - `message_template_status_update`

---

## 🔌 Webhook Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/webhooks/whatsapp` | Meta Webhook Verification Challenge |
| `POST` | `/webhooks/whatsapp` | Inbound Message & Event Receiver |
| `GET` | `/api/v1/whatsapp/conversations` | Admin API: List Conversations |
| `GET` | `/api/v1/whatsapp/onboarding-sessions` | Admin API: Onboarding Applications |

---

## 🗄️ Database Schema

### Tables Created:

1. **`whatsapp_contacts`**: WhatsApp phone numbers linked to vendor/user IDs
2. **`whatsapp_conversations`**: Conversation sessions and state machine states
3. **`whatsapp_messages`**: Full message audit log with Meta IDs (idempotency key)
4. **`whatsapp_media`**: Downloaded media files (logos, documents, IDs)
5. **`whatsapp_flows`**: WhatsApp Flows configurations and screen schemas
6. **`onboarding_sessions`**: Multi-step vendor registration state and form data
7. **`onboarding_events`**: Audit log of every onboarding transition and event

---

## 📝 Vendor Onboarding Flow

The onboarding conversation guides vendors step-by-step:

```
[Vendor: "I want to sell on MyTijaara"]
       │
       ▼
1. Business Basics ───► Name, First/Last Name, Description
       │
       ▼
2. Category Selection ─► Interactive List (Food, Grocery, Pharmacy, etc.)
       │
       ▼
3. Location ───────────► Send WhatsApp Location Pin or Type Address
       │
       ▼
4. Contact Info ───────► Phone (pre-filled), Email Address
       │
       ▼
5. Operating Hours ────► Daily schedules & opening/closing times
       │
       ▼
6. Document Upload ────► Business Registration (CAC) / ID / Logo
       │
       ▼
7. Review & Submit ────► Summary + Submit Button
       │
       ▼
[Admin Panel: Pending Vendor] ──► [Admin Approves] ──► [WhatsApp Notification]
```

---

## 🤖 AI Vendor Concierge & Tools

When an approved vendor chats, the AI Concierge handles their requests via tools:

| Tool | Capability | Example Prompt |
|------|------------|----------------|
| `GetVendorProfileTool` | View business & profile details | *"Show my shop details"* |
| `GetProductsTool` | List products, filter low-stock | *"Show my products"*, *"What's low on stock?"* |
| `CreateProductTool` | Add new product to catalog | *"Add a new product: Jollof Rice at ₦2500"* |
| `UpdateProductTool` | Edit price, stock, details | *"Change price of product #12 to ₦3000"* |
| `GetOrdersTool` | View orders by timeframe | *"Show orders today"*, *"Any new orders?"* |
| `GetOrderDetailsTool` | Full order & customer info | *"Details for order #1042"* |
| `UpdateOrderStatusTool` | Mark confirmed/delivered/etc. | *"Mark order #1042 as delivered"* |
| `GetSalesTool` | Sales and revenue reports | *"How much did I sell today?"* |
| `GetStoreAnalyticsTool` | Full store performance overview | *"Show my shop analytics"* |
| `PauseShopTool` | Temporarily pause/close shop | *"Pause my shop for today"* |
| `ResumeShopTool` | Re-open paused shop | *"Open my shop"* |

---

## 🔒 Security & Idempotency

- **Webhook Verification**: Validates `X-Hub-Signature-256` HMAC-SHA256 signature using `app_secret`
- **Idempotent Ingestion**: Unique constraint on `whatsapp_message_id` prevents duplicate processing
- **Safe State Transitions**: Order status updates validate state machine rules
- **Confirmation Prompts**: Sensitive actions (shop pause, order cancellation) require explicit vendor confirmation
- **Data Privacy**: No sensitive credentials or API keys exposed to vendors

---

## 🧪 Testing

Run the WhatsApp module test suite:

```bash
php artisan test Modules/WhatsAppVendorConcierge/tests
```

---

## 📋 Pre-Go-Live Checklist

- [ ] Meta Business Account verified
- [ ] WhatsApp Business Number registered and verified
- [ ] System User Access Token configured with permanent validity
- [ ] Webhook URL registered and verified in Meta Dashboard
- [ ] Subscribed to `messages` webhook event
- [ ] Message templates submitted and approved in Meta Business Manager
- [ ] Queue workers running for `whatsapp.*` queues
- [ ] S3 / Public storage configured for media downloads
- [ ] Admin notification email / alerts configured for new applications
- [ ] Test end-to-end onboarding flow on staging number
- [ ] Test AI concierge with active vendor account