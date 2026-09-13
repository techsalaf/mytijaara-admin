<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;

class KycDocumentController extends Controller
{
    public function show(WhatsAppMedia $media)
    {
        abort_unless($media->status === 'processed' && $media->file_path && $media->storage_disk === 'local', 404);
        abort_unless(Storage::disk('local')->exists($media->file_path), 404);
        return Storage::disk('local')->download($media->file_path, 'vendor-kyc-'.$media->id);
    }
}
