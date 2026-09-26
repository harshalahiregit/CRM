# SIRE — issue brief, sent back (after the deploy)

> ## ⚠ DO NOT UPLOAD THIS FILE UNTIL MASTER IS DEPLOYED
>
> The eight ticked issues below are fixed and on `master` at `ff1bb353`. They
> are **not on the live server** until you deploy. Uploading this before then
> closes eight issues against fixes nobody can use, and they come straight back.
>
> **Deploy first. Then upload this file.** Nothing else about it needs changing.

Written against the 17-issue export of 23 Sep 07:52, minus the six already
closed. Eleven issues remain open; this closes eight of them.

Every tick was checked in the code, not in a commit message. Three are left
unticked with the reason — one needs thirty seconds on the running site, one is
waiting on an answer from you, and one belongs to QA.

---

## Ticked — fixed and on master, closes once deployed

### SIR-000027 — Vendor Code

- [x] **Done** — `purchase_vendor` has been a configurable type in Settings → Numbering the whole time (prefix, width, date parts, reset rule) and the generator hard-coded PV-0001 and read none of it, so the screen saved a setting nothing consumed; it reads it now, and workspaces that never turned numbering on keep PV-0001 because that type throws when disabled and leaving it unhandled would turn "create a vendor" into an error for everyone who never visited the screen. Covered by VendorCodeFollowsNumberingTest, 8 tests.

### SIR-000028 — Vendor Prequalification

- [x] **Done** — Purchase Settings → Prequalification now edits the questionnaire itself: sections, questions, answers and the score each answer carries, so the drop-downs you were asking about are set from the admin panel instead of living in 280 lines of config PHP that only a developer with a deploy could change. Covered by PrequalificationCatalogueTest, 14 tests.

### SIR-000029 — Prequalification

- [x] **Done** — Same fix as SIR-000028, because they are one issue: the admin sets the questions and the answers and their scores; the score is a sum over the maximum available, so the save refuses what would break it (a section with no questions, a question with one answer, answers that all score the same, a non-numeric score) and names the question at fault, the shipped questionnaire stays the default with nothing migrated, and Back to standard returns it.

### SIR-000030 — Meeting

- [x] **Done** — All five points. (1) The Meeting.docx compliance suites were re-run and found nothing new — 337 meeting and kickoff tests pass, 1325 assertions. (2) Meetings is its own module at /app/meetings with its own sidebar entry, and it is now one you can stay inside: every link on the three shared screens was built from a resolver that tested only for /app/purchase and fell through to TPV, so all twenty of them threw you into TPV the moment you clicked, and the cross-meeting registers had no route on the neutral path at all — one path table now decides, and a check enforces that a link built in a module leads back into it. (3) Organiser, Chairperson and Coordinator are type-or-pick against the real contact list, and BOTH the Customer and Client Name boxes are gone — the customer link that feeds the §13 distribution list is derived from the project, which already knows its customer. (4) Schedule & Location now sits above Participants. (5) The DECISION and ISSUES RAISED flow was walked end to end with dummy data and left behind as MeetingDecisionAndIssueFlowTest, 11 tests — a decision is content and runs Active → Superseded/Rescinded, an issue is a record and runs Open → In Progress → Resolved → Closed with Reopened and Cancelled, moves off that map are refused, both read across every meeting from Meetings → Registers, and re-saving the form corrects the wording of either without touching its status.

### SIR-000037 — Expenses Form

- [x] **Done** — The form asked for four fields and the expense register needs a category, a reference number, a payment mode, a currency, tax and the receipt itself; the columns are there, the receipt uploads and downloads, the CSV export carries the new columns, and the expense_categories master that had been sitting in the database with nothing reading it is now what feeds the category. Covered by ProjectExpenseFormTest, 11 tests.

### SIR-000039 — Member Add

- [x] **Done** — A Members card on the project dashboard adds and removes people in place — your staff, and named people inside a vendor or a TPV company — instead of reopening the whole edit drawer to change one field.

### SIR-000040 — Upload Milestone

- [x] **Done** — An Import sheet button on the Milestones tab reading csv/txt/xls/xlsx/zip through the same reader the workforce import uses, where each row reports its own error rather than the sheet failing whole and a row with no due date is named in the result, because that column is NOT NULL and the alternative is a silent half-import. Covered by MilestoneImportTest, 10 tests.

### SIR-000042 — Assign Members

- [x] **Done** — Assign on the row, in the project's task table, several people at once.

---

## Not ticked — thirty seconds on the running site first

### SIR-000013 — PPE issues

- [ ] **Check first** — The PPE step could only ever *return* kit and show what was already held, so "PPE list is not showing to select" was literally true; the catalogue renders there now, out-of-stock items are shown disabled rather than hidden (because "the helmet is not on the list" and "the helmet has run out" are different problems) and issuing more than is held warns first. **Two things were true when this was last looked at, and only one of them is still true.** The picker built in an earlier pass went into PurchaseWorkerDetail, which nothing renders — that is fixed, the catalogue is now in PurchaseWorkerWizard, which is what `/app/purchase/workers/:id` actually loads. But the **422 on induction** is still not patched directly: it was the step after the one that could never be completed, so it may simply have been a consequence. Issue a kit on the running site, then run the induction. If it completes, tick this and re-upload.

---

## Not ticked — waiting on an answer from you

### SIR-000002 — Mismatch

- [ ] **One question** — Points 1 (cannot click in the popup) and 4 (multiple image attachment) were fixed earlier. Point 3, **Public link & embed**, is now in the ticket queue header and goes to the widget page, so the embed code is reachable from where tickets are worked rather than only from settings. Point 2 is the open one: it asks for *"a new ticket field — ref old crm and freshdesk/zoho"*, and what has been added is one field, **Due by** on the New Ticket form — the column and the request had accepted `due_date` all along and only the form never asked for it. **If Due by is the field you meant, say so and this closes.** If you meant the whole field set matched against those systems, tell me which fields and it is a straightforward piece of work — I would rather ask than close this on a guess.

---

## Not ticked — QA's call, not engineering's

### SIR-000017 — Assign Developer

- [ ] **With QA** — The picker is built and the issue sits at `ready_for_qa`. A developer is assigned from the transition bar on the case: one owner plus any number of co-assignees, because every workflow guard is written against a single owner. It needs a QA verdict, not a code change, and closing it from here would skip the pass it is waiting for.

---

## Still to fix on the server itself

Every "full size" evidence link in these briefs points at
`http://localhost:5173`, so they are dead for anyone who opens the document.
The embedded screenshots are fine; only the links are broken. That is
`FRONTEND_URL` in the production `.env`:

```
FRONTEND_URL=https://<your live frontend host>
```

then `php artisan config:cache`. An environment change, not a code change — no
deploy fixes it on its own.
