<?php

namespace Modules\WhatsAppVendorConcierge\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\WhatsAppVendorConcierge\app\Models\WhatsAppMedia;

class CleanupMedia extends Command
{
    protected $signature = 'whatsapp:cleanup-media {--dry-run : Report candidates without deleting them}';
    protected $description = 'Delete expired WhatsApp media from its configured private disk';

    public function handle(): int
    {
        $deleted = 0;
        WhatsAppMedia::whereNotNull('cleanup_at')->where('cleanup_at', '<=', now())
            ->whereNotIn('status', ['cleaned_up'])->orderBy('id')->chunkById(100, function ($media) use (&$deleted) {
                foreach ($media as $record) {
                    if (!$this->option('dry-run') && $record->file_path) {
                        Storage::disk($record->storage_disk ?: config('whatsapp-vendor-concierge.media.storage_disk'))
                            ->delete($record->file_path);
                    }
                    if (!$this->option('dry-run')) {
                        $record->update(['status' => 'cleaned_up', 'file_path' => null, 'ocr_result' => null]);
                    }
                    $deleted++;
                }
            });
        $this->info(($this->option('dry-run') ? 'Would clean ' : 'Cleaned ').$deleted.' WhatsApp media records.');
        return self::SUCCESS;
    }
}
