**PERFEX CRM CLONE**

**Multi-Tenant Enterprise CRM Platform**

Complete Documentation Package

**6 Core Documents**

* Product Requirements Document (PRD)
* Technical Requirements Document (TRD)
* UI/UX Design Brief
* Application Flow & Routing
* Backend Schema & Database Structure
* Implementation Plan & Timeline

Tech Stack: React + Vite + Tailwind | Laravel 11 + Sanctum | MySQL + Redis

Multi-Tenant Architecture with stancl/tenancy Package

# **1. PRODUCT REQUIREMENTS DOCUMENT (PRD)**

## **1.1 Executive Summary**

Perfex CRM Clone is a comprehensive, multi-tenant Customer Relationship Management (CRM) platform designed to help businesses manage customers, leads, deals, tasks, invoices, and support tickets in a unified interface. The platform prioritizes scalability, security, and ease of use for organizations of all sizes.

## **1.2 Goals & Objectives**

1. Provide enterprise-grade CRM capabilities with multi-tenancy support
2. Enable seamless team collaboration with role-based access controls
3. Automate sales processes and customer relationship management
4. Deliver real-time insights through advanced reporting and dashboards
5. Ensure data security and compliance with industry standards
6. Support scalable architecture for SaaS deployment

## **1.3 Core Product Vision**

A modern, cloud-native CRM that empowers sales teams, customer support, and business managers to collaborate effectively. The platform is designed as a multi-tenant SaaS solution, allowing companies to create isolated workspaces while sharing infrastructure.

## **1.4 User Personas & Use Cases**

### **Sales Manager**

* Track leads and deals through sales pipeline
* Monitor team performance and convert rates
* Generate sales forecasts and reports

### **Support Agent**

* Create and manage support tickets
* Track customer issues from open to resolution
* Collaborate with team members on ticket resolution

### **Administrator**

* Manage user accounts and permissions
* Configure CRM settings and workflows
* Monitor system health and backups

## **1.5 Feature Modules (Priority Order)**

1. Auth & Tenant Onboarding: Registration, subdomain setup, plan selection
2. Contacts/Leads/Deals: Core CRM functionality for customer management
3. Tasks & Projects: Team task management and project tracking
4. Invoicing & Payments: Invoice generation and payment processing
5. Purchase & TPV: Purchase order management and third-party vendor integration
6. Support Tickets: Customer support issue tracking
7. Reports & Dashboard: Analytics and business intelligence

## **1.6 Success Metrics**

* System uptime: 99.5%
* Page load time: <2 seconds
* User onboarding completion: >85%
* Data migration success rate: 100%
* Customer support response time: <24 hours

# **2. TECHNICAL REQUIREMENTS DOCUMENT (TRD)**

## **2.1 Technology Stack**

|  |  |
| --- | --- |
| **Layer** | **Technology** |
| Frontend | React 18+ with Vite |
| Styling | Tailwind CSS 3+ |
| Backend | Laravel 11 (PHP 8.2+) |
| Auth | Laravel Sanctum (API tokens) |
| Database | MySQL 8.0+ |
| Cache/Queue | Redis 6.0+ |
| Storage | S3-compatible + Local fallback |
| Tenancy | stancl/tenancy v3 |

## **2.2 Architecture Overview**

### **Multi-Tenant Architecture**

The system uses a Hybrid Multi-Tenancy model:

* Single Shared Database with tenant\_id column on all tables
* Isolated Subdomains: tenant1.app.com, tenant2.app.com (optional)
* Row-Level Security (tenant\_id in WHERE clauses)
* Shared Infrastructure with logical data isolation

### **API-First Architecture**

* RESTful API endpoints for all operations
* Stateless design for horizontal scalability
* Request-based tenant resolution via Sanctum tokens

## **2.3 Non-Functional Requirements**

|  |  |
| --- | --- |
| **Requirement** | **Specification** |
| Performance | API response time <500ms at 99th percentile |
| Scalability | Support 10k+ concurrent users per instance |
| Availability | 99.5% uptime SLA with multi-region capability |
| Security | TLS 1.3, encryption at rest, RBAC, audit logs |
| Compliance | GDPR, SOC 2 Type II ready |

## **2.4 Security Considerations**

* JWT/Sanctum token expiration: 60 minutes
* Refresh token rotation on every request
* CSRF protection on all state-changing endpoints
* Rate limiting: 100 requests/minute per IP
* Data encryption: AES-256 for sensitive fields
* Audit logs: All user actions tracked with timestamps
* Tenant isolation: Queries automatically filtered by tenant\_id

