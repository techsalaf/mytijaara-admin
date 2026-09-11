MASTER PROMPT — BUILD MYTIJAARA WHATSAPP VENDOR CONCIERGE
You are acting as a Principal Software Architect + Senior Laravel Engineer + WhatsApp Business Platform Engineer + AI Agent Engineer working directly on the MyTijaara codebase.
You have access to the actual MyTijaara Laravel backend on my local machine and its GitHub repository. You must use the existing codebase as the source of truth for understanding the current architecture, database, vendor system, authentication, APIs, admin workflows, queues, notifications, and existing business logic.
Your job is to design and implement the complete MyTijaara WhatsApp Vendor Onboarding and Vendor Concierge system, integrating it properly into the existing MyTijaara backend rather than creating a parallel or disconnected system.
1. PRODUCT VISION
We want to transform WhatsApp into a major entry point for MyTijaara vendors.
The goal is NOT to build a simple WhatsApp chatbot or WhatsApp form.
The goal is:
“MyTijaara on WhatsApp.”
A prospective vendor should be able to discover MyTijaara, register their business, submit their application, receive onboarding guidance, upload relevant information/documents, track their application, and eventually manage meaningful parts of their MyTijaara shop through WhatsApp.
The existing vendor dashboard must continue to work.
WhatsApp and the dashboard must operate against the same underlying MyTijaara backend, services, business rules and database.
The desired high-level architecture is:

```

```


```
                    VENDOR
                       │
              ┌────────┴────────┐
              │                 │
              ▼                 ▼
          WhatsApp          Vendor Dashboard
              │                 │
              ▼                 ▼
       WhatsApp Gateway     Existing Web App
              │                 │
              └────────┬────────┘
                       ▼
                MYTIJAARA LARAVEL
                       │
              ┌────────┼─────────┐
              │        │         │
              ▼        ▼         ▼
           Vendors    Shops    Products
              │
              ▼
             MySQL
```

WhatsApp should be an interface, not a second backend.
2. VERY IMPORTANT — AUDIT BEFORE CODING
Before writing or modifying code, thoroughly inspect the existing project.
Do NOT assume the architecture.
Do NOT blindly implement the architecture described in this prompt if the existing application already has an equivalent mechanism.
First inspect:
Laravel architecture

*  Laravel version 
*  PHP version 
*  project structure 
*  service classes 
*  repositories 
*  controllers 
*  jobs 
*  events/listeners 
*  notifications 
*  queues 
*  scheduled jobs 
*  policies 
*  middleware 
*  authentication 
*  API architecture 
*  existing integrations 
*  environment/configuration structure 

Vendor architecture
Find and understand:

*  Vendor model 
*  Vendor registration 
*  Vendor application 
*  Vendor authentication 
*  Vendor approval 
*  Shop model 
*  Shop creation 
*  Vendor status 
*  Shop status 
*  vendor categories 
*  vendor locations 
*  vendor documents 
*  existing vendor onboarding 
*  existing vendor dashboard APIs 
*  existing admin approval workflow 

Pay particular attention to the existing vendor application page:

```

```


```
https://dashboard.mytijaara.com/vendor/apply
```

Determine exactly how this currently works.
The WhatsApp onboarding should ultimately feed into the same application/registration workflow wherever practical.
Database
Inspect all relevant migrations and models.
Do not create duplicate tables or fields if existing structures can be reused.
Document:

```

```


```
Existing entity
Existing table
Existing relationships
Existing workflow
Can WhatsApp reuse it?
Required modification
```

Existing APIs
Inspect all existing APIs related to:

*  vendors 
*  shops 
*  products 
*  categories 
*  locations 
*  orders 
*  authentication 
*  documents 
*  notifications 

Reuse existing business logic where possible.
3. PRODUCE AN AUDIT REPORT FIRST
Before implementation, create a document such as:

```

```


```
docs/whatsapp-vendor-architecture-audit.md
```

It should explain:

