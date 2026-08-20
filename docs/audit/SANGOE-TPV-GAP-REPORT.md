# Sangoe TPV — Vision vs. Implementation Gap Report

**Source documents analysed:** `docs/Sangoe TPV.docx` (42-section complete-module vision) and `docs/Meeting.docx` (Meetings-engine spec).
**Method:** every spec area was verified against the live codebase (models, migrations, services, routes, UI) — not from memory.
**Legend:** 🟢 **EXISTS** (built & working) · 🟡 **PARTIAL** (some of it exists, real gaps remain) · 🔴 **MISSING** (nothing in code).

---

## 1. Executive summary

The current module is a strong **HSSE + workforce + onboarding** system. The vision asks for a full **third-party governance lifecycle**: *Identity → Prequalification → Risk → Approval → Contract → Meetings → Onboarding → Workforce → Readiness → Control-of-Work → Compliance → Incident/NCR/CAPA → Performance → Renewal → Offboarding*.

Against the vision's ~30 functional areas:

| Status | Count | Areas |
|---|---|---|
| 🟢 Solid | 6 | Onboarding · Temporary Vendors · Workforce · Medical Fitness · PPE (issue/return) · Incidents (+RCA, auto-suspend) |
| 🟡 Partial | 16 | Dashboard · Vendor Master · Approvals · Risk & Due-Diligence · Reports · **Meetings** · PPE Matrix · Permits · Gate Log · Compliance · CAPA · Strikes · Vendor Performance · Renewal · Offboarding · Documents · Communications · Vendor Portal |
| 🔴 Missing | 8 | Prequalification · Vendor Risk Classification · Contracts & Work Orders · Work Packages · Competency & Training (skill matrix) · Inspections & Audits · NCR · Settings/Config UI |

**The single biggest build** is the **Meetings engine** (the whole `Meeting.docx`): today only a *kickoff-scoped* meeting feature exists. Second is the **"qualification front-end"** the module has never had — Prequalification, Risk Classification, Due Diligence — the vision's entire left edge (*Identity → Qualification*) before a vendor is even approved.

---

## 2. Master scorecard (all 42 areas)

### CONTROL
| # | Area | Status | One-line reality |
|---|---|---|---|
| 4 | Dashboard / Control Tower | 🟡 | Two dashboards (`TpvDashboard` orphaned, landing = vendor list; `GovernanceDashboard` live). No unified **Action Centre**, no CAPA-overdue / MOM-action tiles. |
| 5 | Vendor Master | 🟡 | Shared vendor record with GST/PAN/bank(child)/status. Missing: trade_name, category/subcategory, contractor/subcontractor/consultant type, parent_company, **CIN, Udyam**, sponsor/contract_owner, ~half the status enum. |
| 12 | Approvals | 🟡 | Real multi-level **onboarding** approval engine (`onboarding_approvals`, SLA, escalation, delegation). Authority matrix is **read-only config**, not a routing engine. No generic approval routing across the 16 spec approval types. |
| 7 | Risk & Due Diligence | 🟡 | Document verification + expiry gates only. No due-diligence / background-check / risk-scoring flow. |
| 33 | Reports & Analytics | 🟡 | DPR/WPR/MCR + VRS scorecard. No trend/benchmark/analytics, no CSV/Excel export (print-PDF only). |

### ENGAGEMENT & MOBILISATION
| # | Area | Status | One-line reality |
|---|---|---|---|
| 6 | Prequalification | 🔴 | No model/table/UI. No 82/100 qualification scoring. |
| 8 | Contracts & Work Orders | 🔴 | No TPV contract/WO. Purchase & Sales have their own; TPV only *reads* Purchase contracts. |
| 9 | **Meetings** | 🟡 | Shared **kickoff** engine exists (schedule, attendees+attendance, MOM upload/generate + action items, ack tokens, reminders, online links). Missing agenda builder, decisions, issues, templates, calendar, previous-MOM carry-forward, MOM approval+distribution. **See §3.** |
| 10 | Onboarding | 🟢 | 6-step wizard → multi-level approval → real activation (login + access window + logged email). |
| 11 | Temporary Vendors | 🟢 | Time-boxed lifecycle, auto-expiry, reminders, extend, convert. (Recently fixed: list + password.) |
| 13 | Work Packages | 🔴 | No Vendor→Project→Work Package→Activity layer. Workers attach straight to vendor. |

