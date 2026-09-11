# Reference host fixtures

Three fake Laravel CRMs, deliberately shaped differently, used to prove SIRE's
architecture is not quietly written for one of them.

They are **fixtures, not applications**. Each is a JSON description of what
discovery would find — schema shapes, package lists, role names, config — plus
the SIRE configuration a correct installation should produce. The test suite
runs discovery's own classification logic against them and asserts the outcome.

## Why three, and why these three

| | `acme` | `northwind` | `solo` |
|---|---|---|---|
| Tenant | `users.tenant_id` | `users.organization_id` | none — single tenant |
| Tenant strategy | `user_attribute` | `user_attribute` | `single_tenant` |
| Auth | Sanctum | session (`auth:web`) | Passport (`auth:api`) |
| Roles from | `users.role` column | `roles` table (Spatie) | `users.user_type` |
| Role names | admin, staff, client | super_admin, employee, supplier | owner, engineer |
| Frontend | React + Vite | Inertia + React | Blade only |
| Audit | `audit_logs` | none | none |
| Notes | `notes` | `comments` | none |
| KB | `kb_articles` | none | none |

Every row differs from `acme` in at least one axis that SIRE used to hardcode.
`northwind` in particular exists because the original package assumed
`tenant_id`, `auth:sanctum` and a `role` column — all three are wrong there, and
if any of them were still baked in, its fixture would fail.

`solo` is the awkward one on purpose: no tenancy, no audit, no notes, no
knowledge base, no SPA. It is the honest minimum — a small Laravel CRM with
users and nothing else — and SIRE has to install and run there without
complaint, using its own tables for everything.

## What they prove, and what they cannot

**They prove** that discovery's detection and classification logic reaches the
right conclusions for three genuinely different shapes, and that no shape is
privileged in the code.

**They do not prove** that SIRE runs inside a real CRM. That needs a real
application, a real database and a browser, and nobody should read a green
fixture suite as a substitute. See [`../docs/TROUBLESHOOTING.md`](../docs/TROUBLESHOOTING.md)
for what to verify by hand after a real install — the two-tenant isolation check
above all.
