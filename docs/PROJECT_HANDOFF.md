DO NOT RE-ANALYZE THE PROJECT. Continue from the current implementation state described in this document.
# Project Overview
- Project purpose: WhatsApp SaaS dashboard for companies to send messages, manage conversations, and review usage.
- Current architecture: PHP application with procedural entry points, MySQL via mysqli, service/repository layers under core, and tenant-aware session context.
- Branch name: fix/chat-isolation-and-send
- Current status: Active feature branch with recent work around conversation notes, shared WhatsApp transport, usage extraction, and readonly-property bug fixes.

# Completed Work
- Feature: Conversation internal notes
  - Files created: [core/ConversationNote/ConversationNote.php](core/ConversationNote/ConversationNote.php), [core/ConversationNote/ConversationNoteRepository.php](core/ConversationNote/ConversationNoteRepository.php), [core/ConversationNote/ConversationNoteService.php](core/ConversationNote/ConversationNoteService.php)
  - Files modified: [dashboard/chat.php](dashboard/chat.php), [chat_schema.sql](chat_schema.sql)
  - Commit hash: add675a
  - Notes: Added CRUD for internal conversation notes, tenant-scoped access, and chat UI integration.
- Feature: Conversation notes access hardening
  - Files created: None
  - Files modified: [core/ConversationNote/ConversationNoteRepository.php](core/ConversationNote/ConversationNoteRepository.php), [core/ConversationNote/ConversationNoteService.php](core/ConversationNote/ConversationNoteService.php), [dashboard/chat.php](dashboard/chat.php)
  - Commit hash: 890340d
  - Notes: Tightened company/conversation access checks and unauthorized access handling.
- Feature: Shared WhatsApp transport service
  - Files created: [core/Services/WhatsAppService.php](core/Services/WhatsAppService.php)
  - Files modified: [api/send_chat.php](api/send_chat.php), [send_chat.php](send_chat.php), [dashboard/send.php](dashboard/send.php), [dashboard/bulk_send.php](dashboard/bulk_send.php), [dashboard/chat.php](dashboard/chat.php)
  - Commit hash: fa37cf6
  - Notes: Centralized outbound WhatsApp payload delivery and message edit/delete operations.
- Feature: Conversation lifecycle service layer
  - Files created: [core/Conversation/ConversationService.php](core/Conversation/ConversationService.php)
  - Files modified: [core/Conversation/ConversationRepository.php](core/Conversation/ConversationRepository.php), [api/send_chat.php](api/send_chat.php), [dashboard/chat.php](dashboard/chat.php), [webhook/receive.php](webhook/receive.php)
  - Commit hash: 7484ab8
  - Notes: Removed duplicated conversation lookup logic and centralized conversation creation/touch behavior.
- Feature: Shared usage service extraction
  - Files created: [core/Services/UsageService.php](core/Services/UsageService.php)
  - Files modified: [dashboard/index.php](dashboard/index.php), [dashboard/send.php](dashboard/send.php), [dashboard/bulk_send.php](dashboard/bulk_send.php)
  - Commit hash: pending handoff commit
  - Notes: Moved duplicate plan-limit and monthly-usage SQL into a shared service without changing business rules.

# Current Core Architecture
- Core classes: [core/TenantContext.php](core/TenantContext.php), [core/Conversation/Conversation.php](core/Conversation/Conversation.php)
- Services: [core/Services/WhatsAppService.php](core/Services/WhatsAppService.php), [core/Services/UsageService.php](core/Services/UsageService.php)
- Repositories: [core/Conversation/ConversationRepository.php](core/Conversation/ConversationRepository.php), [core/SavedReply/SavedReplyRepository.php](core/SavedReply/SavedReplyRepository.php), [core/ConversationNote/ConversationNoteRepository.php](core/ConversationNote/ConversationNoteRepository.php)
- TenantContext: Immutable session-based tenant scope object that exposes companyId and must not be mutated.
- Conversation layer: ConversationService + ConversationRepository manage lookup, creation, ownership checks, and last-message touch behavior.
- SavedReply layer: SavedReplyService + SavedReplyRepository provide tenant-scoped saved replies for the chat UI.
- ConversationNote layer: ConversationNoteService + ConversationNoteRepository provide internal note CRUD for conversations.
- WhatsAppService: Shared wrapper for Meta WhatsApp Cloud API payload delivery and message edit/delete requests.

# Database Changes
- New tables: conversation_notes
- New indexes: idx_chat_messages_conv, idx_conversations_company, idx_conversation_notes_company_conversation_created
- Migrations applied: Schema changes were applied through [chat_schema.sql](chat_schema.sql) and the working database state.

