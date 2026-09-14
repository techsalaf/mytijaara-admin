# WhatsApp Vendor Meta template register

These templates are required for status updates after the vendor's 24-hour service window expires. They are mapped with environment variables; do not change a name after production approval without updating the matching variable and rerunning configuration cache.

| Environment variable | Proposed template name | Category | Parameters | Trigger |
|---|---|---|---|---|
| `WHATSAPP_TEMPLATE_VENDOR_APPROVED` | `vendor_application_approved` | Utility | `{{1}}` vendor name, `{{2}}` store name, `{{3}}` dashboard URL | Admin approves an application |
| `WHATSAPP_TEMPLATE_VENDOR_DENIED` | `vendor_application_denied` | Utility | `{{1}}` vendor name, `{{2}}` store name, `{{3}}` safe rejection summary, `{{4}}` support URL | Admin denies an application |
| `WHATSAPP_TEMPLATE_VENDOR_SUSPENDED` | `vendor_store_suspended` | Utility | `{{1}}` vendor name, `{{2}}` store name, `{{3}}` support URL | Admin suspends a store |
| `WHATSAPP_TEMPLATE_VENDOR_UNSUSPENDED` | `vendor_store_reactivated` | Utility | `{{1}}` vendor name, `{{2}}` store name, `{{3}}` dashboard URL | Admin reactivates a store |

The current code uses text only inside the 24-hour service window and sends the listed body parameters outside it. Set `WHATSAPP_TEMPLATE_LOCALE` (or an event-specific `WHATSAPP_TEMPLATE_VENDOR_*_LOCALE`) to the exact language code shown for the approved template in WhatsApp Manager, commonly `en_US`. A template name and parameter count must match this table exactly; changing either in WhatsApp Manager requires a matching deployment and `php artisan config:cache`.
