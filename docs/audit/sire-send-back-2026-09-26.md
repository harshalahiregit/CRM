# SIRE — issue brief, sent back

Written against the 17-issue export of Sat 26 Sep 2026, 05:20.

**11 ticked, 6 left open with the reason.** Every tick was checked in the code,
not in a commit message. Two of the ticks are issues that were **already fixed**
before this pass and are ticked as verified, not as newly written — the detail
under each says which.

The six left open are open for four different reasons, and the reasons matter
more than the count:

- **two need a server change I cannot make from a code editor** (SIR-000061,
  SIR-000062) — the code half is done and on the branch;
- **two are HR-module attendance** (SIR-000043, SIR-000049) — diagnosed, fix
  written, but HR is not my module and this needs its owner;
- **two are feature builds, not defects** (SIR-000033, SIR-000052) — scoped at
  the bottom.

> **Do not tick the six open ones to clear the board.** Ticking closes the issue
> and they will come straight back. The two waiting on server configuration in
> particular will look fixed from the code and still be broken in the inbox.

---

## Ticked — fixed

### SIR-000046 — Checklist

- [x] **Done** — The checklist could only be added to and ticked, so a typo stayed a typo and a line added by mistake could only be dealt with by ticking it, which records "we did this" rather than "never mind" and leaves a false account of the work behind. Each line now edits in place (save/cancel, Enter and Escape) and can be removed outright via a new `DELETE /api/tasks/checklist/{item}`; the delete takes the line's assignee rows with it, and the tenant check runs **before** the delete rather than after, because checking afterwards would already have destroyed the row it was meant to protect. Renaming deliberately leaves the people on the line alone — `assigned_to` absent means "do not touch", so fixing a spelling can never silently unassign somebody. Covered by ChecklistItemEditAndDeleteTest, 5 tests.

### SIR-000047 — Back to Task Button

- [x] **Done** — **This was a regression, and the register was right about it.** The button shipped on 10 Jul 2026 (`cd992f80`) as `navigate(-1)` labelled "Back" — real history navigation. On 15 Jul 2026 (`b01c064f`) it was replaced with `navigate('/app/tasks')` and relabelled "Back to tasks", so from then on every route out of a task — the board, a project, a ticket, a search, a notification — led to the global task list instead of where you had been. The first attempt at a fix in this pass made it worse in shape if not in effect: it taught the button the way back from the project task table specifically, which only ever knows the routes somebody remembered to add, and every new screen linking to a task goes back to being wrong. That is how this regressed in the first place. It now does what it originally did: if there is history behind the page it goes back to it, whatever it was. Where there is none — a pasted link, a new tab, a link from an email — `history.state.idx` is 0 and `navigate(-1)` would either do nothing or throw the user out of the application, so only that case resolves a destination: the task's own project if it has one, else the list. The behaviour lives in one shared hook rather than in each page, because a destination hard-coded per screen is exactly what the next edit can quietly get wrong again. The same fault and the same fix apply to the project page, whose back arrow always went to /app/projects.

### SIR-000048 — Edit/Delete/Copy

- [x] **Done** — An Actions column on the project's task table: Edit opens the task form in place, Copy duplicates keeping the project link (a duplicate of a project task belongs to the same project, not loose in the global list), Delete confirms first and says the task goes to Tasks → Trash and can be restored. Previously the table could open a task and change its status and nothing else, so the three things you do most to a row were the three the row did not offer.

### SIR-000059 — Text Visibility

- [x] **Done** — Stored rich text keeps whatever the author pasted, and Word, Google Docs, Outlook and the editor's own colour picker all write an explicit near-black onto the span. That is correct on the white page it came from and invisible on a dark card. The text is not wrong and must not be rewritten on save — the same row is read in both themes, and in light mode that black is right — so the theme overrides it at render time instead, for the handful of values that actually mean "default body text". A deliberate red or green still comes through, because losing an author's highlight is its own bug. The mirror case (white pasted onto the light theme) is handled the same way. Applies to task descriptions and comments and to every other rich-text surface in the product, since the rule lives with the shared styles.

