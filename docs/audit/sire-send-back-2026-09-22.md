# SIRE — issue brief, sent back

**Upload this file on the issue register** (Export issue brief → Send it back).
Only the ticked issues close. You are shown exactly what will close before
anything does.

**Audited against the code on 2026-09-22.** Ticked = the fix is committed on
`master` and I verified it in the code, not just in a commit message. Everything
else is left unticked with a reason, so re-uploading this file later is safe.

---

## Ticked — verified fixed

### SIR-000018 — Bulk Upload

- [x] **Done** — Bulk add plus search for Commodity/Group/SubGroup in Inventory Settings (26023f30), with BulkAddMasterDataTest covering it.

### SIR-000019 — Colour

- [x] **Done** — Real colour picker with a manual hex entry, and bulk upload, in Inventory Settings (26023f30).

### SIR-000023 — Auto Order

- [x] **Done** — A new milestone now takes max(order)+1 instead of leaving it blank (9058d4e4), covered by MilestonesGetTheNextOrderNumberTest.

### SIR-000024 — unable to save documents

- [x] **Done** — Task mail is sent from app()->terminating() after the response is flushed, so saving no longer waits on SMTP (ae733f12).

### SIR-000014 — Vendor - Worker Entry

- [x] **Done** — The onboarding record decides, not the status column; exposed once as PurchaseVendor::can_register_workers and enforced in PurchaseWorkforceService, so no workforce can be added before onboarding completes (e8ee4493).

### SIR-000025 — Public link for ticket

- [x] **Done** — The helpdesk widget is reachable as a plain URL at /support/:key, same endpoint, same key, same throttle (e8ee4493).

### SIR-000012 — Add Customer

- [x] **Done** — A linked customer can now be corrected in place, not only added (e8ee4493, VendorCustomersPanel).

### SIR-000026 — Assignee

- [x] **Done** — Added a "Reported by" column to the register beside Assignee; the API was already sending the name, only the column was missing. Covered by SireRegisterShowsReporterTest.

---

## Not ticked — still open

**SIR-000011 — Port Issue** ("Report Issue not detecting SECTION, SCREEN, RECORD")
The work is done but **not committed yet**, so it is not live. I rewrote the
route audit tool to resolve nested routes, found 163 unresolved pages and 48
where the record was missed, mapped the whole Transport module and fixed 14
screens that lost the record on their tabs and /edit pages. 334 of 334
reportable pages now resolve. Tick this only once it is deployed.

**SIR-000017 — Assign Developer**
Sitting at `ready_for_qa`. The picker was built (staff plus customer directory,
grouped, multi-select with an owner). It needs a QA pass, not a close — closing
it from here would skip the verdict.

**SIR-000002 — Mismatch** — partially done, which is why it is not ticked.
Item 1 (cannot click in the popup) and item 4 (multiple image attachment) are
fixed. Items 2 and 3 (ticket field reference, public link and embed code) are
not. A partly-fixed issue should not close on the strength of its easy half.

**Purchase and TPV — not my module.** These need their owner:
SIR-000004 (line item box), SIR-000005 (submit confirmation with ref no.),
SIR-000006 (onboarding steps unclear), SIR-000013 (PPE list not showing, 422 on
induction), SIR-000015 (bulk upload for workforce and medical),
SIR-000027 (vendor code), SIR-000028 (prequalification dropdowns),
SIR-000029 (admin-set prequalification questions), SIR-000030 (meeting module).

**Projects — mine, but not started:** SIR-000036 (add Vendor/TPV),
SIR-000037 (expenses form incomplete), SIR-000039 (add members from the project
dashboard). These are features, not one-line fixes.

---

## One thing to fix on the live server

Every "full size" evidence link in the brief you sent points at
`http://localhost:5173`. That comes from `FRONTEND_URL` in the production
`.env` — the links are broken for everyone who opens the document.

```
FRONTEND_URL=https://<your live frontend host>
```

Then `php artisan config:cache`. The embedded screenshots still work either way;
it is only the full-size links that are dead.
