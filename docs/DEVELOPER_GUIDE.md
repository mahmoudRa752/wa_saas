# Developer Technical Guide

This guide describes the code architecture, namespaces, testing, and customization options for developers.

## 1. Directory Structure

- `core/`: Application domain models, repositories, and services.
  - `Company/`: Company profiles management.
  - `Employee/`: Employee database interactions.
  - `Conversation/`: Chat conversation repository and security isolation.
  - `ChatMessage/`: Message logging and storage.
  - `Services/`: Core business logic (WhatsApp dispatching, Usage tracking, Logging, Audit).
  - `Auth/`: CSRF helper, Rate limiter, and Security headers helper.
- `cron/`: CLI scripts (Scheduled broadcasts worker, Database backups, Performance indexes).
- `api/`: AJAX endpoints for chat, internal messaging, exports, and status updates.
- `webhook/`: Webhook callback URL handlers.
- `layouts/`: Shared visual headers, sidebar navigation, and script footers.
- `tests/`: Testing directories (Conversation unit mapping and mock database queries tests).

---

## 2. Test Execution

Tests run in an offline-friendly sandbox using a lightweight mock harness.

### Run Tests:
```bash
php tests/TestRunner.php
```

---

## 3. Logger Services
- General application actions (Info, Warning, Error) write logs to `logs/app.log`.
- WhatsApp Graph API HTTP request failures log to `logs/failed_whatsapp.log`.
