# /repositories

Data access layer.

Each Repository is responsible for reading/writing exactly one entity or
tightly-related group of tables (e.g. `WorkspaceRepository`,
`RoleRepository`, `ChatMessageRepository`) via SQL. All SQL lives here —
never in a Controller/Page, never in a Service.

Existing direct `mysqli` queries scattered across `dashboard/*.php` and
`api/*.php` are NOT touched by this delivery. They are migrated into
Repositories gradually, file-by-file, per the approved Strangler Fig
Integration Plan — never as a single big-bang rewrite.

Empty in this delivery.
