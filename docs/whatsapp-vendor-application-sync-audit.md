# WhatsApp Vendor Application & Web Synchronization Audit

**Authoritative Reference**: Web Vendor Application at `https://dashboard.mytijaara.com/vendor/apply` (`app/Http/Controllers/VendorController.php` & `resources/views/vendor-views/auth/general-info.blade.php`).

---

## 1. Actual Canonical Web Vendor Application Fields

The canonical web application collects information across 4 logical sections:

### A. Store / Business Information
1. `name` (`name[default]`): Business/Store Name (multi-language support, default language required)
2. `address` (`address[default]`): Physical Business Address (multi-language support, default language required)
3. `latitude`: Store Latitude coordinate (geocoded/pin-dropped)
4. `longitude`: Store Longitude coordinate (geocoded/pin-dropped)
5. `zone_id`: Operational Zone ID (e.g. Lagos, Abuja)
6. `module_id`: Business Module ID (e.g. Grocery, Pharmacy, eCommerce, Rental, Food)
7. `pickup_zone_id[]`: Pickup Zones (for rental modules)
8. `minimum_delivery_time`: Minimum delivery duration
9. `maximum_delivery_time`: Maximum delivery duration
10. `delivery_time_type`: Delivery unit (`min`, `hours`, `days`)
11. `logo`: Store brand logo (image file)
12. `cover_photo`: Store banner cover photo (image file)

### B. Business Tax & Identity (KYC)
13. `tin`: Taxpayer Identification Number / CAC number
14. `tin_expire_date`: Expiry date of the TIN/document
15. `tin_certificate_image`: Supporting certificate document/image (PDF, DOC, JPG, PNG)

### C. Owner & Account Information
16. `f_name`: Owner First Name
17. `l_name`: Owner Last Name
18. `phone`: Owner Contact Phone number
19. `email`: Account Email address (used for login)
20. `password`: Account Password
21. `confirm-password`: Confirm Password

### D. Business Plan & Legal Acceptance
22. `business_plan`: Choice between `commission-base` and `subscription-base`
23. `package_id`: Selected subscription package ID (if `subscription-base`)
24. `terms_and_conditions`: Acceptance of Terms & Conditions (`route('terms-and-conditions')`)
25. `privacy_policy`: Acceptance of Privacy Policy (`route('privacy-policy')`)

---

## 2. Required Fields on Web
- `f_name` (First name)
- `l_name` (Last name)
- `name[default]` (Business name)
- `address[default]` (Business address)
- `phone` (Vendor phone: min 10 digits, regex pattern, unique:vendors)
- `email` (Vendor email: valid email, unique:vendors)
- `latitude` (Between -90 and 90)
- `longitude` (Between -180 and 180)
- `zone_id` (Exists in zones, must contain latitude/longitude)
- `module_id` (Exists in modules)
- `minimum_delivery_time` & `maximum_delivery_time` & `delivery_time_type`
- `password` (Strong password rules)
- `confirm-password` (Must match password)
- `logo` (Required image file, max 2048KB, 1:1 aspect ratio)
- `businessTerms` (Must accept Terms & Conditions and Privacy Policy)

---

## 3. Optional Fields on Web
- `cover_photo` (Store cover banner: image file, max 2048KB, 2:1 aspect ratio)
- `tin` (Taxpayer Identification Number / CAC registration number)
- `tin_expire_date` (Expiry date of TIN certificate)
- `tin_certificate_image` (Certificate upload: PDF, DOC, JPG, PNG, max 2MB)

---

## 4. Conditional Fields on Web
- `pickup_zone_id[]`: Required only if selected module has `module_type === 'rental'`.
- `package_id`: Required only if `business_plan === 'subscription-base'`.
- `recaptcha` / `custome_recaptcha`: Required for web form bot protection (not applicable to authenticated WhatsApp E2EE sessions).

---

## 5. Validation Rules (Canonical vs WhatsApp)

