# Sangoe — Senior Voice-Transcript Gap Audit & Backlog

Source: senior's walkthrough transcript (Aug 29, 2026) describing the full vendor
lifecycle (kickoff → onboarding → team → medical → training → ID card → kit →
worker 360 → PTW → incident → risk → award/certificate → referral).

Method: 3 code-explorer passes over the real TPV + Purchase codebase (backend
services/controllers/routes + frontend). Each item is EXISTS / PARTIAL / MISSING
with the strongest file evidence. **Tick only what is genuinely done end-to-end.**

**Overall match to this transcript: ~45–50%.** The skeleton exists for almost
everything; the senior's emphasis — legal-grade capture (IP/geo/photo/QR on every
sign), configurable forms, multi-level approvals, live timers, full history — is
mostly missing.

Related docs: [[SANGOE-TPV-GAP-TASKLIST.md]], [[SANGOE-TPV-SPEC-MATCH.md]],
[[SENIOR-FEEDBACK-TASKLIST.md]].

---

## ▶ START HERE — P0 (correctness + foundation, do first)

- [ ] **P0-1 · Medical external-report upload is dropped (DATA-LOSS BUG).** The
  UI captures the external doctor's PDF into `f.external_pdf`
  (`TpvWorkerWizard.jsx:831`) but `saveMedical()` never sends it — the payload is
  only `exam_type`/`examiner_name` and hardcodes `fitness_status:'Fit'`
  (`:585-600`). `certificate_path`/`document_path` are allowed by
  `SaveWorkerMedicalRequest` but never populated. → Persist the uploaded file
  (multipart) + store its path; stop hardcoding "Fit".
- [ ] **P0-2 · Medical has no history / periodicity.** `TpvWorker::medical()` is
  `hasOne` + `updateOrCreate` (`TpvWorker.php:107`, `TpvWorkerService.php:125`):
  each worker holds exactly ONE record; a re-test overwrites it; `valid_until` is
  annual. Senior wants "6 tests, click one, see detail." → `hasOne`→`hasMany`
  (new `tpv_worker_medical_exams` history), per-worker list → drill-down report,
  periodic cadence (2–3 months) config.
- [ ] **P0-3 · Medical does NOT gate training.** `saveInduction()` checks only
  `isEditable()` (`TpvWorkerService.php:142`); a worker can be trained with no /
  failed medical. Medical only gates the badge. → Add a medical-passed
  precondition to induction/training.
- [ ] **P0-4 · Legal-capture foundation (reusable).** Build one small service to
  capture IP + geolocation + photo + signature on a signed action, then wire it
  into the **medical** sign first (today only signature is stored —
  `TpvWorkerService.php:106`; no IP/geo/photo). Reused later by training (4g) and
  permit (9f).

---

## P1 — Permit-to-Work depth (safety-critical governance)

PTW exists (`PermitService.php`, `TpvPermits.jsx`) but is far from the spec.

- [ ] **P1-1 · Multi-level configurable approval.** Today single `approved_by`
  (`PermitService::approve` `:61`). Senior: L1/L2/L3, count configurable,
  approvers = PMC / client / internal / vendor-rep. (A configurable chain exists
  for onboarding only — `config/tpv.php` — not wired to permits.)
- [ ] **P1-2 · Signer capture on approval** — photo / IP / geo / device. None on
  `work_permits` today (only `approved_by`+`approved_at`). Reuse P0-4.
- [ ] **P1-3 · Live countdown timer + auto-close.** Today expiry is a nightly
  batch sweep (`expireLapsed`, `permits:expire`), no live timer, no "stop work"
  block on expiry. Add per-permit timer (6h/8h/24h presets) starting on approval.
- [ ] **P1-4 · Pre-expiry reminder + siren/high-alert.** No 1–2h reminder,
  repeated alerts, or siren visual+sound for permits today.
- [ ] **P1-5 · Max-hours rule + capped extensions** (settings-driven). No
  `max_hours`/extension mechanism/cap and no renew/extend route today.