### WORKFORCE
| # | Area | Status | One-line reality |
|---|---|---|---|
| 14 | Workforce | 🟢 | `TpvWorker` + 5-step wizard, Draft/Active/Suspended/Terminated, badge/QR. Missing trade, experience, competency, joining/exit fields. |
| 15 | Competency & Training | 🔴 | Only **one** generic HSSE induction per worker. No skill matrix (Worker×Activity×Competency×Validity), no trade certs/licences with expiry, no job-specific courses. |
| 16 | Medical Fitness | 🟢 | Full medical record, expiry **hard-enforced** at gate + badge. Enum = Fit/Fit-w-Restrictions/Unfit (no explicit Expired/Pending states). |
| 17 | PPE | 🟢 | Issue / return / damaged / lost via single Inventory ledger. No replacement-frequency scheduling. |
| 18 | PPE Matrix | 🟡 | Rules are **role/skill/all → product+qty** only. No Job+Hazard+Activity dimension, no mandatory/optional/conditional, no replacement frequency. |

### CONTROL OF WORK
| # | Area | Status | One-line reality |
|---|---|---|---|
| 19 | Permits & Work Authorization | 🟡 | Full PTW + JSA lifecycle (approve needs JSA + active vendor; nightly expiry). But **no unified authorization** combining vendor+competency+compliance+PPE+permit; permit not linked to workers or gate. |
| 20 | Gate Log / Access | 🟡 | Worker scan + attendance + roster; separate visitor/vehicle/drill registers. Missing **equipment & material** events, a unified gate-event stream, and a whole-site live view (workers-only today). |
| 22 | Inspections & Audits | 🔴 | No inspection/audit module (Plan→Inspect→Finding→Action→CAPA→Verify→Close). |

### COMPLIANCE & INCIDENTS
| # | Area | Status | One-line reality |
|---|---|---|---|
| 21 | Compliance | 🟡 | Evidence locker + numeric compliance **score**. No per-vendor compliance **status** enum (Compliant/Partial/Non/Waived/Under-Review); categories differ from spec. |
| 23 | Incidents | 🟢 | `HsseIncident` + RCA + auto-suspension on Serious/Fatal/stop-work + close-gating. No First-Aid/LTI granularity. |
| 24 | NCR | 🔴 | No distinct NCR entity; folded into incidents/CAPA. |
| 25 | CAPA | 🟡 | Exists but **incident-only** linkage; no evidence-file attachment; not linked to audits/NCR/inspections/meetings. |
| 26 | Strikes & Violations | 🟡 | Worker-level punch → auto-terminate. No Warning/Strike-1/2/3 tiers, no configurable violation types, no strike→vendor-blacklist path, no `blacklist()` action. |

### PERFORMANCE & CLOSURE
| # | Area | Status | One-line reality |
|---|---|---|---|
| 27 | Vendor Performance | 🟡 | VRS 3-dimension scorecard (safety/compliance/workforce) → A/B/C/D + monthly history. No VPI, no cross-project benchmarking, no broader dimensions (quality/timeliness/commercial). |
| 28 | Renewal & Extension | 🟡 | Temporary-access **extend** only. No renewal-assessment / Renew-Requalify-Replace-Exit decision engine. |
| 29 | Offboarding / Closure | 🟡 | `offboard()` terminates workers + revokes badges + locks login. No **checklist** (PPE/ID return, doc archival, final performance review). |
| 30 | Documents | 🟡 | Statutory vault with versioning/review/expiry — but scattered across **4 stores**; no single vault; CAPA evidence has no store. |
| 31 | Communications | 🟡 | Only activation email + temp-access/approval-SLA sweeps; **one** log type; email-only. No in-app feed, SMS/WhatsApp, or expiry/incident/strike/CAPA notifications. |
| 32 | Vendor Portal | 🟡 | Strong on onboarding/documents/workforce/PPE/countdown. Missing: respond-to-NCR, submit-CAPA-evidence, view meetings/MOM, respond-to-actions. |
| 34 | Settings / Config | 🔴 | No admin settings UI. PPE matrix is the **only** UI-configurable item; everything else is config-file or hard-coded constants. |
| 2 | Vendor Risk Classification | 🔴 | No `risk_level` (Critical/High/Med/Low) field or vendor risk score. (A–D performance band ≠ risk.) |

