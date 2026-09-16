## Conventions

| | |
|---|---|
| Auth | Sanctum bearer token, like the rest of the host |
| Tenant | Server-side from the token. **Never** read from the body — requests are stripped of `tenant_id` before validation |
| Not yours | **404**, not 403 — existence hiding |
| No permission | **403**, never 401. The SPA signs out on an auth-shaped 401 |
| Errors | `SireApiResponse` — `{ message, context }`. `SireException` renders 409 with a user-safe message |


## Shapes worth knowing

**Create an issue** — `POST /api/sire/reports`

```json
{
  "title": "Saving a lead fails with a 500",
  "description": "The spinner runs and then nothing happens.",
  "submit": true,
  "context": { "module": "sales", "screen": "lead-details", "...": "" }
}
```

`tenant_id` and `reporter_id` are **never** accepted — they come from the token.
The `context` bag passes a server-side allowlist and is re-redacted on receipt.

**Move an issue** — `POST /api/sire/reports/{report}/transitions`

```json
{ "action": "mark_ready_for_qa", "fix_summary": "Added the missing null check." }
```

One endpoint for all transitions. The state machine already knows what is legal
from where; twenty near-identical endpoints would be twenty places for the rules to
drift. `status` is stripped from the payload — the machine decides.

**Record a test result** — `POST /api/sire/test-cases/{testCase}/result`

The **only** path that writes a result. One person, one test, one result. No bulk
pass, no automatic pass.
