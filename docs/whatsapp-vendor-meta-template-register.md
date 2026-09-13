# WhatsApp Vendor Meta template register

These templates are required for status updates after the vendor's 24-hour service window expires. They are mapped with environment variables; do not change a name after production approval without updating the matching variable and rerunning configuration cache.

| Environment variable | Proposed template name | Category | Parameters | Trigger |
|---|---|---|---|---|
| `WHATSAPP_TEMPLATE_VENDOR_APPROVED` | `vendor_application_approved` | Utility | vendor name, store name, dashboard URL | Admin approves an application |
| `WHATSAPP_TEMPLATE_VENDOR_DENIED` | `vendor_application_denied` | Utility | vendor name, store name, safe rejection summary, support URL | Admin denies an application |
| `WHATSAPP_TEMPLATE_VENDOR_SUSPENDED` | `vendor_store_suspended` | Utility | vendor name, store name, support URL | Admin suspends a store |
| `WHATSAPP_TEMPLATE_VENDOR_UNSUSPENDED` | `vendor_store_reactivated` | Utility | vendor name, store name, dashboard URL | Admin reactivates a store |

The current code uses text only inside the 24-hour service window and selects the mapped template outside it. Template components must be added to `SendVendorStatusNotification` before templates with variables are approved; until then approve parameter-free wording or keep these notifications disabled. Template approval status must be checked in Meta before enabling production status notifications.