| Field | Canonical Web Rule | WhatsApp Rule Needed |
|---|---|---|
| `f_name` | `required\|string\|max:100` | Same |
| `l_name` | `required\|string\|max:100` | Same |
| `business_name` | `required\|string\|min:2\|max:250` | Same |
| `phone` | `required\|regex:/^([0-9\s\-\+\(\)]*)$/\|min:10\|unique:vendors` | Same (pre-fills WhatsApp contact number) |
| `email` | `required\|email\|unique:vendors` | Same |
| `latitude` / `longitude` | `required\|numeric` | Same (WhatsApp location pin or geocoded address) |
| `zone_id` | `required\|exists:zones,id` (must contain coordinates) | Same (auto-matched by spatial query or list selection) |
| `module_id` | `required\|exists:modules,id` | Same (selected via interactive list) |
| `delivery_time` | `required` (min, max, unit) | Default to 30-40 min, customizable |
| `password` | `Password::min(8)->mixedCase()->letters()->numbers()->symbols()` | Same rule, hashed immediately |
| `logo` | `required\|image\|max:2048\|mimes:png,jpg,jpeg` | WhatsApp image upload, max 2MB |
| `cover_photo` | `nullable\|image\|max:2048\|mimes:png,jpg,jpeg` | WhatsApp image upload, optional |
| `tin` | `nullable\|string\|max:255` | Optional text input |
| `tin_certificate_image`| `nullable\|file\|max:2048\|mimes:pdf,doc,jpg,png` | Document/Image upload, optional |
| `business_plan` | `in:commission-base,subscription-base` | Interactive buttons (Commission default) |
| `terms` | `accepted` | Interactive button agreement |

---

## 6. File & Image Constraints
- **Logo**: 1:1 square ratio recommended, max 2MB (2048 KB), JPG/PNG/JPEG. Saved to `storage/app/public/store/{filename}`.
- **Cover Photo**: 2:1 landscape ratio recommended, max 2MB (2048 KB), JPG/PNG/JPEG. Saved to `storage/app/public/store/cover/{filename}`.
- **TIN Certificate / KYC Document**: Max 2MB, PDF/DOC/JPG/PNG. Saved to `storage/app/public/store/{filename}`.
- **Storage Disks**: Standard `public` disk via `Helpers::upload()` or direct storage mapping so URLs resolve via `Helpers::get_full_url()`.

---

## 7. Field Dependencies
- **Zone ↔ Coordinates**: The store coordinates (`latitude`, `longitude`) must lie within the selected `zone_id` polygon.
- **Zone ↔ Module**: The selected `module_id` must be active in the assigned `zone_id`.
- **Module ↔ Business Plan**: If `subscription-base` is chosen, packages must match the module type (`all` or `rental`).

---

## 8. Database Mappings

### `vendors` Table:
- `id` (bigint unsigned, auto-increment)
- `f_name` (varchar 100)
- `l_name` (varchar 100)
- `phone` (varchar 20, unique)
- `email` (varchar 100, unique)
- `password` (bcrypt hash)
- `status` (**`null` for pending review**, `0` for denied, `1` for approved)
- `rejection_note` (text, nullable)

### `stores` Table:
- `id` (bigint unsigned, auto-increment)
- `name` (varchar 255)
- `phone` (varchar 20, unique)
- `email` (varchar 100, nullable)
- `logo` (varchar 255, filename in `store/`)
- `cover_photo` (varchar 255, filename in `store/cover/`)
- `latitude` (varchar 255)
- `longitude` (varchar 255)
- `address` (text)
- `zone_id` (bigint unsigned)
- `module_id` (bigint unsigned)
- `status` (`0` pending/inactive until approved, `1` active)
- `delivery_time` (varchar 100, e.g. `'30-40 min'`)
- `store_business_model` (`'commission'`, `'subscription'`, `'none'`)
- `package_id` (bigint unsigned, nullable)
- `tin` (varchar 255, nullable)
- `tin_expire_date` (date, nullable)
- `tin_certificate_image` (varchar 255, filename in `store/`)

