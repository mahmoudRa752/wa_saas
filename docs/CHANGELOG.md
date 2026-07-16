# Changelog

All notable changes to the WA SaaS Manager project are documented here.

## [1.2.0] - 2026-07-13
### Added
- **Canned Message Templates with Variables**: Client-side variable replacements (`{contact_number}`, `{my_name}`, `{company_name}`) inside live chat message composition.
- **Smart Customer Segments**: Saved smart filters filtering by assignee, status, and tag ID in live chat.
- **Scheduled Broadcast Campaigns**: Scheduling bulk dispatches with Excel recipient parsing and background execution workers.
- **Auto-Replies webhook integration**: Automatic intercept keywords triggers matching configured responder rules.
- **Conversation SLA Timers**: Display warning and breached badges indicating customer wait times.
- **Employee Performance Dashboard**: Interactive metrics and speed response speed charts using Chart.js.
- **Advanced Global Search**: Search messages, contacts, tags, and internal notes.
- **Excel/PDF Chat Exports**: Direct Excel sheets generation and print-to-PDF transcripts.
- **Paginated Audit Logging**: Event-tracking service logging admin/employee actions.
- **Consolidated Settings**: Settings tabs for Profile updates, WhatsApp linking, and alert preferences.
- **Docker Support**: Containerized Dockerfile and docker-compose configurations.
- **CI/CD Actions**: Github Actions linting pipeline and Composer validation checks.
- **Offline Test Suite**: Mock database testing framework and runner script.
