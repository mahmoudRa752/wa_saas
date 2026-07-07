# /services

Business logic layer.

Each Service encapsulates one area of business logic (e.g. `EmployeeService`,
`WhatsappNumberService`, `ChatService`) and is called by a thin
Controller/Page. Services orchestrate one or more Repositories and Core
classes (`Auth`, `Workspace`, `Permission`) — they never talk to the
database directly (that's the Repository's job) and never emit HTML/JSON
directly (that's the Controller/Page's job, optionally via `Response`).

Empty in this delivery. Populated incrementally per the Strangler Fig plan
as existing pages are refactored, and for all new features going forward.
