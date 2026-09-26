# SIRE — issue brief, sent back (23 Sep)

**Upload this file on the issue register** (Issues → Export issue brief → Send it
back). You are shown exactly what will close before anything does, and
re-uploading is safe — untouched issues are left alone.

Written against the 17-issue export of 23 Sep 07:52 (scope: `open`).

**Six issues close on this upload.** Every one of them was checked three ways:
the fix is in the code, the fix is on `master`, and the commit carrying it is an
ancestor of a commit already proven to be running on the live server. Nothing is
ticked on the strength of a commit message — two commits in this repo name a SIR
number without fixing anything.

**Eleven are left unticked, each with the reason.** Ten of them are written and
tested but sit in an uncommitted working tree, so they are not on the live site
yet; ticking those would close issues against fixes nobody can use, and they
would come straight back. The eleventh is with QA, not with engineering.

---

## Ticked — fixed, on master, and live

### SIR-000004 — Line item

- [x] **Done** — The catalog box on a purchase request now accepts typing whatever the catalog holds; it used to be `disabled` whenever the catalog was empty, so on a fresh workspace it took no characters at all and the Add Item button beside it had collapsed to nothing, leaving no way to put a line on a request — a catalog match now brings its SKU, unit, rate and any contract rate, and anything else becomes a free-text line with the typed words already in it.

### SIR-000005 — Ref. No.

- [x] **Done** — Reporting an issue now ends on a receipt that replaces the form and stays until it is dismissed by hand, instead of a toast that showed the SIR- number for a few seconds and closed itself; it carries the reference number with a Copy button, says in words where to watch the issue (Issues & Quality → My Work), and offers View my issues and Open this issue.

### SIR-000006 — Onboarding Vendor

- [x] **Done** — The vendor's onboarding panel now lists all six steps in order rather than only saying "Step 1 of 6", each with the server's own one-line detail ("3/7 uploaded", "2 rejected"), the completed ones ticked, and the first incomplete one marked Next step instead of left for the reader to work out.

### SIR-000011 — Port Issue

- [x] **Done** — Report Issue now resolves MODULE, SECTION, SCREEN and RECORD on every page: the route map was rewritten to match nested and prefixed paths and the whole Transport module was added, so the very page this was filed from, /app/purchase/vendors/1/customer, now reads Purchase / Vendors / Customer / Purchase Vendor #1 — this applies to newly filed issues, and reports already in the register keep the context captured when they were filed, which is history rather than a bug.

### SIR-000015 — Bulk Upload

- [x] **Done** — Both halves are there and reachable from Purchase → Workers: workforce via POST /purchase/workforce/workers/upload and medical certificates via POST /purchase/medical/bulk, with the medical import taking the certificate files alongside the sheet and reporting how many were imported and how many were rejected.

### SIR-000036 — Vendor/TPV

- [x] **Done** — The project's Vendors/TPV tab now assigns named people inside a client, a purchase vendor or a TPV vendor, several at a time, rather than only the company, and it is held down by ProjectPartyAssignmentTest.

---

## Not ticked — written and tested, but not committed and not live

Ten issues. The code exists, 54 tests across five new files pass, and the
frontend builds with all of it applied — but none of it is committed, so none of
it is on the server you are reading this on.

They are left unticked deliberately. Tick this whole section in one pass once the
branch is merged and deployed, not before.

### Projects

- [ ] **Not yet** — SIR-000037, Expenses Form. The form asked for four fields; the register needs a category, a reference number, a payment mode, a currency, tax and the receipt itself. The columns exist, the receipt uploads and downloads, and the CSV export carries the new columns. The `expense_categories` master had been sitting in the database with nothing reading it — this reads it. Covered by ProjectExpenseFormTest (11 tests).

- [ ] **Not yet** — SIR-000039, Member Add. A Members card on the project page adds and removes people in place — staff, and named people inside a vendor or TPV company — instead of reopening the whole edit drawer to change one field.

- [ ] **Not yet** — SIR-000040, Upload Milestone. An Import sheet button on the Milestones tab, reading csv/txt/xls/xlsx/zip through the same reader the workforce import uses. Each row reports its own error rather than the sheet failing whole, and a row with no due date is named in the result, because that column is NOT NULL and the alternative is a silent half-import. Covered by MilestoneImportTest (10 tests).

- [ ] **Not yet** — SIR-000042, Assign Members. Assign on the row, in the project's task table, several people at once.

### Purchase and TPV

