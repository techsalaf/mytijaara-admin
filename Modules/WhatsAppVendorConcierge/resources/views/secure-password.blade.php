<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Set Secure Password - MyTijaara Vendor Concierge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #008a3d;
            --primary-dark: #006b2f;
            --primary-light: #e8f5ed;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --bg-card: #ffffff;
            --bg-page: #f8fafc;
            --danger: #ef4444;
            --danger-light: #fef2f2;
            --success: #10b981;
            --success-light: #ecfdf5;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        body {
            background-color: var(--bg-page);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .container {
            width: 100%;
            max-width: 440px;
        }

        .brand-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .brand-logo-badge {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #008a3d 0%, #10b981 100%);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px -5px rgba(0, 138, 61, 0.3);
            margin-bottom: 12px;
        }

        .brand-logo-badge svg {
            width: 32px;
            height: 32px;
            color: white;
        }

        .brand-title {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: -0.02em;
        }

        .brand-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .card {
            background: var(--bg-card);
            border-radius: 20px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.03);
            border: 1px solid var(--border);
            padding: 28px 24px;
        }

        .card-header {
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .card-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
            line-height: 1.5;
        }

        .vendor-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-light);
            border: 1px solid rgba(0, 138, 61, 0.15);
            padding: 8px 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 12px;
            color: var(--primary-dark);
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 6px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .form-input {
            width: 100%;
            padding: 12px 42px 12px 14px;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-dark);
            transition: all 0.2s ease;
            background: #fff;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 138, 61, 0.12);
        }

        .toggle-pwd {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
        }

        .toggle-pwd:hover {
            color: var(--text-dark);
        }

        .checklist {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 20px;
        }

        .checklist-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 8px;
        }

        .check-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 6px;
            transition: color 0.2s ease;
        }

        .check-item:last-child {
            margin-bottom: 0;
        }

        .check-item.valid {
            color: var(--success);
            font-weight: 600;
        }

        .check-icon {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease;
            flex-shrink: 0;
        }

        .check-item.valid .check-icon {
            background: var(--success);
        }

        .check-icon svg {
            width: 9px;
            height: 9px;
            color: white;
            display: none;
        }

        .check-item.valid .check-icon svg {
            display: block;
        }

        .btn-submit {
            width: 100%;
            background: var(--primary);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0, 138, 61, 0.25);
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(0, 138, 61, 0.3);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .error-banner {
            background: var(--danger-light);
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 13px;
            margin-bottom: 18px;
            line-height: 1.4;
        }

        .state-card {
            text-align: center;
            padding: 36px 20px;
        }

        .state-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
        }

        .state-icon.expired {
            background: var(--danger-light);
            color: var(--danger);
        }

        .state-icon.success {
            background: var(--success-light);
            color: var(--success);
        }

        .state-title {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .state-desc {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .btn-whatsapp {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #25D366;
            color: white;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
            transition: all 0.2s ease;
        }

        .btn-whatsapp:hover {
            background: #1ebc59;
            transform: translateY(-1px);
        }

        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 20px;
            font-size: 12px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="brand-header">
        <div class="brand-logo-badge">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
        </div>
        <h1 class="brand-title">MyTijaara Security</h1>
        <p class="brand-sub">Vendor Credential Setup</p>
    </div>

    @if (!empty($expired))
        <!-- Expired / Used Link State -->
        <div class="card state-card">
            <div class="state-icon expired">
                <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h2 class="state-title">Link Expired or Used</h2>
            <p class="state-desc">
                For your security, this password creation link is single-use and valid for only 15 minutes. It has either expired or was already completed.
            </p>
            <p class="state-desc" style="font-size: 13px;">
                Please return to your WhatsApp conversation with MyTijaara and reply <strong>"Resend Link"</strong> or <strong>"Edit Password"</strong> to generate a fresh link.
            </p>
            <a href="https://api.whatsapp.com" class="btn-whatsapp">
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-5.46-4.45-9.92-9.91-9.92z"/>
                </svg>
                Return to WhatsApp
            </a>
        </div>
    @elseif (!empty($success))
        <!-- Successful Setup State -->
        <div class="card state-card">
            <div class="state-icon success">
                <svg width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h2 class="state-title">Password Created! 🎉</h2>
            <p class="state-desc">
                Your vendor dashboard password has been securely encrypted and saved. Your WhatsApp onboarding session has automatically resumed.
            </p>
            <a href="https://api.whatsapp.com" class="btn-whatsapp">
                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-5.46-4.45-9.92-9.92z"/>
                </svg>
                Continue in WhatsApp
            </a>
        </div>
    @else
        <!-- Password Setup Form -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Create Dashboard Password</h2>
                <p class="card-desc">Set credentials to access your vendor administration dashboard at <strong>dashboard.mytijaara.com</strong>.</p>
            </div>

            <div class="vendor-pill">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <span>{{ $businessName }} &bull; {{ $email ?: 'Vendor Account' }}</span>
            </div>

            @if ($errors->any())
                <div class="error-banner">
                    @foreach ($errors->all() as $error)
                        <div>&bull; {{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('whatsapp.onboarding.password.store', ['token' => $token], false) }}" id="passwordForm">
                @csrf

                <div class="form-group">
                    <label class="form-label" for="password">New Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" class="form-input" required autocomplete="new-password" placeholder="Enter secure password">
                        <button type="button" class="toggle-pwd" onclick="toggleVisibility('password', this)" title="Toggle password visibility">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password_confirmation">Confirm Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="password_confirmation" id="password_confirmation" class="form-input" required autocomplete="new-password" placeholder="Confirm your password">
                        <button type="button" class="toggle-pwd" onclick="toggleVisibility('password_confirmation', this)" title="Toggle password visibility">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="checklist">
                    <div class="checklist-title">Password Security Requirements</div>
                    <div class="check-item" id="req-len">
                        <span class="check-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></span>
                        <span>At least 8 characters</span>
                    </div>
                    <div class="check-item" id="req-case">
                        <span class="check-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></span>
                        <span>Uppercase & lowercase letters</span>
                    </div>
                    <div class="check-item" id="req-num">
                        <span class="check-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></span>
                        <span>At least one number (0-9)</span>
                    </div>
                    <div class="check-item" id="req-sym">
                        <span class="check-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></span>
                        <span>At least one special symbol (!@#$%^&*)</span>
                    </div>
                    <div class="check-item" id="req-match">
                        <span class="check-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></span>
                        <span>Passwords match</span>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn" disabled>
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <span>Save Secure Password</span>
                </button>
            </form>
        </div>

        <div class="security-badge">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            <span>256-Bit Encrypted &bull; Never Shared with WhatsApp</span>
        </div>

        <script>
            function toggleVisibility(inputId, btn) {
                const input = document.getElementById(inputId);
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                btn.style.color = isPassword ? 'var(--primary)' : 'var(--text-muted)';
            }

            const pwd = document.getElementById('password');
            const confirm = document.getElementById('password_confirmation');
            const submitBtn = document.getElementById('submitBtn');

            function validate() {
                const val = pwd.value;
                const conf = confirm.value;

                const hasLen = val.length >= 8;
                const hasCase = /[a-z]/.test(val) && /[A-Z]/.test(val);
                const hasNum = /\d/.test(val);
                const hasSym = /[^A-Za-z0-9]/.test(val);
                const matches = val.length > 0 && val === conf;

                setItemState('req-len', hasLen);
                setItemState('req-case', hasCase);
                setItemState('req-num', hasNum);
                setItemState('req-sym', hasSym);
                setItemState('req-match', matches);

                submitBtn.disabled = !(hasLen && hasCase && hasNum && hasSym && matches);
            }

            function setItemState(id, valid) {
                const el = document.getElementById(id);
                if (valid) {
                    el.classList.add('valid');
                } else {
                    el.classList.remove('valid');
                }
            }

            pwd.addEventListener('input', validate);
            confirm.addEventListener('input', validate);

            document.getElementById('passwordForm').addEventListener('submit', function () {
                submitBtn.disabled = true;
                submitBtn.querySelector('span').textContent = 'Encrypting & Saving...';
            });
        </script>
    @endif
</div>

</body>
</html>
