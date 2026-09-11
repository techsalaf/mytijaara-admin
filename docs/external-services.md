# External Services Directory

Required third-party accounts, APIs, credentials, and cost structures for the WhatsApp Vendor Concierge module.

---

## 1. Meta WhatsApp Cloud API
- **Purpose:** Core messaging infrastructure, inbound webhooks, and outbound messaging.
- **Account Required:** Yes, [Meta Developer Account](https://developers.facebook.com) + [Meta Business Manager](https://business.facebook.com).
- **Credentials Required:**
  - `WHATSAPP_PHONE_NUMBER_ID`
  - `WHATSAPP_BUSINESS_ACCOUNT_ID`
  - `WHATSAPP_ACCESS_TOKEN` (Permanent System User Token)
  - `WHATSAPP_APP_ID`
  - `WHATSAPP_APP_SECRET`
  - `WHATSAPP_VERIFY_TOKEN`
- **Cost Model:**
  - Inbound user-initiated conversations: 1,000 free service conversations per month per WABA. Subsequent user-initiated conversations are charged per Meta's country-specific conversation rate (approx $0.012 per conversation in Nigeria).
  - Business-initiated template notifications (e.g. store approval notifications outside 24h window) are billed as Utility conversations (approx $0.007 per conversation).

---

## 2. AI LLM Provider
- **Purpose:** Power the Vendor Concierge conversational agent and tool calling.
- **Supported Providers:**
  - **OpenAI:** `WHATSAPP_AI_PROVIDER=openai`, `WHATSAPP_AI_MODEL=gpt-4o` (or `gpt-4o-mini` for budget).
  - **Anthropic:** `WHATSAPP_AI_PROVIDER=anthropic`, `WHATSAPP_AI_MODEL=claude-3-5-sonnet-20241022`.
- **Credentials Required:**
  - `OPENAI_API_KEY` (if OpenAI selected)
  - `ANTHROPIC_API_KEY` (if Anthropic selected)
- **Cost Model:** Token-based pay-as-you-go. Average concierge conversation turn costs ~$0.002 on mini/haiku models or ~$0.015 on flagship models.

---

## 3. Storage Service (Media & Documents)
- **Purpose:** Storing downloaded vendor registration documents (CAC certificates, ID proofs) and product photos.
- **Options:**
  - **Local Public Disk (Default):** `WHATSAPP_MEDIA_DISK=public` (Stores in `storage/app/public/whatsapp`). Free, uses server disk space.
  - **Amazon S3 / DigitalOcean Spaces:** `WHATSAPP_MEDIA_DISK=s3`. Recommended for multi-server production environments.
- **Credentials Required (if S3):**
  - `AWS_ACCESS_KEY_ID`
  - `AWS_SECRET_ACCESS_KEY`
  - `AWS_DEFAULT_REGION`
  - `AWS_BUCKET`

---

## 4. Mail Transport (Support Escalation)
- **Purpose:** Delivers human-support escalation alerts (`VendorSupportEscalationMail`) to the platform admin team.
- **Provider:** Existing MyTijaara mail transport (SMTP, Mailgun, or Postmark).
- **Config:** Uses `config('mail.from.address')` and business setting `email_address`.
