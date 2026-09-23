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
            str_contains($response->headers->get('Content-Type'), 'text/html')
        ) {
            $content = $response->getContent();

            // The URL for the AI providers
            $url = route('admin.whatsapp.ai-providers.index');
            $isActive = request()->routeIs('admin.whatsapp.ai-providers.*') ? 'is-active' : '';

            $script = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Look for the Integrations & Third-Party panel
    let intPanel = document.querySelector('.v2-panel-content[data-panel="int"] .v2-group-items');
    if (intPanel) {
        // Avoid duplicate injections
        if (!document.getElementById('wa-ai-nav-item')) {
            let link = document.createElement('a');
            link.id = 'wa-ai-nav-item';
            link.className = 'v2-nav-item {$isActive}';
            link.href = '{$url}';
            link.setAttribute('data-id', 'int-wa-ai');
            link.innerHTML = '<span class="v2-dot v2-dot--green"></span><span class="v2-label">WhatsApp AI Providers</span>';
            intPanel.appendChild(link);
        }
    }
});
</script>
HTML;
            // Inject right before the closing body tag
            $content = str_replace('</body>', $script . "\n</body>", $content);
            $response->setContent($content);
        }

        return $response;
    }
}