# **3. UI/UX DESIGN BRIEF**

## **3.1 Design Principles**

* Simplicity: Minimal cognitive load for users
* Consistency: Uniform components across all modules
* Efficiency: Quick access to frequently used features
* Responsiveness: Mobile-first design approach
* Accessibility: WCAG 2.1 AA compliance

## **3.2 Design System**

### **Color Palette**

* Primary: #2E75B6 (Professional Blue)
* Secondary: #44B578 (Success Green)
* Danger: #E74C3C (Alert Red)
* Neutral: #F5F5F5, #333333

### **Typography**

* Headings: Inter Bold
* Body: Inter Regular
* Monospace: JetBrains Mono (for data)
* Font Scale: 12px, 14px, 16px, 18px, 24px, 32px

### **Component Library**

* Button variants: Primary, Secondary, Danger, Ghost
* Form fields: Text input, Select, Checkbox, Radio, Date picker
* Cards: Content cards, stat cards, action cards
* Tables: Sortable, filterable, paginated
* Modals: Confirmation, form submission, alerts
* Navigation: Sidebar, top navbar, breadcrumbs

## **3.3 Layout Structure**

### **Main Application Layout**

* Left Sidebar: Navigation menu (collapsible on mobile)
* Top Navbar: User profile, notifications, search
* Main Content: Dynamic based on selected module
* Right Panel (optional): Quick actions or detailed view

## **3.4 Key Screens Overview**

|  |  |
| --- | --- |
| **Screen** | **Purpose** |
| Dashboard | Overview of key metrics and recent activities |
| Contacts List | Searchable, filterable list of all contacts |
| Deal Board | Kanban-style pipeline view of deals |
| Task Calendar | Calendar and list view of tasks |
| Invoice Manager | Create, send, track invoices |
| Support Queue | Ticket management with priority and status |

# **4. APPLICATION FLOW & ROUTING**

## **4.1 Authentication Flow**

### **Registration Flow**

1. User visits signup page
2. Enters email, company name, password
3. System creates tenant (generates subdomain)
4. Sends verification email
5. User confirms email
6. Redirects to onboarding (plan selection)
7. Tenant activated, user logged in

### **Login Flow**

1. User visits login page
2. Enters email and password
3. System verifies credentials + tenant
4. Issues Sanctum API token
5. Redirects to dashboard

## **4.2 Frontend Route Structure**

**/auth**

* /auth/register - Signup form
* /auth/login - Login form
* /auth/forgot-password - Password recovery

**/app**

* /app/dashboard - Main dashboard
* /app/contacts - Contact management
* /app/leads - Lead pipeline
* /app/deals - Deal management (Kanban view)
* /app/deals/:id - Deal detail view
* /app/tasks - Task management
* /app/projects - Project management
* /app/invoices - Invoice management
* /app/invoices/create - Create new invoice
* /app/purchases - Purchase orders
* /app/support/tickets - Support tickets
* /app/reports - Analytics & reports
* /app/settings - Admin settings

## **4.3 User Journey Maps**

### **Sales Rep: Create & Track Deal**

1. Login to app
2. Navigate to Leads section
3. Create new lead from contact
4. Convert lead to deal
5. Move deal through pipeline (Kanban)
6. Add tasks and followups
7. View deal history and communications
8. Mark deal as won/lost

### **Admin: Setup Team & Permissions**

1. Login to Settings
2. Create user accounts
3. Assign roles (Admin, Manager, User)
4. Set module permissions per role
5. Configure team assignments
6. Enable/disable features

# **5. BACKEND SCHEMA & DATABASE STRUCTURE**

## **5.1 Core Database Entities**

### **Tenants Table (Multi-Tenancy Core)**

|  |  |  |  |
| --- | --- | --- | --- |
| **Column** | **Type** | **Key** | **Notes** |
| id | UUID | PK | Unique tenant ID |
| name | string | - | Company name |
| subdomain | string | UNIQUE | tenant1.app.com |
| plan\_id | FK | - | Subscription plan |
| status | enum | - | active, suspended, deleted |
| created\_at | timestamp | - | Tenant creation date |

### **Users Table (Multi-Tenant)**

**All rows contain tenant\_id foreign key:**