1.  Existing architecture 
2.  Existing vendor onboarding architecture 
3.  Existing database entities 
4.  Existing vendor APIs/services 
5.  Existing authentication 
6.  Existing queue infrastructure 
7.  Existing notification infrastructure 
8.  Existing admin approval workflow 
9.  What can be reused 
10.  What needs to be created 
11.  What needs to be refactored 
12.  Potential architectural conflicts 
13.  Recommended implementation architecture 
14.  Risks 
15.  External dependencies 
16.  Environment variables required 

Do not start making large architectural changes until you have completed this audit.
4. TARGET SYSTEM
After auditing the existing codebase, implement the following system where appropriate.
A. WhatsApp Cloud API
Integrate the official:
Meta WhatsApp Business Platform / WhatsApp Cloud API
The system should support:

*  receiving messages 
*  sending messages 
*  interactive messages 
*  buttons 
*  lists 
*  media 
*  images 
*  documents 
*  locations 
*  templates where required 
*  WhatsApp Flows 
*  webhook events 
*  delivery/read status where useful 

Use Meta's current API conventions.
Do not rely on obsolete WhatsApp API documentation or deprecated endpoints.
If the implementation depends on a Meta feature/version that needs confirmation, identify it clearly rather than guessing.
5. WEBHOOK SYSTEM
Implement a production-grade WhatsApp webhook.
For example:

```

```


```
GET  /api/webhooks/whatsapp
POST /api/webhooks/whatsapp
```

But use routes consistent with the existing application's architecture.
The webhook must handle:
Verification

*  Meta webhook verification 
*  secure verification token 
*  signature validation where applicable 

Incoming events
Handle at minimum:

*  text messages 
*  button responses 
*  list responses 
*  Flow responses 
*  images 
*  documents 
*  location messages 
*  interactive responses 
*  message status events 

Important architecture rule
The webhook should acknowledge Meta quickly.
Do NOT perform expensive AI processing synchronously inside the webhook request.
Prefer:

```

```


```
Meta
 ↓
Webhook
 ↓
Validate
 ↓
Persist event/message
 ↓
Queue Job
 ↓
Process asynchronously
```

6. WHATSAPP DATABASE ARCHITECTURE
Inspect the existing database first.
If these concepts do not already exist, introduce appropriate models/tables for things such as:

```

```


```
whatsapp_contacts
whatsapp_conversations
whatsapp_messages
whatsapp_media
whatsapp_flows
onboarding_sessions
onboarding_events
```

Do not blindly use these exact table names if the existing naming conventions suggest otherwise.
The system should be able to track:
Contact

```

```


```
WhatsApp number
WhatsApp user ID
MyTijaara user ID
Vendor ID
first interaction
last interaction
```

Conversation

```

```


```
contact
vendor
conversation state
current intent
last interaction
context
```

Messages

```

```


```
WhatsApp message ID
direction
type
content
media
status
timestamp
metadata
```

Onboarding session
Track:

```

```


```
session
vendor/application
current step
status
started_at
updated_at
completed_at
expires_at
flow version
metadata
```

Onboarding events
Track important events such as:

```

```


```
onboarding_started
business_name_submitted
category_selected
location_shared
contact_submitted
documents_uploaded
review_started
application_submitted
application_approved
application_rejected
onboarding_abandoned
```

This event data should eventually allow us to analyse onboarding conversion.
7. VENDOR ONBOARDING EXPERIENCE
Build the onboarding experience around this philosophy:
Progressive onboarding.
Do NOT ask a new vendor for 30 pieces of information at once.
Collect the minimum information necessary to establish the application first.
Then collect additional information progressively.
8. INITIAL WHATSAPP EXPERIENCE
When a new person contacts MyTijaara:

```

```


```
Assalaamu Alaikum 👋

Welcome to MyTijaara.

What would you like to do?
```

Provide appropriate interactive options such as:

```

```


```
🛍️ Open My Shop

🏪 Manage My Shop

ℹ️ Learn About Selling

👨‍💬 Talk to Support
```

The exact copy can be improved by you if you find a better UX.
9. EXISTING ACCOUNT DETECTION
Before creating a new vendor account/application:
Use the WhatsApp number to determine whether the person already exists.
Potential states:

```

```


```
New person
Existing customer
Existing vendor
Existing vendor applicant
Rejected applicant
Incomplete onboarding
```

