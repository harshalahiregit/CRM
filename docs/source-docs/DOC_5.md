**DOC 5 — INTELLIGENCE & RISK ENGINES**

**ER DIAGRAM + TABLE DEFINITION (FINAL)**

**1. SCOPE OF DOC 5 (BOUNDARY CLEAR)**

**Included**

* Vendor Risk Score (VRS)
* Supervisor Scorecard
* Risk events & scoring history
* Audit findings (input to risk)
* Performance reports (VPR)
* Score decay & recovery logic (data side)

**Explicitly EXCLUDED**

* PTW / Observations / Incidents (Doc 4)
* Workforce & access (Doc 3)
* Commercial payments (Doc 2)
* Dashboards & evidence (Doc 6)

👉 This document defines **how raw operational data becomes intelligence**.

**2. CORE ENTITIES (TABLE DEFINITIONS)**

**2.1 Vendor Risk Score – Current Snapshot**

tbl\_vendor\_risk\_score

---------------------

id (PK)

vendor\_id (FK → tbl\_tpv\_vendors.id)

current\_score

risk\_band -- green / amber / red

last\_calculated\_at

**2.2 Vendor Risk Events (Atomic Intelligence)**

tbl\_vendor\_risk\_events

----------------------

id (PK)

vendor\_id (FK)

source\_module -- observation / incident / ptw / audit / penalty

source\_record\_id

score\_impact

impact\_reason

created\_at

This table is the **single source of truth**
for *why* a vendor score changed.

**2.3 Vendor Performance Report (VPR)**

tbl\_vendor\_performance\_reports

------------------------------

id (PK)

vendor\_id (FK)

period\_start

period\_end

safety\_score

discipline\_score

timeliness\_score

overall\_rating

generated\_at

**2.4 Supervisor Score – Current Snapshot**

tbl\_supervisor\_score

--------------------

id (PK)

supervisor\_id (FK)

current\_score

risk\_band -- excellent / watch / high / critical

last\_calculated\_at

**2.5 Supervisor Score Events (History & Audit)**

tbl\_supervisor\_score\_events

---------------------------

id (PK)

supervisor\_id (FK)

source\_module -- observation / incident / ptw / training

source\_record\_id

score\_change

change\_reason

created\_at

**2.6 Supervisor Boundary Mapping (Reference)**

*(Logical dependency from Doc 3 & Supervisor design)*

tbl\_supervisor\_boundaries

-------------------------

id (PK)

supervisor\_id (FK)

project\_id

zone

floor

trade

shift

Used to apply **full or partial score impact**

**2.7 Audit Findings (Risk Input)**

tbl\_audit\_reports

-----------------

id (PK)

project\_id

vendor\_id (FK)

audit\_type

audit\_date

overall\_result -- compliant / minor\_nc / major\_nc

tbl\_audit\_findings

------------------

id (PK)

audit\_report\_id (FK → tbl\_audit\_reports.id)

finding\_type -- NC / observation / good\_practice

severity

status -- open / closed

**2.8 Score Decay & Recovery Log**

tbl\_score\_decay\_log

-------------------

id (PK)

entity\_type -- vendor / supervisor

entity\_id

decay\_reason -- zero incident / timely closure / audit pass

score\_reduction

applied\_at

Ensures **fairness** and prevents manipulation.

**3. VISUAL ER DIAGRAM (MERMAID – DOC 5)**

👉 Paste into [**https://mermaid.live**](https://mermaid.live/)
Export PNG / PDF → share with developer

erDiagram

TBL\_TPV\_VENDORS ||--|| TBL\_VENDOR\_RISK\_SCORE : evaluated\_by

TBL\_TPV\_VENDORS ||--o{ TBL\_VENDOR\_RISK\_EVENTS : influenced\_by

TBL\_TPV\_VENDORS ||--o{ TBL\_VENDOR\_PERFORMANCE\_REPORTS : measured\_in

TBL\_SUPERVISORS ||--|| TBL\_SUPERVISOR\_SCORE : evaluated\_by

TBL\_SUPERVISORS ||--o{ TBL\_SUPERVISOR\_SCORE\_EVENTS : influenced\_by

TBL\_SUPERVISORS ||--o{ TBL\_SUPERVISOR\_BOUNDARIES : assigned\_to

TBL\_VENDOR\_RISK\_SCORE ||--o{ TBL\_SCORE\_DECAY\_LOG : adjusted\_by

TBL\_SUPERVISOR\_SCORE ||--o{ TBL\_SCORE\_DECAY\_LOG : adjusted\_by

TBL\_AUDIT\_REPORTS ||--o{ TBL\_AUDIT\_FINDINGS : contains

TBL\_TPV\_VENDORS ||--o{ TBL\_AUDIT\_REPORTS : audited\_in

TBL\_VENDOR\_RISK\_SCORE {

int id PK

int vendor\_id FK

int current\_score

string risk\_band

}

TBL\_VENDOR\_RISK\_EVENTS {

int id PK

int vendor\_id FK

int score\_impact

string source\_module

}

TBL\_SUPERVISOR\_SCORE {

int id PK

int supervisor\_id FK

int current\_score

string risk\_band

}

TBL\_SUPERVISOR\_SCORE\_EVENTS {

int id PK

int supervisor\_id FK

int score\_change

string source\_module

}

TBL\_AUDIT\_REPORTS {

int id PK

int vendor\_id FK

string overall\_result

}

TBL\_AUDIT\_FINDINGS {

int id PK

int audit\_report\_id FK

string finding\_type

string severity

}

TBL\_SCORE\_DECAY\_LOG {

int id PK

string entity\_type

int entity\_id

int score\_reduction

}

**4. CRITICAL SYSTEM RULES (DEVELOPER MUST FOLLOW)**

Tell your developer **explicitly**:

1. ❌ Never overwrite risk scores
2. ✔ Always calculate from **events table**
3. ✔ Score snapshots are **derived**, not primary
4. ✔ Fatality / insurance lapse overrides decay
5. ✔ Score history must be immutable

This is **non-negotiable**.

**5. DOC 5 STATUS**

✅ Vendor & Supervisor risk engines formalised
✅ Scoring fully auditable
✅ Fairness & decay logic supported
✅ No overlap with operational tables
✅ Developer-safe

**DOC 5 IS NOW LOCKED**