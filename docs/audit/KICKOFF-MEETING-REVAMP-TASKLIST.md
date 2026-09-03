# Kickoff Meeting Revamp — Task List

Source: Shivam's 11-point brief on the kickoff meeting scheduling flow.
Scope: **SHARED** kickoff engine primarily (the live scheduler); Purchase mirror
noted where the same change applies. Point 7 (in-page meeting + in-dashboard
signature/location/device capture) is **DEFERRED** by explicit instruction.

**STATUS: COMPLETE (TPV + Purchase parity).** All 11 points done (point 7 deferred).
Post-review gap-closures done, and the whole set mirrored to the Purchase engine.

### Gap-closure pass 2 (after "what's missing" review)
- [x] **#1 welcome-on-add** now fires immediately when an admin adds a vendor (TPV + Purchase) — `onCredentialsIssued` wired into both `VendorService::create` and `PurchaseVendorService::create`, with `welcome_credentials` blades for both.
- [x] **#4b** agenda Discussion/Decision are now the same rich editor as the Minutes Description with more space; all agenda sub-fields (Previous discussion ref, Supporting documents) + Issue title labelled.
- [x] **#9 multiple everywhere** — per-action **evidence** is now multiple + labelled too (documents table gained an action link; action row has a multi-upload). The approved-minutes doc stays single by design (it is the one artifact that gets approved).
- [x] **#11** portal **Join meeting** button added.
- [x] **#3 reminders** confirmed: fire *before* the meeting (24h + 1h) — matches "reminder of the meeting".

### Purchase parity (full mirror) — DONE
- [x] Draft/Publish (`PurchaseKickoffStatus::DRAFT`), computed duration, mandatory non-past start/end, publish sends invitations, edit re-notifies.
- [x] Auto reminders: `reminders_sent` column + `runDueReminders()` + `purchase-kickoff:send-reminders` command (every 15 min).
- [x] Acknowledgement removed (`publishForAck`→`distributeMom`, ack routes/accept endpoints gone); portal MoM gated on approval + drafts hidden; vendor notified.
- [x] Multiple labelled documents (`purchase_kickoff_documents` table + model + service + admin endpoints + portal download + create/detail UI + Join button).
- [x] Create form: computed duration, non-past, save-as-draft, meeting_link; detail: Publish button + "Send minutes to vendor" + Documents card.
- [x] Welcome-on-add credentials for Purchase vendors.
- Tests: `PurchaseMeetingsFlowTest`, `PurchaseMeetingsExtendedFlowTest`, `PurchaseKickoffDocumentsTest` all green; 152 Purchase tests pass.

### Gap-closure pass 3 ("I don't want any gap")
- [x] **Responsiveness:** every kickoff create + detail grid is now fluid (`repeat(auto-fit, minmax(...))`) — side-by-side editors and multi-column rows stack on narrow screens. TPV + Purchase.
- [x] **Long-string overflow:** fixed in the rich editor + rendered content (`overflow-wrap:anywhere`) AND in the generated **MoM PDF** (both TPV + Purchase Blades).
- [x] **Invite mandatory to all:** manual participants now require an email (both create pages); TPV requires email-or-linked-Sangoe-user so everyone is reachable.
- [x] **Login-before-activation:** verified — an admin-added vendor's login is `inactive`, which passes the login guard, so the welcome creds work. Self-registered vendors stay `pending` until activation (existing, correct — they can't log in while pending, so welcome-at-activation is right).
- [x] **Reminders "one or two":** two config offsets (24h + 1h) via `meetings.reminder_offsets_minutes`.
- [x] **Purchase agenda:** enlarged to a notepad-style field (parity with TPV).
- [x] Verified: full `vite build` compiles the whole app; `route:list` loads (114 kickoff routes); 70 touched tests pass.
- Note: not headless-clicked screen-by-screen (no automated browser here); everything is compiled + route-loaded + tested.

### Not mine — pre-existing failures in the working tree (flagged, untouched)
- Medical `latestOfMany('exam_date')` → ambiguous `tpv_worker_id` (blocks `/tpv/workers`); Harshal's in-progress medical work.
- `2026_12_11 purchase_vendor_notifications` migration: composite index name > 64 chars (MySQL) — earlier work.
- SangoeTrack tests need a live host; banned-pattern baseline is stale from the broader uncommitted tree.

Legend: `[ ]` todo · `[~]` in progress · `[x]` done · `[-]` decided N/A (with reason)

---

