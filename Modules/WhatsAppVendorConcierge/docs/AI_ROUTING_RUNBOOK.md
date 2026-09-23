# OmniRoute-Style AI Routing Subsystem Runbook

## 1. Overview & Architecture

The **OmniRoute-Style AI Routing Subsystem** provides multi-provider redundancy, automated model discovery, intelligent fallback, cost control, and capability-aware routing for the MyTijaara WhatsApp Vendor Concierge.

### Architectural Principles

```
                  ┌───────────────────────────────┐
                  │ WhatsApp Inbound Message /    │
                  │ VendorConciergeAgent          │
                  └───────────────┬───────────────┘
                                  │
                                  ▼
                  ┌───────────────────────────────┐
                  │       AiRouterService         │
                  │  (Requirement Classifier:    │
                  │   Tools, Vision, Context)     │
                  └───────────────┬───────────────┘
                                  │
               ┌──────────────────┴──────────────────┐
               ▼                                     ▼
┌──────────────────────────────┐     ┌──────────────────────────────┐
│    Active Routing Policy     │     │   AiCircuitBreakerService    │
│ (Free First, Strict Fallback,│     │  (Health, Rate-Limits,       │
│  Round Robin, Lowest Cost)   │     │   Daily & Monthly Budgets)   │
└──────────────┬───────────────┘     └──────────────┬───────────────┘
               │                                    │
               └──────────────────┬─────────────────┘
                                  ▼
               ┌─────────────────────────────────────┐
               │    Resolved Candidates (Ordered)    │
               │  Only tool-capable models if tools  │
               │  required; free-first prioritized   │
               └──────────────────┬──────────────────┘
                                  │
          ┌───────────────────────┼───────────────────────┐
          ▼                       ▼                       ▼
┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐
│  Groq Cloud /    │    │  Google Gemini / │    │  OpenAI / Open-  │
│  NVIDIA NIM      │    │  Free Tier       │    │  Router Fallback │
│  (Primary Free)  │    │  (Secondary Free)│    │  (Emergency Paid)│
└──────────────────┘    └──────────────────┘    └──────────────────┘
```

---

## 2. Key Subsystem Components

1. **Provider Definitions (`ai_provider_definitions`)**
   - Canonical catalogue of platforms (OpenAI, Gemini, Groq, OpenRouter, NVIDIA NIM, DeepSeek, Anthropic).
   - Holds default base URLs, authentication specifications, and model discovery capabilities.

2. **Provider Connections (`ai_provider_connections`)**
   - Administrator-configured credentials and account settings.
   - Encrypted with AES-256 (`encrypted:array` cast). Never rendered in plaintext or returned in JSON responses.
   - Dynamic health states: `healthy`, `degraded`, `cooldown`, `rate_limited`, `auth_failed`, `budget_exhausted`, `disabled`.
   - Cost tracking: `current_day_cost_usd`, `current_month_cost_usd`, `daily_budget_usd`, `monthly_budget_usd`.

3. **Discovered Models (`ai_provider_models`)**
   - Granular models discovered automatically from provider endpoints or definition catalogues.
   - Verified capabilities: `supports_tool_calling`, `supports_vision`, `is_free_tier`, `context_window`.
   - Priority (e.g. 1 for free tier, 10 for paid tier) and Weight.

4. **Routing Policies (`ai_routing_policies`)**
   - `free_first`: Prioritises free-tier models (Groq, Gemini, OpenRouter `:free`, NVIDIA NIM), using paid models only as emergency fallback.
   - `strict_fallback`: Follows deterministic priority ordering.
   - `round_robin`: Cycles across eligible models evenly.
   - `lowest_cost`: Sorts by token pricing.
   - `quality_first`: Uses flagship paid models first, free as fallback.

5. **Circuit Breaker (`AiCircuitBreakerService`)**
   - Exponential cooldown when consecutive failures reach 3.
   - Automatic rate-limit detection with `Retry-After` reset timer.
   - Automatic status recovery when cooldown/rate-limit windows elapse.
   - Budget ceilings: halts routing to a connection when daily or monthly USD budget is reached.

---

## 3. Supported Providers & Adapters

| Provider | Adapter Class | Discovery API | Free Tier Available | Tool Calling Support |
| :--- | :--- | :--- | :--- | :--- |
| **OpenAI** | `OpenAiCompatibleAdapter` | `/v1/models` | No | Yes (GPT-4o, Mini, o1, o3) |
| **Google Gemini** | `GeminiAdapter` | `/v1beta/models` | Yes | Yes (Flash 2.0, 1.5, Pro) |
| **Groq Cloud** | `GroqAdapter` | `/v1/models` | Yes (LPU Fast) | Yes (Llama 3.3 70B) |
| **OpenRouter** | `OpenRouterAdapter` | `/api/v1/models` | Yes (`:free` models)| Yes |
| **NVIDIA NIM** | `NvidiaNimAdapter` | `/v1/models` | Yes (Credits) | Yes (Llama 3.3, Mistral) |
| **DeepSeek** | `OpenAiCompatibleAdapter` | `/v1/models` | Low cost | Yes (V3 Chat) |
| **Anthropic** | `OpenAiCompatibleAdapter` | Catalogue | No | Yes (Claude 3.5 Sonnet) |

---

## 4. Operational Instructions

### Adding a Provider Connection
1. In the admin panel, navigate to **WhatsApp Concierge > AI Providers**.
2. Click **Connect AI Provider** or choose one of the available catalogue cards.
3. Enter account label, API key, and choose your **Model Selection Strategy**:
   - `all_compatible`: Enables all compatible models discovered.
   - `auto_include_free`: Auto-enables only free models (zero cost operation).
   - `manual`: Allows selective manual toggling of models.
4. Optional: Set a daily or monthly budget cap (USD).
5. Click **Save & Discover Models**. The engine automatically fetches and registers all available models.

### Testing and Verification
- Click the **Test** button on any connection to perform an immediate live handshake.
- Click **Sync Models** to refresh available models from the provider endpoint.
- Navigate to **AI Routing Engine** in the sidebar to simulate prompt routing live and view recent latency, token consumption, and cost metrics.

### Migrating Legacy Providers
If upgrading an environment with pre-existing `whatsapp_ai_providers` entries, run:
```bash
php artisan whatsapp:migrate-legacy-ai-providers
```
This command is completely idempotent and migrates keys and priorities into the new connections and models tables without duplicates.

---

## 5. Security & Isolation Assurances

1. **Credential Safety**:
   - API keys are encrypted at rest using Laravel's application key.
   - API keys are excluded from `toArray()` and `toJson()` serialization on models.
   - Keys are never output in Blade views once saved (displayed only as masked bullets `••••••••`).
   - Keys are never included in prompts, logs, or exception traces.
2. **Core Boundary Isolation**:
   - All migrations, models, services, adapters, controllers, and views are strictly isolated within `Modules/WhatsAppVendorConcierge/`.
   - Core 6amMart tables (`stores`, `items`, `orders`) are never modified directly by the routing subsystem.
