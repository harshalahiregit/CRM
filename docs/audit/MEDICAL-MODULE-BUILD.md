# Medical Module — build record

Built across TPV and Purchase in one pass, to the brief of 2026-09-03 (internal
medical flow, external flow, quality check, back-and-forth timeline,
re-examination, profile integration, project bypass, safety-induction
prerequisite).

Everything below is **done and test-covered** unless it says otherwise.

---

## 1 · What was built

| Requirement | Where it lives |
|---|---|
| **Internal flow** — doctor login → vendor → worker → detailed examination form | `pages/doctor-portal/*`, `Api/Medical/DoctorPortalController` |
| PDF prescription with geolocation, IP, camera capture, signature, **barcode**, **QR**, licence no | `Services/Medical/MedicalCertificatePdfService`, `views/pdf/medical_certificate.blade.php` |
| **External flow** — vendor uploads one certificate, or a sheet of them | `Api/Portal/VendorPortalMedicalController`, `PurchasePortalMedicalController` |
| **Quality check** — Approve / Reject / **Hold**, reason mandatory on the negatives | `MedicalQcStatus`, `*MedicalWorkflowService::decide()` |
| **Back-and-forth timeline**, capped at 10 rounds | `tpv_medical_messages` / `purchase_medical_messages`, `MedicalTimeline.jsx` |
| **Re-examination** + medical history log | `record(..., is_reexam)`, `previous_medical_id`, `attempt_no` |
| **Profile integration** — health reports + score out of 10 | `Support/Medical/HealthScore`, `WorkerHealthPanel.jsx` |
| **Bypass** — "Not Applicable for the Project" | `*_work_packages.medical_not_applicable` + tenant default in settings |
| **Induction prerequisite** — "Medical Report is Pending" | `TpvWorkerService::saveInduction`, `PurchaseWorkforceService::saveInduction`, `MedicalPendingBanner.jsx` |

## 2 · Decisions worth knowing

- **Clearance ≠ a signed certificate.** `isCurrentlyValid()` now also requires the
  quality team's approval. A `qc_status` of `null` reads as approved so records
  made before this module are not retroactively blocking anyone.
- **One clearance verdict.** `clearanceFor($worker)` is the single answer used by
  the induction block, the badge blockers, work authorization, the dashboards and
  the portals — so the gate and the message a user reads cannot disagree.
- **Hold vs Reject.** Hold comes back (and advances the round counter); Reject is
  terminal and the remedy is a re-examination. Enforced, not just labelled.
- **The 10-round cap is a hard stop**, not a display depth. At the cap the vendor
  can no longer resubmit and the reviewer must decide on what they have.
- **Same-day re-examination** is a new record: the unique key on TPV moved from
  `(worker, exam_date)` to `(worker, exam_date, attempt_no)`.
- **Health score is computed**, not asked for (BMI, BP, SpO₂, pulse, sensory,
  declared conditions, screening band; capped by the fitness verdict). A doctor
  may override, and the override is recorded as `health_score_source = manual`.
- **One doctor login serves both vendor sides.** Every doctor route carries a
  `{module}` segment; a profile may narrow which sides it serves.
- **`users` was not touched.** Doctor identity lives in `medical_doctor_profiles`
  — a shared entity no single module may reshape (TEAM-CONVENTIONS §2/§6).
- **Purchase vendors are not Users.** The Purchase workflow service accepts
  `User|PurchaseVendor|null` and never writes a vendor id into a user column; the
  timeline still records who filed what.
- **Certificate numbers** derive from the row id (`MED-TPV-2026-000123`), so they
  are unique without a sequence table and cannot collide under concurrency.

## 3 · New dependencies

Two, both pure-PHP so no new PHP extension is required:

- `picqer/php-barcode-generator` — Code 128, SVG output
- `bacon/bacon-qr-code` — QR, SVG output

Rendered as SVG data URIs because dompdf draws SVG via php-svg-lib without GD.

## 4 · Configuration

`config/medical.php` is the shipped baseline. Per tenant:

- **TPV** — Settings → **Medical** (the `medical` settings group).
- **Purchase** — Settings → **Medical** (flat `medical_*` keys).

Both expose: certificate validity, auto-approve-internal, max exchanges,
reviewer user IDs, induction block on/off, the pending message, the
not-applicable default. TPV additionally edits the reason catalogue.

Reverse geocoding (coordinates → "Andheri, Mumbai") uses Nominatim by default —
no API key, cached 30 days, and a failure never blocks a save.

## 5 · Tests

39 new feature tests in `tests/Feature/Medical/`:

- `DoctorPortalExaminationTest` — issuing, licence gate, scoring, module scoping, same-day re-exam, PDF
- `MedicalQualityCheckTest` — reason enforcement, hold vs reject, the timeline, the 10-round cap, who may review
- `ExternalMedicalUploadTest` — single upload, bulk import with per-row rejects, cross-vendor isolation
- `PurchaseMedicalModuleTest` — the Purchase mirror, the bypass, public verification

`tests/Feature/{Medical,Tpv,Purchase,Portal}` — **570 passing**.

## 6 · Open / deliberately not done

- **Notifications to Purchase vendors are e-mail only** — a PurchaseVendor holds
  no User session, so there is no in-app bell to ring on that side.
- **Doctor logins are created by an admin** (Medical → Doctors). There is no
  self-registration, and no password-reset flow specific to doctors — they use
  the normal one.
- **The reason catalogue is editable on TPV only** through the UI; Purchase reads
  the shipped catalogue unless `medical_reasons` is set directly as JSON.
- The bulk template matches attachments **by filename = worker code**. A ZIP of
  certificates is not accepted (the `zip` PHP extension is not enabled here).
