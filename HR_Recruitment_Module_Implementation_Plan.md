# HR Recruitment Module - Required Changes

## Objective
Enhance the existing HR Recruitment Module based on the latest business requirements. Do not redesign the module; extend the current implementation while maintaining clean architecture and full multi-tenant compatibility.

---

# 1. Manpower Request Enhancements

Enhance the existing Manpower Request form by adding:

- Business Unit
- Department
- Project
- Project Location
- Job Title
- Number of Positions
- Employee Level
- Employment Type
- Experience
- Salary Range
- Required Skills
- Job Description
- Hiring Justification
- Requested By
- Target Joining Date

The request should provide complete hiring information before HR starts recruitment.

---

# 2. Approval Workflow

Replace the current workflow with:

Department User
→ Department Head Approval (L1)
→ Management Approval (L2)
→ HR Queue
→ Convert to Job Description
→ Job Posting

HR should not be able to start recruitment until both approvals are completed.

---

# 3. Request Status

Implement workflow statuses:

- Draft
- Submitted
- L1 Pending
- L1 Approved
- L2 Pending
- L2 Approved
- Ready for HR
- Converted to JD
- Job Posted
- Hiring in Progress
- Closed

---

# 4. HR Queue

Display only requests with completed approvals.

Available actions:

- View Request
- Convert to Job Description
- Edit Job Description
- Publish Job

---

# 5. Request to JD Conversion

Implement automatic conversion.

Approved Request
↓

Job Description Draft

↓

HR edits only missing information

↓

Publish Job

Avoid duplicate data entry.

---

# 6. Dashboard Enhancements

Show:

- Total Requests
- L1 Pending
- L2 Pending
- Ready for HR
- Converted to JD
- Posted Jobs
- Active Hiring
- Closed Positions

---

# 7. Candidate Workflow

Job Posting
↓

Candidate
↓

Screening
↓

Interview
↓

Offer Letter
↓

Onboarding
↓

Employee

---

# 8. Future AIR OS Ready

Keep the architecture ready for:

- Resume Intelligence
- AI Resume Parsing
- Candidate Intelligence Report
- Prediction Engine
- AI Interview Scoring
- Offer Prediction
- Retention Prediction
- Employee Intelligence

Do not implement AI now; prepare the architecture for future integration.

---

# Development Guidelines

- Keep existing functionality intact.
- Follow the current project architecture.
- Write clean, reusable, production-ready code.
- Avoid duplicate logic.
- Maintain backward compatibility.
- Ensure every feature is fully compatible with the existing multi-tenant (separate database) architecture.
- Reuse existing components wherever possible.
- Follow proper permissions and role-based access.