Do NOT create duplicate accounts.
If an existing application exists, resume it where appropriate.
For example:
“You already started your MyTijaara vendor application. Would you like to continue where you stopped?”
10. WHATSAPP FLOW
Use WhatsApp Flows for structured information where appropriate.
The onboarding Flow should ideally capture:
Screen 1 — Business Basics

*  Business name 
*  Business description/type 

Screen 2 — Category
Use MyTijaara's actual category structure.
Do NOT invent categories if the database already contains them.
Screen 3 — Location
Capture business location.
Where possible, allow:

```

```


```
📍 Share Location
```

Use the location to determine:

*  coordinates 
*  city 
*  area 
*  zone 
*  service availability 

This is particularly important because MyTijaara will operate city-by-city.
Screen 4 — Contact
Use the WhatsApp number automatically when appropriate.
Only ask for information that is missing.
Screen 5 — Operating information
Capture relevant:

*  opening days 
*  opening time 
*  closing time 
*  pickup/delivery availability 

Screen 6 — Documents
Support relevant document uploads according to the actual MyTijaara verification requirements.
Screen 7 — Review
Show a summary.
Allow:

```

```


```
Edit
Submit
Cancel
```

11. APPLICATION SUBMISSION
When the vendor submits:
The system must use the existing MyTijaara vendor application/business logic wherever possible.
Do NOT create a separate WhatsApp-only vendor database.
The final architecture should be approximately:

```

```


```
WhatsApp
 ↓
WhatsApp Gateway
 ↓
VendorOnboardingService
 ↓
Existing Vendor Application Logic
 ↓
Database
 ↓
Admin Panel
```

If the existing architecture is not sufficiently reusable, refactor it into a reusable service rather than duplicating the logic.
12. ADMIN APPROVAL
The existing MyTijaara admin panel should remain the authority for vendor verification.
The admin should be able to see:

```

```


```
Vendor
Business
Category
Location
Phone
Documents
Application source
Application status
Submission time
Onboarding progress
```

Where useful, show:

```

```


```
Source: WhatsApp
```

The admin should be able to:

```

```


```
Approve
Reject
Request correction
```

If the current admin panel already supports these actions, integrate with the existing workflow rather than duplicating it.
13. WHATSAPP APPROVAL EXPERIENCE
When approved, the vendor should receive an appropriate WhatsApp notification.
For example:

```

```


```
🎉 Your MyTijaara shop has been approved!

Welcome aboard.

Your next step is to set up your shop.

What would you like to do?
```

Options:

```

```


```
➕ Add Products
🏪 Set Up Shop
📸 Add Photos
🚀 Go Live
```

14. AI VENDOR CONCIERGE
After the basic onboarding system works, implement the AI layer.
The AI should not directly access the database.
Use a tool/function architecture:

```

```


```
WhatsApp
 ↓
AI Agent
 ↓
Validated MyTijaara Tools
 ↓
Laravel Services
 ↓
Database
```

The AI should be able to understand natural-language vendor requests.
Examples:
“I want to sell on MyTijaara.”
“How do I open my shop?”
“Where is my application?”
“How many orders do I have today?”
“How much did I sell today?”
“Add chicken shawarma.”
“Change shawarma price to 5,000.”
“Pause my shop.”
“Open my shop again.”
“I want to talk to support.”
15. AI TOOL LAYER
Create controlled tools/functions based on the actual MyTijaara backend.
Potential tools include:

```

```


```
start_vendor_onboarding
get_onboarding_status
get_vendor_profile
update_vendor_profile
get_vendor_categories
get_vendor_locations
submit_vendor_application
upload_vendor_document
get_vendor_products
create_product_draft
create_product
update_product
get_vendor_orders
get_vendor_sales
get_shop_status
pause_shop
resume_shop
contact_support
```

But inspect the existing system first and create only the tools that make sense.
Every tool must:

*  validate input 
*  authorize the vendor 
*  verify ownership 
*  enforce business rules 
*  log important actions 
*  return structured results 

16. CRITICAL AI SAFETY RULE
Never allow:

```

```


```
AI → raw SQL → database
```

Instead:

```

```


```
AI
 ↓
Tool
 ↓
Laravel Service
 ↓
Authorization
 ↓
Validation
 ↓
Business Rules
 ↓
Database
```

