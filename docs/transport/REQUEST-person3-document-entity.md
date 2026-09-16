# Request to Person 3 — allow documents on a Consignment, and add `delivery_order`

**From:** Person 1 (Core, Commercial & Operations) · **Date:** 2026-09-12
**Needed by:** Block 2 (Container 360 document panel). Block 1 is not blocked on this.
**Ruling behind it:** D-41, ruled 2026-09-12 — *LR and DO stay documents; no `transport_lr_records`,
no `transport_delivery_orders`.*

---

## Why you and not me

These are three small changes in **your** files. I have not touched them. Under TM-001 §3 documents
and compliance are yours, and `ENUM-006`'s recorded owner is *Product + Compliance*.

The alternative — me building `transport_lr_records` and `transport_delivery_orders` — was rejected
because LR already lives in `transport_documents` as `document_type='lr'`, and a second home for it
is exactly what Step 9's domain model and our "no duplicate master data" rule forbid.

## What I need — three changes

**1. `backend/app/Support/Transport/TransportDocumentEntity.php`** (line 21–28)

```php
public const CONSIGNMENT = 'consignment';

public const ALL    = [self::VEHICLE, self::DRIVER, self::CUSTOMER, self::CONSIGNMENT];
public const ACTIVE = [self::VEHICLE, self::DRIVER, self::CONSIGNMENT];
```

**2. `backend/app/Services/Transport/TransportDocumentService.php`** — `entityTypeFor()`, **line 227**
(not `resolveEntity`; the method was renamed at some point)

```php
$subject instanceof \App\Models\Transport\TransportConsignment => TransportDocumentEntity::CONSIGNMENT,
```

**3. `ENUM-006` / `TransportDocumentType`** — add `delivery_order`

`ENUM-006` currently reads `lr|ewaybill|invoice|pod|driver_doc|vehicle_doc|insurance|permit|fitness|other`.
`RTM STOS-REQ-ORD-006` ("Capture DO details", **P0**) has no value to use. Suggested:

```php
public const DELIVERY_ORDER = 'delivery_order';   // RTM STOS-REQ-ORD-006
```

Label: *"Delivery Order"*.

> ### ✅ Architecture approval already granted — you do not need to escalate this
>
> `ENUM-006` is **LOCKED** in Step 11, so I raised the change rather than assuming it was routine.
> **The owner granted approval in writing on 2026-09-12**, on the same footing as D-39. Grounds
> recorded in `registry-defects.md` under D-41:
>
> - `ORD-006` is **P0** and unimplementable today — there is no value to file a DO under.
> - A delivery order has **no other home**: no domain-model row, no table, no enum.
> - The only alternative is a separate `transport_delivery_orders` table, which gives delivery
>   orders a second home and is forbidden by Step 9's no-duplicate-business-objects rule.
>
> This arrives as an approved change, not a question.

## What this unblocks

| Requirement | Priority |
|---|---|
| `STOS-REQ-ORD-005` — Capture LR details | **P0** |
| `STOS-REQ-ORD-006` — Capture DO details | **P0** |
| `STOS-REQ-CTD-004` — Link container to LR | **P0** |
| `STOS-REQ-CTD-005` — Link container to DO | **P0** |

## Until it lands

`TransportConsignment` will exist and be stable after Block 1, so change 2 can be made any time after
that. I am coding Block 2's document panel against a stub returning realistic LR/DO rows, bound in
the service provider — swapping to the real call is one binding line, the same pattern as
`PendingFleetResourceGateway`. No `if (env('local'))` branches.

**No reply needed if you agree** — 24h / no objection, per TM-001 §8 step 5. Tell me if you would
rather I raise it as a PR against your files for you to review instead.
