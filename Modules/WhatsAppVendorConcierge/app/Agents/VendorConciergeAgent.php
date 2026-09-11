<?php

namespace Modules\WhatsAppVendorConcierge\app\Agents;

use Modules\WhatsAppVendorConcierge\app\Agents\Tools\GetVendorProfileTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\CreateProductTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\UpdateProductTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\GetOrdersTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\GetSalesTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\PauseShopTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\ResumeShopTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\GetOrderDetailsTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\GetProductsTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\UpdateOrderStatusTool;
use Modules\WhatsAppVendorConcierge\app\Agents\Tools\GetStoreAnalyticsTool;
use App\Models\Vendor;
use App\Models\Store;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class VendorConciergeAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * @param  Message[]  $history       Previous conversation messages from DB
     * @param  Vendor     $vendor        The vendor this agent is assisting
     * @param  Store      $store         The vendor's store
     */
    public function __construct(
        private readonly VendorAiContext $context,
        private readonly array             $history  = [],
        private readonly ?Vendor           $vendor   = null,
        private readonly ?Store            $store    = null,
        private readonly string            $language = 'en',
    ) {}

    // -------------------------------------------------------------------------
    // Agent contract
    // -------------------------------------------------------------------------

    public function instructions(): Stringable|string
    {
        $appName      = config('app.name', 'MyTijaara');
        $vendorBlock  = $this->vendorContextBlock();
        $storeBlock   = $this->storeContextBlock();
        $languageBlock = $this->languageBlock();

        return <<<INSTRUCTIONS
You are **MyTijaara Vendor Concierge** — a smart, friendly AI assistant for marketplace vendors. You help vendors manage their shop, products, orders, and sales through natural conversation on WhatsApp.

{$vendorBlock}

{$storeBlock}

{$languageBlock}

===== YOUR CAPABILITIES =====

You can help vendors with:
• **Profile & Shop Management** — View/update vendor profile, shop details, operating hours
• **Product Management** — Add new products, update existing products, view product catalog
• **Order Management** — View orders (today/this week/this month), get order details, update order status
• **Sales & Analytics** — Daily/weekly/monthly sales reports, top-selling products, revenue tracking
• **Shop Operations** — Pause/resume shop, toggle open/closed status

===== TONE & STYLE =====

• Conversational, warm, professional — like a helpful business partner
• Concise: 1-3 sentences for simple answers, bullet lists for structured data
• Use WhatsApp-friendly formatting: **bold**, *italic*, emojis for visual scanning
• Always show prices in **₦** (Nigerian Naira) with proper formatting (e.g., ₦1,250.00)
• Never show internal IDs, technical details, or raw tool output to the vendor

===== MANDATORY RULES =====

RULE 0 — CLASSIFY BEFORE YOU ACT:
Not every message needs a tool. Classify first:

  a) **GREETING / THANKS / ACK** — "hi", "hello", "thanks", "ok"
     → Warm 1-sentence reply. No tool. End with a helpful prompt.

  b) **VENDOR PROFILE / SHOP INFO** — "my details", "shop info", "my profile"
     → Call GetVendorProfileTool

  c) **PRODUCT QUERIES** — "my products", "what am I selling", "show catalog"
     → Call GetProductsTool

  d) **ADD PRODUCT** — "add product", "new item", "list product"
     → Call CreateProductTool (will ask for details if missing)

  e) **UPDATE PRODUCT** — "update product", "change price", "edit product"
     → Call UpdateProductTool (needs product ID)

  f) **ORDER QUERIES** — "my orders", "orders today", "recent orders"
     → Call GetOrdersTool with appropriate timeframe

  g) **ORDER DETAILS** — "order #123", "details for order X"
     → Call GetOrderDetailsTool with order ID

  h) **UPDATE ORDER STATUS** — "mark order delivered", "confirm order", "cancel order"
     → Call UpdateOrderStatusTool with order ID and new status

  i) **SALES REPORTS** — "sales today", "how much did I sell", "revenue this week"
     → Call GetSalesTool with timeframe

  j) **ANALYTICS** — "top products", "best selling", "shop analytics"
     → Call GetStoreAnalyticsTool

  k) **PAUSE/RESUME SHOP** — "pause shop", "close shop", "open shop", "resume shop"
     → Call PauseShopTool or ResumeShopTool

  l) **OFF-TOPIC** — weather, jokes, personal chat
     → Brief acknowledgment, pivot to vendor help in 1 line

RULE 1 — SEARCH BEFORE YOU SPEAK:
You MUST call a tool before making ANY statement about products, orders, sales, or shop data.
NEVER say "you have X orders" or "your sales are Y" without calling the appropriate tool first.

RULE 2 — CONFIRM MUTATING & DESTRUCTIVE ACTIONS:
For pausing shop, resuming shop, cancelling/delivering orders, price changes, or publishing products — ALWAYS confirm with the vendor before executing. Present the summary and ask for their confirmation. When the vendor replies with "yes", "confirm", or approval, pass `confirm: true` to the respective tool to execute the action.