For destructive or important write actions, require explicit vendor confirmation.
For example:
Vendor:
Change my shawarma price to ₦5,000.
Assistant:
I found Chicken Shawarma, currently ₦4,500.
Change it to ₦5,000?
Buttons:

```

```


```
✅ Confirm
❌ Cancel
```

Only then execute the tool.
17. PRODUCT CREATION VIA AI
Build toward an experience where a vendor can send:

```

```


```
[PRODUCT PHOTO]
```

The AI can identify/extract:

```

```


```
product name
category
description
possible variants
```

Then ask:
“What is the selling price?”
Vendor:
₦4,500
Assistant:
Here's the listing I prepared:

```

```


```
Chicken Shawarma
₦4,500

Grilled chicken shawarma...
```

Then:

```

```


```
✅ Add Product
✏️ Edit
❌ Cancel
```

The AI should create the product only after confirmation.
If product images need to be stored, integrate with the existing MyTijaara storage/media system.
18. VENDOR MANAGEMENT VIA WHATSAPP
Build the architecture so vendors can eventually do things like:
Orders
“How many orders do I have today?”
Sales
“How much did I make today?”
Products
“Show me my products.”
“Change the price of my burger to ₦3,500.”
Shop
“Pause my shop until tomorrow.”
Support
“I have a problem with an order.”
These capabilities can be phased in if implementing everything at once would create unnecessary risk.
19. AUTHENTICATION AND SECURITY
Design a proper mechanism for associating a WhatsApp number with a MyTijaara vendor account.
Do not assume that possession of a phone number alone is sufficient for every sensitive action.
Consider:

*  WhatsApp identity 
*  vendor ID 
*  one-time verification where necessary 
*  session expiration 
*  action confirmation 
*  authorization 
*  audit logs 

Never expose:

*  passwords 
*  API keys 
*  internal tokens 
*  sensitive database information 

in WhatsApp conversations.
20. DOCUMENT HANDLING
For vendor documents:

```

```


```
WhatsApp
 ↓
Media API
 ↓
Secure download
 ↓
MyTijaara storage
 ↓
Vendor document
 ↓
Verification
```

Do not permanently store unnecessary temporary Meta media files.
Implement appropriate cleanup.
If AI/OCR is used:

```

```


```
Document
 ↓
OCR / extraction
 ↓
Structured information
 ↓
Validation
 ↓
Human review
```

AI must assist verification rather than automatically making high-risk verification decisions unless the existing business rules explicitly permit it.
21. QUEUES
Use Laravel queues for expensive operations.
At minimum consider separate jobs for:

```

```


```
ProcessIncomingWhatsAppMessage
ProcessWhatsAppMedia
ProcessVendorDocument
RunVendorAIConversation
SendWhatsAppMessage
ProcessVendorOnboarding
```

Use the existing queue infrastructure if present.
Do not introduce another queue technology unnecessarily.
22. OBSERVABILITY
Implement strong logging.
We need to be able to answer:

```

```


```
What happened to this WhatsApp message?

What did the AI decide?

What tool did it call?

What vendor account was involved?

What database action occurred?

Why did the action fail?

```

Use structured logs where appropriate.
Avoid logging sensitive information unnecessarily.
23. IDEMPOTENCY
This is very important.
WhatsApp/webhooks can produce duplicate events.
Make message/event processing idempotent using Meta's message/event IDs.
We must not accidentally:

*  create duplicate vendors 
*  create duplicate applications 
*  create duplicate products 
*  execute a write action twice 

because a webhook was delivered twice.
24. ERROR HANDLING
Design graceful recovery.
Examples:
WhatsApp API unavailable
Don't lose the message.
AI unavailable
Fallback to a deterministic conversational flow.
Flow submission fails
Allow retry.
Vendor abandons onboarding
Allow them to resume later.
Document upload fails
Allow re-upload.
Unknown message
Respond gracefully:
“I’m not sure I understood that. Here are some things I can help you with…”
25. HUMAN HANDOFF
The vendor must always be able to reach a human.
Example:
“Talk to support”
should create/route an appropriate support request using the existing MyTijaara support infrastructure if available.
If no suitable infrastructure exists, design a minimal one that can later integrate with the admin panel.
26. ANALYTICS
Build the architecture so we can measure:

