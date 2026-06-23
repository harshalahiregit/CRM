# Product Requirements Document (PRD)
## Multi-Tenant SaaS CRM Platform

---

## 1. OVERVIEW

**Product Name:** MLA Perfex CRM (Multi-Tenant SaaS)  
**Version:** 1.0  
**Target Users:** SMBs, Agencies, Enterprises  
**Deployment:** Cloud-based SaaS with per-tenant subdomains  

### Vision
A scalable, multi-tenant Customer Relationship Management system that enables businesses to manage contacts, deals, projects, invoices, and vendor relationships in a unified platform. Each tenant operates in isolation with dedicated data, branding, and user permissions.

---

## 2. GOALS

### Business Goals
- **Scalability:** Support 1000+ tenants on single infrastructure
- **Revenue:** Tiered SaaS pricing (Starter, Professional, Enterprise)
- **Retention:** Feature-rich, low-churn platform
- **Time-to-Value:** Customers operational in <24 hours

### User Goals
- **Speed:** Manage contacts, deals, tasks in single interface
- **Customization:** Configure fields, workflows per tenant
- **Integration:** API-ready for third-party tools
- **Collaboration:** Assign tasks, share deals, track progress

---

## 3. CORE REQUIREMENTS

### 3.1 AUTH + TENANT ONBOARDING
- Self-serve tenant registration with email verification
- Subdomain assignment (e.g., acme.app.com)
- Plan selection (Starter, Professional, Enterprise)
- Multi-role support: Owner, Admin, User, Vendor
- SSO-ready (OAuth2 framework, not MVP)

### 3.2 CONTACTS / LEADS / DEALS (CRM CORE)
- **Contacts:** Store name, email, phone, address, custom fields
- **Leads:** Pipeline tracking with stages (New, Qualified, Proposal, Won/Lost)
- **Deals:** Value, expected close date, associated contacts, notes
- **Bulk Import:** CSV upload for contacts/leads
- **Search + Filter:** Global search, advanced filters by status/value/date
- **Activity Timeline:** Log calls, emails, meetings per contact/deal

### 3.3 TASKS + PROJECTS
- Create tasks linked to deals, contacts, or standalone
- Assign to users, set due dates, priority levels
- Project grouping with kanban board view
- Reminders + notifications on due date
- Time tracking (optional, Phase 2)

### 3.4 INVOICING + PAYMENTS
- Generate invoices from deals
- Line item support with tax/discount
- Payment tracking (Pending, Paid, Overdue)
- Stripe/Razorpay integration
- Email invoice to customer
- Payment reminder automation

### 3.5 PURCHASE + TPV (THIRD-PARTY VENDOR)
- Vendor portal (separate login, sub-tenant isolation)
- Vendor assignment to tasks/projects
- Purchase order creation and tracking
- Vendor performance metrics
- Communication thread per order

### 3.6 SUPPORT (TICKETS)
- Customer ticket submission
- Status tracking (Open, In Progress, Resolved, Closed)
- Auto-assignment to support agents
- Ticket history + knowledge base link (Phase 2)

### 3.7 REPORTS + DASHBOARD
- Sales dashboard: Pipeline value, win rate, forecast
- User activity: Tasks completed, calls logged
- Revenue report: Invoice status, payment trends
- Custom report builder (Phase 2)
- Export to CSV/PDF

### 3.8 CUSTOMER SALE (FUTURE)
- E-commerce integration (products, orders, fulfillment)
- Or: Link to external Shopify/WooCommerce

---

## 4. OUT OF SCOPE (MVP)

- SSO / SAML integration (Phase 2)
- Advanced AI/Automation (Phase 2)
- Mobile app (Phase 2)
- Custom fields on all modules (basic only in MVP)
- Accounting integration (Xero, QBO) — Phase 2
- Marketing automation workflows (Phase 2)

---

## 5. PRICING TIERS

| Feature | Starter | Professional | Enterprise |
|---------|---------|--------------|-----------|
| Tenants | 1 | Unlimited | Unlimited |
| Users | 3 | Unlimited | Unlimited |
| Contacts | 500 | Unlimited | Unlimited |
| Storage | 1 GB | 50 GB | 500 GB |
| API Access | No | Yes | Yes + Priority |
| Vendor Portal | No | Yes | Yes |
| Price | $49/mo | $149/mo | Custom |

---

## 6. SUCCESS METRICS

- **Adoption:** 100 signups in month 1
- **Retention:** 90% month-over-month churn
- **NPS:** >40 (target)
- **Performance:** Page load <2s, API response <500ms
- **Uptime:** 99.9% SLA

---

## 7. ASSUMPTIONS & CONSTRAINTS

### Assumptions
- Users have modern browsers (Chrome, Firefox, Safari, Edge)
- Single DB per platform (multi-tenancy via `tenant_id` column)
- Stancl/tenancy Laravel package for subdomain routing
- Stripe for payment processing

### Constraints
- **Timeline:** MVP launch in 8 weeks
- **Budget:** Eng team + AWS infrastructure
- **Compliance:** GDPR-ready (data export per tenant)

---

## 8. ACCEPTANCE CRITERIA

- ✅ Tenant signup → subdomain live in <5 min
- ✅ CRM core (contacts/deals/tasks) fully functional
- ✅ Invoice generation + payment acceptance working
- ✅ User can export all personal data (GDPR)
- ✅ 99.5% uptime across test period
- ✅ No data leakage between tenants (security audit passed)