### SIR-000044 — Recent activity

- [x] **Done** — Two questions were being answered as one: being *entitled* to the company's activity is not the same as *asking* for it. The feed widened itself the moment somebody had the report scope, so the first screen after login showed everyone's actions, unasked, with no way back to your own. The entitlement now only decides whether "Everyone" is offered at all; the request decides what is shown, and it shows your own work unless you say otherwise. A Mine / Everyone toggle appears only for a reader the server says may widen it, so it is never a control that does nothing, and the empty state says which of the two you are looking at.

### SIR-000053 — Last login location

- [x] **Done** — "📍 Pune, Maharashtra · Chrome" was two string literals on the login page. Every visitor, in any city, on any browser, was told the same thing — and this is the one place in the product where a wrong location actually matters, because "was that me?" is the question a last-login line exists to answer. It also cannot be made true there: nobody has identified themselves yet on a login screen, so the server does not know whose last login to report and should not be guessing from an unauthenticated request. The line is removed from the login page, and a real one now sits on the dashboard after sign-in, built from the previous session's own record: time, browser, device and IP. **Deliberately no city** — turning an IP into a place needs a geo-IP service this deployment does not have, and guessing one is exactly how "Pune" got there. It shows nothing at all on a first-ever sign-in rather than an empty shell.

### SIR-000011 — Port Issue

- [x] **Done** — "Sometimes" was the clue: the widget was fine, the route→context map had gaps, and a page with no entry silently reports a blank section and screen. Eight reportable pages had no entry — five HR configuration screens, the meetings register, and Fleet's trailer and tyre registers. Worse, `/app/meetings/registers` was matching the `/app/meetings/:id` pattern and reporting a meeting whose id was the word "registers" — a confidently wrong record, which is worse for whoever picks the issue up than an empty one. All are mapped now, in both the live map and the package copy so a reinstall cannot regress it. The audit tool reads **342 of 342 reportable pages resolved exactly, 0 missing, 0 unresolved, 0 records not detected** — it was 326 exact / 8 by prefix / 8 missing.

### SIR-000060 — Vendor Code

- [x] **Done** — The prefix, width, date parts and reset rule for vendor codes **have been configurable all along**, under Settings → Document Numbering, as one format among thirty-odd in a single list with nothing on the vendor screen pointing at it. This was a findability problem, not a missing feature, so the answer is a link where the codes are rather than a second settings screen: a **Code format** button on the Purchase Vendors page that opens Document Numbering already on the vendor-code format. The settings page now accepts `?type=` so any module that owns a number can link to its own format the same way.

### SIR-000050 — Vendor Onboarding

- [x] **Done** — Akmal received nothing because nothing was ever sent. Both self-registration paths — TPV and Purchase — created their records, wrote a log line and told the vendor nothing at all; the form said "Awaiting admin approval" on a page the vendor then closed, and after that there was silence for as long as approval took. A vendor in that position either registers again, which is refused because the email is already taken, or telephones somebody. Two messages now go out: to the vendor, that it arrived, their reference, the three things that happen next, and **not to register a second time**; and to the workspace's admins, that somebody is waiting on them — without which a self-registration was only discoverable by noticing a new Draft row in a list. Both send after commit, and neither can break a registration: a vendor whose record was created must never be told it failed because an SMTP host was slow, so every send is wrapped and a failure is logged instead of thrown.

---

## Ticked — verified as already fixed

These two were reported against behaviour that has since been corrected. Nothing
new was written for them; they were checked in the code and are ticked so they
stop occupying the board.

### SIR-000002 — Mismatch

