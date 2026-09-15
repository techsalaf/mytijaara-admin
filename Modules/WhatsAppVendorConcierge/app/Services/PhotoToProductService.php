<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Models\Category;
use App\Models\Store;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppVendorConcierge\app\Models\PendingAction;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppContact;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppConversation;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;

class PhotoToProductService
{
    public function __construct(
        protected MediaPolicyService $mediaPolicyService,
        protected PendingActionService $pendingActionService,
    ) {}

    /**
     * Start a draft product from uploaded media, validating bytes and applying untrusted AI suggestions if provided.
     */
    public function startDraftFromMedia(
        WhatsAppContact $contact,
        WhatsAppConversation $conversation,
        WhatsAppMedia $media,
        ?array $aiInference = null
    ): array {
        abort_unless((int) $conversation->contact_id === (int) $contact->id && $contact->vendor_id, 403);
        app(\Modules\WhatsAppVendorConcierge\app\Services\CoreAdapters\ProductMedia::class)->owned($media->id, $contact->vendor_id);
        // Validate media through MediaPolicyService
        $validation = $this->mediaPolicyService->validateProductImage($media);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error'] ?? 'Image does not meet product photo specifications.',
            ];
        }

        $draft = [
            'media_id' => $media->id,
            'image_path' => $media->file_path,
            'name' => $aiInference['name'] ?? null,
            'description' => $aiInference['description'] ?? null,
            'category_id' => $aiInference['category_id'] ?? null,
            'category_name' => $aiInference['category_name'] ?? null,
            'price' => null,
            'stock' => 0,
            'is_ai_draft' => !empty($aiInference),
            'confirmed_by_vendor' => false,
            'step' => 'awaiting_details',
            'created_at' => now()->toIso8601String(),
        ];

        $context = $conversation->context ?? [];
        $context['photo_to_product_draft'] = $draft;
        $conversation->update(['context' => $context]);

        $prompt = "📸 *Product Photo Received & Validated!*\n\n";
        if (!empty($aiInference['name'])) {
            $prompt .= "I analyzed the image and suggest:\n";
            $prompt .= "• Suggested Name: *{$aiInference['name']}*\n";
            if (!empty($aiInference['category_name'])) {
                $prompt .= "• Suggested Category: *{$aiInference['category_name']}*\n";
            }
            $prompt .= "\n*Please review and confirm or provide the exact details:*\n";
        } else {
            $prompt .= "*Please provide the product details:*\n";
        }
        $prompt .= "• Name: (e.g. Fresh Mangoes)\n";
        $prompt .= "• Price in ₦: (e.g. ₦1,500)\n";
        $prompt .= "• Category: (from your shop categories)\n";
        $prompt .= "• Stock: (default 0 units)\n\n";
        $prompt .= "Reply with: *Name*, *Price*, and *Category*.";

        return [
            'success' => true,
            'draft' => $draft,
            'prompt' => $prompt,
        ];
    }

    /**
     * Parse structured or semi-structured vendor response into product draft fields.
     */
    public function parseVendorDetails(string $text, Store $store): array
    {
        $data = [];

        // Match Price
        if (preg_match('/(?:price|₦|naira)[:\s]*([0-9,]+(?:\.[0-9]{1,2})?)/i', $text, $matches)) {
            $data['price'] = (float) str_replace(',', '', $matches[1]);
        } elseif (preg_match('/\b(?:₦\s*|NGN\s*)([0-9,]+(?:\.[0-9]{1,2})?)/i', $text, $matches)) {
            $data['price'] = (float) str_replace(',', '', $matches[1]);
        }

        // Match Stock
        if (preg_match('/(?:stock|quantity|qty)[:\s]*([0-9]+)/i', $text, $matches)) {
            $data['stock'] = (int) $matches[1];
        }

        // Match Name
        if (preg_match('/(?:name|title)[:\s]*([^\n,]+)/i', $text, $matches)) {
            $data['name'] = trim($matches[1]);
        }

        // Match Category
        if (preg_match('/(?:category|cat)[:\s]*([^\n,]+)/i', $text, $matches)) {
            $catSearch = trim($matches[1]);
            $category = Category::where('name', 'like', "%{$catSearch}%")
                ->where(function ($q) use ($store) {
                    $q->where('module_id', $store->module_id)->orWhereNull('module_id');
                })->first();
            if ($category) {
                $data['category_id'] = $category->id;
                $data['category_name'] = $category->name;
            }
        }

        return $data;
    }

    /**
     * Submit draft for explicit vendor confirmation via PendingAction.
     *
     * @throws ValidationException
     */
    public function prepareConfirmation(
        WhatsAppContact $contact,
        WhatsAppConversation $conversation,
        Store $store
    ): PendingAction {
        $context = $conversation->context ?? [];
        $draft = $context['photo_to_product_draft'] ?? null;

        if (!$draft) {
            throw ValidationException::withMessages([
                'draft' => ['No active product draft found. Please upload a photo to start.'],
            ]);
        }

        if (empty($draft['name']) || empty($draft['price']) || empty($draft['category_id'])) {
            throw ValidationException::withMessages([
                'draft' => ['Please provide Name, Price, and Category before confirming the product.'],
            ]);
        }

        $productData = [
            'name' => $draft['name'],
            'price' => (float) $draft['price'],
            'category_id' => (int) $draft['category_id'],
            'stock' => (int) ($draft['stock'] ?? 0),
            'description' => $draft['description'] ?? null,
            'media_id' => $draft['media_id'],
        ];

        return $this->pendingActionService->prepareProductCreate(
            $contact->id,
            $conversation->id,
            $store->id,
            $productData
        );
    }
}
