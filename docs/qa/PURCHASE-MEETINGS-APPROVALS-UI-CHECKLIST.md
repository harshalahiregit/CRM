# Purchase Meetings & Approvals — Manual UI Smoke Checklist

What the automated feature tests **cannot** cover is the React UI itself — that the
buttons are wired to the right API and the screens render the result. This is a
one-time click-through to confirm that, per actor. It follows the Sangoe TPV doc
flow (§9 Meetings, §12 Approvals).

**Prep**
- Run the app (backend + `npm run dev` frontend).
- Have two logins ready: an **admin** and a **staff** user in the same tenant.
- Have at least one **active Purchase vendor** (create one via Vendors if needed).

Tick each box. If any step doesn't match the "Expect", note it — that's a real UI bug.

---

## A. Meetings — MOM approval lifecycle (admin)  ·  doc §9

1. [ ] Purchase → **Meetings** → **Schedule meeting**. Pick the vendor, Meeting Type = *Kickoff*, set a date, add a participant, Save.
   **Expect:** lands on the meeting detail; status pill = **Scheduled**; MOM approval card shows **Draft**.
2. [ ] In the **Vendor acknowledgement** card, the *Send for acknowledgement* button is **disabled**, with a hint that the MOM must be generated and approved.
3. [ ] Header → **Mark completed** (add minutes). **Expect:** status pill = **Completed**.
4. [ ] **Minutes document** card → **Generate PDF** (or Upload). **Expect:** "MOM document ready".
5. [ ] **MOM approval** card → **Submit for approval**. **Expect:** status → **Pending Organizer**; the "Submitted" trail row ticks with your name + time.
6. [ ] **Approve (Organizer)**. **Expect:** → **Pending Chairperson**; "Organizer approved" row ticks.
7. [ ] **Approve (Chairperson)**. **Expect:** → **Approved**; "Chairperson approved" row ticks.
8. [ ] Vendor acknowledgement card → **Send for acknowledgement** is now **enabled**; click it. **Expect:** MOM approval shows **Distributed**; card says "Sent for acknowledgement".
9. [ ] (Return path) On another meeting, from **Pending Organizer** click **Return** with **no note** → refused; with a note → back to **Draft**, and a "Returned for revision" banner shows the reason.

## B. Meetings — Action items (admin)  ·  Rules 11 & 12

10. [ ] **Action items** card → **Add**. Leave owner blank, add a description, **Add action**.
    **Expect:** refused with a "needs an owner" message (Rule 11).
11. [ ] Add again with an owner (participant or typed name), a due date in the past. **Expect:** row appears with ref `ACT-…`, an **OVERDUE** flag.
12. [ ] Move it **In Progress** → **Pending Verification**. From Pending Verification click **Closed** with **no note/file** → refused (Rule 12).
13. [ ] Click **Closed** again, this time add a verification note and/or attach a file → **Closed**. **Expect:** if a file was attached, an **Evidence** link appears and opens the file.

## C. Meetings — Issues → NCR/CAPA (admin)  ·  §9

14. [ ] **Issues** card → **Add** an issue (title, severity, category). **Expect:** row with ref `ISS-…` and a status chip.
15. [ ] On that issue click **To NCR**. **Expect:** a green **NCR PNCR-…** badge appears on the issue; status moves to In Progress.
16. [ ] Go to Purchase → **NCR**. **Expect:** the new NCR is listed. (It also auto-raises a CAPA — check Purchase → **CAPA**.)
17. [ ] Back on the issue, the **To NCR / To CAPA** buttons are gone (already converted).

## D. Meetings — Decisions (admin)  ·  §9

18. [ ] **Decisions** card → **Add** a decision (text, decided-by, effective date). **Expect:** row with ref `DEC-…`, status **Active**.
19. [ ] Click **Mark Superseded** then **Mark Rescinded**. **Expect:** the status badge updates each time.

## E. Central Approval Register (admin)  ·  §12

20. [ ] Purchase → **Approvals**. **Expect:** KPI cards (Total/Pending/Approved/Rejected) and a type filter listing all 18 types.
21. [ ] **Raise approval** → pick a type (e.g. Contract), a vendor, priority, title → **Raise**.
    **Expect:** a row with ref `PAPR-…`, status **Pending**.
22. [ ] On that row click **Reject** with **no remarks** → refused; add a reason → status **Rejected**, decider + date shown.
23. [ ] Raise another and **Approve** it. **Expect:** status **Approved**; no decide buttons remain on a decided row.

## F. Role boundaries (log in as STAFF)

24. [ ] As **staff**, open Purchase → **Approvals**, raise an approval (allowed).
    **Expect:** you can raise and view, but the **Approve/Reject/Cancel** buttons are **not shown** on rows (deciding is admin-only). If they appear, that's a UI bug — the server will 403 either way.
25. [ ] As **staff**, open a Meeting and confirm you can manage actions/issues/decisions (staff are allowed here).

## G. Vendor portal (log in as the VENDOR)

26. [ ] In the Purchase Vendor Portal, after step 8 above, confirm the vendor can see/acknowledge the distributed minutes (onboarding Step 1 acknowledgement path).
    **Expect:** acknowledging reflects back on the admin meeting detail ("Acknowledged by …").

---

### Notes column
Record anything that didn't match. Common real bugs to watch for: a button that
does nothing (not wired), a list that doesn't refresh after an action, a status
badge that shows the raw value (`In_Progress`) instead of a label (`In Progress`),
or an action that should be hidden for staff but isn't.