- [ ] **Not yet** — SIR-000013, PPE issues. The PPE step could only ever *return* kit and show what was already held, so "PPE list is not showing to select" was literally true on the screen this was filed from. The catalogue renders there now, with out-of-stock items shown disabled rather than hidden — "the helmet is not on the list" and "the helmet has run out" are different problems — and issuing more than is held warns first. **Two things to check before ticking this one even after it deploys:** the picker built in an earlier pass went into PurchaseWorkerDetail, which `/app/purchase/workers/:id` does not render, and the 422 on induction is not patched directly because it was the step after the one that could never be completed. Issue a kit on the running site and then run the induction.

- [ ] **Not yet** — SIR-000027, Vendor Code. `purchase_vendor` has been a configurable type in Settings → Numbering the whole time — prefix, width, date parts, reset rule — and the generator hard-coded PV-0001 and read none of it, so the honest answer to "how do I set the vendor code?" was: you can, the screen saves it, and nothing reads it. It reads it now. Workspaces that never turned numbering on keep PV-0001, because that type throws when it is disabled and leaving it unhandled would turn "create a vendor" into an error for everyone who never visited the screen. Covered by VendorCodeFollowsNumberingTest (8 tests).

- [ ] **Not yet** — SIR-000028, Vendor Prequalification.
- [ ] **Not yet** — SIR-000029, Prequalification. One fix, because they are one issue. Every drop-down on the prequalification form *is* a question in `config/purchase_prequalification.php` — 280 lines of PHP, identical for every workspace and changeable only by a developer with a deploy. Purchase Settings → Prequalification now edits the whole thing: sections, questions, answers, and the score each answer carries. The result is a sum over the maximum available, so the save refuses what would break it — a section with no questions, a question with one answer, answers that all score the same, a non-numeric score — and names the question at fault. The shipped questionnaire stays the default and nothing is migrated, and **Back to standard** returns it. Covered by PrequalificationCatalogueTest (14 tests).

- [ ] **Not yet** — SIR-000030, Meeting. Points 1, 3, 4 and 5 are done: the Meeting.docx compliance suites found nothing new; organiser, chairperson and coordinator are now type-or-pick against the real contact list and the duplicate free-text "Client name" box is gone; Schedule & Location now sits above Participants; and the DECISION / ISSUES RAISED flow was walked end to end with dummy data and left behind as MeetingDecisionAndIssueFlowTest (11 tests), which holds down the one thing that would have quietly lost somebody's work — re-saving the meeting form corrects the *wording* of a decision or an issue without dragging its *status* back. **Point 2 is not done.** "Why is meeting inside purchase vendor?" asks for a structural move, and that has not happened. This issue should not close until it has. *One judgement call on point 3:* the Customer picker was kept, optional, because it feeds the §13 distribution list; if you meant for both customer fields to go, say so and it is a one-line change.

### Helpdesk

- [ ] **Not yet** — SIR-000002, Mismatch. Five numbered points. Point 1 (cannot click in the popup) and point 4 (multiple image attachment) are already fixed and on master. New but uncommitted: **Due by** on the New Ticket form (point 2) — the column and the request had accepted `due_date` all along and only the form never asked for it — and **Public link & embed** in the ticket queue header (point 3), going to the widget page so the embed code is reachable from where tickets are worked rather than only from settings. **Read point 2 again before this closes.** It asks for a new ticket field referenced against the old CRM and Freshdesk/Zoho; what exists is one date field. If that is what you wanted, this is finished once it deploys. If you wanted the field set matched against those systems, it is not, and this is the one issue in this file where I am guessing at the scope.

---

## Not ticked — with QA, not with engineering

- [ ] **Not yet** — SIR-000017, Assign Developer. The picker is built and the issue sits at `ready_for_qa`. A developer is assigned from the transition bar on the case: one owner plus any number of co-assignees, because every workflow guard is written against a single owner. It needs a QA verdict, not a code change, and closing it from here would skip the pass it is waiting for.

---

## Two things about the server, not about the code

**Every "full size" evidence link in your brief points at `http://localhost:5173`,**
so they are dead for anyone who opens the document. The embedded screenshots are
fine; only the links are broken. That is `FRONTEND_URL` in the production `.env`:

```
FRONTEND_URL=https://<your live frontend host>
```

then `php artisan config:cache`. This is an environment change, not a code
change, so no deploy fixes it on its own.

**Your export came down with scope `open`, which is not the same question as
"what still needs fixing".** `open` means not-closed, so an issue that has been
fixed, passed QA, shipped and been validated in production is still open —
correctly, because somebody still owes it a close. SIR-000017 in your file is
exactly that. The export panel now asks which you want and says which it is
using, and defaults to the narrower one. That change is on master and needs a
deploy to reach you.