---

## 3. Meetings engine (`Meeting.docx`) — detailed gap

**Today:** a shared kickoff-meeting engine (`App\Models\Shared\KickoffMeeting…`, `KickoffMeetingService`, routes `shared.php`) consumed by TPV/Purchase/Projects. It supports: schedule (title, datetime, duration, online/onsite, link), attendee registry + **per-person attendance**, status lifecycle (Scheduled/Delayed/Completed/Cancelled), **MOM** (upload or generated PDF) + **itemised action items** (`kickoff_mom_items`: description, responsible, target date), **token acknowledgement** (per vendor), **reminders**, and online-provider links (Zoom/Meet/Jitsi).

| Meeting-engine capability | Status | Gap |
|---|---|---|
| Configurable **Meeting Types** (Kickoff as *one* type of ~20) | 🔴 | Only "kickoff" exists as a concept. |
| **Agenda Builder** (# · item · owner · duration · priority) | 🔴 | One free-text `agenda` field. |
| Participants (internal/external, role-in-meeting) | 🟡 | Attendee registry exists; no internal/external typing or Sangoe-identity linkage. |
| Attendance (Present/Absent/Late/Excused/Online/Offline) | 🟡 | Boolean `attended` only. |
| Structured **MOM** (agenda→discussion→decision→action) | 🟡 | MOM file + action items; no per-agenda discussion/decision structure. |
| **Action Engine** as trackable Sangoe tasks (Open→In-Progress→Pending-Verification→Closed→Reopened) linked to Project/Vendor/WP/Person | 🟡 | Action items exist but are meeting-local, not cross-module tasks. |
| **Decision Register** | 🔴 | None. |
| **Issues Raised** (convert to Task/NCR/CAPA/Incident/Approval) | 🔴 | None. |
| **Meeting Templates** (auto-load agenda by type) | 🔴 | None. |
| **Previous-MOM integration / Carry-Forward open items** | 🔴 | None. |
| **MOM Approval** (Draft→Review→Organizer→Chair→Final) | 🔴 | Token ack only, no approval chain. |
| **MOM Distribution** (sent/viewed/acknowledged) | 🟡 | Ack tokens + reminders; no distribution tracking. |
| **Meeting Calendar** (Day/Week/Month) + filters | 🔴 | None. |
| Meeting **Dashboard** (pending MOM, open/overdue actions) | 🔴 | None. |
| Meeting → **Project / Vendor history** rollups | 🔴 | None. |

**Architectural note from the doc:** build Meetings as a **Sangoe-OS-core** engine (reused by Projects/HR/HSE/Sales), with TPV as one consumer — not a TPV-only feature. The existing `Shared\Kickoff*` code is the right seed to generalise.

---

## 4. The 12 critical business rules — enforcement status

| Rule | Status | Evidence / gap |
|---|---|---|
| 1 · No Approval, No Activation | 🟢 Enforced | Activation only via onboarding approve → `VendorService::updateStatus(ACTIVE)`. |
| 2 · No Compliance, No Mobilisation | 🟢 Enforced | Submit blocked till docs approved; nightly `EnforceVendorCompliance` auto-suspends lapsed docs. |
| 3 · No Worker Compliance, No Access | 🟢 Enforced | `blockers()` gates badge; gate re-validates live. |
| 4 · No Competency, No Authorization | 🟡 Partial | Only HSSE-induction pass enforced; no trade-competency model. |
| 5 · No PPE, No Work | 🟡 Partial | PPE checked at **badge issue** only; **gate scan does not check PPE**. |
| 6 · No Permit, No High-Risk Work | 🟡 Partial | Permit lifecycle exists but not linked to gate/worker/task — work isn't actually blocked. |
| 7 · Temporary Means Temporary | 🟢 Enforced | Auto-expiry + reminders + portal middleware. |
| 8 · Expiry Drives Risk | 🟡 Partial | Expiry → gate deny/warn + auto-suspend, but **does not feed the VRS score**. |
| 9 · Repeated Violations Escalate | 🟢 Enforced | 3 strikes / 1 critical → auto-terminate site access. |
| 10 · Performance Influences Renewal | 🔴 Not enforced | Scorecards exist but **no renewal workflow consumes them**. |
| 11 · Every Action Has an Owner | 🟡 Partial | CAPAs have owner+due; no MOM/meeting-action ownership, no "my open actions" queue. |
| 12 · Closure Requires Evidence | 🟡 Partial | Incident close needs RCA + all CAPAs Verified, but CAPA verification is a **status flag, no evidence file**. |

**Score: 4 enforced · 7 partial · 1 not-enforced.** The rule engine is real but incomplete — mostly around competency, permit-to-gate linkage, and performance→renewal.

---

## 5. Navigation — current vs. vision

**Current (19 flat tabs):** Dashboard · Kickoff · Onboarding · Temporary · Approvals · Workforce · PPE · PPE Matrix · Compliance · Gate Log · Strikes · Permits · Observations · Registers · Incidents · Governance · Evidence · Authority · Reports.

**Vision (9 grouped clusters):** Dashboard · **Vendors** (Master/Prequal/Risk/Temporary/Contracts) · **Mobilisation** (Meetings/Onboarding/Work Packages/Approvals) · **Workforce** (Workforce/Competency/Medical/PPE/Matrix) · **Work Control** (Permits/Gate/Inspections) · **Compliance** (Compliance/Incidents/NCR/CAPA/Strikes) · **Performance** (Performance/Renewal/Offboarding) · **Intelligence** (Reports/Documents/Communications) · **Ecosystem** (Vendor Portal/Settings).

Kickoff becomes **Meetings → New → Type = Kickoff**, not its own tab.

---

## 6. Recommended build sequence

Ordered by dependency and value; each phase is independently shippable.

**Phase A — Meetings engine (largest, highest-leverage).** Generalise `Shared\Kickoff*` into a Meetings engine: meeting types (config), agenda builder, structured MOM, Action Engine as real tasks, Decision Register, Issues, templates, carry-forward, MOM approval+distribution, calendar, project/vendor history. Replace the Kickoff tab.

**Phase B — Qualification front-end (the missing left edge).** Vendor Risk Classification (`risk_level` on vendor + scoring), Prequalification (scored questionnaire → Qualified/Conditional/Not-Qualified), Due-Diligence checklist. These gate approval and set monitoring depth.

**Phase C — Vendor Master completion + Settings.** Add trade_name, category/subcategory, contractor-type, parent_company, CIN, Udyam, sponsor/contract_owner; extend the status enum. Build the admin **Settings** UI (types, templates, approval routing, strike rules, scoring weights) — turns hard-coded constants into tenant config.

**Phase D — Control-of-Work hardening (rules 4/5/6).** Competency & skill-matrix model; link Permit ↔ Worker ↔ Gate; enforce PPE at the gate; unified Work-Authorization check. Add Work Packages as the Vendor→Project→Activity spine.

**Phase E — Corrective-action completeness.** NCR entity; Inspections & Audits module; generalise CAPA to link audits/NCR/inspections/meetings + require evidence files; strike tiering (Warning→1/2/3→Suspend→Blacklist) + `blacklist()` action.

**Phase F — Governance polish.** Unified Action Centre on the dashboard; Renewal & Extension engine (consume VRS → rule 10); Offboarding checklist; unified Document Vault; notification expansion (in-app feed, expiry/incident/CAPA alerts, SMS/WhatsApp); management analytics + export; expand VRS dimensions + expiry-into-score (rule 8).

---

## 7. Bottom line

- **What's genuinely done:** onboarding→activation, workforce+medical+PPE, incidents+RCA+auto-suspension, temporary-vendor lifecycle, gate scanning, VRS scorecards. The HSSE/operational core is solid.
- **What the two docs mostly add:** the **Meetings engine** (a whole subsystem), the **qualification/risk front-end** (prequalification, risk classification, due diligence), the **commercial spine** (contracts/work-orders/work-packages), **NCR + Inspections/Audits**, a **competency/skill matrix**, and an **admin Settings** layer — plus tightening the remaining business rules.
- **Nothing in the docs conflicts with what exists**; it's almost entirely *additive*, and the existing `Shared\Kickoff*`, VRS, incident, and approval code are the correct seeds to extend rather than replace.
