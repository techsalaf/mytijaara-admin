@extends('layouts.admin.app')

@section('title', translate('WhatsApp Inbox'))

@push('css_or_js')
<style>
    .wa-container { height: calc(100vh - 160px); display: flex; background: #efeae2; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .wa-sidebar { width: 350px; background: #ffffff; display: flex; flex-direction: column; border-right: 1px solid #d1d7db; }
    .wa-sidebar-header { padding: 10px 16px; background: #f0f2f5; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #d1d7db; }
    .wa-search { padding: 8px 12px; background: #ffffff; border-bottom: 1px solid #d1d7db; }
    .wa-search input { background: #f0f2f5; border: none; border-radius: 8px; padding: 6px 12px; width: 100%; font-size: 14px; }
    .wa-filters { padding: 8px 12px; display: flex; gap: 8px; overflow-x: auto; background: #ffffff; border-bottom: 1px solid #d1d7db; scrollbar-width: none; }
    .wa-filters::-webkit-scrollbar { display: none; }
    .wa-filter-btn { padding: 6px 12px; border-radius: 16px; background: #f0f2f5; border: none; font-size: 13px; cursor: pointer; white-space: nowrap; color: #54656f; }
    .wa-filter-btn.active { background: #008069; color: #ffffff; }
    .wa-chat-list { flex-grow: 1; overflow-y: auto; background: #ffffff; }
    .wa-chat-item { display: flex; padding: 12px 16px; cursor: pointer; border-bottom: 1px solid #f2f2f2; text-decoration: none !important; color: inherit; }
    .wa-chat-item:hover { background: #f5f6f6; }
    .wa-chat-item.active { background: #f0f2f5; }
    .wa-avatar { width: 48px; height: 48px; border-radius: 50%; background: #dfe5e7; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff; margin-right: 15px; flex-shrink: 0; }
    .wa-chat-info { flex-grow: 1; overflow: hidden; }
    .wa-chat-title { display: flex; justify-content: space-between; margin-bottom: 4px; }
    .wa-chat-name { font-size: 16px; font-weight: 500; color: #111b21; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .wa-chat-time { font-size: 12px; color: #667781; }
    .wa-chat-preview { display: flex; justify-content: space-between; align-items: center; }
    .wa-chat-last-msg { font-size: 14px; color: #667781; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .wa-main { flex-grow: 1; display: flex; flex-direction: column; position: relative; background: #f4f7f6; }
    .wa-chat-header { padding: 10px 16px; background: #f0f2f5; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #d1d7db; }
    .wa-chat-messages { flex-grow: 1; overflow-y: auto; padding: 20px 5%; display: flex; flex-direction: column; }
    .wa-message { max-width: 65%; margin-bottom: 12px; padding: 8px 12px; border-radius: 8px; font-size: 14.5px; line-height: 1.4; position: relative; display: flex; flex-direction: column; box-shadow: 0 1px 1px rgba(0,0,0,0.1); }
    .wa-message.inbound { align-self: flex-start; background: #ffffff; border-top-left-radius: 0; }
    .wa-message.outbound { align-self: flex-end; background: #d9fdd3; border-top-right-radius: 0; }
    .wa-msg-meta { display: flex; justify-content: flex-end; align-items: center; margin-top: 4px; gap: 4px; }
    .wa-msg-time { font-size: 11px; color: #667781; }
    .wa-msg-origin { font-size: 10px; padding: 2px 4px; border-radius: 4px; background: rgba(0,0,0,0.05); color: #667781; }
    .wa-msg-status { font-size: 14px; }
    .wa-msg-status.read { color: #53bdeb; }
    .wa-msg-status.delivered { color: #667781; }
    .wa-msg-status.sent { color: #667781; }
    .wa-msg-status.failed { color: #f15c6d; }

    .wa-composer { padding: 12px 16px; background: #f0f2f5; display: flex; align-items: flex-end; gap: 12px; }
    .wa-composer-input { flex-grow: 1; background: #ffffff; border-radius: 8px; padding: 10px 16px; border: none; outline: none; resize: none; max-height: 100px; overflow-y: auto; font-size: 15px; }
    .wa-btn-send { background: #00a884; color: white; border: none; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
    .wa-btn-send:disabled { background: #879297; cursor: not-allowed; }
    .wa-btn-action { background: none; border: none; color: #54656f; font-size: 24px; cursor: pointer; padding: 4px; }

    .wa-empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #667781; text-align: center; }
    .wa-empty-state i { font-size: 64px; margin-bottom: 20px; opacity: 0.5; }

    .wa-badge { font-size: 10px; padding: 2px 6px; border-radius: 10px; color: white; font-weight: bold; }
    .wa-badge-unread { background: #25d366; }
    .wa-badge-ai { background: #8e24aa; }
    .wa-badge-human { background: #ff9800; }
    .wa-badge-paused { background: #f44336; }

    .wa-container{min-height:520px;height:calc(100dvh - 155px)}.wa-sidebar{flex:0 0 310px;min-width:0}.wa-main,.wa-chat-info{min-width:0}.wa-main,.wa-chat-messages,.wa-chat-list{min-height:0}.wa-message{overflow-wrap:anywhere;max-width:85%}.wa-chat-header{gap:12px;flex-wrap:wrap}.wa-chat-header .d-flex{flex-wrap:wrap;gap:6px}.wa-avatar{color:#31564c;background:#e0eee9}.wa-chat-time{flex-shrink:0;margin-left:8px}.wa-composer-input{min-width:0}.wa-status-banner{padding:12px 18px;background:#fff;border-bottom:1px solid #e3e8e5;font-size:13px}.wa-mobile-back{display:none}.wa-search input:focus,.wa-composer-input:focus{outline:2px solid #008069;outline-offset:2px}
    @media(max-width:1000px){.wa-sidebar{flex-basis:260px}.wa-message{max-width:92%}}
    @media(max-width:700px){.wa-container{height:calc(100dvh - 130px);min-height:440px}.wa-sidebar{flex-basis:100%;width:100%}.wa-main{display:none}.wa-container.wa-open .wa-sidebar{display:none}.wa-container.wa-open .wa-main{display:flex;width:100%}.wa-mobile-back{display:inline-block}.wa-chat-header{padding:10px}.wa-chat-messages{padding:12px}.wa-composer{padding:10px;gap:6px}}
</style>
@endpush

@section('content')
<div class="content container-fluid">
    <div class="wa-container wa-open">
        <!-- Sidebar -->
        <div class="wa-sidebar">
            <div class="wa-sidebar-header">
                <h4 class="mb-0">{{ translate('Chats') }}</h4>
            </div>

            <form class="wa-search" action="{{ url()->current() }}" method="GET">
                <input type="hidden" name="filter" value="{{ request('filter', 'all') }}">
                <input type="text" name="search" placeholder="{{ translate('Search name or phone') }}" value="{{ request('search') }}">
            </form>

            <div class="wa-filters">
                @php $currentFilter = request('filter', 'all'); @endphp
                <a href="{{ request()->fullUrlWithQuery(['filter' => 'all']) }}" class="wa-filter-btn {{ $currentFilter == 'all' ? 'active' : '' }}">All</a>
                <a href="{{ request()->fullUrlWithQuery(['filter' => 'unread']) }}" class="wa-filter-btn {{ $currentFilter == 'unread' ? 'active' : '' }}">Unread</a>
                <a href="{{ request()->fullUrlWithQuery(['filter' => 'ai']) }}" class="wa-filter-btn {{ $currentFilter == 'ai' ? 'active' : '' }}">AI Active</a>
                <a href="{{ request()->fullUrlWithQuery(['filter' => 'human']) }}" class="wa-filter-btn {{ $currentFilter == 'human' ? 'active' : '' }}">Human</a>
                <a href="{{ request()->fullUrlWithQuery(['filter' => 'stuck']) }}" class="wa-filter-btn {{ $currentFilter == 'stuck' ? 'active' : '' }}">Stuck</a>
                <a href="{{ request()->fullUrlWithQuery(['filter' => 'incomplete']) }}" class="wa-filter-btn {{ $currentFilter == 'incomplete' ? 'active' : '' }}">Incomplete</a>
            </div>

            <div class="wa-chat-list">
                @forelse($conversations as $conv)
                    <a href="{{ route('admin.whatsapp.inbox.show', array_merge(['conversation' => $conv->id], request()->query())) }}" class="wa-chat-item {{ isset($conversation) && $conversation->id == $conv->id ? 'active' : '' }}">
                        <div class="wa-avatar">
                            <i class="tio-user"></i>
                        </div>
                        <div class="wa-chat-info">
                            <div class="wa-chat-title">
                                <span class="wa-chat-name">{{ $conv->contact->display_name ?? $conv->contact->phone_number }}</span>
                                <span class="wa-chat-time">{{ $conv->last_activity_at ? $conv->last_activity_at->format('H:i') : '' }}</span>
                            </div>
                            <div class="wa-chat-preview">
                                <span class="wa-chat-last-msg">
                                    @if($conv->messages->isNotEmpty())
                                        {{ Str::limit($conv->messages->first()->raw_text ?? 'Media message', 30) }}
                                    @else
                                        No messages
                                    @endif
                                </span>
                                <div class="d-flex align-items-center gap-1">
                                    @if($conv->state === 'human_handoff')
                                        <span class="wa-badge wa-badge-human">Human</span>
                                    @elseif($conv->state === 'onboarding_paused')
                                        <span class="wa-badge wa-badge-paused">Stuck</span>
                                    @else
                                        <span class="wa-badge wa-badge-ai">AI</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="p-4 text-center text-muted">
                        <small>No conversations found.</small>
                    </div>
                @endforelse

                <div class="p-3">
                    {{ $conversations->appends(request()->all())->links('pagination::bootstrap-4') }}
                </div>
            </div>
        </div>

        <!-- Main Chat Area -->
        <div class="wa-main">
            @if(isset($conversation))
                <div class="wa-chat-header"><a class="wa-mobile-back btn btn-sm btn-outline-secondary" href="{{ route('admin.whatsapp.inbox.index') }}">Back to chats</a>
                    <div class="d-flex align-items-center">
                        <div class="wa-avatar" style="width:40px; height:40px; margin-right:12px;">
                            <i class="tio-user"></i>
                        </div>
                        <div>
                            <div style="font-size: 16px; font-weight: 500; color: #111b21;">{{ $conversation->contact->display_name ?? $conversation->contact->phone_number }}</div>
                            <div style="font-size: 13px; color: #667781;">
                                {{ $conversation->vendor ? ($conversation->vendor->f_name . ' ' . $conversation->vendor->l_name) : 'Application in progress' }}
                                &bull; {{ Str::title(str_replace('_', ' ', $conversation->state)) }}
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <form action="{{ route('admin.whatsapp.inbox.toggle-state', $conversation->id) }}" method="POST" class="d-inline">
                            @csrf
                            @if($conversation->state === 'human_handoff')
                                <input type="hidden" name="state" value="ai_active">
                                <button type="submit" class="btn btn-sm btn-outline-success"><i class="tio-robot"></i> Resume automation</button>
                            @else
                                <input type="hidden" name="state" value="human_handoff">
                                <button type="submit" class="btn btn-sm btn-outline-warning"><i class="tio-user"></i> Take Over</button>
                            @endif
                        </form>
                        @php
                            $storeId = $conversation->vendor?->stores?->first()?->id ?? $conversation->onboardingSession?->store_id ?? null;
                        @endphp
                        @if($storeId)
                            <a href="{{ route('admin.store.view', ['store' => $storeId]) }}" class="btn btn-sm btn-outline-info" title="{{ translate('View Store Application') }}" target="_blank">
                                <i class="tio-folder-bookmarked"></i>
                            </a>
                        @endif
                        <a href="{{ route('admin.whatsapp.operations-center.show', $conversation->id) }}" class="btn btn-sm btn-outline-secondary" title="{{ translate('View Diagnostics & Recovery') }}">
                            <i class="tio-settings"></i>
                        </a>
                    </div>
                </div>

                <div class="wa-status-banner"><strong>{{ $diag['step_label'] }}</strong> · {{ $diag['human_diagnosis'] }}<br><span class="text-muted">{{ $diag['service_window_open'] ? 'Reply window open' : 'Reply window closed: use an approved template' }} · {{ $conversation->state === 'human_handoff' ? 'You are in human support mode.' : 'Take over before sending a human reply.' }}</span></div>
                <div class="wa-chat-messages" id="chat-messages">
                    @foreach($messages as $msg)
                        <div class="wa-message {{ $msg->direction === 'inbound' ? 'inbound' : 'outbound' }}">
                            @if($msg->raw_text)
                                <div>{!! nl2br(e($msg->raw_text)) !!}</div>
                            @else
                                <div><i class="tio-attachment"></i> [{{ ucfirst(str_replace('_', ' ', $msg->type)) }}]</div>
                            @endif

                            <div class="wa-msg-meta">
                                @if($msg->direction === 'outbound')
                                    @php
                                        $origin = $msg->metadata['origin'] ?? null;
                                        if (!$origin) {
                                            $origin = !empty($msg->metadata['response']['messages']) ? 'ai/system' : 'unknown';
                                        }
                                    @endphp
                                    <span class="wa-msg-origin">{{ Str::title(str_replace('_', ' ', $origin)) }}</span>
                                @endif
                                <span class="wa-msg-time">{{ $msg->created_at->format('M j, H:i') }}</span>
                                @if($msg->direction === 'outbound')
                                    <span class="wa-msg-status {{ $msg->status }}">
                                        @if($msg->status === 'read')
                                            <i class="tio-done-all"></i>
                                        @elseif($msg->status === 'delivered')
                                            <i class="tio-done-all"></i>
                                        @elseif($msg->status === 'sent')
                                            <i class="tio-done"></i>
                                        @elseif($msg->status === 'failed')
                                            <i class="tio-error" title="{{ json_encode($msg->error) }}"></i>
                                        @else
                                            <i class="tio-time"></i>
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="wa-composer">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.whatsapp.resume-campaigns.index') }}" title="Approved resume templates">Templates</a>
                    <textarea id="messageText" class="wa-composer-input" placeholder="{{ $conversation->state === 'human_handoff' ? 'Type a message' : 'Take over to send a message' }}" rows="1" {{ ($conversation->state !== 'human_handoff' || !$diag['service_window_open']) ? 'disabled' : '' }}></textarea>
                    <button id="btnSend" class="wa-btn-send" {{ ($conversation->state !== 'human_handoff' || !$diag['service_window_open']) ? 'disabled' : '' }}>
                        <i class="tio-send"></i>
                    </button>
                </div>
            @else
                <div class="wa-empty-state">
                    <i class="tio-chat-outlined"></i>
                    <h3>WhatsApp Web</h3>
                    <p>Send and receive messages without keeping your phone online.<br>Select a conversation from the left to start.</p>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('script_2')
<script>
    const messagesContainer = document.getElementById('chat-messages');
    if (messagesContainer) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    @if(isset($conversation) && $conversation->state === 'human_handoff')
    const textarea = document.getElementById('messageText');
    const btnSend = document.getElementById('btnSend');

    // Auto-resize textarea
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    const escapeText = text => { const element=document.createElement('div'); element.textContent=text; return element.innerHTML; };
    let sending = false;
    const sendMessage = () => {
        const text = textarea.value.trim();
        if (!text) return;

        sending = true;
        textarea.disabled = true;
        btnSend.disabled = true;

        fetch('{{ route('admin.whatsapp.inbox.send', $conversation->id) }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ message: text })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const html = `
                    <div class="wa-message outbound">
                        <div>${escapeText(text).replace(/\n/g, '<br>')}</div>
                        <div class="wa-msg-meta">
                            <span class="wa-msg-origin">Human Operator</span>
                            <span class="wa-msg-time">${data.message.created_at}</span>
                            <span class="wa-msg-status ${data.message.status}"><i class="tio-done"></i></span>
                        </div>
                    </div>
                `;
                messagesContainer.insertAdjacentHTML('beforeend', html);
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
                textarea.value = '';
                textarea.style.height = 'auto';
            } else {
                toastr.error(data.message || 'Failed to send message');
            }
        })
        .catch(e => toastr.error('An error occurred'))
        .finally(() => {
            sending = false;
            textarea.disabled = @json($conversation->state !== 'human_handoff' || !$diag['service_window_open']);
            btnSend.disabled = textarea.disabled;
            textarea.focus();
        });
    };

    btnSend.addEventListener('click', sendMessage);

    textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Real-time polling
    setInterval(function() {
        if(document.hidden || sending || (textarea && textarea.value.trim().length > 0)) return; // Don't interrupt while typing
        fetch(window.location.href, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newMessages = doc.getElementById('chat-messages');
            if (newMessages && newMessages.innerHTML !== messagesContainer.innerHTML) {
                const nearBottom = messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 80;
                messagesContainer.innerHTML = newMessages.innerHTML;
                if (nearBottom) messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }).catch(()=>{});
    }, 10000); // 10 seconds
    @endif
</script>
@endpush
