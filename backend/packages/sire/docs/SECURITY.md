# Security

SIRE holds an organisation's defect backlog: what is broken, how badly, since
when, and reproduction steps for bugs that are **not yet fixed**. Four boundaries
protect it.

---

## 1. Tenant isolation

**The only failure in SIRE with no symptoms.** A wrong tenant source returns
another customer's issues on a page that renders perfectly normally, with
nothing in any log.

Everything about the design follows from that asymmetry.

| | |
|---|---|
| Resolved **server-side only** | Never from a body, query string, route parameter, header or browser storage |
| `currentTenant()` **throws** | No nullable return, no default argument — nowhere to put a fallback, because a fallback *is* the bug |
| Ownership failures return **404** | Not 403. A 403 confirms the record exists, turning any id into a cross-tenant existence oracle |
| Every query is scoped | ~1,100 explicit `->forTenant()` calls; a static scan fails the build on one that forgets |
| Every table has `tenant_id NOT NULL` | The database refuses an unscoped row |
| The installer **will not guess** | Discovery never reports a tenant column as HIGH confidence, and a human confirms it |

**Verify it yourself.** Sign in as tenant A, note an issue id, sign in as tenant
B, request it. You want a 404. `sire:doctor` cannot check this — only you can.

Implementing `SireTenantProvider` does **not** hand tenancy to the host. SIRE
still scopes every query and still calls `assertAccess()` on every route-bound
record; the provider only answers *which tenant is this request*.

## 2. Who reaches SIRE at all

Four categories. Two of them get in.

| | Access |
|---|---|
| **ADMIN** | Yes, subject to capabilities |
| **INTERNAL_USER** | Yes, subject to capabilities |
| **CUSTOMER** | **No** |
| **VENDOR** | **No** |

A role in **no** list gets nothing. Unmapped means denied, because the failure
mode of "unmapped means denied" is a colleague asking for access, and of
"unmapped means allowed" is a customer reading the backlog.

**Two locks on the same door.** `SireRequireEngineeringLogin` middleware runs on
every route before any controller; `SireAccessService::can()` checks again
before every capability. A route that forgot its capability check is still
closed.

The **mapping** is configurable — SIRE cannot know whether your "partner" role
means a colleague or a competitor's supplier. Whether the boundary is
**enforced** is not: it is a middleware SIRE always appends, so
"customers can read the backlog" is not reachable by editing a config file.

## 3. Authentication fails closed

SIRE hardcodes no guard. It composes its middleware stack from
`config('sire.host.auth_middleware')`.

**If that is empty, every SIRE route returns 503.**

Not "open with a warning". Not "fall back to `auth`". An engineering issue
tracker on the open internet is not a state anyone should reach by leaving a
config key blank — and that key is exactly the sort someone empties while
debugging and forgets to restore.

## 4. What may leave the application

`AiContextSchema` is an **allowlist**. Only fields named there can be sent to an
AI provider.

Never sent: passwords, tokens, cookies, authorization headers, API keys,
secrets, unnecessary identity, cross-tenant data.

**AI is off by default**, all 13 capabilities compute locally, and providers
resolve through a hardcoded allowlist — never by instantiating a class name read
from a tenant setting. A tenant that could name a provider class could name any
class.

`SireUserIdentity` carries four fields. That is not minimalism for its own sake:
a rich user model behind the redaction boundary is a standing invitation for
someone to append `$user->email` to a prompt payload. Four readonly fields
cannot leak a fifth.

---

## Error semantics

Getting these wrong leaks information.

| | When | Why |
|---|---|---|
| **401** | Not authenticated | The only place SIRE emits one — an SPA signs out on a 401 |
| **403** | Authenticated, not permitted | Never for cross-tenant access |
| **404** | Not found, **or another tenant's** | Deliberately indistinguishable |
| **409** | A rule violation | Well-formed request, wrong world-state |
| **422** | Validation | Per-field |
| **503** | SIRE misconfigured | Fails closed |

Stack traces are never exposed. Errors carry a short reference tying a user's
message to the logged trace.

## Audit cannot be rewritten

`SireAuditProvider` has **no update and no delete**, no SIRE endpoint edits an
event, the model throws on both, and a test asserts no such route exists.

Release approvals and emergency overrides are defended by this trail. A history
that can be rewritten afterwards proves nothing about what happened.

## Attachments

Tenant is in the storage path, so isolation is structural rather than a `WHERE`
clause somebody must remember. Type and size are checked against the **sniffed**
mime type — an `accept` attribute and a `Content-Type` header are both client
hints. Attachment ids are opaque handles: if ids were paths, `../../.env` would
be a valid id.

## Discovery and installation

- Discovery is read-only, and reads shapes rather than values.
- The host profile is written to `storage/app`, never `public/`.
- Profiles and install reports are redacted before printing.
- Security-sensitive settings are never applied without explicit confirmation —
  including in `--non-interactive` mode, where a missing answer **stops** the
  install rather than defaulting.
- Uninstall cannot touch a table without the `sire_` prefix, and data deletion
  requires a typed phrase that `--force` does not bypass.

## Reporting a vulnerability

Do not open a public issue. Contact whoever maintains this package for your
organisation, with the SIRE version and, if you can, the output of
`php artisan sire:host-profile` — which is redacted and safe to share.