|  |  |  |  |
| --- | --- | --- | --- |
| **Column** | **Type** | **Key** | **Notes** |
| id | UUID | PK |  |
| tenant\_id | FK | INDEX | Tenant isolation |
| name | string | - |  |
| email | string | INDEX | Unique per tenant |
| role | enum | - | admin, manager, user |
| status | enum | - | active, inactive |
| created\_at | timestamp | - |  |

### **Contacts Table (Core CRM Entity)**

|  |  |  |  |
| --- | --- | --- | --- |
| **Column** | **Type** | **Key** | **Notes** |
| id | UUID | PK |  |
| tenant\_id | FK | INDEX | Tenant isolation |
| name | string | - | Contact name |
| email | string | - | Contact email |
| phone | string | - | Contact phone |
| company | string | - | Associated company |
| source | enum | - | web, email, call, etc. |
| created\_at | timestamp | - |  |

### **Leads Table (Sales Pipeline)**

|  |  |  |  |
| --- | --- | --- | --- |
| **Column** | **Type** | **Key** | **Notes** |
| id | UUID | PK |  |
| tenant\_id | FK | INDEX |  |
| contact\_id | FK | INDEX | Reference to Contact |
| status | enum | - | new, qualified, lost, converted |
| value | decimal | - | Lead value in currency |
| assigned\_to | FK | - | User ID |
| created\_at | timestamp | - |  |

### **Deals Table (Opportunities)**

|  |  |  |  |
| --- | --- | --- | --- |
| **Column** | **Type** | **Key** | **Notes** |
| id | UUID | PK |  |
| tenant\_id | FK | INDEX |  |
| contact\_id | FK | INDEX |  |
| stage | enum | INDEX | prospecting, qualified, proposal, etc. |
| value | decimal | - | Expected deal value |
| probability | integer | - | 0-100% close probability |
| close\_date | date | - | Expected close date |
| assigned\_to | FK | - | Sales owner |
| created\_at | timestamp | - |  |

### **Tasks Table**

|  |  |  |  |
| --- | --- | --- | --- |
| **Column** | **Type** | **Key** | **Notes** |
| id | UUID | PK |  |
| tenant\_id | FK | INDEX |  |
| title | string | - |  |
| description | text | - |  |
| status | enum | - | todo, in\_progress, done |
| priority | enum | - | low, medium, high |
| due\_date | date | - |  |
| assigned\_to | FK | - | User ID |
| created\_at | timestamp | - |  |

### **Invoices Table**

|  |  |  |  |
| --- | --- | --- | --- |
| **Column** | **Type** | **Key** | **Notes** |
| id | UUID | PK |  |
| tenant\_id | FK | INDEX |  |
| contact\_id | FK | INDEX |  |
| invoice\_number | string | UNIQUE | INV-2024-001 |
| status | enum | - | draft, sent, paid, overdue |
| total\_amount | decimal | - |  |
| due\_date | date | - |  |
| created\_at | timestamp | - |  |

### **Support Tickets Table**

|  |  |  |  |
| --- | --- | --- | --- |
| **Column** | **Type** | **Key** | **Notes** |
| id | UUID | PK |  |
| tenant\_id | FK | INDEX |  |
| contact\_id | FK | INDEX |  |
| title | string | - |  |
| description | text | - |  |
| status | enum | INDEX | open, in\_progress, resolved, closed |
| priority | enum | - | low, medium, high, critical |
| assigned\_to | FK | - | Support agent |
| created\_at | timestamp | - |  |

## **5.2 Key Database Principles**

* Every table has tenant\_id column for isolation
* Composite index on (tenant\_id, id) for queries
* Foreign keys reference same-tenant records only
* Soft deletes on transactional tables (invoices, deals)
* Audit log table tracks all modifications

# **6. IMPLEMENTATION PLAN & TIMELINE**

## **6.1 Project Phases**

### **Phase 1: Foundation (Weeks 1-2)**

1. Laravel project setup with Sanctum & stancl/tenancy
2. Database schema design and migration creation
3. React + Vite + Tailwind scaffolding
4. Basic layout components and design system
5. Developer environment setup (Docker, CI/CD)
6. Git repository and collaboration setup

### **Phase 2: Authentication & Tenancy (Weeks 3-4)**

1. User registration and email verification
2. Tenant creation workflow
3. Login and API token generation
4. Subdomain routing (optional)
5. Role-based access control (RBAC)
6. Admin panel basics

### **Phase 3: Core CRM Module (Weeks 5-8)**