RULE 3 — HANDLE MISSING DATA GRACEFULLY:
If a tool returns no data (no products, no orders), say so honestly and suggest a next step.
Example: "You don't have any products yet. Want to add your first one?"

RULE 4 — LANGUAGE:
Respond in the vendor's language ({$this->language}). If they switch languages, switch with them.
Supported: English, Hausa, Yoruba, Igbo, Pidgin.

===== RESPONSE FORMAT =====

For structured data (products, orders, sales), use clean bullet lists:

**Your Products:**
• Product Name — ₦1,250 — 15 in stock ⭐ 4.5
• Another Product — ₦3,500 — 3 in stock ⭐ 4.2

**Today's Orders (5):**
• #ORD-1234 — ₦5,200 — *Pending* — 10 mins ago
• #ORD-1235 — ₦12,500 — *Confirmed* — 1 hour ago

**Sales Summary (Today):**
• Revenue: ₦45,750
• Orders: 12
• Avg Order: ₦3,812
• Top Product: Jollof Rice (₦15,000)

===== ERROR HANDLING =====

If a tool fails, relay the specific reason naturally:
• "I couldn't find that product. Let me show you your catalog first."
• "The order is already delivered and can't be cancelled."
• "Your shop is already open."

Never say "something went wrong" — be specific about what and why.

INSTRUCTIONS;
    }

    /**
     * @return Message[]
     */
    public function messages(): iterable
    {
        return $this->history;
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new GetVendorProfileTool($this->context, $this->vendor, $this->store),
            new GetProductsTool($this->context, $this->vendor, $this->store),
            new CreateProductTool($this->context, $this->vendor, $this->store),
            new UpdateProductTool($this->context, $this->vendor, $this->store),
            new GetOrdersTool($this->context, $this->vendor, $this->store),
            new GetOrderDetailsTool($this->context, $this->vendor, $this->store),
            new UpdateOrderStatusTool($this->context, $this->vendor, $this->store),
            new GetSalesTool($this->context, $this->vendor, $this->store),
            new GetStoreAnalyticsTool($this->context, $this->vendor, $this->store),
            new PauseShopTool($this->context, $this->vendor, $this->store),
            new ResumeShopTool($this->context, $this->vendor, $this->store),
        ];
    }

    // -------------------------------------------------------------------------
    // Dynamic instruction builders
    // -------------------------------------------------------------------------

    private function vendorContextBlock(): string
    {
        if (!$this->vendor) {
            return 'VENDOR CONTEXT: No vendor linked — this should not happen for registered vendors.';
        }

        $lines = [
            "Vendor ID: {$this->vendor->id}",
            "Vendor Name: " . trim(($this->vendor->f_name ?? '') . ' ' . ($this->vendor->l_name ?? '')),
            "Phone: {$this->vendor->phone}",
            "Email: {$this->vendor->email}",
            "Status: " . ($this->vendor->status ? 'Active' : 'Inactive'),
        ];

        if ($this->vendor->userinfo) {
            $info = $this->vendor->userinfo;
            if ($info->company_name) $lines[] = "Business Name: {$info->company_name}";
            if ($info->address) $lines[] = "Business Address: {$info->address}";
            if ($info->city) $lines[] = "City: {$info->city}";
            if ($info->state) $lines[] = "State: {$info->state}";
        }

        return "VENDOR CONTEXT:\n" . implode("\n", $lines);
    }

    private function storeContextBlock(): string
    {
        if (!$this->store) {
            return 'STORE CONTEXT: No store linked yet — vendor may be in onboarding.';
        }

        $lines = [
            "Store ID: {$this->store->id}",
            "Store Name: {$this->store->name}",
            "Phone: {$this->store->phone}",
            "Email: {$this->store->email}",
            "Address: {$this->store->address}",
            "Status: " . ($this->store->status ? 'Approved' : 'Pending Approval'),
            "Active (Open): " . ($this->store->active ? 'Yes 🟢' : 'No 🔴'),
            "Delivery: " . ($this->store->delivery ? 'Enabled' : 'Disabled'),
            "Takeaway: " . ($this->store->take_away ? 'Enabled' : 'Disabled'),
            "Module: {$this->store->module?->name ?? 'Unknown'}",
            "Zone: {$this->store->zone?->name ?? 'Unknown'}",
        ];

        if ($this->store->off_day) {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $offDays = array_map(fn($d) => $days[(int)$d] ?? $d, str_split($this->store->off_day));
            $lines[] = "Closed on: " . implode(', ', $offDays);
        }

        return "STORE CONTEXT:\n" . implode("\n", $lines);
    }

    private function languageBlock(): string
    {
        $languages = [
            'en' => 'English',
            'ha' => 'Hausa',
            'yo' => 'Yoruba',
            'ig' => 'Igbo',
            'pcm' => 'Nigerian Pidgin',
        ];

        $current = $languages[$this->language] ?? 'English';
        $supported = implode(', ', $languages);

        return "LANGUAGE: Current conversation language is **{$current}**.\n" .
               "Supported: {$supported}\n" .
               "Always respond in the vendor's language. If they write in an unsupported language, reply in English with: \"I'm available in: {$supported}. Please write in one of these languages.\"";
    }
}