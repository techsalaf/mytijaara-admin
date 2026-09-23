# Chat navigation and first-contact prompts

Navigation runs before onboarding validation or AI. At the password step, only
exact recognized commands and known navigation button IDs survive redaction;
other text, button titles, attachments and flow data remain redacted.

- `Start`, `Reset`, `Restart`, `Start over`, `Start fresh`, `Create shop` and
  `I want to create a shop` start a fresh draft at the business-name question.
  Previous incomplete drafts are abandoned, outstanding password links revoked,
  and conversation draft data cleared. Submitted applications and existing
  vendor accounts are preserved and receive their application status instead.
- `Hi`, `Hello` and `Menu` offer Continue / Start New / Talk to Support when a
  resumable draft exists. A greeting does not discard progress.
- `Continue` resumes the current draft. `Resend Link` issues a new secure link
  at the password step. Passwords must still be created on the HTTPS page.
- `Talk to Support` and `I want to talk to support` request human help. While
  human handoff is active, messages remain with support rather than restarting
  the bot automatically.

New contacts see Open My Shop / Learn About Selling / Talk to Support after
messaging the concierge. Meta Manager icebreakers can use `I want to create a
shop`, `Learn about selling`, and `Talk to support` as their reply text. This
release does not modify or replace the icebreakers already configured in Meta.
These are inbound conversation starters, not scheduled outbound reminders.

Automated replies request a typing indicator with the incoming message's read
receipt. Human handoff requests only the read receipt. Delivery remains
best-effort, and a typing/read failure does not stop message processing.
Payload reference: [Meta's WhatsApp API collection](https://www.postman.com/meta/whatsapp-business-platform/request/lhf0duq/send-typing-indicator-and-read-receipt).

Regression coverage: `tests/Hardening/ConversationNavigationTest.php` exercises
the inbound job through controller-style redaction, job redaction, message
storage, navigation routing, token revocation, and the typing request payload.