```

```


```
WhatsApp conversations
Onboarding started
Onboarding completed
Applications submitted
Applications approved
Applications rejected
Average onboarding duration
Drop-off by step
Vendor acquisition source
AI conversations
Human handoffs
Product creation via WhatsApp
Vendor activity via WhatsApp
```

Especially:

```

```


```
Started → Submitted → Approved → Activated
```

We should eventually be able to calculate:

```

```


```
WhatsApp Vendor Conversion Rate
```

27. FIELD AGENT / MARKETING INTEGRATION
Design support for acquisition links/QR codes.
For example:

```

```


```
Scan QR
 ↓
WhatsApp
 ↓
"I want to sell on MyTijaara."
```

Track acquisition source where possible:

```

```


```
source
campaign
agent
referral
QR identifier
```

Do not overengineer this if the existing system has no campaign/referral architecture; document what should be added later.
28. ENVIRONMENT VARIABLES
Identify every external configuration requirement.
Create/update appropriate:

```

```


```
.env.example
```

Potential configuration may include:

```

```


```
WHATSAPP_ACCESS_TOKEN
WHATSAPP_PHONE_NUMBER_ID
WHATSAPP_BUSINESS_ACCOUNT_ID
WHATSAPP_APP_ID
WHATSAPP_APP_SECRET
WHATSAPP_VERIFY_TOKEN
WHATSAPP_API_VERSION
```

Only add variables that are actually required.
Do not hard-code secrets.
29. META SETUP DOCUMENTATION
Create:

```

```


```
docs/whatsapp-meta-setup.md
```

Explain exactly what I need to configure in Meta.
Include:

1.  Meta Developer App 
2.  WhatsApp product/use case 
3.  Business Portfolio 
4.  WhatsApp Business Account 
5.  Phone number 
6.  Access token 
7.  System user if required 
8.  Required permissions 
9.  Webhook URL 
10.  Webhook verification 
11.  Webhook subscriptions 
12.  WhatsApp Flow configuration 
13.  Template configuration 
14.  Production verification requirements 
15.  App mode requirements 
16.  Anything else required before going live 

Clearly separate:

```

```


```
Developer setup
Business setup
Code setup
Production setup
```

30. EXTERNAL SERVICES / API KEYS
Create:

```

```


```
docs/external-services.md
```

List every external service the implementation needs.
For each:

```

```


```
Service
Purpose
Why needed
Account required?
API key required?
Where to obtain it
Free/paid
Production considerations
```

Do NOT assume I already have any service configured.
31. AI PROVIDER
Inspect the project to determine whether there is already an AI provider/integration.
If one exists, evaluate whether it should be reused.
If none exists, recommend the most appropriate architecture.
The AI provider should support:

*  tool/function calling 
*  structured output 
*  conversation handling 
*  image understanding where needed 
*  reliable latency 
*  production API access 

Do not unnecessarily introduce multiple AI providers.
32. CONVERSATION ENGINE
Do not rely entirely on raw LLM conversation history.
Implement explicit application state.
For example:

```

```


```
conversation_state
onboarding_state
current_intent
pending_action
pending_confirmation
```

The AI should understand the state, but Laravel remains authoritative.
Example:

```

```


```
state = awaiting_business_name
```

If the vendor says:
“Rasheed Foods”
the system knows this is a business name even if the AI has minor uncertainty.
33. MULTILINGUAL READINESS
Do not necessarily implement all languages now, but architect the system so it can eventually support:

*  English 
*  Nigerian English 
*  Yoruba 

Do not hard-code conversational text throughout business logic.
Centralize messages/templates where practical.
34. TESTING
Write proper tests.
At minimum:
Unit tests
For:

*  onboarding state transitions 
*  vendor matching 
*  authorization 
*  tool validation 
*  business rules 

Feature tests
For:

*  webhook verification 
*  incoming WhatsApp message 
*  onboarding submission 
*  vendor application 
*  approval 
*  AI tool execution 

Idempotency tests
Ensure duplicate webhooks don't create duplicate records/actions.
Failure tests
Test:

