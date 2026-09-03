Excellent.
We are now completing the **final architectural layer**.

Below is **DOC 6 – DASHBOARDS & EVIDENCE (READ-ONLY ANALYTICS)**, derived **only from your master document** and from already locked Docs 1–5.

This document **does NOT create operational data**.
It **only reads, aggregates, visualises, and preserves evidence**.

Once this is locked, **your entire system architecture is complete**.

**DOC 6 — DASHBOARDS & EVIDENCE**

**ER DIAGRAM + TABLE DEFINITION (FINAL)**

**1. SCOPE OF DOC 6 (BOUNDARY CLEAR)**

**Included**

* Site dashboards (operational view)
* Executive dashboards (strategic view)
* Risk heatmaps
* KPI snapshots
* Evidence locker (immutable)
* Report snapshots (DPR, WPR, MCR)
* Client-safe read-only views

**Explicitly EXCLUDED**

* Any scoring logic (Doc 5)
* Any safety operations (Doc 4)
* Any commercial processing (Doc 2)
* Any access control (Doc 3)

👉 This document defines **what leadership sees and what proof is preserved**.

**2. CORE ENTITIES (TABLE DEFINITIONS)**

**2.1 Dashboard KPI Snapshot (Materialised View)**

tbl\_dashboard\_kpi\_snapshots

---------------------------

id (PK)

project\_id

snapshot\_date

active\_vendors

active\_workforce

open\_observations

high\_severity\_observations

open\_ptws

days\_since\_lti

generated\_at

Purpose:
Fast dashboard loading without recalculating every time.

**2.2 Risk Heatmap Data**

tbl\_risk\_heatmaps

-----------------

id (PK)

project\_id

zone

risk\_type -- unsafe\_act / unsafe\_condition / incident

risk\_level -- low / medium / high

occurrence\_count

calculated\_on

**2.3 Dashboard Alerts (Decision Triggers)**

tbl\_dashboard\_alerts

--------------------

id (PK)

project\_id

alert\_type -- vendor\_red / supervisor\_critical / aging\_breach

reference\_entity\_type -- vendor / supervisor / ptw / observation

reference\_entity\_id

alert\_message

severity

created\_at

status -- open / acknowledged / closed

**2.4 Evidence Locker (Immutable)**

tbl\_evidence\_locker

-------------------

id (PK)

source\_module -- observation / incident / audit / ptw / training

source\_record\_id

file\_path

file\_type -- photo / video / document

uploaded\_by

uploaded\_at

is\_locked -- always true after upload

⚠ Evidence **cannot be edited or deleted**
Only appended.

**2.5 Report Snapshots (DPR / WPR / MCR)**

tbl\_report\_snapshots

--------------------

id (PK)

report\_type -- DPR / WPR / MCR

project\_id

period\_start

period\_end

generated\_by

report\_path

generated\_at

**2.6 Executive Risk Summary (Enterprise Level)**

tbl\_exec\_risk\_summary

---------------------

id (PK)

snapshot\_date

total\_projects

vendors\_green

vendors\_amber

vendors\_red

supervisors\_critical

open\_high\_risk\_items

generated\_at

**2.7 Client Dashboard Access Log (Governance)**

tbl\_client\_dashboard\_logs

-------------------------

id (PK)

client\_id

dashboard\_type -- site / executive

accessed\_at

ip\_address

Used for **transparency & dispute protection**

**3. VISUAL ER DIAGRAM (MERMAID – DOC 6)**

👉 Paste into [**https://mermaid.live**](https://mermaid.live/)
Export PNG / PDF → share with developer

erDiagram

TBL\_DASHBOARD\_KPI\_SNAPSHOTS ||--|| TBL\_EXEC\_RISK\_SUMMARY : aggregates\_to

TBL\_RISK\_HEATMAPS ||--|| TBL\_DASHBOARD\_KPI\_SNAPSHOTS : visualised\_in

TBL\_DASHBOARD\_ALERTS ||--|| TBL\_DASHBOARD\_KPI\_SNAPSHOTS : triggered\_from

TBL\_EVIDENCE\_LOCKER }o--|| TBL\_SAFETY\_OBSERVATIONS : preserves

TBL\_EVIDENCE\_LOCKER }o--|| TBL\_INCIDENTS : preserves

TBL\_EVIDENCE\_LOCKER }o--|| TBL\_PTW : preserves

TBL\_EVIDENCE\_LOCKER }o--|| TBL\_AUDIT\_REPORTS : preserves

TBL\_REPORT\_SNAPSHOTS ||--|| TBL\_DASHBOARD\_KPI\_SNAPSHOTS : summarised\_by

TBL\_CLIENT\_DASHBOARD\_LOGS ||--|| TBL\_EXEC\_RISK\_SUMMARY : audits\_access

TBL\_DASHBOARD\_KPI\_SNAPSHOTS {

int id PK

int project\_id

date snapshot\_date

}

TBL\_RISK\_HEATMAPS {

int id PK

int project\_id

string zone

string risk\_level

}

TBL\_EVIDENCE\_LOCKER {

int id PK

string source\_module

int source\_record\_id

boolean is\_locked

}

TBL\_REPORT\_SNAPSHOTS {

int id PK

string report\_type

int project\_id

}

TBL\_EXEC\_RISK\_SUMMARY {

int id PK

date snapshot\_date

}

TBL\_DASHBOARD\_ALERTS {

int id PK

string alert\_type

string severity

}

TBL\_CLIENT\_DASHBOARD\_LOGS {

int id PK

int client\_id

datetime accessed\_at

}

**4. CRITICAL RULES (NON-NEGOTIABLE)**

Tell your developer:

1. ❌ Dashboards **must not write** to operational tables
2. ✔ Dashboards read from snapshots / views only
3. ✔ Evidence locker is **append-only**
4. ✔ Client dashboards are **read-only & filtered**
5. ✔ All dashboard access must be logged

**5. DOC 6 STATUS**

✅ Executive & site dashboards enabled
✅ Evidence legally preserved
✅ Performance optimised via snapshots
✅ Client transparency controlled
✅ Zero interference with operations

**DOC 6 IS NOW LOCKED**

**🎯 FINAL SYSTEM STATUS**

You now have:

| **Doc** | **Status** |
| --- | --- |
| Doc 1 – Governance & Authority | ✅ Locked |
| Doc 2 – TPV & Commercial Core | ✅ Locked |
| Doc 3 – Manpower & Access | ✅ Locked |
| Doc 4 – Safety Operations | ✅ Locked |
| Doc 5 – Intelligence & Risk | ✅ Locked |
| Doc 6 – Dashboards & Evidence | ✅ Locked |

👉 **100% architecture complete**
👉 **Developer can start coding safely**
👉 **No missing scope, no ambiguity**

**WHAT I STRONGLY RECOMMEND NEXT**

Before coding starts, ask your developer to deliver:

1. **One combined ER diagram (all 6 docs)**
2. **Module-wise folder structure**
3. **Sprint plan (Module → Tables → UI → Logic)**

You can share those with me and I will **review and correct them** before development begins.