## 1. Welcome email includes login credentials — DONE
- [x] Welcome/activation email now labels the **Login ID (email)** explicitly and shows the system-generated **password** (admin-created case, already worked).
- [x] Self-registered vendors get a "Signing in" block: use the login ID + the password you chose (+ Forgot-password hint) — we never stored a plaintext to show.
- Note: the credential email fires at **activation** (which is when the login actually works), for both TPV + Purchase.
- Files: `resources/views/emails/tpv/activation.blade.php`, `resources/views/emails/purchase/activation.blade.php`.

## 2. Participant selection — DONE (except manual-participant polish)
- [x] All vendors load in the selector — picker now calls `vendors.list({ engagement: '' })` (empty skips the JSON filter).
- [ ] Manual "Add participant" already exists — polish/prominence pending (fold into #4b UI batch).
- [x] Invite email sent to the whole roster on Publish (Draft→Scheduled transition sends invitations).
- [x] Auto reminders: `reminders_sent` column + `runDueReminders()` (24h & 1h, config-driven) + `kickoff:send-reminders` command every 15 min. Migration applied, command smoke-tested, uses tenant SMTP.
- Files: `KickoffMeetingCreate.jsx`, `KickoffMeetingService.php`, `config/meetings.php`, `Console/Commands/SendKickoffReminders.php`, `routes/console.php`, migration `..._add_reminders_sent_to_kickoff_meetings`.

## 3. Scheduling logic (draft/publish, duration, time)  — BACKEND+FRONTEND DONE (shared)
- [x] Add `Draft` status to `KickoffStatus` (+ transitions Draft→Scheduled, helpers isDraft/isPublished).
- [x] `schedule()` creates as **Draft**; no invites at create.
- [x] **Publish** = Draft→Scheduled transition; sends invitations on publish (reminders read live time; link delivered via invite mail). *Dashboard popup for link → point 11 batch.*
- [x] **Removed manual duration**; `computeDuration()` derives it from start+end (create + update); UI shows it read-only.
- [x] `scheduled_at` + `end_at` **mandatory**; end after start (Store required; Update sometimes-required).
- [x] **No past date/time**: Store rejects past start (2-min grace); create UI blocks past date + past time-on-today; end>start guard.
- [x] Fixed edit-time bug: edit mode drops the date `min` floor; Update only blocks a past time when the start is actually MOVED.
- [x] On edit-after-publish: `update()` re-sends invitations when a published meeting's time/place/roster changes.
- [ ] Purchase mirror (follow-up).
- Files: `Support/Shared/KickoffStatus.php`, `Store|UpdateKickoffMeetingRequest.php`, `KickoffMeetingService.php`, `KickoffMeetingCreate.jsx`, `KickoffMeetingDetail.jsx`, `kickoffConstants.js`.
- ⚠️ Follow-up: ensure **Draft meetings are hidden from the vendor portal** (don't leak un-published drafts) — fold into #8.

## 4a. Live vendor status — DONE
- [x] Added `has_history` to the live-status snapshot (true when the vendor has prior meetings); card now hidden on a first meeting.
- Files: `VendorLiveStatusService.php`, `KickoffMeetingCreate.jsx`.

## 4b. Kickoff template + fields UI — DONE (core)
- [x] Agenda builder now has a labelled column header; discussion/decision boxes labelled + enlarged (rows 4, minHeight 88).
- [x] MoM Description enlarged (minHeight 180) and Remarks (140) — long text stays readable; decision title now labelled.
- [x] Notepad: the free-text box relabelled "Notepad / agenda notes" and enlarged (rows 8, minHeight 160).
- [x] Templates load via existing "Load template" button (verified path intact).
- Files: `KickoffMeetingCreate.jsx`.

## 5. Carry forward — DONE
- [x] Whole carry-forward card hidden unless `vendorStatus.has_history` (a first meeting has nothing to carry).
- Files: `KickoffMeetingCreate.jsx`.

## 6. MoM items — agenda & depends-on dropdowns — DONE
- [x] Agenda dropdown: instructive empty-state ("Add agenda items above first") when no agenda rows exist (MoM + decisions).
- [x] Depends-on: instructive empty-state ("Add another action to link one").
- [x] client_key mapping already resolves (verified by passing KickoffEnhancements MoM tests).
- Files: `KickoffMeetingCreate.jsx`.

## 7. In-page meeting (DEFERRED)
- [-] Deferred by instruction — later.

## 8. MoM visible to vendor only after admin approval — DONE (TPV)
- [x] Portal `meetingMom()` now 403s unless `mom_status ∈ {Approved, Distributed}`; list exposes a `mom_available` flag and hides `mom_path` until then.
- [x] Draft meetings hidden from the vendor portal entirely (folds in the Draft-leak follow-up from #3).
- [x] Vendor notified on distribute — email (minutes inline) + in-app popup to the vendor's portal login.
- [ ] Purchase mirror (follow-up).
- Files: `VendorPortalGovernanceController.php`, `KickoffMeetingService.php` (distributeMom + sendMomNotifications).

## 9. Document upload (multiple + labeled) & vendor MoM display — DONE (TPV)
- [x] New `kickoff_meeting_documents` table + model + relation; multiple-file upload, each with its own **label**.
- [x] Admin endpoints: list / upload (multiple) / download / delete; a "Documents" card on the detail page (stage files, name each, upload, download, delete).
- [x] Vendor sees documents only after approval+distribution (same gate as #8); portal MoM view shows **structured info + Download buttons** (never a raw embedded PDF).
- [x] Tests: `KickoffDocumentsTest` (upload/list/delete + label fallback). Migration applied.
- [ ] Purchase mirror + per-agenda/evidence multi-upload (follow-up).
- Files: migration, `KickoffMeetingDocument.php`, `KickoffMeeting.php`, `KickoffMeetingService.php`, `KickoffMeetingController.php`, `VendorPortalGovernanceController.php`, `routes/shared.php`, `routes/portal.php`, `KickoffMeetingDetail.jsx`, `GovernanceTabs.jsx`, `kickoffApi.js`, `portalApi.js`.

## 10. Remove vendor acknowledgement — DONE (TPV)
- [x] Removed public ack routes; deleted `PublicKickoffController`, `KickoffAck.jsx`, `KickoffMom.jsx`, `kickoffAckApi.js` + their frontend routes.
- [x] `publishForAck` → `distributeMom` (no token, no 48h window); removed ack URL + public read links from MoM notifications.
- [x] Detail "Vendor acknowledgement" card → "Send minutes to vendor" (distribute); removed ack badges + list columns (Acknowledgement, Response).
- [x] Tests updated: ack tests → distribution tests; deleted `PublicMomViewTest`; publish helpers publish drafts. **181 tests pass.**
- Note: ack DB columns left in place (unused, no migration) to avoid a risky teardown; a few dead service methods (`acknowledge`, `resolveByToken`, `markMomViewed`) remain but are unreachable.
- [ ] Purchase mirror (follow-up).

## 11. Meeting link delivery (email + dashboard popup) — DONE
- [x] On Publish, invitations send the join link by **email** and an **in-app notification** to every attendee with a Sangoe login (dashboard bell).
- [x] Vendor portal meetings list now shows a green **"Join meeting"** button for online meetings (`meeting_link` exposed to the portal).
- Files: `GovernanceTabs.jsx`, `VendorPortalGovernanceController.php`.

## Gap-closure pass (after review)
- [x] **#1 welcome-on-add:** new `emails/tpv/welcome_credentials.blade.php` + `TpvActivationNotifier::onCredentialsIssued()`, sent from `VendorService::create` the moment an admin adds a vendor (login ID + password + portal link).
- [x] **#4b labels:** Issue title labelled (participant rows were already labelled).
- [x] **#11:** portal Join button (above).
- Note: **#9** — the new Documents card gives multiple labelled uploads on the meeting; the single MoM-approval doc and per-action evidence stay single by design (one approved minutes / one evidence per action).
- Pre-existing (NOT this work): `GET /tpv/workers` 500s with `ambiguous column tpv_worker_id` from the in-progress medical-history feature's `latestOfMany` relation — flagged, not fixed (belongs to that work).

## 11. Meeting link delivery (email + dashboard popup)
- [ ] On Publish of an online meeting, deliver the join link via email AND an in-app dashboard popup/notification to every participant with a Sangoe identity.
- Files: `MeetingInviteService.php`, notification service, portal/dashboard notification surfaces.

---

### Judgment calls made (confirm if wrong)
- **4a / 5:** hide (not delete) live-vendor-status and carry-forward when there's no prior data, so they still serve recurring meetings.
- **Reminders:** default to two auto reminders (e.g. 24h and 1h before) unless told otherwise.
- **Point 1:** self-registered vendors keep their chosen password (we can't email a plaintext we never stored); email carries login ID + a reset path if needed.
