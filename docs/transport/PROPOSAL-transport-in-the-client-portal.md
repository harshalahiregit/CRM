# Proposal — Transport inside the client portal

**Person 1 · 22 September 2026 · proposal only. No code, no migration, no route.**
Everything below was read in the codebase today, not carried over from my earlier proposal.
Two §19 stop conditions are raised in §7.

---

## The short version

**The portal is further along than either of my previous proposals said, and it already has
conventions for all three things I thought we would have to invent.** Scoping, permission gating
and field whitelisting are each solved here, in working code, with reasoning in the comments.

**Transport should copy all three and invent nothing.** A Transport list screen is a column
definition and one endpoint — not a page. My earlier "presenter pattern" idea should be dropped in
favour of what is already here, which is better.

---

## 1 · What is in the portal today

A shell with **fourteen sections in six groups**, navigation built from the contact's own
permissions:

| Group | Sections | Gated on |
|---|---|---|
| — | Overview | always |
| Money | Invoices · Payments · Credit Notes · Statement | `invoice` |
| Sales | Estimates · Proposals | `estimate` / `proposal` |
| Agreements | Contracts | `contract` |
| Work | Projects · Support | `project` / `support` |
| — | Feedback · Files · Notes · Contacts · My Profile | always |

**The house style is one generic table.** `ClientPortalRecords.jsx` says it plainly:

> *"Ten screens that differ only in their columns and endpoint do not need ten files — they need
> one table and ten column definitions."*

A section is declared like this:

```js
projects: { title: 'Projects', fetch: () => clientPortalApi.projects(), cols: [
  { k: 'name', h: 'Project', bold: true }, { k: 'start_date', h: 'Start', fmt: date },
  { k: 'status', h: 'Status', status: true },
]},
```

**So "add a Transport screen" means: one entry in that map, one nav item, one endpoint.** Not a
new page, not a new layout, and it will look like it belongs because it *is* the same table.

One more thing worth copying: a 403 renders as *"not shared with you"*, not as an error — *"the
server refusing a section the contact was never granted is correct behaviour, and showing it as a
failure would suggest something is broken."*

---

## 2 · The fourteen endpoints — how they actually work

Read properly, they share one shape:

```php
$this->portal->assertCan($this->contact($r), 'invoice');   // 1. permission, server-side
$c = $this->client($r);                                     // 2. client off the TOKEN

DB::table('sales_invoices')
    ->where('tenant_id', $c->tenant_id)->where('client_id', $c->id)   // 3. scoped
    ->get(['id', 'number', 'date', 'due_date', 'total', 'paid',       // 4. explicit columns
           'balance', 'status', 'currency']);
```

**Four conventions, all of them load-bearing:**

1. **`assertCan()` first**, throwing 403. Not a menu filter — the nav uses the same flags, but the
   server refuses independently.
2. **The client comes off the token.** The route file says why: *"No route here takes a client id:
   the contact's own client comes off the token, so 'show me another customer' is not
   expressible."*
3. **Every query scoped by `tenant_id` + `client_id`.**
4. **`DB::table(...)->get([explicit columns])` — never a model.** I checked: nine `DB::table` uses
   and **zero** `::with()`, `->load()`, Resource classes, `->toArray()` or `makeHidden()` in the
   controller or the service.

---

## 3 · How the portal already hides things — and it answers my open question

**Yes, and by three mechanisms.** This is the precedent and we copy it.

**(a) Permission gate.** `ClientPortalService::can()` fails closed:

> *"A contact with no permissions array is a legacy row from before the portal existed. Treating
> that as 'everything' would silently hand out access nobody granted, so the safe reading is
> 'nothing'."*

**(b) Column whitelist by construction.** This is the important one. Because every query is
`DB::table(...)->get([named columns])`, **a new column on a table cannot leak — it is never
selected.** The sensitive data is not fetched, so there is nothing to forget to strip.

**(c) Row-level visibility flags.** Notes are filtered to `visibility = 'client'`; files to
`customer_visible = true`. And the service documents what is deliberately withheld:

