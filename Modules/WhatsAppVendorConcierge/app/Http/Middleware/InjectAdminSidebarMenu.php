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
        if (document.getElementById('wa-ai-nav-item')) return;

        // Try targeting AI Configuration link directly
        const aiLink = document.querySelector('a[data-id="int-ai"]');
        let targetContainer = null;

        if (aiLink && aiLink.parentElement) {
            targetContainer = aiLink.parentElement;
        } else {
            targetContainer = document.querySelector('.v2-panel-content[data-panel="int"] .v2-group-items')
                || document.querySelector('[data-panel="int"] .v2-group-items');
        }

        if (targetContainer && !document.getElementById('wa-ai-nav-item')) {
            const link = document.createElement('a');
            link.id = 'wa-ai-nav-item';
            link.className = 'v2-nav-item {$isActive}';
            link.href = '{$url}';
            link.setAttribute('data-id', 'int-wa-ai');
            link.innerHTML = '<span class="v2-dot v2-dot--green"></span><span class="v2-label">WhatsApp AI Providers</span>';
            
            if (aiLink && aiLink.nextSibling) {
                targetContainer.insertBefore(link, aiLink.nextSibling);
            } else {
                targetContainer.appendChild(link);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectWhatsAppNav);
    } else {
        injectWhatsAppNav();
    }

    // Backup polling in case sidebar loads asynchronously or via PJAX/Livewire
    let attempts = 0;
    const interval = setInterval(function() {
        attempts++;
        injectWhatsAppNav();
        if (document.getElementById('wa-ai-nav-item') || attempts > 20) {
            clearInterval(interval);
        }
    }, 250);
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