1. Contact management (CRUD operations)
2. Lead creation and qualification
3. Deal pipeline with Kanban view
4. Activity timeline on contacts/deals
5. Email integration for communications
6. File attachments on records

### **Phase 4: Tasks & Projects (Weeks 9-10)**

1. Task creation and assignment
2. Task calendar view
3. Project management basics
4. Task comments and collaborations
5. Task reminders and notifications

### **Phase 5: Invoicing & Payments (Weeks 11-12)**

1. Invoice template design
2. Invoice creation and editing
3. Payment tracking (Stripe integration)
4. Invoice email sending
5. Payment history and reports

### **Phase 6: Support Module (Week 13)**

1. Ticket creation from customer portal
2. Ticket assignment and escalation
3. Ticket lifecycle management
4. Customer self-service portal

### **Phase 7: Reports & Dashboard (Week 14)**

1. Sales dashboard with key metrics
2. Deal pipeline analytics
3. Team performance reports
4. Revenue and forecast reports
5. Custom report builder

### **Phase 8: Optimization & Testing (Week 15-16)**

1. Performance optimization
2. Security audit and hardening
3. Load testing
4. User acceptance testing (UAT)
5. Documentation and training

## **6.2 Development Team Structure**

|  |  |  |  |
| --- | --- | --- | --- |
| **Role** | **Count** | **Responsibility** | **Hours/Week** |
| Backend Lead | 1 | Laravel architecture, API design | 40 |
| Frontend Lead | 1 | React architecture, UI components | 40 |
| Full-stack Dev | 2 | Feature implementation | 40 each |
| DevOps Engineer | 1 | Infrastructure, CI/CD, monitoring | 40 |
| QA Tester | 1 | Testing, bug reporting | 40 |

## **6.3 Critical Milestones**

|  |  |  |  |
| --- | --- | --- | --- |
| **Milestone** | **Week** | **Deliverable** | **Status** |
| M1: Foundation | Week 2 | Backend & frontend scaffolding | Checkpoint |
| M2: Auth MVP | Week 4 | User registration & login working | Checkpoint |
| M3: CRM Core | Week 8 | Contacts, leads, deals functional | Checkpoint |
| M4: Full Feature | Week 14 | All modules implemented | Checkpoint |
| M5: Launch Ready | Week 16 | Security audit, performance tuned | Go-live |

## **6.4 Risk Mitigation**

### **Technical Risks**

* Risk: Database performance at scale → Mitigation: Plan caching strategy, optimize indexes early
* Risk: Multi-tenancy isolation issues → Mitigation: Implement row-level security from day 1, test thoroughly
* Risk: API design changes mid-project → Mitigation: Finalize API spec before frontend coding

### **Organizational Risks**

* Risk: Scope creep → Mitigation: Strict feature prioritization, documented requirements
* Risk: Team availability → Mitigation: Cross-training, documentation

## **6.5 Testing Strategy**

### **Unit Testing**

* Backend: 80%+ code coverage with PHPUnit
* Frontend: Jest + React Testing Library

### **Integration Testing**

* API integration tests (API vs Database)
* Multi-tenant isolation tests
* Payment gateway integration tests

### **End-to-End Testing**

* Cypress for critical user journeys
* Test data generation for multi-tenant scenarios

## **6.6 Deployment Strategy**

### **Infrastructure**

* Docker containers for consistency
* Kubernetes for orchestration (optional, Phase 2)
* AWS RDS for managed MySQL
* Redis on ElastiCache
* S3 for file storage

### **CI/CD Pipeline**

* GitHub Actions for automated testing
* Staging environment mirrors production
* Blue-green deployments for zero-downtime updates
* Automated database migrations with rollback capability

# **APPENDIX: Technology Decision Rationale**

## **Why React + Vite?**

* Fast development with Hot Module Replacement (HMR)
* Optimized build output, faster load times
* Large ecosystem and community support

## **Why Laravel 11?**

* Mature framework with excellent multi-tenancy support
* Built-in ORM (Eloquent) for productive development
* Rich ecosystem (Sanctum, Cashier, Horizon)

## **Why stancl/tenancy?**

* Purpose-built for multi-tenancy in Laravel
* Handles subdomain routing automatically
* Tenant-aware migrations and seeding

## **Why Single Database vs Separate DB per Tenant?**

* Single DB advantages:
* - Lower operational overhead
* - Easier backups and disaster recovery
* - Better for analytics across tenants
* Separate DB available in Phase 2 if needed for enterprise clients

**END OF DOCUMENTATION**