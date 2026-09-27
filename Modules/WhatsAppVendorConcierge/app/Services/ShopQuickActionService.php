<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Item;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;

/** Explicit commands prepare existing canonical confirmations; they never mutate inventory. */
class ShopQuickActionService
{
    public function handle(string $text, WhatsAppConversation $conversation, WhatsAppContact $contact, WhatsAppGateway $gateway): bool
    {
        if ($conversation->state !== 'ai_active' || !preg_match('/^set\s+(price|stock)\b/i', trim($text))) return false;
        if (!preg_match('/^set\s+(price|stock)\s+#?([1-9][0-9]{0,9})\s+(?:to\s+)?([0-9]{1,9}(?:\.[0-9]{1,2})?)$/i', trim($text), $parts)) {
            $gateway->sendTextMessage($contact->phone_number, "✏️ Use one change at a time:\n• *Set price #123 13000* (naira)\n• *Set stock #123 30* (whole units)\n\nReplace 123 with your product ID. You will review and confirm before anything changes.");
            return true;
        }
        $field = strtolower($parts[1]);
        $value = $parts[3];
        if (($field === 'price' && (float) $value <= 0) || ($field === 'stock' && str_contains($value, '.'))) {
            $gateway->sendTextMessage($contact->phone_number, 'Use a price greater than zero, or a whole-number stock quantity of zero or more.');
            return true;
        }
        $store = $contact->vendor?->store;
        if (!$store || (int) $contact->vendor?->status !== 1 || (int) $store->status !== 1 || $contact->is_blocked || (int) $conversation->contact_id !== (int) $contact->id || (int) $conversation->vendor_id !== (int) $contact->vendor_id) {
            $gateway->sendTextMessage($contact->phone_number, 'Shop updates are available only to the linked, approved vendor. Type *Support* if you need help.');
            return true;
        }
        $item = Item::where('store_id', $store->id)->find((int) $parts[2]);
        if (!$item) {
            $gateway->sendTextMessage($contact->phone_number, 'That product was not found in your shop. Type *Product status* to find your product IDs.');
            return true;
        }
        $variations = is_array($item->variations) ? $item->variations : json_decode($item->variations ?: '[]', true);
        if (!empty($variations) || ($field === 'stock' && !config('module.'.$store->module?->module_type.'.stock', true))) {
            $gateway->sendTextMessage($contact->phone_number, 'This product needs module-specific or variation details. Use *Manage shop* to update it in your dashboard; its current details are unchanged.');
            return true;
        }
        $actions = app(PendingActionService::class);
        if ($field === 'price') $actions->prepareProductPrice($contact->id, $conversation->id, $item->id, (float) $value);
        else $actions->prepareProductStock($contact->id, $conversation->id, $item->id, (int) $value);
        return true;
    }
}
