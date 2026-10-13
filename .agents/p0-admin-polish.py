from pathlib import Path
r=Path('Modules/WhatsAppVendorConcierge')
p=r/'resources/views/admin/ai_providers/index.blade.php';s=p.read_text();needle='                            <div class="small text-muted mt-1">{{ strtoupper($conn->status) }}</div>';assert needle in s;s=s.replace(needle,needle+'''
                            @if($conn->verification)
                                <details class="small mt-1">
                                    <summary>Verification evidence</summary>
                                    @foreach(['endpoint_check' => 'Endpoint check', 'discovery' => 'Model discovery'] as $key => $label)
                                        @if(isset($conn->verification[$key]))
                                            <div>{{ $label }}: {{ $conn->verification[$key]['passed'] ? 'passed' : 'failed' }} · {{ $conn->verification[$key]['at'] }}</div>
                                        @endif
                                    @endforeach
                                    @foreach($conn->verification['models'] ?? [] as $modelId => $checks)
                                        @foreach($checks as $kind => $check)
                                            <div>{{ $modelId }} · {{ $kind }}: {{ $check['passed'] ? 'passed' : 'failed' }} · {{ $check['at'] }}</div>
                                        @endforeach
                                    @endforeach
                                    <div class="text-muted">Endpoint access and discovery do not prove text or tool execution.</div>
                                </details>
                            @endif''');p.write_text(s)
p=r/'app/Services/Operations/ConciergeDiagnosticService.php';s=p.read_text().replace("['silenced_onboarding', 'stale_human_handoff', 'validation_failure_loop', 'outbound_failed', 'inconsistent_application']", "['silenced_onboarding', 'stale_human_handoff', 'validation_failure_loop', 'outbound_failed', 'inconsistent_application', 'inbound_interrupted', 'inbound_retry_pending']");needle="        $receipt = $in ? \\Modules\\WhatsAppVendorConcierge\\app\\Models\\InboundReceipt::where('message_id', $in->id)->first() : null;";repl=r'''        $receipt = \Modules\WhatsAppVendorConcierge\app\Models\InboundReceipt::whereIn('message_id', $conversation->messages()->select('id'))
            ->where(function ($q) { $q->where('phase', 'needs_review')->orWhere(function ($q) { $q->whereIn('phase', ['received','processing'])->where('updated_at', '<', now()->subMinutes(3)); }); })
            ->latest('id')->first();
        $receipt ??= $in ? \Modules\WhatsAppVendorConcierge\app\Models\InboundReceipt::where('message_id', $in->id)->first() : null;''';assert needle in s;s=s.replace(needle,repl);p.write_text(s)
p=r/'app/Jobs/ProcessIncomingWhatsAppMessage.php';s=p.read_text().replace("if ($receipt->state_version && $receipt->state_version !== $version)", "if (($receipt->state_version && $receipt->state_version !== $version) || ($existingMessage && $conversation->messages()->where('direction', 'inbound')->where('id', '>', $message->id)->exists()))");p.write_text(s)
p=r/'app/Console/Commands/ReplayInboundCommand.php';s=p.read_text().replace("if ($receipt->state_version !== $version)", "if ($receipt->state_version !== $version || $conversation->messages()->where('direction', 'inbound')->where('id', '>', $message->id)->exists())");p.write_text(s)
