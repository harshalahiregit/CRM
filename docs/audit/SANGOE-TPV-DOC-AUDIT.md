# Sangoe TPV — Doc-vs-Build Audit

Audited **strictly against `docs/Sangoe TPV.docx`**, section by section, in the doc's own flow.
For each section: what the doc asks, and whether it is actually built — in **TPV** (the module the
doc specifies) and in **Purchase** (the "same-to-same" parity mirror on `purchase_*` tables).

Legend: ✅ Complete · 🟡 Partial · ❌ Missing · — narrative / not a build item · 🔮 future (doc marks as future)

> Honest headline: **the TPV module substantially implements the doc.** The gaps are almost entirely on the
> **Purchase parity** side — the governance *record registers* are true mirrors, but several *engines and
> forward-looking gates* are thin or absent on Purchase. This corrects the earlier "Purchase parity complete"
> overstatement.

---

## Section-by-section

| § | Doc section | TPV | Purchase | Notes / what's thin or absent |
|---|-------------|:---:|:---:|-------------------------------|
| 1 | Purpose | — | — | Narrative. |
| 2 | Core Lifecycle | — | — | Narrative flow; most stages implemented (see rows below). |
| 3 | Main Navigation | ✅ | ✅ | TPV 9-cluster nav; Purchase has its own nav (Meetings entry added). |
| 4 | Dashboard / Control Tower (KPIs, Risk, Action Centre) | ✅ | 🟡 | TPV control-tower built. Purchase dashboard exists but does not surface the full KPI/risk/action-centre set. |
| 5 | Vendor Master | ✅ | ✅ | Both have full master + status vocab. |
| 6 | Prequalification (score → Qualified/Conditional/Not) | ✅ | ❌ | TPV: prequal fields + queue pages. **Purchase: none.** |
| 7 | Risk & Due Diligence (risk tiers, DD) | ✅ | ❌ | TPV: risk fields + panels. **Purchase: no risk classification on `purchase_vendors`.** |
| 8 | Contracts & Work Orders | ✅ | 🟡 | TPV: `tpv_contracts` (SLA/KPI/penalties/HSE clauses) + `tpv_work_orders`. Purchase: commercial rate-card only — **no Work Order, no governance clauses.** |
| 9 | Meetings (types, agenda, MOM lifecycle, actions, decisions, issues) | ✅ | 🟡 | TPV: full engine. Purchase: scheduler + participants + MOM **PDF** + meeting-type picker only — **no MOM approval lifecycle, agenda-items table, action engine, decision register, or issue register.** |
| 10 | Onboarding (checklist → active) | ✅ | ✅ | Both: wizard + approval chain. |
| 11 | Temporary Vendors (auto-expiry) | ✅ | 🟡 | TPV: full temporary lifecycle. Purchase: temporary type + access-window expiry, but not the full temporary controls form. |
| 12 | Approvals (central engine, ~18 types, routing) | ✅ | ❌ | TPV: `tpv_approvals` register + authority matrix + delegation. **Purchase: only the onboarding 5-stage chain — no central register.** |
| 13 | Work Packages (Vendor→Project→WP→Activity→Workforce) | ✅ | ❌ | TPV: `tpv_work_packages` + activities. **Purchase: none.** |
| 14 | Workforce | ✅ | ✅ | Both: full worker stack. |
| 15 | Competency & Training (Skill Matrix) | ✅ | 🟡 | TPV: competency register + skill matrix. Purchase: flat training records only — **no competency/skill matrix.** |
| 16 | Medical Fitness | ✅ | ✅ | Both. |
| 17 | PPE (catalogue, issue/return) | ✅ | ✅ | Both: issuance. |
| 18 | PPE Matrix (Job+Hazard+Activity→PPE) | ✅ | ❌ | TPV: `tpv_ppe_requirements` + matrix page. **Purchase: none.** |
| 19 | Permits & Work Authorization | ✅ | ❌ | TPV: permits + work-authorization + work-start letter. **Purchase: none.** |
| 20 | Gate Log / Access (scan, live "who's inside") | ✅ | ❌ | TPV: gate scans + attendance + log. Purchase: badges/QR minted but **no scan/attendance/log.** |
| 21 | Compliance | ✅ | ✅ | Both: mirror. |
| 22 | Inspections & Audits | ✅ | ✅ | Both: inspections + findings + NCR escalation. |
| 23 | Incidents | ✅ | ❌ | TPV: incident engine. **Purchase: none.** |
| 24 | NCR | ✅ | ✅ | Both. |
| 25 | CAPA | ✅ | ✅ | Both (incl. auto-raise from NCR/violation). |
| 26 | Strikes & Violations | ✅ | 🟡 | TPV: vendor violations **and** worker safety strikes. Purchase: vendor violations ✅ — **worker safety strikes ❌.** |
| 27 | Vendor Performance (VPI, history) | ✅ | 🟡 | TPV: VPI + **persisted scorecard snapshots** (history). Purchase: live VPI only, fewer dims, **no snapshots/history.** |
| 28 | Renewal & Extension | ✅ | ✅ | Both. |
| 29 | Offboarding / Closure | ✅ | ✅ | Both. |
| 30 | Documents (Vault) | ✅ | ✅ | Both: versions/expiry/review. |
| 31 | Communications | ✅ | ✅ | Both (incl. auto-dispatch on NCR/violation). |
| 32 | Vendor Portal | ✅ | ✅ | Both have controlled portals. |
| 33 | Reports & Analytics | ✅ | ✅ | Both (Purchase adds a report service). |
| 34 | Settings (admin-configurable everything) | 🟡 | 🟡 | Both: some settings are admin-editable, but many doc items (strike rules, PPE matrix editor, approval workflows, performance scoring, meeting templates) are **config-file driven, not tenant-editable UI.** |
| 35 | Core Relationships (data model) | ✅ | 🟡 | TPV model matches the doc tree. Purchase missing the Work-Package/Permit/Incident branches. |
| 36 | Business Rules (12) | ✅ | 🟡 | See rule table below. |
| 37 | Control-Tower top view | ✅ | 🟡 | Same as §4. |
| 38 | Final clustered navigation | ✅ | 🟡 | TPV clustered nav matches; Purchase nav is flatter. |
| 39 | Kickoff = a Meeting Type (not separate) | ✅ | ✅ | Done both sides (labels + type engine). |
| 40 | Positioning | — | — | Narrative. |
| 41–42 | AI Meeting Engine / AI intelligence | 🔮 | 🔮 | Doc marks these **future**; not built either side (not required for v1). |

