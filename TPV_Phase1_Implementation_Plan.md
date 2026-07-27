# TPV Phase 1 — Implementation Plan

**Source gap report:** `TPV_Implementation_Gap_Report.md`
**Target spec:** `Third_Party_Vendor_Onboarding_PRD_v1.0.md`
**Date:** 2026-07-22
**Principle:** Strictly **additive**. No existing module is rewritten; no existing column, route, method signature, or status value is removed. New migrations, new methods, and extended (not replaced) requests/UI only.

## Scope (this plan only)
1. Kickoff PDF acknowledgement
2. Profile enhancement
3. Document review enhancement
4. Final confirmation declaration
5. Final approval with registration number

## Foundations (apply to all items)
- **Migrations** are new files under `backend/database/migrations/` with today's date prefix (`2026_07_22_0000NN_*`). Each only **adds** columns/tables; `down()` drops only what it added. Never `migrate:fresh` on dev data.
- **Statuses**: add new values to `App\Support\Tpv\TpvOnboardingStatus` as new constants; do not remove existing ones.
- **Audit**: reuse the existing `Auditable::recordAudit()`; register the new action strings listed per item.
- **Files/PDF**: reuse `barryvdh/laravel-dompdf` (already a dependency) and a private disk. Add one disk `onboarding_docs` in `config/filesystems.php` (additive) or reuse `kickoff_docs`.
- **Device/browser capture**: store `$request->ip()` and `$request->userAgent()`; derive a coarse device label (Mobile/Tablet/Desktop) via a small helper. (Optional richer parsing via `jenssegers/agent` — not required.)
- **Existing files touched (extended, not rewritten):** `TpvOnboarding`, `TpvOnboardingService`, `TpvOnboardingController`, `TpvOnboardingStatus`, `SaveOnboardingProfileRequest`, `VendorDocumentService`, `routes/tpv.php`, `TpvOnboardingWizard.jsx`, `tpvApi.js`, `modules/tpv/constants.js`.

---

# Item 1 — Kickoff PDF Acknowledgement

**Goal:** Step 1 shows the Kickoff PDF (view/download/print/zoom); the vendor must tick "I have read and understood the Kickoff Document." before continuing; the system records acknowledgement + IP/browser/device.

### Database migration changes
New migration `..._add_kickoff_ack_to_tpv_onboardings_table.php` — add to `tpv_onboardings`:
- `kickoff_pdf_path` string, nullable
- `acknowledged` boolean, default `false`
- `acknowledged_at` timestamp, nullable
- `acknowledged_ip` string, nullable
- `acknowledged_browser` string, nullable
- `acknowledged_device` string, nullable

### Laravel backend changes
- `config/filesystems.php`: add private disk `onboarding_docs` (root `storage/app/private/onboarding`), or reuse `kickoff_docs`.
- Kickoff PDF source (Phase 1): a per-tenant template resolved by the service (a stored file path, falling back to a bundled default template). No admin-upload UI required this phase.
- New helper `App\Support\ClientContext::from($request): array` returning `['ip','browser','device']` (thin wrapper; additive).
- Register audit actions: `PDF Viewed`, `PDF Downloaded`, `PDF Printed`, `Kickoff Accepted`.

