@extends('layouts.admin.app')

@section('title', translate('WhatsApp Inbox'))

@push('css_or_js')
<style>
    .chat-container { height: calc(100vh - 200px); display: flex; }
    .chat-list { width: 30%; border-right: 1px solid #e7eaf3; overflow-y: auto; }
    .chat-area { width: 70%; display: flex; flex-direction: column; }
    .chat-messages { flex-grow: 1; overflow-y: auto; padding: 1rem; background-color: #f8f9fa; }
    .chat-input { border-top: 1px solid #e7eaf3; padding: 1rem; background: #fff; }
    .chat-item { padding: 15px; border-bottom: 1px solid #e7eaf3; cursor: pointer; transition: 0.3s; }
    .chat-item:hover, .chat-item.active { background-color: #f8f9fa; }
    .msg-bubble { max-width: 75%; padding: 10px 15px; border-radius: 15px; margin-bottom: 10px; font-size: 14px; position: relative; }
    .msg-in { background-color: #fff; border: 1px solid #e7eaf3; align-self: flex-start; border-bottom-left-radius: 0; }
    .msg-out { background-color: #007bff; color: #fff; align-self: flex-end; border-bottom-right-radius: 0; }
    .msg-time { font-size: 10px; color: #999; margin-top: 5px; text-align: right; }
    .msg-out .msg-time { color: #d0e8ff; }
</style>
@endpush

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon"><i class="tio-chat-outlined"></i></span>
            {{translate('WhatsApp Inbox')}}
        </h1>
    </div>

    <div class="card">
        <div class="chat-container">
            <!-- Sidebar / Chat List -->
            <div class="chat-list">
                @forelse($conversations as $conv)
                    <a href="{{ route('admin.whatsapp.inbox.show', $conv->id) }}" class="chat-item d-flex align-items-center {{ isset($conversation) && $conversation->id == $conv->id ? 'active' : '' }}" style="text-decoration: none; color: inherit;">
                        <div class="avatar avatar-sm avatar-circle mr-3">
                            <img class="avatar-img" src="{{ asset('public/assets/admin/img/160x160/img1.jpg') }}" alt="Image Description">
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <h5 class="mb-0">{{ $conv->vendor->f_name ?? 'Unknown Vendor' }} {{ $conv->vendor->l_name ?? '' }}</h5>
                                <small class="text-muted">{{ $conv->last_activity_at ? $conv->last_activity_at->format('H:i') : '' }}</small>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-truncate text-muted" style="max-width: 150px; font-size:12px;">{{ $conv->contact->phone_number }}</span>
                                @if($conv->state === 'human_handoff')
                                    <span class="badge badge-soft-warning badge-pill">Handoff</span>
                                @else
                                    <span class="badge badge-soft-success badge-pill">AI Active</span>
                                @endif
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="p-4 text-center text-muted">No conversations found.</div>
                @endforelse
            </div>

            <!-- Chat Area -->
            <div class="chat-area">
                @if(isset($conversation))
                    <!-- Chat Header -->
                    <div class="d-flex justify-content-between align-items-center p-3 border-bottom bg-white">
                        <div class="d-flex align-items-center">
                            <div class="avatar avatar-sm avatar-circle mr-3">
                                <img class="avatar-img" src="{{ asset('public/assets/admin/img/160x160/img1.jpg') }}" alt="Avatar">
                            </div>
                            <div>
                                <h5 class="mb-0">{{ $conversation->vendor->f_name ?? 'Unknown' }} {{ $conversation->vendor->l_name ?? '' }}</h5>
                                <small class="text-muted">{{ $conversation->contact->phone_number }}</small>
                            </div>
                        </div>
                        <div>
                            <form action="{{ route('admin.whatsapp.inbox.toggle-state', $conversation->id) }}" method="POST">
                                @csrf
                                @if($conversation->state === 'human_handoff')
                                    <input type="hidden" name="state" value="ai_active">
                                    <button type="submit" class="btn btn-sm btn-success"><i class="tio-robot"></i> Handback to AI</button>
                                @else
                                    <input type="hidden" name="state" value="human_handoff">
                                    <button type="submit" class="btn btn-sm btn-warning"><i class="tio-user"></i> Take Over (Mute AI)</button>
                                @endif
                            </form>
                        </div>
                    </div>

                    <!-- Messages -->
                    <div class="chat-messages d-flex flex-column" id="chat-messages">
                        @foreach($messages as $msg)
                            @if($msg->direction === 'inbound')
                                <div class="msg-bubble msg-in">
                                    {{ $msg->raw_text }}
                                    <div class="msg-time">{{ $msg->created_at->format('H:i') }}</div>
                                </div>
                            @else
                                <div class="msg-bubble msg-out">
                                    {{ $msg->raw_text }}
                                    <div class="msg-time">
                                        {{ $msg->created_at->format('H:i') }}
                                        @if($msg->status === 'read')
                                            <i class="tio-done-all text-white ml-1"></i>
                                        @elseif($msg->status === 'delivered')
                                            <i class="tio-done-all text-light ml-1"></i>
                                        @elseif($msg->status === 'sent')
                                            <i class="tio-done text-light ml-1"></i>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <!-- Input -->
                    <div class="chat-input">
                        <form id="sendMessageForm">
                            <div class="input-group">
                                <input type="text" id="messageText" class="form-control" placeholder="Type a message..." {{ $conversation->state !== 'human_handoff' ? 'disabled' : '' }}>
                                <div class="input-group-append">
                                    <button class="btn btn-primary" type="submit" {{ $conversation->state !== 'human_handoff' ? 'disabled' : '' }}>
                                        <i class="tio-send"></i> Send
                                    </button>
                                </div>
                            </div>
                            @if($conversation->state !== 'human_handoff')
                                <small class="text-muted mt-1 d-block"><i class="tio-info-outined"></i> You must take over the conversation before sending a manual message.</small>
                            @endif
                        </form>
                    </div>
                @else
                    <div class="d-flex justify-content-center align-items-center h-100 text-muted">
                        Select a conversation to start messaging
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('script_2')
<script>
    // Scroll to bottom
    const messagesContainer = document.getElementById('chat-messages');
    if (messagesContainer) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    @if(isset($conversation) && $conversation->state === 'human_handoff')
    document.getElementById('sendMessageForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const textInput = document.getElementById('messageText');
        const text = textInput.value.trim();
        if (!text) return;

        textInput.disabled = true;

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
                // simple optimistic append
                const html = `
                    <div class="msg-bubble msg-out">
                        ${text}
                        <div class="msg-time">Just now</div>
                    </div>
                `;
                messagesContainer.insertAdjacentHTML('beforeend', html);
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
                textInput.value = '';
            } else {
                toastr.error(data.message || 'Failed to send message');
            }
        })
        .catch(e => toastr.error('An error occurred'))
        .finally(() => {
            textInput.disabled = false;
            textInput.focus();
        });
    });
    @endif
</script>
@endpush