- [x] **Done** — All four actionable points are in place, checked individually. (1) *"Report Issue" unable to click in pop-up* — the modal has to clear every overlay in the app including the one it was opened from; the button sits above HR dialogs, the select portal and the toasts, and a stacking rule now lifts its own backdrop above that, so clicking the button no longer opens a form behind the popup you were reporting. (2) *New ticket fields vs Freshdesk/Zoho* — the form carries Subject, Contact, requester name and email, Department, CC, Project, Tags, Assignee, Priority, Status, **Due by**, Service, a rich-text body, attachments, canned replies and KB links. (3) *Public link and embed code* — both are on Settings → Widget, with copy buttons: an embed snippet for anyone who can edit a page's HTML, and a public support link for anyone who cannot. (4) *Multiple image attachment* — the Report Issue attachment field takes multiple images, caps the count, uploads them in parallel and reports partial failures rather than silently dropping them.

### SIR-000013 — PPE issues

- [x] **Done** — Two separate things were reported as one, and both are now correct. The PPE step genuinely could only *return* kit and show what was already held, so "PPE list is not showing to select" was literally true — no list was rendered. The catalogue now renders in the worker wizard, which is what `/app/purchase/workers/:id` actually loads, with issuing, returns and a compliance read from the PPE ledger. The **422 on induction is not a fault**: it is the medical-clearance gate refusing a safety induction for a worker without clearance, with the reason in the message, and the wizard already shows that state and a "what to do" line on the worker's progress panel before you reach the step. If you want induction to proceed without clearance that is a policy change in Purchase → Medical settings (`block_induction`), not a bug fix.

---

## Not ticked — the code is done, the server is not

Both of these have one root cause and it is not in the application: **`FRONTEND_URL`
is not set on the server**, so every link the deployment builds resolves to
`http://localhost:5173`.

> **Action for whoever owns the deployment:** set `FRONTEND_URL` (or `APP_URL`)
> to the public address of the React app, then run `php artisan config:clear`.
> Re-send one vendor invitation and confirm the button opens the real site.
> Tick these two after that, not before.

### SIR-000062 — Email Template

- [ ] **Blocked on server configuration** — The broken "Login to the portal" button and the localhost link underneath it are the same fault seen twice: `$portalUrl` resolves to `http://localhost:5173/auth/login`, so the button lands on the recipient's own machine and the same dead address is printed below it as the paste-this-link fallback. From the sending side everything looked delivered. Two code changes are on the branch: the URL resolver now logs a loud, explicit error the first time it falls back to localhost outside a developer machine, and the vendor credential email **refuses to send at all** in that state rather than mailing a supplier a dead login link and a temporary password they have nowhere to use — the credentials stay on the vendor record, so nothing is lost by resending once the URL is configured. Neither change can invent the public domain; that is the one thing only the deploy knows.

### SIR-000061 — Spam Email

- [ ] **Partly fixed; the rest is DNS** — Two real code-side causes were found and fixed. First, the SMTP greeting: with `APP_URL` unset the application introduced itself to every receiving server as `EHLO localhost`, a name that resolves nowhere, from an address claiming to be a real company — one of the cheapest spam signals there is to emit. It now greets with the sending address's own domain, so the greeting, the envelope and the visible From all agree and sit under the same SPF and DKIM. Second, messages that went out as HTML with no plain-text alternative, which is an old and heavily weighted spam signal; every message now leaves as multipart/alternative, with a readable text rendering generated where the caller did not supply one (links keep their destination, table rows keep their labels). **What remains is not code.** Deliverability needs SPF, DKIM and DMARC published for the sending domain, and the From address must be on that domain. Until those records exist, mail will keep landing in Junk however the application behaves. Please confirm the DNS side, then re-test and tick.

---

## Not ticked — HR module, needs its owner

### SIR-000043 — Attendance Issue