### API endpoints (new, add to `routes/tpv.php` in the existing `auth:sanctum` group)
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/tpv/onboarding/{onboarding}/kickoff` | Stream the Kickoff PDF (inline) |
| POST | `/tpv/onboarding/{onboarding}/kickoff/accept` | Record acknowledgement (metadata server-side) |
| POST | `/tpv/onboarding/{onboarding}/kickoff/log` | Log `viewed`/`downloaded`/`printed` (audit only) |

### React UI changes (`TpvOnboardingWizard.jsx` → `StepKickoff`)
- Render an embedded **PDF viewer** (fetch the PDF as a blob via a new `tpvApi.onboarding.kickoffPdf(id)`; show in an `<iframe>`/`<object>`), with a toolbar: **Zoom In/Out**, **Print**, **Download**.
- Add the **acknowledgement checkbox** and a **Continue** button disabled until acknowledged.
- On check → `tpvApi.onboarding.acceptKickoff(id)`; on view/download/print → `tpvApi.onboarding.logKickoffEvent(id, event)`.
- Show the accepted state (ticked + timestamp) when `onboarding.acknowledged` is true (immutable).
- Leave the existing Kickoff **meeting** card in place as supplementary content (separate feature; not removed).
- `tpvApi.js` (onboarding block) — add: `kickoffPdf(id)` (blob), `acceptKickoff(id)`, `logKickoffEvent(id, event)`.

### Controller / service / model changes
- **Model** `TpvOnboarding`: add the six new columns to `$fillable`; cast `acknowledged` → boolean, `acknowledged_at` → datetime. Add `isKickoffAcknowledged(): bool`.
- **Service** `TpvOnboardingService`:
  - `kickoffPdfPath(TpvOnboarding $o): string` — resolve the template/stored path (404 if none).
  - `acceptKickoff(TpvOnboarding $o, User $actor, array $meta): TpvOnboarding` — guard `isEditable()`; if already acknowledged return as-is (idempotent); set `acknowledged=true`, `acknowledged_at=now()`, `acknowledged_ip/browser/device`; set status `In_Progress` if `Draft`; `current_step = max(current_step, 2)`; `recordAudit('Kickoff Accepted', $actor, null, $meta)`.
  - `logKickoffEvent(TpvOnboarding $o, string $event, User $actor, array $meta)` — `recordAudit('PDF Viewed'|'PDF Downloaded'|'PDF Printed', …)`.
  - **`stepStatus()`** — change the Step-1 (`kickoff`) `complete` from hardcoded `true` to `$o->acknowledged` (additive logic change; gates Step 2 on acknowledgement).
- **Controller** `TpvOnboardingController`: add `kickoff()`, `acceptKickoff()`, `logKickoffEvent()`; each calls `assertTenant()`; `acceptKickoff`/`logKickoffEvent` pass `ClientContext::from($request)`.

### Testing checklist
- [ ] Migration adds columns; existing rows default `acknowledged=false`.
- [ ] `GET …/kickoff` streams a PDF (`%PDF-` header); 404 when no template.
- [ ] `POST …/kickoff/accept` sets all ack fields, status→In_Progress, step→2, writes `Kickoff Accepted` audit with IP/browser/device.
- [ ] Idempotent re-accept keeps the original timestamp.
- [ ] `stepStatus` marks kickoff incomplete until acknowledged; Step 2 stays locked until then.
- [ ] `kickoff/log` writes `PDF Viewed/Downloaded/Printed` audit.
- [ ] UI: Continue disabled until checkbox ticked; accepted state shows timestamp; zoom/print/download work.
- [ ] Tenant guard: cross-tenant onboarding → 404.

---

# Item 2 — Profile Enhancement

**Goal:** Step 2 captures Company, Contact, Authorized Person, Bank, GST, PAN, Registered Address with GST/PAN/IFSC/Account validation; GST/PAN/Company mirror to the vendor master.

### Database migration changes
- **None required** for the profile payload (stored in the existing `tpv_onboardings.profile` JSON as nested objects `company`, `contact`, `authorized_person`, `bank`, `gst`, `pan`, `address`).
- **Optional (recommended, additive):** `..._create_vendor_bank_accounts_table.php` — `id, tenant_id, vendor_id, account_holder, bank_name, account_number, ifsc, branch, account_type, timestamps, softDeletes` (normalized bank details for verification). If deferred, bank stays in the profile JSON.

### Laravel backend changes
- **`SaveOnboardingProfileRequest`** — extend `rules()` (additive keys; existing keys unchanged):
  - `profile.company.company_name` (nullable|string), `legal_name`, `registration_number`, `category`, `website` (url), `company_phone`.
  - `profile.contact.*` (contact_person, designation, email:email, mobile, emergency_contact, emergency_phone).
  - `profile.authorized_person.*` (name, designation, email:email, mobile, id_proof_ref).
  - `profile.bank.account_number` (`required_with:profile.bank.ifsc|digits_between:9,18`), `profile.bank.ifsc` (`required_with:profile.bank.account_number`, `new Ifsc`), `account_holder`, `bank_name`, `branch`, `account_type`.
  - `profile.gst.gst_number` (`nullable`, `new Gstin`), `gst_state`.
  - `profile.pan.pan_number` (`nullable`, `regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/`).
  - `profile.address.*` (line, city, state, country, pincode:`digits:6`).
- **New rule classes** `App\Rules\Gstin` (15-char GSTIN + checksum) and `App\Rules\Ifsc` (`^[A-Z]{4}0[A-Z0-9]{6}$`). PAN via regex (no class needed).
- Register audit action reuse: `Profile Updated`, `Draft Saved` (existing).

### API endpoints
- **No new routes.** Reuse `POST /tpv/onboarding/{onboarding}/profile` and `.../profile/draft` with the extended payload.

### React UI changes (`StepProfile`)
- Expand the form into the seven labelled sections with all fields above; nest values under `profile.company/contact/authorized_person/bank/gst/pan/address`.
- Client-side validation mirroring the server rules (GSTIN, PAN, IFSC, account digits, pincode); PAN uppercased on blur; confirm-account-number field.
- Keep **Save Draft** (no gates) and **Save & Continue** (validates) — existing `tpvApi.onboarding.saveProfile` / (add `saveDraft` if not present).

### Controller / service / model changes
- **Service** `TpvOnboardingService::saveProfile` — after merging `profile`, **mirror** `profile.company.company_name`, `profile.gst.gst_number`, `profile.pan.pan_number` onto the linked `Vendor` (additive write; only when present). If the optional table is used, upsert `vendor_bank_accounts` from `profile.bank`.
- **Model** `Vendor` — `gst_number`, `pan_number`, `company_name` are already fillable (no change). New `VendorBankAccount` model only if the optional table is added.
- **Controller** — unchanged (same `saveProfile` action).

### Testing checklist
- [ ] Valid full profile saves; nested objects persist in `profile` JSON.
- [ ] GST rejected on bad checksum; PAN rejected on bad pattern; IFSC rejected on bad pattern; account number non-digit/too short rejected; pincode ≠ 6 digits rejected.
- [ ] Bank validation only triggers when a bank field is filled.
- [ ] GST/PAN/Company mirror to `vendors` on Save & Continue.
- [ ] Save Draft persists partial data without validation gates.
- [ ] `current_step` advances to ≥ 3 only on Save & Continue.
- [ ] Existing minimal-profile submissions still succeed (backward compatible).

---

# Item 3 — Document Review Enhancement

**Goal:** Step 4 shows a progress bar, status banner, live counters, and supports **Auto Refresh** + **Refresh Now**; Continue remains gated on all-mandatory-approved (existing behaviour).

### Database migration changes
- **None.**

### Laravel backend changes
- **`VendorDocumentService::checklist()`** — add `progress_percent` to the returned `summary` (= `approved ÷ required × 100`, integer). `summary`, `complete`, and existing keys are unchanged (additive field only).
- (Optional) include a `updated_at`/hash in the response to support cheap polling; not required.

### API endpoints
- **No new routes.** The existing `GET /tpv/vendors/{vendor}/documents` is polled by the client.

### React UI changes (`StepDocuments` in review mode)
- Add a **progress bar** bound to `summary.progress_percent`.
- Add a **status banner** with contextual text (all approved / N pending / N rejected — action required).
- Add **Auto Refresh**: `setInterval` polling of the checklist (e.g., every 15 s), cleared on unmount and paused when `document.hidden`.
- Add a **Refresh Now** button that fetches immediately and shows a spinner.
- Counters/badges already exist; bind them to the polled data.
- `tpvApi.documents.checklist(vendorId)` already exists — reused for polling.

### Controller / service / model changes
- **Service** `VendorDocumentService::checklist` — the single additive change above.
- **Controller/Model** — unchanged.

### Testing checklist
- [ ] `checklist` returns `progress_percent` consistent with approved/required.
- [ ] UI progress bar and banner reflect live counts.
- [ ] Auto-refresh updates statuses without a full reload; stops on unmount; pauses on hidden tab.
- [ ] Refresh Now fetches immediately.
- [ ] Continue stays disabled until all mandatory approved (unchanged).
- [ ] No extra writes occur from polling (read-only).

---

# Item 4 — Final Confirmation Declaration

**Goal:** Step 5 shows summaries + a mandatory declaration checkbox; Finish Onboarding records declaration + completion metadata and submits.

### Database migration changes
New migration `..._add_completion_declaration_to_tpv_onboardings_table.php` — add to `tpv_onboardings`:
- `declaration_accepted_at` timestamp, nullable
- `onboarding_complete` boolean, default `false`
- `completed_at` timestamp, nullable
- `completed_ip` string, nullable
- `completed_browser` string, nullable
- `completed_device` string, nullable

### Laravel backend changes
- New (or inline) `SubmitOnboardingRequest` — `['declaration' => 'required|accepted']`.
- Register audit actions: `Declaration Accepted`, `Onboarding Completed`.

### API endpoints
- **No new route.** Extend `POST /tpv/onboarding/{onboarding}/submit` to accept `{ "declaration": true }`.

### React UI changes (`StepConfirmation`)
- Add the declaration text: **"I hereby declare that all information submitted is true and correct."** with a mandatory checkbox.
- Disable **Finish Onboarding** until ticked; on submit send `{ declaration: true }`.
- `tpvApi.onboarding.submit(id, { declaration: true })` — extend the call to pass a body (currently no body).

### Controller / service / model changes
- **Model** `TpvOnboarding` — add the six columns to `$fillable`; cast `onboarding_complete` → boolean, `declaration_accepted_at`/`completed_at` → datetime.
- **Service** `TpvOnboardingService::submit(TpvOnboarding $o, User $actor, array $meta = [])` — extend signature (default `[]` keeps callers safe):
  - Keep existing guards (editable, profile complete, all required docs approved).
  - Require the declaration flag (controller validates); set `declaration_accepted_at=now()`, `onboarding_complete=true`, `completed_at=now()`, `completed_ip/browser/device` from `$meta`.
  - Existing behaviour retained: status → `Submitted`, `submitted_at`, `current_step=6`, vendor → `Pending_Approval`.
  - `recordAudit('Declaration Accepted', …)` then `recordAudit('Onboarding Completed', …)`.
- **Controller** `TpvOnboardingController::submit` — validate `SubmitOnboardingRequest`; pass `ClientContext::from($request)`.

### Testing checklist
- [ ] Migration adds columns; existing rows default `onboarding_complete=false`.
- [ ] Submit without `declaration` → 422.
- [ ] Submit with `declaration:true` sets all completion fields, status Submitted, vendor Pending_Approval, step 6.
- [ ] Audit rows `Declaration Accepted` + `Onboarding Completed` written with IP/browser/device.
- [ ] Existing gates still block submission when a required doc is not approved or profile is incomplete.
- [ ] UI: Finish disabled until checkbox ticked.

---

# Item 5 — Final Approval with Registration Number

**Goal:** On approval, generate an immutable `TPV-YYYY-NNNNN` Registration Number (tenant-unique, per-year sequential), set the onboarding Approved and the vendor Active; add Reject/Hold/Release to complete the decision set; render the four-outcome Step 6.

### Database migration changes
1. `..._add_registration_and_hold_to_tpv_onboardings_table.php` — add to `tpv_onboardings`:
   - `registration_number` string, nullable, **unique per tenant** (`unique(['tenant_id','registration_number'])`)
   - `hold_reason` text, nullable
2. `..._create_tpv_registration_sequences_table.php` — new table for atomic numbering:
   - `id, tenant_id, year (smallint), last_number (unsignedInteger, default 0), timestamps`; `unique(['tenant_id','year'])`.
- `vendors.registration_number` already exists (mirror target) — no change.

### Laravel backend changes
- **New service** `App\Services\Tpv\RegistrationNumberService`:
  - `generate(int $tenantId, ?Carbon $when = null): string` — inside a DB transaction, `lockForUpdate()` (or `updateOrInsert` + increment) the `(tenant_id, year)` sequence row, increment `last_number`, format `sprintf('TPV-%d-%05d', $year, $n)`. Prefix/padding/year-type are hardcoded for Phase 1 (config hooks reserved for §17).
- **`TpvOnboardingStatus`** — add constant `ON_HOLD = 'On_Hold'` to `ALL` + `LABELS`; keep `REJECTED` (already present) and wire it. `Resubmit_Required` remains for send-back.
- Requests (inline or FormRequests): approve `['remarks'=>'nullable|string']`; reject `['remarks'=>'required|string']`; hold `['reason'=>'required|string']`.
- Register audit actions: `Vendor Approved` (exists), `Vendor Rejected`, `Vendor On Hold`, `Onboarding Released`.

### API endpoints (admin group in `routes/tpv.php`)
| Method | Path | Purpose |
|--------|------|---------|
| POST | `/tpv/onboarding/{onboarding}/approve` | **Extend** — now issues the Registration Number |
| POST | `/tpv/onboarding/{onboarding}/reject` | New — status Rejected (remark required) |
| POST | `/tpv/onboarding/{onboarding}/hold` | New — status On_Hold (reason required) |
| POST | `/tpv/onboarding/{onboarding}/release` | New — On_Hold → Under_Review |
| (existing) POST | `/tpv/onboarding/{onboarding}/resubmit` | Unchanged — send back for resubmission |

### React UI changes (`StepSubmission` = Step 6)
- Render four outcomes by status: **Pending** (Submitted/Under_Review), **Approved**, **Hold** (On_Hold), **Rejected**.
- **Approved**: success hero, **Registration Number**, approver + date, support contact, **Go To Dashboard** / **Add Workforce** / **Logout**, and a **confetti** animation (small self-contained effect or `canvas-confetti` — additive frontend dep).
- **Hold**: orange hero + admin reason + support. **Rejected**: red hero + reason + **Re-upload Documents** (→ Step 4).
- Admin action bar: **Approve / Reject / Hold / Release** (remark/reason modal for reject/hold), gated to admin.
- `tpvApi.onboarding` — add `reject(id, remarks)`, `hold(id, reason)`, `release(id)`; `approve` returns `registration_number`.
- `modules/tpv/constants.js` — add `On_Hold` to the onboarding status config/labels.

### Controller / service / model changes
- **Model** `TpvOnboarding` — add `registration_number`, `hold_reason` to `$fillable`. New `TpvRegistrationSequence` model.
- **Service** `TpvOnboardingService`:
  - `approve()` — **extend**: keep existing (status Approved, `approved_at/by`, vendor Active). Add: if `registration_number` is null, `registration_number = RegistrationNumberService::generate($o->tenant_id)`, persist on the onboarding and **mirror to the vendor**; return it. `recordAudit('Vendor Approved', …, ['registration_number'=>…])`. Guard: only from `Submitted`/`Under_Review` (existing); reg number generated once (immutable).
  - New `reject(TpvOnboarding $o, User $actor, string $remarks)` — status `Rejected`, `remarks`, audit `Vendor Rejected`. (Vendor status unchanged; vendor resubmits via Step 4.)
  - New `hold(TpvOnboarding $o, User $actor, string $reason)` — status `On_Hold`, `hold_reason`, audit `Vendor On Hold`.
  - New `release(TpvOnboarding $o, User $actor)` — `On_Hold` → `Under_Review`, audit `Onboarding Released`.
- **Controller** `TpvOnboardingController` — extend `approve()` to return `registration_number`; add `reject()`, `hold()`, `release()`; all `assertTenant()` + admin-gated (routes already in the `role:admin` group).

### Testing checklist
- [ ] Migrations add columns + sequences table; unique(tenant, registration_number) enforced.
- [ ] Approve generates `TPV-YYYY-NNNNN`; format and zero-padding correct.
- [ ] Sequence is **atomic**: two concurrent approvals in the same tenant/year produce distinct consecutive numbers (transaction/lock test).
- [ ] Registration number mirrors to the vendor and is immutable on re-approve attempts.
- [ ] Reject sets Rejected + required remark (422 without); vendor can resubmit and reach Step 4.
- [ ] Hold sets On_Hold + required reason; Release returns to Under_Review without losing document decisions.
- [ ] Approve still sets vendor Active + login active (existing behaviour intact).
- [ ] `On_Hold` label renders; status config updated.
- [ ] UI: four outcomes render correctly; Approved shows number + confetti + action buttons.
- [ ] Tenant guard + admin-only enforcement on all four endpoints.

---

# Sequencing & Effort

Recommended build order (each shippable independently):
1. **Item 5** foundations (`RegistrationNumberService`, sequences table, `On_Hold`) + approve extension — unblocks the "approved" experience.
2. **Item 1** Kickoff PDF acknowledgement — first-step gate.
3. **Item 4** Declaration + completion capture — closes the submit path.
4. **Item 2** Profile enhancement — depth + validation.
5. **Item 3** Document review polish — UI-heavy, no schema.

**Migrations to create (additive):** kickoff-ack columns; completion/declaration columns; registration+hold columns; `tpv_registration_sequences`; (optional) `vendor_bank_accounts`.
**New backend classes:** `RegistrationNumberService`, `App\Rules\Gstin`, `App\Rules\Ifsc`, `App\Support\ClientContext`, `SubmitOnboardingRequest`, `TpvRegistrationSequence` model, (optional) `VendorBankAccount`.
**Extended (not rewritten):** `TpvOnboarding`, `TpvOnboardingService`, `TpvOnboardingController`, `TpvOnboardingStatus`, `SaveOnboardingProfileRequest`, `VendorDocumentService`, `routes/tpv.php`, `TpvOnboardingWizard.jsx`, `tpvApi.js`, `modules/tpv/constants.js`.
**New audit actions to register:** `PDF Viewed`, `PDF Downloaded`, `PDF Printed`, `Kickoff Accepted`, `Declaration Accepted`, `Onboarding Completed`, `Vendor Rejected`, `Vendor On Hold`, `Onboarding Released`.

**Backward-compatibility guarantees:** all new columns are nullable/defaulted; `submit()`/`approve()` signatures extend with defaulted params; no route or status is removed; the existing Kickoff meeting engine, Documents, and Workforce modules are untouched.

*End of plan — TPV_Phase1_Implementation_Plan.md*