- [ ] **P1-6 · Gate exit-block + emergency-exit request.** `GateScanService::checkOut`
  deliberately never checks permits. Add: a worker on an OPEN permit can't exit
  until it closes; emergency-exit → re-approval (L2/L3) → security release.
- [ ] **P1-7 · Configurable permit forms** (no hard-coding; editable in Settings).
  Form is hard-coded JSX + fixed validation today. Add fields: project, floor,
  zone (E/W), teams, supervisor (location is one free-text box now); add Cold +
  General types.
- [ ] **P1-8 · Permit index** — issued-today count, start/in-time, live timers.

---

## P2 — Training + kickoff + incident + risk completeness

- [ ] **P2-1 · Training rating + feedback.** No trainee 1–5 rating, no trainer
  feedback field, no WhatsApp/mobile feedback link (models have no such columns).
- [ ] **P2-2 · Trainer sign IP/geo/QR.** Only truncated userAgent captured
  (`TpvWorkerController.php:283`). Add IP + geo + QR (legal verification).
- [ ] **P2-3 · Group multi-vendor training form.** Group induction exists but the
  participant list shows only name + code — no vendor / designation / department /
  company columns, and it's single-vendor. Build the tabular multi-vendor capture.
- [ ] **P2-4 · First-time = Induction default; medical-passed visible in training
  UI; auto ID-card after training** (today a separate admin activate click).
- [ ] **P2-5 · Kickoff drives onboarding.** Docs checklist is a static per-vendor-
  type matrix, NOT derived from the kickoff MOM (`VendorDocumentService::checklist`).
  Also: kickoff email carries no portal login link; portal shows the whole wizard,
  not a kickoff-only pre-activation view.
- [ ] **P2-6 · Incident public link + email-OTP.** No share/download/public link
  for incidents; OTP viewing exists only for Sales proposals. Add discrete
  slip-trip + electrical-shock types; add per-type totals rollup.
- [ ] **P2-7 · Risk score inputs + auto-blacklist.** Penalties + feedback are NOT
  inputs to either score today; no auto-blacklist threshold (grave incident
  auto-suspends, blacklist is manual).

---

## P3 — Features & polish

- [ ] **P3-1 · Certificate template engine** — templates, create-template,
  feedback-type (appreciation/achievement/milestone) → certificate popup → verify
  → send → digital certificate. None today (only HR training certs, unrelated).
- [ ] **P3-2 · Qualitative client/PMC feedback capture** per vendor (today the
  "Feedback" panel is just the read-only VRS scorecard; no VendorFeedback model).
- [ ] **P3-3 · Separate Doctor + Trainer staff roles** selectable via dropdown
  (today trainer = hardcoded preset list, doctor = free-text; roles don't exist).
- [ ] **P3-4 · Worker 360 consolidated** — one screen: medical count/history, kit,
  awards, penalties, notices, full journey (today scattered across the stepper).
  Add a "notices" concept for workers.
- [ ] **P3-5 · Referral earnings/commission tracking** (referral capture exists;
  no earnings field).
- [ ] **P3-6 · ID card** — show emergency phone + training/awards/achievements on
  the card face (today only penalties on card; training/awards in scan payload).
- [ ] **P3-7 · Medical polish** — per-project/site skip flag; vendor→worker
  auto-populate in the form; blood history + work-based risk factor fields.
- [ ] **P3-8 · Kit zone-based** requirement (today role-based only).
- [ ] **P3-9 · Biometric/Aadhaar thumb + mobile signing** for training/attendance
  (today "thumb" is a drawn PNG; no biometric device, no mobile-link signing).

---

## What already works (verified — don't rebuild)
- Onboarding approve → full activation (login active + access window + email) — `TpvOnboardingService::approve` → `VendorService::updateStatus`.
- Digital ID card with QR from the real gate token; QR-scan gate endpoint; badge-expiry + 3-penalty auto-block at the gate.
- Kit = PPE issued from real inventory to a named worker (stock decrement).
- Incident flow (Report→RCA→CAPA→Close) with grave→auto-suspend; rich incident type list.
- Award + penalty (violations) management; referral capture; VRS scorecard + manual risk tier.
