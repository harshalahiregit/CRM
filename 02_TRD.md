# Technical Requirements Document (TRD)
## Multi-Tenant SaaS CRM Platform

---

## 1. TECHNOLOGY STACK

### Frontend
| Component | Technology | Rationale |
|-----------|-----------|-----------|
| Framework | React 18+ | Component reusability, ecosystem |
| Build Tool | Vite | Fast dev, optimized prod builds |
| Styling | Tailwind CSS | Utility-first, fast styling |
| State Management | TanStack Query (React Query) | API sync, caching, pagination |
| Forms | React Hook Form + Zod | Lightweight validation |
| UI Components | shadcn/ui | Headless, accessible, customizable |
| Charts | Recharts | React-native, responsive |
| Real-time | Socket.io (client) | Live notifications, chat |

### Backend
| Component | Technology | Rationale |
|-----------|-----------|-----------|
| Framework | Laravel 11 | Eloquent ORM, built-in auth |
| API | REST + JSON | Standard, easy to test |
| Auth | Laravel Sanctum | Token-based, multi-device |
| Tenancy | stancl/tenancy | Subdomain routing, automatic tenant binding |
| Database | MySQL 8+ | Proven, reliable, good query performance |
| Queue | Laravel Queues + Redis | Async jobs (invoice, email) |
| Cache | Redis | Session, rate limiting |
| Storage | S3 / Local | File uploads, documents |
| Mailing | Laravel Mail + SendGrid | Transactional email |
| Validation | Laravel Validation | Server-side request validation |

### Infrastructure
| Component | Technology | Rationale |
|-----------|-----------|-----------|
| Hosting | AWS (EC2 + RDS) | Scalable, managed services |
| CDN | CloudFront | Asset delivery, global caching |
| Storage | S3 | File uploads, backups |
| Monitoring | CloudWatch + Sentry | Error tracking, performance |
| CI/CD | GitHub Actions | Automated testing, deployment |

---

## 2. ARCHITECTURE OVERVIEW

```
┌─────────────────────────────────────────────────────┐
│                   CLIENT LAYER                       │
│  React + Vite (acme.app.com, demo.app.com, etc.)    │
└────────────────────┬────────────────────────────────┘
                     │
         ┌───────────┴───────────┐
         │   CDN / CloudFront    │
         └───────────┬───────────┘
                     │
┌────────────────────┴────────────────────────────────┐
│              API GATEWAY / NGINX                      │
│  (TLS, Rate limiting, CORS, Request logging)         │
└────────────────────┬────────────────────────────────┘
                     │
┌────────────────────┴────────────────────────────────┐
│        LARAVEL 11 API (api.app.com)                  │
│  ┌──────────────────────────────────────────────┐   │
│  │ Stancl Tenancy Middleware                     │   │
│  │ - Extract tenant from subdomain              │   │
│  │ - Inject tenant_id into queries              │   │
│  └──────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────┐   │
│  │ Sanctum Auth (Token-based)                    │   │
│  │ - Verify tenant_id in token                  │   │
│  │ - Multi-role support                         │   │
│  └──────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────┐   │
│  │ Controllers / Business Logic                  │   │
│  │ - ContactController, DealController, etc.    │   │
│  └──────────────────────────────────────────────┘   │
│  ┌──────────────────────────────────────────────┐   │
│  │ Eloquent Models + Scopes                      │   │
│  │ - Auto-filter by tenant_id                   │   │
│  └──────────────────────────────────────────────┘   │
└────────────────────┬────────────────────────────────┘
         │           │           │
    ┌────┴──┐   ┌────┴──┐   ┌───┴────┐
    │  MySQL│   │ Redis │   │   S3   │
    │  RDS  │   │ Cache │   │ Storage│
    └───────┘   └───────┘   └────────┘
         │           │
    ┌────┴───────────┴────┐
    │  Laravel Queue      │
    │  (Async Jobs)       │
    └─────────────────────┘
```

---

## 3. MULTI-TENANCY MODEL

### Approach: Hybrid (Single DB + tenant_id)
- **Single MySQL database** with `tenant_id` column on all tables
- **Stancl/tenancy package** for subdomain routing and automatic binding
- **Row-level isolation** via Eloquent scopes
- **Shared infrastructure** (same server, Redis, S3)

### Benefits
✅ Lower operational complexity than separate DBs  
✅ Easier schema migrations and backups  
✅ Efficient resource utilization  
✅ Cross-tenant analytics possible (if needed)

### Security
- Sanctum tokens include `tenant_id`
- Every query scoped to authenticated tenant
- No cross-tenant queries in codebase
- Audit logging for sensitive operations

---

## 4. DATABASE SCHEMA (CORE)

### Base Columns (All Tables)
```
- id (uuid or bigint auto-increment)
- tenant_id (foreign key → tenants.id, indexed)
- created_at, updated_at (timestamps)
- deleted_at (soft deletes, nullable)
```

### Core Tables

#### tenants
```
id, name, slug, subdomain, plan_id, 
status (active/suspended), 
created_at, updated_at
```

#### users
```
id, tenant_id, name, email, password, role 
(owner/admin/user/vendor), phone, avatar_url, 
is_active, last_login_at, created_at, updated_at
```

#### contacts
```
id, tenant_id, first_name, last_name, email, phone, 
company, job_title, address, city, state, zip, country, 
source (website/referral/cold/import), custom_fields (JSON), 
created_by (user_id), created_at, updated_at, deleted_at
```

#### leads
```
id, tenant_id, contact_id, title, value (decimal), 
currency, stage (new/qualified/proposal/won/lost), 
expected_close_date, assigned_to (user_id), 
priority (low/medium/high), notes, 
created_at, updated_at, deleted_at
```

