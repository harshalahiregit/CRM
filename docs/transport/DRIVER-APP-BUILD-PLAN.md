# Sangoé Driver — full build plan (v2: real app, not a prototype)

**Owner:** Shivam (Dev 2, Fleet/Telemetry). **App:** `driver-app/` (Expo/React
Native). **Backend:** STOS Fleet domain + driver registration/documents.

This is the tracker. Tick a box **only when it is really built and verified**,
not when it is started. Phases are ordered so each one ships something usable.
Items marked **[Dev 1]** need Raza's trip/milestone endpoints — build the UI
shell now, wire it when his API lands (send him the paste-ready message).

---

## 0. North star

A driver opens the app and it feels like a modern consumer app (the user's
reference: **Blinkit** — fast, seamless, responsive, beautiful, obvious). Big
tap targets, real spacing rhythm, motion that guides, clear state at every step,
works on any phone size, survives a dropped signal. It guides the driver through
the **14 operational stages / 24 screens** of a trip, and shows a driver only
their own active trip — never company financials, rates, or salaries.

**Guardrails that must always hold**
- A driver sees only their assigned active trip's data. No margins, no rates, no
  other drivers, no salary data.
- Suspended/inactive/unapproved drivers cannot start a trip.
- Cold-chain rule: `MOVING + GENERATOR OFF + TEMPERATURE RISK` → high-priority
  exception + loud in-app alert.
- Offline-safe: queue milestones/photos/GPS/POD locally, sync idempotently on
  reconnect — never duplicate a trip or overwrite server state.

---

## 1. What exists today (baseline)

- MVP shell: Login, Trips list, Trip detail, POD capture (camera/gallery).
- Driver self-registration → admin approval → login.
- Points at the live server by default; self-hosted OTA updates.
- Basic responsive pass (status-bar inset + width scaling).

**Gap:** it still looks and feels like a prototype. This plan closes that.

---

## PHASE A — Design system & responsive foundation  *(Dev 2, no blockers)*

The thing that turns "prototype" into "real app". Everything else is built on it.

- [x] A1. Design tokens (`src/theme.js`): color roles, 4pt spacing, radius,
  elevation/shadow, typography scale.
- [x] A2. Responsive engine (`src/responsive.js`): safe-area top **and** bottom
  insets, width scaling clamp, breakpoints, content max-width + centering. One
  `useLayout()` hook, used everywhere.
- [x] A3. Shared UI primitives (`src/ui.js`): `Screen`, `AppBar`, `Button`
  (primary/secondary/ghost/danger + loading + pressed), `Card`, `Field` (focus
  ring, hint, error), `Pill`, `SectionLabel`, `EmptyState`, `Loading`, `Divider`.
- [~] A4. Motion: press feedback + pull-to-refresh done; screen transitions and
  skeletons still to add.
- [ ] A5. Iconography + brand: consistent icon set, splash, app icon, empty-state
  art. (Using emoji placeholders today.)
- [x] A6. Re-skinned Login, Register, Trips (home) and Trip detail on the system.

---

## PHASE B — Onboarding, identity & approval  *(Dev 2)*

- [ ] B1. Login polish: remembers server, shows friendly errors, blocks
  suspended/inactive/unapproved drivers with a clear message.
- [ ] B2. Register screen polish (multi-step, validation inline, progress).
- [x] **B3. Approval email with credentials** — on approve, a branded email goes
  to the driver via tenant SMTP (never `.env`), best-effort (approval never fails
  on a mail problem). Says to sign in with the password they chose — we never
  store/send the plaintext. `DriverRegistrationService::sendApprovalEmail`.
- [~] B4. Eligibility gate: the app now shows cleared/not-cleared and what is
  blocking (from `DriverSelfService::eligibility`). Still to do: actually block
  trip actions (accept/start) until eligible — waits on the journey screens (F).
- [ ] **B5. Role boundary (SECURITY — do next).** An approved driver's login has
  role `staff`, which passes the `role:admin,staff` gate on the admin fleet
  endpoints — so a driver's token could read admin fleet data directly (not
  through the app, but possible). The self endpoints (`/v1/me/*`) are correctly
  scoped, but drivers should have their own role/gate that cannot reach admin
  fleet/telemetry endpoints. Harden before wider release.

---

## PHASE C — Driver profile & documents  *(Dev 2)*

- [x] C1. **Profile section in the app** (`ProfileScreen.js`): name, phone,
  licence, documents area, eligibility banner. Driver picks a type and uploads a
  photo of any document (licence, medical, police verification, ID, …).
- [x] C2. Each upload files as **PENDING**; it appears on the admin drivers board
  as awaiting verification. *(A push/notification to the admin is B-G5, later.)*
- [~] C3. Admin reviews + Approve/Reject already exists on the web drivers panel
  (view file + verdict). App now shows per-document status (pending/approved/
  rejected). Showing the reject reason in the app is still to add.
- [x] C4. Eligibility computed from verified dispatch-gating docs
  (`DriverSelfService::eligibility`) and shown in the app. Enforcement of trip
  actions is B4 (waits on journey screens).