---

## §36 Business Rules — enforcement

| Rule | TPV | Purchase | Note |
|------|:---:|:---:|------|
| 1 — No Approval, No Activation | ✅ | ✅ | Activation gated by approval both sides. |
| 2 — No Mandatory Compliance, No Mobilisation | ✅ | 🟡 | TPV enforces onboarding doc completeness; Purchase enforces at onboarding submit. |
| 3 — No Valid Worker Compliance, No Access | ✅ | 🟡 | TPV gate enforces; Purchase has readiness but no gate scan. |
| 4 — No Competency, No Work Authorization | ✅ | ❌ | Purchase has no competency/work-authorization. |
| 5 — No Required PPE, No Work | ✅ | 🟡 | PPE-at-gate both; Purchase has no PPE matrix to define "required". |
| 6 — No Permit, No High-Risk Work | ✅ | ❌ | Purchase has no permits. |
| 7 — Temporary Means Temporary | ✅ | 🟡 | Access-window expiry both; Purchase controls thinner. |
| 8 — Expiry Drives Risk/Status | ✅ | ✅ | Compliance expiry drives status both. |
| 9 — Repeated Violations Escalate | ✅ | ✅ | Escalation ladder both. |
| 10 — Performance Influences Renewal | ✅ | ✅ | Renewal assessment both. |
| 11 — Every Action Has an Owner | ✅ | 🟡 | TPV MOM/NCR/CAPA actions owned; Purchase has no MOM action engine. |
| 12 — Every Closure Requires Evidence | ✅ | ✅ | CAPA verified-needs-evidence both. |

---

## Summary counts (build items only; narrative/future excluded)

- **TPV:** ~30 build sections → **Complete ✅ ~29, Partial 🟡 1 (§34 Settings).** The doc is substantially delivered for TPV.
- **Purchase:** of the same ~30 →
  - **Complete ✅ 15:** Nav, Vendor Master, Onboarding, Workforce, Medical, PPE (issuance), Compliance, Inspections, NCR, CAPA, Renewal, Offboarding, Documents, Communications, Vendor Portal, Reports (16 incl. Reports).
  - **Partial 🟡 8:** Dashboard, Contracts/Work-Orders, Meetings, Temporary Vendors, Competency, Strikes (vendor-only), Performance (no history), Settings.
  - **Missing ❌ 7:** Prequalification, Risk & Due Diligence, Approvals (central register), Work Packages, PPE Matrix, Permits & Work Authorization, Gate Log, Incidents.

## Ranked Purchase gaps (largest first)
1. Meetings engine (MOM lifecycle + agenda + action/decision/issue registers) — §9
2. Central Approval Register (+ authority matrix / delegation) — §12
3. Permits & Work Authorization — §19
4. Work Packages — §13
5. Competency / Skill Matrix — §15
6. Prequalification — §6
7. Risk & Due Diligence — §7
8. Gate Log / scan — §20
9. Incidents — §23
10. PPE Matrix — §18
11. Worker Safety Strikes — §26
12. Contracts depth + Work Orders — §8
13. Performance snapshots/history — §27
14. Dashboard KPI depth — §4 · Settings configurables — §34

> **Scoping note (business decision, not a silent omission):** several missing items — Work Packages,
> Permits/Work Authorization, Gate scan, Worker Strikes, Incidents, PPE Matrix — are **on-site control-of-work**
> features. They are essential for a TPV site contractor; a materials-supply Purchase vendor may legitimately
> never need them. Whether to build them for Purchase is a scope call for the owner, not an automatic "gap".