*  invalid WhatsApp payload 
*  invalid vendor 
*  expired session 
*  duplicate message 
*  failed media download 
*  failed AI response 
*  failed WhatsApp API response 

Mock external APIs appropriately.
Do not make tests depend on live Meta or AI API calls.
35. DOCUMENTATION
Create a comprehensive implementation document:

```

```


```
docs/whatsapp-vendor-concierge.md
```

It should contain:
Architecture
Data flow
Webhook flow
AI architecture
Vendor onboarding flow
Database changes
API endpoints
Laravel services
Queue jobs
WhatsApp Flows
Security
Environment variables
Meta setup
Deployment
Testing
Troubleshooting
Future roadmap
36. IMPLEMENTATION PRIORITY
Do NOT try to build every advanced AI capability before the core onboarding works.
Implement in this order:
Phase 1

```

```


```
Meta configuration
 ↓
Webhook
 ↓
WhatsApp messaging
 ↓
Contact identification
 ↓
Conversation persistence
```

Phase 2

```

```


```
WhatsApp Flow
 ↓
Vendor registration
 ↓
Existing vendor application system
 ↓
Admin review
 ↓
Approval notification
```

Phase 3

```

```


```
AI Concierge
 ↓
Natural-language understanding
 ↓
Controlled tools
 ↓
Vendor support
```

Phase 4

```

```


```
AI Product Creation
 ↓
Product management
 ↓
Orders
 ↓
Sales
 ↓
Shop management
```

Phase 5

```

```


```
Analytics
 ↓
Field agent attribution
 ↓
Optimization
 ↓
Advanced automation
```

37. DO NOT BREAK EXISTING MYTIJAARA
This is one of the most important requirements.
Before modifying existing code:

*  understand its purpose 
*  understand its dependencies 
*  understand production implications 

Do not casually rewrite existing vendor functionality.
Do not break:

*  vendor dashboard 
*  vendor authentication 
*  admin panel 
*  existing applications 
*  existing product management 
*  order management 
*  existing APIs 

Prefer:

```

```


```
reuse
→ extend
→ refactor carefully
→ create new
```

in that order.
38. GIT / CHANGE MANAGEMENT
Don't work in a separate or dedicated branch. Commit directly to main:

```

git status
git branch
```

Do not overwrite unrelated work.
Keep commits logical.
Suggested commits:

```

```


```
feat: add whatsapp webhook infrastructure

feat: add whatsapp conversation persistence

feat: add vendor onboarding flow

feat: integrate vendor application workflow

feat: add whatsapp ai concierge

feat: add vendor product tools

test: add whatsapp integration tests

docs: add whatsapp deployment documentation
```

39. IMPORTANT: DO NOT ASK ME FOR INFORMATION YOU CAN DISCOVER YOURSELF
You have access to the actual codebase and repository.
Before asking me anything:

1.  Search the repository. 
2.  Inspect the relevant code. 
3.  Inspect `.env.example`. 
4.  Inspect migrations. 
5.  Inspect routes. 
6.  Inspect services. 
7.  Inspect existing integrations. 
8.  Inspect documentation. 
9.  Inspect Git history where useful. 

Only ask me when something genuinely requires an external decision, credential, business rule, or account-level configuration that cannot be discovered from the repository.
For example:

```

```


```
Meta credentials
WhatsApp phone number
final verification requirements
support phone
business policy
AI provider preference
```

40. IF YOU DISCOVER SOMETHING BETTER
You are not required to blindly follow this specification.
If your audit reveals a better, simpler, safer or more maintainable architecture, propose it.
But:

1.  Explain what you discovered. 
2.  Explain why the architecture should change. 
3.  Explain the trade-offs. 
4.  Explain what existing functionality will be reused. 
5.  Then implement the improved architecture. 

Do not introduce complexity merely because it is technically possible.
41. FINAL DELIVERABLES
At the end, I expect:
Code
Fully implemented production-ready WhatsApp Vendor Concierge infrastructure.
Database
All required migrations/models/relationships.
WhatsApp
Cloud API integration.
Webhook.
Messaging.
Media.
Flows.
AI
Agent orchestration.
Tools.
State management.
Confirmation mechanisms.
Vendor onboarding
Complete registration → application → admin approval → activation flow.
Tests
Comprehensive automated tests.
Documentation
At minimum:

```

```


```
docs/whatsapp-vendor-architecture-audit.md
docs/whatsapp-vendor-concierge.md
docs/whatsapp-meta-setup.md
docs/external-services.md
docs/whatsapp-deployment.md
```

Configuration
Updated:

```

```


```
.env.example
```

and any required config files.
42. VERY IMPORTANT — GIVE ME A SETUP CHECKLIST
When implementation is complete, create:

```

```


```
docs/whatsapp-go-live-checklist.md
```

This must tell me exactly what I personally need to do outside the codebase.
Break it into:
A. Meta
What I need to configure.
B. WhatsApp Business
What I need to configure.
C. Phone number
What I need to configure.
D. API credentials
What I need to generate.
E. AI
What account/API key I need.
F. Server
What environment variables/configuration I need.
G. Domain
What DNS/SSL/webhook requirements exist.
H. Laravel
What commands I need to run.
For example:

```

```


```
php artisan migrate
php artisan queue:restart
php artisan config:cache
php artisan route:cache
```

Only include commands actually required.
I. WhatsApp Flow
Exactly how I create/publish/configure the Flow.
J. Testing
Exactly how I test:

```

```


```
New vendor
Existing vendor
Incomplete application
Rejected vendor
Approved vendor
Document upload
Location
AI
Product creation
Human support
```

K. Production launch
Exact final steps before going live.
43. ALSO TELL ME WHAT I DON'T KNOW YET
This is extremely important.
At the end of the project, provide a section:
“Things Rasheed Needs To Know Before Going Live”
Identify anything that I may not have considered.
Examples could include:

*  Meta Business verification 
*  WhatsApp messaging restrictions 
*  template approval 
*  conversation windows 
*  WhatsApp pricing 
*  media limitations 
*  Flow limitations 
*  rate limits 
*  webhook reliability 
*  AI costs 
*  token usage 
*  document storage 
*  Nigerian data protection/privacy requirements 
*  vendor consent 
*  data retention 
*  security 
*  production monitoring 
*  backup 
*  queue workers 
*  failure recovery 
*  human escalation 
*  abuse prevention 
*  spam prevention 
*  account suspension 
*  WhatsApp number quality/rating 
*  Meta policy considerations 

Do not simply list generic concerns.
Research/verify the things that depend on current Meta capabilities and clearly distinguish:

```

```


```
Confirmed requirement
Recommended practice
Potential consideration
```

44. FINAL ARCHITECTURAL PRINCIPLE
The end state should be:

```

```


```
                         MYTIJAARA
                              │
               ┌──────────────┴──────────────┐
               │                             │
          CUSTOMER SIDE                 VENDOR SIDE
               │                             │
        Mobile / Website             ┌────────┴────────┐
                                     │                 │
                                  WhatsApp          Dashboard
                                     │                 │
                                     └────────┬────────┘
                                              │
                                       Laravel Backend
                                              │
                  ┌───────────────────────────┼──────────────────────────┐
                  │                           │                          │
               Vendors                     Shops                     Products
                  │                           │                          │
                  └───────────────────────────┼──────────────────────────┘
                                              │
                                             DB
```

And for WhatsApp specifically:

```

```


```
Vendor
  ↓
WhatsApp
  ↓
Meta Cloud API
  ↓
Webhook
  ↓
MyTijaara WhatsApp Gateway
  ↓
Conversation / State Engine
  ↓
AI Agent when necessary
  ↓
Controlled MyTijaara Tools
  ↓
Laravel Services
  ↓
Validation + Authorization
  ↓
MyTijaara Database
  ↓
Admin / Vendor Dashboard
```

The AI is the concierge.
 WhatsApp is the interface.
 Laravel is the brain/system of record.
 The database is the source of truth.
 The admin remains the authority for verification.
Now begin by auditing the existing MyTijaara Laravel project and repository.
Do not start by writing code.
First show me the architecture you discovered, what already exists, what can be reused, what needs to change, and your final implementation plan.
Then proceed to implementation phase-by-phase, keeping the existing MyTijaara platform stable throughout.