- [ ] **Diagnosed, not mine to ship** — 06:06 is 11:36 IST expressed in UTC, exactly. Storage is UTC by design and presentation converts per workspace, so a clock face that is a whole offset out means the conversion used the wrong zone. Three things were found: `localization.timezone` is nullable, so it can be stored blank, and a blank made the server fall through to UTC while the browser fell through to the reader's own clock — two halves disagreeing about one punch, each confident; the dashboard's attendance endpoint picked "today" by the **UTC** day, so an early IST shift was written to the previous day's row; and the two sides each converted the timestamp independently. Fixes are written for all three (blank resolves to the registry default, the tenant's day decides the row, and the server sends the already-converted clock face so the browser cannot disagree). **One thing remains that is a setting, not code:** if this workspace's Settings → Localization → Timezone is stored as `UTC`, both halves will correctly agree on 06:06. Please check that value first — if it reads UTC, set it to Asia/Kolkata and this resolves on its own.

### SIR-000049 — Clock in issue

- [ ] **Diagnosed, not mine to ship** — Nothing clocks anybody out automatically; this is a misclick trap, and a well-built one. On the dashboard card, **Clock in and Clock out were the same button** — same first position in the row, same size, same green fill. The instant you clocked in, `Clock in` disappeared and `Clock out` rendered in exactly the slot you had just clicked, so a second click, a double-click, or coming back to the dashboard and pressing the button you always press ended the shift minutes after it started. A fix is written: breaks come first while a shift is running, clock out is last and outlined in the danger colour rather than filled green, it confirms and names your start time, and the server refuses an unconfirmed clock-out within five minutes of a clock-in so the guard holds even if some other client forgets to ask.

> **Neither fix is on the branch.** Both touch HR files (`MyAttendanceCard`,
> `HeaderPunch`, `hrApi`, `MyAttendanceController`, `TenantTime`,
> `SettingsFormatter`), HR is not this developer's module, and the repo's
> module-isolation rule says that call belongs to HR's owner. The changes were
> written and building, then deliberately **reverted before pushing** so nothing
> crosses the boundary unreviewed. What is above is the diagnosis, which is the
> part worth keeping — the fix itself is half an hour's work for whoever owns HR,
> and the SIR-000043 timezone setting may not need code at all.

---

## Not ticked — feature builds, not defects

Both of these are real requests and neither is a bug. They are sized here so the
decision can be made on what they actually cost.

### SIR-000033 — Tutorial KB

- [ ] **Feature, not built** — There is no tutorial or guided-tour system anywhere in the product; the only thing resembling one is a getting-started panel inside the vendor portal. What is being asked for is two things: a reusable guide framework (auto pop-up per section, "hide", "mark complete", remembered **per user on the server** so it does not reappear on their next device), and then guide content written for every module. The framework is perhaps a day, including the per-user persistence that makes "mark complete" mean anything. The content is not a development task at all — it is 25-odd modules of writing, one data entry each, best done by whoever owns each module. Splitting it that way is the only version of this that finishes. Marked Performance/S2 on the register, which undersells it.

### SIR-000052 — Vendor dashboard issue

- [ ] **Feature, not built** — The vendor portal is read-only for invoices: a vendor can see invoices raised *to* them and cannot submit one. Confirmed in the routes — every portal invoice endpoint is a GET. Making a vendor able to raise an invoice is a build, not a fix: a submission model and migration, an upload-and-line-items form in the portal, an admin review and approval queue, notifications on both sides, and a decision about how a vendor-submitted invoice meets the existing three-way match. The `422 on POST /auth/register/vendor` attached to this ticket is unrelated and is not a fault — it is the registration form correctly refusing an email address already on file. Worth splitting into its own ticket with the commercial rules attached, because the hard part is the approval policy, not the form.

---

## Sending this back

Upload this file on the issue register. The eleven ticked issues close with the
line under each as the reason; the six unticked ones are left exactly as they
are, so re-uploading is safe.

**Verified before sending:** the frontend builds clean; the Task suite passes 69
tests; the new ChecklistItemEditAndDeleteTest passes 5, including a cross-tenant
case proving the delete is refused *before* the row is touched; the SIRE route
audit reports 342/342 resolved. Four pre-existing failures in the Transport
suite (`licence_valid_until`, a missing column) are untouched by this work.