#### deals (synonym for leads with more fields)
```
id, tenant_id, contact_id, title, value, 
currency, stage, probability (0-100%), 
expected_close_date, assigned_to, lost_reason (nullable), 
custom_fields (JSON), created_at, updated_at, deleted_at
```

#### projects
```
id, tenant_id, name, description, status (active/completed/archived), 
start_date, end_date, owner_id (user_id), budget (nullable), 
created_at, updated_at, deleted_at
```

#### tasks
```
id, tenant_id, project_id (nullable), deal_id (nullable), 
contact_id (nullable), title, description, 
assigned_to (user_id), priority (1-5), status (todo/in_progress/done), 
due_date, completed_at (nullable), created_by (user_id), 
created_at, updated_at, deleted_at
```

#### invoices
```
id, tenant_id, contact_id (nullable), deal_id (nullable), 
invoice_number (unique per tenant), issue_date, due_date, 
subtotal, tax, discount, total, currency, status 
(draft/sent/paid/overdue/cancelled), paid_at (nullable), 
notes, created_at, updated_at, deleted_at
```

#### invoice_items
```
id, invoice_id, description, quantity, unit_price, 
tax_percent, total, created_at, updated_at
```

#### payments
```
id, tenant_id, invoice_id, amount, currency, status 
(pending/completed/failed), payment_method 
(stripe/razorpay/bank_transfer), transaction_id, 
paid_at, created_at, updated_at
```

#### vendors
```
id, tenant_id, name, email, phone, company, category, 
portal_access (boolean), user_id (nullable, if registered), 
contract_url, status (active/inactive), 
created_at, updated_at, deleted_at
```

#### purchase_orders
```
id, tenant_id, vendor_id, deal_id (nullable), order_number, 
order_date, expected_delivery, status (draft/sent/received/cancelled), 
subtotal, tax, total, currency, notes, created_at, updated_at, deleted_at
```

#### purchase_order_items
```
id, purchase_order_id, description, quantity, unit_price, 
tax_percent, total, created_at, updated_at
```

#### tickets
```
id, tenant_id, contact_id (nullable), title, description, 
priority (1-5), status (open/in_progress/resolved/closed), 
assigned_to (user_id, nullable), created_by (user_id), 
created_at, updated_at, resolved_at (nullable), deleted_at
```

#### activity_logs
```
id, tenant_id, user_id, loggable_type (Contact/Deal/Invoice/Task), 
loggable_id, action (created/updated/deleted/called/emailed), 
description, ip_address, created_at
```

#### audit_logs
```
id, tenant_id, user_id, resource_type, resource_id, 
action, before (JSON), after (JSON), created_at
```

---

## 5. API DESIGN

### Base URL
```
https://api.app.com/api
```

### Authentication Header
```
Authorization: Bearer {sanctum_token}
X-Tenant-ID: {tenant_id} (optional, extracted from token)
```

### Response Format
```json
{
  "status": "success|error",
  "data": {},
  "message": "...",
  "errors": []
}
```

### Rate Limiting
- 1000 requests / hour per tenant
- 429 Too Many Requests on limit exceeded

---

## 6. KEY TECHNICAL DECISIONS

| Decision | Rationale |
|----------|-----------|
| **Single DB + tenant_id** | Simpler ops, easier migrations than separate DBs per tenant |
| **Stancl/tenancy** | Automatic subdomain binding, reduces boilerplate |
| **Redis queue** | Decouples invoice generation, email, from request-response cycle |
| **S3 storage** | Scalable file storage, not limited by server disk |
| **Sanctum tokens** | Lightweight auth, no session server needed |
| **React Query (TanStack)** | Smart caching, automatic refetch, optimistic updates |
| **Tailwind CSS** | Fast styling, consistent design system, easy theming |

---

## 7. PERFORMANCE TARGETS

| Metric | Target |
|--------|--------|
| Page Load Time | <2s (first contentful paint) |
| API Response | <500ms (p95) |
| Database Query | <200ms (p95) |
| Search Results | <1s (1000 records) |
| Uptime | 99.9% |
| Error Rate | <0.1% |

---

## 8. SECURITY REQUIREMENTS

- ✅ TLS 1.2+ for all traffic
- ✅ Password hashing: bcrypt (Laravel default)
- ✅ CSRF protection: Laravel built-in
- ✅ SQL injection: Eloquent parameterized queries
- ✅ XSS prevention: React auto-escaping
- ✅ No cross-tenant data leakage (audit required)
- ✅ Rate limiting on auth endpoints
- ✅ API key rotation for integrations
- ✅ Audit logging on sensitive operations

---

## 9. DEPLOYMENT & SCALING

### Initial Deployment
- **Compute:** AWS EC2 (t3.large, auto-scaling group)
- **Database:** AWS RDS MySQL (db.t3.medium, Multi-AZ)
- **Cache:** AWS ElastiCache Redis (cache.t3.micro)
- **Storage:** S3 (lifecycle policies for old files)
- **CDN:** CloudFront for static assets
- **Monitoring:** CloudWatch, Sentry for errors

### Scaling Strategy
- **Horizontal:** Add EC2 instances behind ALB
- **Database:** Read replicas for analytics queries
- **Cache:** ElastiCache cluster mode for higher throughput
- **Tenants:** Separate DB per tenant at 1000+ users/tenant (Phase 2)

---

## 10. INTEGRATION POINTS

### Future Integrations (Phase 2+)
- Stripe / Razorpay (payments) — MVP
- SendGrid (email) — MVP
- Slack (notifications)
- Zapier (workflow automation)
- Google Calendar (event sync)
- Xero / QBO (accounting)