- [ ] C5. Expiry awareness: warn on soon-to-expire/expired mandatory documents.
- [x] C6. Backend: `DriverSelfService` + `DriverSelfController`; self-scoped
  endpoints `GET /v1/me/driver`, `POST /v1/me/driver/documents` (resolve the
  driver from the token — no id in the path), reusing `DriverDocumentService`.
  Login↔driver linked by `stos_drivers.user_id` (set on approval). Tested end to
  end (`DriverSelfServiceTest`, 4 passing).

---

## PHASE D — Admin driver-360 page (web STOS)  *(Dev 2)*

- [ ] D1. In the admin Drivers section, clicking a driver opens a **full profile
  page** (not just the side panel): identity, contact, licence, status.
- [ ] D2. **Documents tab**: every document, view file, approve/reject, expiry.
- [ ] D3. **Activity tab**: the driver's trips, POD history, exceptions,
  fuel/expense claims, feedback — read from the trip/telemetry seams. *(some data
  is [Dev 1])*
- [ ] D4. **Access/controls**: approve the account, suspend/reactivate, set which
  document types are mandatory, reset access.
- [ ] D5. Everything role-gated (admin/staff), tenant-scoped, matches STOS UI.

---

## PHASE E — Home dashboard "Driver Today"  *(Dev 2 shell + [Dev 1] data)*

- [ ] E1. A dashboard as the post-login home: current assignment card (trip #,
  vehicle reg, container/consignment #), next required milestone, document
  readiness, and a big primary action ("Start pre-trip", "Continue trip").
- [ ] E2. Empty/available state when no trip is assigned.
- [ ] E3. Quick actions: report incident, request fuel, emergency.

---

## PHASE F — The trip journey (14 stages / 24 screens)

Build the UI now; wire milestone writes as Dev 1's endpoints land.

- [ ] F1. **Assignment review & accept/reject** — details, route, cargo, special
  handling; Accept (timestamp) / Reject (mandatory reason code). **[Dev 1]**
- [ ] F2. **Pre-trip inspection** — digital checklist (brakes, tyres, lights,
  genset, docs); a critical defect logs an exception and blocks vehicle release.
  *(inspection is Fleet-side; block interacts with [Dev 1] release)*
- [ ] F3. **Document checklist** — required driver + vehicle papers, missing-doc
  alerts. *(driver docs = Phase C; vehicle docs = Fleet)*
- [ ] F4. **Dispatch & pickup M01–M05** — yard departure, container-yard arrival,
  survey, loading, with timestamped proof. **[Dev 1]**
- [ ] F5. **Loading & client M06–M08** — gate arrival, loading/sealing (seal # +
  photo), client departure. **[Dev 1]**
- [ ] F6. **Transit mode & telemetry** — low-distraction driving screen: route
  progress, next stop, reefer temp + generator state; cold-chain guardrail alert.
  *(telemetry = Dev 2; route/next-stop = [Dev 1])*
- [ ] F7. **Exception & incident reporting** — breakdown/accident/delay/deviation/
  document issue, with photo + GPS. **[Dev 1 escalation]**
- [ ] F8. **Fuel requests & expense claims** — diesel advance against trip budget;
  receipt uploads (FASTag/toll/parking/repair). *(cost = Dev 2; budget = [Dev 1])*
- [ ] F9. **Unloading & delivery M10–M11** — destination arrival, gate entry,
  detention start, document collection. **[Dev 1]**
- [ ] F10. **POD capture** — camera-first: signature, stamp, photo, condition
  remarks. *(enhance existing POD)*
- [ ] F11. **Handover feedback M12** — 10-second prompt (Good/Okay/Issue +
  category); locked after submit. **[Dev 1]**
- [ ] F12. **Trip closure** — completion, return to available / prompt physical
  document return. **[Dev 1]**

---

## PHASE G — Cross-cutting reliability  *(Dev 2)*

- [ ] G1. **Offline-safe sync**: local queue for milestones/photos/GPS/POD with
  encrypted timestamps; idempotent replay on reconnect; visible sync status.
- [ ] G2. **One-tap emergency**: immediate high-priority escalation to Control
  Tower + Fleet Manager. **[Dev 1 routing]**
- [ ] G3. **Reefer guardrail**: enforce MOVING+GEN OFF+TEMP RISK alert locally.
- [ ] G4. **Privacy pass**: audit every screen — no financials/rates/salary/other
  drivers ever reach a driver device.
- [ ] G5. Push notifications for assignment, approval, document status.

---

## Dependencies on Dev 1 (Raza) — to coordinate

Trip assignment accept/reject, milestone writes (M01–M12), incident escalation
routing, trip budget for fuel, handover feedback, trip closure. Fleet reads
these through the existing Integration seams; writes need Dev 1's endpoints.
A paste-ready request goes to Raza before those phases start.

## Immediate order of work

1. **Phase A** (design system) + re-skin existing screens — the "real app" bar.
2. **Phase B3** (approval email) — small, high value, fully ours.
3. **Phase C** (profile + documents + approval) and **Phase D** (admin 360).
4. **Phase E** (Driver Today), then the journey (F) as Dev 1's API lands.