> *"Customer Health and Risk — an internal management judgement about the customer. Showing
> someone their own risk rating would be extraordinary. Credentials (the vault) — it stores OUR
> access to their systems. Internal notes. Files not marked customer_visible."*

### This changes my answer to the nested-payload question

I proposed a presenter per resource. **I now think that is the wrong answer and this is the right
one.** A presenter still *loads* the model and then chooses; the query-builder select never loads
the field at all. And nesting — the case I flagged, a driver inside an assignment inside a trip —
**does not arise**, because the portal joins and aliases rather than eager-loading:

```php
->join('sales_invoices as i', 'i.id', '=', 'p.invoice_id')
->get(['p.id', 'p.date', 'p.amount', 'p.mode', 'i.number as invoice_number'])
```

**A flat select with explicit columns has no nesting to leak through.** That is a better answer
than mine and it is already the house style.

---

## 4 · What `client_contacts` can express — re-checked today

| CLP §3 axis | Column | Expressible |
|---|---|---|
| Organisation | `client_id` | **Yes**, enforced by the guard |
| Role | `role`, `permissions` | **Yes** |
| Transaction | `permissions` | **Partly** — it names sections, not transaction types |
| **Branch** | — | **No.** `branch_id`, `location_id`, `site_id` all absent |
| **Document type** | — | **No** |

**And I re-confirmed there is no customer-location table anywhere in the schema.** Searched every
table for a client/customer location, branch or site: **none.**

Against CLP §3's six roles:

| Role | Expressible today |
|---|---|
| Client Admin | **Yes** |
| Client Operations | **Yes** |
| Client Finance | **Yes** |
| Client Management | **Yes** |
| **Client Quality / Compliance** | **Partly** — needs document-type awareness for CAPA evidence |
| **Client Warehouse / Gate** | **No.** Its entire purpose is site-scoped |

---

## 5 · What Transport data may cross — audited, not judged

CLP §3: *"Internal salary, profitability, penalties, management notes and internal CIA analysis are
excluded."* §23: *"No internal salary/profitability/private HR/other-customer data."*

Audited every Transport table. **Everything in this list must never be selected into a portal
query:**

| Table | Must not cross |
|---|---|
| `transport_trips` | `approved_freight`, `closure_reason`, `rejection_reason`, `*_by` |
| `transport_orders` | `rate_reference`, `*_by` |
| `trip_assignments` | `reason`, `override_reason`, `approved_by` |
| `transport_drivers` | `driver_code`, `licence_number`, `licence_normalized`, `licence_class`, `licence_valid_*`, `availability` |
| `trip_bills` | `notes`, `prepared_by`, `invoiced_by` |
| `trip_collections` | `blocker_reason`, `notes`, `last_followed_up_by` |
| `trip_costs` | **the whole table** |
| `trip_advances` | **the whole table** |
| `trip_exceptions` | `resolution_note`, `raised_by`, `acknowledged_by`, `resolved_by` |
| `trip_documents` | `file_path`, `file_hash`, `rejection_reason`, `notes`, `*_by` |

`file_path` deserves its own line: **STOS-SEC-002 (l.156) forbids exposing unrestricted storage
paths for protected documents.** A portal document endpoint must serve a streamed, authorised
download, never a path.

**What a client may see** is small and easy to write down: trip number, status *in plain words*,
route, planned and actual dates, vehicle registration, driver *name only*, container number,
consignment number and cargo description, their own reference, POD verified yes/no, invoice number
and amount, payment status.

---

## 6 · The proposal

### a) Which screens, in what order

**Screen 1 — "Shipments" (a list).** One entry in the `SECTIONS` map, one nav item gated on a new
`transport` permission, one endpoint. Columns: reference, container, route, status (plain), ETA,
POD, invoice.

**Screen 2 — the journey view.** One shipment: header, plain-language timeline, exceptions
prominent (§27), documents. **This is the "single Trip/Container 360 view" §27 asks for** — the
same concept we rebuilt last week, which is why it is not a fresh design.

