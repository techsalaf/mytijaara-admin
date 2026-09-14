# WhatsApp Vendor Meta template register

These templates are required for status updates after the vendor's 24-hour service window expires. The registration contracts below were verified by reading the production WABA's Meta template catalogue on 2026-09-14. The earlier three-parameter approval/four-parameter denial table was incorrect.

| Event | Configured template name | Verified Meta status / language | Sender body parameters |
|---|---|---|---|
| Approved | `vendor_application_approved` | APPROVED / `en` | `{{1}}` store name |
| Denied | `vendor_application_denied` | APPROVED / `en` | `{{1}}` vendor first name, `{{2}}` safe rejection reason |
| Suspended | `vendor_store_suspended` | Missing from production WABA | Expected: `{{1}}` vendor name, `{{2}}` store name, `{{3}}` support URL |
| Reactivated | `vendor_store_reactivated` | Missing from production WABA | Expected: `{{1}}` vendor name, `{{2}}` store name, `{{3}}` dashboard URL |

The sender uses text inside the 24-hour service window and these body parameters outside it. The approved registration templates have no dynamic header or URL-button parameters. Static footers and quick-reply buttons do not require extra body parameters.

For the verified production registration templates, use:

```dotenv
WHATSAPP_TEMPLATE_VENDOR_APPROVED=vendor_application_approved
WHATSAPP_TEMPLATE_VENDOR_DENIED=vendor_application_denied
WHATSAPP_TEMPLATE_VENDOR_APPROVED_LOCALE=en
WHATSAPP_TEMPLATE_VENDOR_DENIED_LOCALE=en
```

Event-specific locale settings override `WHATSAPP_TEMPLATE_LOCALE`. The fallback locale is `en`; every configured locale must match the actual approved translation exactly. `en_US` is a different translation and does not match these registration templates. Deploy the matching sender parameter correction along with the locale correction, refresh Laravel configuration, and restart queue workers.

Run this read-only check after configuration/template changes:

```sh
php artisan whatsapp:check-templates --event=approved --event=denied
php artisan whatsapp:check-templates
```

The command queries Meta without sending messages or changing vendor status. It exits nonzero for missing translations, unapproved templates, mismatched body parameters, or unsupported dynamic components. The full check will fail until the missing suspension/reactivation templates are approved and configured to match their sender contracts. Passing the registration-only check does not establish readiness of all notification types or prove delivery to a vendor.

Do not toggle vendor status or replay historical approval notifications to test configuration. Inspect the current application status and delivery records first; a historical approval may no longer reflect the current decision.
