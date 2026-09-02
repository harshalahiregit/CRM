**DOC 2 — TPV & COMMERCIAL CORE**

**ER DIAGRAM + TABLE DEFINITION (FINAL)**

**1. SCOPE OF DOC 2 (BOUNDARY CLEAR)**

**Included (from your master sequence):**

* TPV Master Registration
* Sub-Vendor & Chain-of-Responsibility
* Contracts & Work Orders
* Deviation & Change Control
* Penalty Management
* Award & Reward
* Invoice & Payment Tracking
* Legal Notices & Claims
* Vendor Exit & Offboarding

**Explicitly NOT included (later docs):**

* Workforce / supervisors
* Safety observations
* PTW / incidents
* Risk scoring
* Dashboards

👉 This document defines **commercial authority, accountability, and money flow**.

**2. CORE ENTITIES (TABLE LIST)**

**2.1 TPV Vendor Master**

tbl\_tpv\_vendors

---------------

id (PK)

client\_id

project\_id

vendor\_name

vendor\_category

legal\_entity\_type

authorized\_signatory

status -- draft / active / suspended / closed

onboarded\_at

**2.2 Sub-Vendor Mapping (Chain of Responsibility)**

tbl\_tpv\_sub\_vendors

-------------------

id (PK)

parent\_vendor\_id (FK → tbl\_tpv\_vendors.id)

sub\_vendor\_name

scope\_description

status

**Rule:** Parent vendor remains fully accountable
(this is enforced at logic level, not UI)

**2.3 Contracts / Work Orders**

tbl\_tpv\_work\_orders

-------------------

id (PK)

vendor\_id (FK)

contract\_number

scope\_summary

boq\_reference

start\_date

end\_date

status -- draft / approved / active / closed

**2.4 Deviation & Change Management**

tbl\_tpv\_deviations

------------------

id (PK)

work\_order\_id (FK → tbl\_tpv\_work\_orders.id)

change\_type -- scope / manpower / equipment

risk\_impact\_level

justification

approval\_status -- pending / approved / rejected

approved\_by\_role\_id

approved\_at

**2.5 Penalty Register**

tbl\_tpv\_penalties

-----------------

id (PK)

vendor\_id (FK)

source\_type -- safety / delay / compliance

source\_reference\_id

penalty\_amount

remarks

status -- imposed / recovered / waived

**2.6 Award & Reward Register**

tbl\_tpv\_rewards

---------------

id (PK)

vendor\_id (FK)

reward\_type -- safety / performance / compliance

description

reward\_value

awarded\_at

**2.7 Invoice Submission**

tbl\_tpv\_invoices

----------------

id (PK)

vendor\_id (FK)

work\_order\_id (FK)

invoice\_number

invoice\_amount

submitted\_date

status -- submitted / approved / rejected

**2.8 Payment Tracking**

tbl\_tpv\_payments

----------------

id (PK)

invoice\_id (FK → tbl\_tpv\_invoices.id)

paid\_amount

payment\_date

payment\_status -- paid / partial / pending

**2.9 Legal Notices & Claims**

tbl\_tpv\_legal\_cases

-------------------

id (PK)

vendor\_id (FK)

case\_type -- notice / claim / back-charge

reference\_number

amount\_claimed

status -- open / resolved / escalated

**2.10 Vendor Exit Analysis**

tbl\_tpv\_exit\_analysis

---------------------

id (PK)

vendor\_id (FK)

exit\_reason

performance\_summary

eligibility\_future -- yes / no / conditional

recorded\_at

**2.11 TPV Offboarding**

tbl\_tpv\_offboarding

-------------------

id (PK)

vendor\_id (FK)

offboarded\_at

final\_status

remarks

**3. VISUAL ER DIAGRAM (MERMAID – DOC 2)**

👉 Paste into [**https://mermaid.live**](https://mermaid.live/)
Export PNG / PDF → share with developer

erDiagram

TBL\_TPV\_VENDORS ||--o{ TBL\_TPV\_SUB\_VENDORS : controls

TBL\_TPV\_VENDORS ||--o{ TBL\_TPV\_WORK\_ORDERS : executes

TBL\_TPV\_WORK\_ORDERS ||--o{ TBL\_TPV\_DEVIATIONS : allows

TBL\_TPV\_VENDORS ||--o{ TBL\_TPV\_PENALTIES : incurs

TBL\_TPV\_VENDORS ||--o{ TBL\_TPV\_REWARDS : earns

TBL\_TPV\_WORK\_ORDERS ||--o{ TBL\_TPV\_INVOICES : billed\_under

TBL\_TPV\_INVOICES ||--o{ TBL\_TPV\_PAYMENTS : settled\_by

TBL\_TPV\_VENDORS ||--o{ TBL\_TPV\_LEGAL\_CASES : faces

TBL\_TPV\_VENDORS ||--|| TBL\_TPV\_EXIT\_ANALYSIS : reviewed\_in

TBL\_TPV\_VENDORS ||--|| TBL\_TPV\_OFFBOARDING : closed\_by

TBL\_TPV\_VENDORS {

int id PK

string vendor\_name

string vendor\_category

string status

}

TBL\_TPV\_SUB\_VENDORS {

int id PK

int parent\_vendor\_id FK

string sub\_vendor\_name

string status

}

TBL\_TPV\_WORK\_ORDERS {

int id PK

int vendor\_id FK

string contract\_number

string status

}

TBL\_TPV\_DEVIATIONS {

int id PK

int work\_order\_id FK

string change\_type

string approval\_status

}

TBL\_TPV\_PENALTIES {

int id PK

int vendor\_id FK

float penalty\_amount

string status

}

TBL\_TPV\_REWARDS {

int id PK

int vendor\_id FK

string reward\_type

float reward\_value

}

TBL\_TPV\_INVOICES {

int id PK

int vendor\_id FK

float invoice\_amount

string status

}

TBL\_TPV\_PAYMENTS {

int id PK

int invoice\_id FK

float paid\_amount

string payment\_status

}

TBL\_TPV\_LEGAL\_CASES {

int id PK

int vendor\_id FK

string case\_type

string status

}

TBL\_TPV\_EXIT\_ANALYSIS {

int id PK

int vendor\_id FK

string exit\_reason

string eligibility\_future

}

TBL\_TPV\_OFFBOARDING {

int id PK

int vendor\_id FK

datetime offboarded\_at

string final\_status

}

**4. DEVELOPER HANDOVER NOTE (COPY–PASTE)**

Give this exact instruction:

“This is **DOC 2 – TPV & Commercial Core ER**.
These tables handle **vendors, contracts, money, penalties, and exits**.
Do not merge safety or workforce logic here.
Follow this ER strictly.”

**5. DOC 2 STATUS**

✅ Commercial scope isolated
✅ Sub-vendor risk handled
✅ Money flow traceable
✅ Legal & exit defensible
✅ ER ready for coding

**DOC 2 IS NOW LOCKED**