**Screen 3 — documents.** Blocked on a ruling (§7, S-2).

§27's *"responsive for gate and warehouse users"* applies from screen 1. The portal's generic table
is already a portal-width layout; our internal 1440px two-column pages are not, and **that is the
reason to build these as portal screens rather than reuse ours.**

### b) The field whitelist

**Adopt the portal's convention exactly**: `DB::table(...)->where(tenant)->where(client)->get([named
columns])`, joins and aliases instead of relations, no models.

Plus **one guard of our own**, because the convention is a habit and habits slip: a test that calls
every Transport portal endpoint and fails if any response key appears in the §5 deny-list. Broken
deliberately two ways — a denied column added to a select, and a `->join` that pulls one in under
an alias. **The alias case is the one that would slip past a reviewer.**

### c) M01–M14

**Not in this proposal, and I recommend it is not in the portal's first release.** I sized it
separately as **D-126**: two of fourteen milestones are fully live, four have **no vocabulary
registered anywhere** (container yard arrival, inspection and loading, yard departure, loading and
sealing), five are registered types nobody emits — all owned by P2 or P3 — and detention has
nothing at all.

It also needs a milestone *entity*: §8 requires planned **and** actual time, location, evidence and
an exception link, and `trip_events` has none of those four. **A milestone carries an expectation
as well as a fact. An event cannot be late; a milestone can.**

**Where I would have to choose rather than read** — and therefore will not, without a ruling:
which of our events maps to M02 (our `pretrip.passed` is an inspection, but §8's M02 bundles
vehicle documents and yard departure with it), and whether M08 *Client Premises Departure* is our
`trip.departed` (ours means "left the pickup point", which is the same event only when the pickup
*is* the client's premises). Two guesses, both load-bearing, neither mine to make.

**What we can honestly show now:** the nine moments we already emit, in the client's language —
allocated · checks passed · dispatched · departed · delivered · proof of delivery · invoiced ·
paid · closed. Labelled as an interim so nobody mistakes it for §8's model.

### d) What I would build first

**Screen 1, end to end: the Shipments list.**

One nav item, one column definition, one endpoint, one permission entry, one leak test. A client
logs in and sees their shipments with a plain status. That is genuinely useful on its own, it
exercises every convention the rest inherits, and if the conventions are wrong we find out on the
cheapest possible screen.

### e) What I will not build, and why

| | Why |
|---|---|
| **Booking / transport requests** | No `Transport Request` entity, and booking needs the rate card — unspecified and unowned |
| **Contract or rate display** | Same |
| **Client Warehouse/Gate role** | No customer-location entity anywhere; CLP §26 puts it in the Customer module |
| **M01–M14 milestones** | D-126 — four have no vocabulary, five emitters are not ours, and it needs an entity Step 11 has no registry for (B-08) |
| **Client document downloads** | Blocked on S-2 below |
| **A second scope resolver** | The portal already scopes by client off the token. P3's `ScopeResolver` stays the staff answer. |

---

## 7 · Stop conditions (§19)

**S-1 — which document types a client may open is undefined.**
CLP §11 gives clients an evidence vault and §23 requires *"permission-controlled document
access"* — but nothing says *which types*. A POD, presumably. An LR, probably. An internal cost
note, obviously not. An e-way bill, unclear. SEC-002 forbids exposing storage paths, so this must
be settled before any document endpoint exists. **I am not choosing the convenient reading.**

**S-2 — the Transport permission matrix maps `role:client` to the identity that does not sign in.**
`TransportPermission::ROLE_MAP` maps `role:client` → `ROLE_CUSTOMER`, but the working door
authenticates a **`ClientContact`**, which that matrix does not model at all. The portal will gate
on `client_contacts.permissions`, not on the Transport matrix — which is correct, and means that
mapping is now misleading. **Removing or re-pointing a permission mapping is a ruling, not a
refactor.**

---

**Nothing built. Two stop conditions raised. Waiting.**
