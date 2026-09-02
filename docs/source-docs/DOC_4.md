**DOC 4 — SAFETY OPERATIONS CORE**

**(PTW • Observations • Incidents • CAPA • Emergency)**

**1. SCOPE OF DOC 4 (BOUNDARY LOCK)**

**Included**

* Permit to Work (PTW)
* Method Statement & JSA
* Unsafe Act / Unsafe Condition Observations
* Observation validation, closure & escalation
* Incident & Near-Miss management
* RCA & CAPA
* Stop-Work linkage (from Doc 1)
* Emergency preparedness & mock drills

**Explicitly EXCLUDED**

* Workforce master (Doc 3)
* Vendor commercial logic (Doc 2)
* Risk score engines (Doc 5)
* Dashboards (Doc 6)

👉 This document defines **how work is allowed, monitored, stopped, and investigated**.

**2. CORE ENTITIES (TABLE DEFINITIONS)**

**2.1 Permit to Work (PTW)**

tbl\_ptw

-------

id (PK)

project\_id

vendor\_id

supervisor\_id

ptw\_type -- general / height / hot / electrical / confined

location

valid\_from

valid\_to

status -- requested / approved / active / suspended / closed

requested\_at

**2.2 PTW Approval Workflow**

tbl\_ptw\_approvals

-----------------

id (PK)

ptw\_id (FK → tbl\_ptw.id)

approval\_level -- safety / pmc / client

approved\_by\_role\_id

approval\_status -- pending / approved / rejected

approved\_at

**2.3 Method Statement & JSA**

tbl\_jsa

-------

id (PK)

ptw\_id (FK → tbl\_ptw.id)

version\_no

activity\_description

identified\_hazards

control\_measures

prepared\_by

approved\_by

approved\_at

**Rule:**
High-risk PTW cannot activate without approved JSA

**2.4 Safety Observations (Unsafe Act / Condition)**

tbl\_safety\_observations

-----------------------

id (PK)

project\_id

vendor\_id

supervisor\_id

observer\_staff\_id

observation\_type -- act / condition / both

severity -- low / medium / high

severity\_reason

status -- open / pending / closed

anonymous\_flag

created\_at

**2.5 Observation Evidence**

tbl\_safety\_observation\_evidence

-------------------------------

id (PK)

observation\_id (FK)

file\_path

file\_type -- photo / video

uploaded\_at

**2.6 Observation Validation**

tbl\_safety\_observation\_validation

---------------------------------

id (PK)

observation\_id (FK)

validation\_status -- valid / weak / invalid

validated\_by\_role\_id

remarks

validated\_at

**2.7 Observation Corrective Actions**

tbl\_safety\_observation\_actions

------------------------------

id (PK)

observation\_id (FK)

assigned\_to

action\_description

target\_date

closure\_status

closed\_at

**2.8 Incident & Near-Miss Register**

tbl\_incidents

-------------

id (PK)

project\_id

vendor\_id

supervisor\_id

incident\_type -- near-miss / FA / MTC / LTI / fatal

description

severity

occurred\_at

status -- open / under-investigation / closed

**2.9 Incident RCA (Root Cause Analysis)**

tbl\_incident\_rca

----------------

id (PK)

incident\_id (FK → tbl\_incidents.id)

root\_cause

analysis\_summary

conducted\_by

completed\_at

**2.10 Incident CAPA (Corrective & Preventive Action)**

tbl\_incident\_capa

-----------------

id (PK)

incident\_id (FK)

action\_type -- corrective / preventive

action\_description

responsible\_person

target\_date

status -- open / closed

**2.11 Emergency Preparedness & Mock Drills**

tbl\_emergency\_plans

-------------------

id (PK)

project\_id

emergency\_type -- fire / medical / collapse / chemical

plan\_document

tbl\_mock\_drills

---------------

id (PK)

emergency\_plan\_id (FK)

conducted\_on

participants\_count

observations

improvement\_points

**3. VISUAL ER DIAGRAM (MERMAID – DOC 4)**

👉 Paste into [**https://mermaid.live**](https://mermaid.live/)
Export PNG / PDF and share with your developer

erDiagram

TBL\_PTW ||--o{ TBL\_PTW\_APPROVALS : approved\_by

TBL\_PTW ||--|| TBL\_JSA : governed\_by

TBL\_PTW ||--o{ TBL\_SAFETY\_OBSERVATIONS : linked\_to

TBL\_SAFETY\_OBSERVATIONS ||--o{ TBL\_SAFETY\_OBSERVATION\_EVIDENCE : has

TBL\_SAFETY\_OBSERVATIONS ||--|| TBL\_SAFETY\_OBSERVATION\_VALIDATION : validated\_as

TBL\_SAFETY\_OBSERVATIONS ||--o{ TBL\_SAFETY\_OBSERVATION\_ACTIONS : resolved\_by

TBL\_INCIDENTS ||--|| TBL\_INCIDENT\_RCA : analysed\_by

TBL\_INCIDENTS ||--o{ TBL\_INCIDENT\_CAPA : corrected\_by

TBL\_EMERGENCY\_PLANS ||--o{ TBL\_MOCK\_DRILLS : tested\_by

TBL\_PTW {

int id PK

string ptw\_type

string status

datetime valid\_from

datetime valid\_to

}

TBL\_JSA {

int id PK

int ptw\_id FK

string version\_no

}

TBL\_SAFETY\_OBSERVATIONS {

int id PK

string observation\_type

string severity

string status

}

TBL\_INCIDENTS {

int id PK

string incident\_type

string severity

string status

}

TBL\_INCIDENT\_RCA {

int id PK

int incident\_id FK

}

TBL\_INCIDENT\_CAPA {

int id PK

int incident\_id FK

string status

}

TBL\_EMERGENCY\_PLANS {

int id PK

string emergency\_type

}

TBL\_MOCK\_DRILLS {

int id PK

int emergency\_plan\_id FK

}

**4. CRITICAL SYSTEM RULES (SHARE WITH DEVELOPER)**

Tell your developer:

* ❌ PTW **cannot activate** without approved JSA (if high-risk)
* ❌ Observation **cannot close** without evidence
* ❌ High-severity observation **cannot be closed by supervisor**
* ❌ Incident **cannot close** without RCA + CAPA
* ✔ Stop-Work Authority overrides all PTWs
* ✔ All actions must log to **System Audit Trail (Doc 1)**

**5. DOC 4 STATUS**

✅ PTW & JSA fully governed
✅ Unsafe acts & conditions controlled
✅ Incident investigation defensible
✅ Emergency preparedness auditable
✅ No overlap with other documents

**DOC 4 IS NOW LOCKED**