---

## 9. Account & Password Behavior
- On Web: The user inputs password and confirm password. Validated against:
  `Password::min(8)->mixedCase()->letters()->numbers()->symbols()`.
  Stored as `bcrypt($password)`.
- On WhatsApp:
  - Previously: Generated random secret `bcrypt(\Str::random(16))`, preventing vendor from ever logging in to the web panel.
  - Fix: Prompt vendor to set their account password during onboarding with clear guidance (8+ chars, uppercase, lowercase, number, symbol).
  - Security: Validated immediately with the same `Password` rule, hashed with `bcrypt()`, never logged in plaintext in messages, events, or debug traces.

---

## 10. Business Plan Behavior
- Web provides two options:
  1. **Commission Base**: Vendor pays standard admin commission (%) per order.
  2. **Subscription Base**: Vendor pays monthly/annual package fee.
- WhatsApp: Provide interactive selection with clear explanation:
  - `[📊 Commission Based]` (Standard commission per sale, zero upfront cost)
  - `[📦 Subscription Plan]` (Select from available active packages)

---

## 11. Zone & Module Behavior
- Web: User selects zone, then module dropdown populates with active modules in that zone.
- WhatsApp:
  - Coordinates from WhatsApp location pin automatically match the containing Zone polygon.
  - If no pin is available, presents an interactive list of active Zones.
  - Module selection: presents an interactive list of active modules (e.g. Grocery, eCommerce, Pharmacy, Food).

---

## 12. Terms & Conditions and Privacy Policy Behavior
- Web: Mandatory checkbox `#businessTerms` linking to `/terms-and-conditions` and `/privacy-policy`.
- WhatsApp: Interactive step with clickable links to the live policy pages:
  - `https://dashboard.mytijaara.com/terms-and-conditions`
  - `https://dashboard.mytijaara.com/privacy-policy`
  - Requires explicit `[✅ I Agree & Continue]` action.

---

