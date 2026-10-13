from pathlib import Path
r=Path('Modules/WhatsAppVendorConcierge')
def put(p,s): (r/p).write_text(s,encoding='utf8')
put('database/migrations/2026_09_27_140000_create_inbound_processing_receipts.php',r'''<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('whatsapp_inbound_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_message_id')->unique();
            $table->unsignedBigInteger('message_id')->nullable()->index();
            $table->string('phase', 32)->default('received')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_type')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('whatsapp_inbound_receipts'); }
};
''')
put('app/Models/InboundReceipt.php',r'''<?php
namespace Modules\WhatsAppVendorConcierge\app\Models;
use Illuminate\Database\Eloquent\Model;
class InboundReceipt extends Model {
    protected $table = 'whatsapp_inbound_receipts';
    protected $guarded = [];
    protected $casts = ['completed_at' => 'datetime'];
}
''')
p=r/'app/Jobs/ProcessIncomingWhatsAppMessage.php';s=p.read_text();s=s.replace('use Modules\\WhatsAppVendorConcierge\\app\\Models\\WhatsAppContact;', 'use Modules\\WhatsAppVendorConcierge\\app\\Models\\WhatsAppContact;\nuse Modules\\WhatsAppVendorConcierge\\app\\Models\\InboundReceipt;')
s=s.replace("        $lock = null;", "        if (!$messageId) return;\n        $lock = null;\n        $receipt = null;",1)
s=s.replace("            if ($existingMessage) {", "            $receipt = InboundReceipt::where('whatsapp_message_id', $messageId)->first();\n            // Historical receipts cannot prove whether their side effects completed.\n            if ($existingMessage && !$receipt) {",1)
s=s.replace('            // Get or create contact',r'''            $receipt ??= InboundReceipt::create(['whatsapp_message_id' => $messageId]);
            if (in_array($receipt->phase, ['completed', 'needs_review'], true)) return;
            if ($receipt->phase === 'processing') {
                // A worker may have died after a mutation or external send. Never replay blindly.
                $receipt->update(['phase' => 'needs_review', 'error_type' => 'InterruptedProcessing']);
                return;
            }
            $receipt->increment('attempts');
            // Get or create contact''',1)
s=s.replace('$conversation = WhatsAppConversation::getOrCreateActive($contact->id);', '$conversation = $existingMessage?->conversation ?? WhatsAppConversation::getOrCreateActive($contact->id);',1)
s=s.replace("            if ($contact->is_blocked) {\n                return;", "            if ($contact->is_blocked) {\n                $receipt->update(['phase' => 'completed', 'completed_at' => now()]);\n                return;",1)
s=s.replace("            $message = WhatsAppMessage::logInbound($conversation->id, $this->messageData, $this->metaValue);", "            $message = $existingMessage ?? WhatsAppMessage::logInbound($conversation->id, $this->messageData, $this->metaValue);\n            $receipt->update(['message_id' => $message->id, 'error_type' => null]);",1)
s=s.replace('            // Process based on conversation state', "            $receipt->update(['phase' => 'processing']);\n            // Process based on conversation state",1)
s=s.replace("\n        } catch (\\Throwable $e) {\n            Log::error('ProcessIncomingWhatsAppMessage failed'", "\n            $receipt->update(['phase' => 'completed', 'completed_at' => now()]);\n        } catch (\\Throwable $e) {\n            if ($receipt) {\n                $receipt->update(['phase' => $receipt->phase === 'processing' ? 'needs_review' : 'received', 'error_type' => $e::class]);\n            }\n            Log::error('ProcessIncomingWhatsAppMessage failed'",1)
# Let a failed media enqueue retry the known safe pre-processing checkpoint.
s=s.replace("                'error' => $e->getMessage(),\n            ]);\n        }\n    }\n\n    /**\n     * Process message based", "                'error' => $e->getMessage(),\n            ]);\n            throw $e;\n        }\n    }\n\n    /**\n     * Process message based",1)
p.write_text(s)
p=r/'app/Http/Controllers/Admin/ResumeCampaignController.php';s=p.read_text();a=s.index('    /**\n     * Launch the campaign');s=s[:a]+'''    /** Bulk launch is unavailable until per-recipient delivery accounting is implemented. */
    public function launch(ResumeCampaign $campaign)
    {
        return redirect()->route('admin.whatsapp.resume-campaigns.index')
            ->with('error', 'Campaign saved as a draft. Bulk sending is not available yet. Use Operations Centre to preview eligible individual recovery prompts. No messages were sent.');
    }
}
''';p.write_text(s)
p=r/'resources/views/admin/resume_campaigns/index.blade.php';s=p.read_text();s=s.replace('    <div class="card">','''    <div class="alert alert-warning">Campaigns are draft-only. Bulk delivery is not implemented; previous counts are unverified and do not prove messages were sent. Use Operations Centre for previewed individual recovery.</div>
    @if(session('error'))<div class="alert alert-warning">{{ session('error') }}</div>@endif
    <div class="card">''',1).replace('<th>Sent</th>','<th>Historical count (unverified)</th>');a=s.index("                            @if($campaign->status === 'draft')");b=s.index('                            @endif',a)+len('                            @endif');s=s[:a]+'                            <span class="text-muted">Draft only — sending unavailable</span>'+s[b:];p.write_text(s)
p=r/'app/Services/AiAdapters/NvidiaNimAdapter.php';s=p.read_text().replace('createOpenaiDriver([','createGroqDriver([').replace("'driver' => 'openai',", "'driver' => 'groq',").replace("'name' => 'openai',", "'name' => 'nvidia_nim',");p.write_text(s)
