<?php

namespace Modules\WhatsAppVendorConcierge\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InjectAdminSidebarMenu
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only inject into HTML responses in the admin panel
        if (
            $request->is('admin*') &&
            $response instanceof \Illuminate\Http\Response &&
            str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')
        ) {
            $content = $response->getContent();

            try {
                $url = route('admin.whatsapp.ai-providers.index');
            } catch (\Throwable $e) {
                $url = url('/admin/whatsapp/ai-providers');
            }
            $isActive = request()->is('admin/whatsapp/ai-providers*') ? 'is-active' : '';

            $script = <<<HTML
<script>
(function() {
    function injectWhatsAppNav() {
        const url = '{$url}';
        const isActive = '{$isActive}';

        // 1. Integrations panel
        if (!document.getElementById('wa-ai-nav-item')) {
            const aiLink = document.querySelector('a[data-id="int-ai"]');
            const link = document.createElement('a');
            link.id = 'wa-ai-nav-item';
            link.className = 'v2-nav-item ' + (isActive ? 'is-active' : '');
            link.href = url;
            link.setAttribute('data-id', 'int-wa-ai');
            link.innerHTML = '<span class="v2-dot v2-dot--green"></span><span class="v2-label">WhatsApp AI Providers</span>';

            if (aiLink) {
                aiLink.after(link);
            } else {
                const targetContainer = document.querySelector('.v2-panel-content[data-panel="int"] .v2-group-items')
                    || document.querySelector('[data-panel="int"] .v2-group-items');
                if (targetContainer) targetContainer.appendChild(link);
            }
        }

        // 2. Vendors panel
        if (!document.getElementById('wa-vendor-nav-item')) {
            const vendorContainer = document.querySelector('.v2-panel-content[data-panel="vendors"] .v2-group-items')
                || document.querySelector('[data-panel="vendors"] .v2-group-items');
            if (vendorContainer) {
                const vlink = document.createElement('a');
                vlink.id = 'wa-vendor-nav-item';
                vlink.className = 'v2-nav-item ' + (isActive ? 'is-active' : '');
                vlink.href = url;
                vlink.innerHTML = '<span class="v2-dot v2-dot--green"></span><span class="v2-label">WhatsApp Concierge AI</span>';
                vendorContainer.appendChild(vlink);
            }
        }

        // 3. Left Rail
        if (!document.getElementById('wa-rail-btn')) {
            const rail = document.querySelector('#v2-rail .v2-rail-btns') || document.querySelector('.v2-rail-btns');
            if (rail) {
                const rbtn = document.createElement('a');
                rbtn.id = 'wa-rail-btn';
                rbtn.className = 'v2-rail-btn ' + (isActive ? 'is-active' : '');
                rbtn.href = url;
                rbtn.title = 'WhatsApp AI Providers';
                rbtn.setAttribute('aria-label', 'WhatsApp AI Providers');
                rbtn.style.display = 'inline-flex';
                rbtn.style.alignItems = 'center';
                rbtn.style.justifyContent = 'center';
                rbtn.style.textDecoration = 'none';
                rbtn.innerHTML = '<span style="font-size: 1.25rem; line-height: 1;">🤖</span><span class="v2-pin-dot"></span>';
                rail.appendChild(rbtn);
            }
        }

        // 4. Top Header Workspace Tabs
        if (!document.getElementById('wa-top-tab')) {
            const tabs = document.querySelector('.v2-workspace-tabs') || document.querySelector('.navbar-nav');
            if (tabs) {
                const tab = document.createElement('a');
                tab.id = 'wa-top-tab';
                tab.className = 'v2-workspace-tab ' + (isActive ? 'is-active' : '');
                tab.href = url;
                tab.style.display = 'inline-flex';
                tab.style.alignItems = 'center';
                tab.style.gap = '4px';
                tab.style.textDecoration = 'none';
                tab.innerHTML = '<span style="font-size:14px;">🤖</span><span>WhatsApp AI</span>';
                tabs.appendChild(tab);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectWhatsAppNav);
    } else {
        injectWhatsAppNav();
    }

    let attempts = 0;
    const interval = setInterval(function() {
        attempts++;
        injectWhatsAppNav();
        if (attempts > 30) clearInterval(interval);
    }, 250);

    try {
        const observer = new MutationObserver(function() {
            injectWhatsAppNav();
        });
        const shell = document.getElementById('v2-shell') || document.body;
        if (shell) observer.observe(shell, { childList: true, subtree: true });
    } catch (e) {}
})();
</script>
HTML;
            if (str_contains($content, '</body>')) {
                $content = str_replace('</body>', $script . "\n</body>", $content);
                $response->setContent($content);
            }
        }

        return $response;
    }
}