## 13. Current KYC Architecture & Recommended Strengthening
- **Current State**: Web application has optional fields `tin`, `tin_expire_date`, and `tin_certificate_image`.
- **Findings**: Small Nigerian merchants often operate as sole proprietors with CAC Business Name registration (BN) or JTB TIN.
- **Recommendation**:
  - Accept either:
    1. **TIN** (Tax Identification Number) + TIN Certificate, OR
    2. **CAC Registration** (RC / BN number) + Certificate of Incorporation / Business Name Registration, OR
    3. **Owner Government ID** (NIN slip, Voter's Card, or Passport).
  - Store document in `store.tin_certificate_image` and registration number in `store.tin`.
  - Display the uploaded document type in the admin pending application viewer.

---

## 14. Current WhatsApp Fields vs. Web Discrepancies

| Field | In Web Form? | In Current WhatsApp? | Status |
|---|---|---|---|
| Business Name | Yes | Yes | Supported |
| Owner First Name | Yes | No (`Business`) | Discrepancy |
| Owner Last Name | Yes | No (`Owner`) | Discrepancy |
| Phone | Yes | Yes (from WhatsApp) | Supported |
| Email | Yes | Yes | Supported |
| Password | Yes (User sets) | No (Randomized) | Discrepancy |
| Store Address | Yes | Yes | Supported |
| Latitude / Longitude | Yes | Yes | Supported |
| Zone ID | Yes | Guessed or defaulted | Needs explicit validation |
| Module ID | Yes | Guessed from category | Needs explicit list selection |
| Delivery Time | Yes | Hardcoded '30-40 min' | Supported (default) |
| Store Logo | Yes (Required) | No (Arbitrary media) | Discrepancy |
| Cover Photo | Yes (Optional) | No | Discrepancy |
| TIN / CAC / Document | Yes | Single generic doc | Needs canonical file persistence |
| Business Plan | Yes | Hardcoded commission | Discrepancy |
| Terms & Conditions | Yes (Mandatory) | No | Discrepancy |

---

## 15. Root Cause of Unexpected "DENIED" Application Status

### Trace & Verification:
1. In `app/Http/Controllers/Admin/VendorController.php`:
   - `pending_requests()`:
     ```php
     $stores = Store::whereHas('vendor', function ($query) {
         return $query->where('status', null); // <-- PENDING IS NULL
     });
     ```
   - `deny_requests()`:
     ```php
     $stores = Store::whereHas('vendor', function ($query) {
         return $query->where('status', 0); // <-- DENIED IS 0
     });
     ```
   - `list()` / active stores:
     ```php
     $stores = Store::whereHas('vendor', function ($query) {
         return $query->where('status', 1); // <-- APPROVED IS 1
     });
     ```
2. In `app/Http/Controllers/VendorController.php` (web registration, line 143):
   ```php
   $vendor->status = null; // Correctly sets null for pending review
   ```
3. In `Modules/WhatsAppVendorConcierge/app/Services/VendorOnboardingService.php` (line 566):
   ```php
   $vendor = Vendor::create([
       ...
       'status' => 0, // <-- BUG! Developer wrote 0 thinking it meant "pending", but 0 is DENIED!
   ]);
   ```
### Conclusion:
The application was never rejected by a business rule, automated worker, or database constraint. It appeared in the "Denied Stores" admin tab solely because `Vendor::create()` initialized `status = 0` instead of `status = null`.

---

## 16. Notification & Status Synchronization Architecture

### Admin Application Actions:
In `app/Http/Controllers/Admin/VendorController.php`:
- `updateVendorApplication(Request $request)`:
  - When `$request->status == 1`: Application **Approved**.
  - When `$request->status == 0`: Application **Denied** (with optional `rejection_note`).
- `status(Store $store, Request $request)`:
  - When `$request->status == 0`: Store **Suspended**.
  - When `$request->status == 1`: Store **Re-activated / Unsuspended**.

### Synchronization Mechanism:
We insert a clean hook into `updateVendorApplication()` and `status()` wrapped in `// GEMINI-MYTJ START: ...` markers. When triggered, it dispatches `SendVendorStatusNotification` which:
1. Looks up the vendor and store.
2. Identifies the vendor's registered `WhatsAppContact`.
3. Sends a real-time WhatsApp notification with clear next steps and portal login links.

---

## 17. Core-System Changes Required
1. `app/Http/Controllers/Admin/VendorController.php`:
   - Add status change notification dispatch in `updateVendorApplication()` for Approval / Denial.
   - Add status change notification dispatch in `status()` for Suspension / Reactivation.
   - Guard with `// GEMINI-MYTJ START` and `// GEMINI-MYTJ END` markers.

---

## 18. WhatsApp Module Changes Required
1. `VendorOnboardingService.php`:
   - Set `$vendor->status = null` on creation so the application lands in **Pending Requests**.
   - Expand onboarding flow to collect:
     - Owner Name (`f_name`, `l_name`)
     - Business Name & Category/Module selection
     - Location (Pin / Address / Zone verification)
     - Account Email & Secure Password
     - Store Logo (required image, saved canonically to `store/`)
     - Business Plan selection (`commission-base` vs `subscription-base`)
     - KYC Document / TIN (saved canonically to `store/`)
     - Terms & Conditions acceptance
   - Save all media canonically to `storage/app/public/store/` and `storage/app/public/store/cover/`.
2. `ConversationManager.php`:
   - Support new step prompts, edit mappings, and plan selections.
3. `OnboardingSession.php`:
   - Update step sequence to include the full canonical steps.
4. `SendVendorStatusNotification.php`:
   - New Job to dispatch WhatsApp approval/denial/suspension notifications asynchronously.