# Bugs Fixed
- Readonly-property fatal error from mysqli bind_param: solved by copying readonly TenantContext values into local variables before binding.
- Conversation notes lacked strict tenant access enforcement: solved by adding company/conversation checks in the repository and service path.
- WhatsApp send logic was duplicated across entry points: solved by centralizing the transport code in [core/Services/WhatsAppService.php](core/Services/WhatsAppService.php).
- Stale WhatsApp access token handling in the API path: solved by aligning the API sender with the current shared token usage.

# Pending Work
1. Complete end-to-end verification with a real authenticated company and working WhatsApp configuration.
2. Validate the chat flow and send flow against live database data after the recent refactors.

# Current Technical Debt
- Several dashboard pages still use procedural SQL directly instead of a fully unified service layer.
- Live validation is still partially dependent on available seeded credentials and runtime WhatsApp configuration.

# Important Rules
- Keep TenantContext immutable and never mutate readonly properties.
- Preserve existing SQL and business rules during refactors.
- Do not change the database schema or UI unless explicitly approved.
- Keep tenant/company isolation strict in all repository and service calls.
- Prefer shared services for repeated logic rather than duplicating SQL in multiple entry points.

# Files That Must Be Read First
- [core/TenantContext.php](core/TenantContext.php)
- [core/Conversation/ConversationService.php](core/Conversation/ConversationService.php)
- [core/SavedReply/SavedReplyService.php](core/SavedReply/SavedReplyService.php)
- [core/ConversationNote/ConversationNoteService.php](core/ConversationNote/ConversationNoteService.php)
- [core/Services/WhatsAppService.php](core/Services/WhatsAppService.php)
- [core/Services/UsageService.php](core/Services/UsageService.php)
- [dashboard/chat.php](dashboard/chat.php)
- [dashboard/send.php](dashboard/send.php)
- [dashboard/index.php](dashboard/index.php)
- [chat_schema.sql](chat_schema.sql)

# Next Recommended Task
- Run a full end-to-end login, chat, and send verification against a real tenant and valid WhatsApp credentials.

# Things NOT to Change
- Do not remove readonly from TenantContext.
- Do not change the database schema.
- Do not change the UI flow or existing business rules.
- Do not bypass tenant/company access checks.
- Do not introduce new storage patterns without explicit approval.

# Validation Checklist
- Run php -l on every modified PHP file.
- Verify tenant/company scoping for conversation and notes operations.
- Verify the chat and send pages still render without fatal errors.
- Verify existing SQL behavior remains unchanged after any refactor.
- Verify any new feature still works with the current database schema.
# Current Known Issues

- [ ] End-to-end test لم يتم بعد.
- [ ] يحتاج اختبار إرسال رسالة حقيقية على Meta Cloud API.
- [ ] يحتاج اختبار Webhook Receive.
- [ ] يحتاج اختبار Bulk Send.
- [ ] يحتاج اختبار Conversation Notes CRUD بالكامل.
- [ ] يحتاج اختبار Saved Replies CRUD بالكامل.

# Files Modified Recently

core/
 ├── TenantContext.php
 ├── UsageService.php
 ├── WhatsAppService.php
 ├── Conversation/
 ├── ConversationNote/
 └── SavedReply/

dashboard/
 ├── chat.php
 ├── send.php
 ├── bulk_send.php

api/
 └── send_chat.php

# Current Design Rules

- TenantContext immutable (readonly) ولا يتم تمرير خصائصه مباشرة إلى bind_param().
- أي Repository يستخدم mysqli::bind_param يجب نسخ readonly values إلى local variables أولاً.
- أي Feature جديدة يجب أن تكون Service + Repository وليس SQL داخل الصفحة.
- أي استدعاء للـ Meta API يمر عبر WhatsAppService فقط.
- أي عملية CRUD يجب أن تكون Tenant Scoped.
- أي Form جديد يجب أن يحتوي CSRF.

# Before Writing Any Code

1. اقرأ PROJECT_HANDOFF.md بالكامل.
2. اقرأ الملفات المذكورة فيه فقط.
3. لا تعمل Refactor كبير.
4. لا تغير الـ UI.
5. لا تغير الـ Database Schema إلا إذا طُلب.
6. لا تكسر Backward Compatibility.

# Priority Backlog

P1
- End-to-End Testing

P2
- إصلاح أي Bugs تظهر أثناء الاختبار

P3
- تحسينات Performance

P4
- Refactoring تدريجي فقط بعد نجاح الاختبارات

# Definition of Done

قبل اعتبار أي Task منتهية:

- php -l PASS
- لا يوجد Fatal Error
- لا يوجد Warning
- Login يعمل
- Chat يعمل
- Send يعمل
- Notes تعمل
- Saved Replies تعمل
- Bulk Send يعمل
- WhatsApp API يعمل
- لا يوجد Regression
