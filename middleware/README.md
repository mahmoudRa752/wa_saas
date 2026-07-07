# /middleware

Request gatekeepers that run before a Controller/Page/API handler executes
(e.g. an "must be logged in" guard, a "must hold permission X" guard, future
CSRF verification). Built on top of the Core Layer (`Auth`, `Permission`,
`Session`) rather than duplicating inline `if (!isset($_SESSION[...]))`
checks.

Empty in this delivery. Introduced in Phase 3 of the Integration Plan when
inline guards are swapped for shared checks, one file at a time.
