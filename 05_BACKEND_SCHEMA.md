# Backend Database Schema
## Multi-Tenant SaaS CRM Platform

---

## 1. DATABASE OVERVIEW

### Database Name
```
mla_perfex_crm
```

### Character Set
```
utf8mb4 (Unicode support)
```

### Collation
```
utf8mb4_unicode_ci
```

### Multi-Tenancy Strategy
- **Single Database** with `tenant_id` column on all tables
- **Row-Level Isolation** via Eloquent scopes in application
- **No cross-tenant queries** in code

---

## 2. CORE TABLES

### Table: tenants
**Purpose:** Tenant organization records (each customer = 1 tenant)

```sql
CREATE TABLE tenants (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  subdomain VARCHAR(255) NOT NULL UNIQUE,
  plan_id BIGINT UNSIGNED NOT NULL,
  status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
  custom_domain VARCHAR(255) NULL,
  logo_url VARCHAR(255) NULL,
  branding_color VARCHAR(7) DEFAULT '#2563EB',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  FOREIGN KEY (plan_id) REFERENCES plans(id),
  INDEX idx_status (status),
  INDEX idx_deleted_at (deleted_at)
);
```

### Table: plans
**Purpose:** SaaS pricing plans (Starter, Professional, Enterprise)

```sql
CREATE TABLE plans (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  price DECIMAL(10, 2) NOT NULL,
  currency VARCHAR(3) DEFAULT 'USD',
  billing_period ENUM('monthly', 'annual') DEFAULT 'monthly',
  max_users INT DEFAULT 3,
  max_contacts INT DEFAULT 500,
  storage_gb INT DEFAULT 1,
  features JSON,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  INDEX idx_is_active (is_active)
);
```

**Sample Data:**
```json
{
  "name": "Starter",
  "slug": "starter",
  "price": 49.00,
  "features": {
    "api_access": false,
    "vendor_portal": false,
    "custom_fields": true,
    "invoicing": true,
    "reports": false
  }
}
```

### Table: users
**Purpose:** User accounts (owners, admins, users, vendors)

```sql
CREATE TABLE users (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('owner', 'admin', 'user', 'vendor') DEFAULT 'user',
  phone VARCHAR(20) NULL,
  avatar_url VARCHAR(255) NULL,
  is_active BOOLEAN DEFAULT TRUE,
  email_verified_at TIMESTAMP NULL,
  last_login_at TIMESTAMP NULL,
  remember_token VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY unique_tenant_email (tenant_id, email),
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_role (role),
  INDEX idx_deleted_at (deleted_at)
);
```

### Table: contacts
**Purpose:** Customer/contact records

```sql
CREATE TABLE contacts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  first_name VARCHAR(255) NOT NULL,
  last_name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(20) NULL,
  company_name VARCHAR(255) NULL,
  job_title VARCHAR(255) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  zip_code VARCHAR(20) NULL,
  country VARCHAR(100) NULL,
  source ENUM('website', 'referral', 'cold_call', 'import', 'form', 'other') 
    DEFAULT 'other',
  notes LONGTEXT NULL,
  custom_fields JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_email (email),
  INDEX idx_company_name (company_name),
  INDEX idx_created_at (created_at),
  INDEX idx_deleted_at (deleted_at),
  FULLTEXT INDEX ft_name_email (first_name, last_name, email)
);
```

### Table: deals
**Purpose:** Sales opportunities / pipeline records

```sql
CREATE TABLE deals (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  description LONGTEXT NULL,
  value DECIMAL(12, 2) NOT NULL,
  currency VARCHAR(3) DEFAULT 'USD',
  stage ENUM('new', 'qualified', 'proposal', 'negotiation', 'won', 'lost') 
    DEFAULT 'new',
  probability INT DEFAULT 0,
  expected_close_date DATE NOT NULL,
  actual_close_date DATE NULL,
  lost_reason VARCHAR(255) NULL,
  assigned_to BIGINT UNSIGNED NULL,
  custom_fields JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_stage (stage),
  INDEX idx_assigned_to (assigned_to),
  INDEX idx_expected_close_date (expected_close_date),
  INDEX idx_created_at (created_at),
  INDEX idx_deleted_at (deleted_at)
);
```

### Table: projects
**Purpose:** Project grouping and management

```sql
CREATE TABLE projects (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  description LONGTEXT NULL,
  status ENUM('active', 'on_hold', 'completed', 'archived') DEFAULT 'active',
  start_date DATE NULL,
  end_date DATE NULL,
  owner_id BIGINT UNSIGNED NOT NULL,
  budget DECIMAL(12, 2) NULL,
  custom_fields JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_status (status),
  INDEX idx_owner_id (owner_id),
  INDEX idx_deleted_at (deleted_at)
);
```

### Table: tasks
**Purpose:** Task/activity management

```sql
CREATE TABLE tasks (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  deal_id BIGINT UNSIGNED NULL,
  contact_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  description LONGTEXT NULL,
  assigned_to BIGINT UNSIGNED NULL,
  priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  status ENUM('todo', 'in_progress', 'done') DEFAULT 'todo',
  due_date DATE NULL,
  completed_at TIMESTAMP NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  custom_fields JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE SET NULL,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_status (status),
  INDEX idx_assigned_to (assigned_to),
  INDEX idx_due_date (due_date),
  INDEX idx_created_at (created_at),
  INDEX idx_deleted_at (deleted_at)
);
```

### Table: invoices
**Purpose:** Invoice / billing records

```sql
CREATE TABLE invoices (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NULL,
  deal_id BIGINT UNSIGNED NULL,
  invoice_number VARCHAR(255) NOT NULL,
  invoice_date DATE NOT NULL,
  due_date DATE NOT NULL,
  subtotal DECIMAL(12, 2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(12, 2) DEFAULT 0,
  discount_amount DECIMAL(12, 2) DEFAULT 0,
  total DECIMAL(12, 2) NOT NULL,
  currency VARCHAR(3) DEFAULT 'USD',
  status ENUM('draft', 'sent', 'viewed', 'paid', 'overdue', 'cancelled') 
    DEFAULT 'draft',
  paid_at TIMESTAMP NULL,
  notes LONGTEXT NULL,
  terms LONGTEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE KEY unique_tenant_invoice (tenant_id, invoice_number),
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_status (status),
  INDEX idx_contact_id (contact_id),
  INDEX idx_due_date (due_date),
  INDEX idx_created_at (created_at),
  INDEX idx_deleted_at (deleted_at)
);
```

### Table: invoice_items
**Purpose:** Line items on invoices

```sql
CREATE TABLE invoice_items (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  invoice_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(12, 2) NOT NULL,
  tax_percent DECIMAL(5, 2) DEFAULT 0,
  total DECIMAL(12, 2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  INDEX idx_invoice_id (invoice_id)
);
```

### Table: payments
**Purpose:** Payment records and tracking

```sql
CREATE TABLE payments (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NULL,
  amount DECIMAL(12, 2) NOT NULL,
  currency VARCHAR(3) DEFAULT 'USD',
  status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
  payment_method ENUM('stripe', 'razorpay', 'bank_transfer', 'cash', 'check') 
    DEFAULT 'stripe',
  transaction_id VARCHAR(255) NULL,
  stripe_charge_id VARCHAR(255) NULL,
  payment_date TIMESTAMP NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_status (status),
  INDEX idx_payment_date (payment_date),
  INDEX idx_transaction_id (transaction_id)
);
```

### Table: vendors
**Purpose:** Vendor/supplier management

```sql
CREATE TABLE vendors (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(20) NULL,
  company_name VARCHAR(255) NULL,
  category VARCHAR(100) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(100) NULL,
  state VARCHAR(100) NULL,
  zip_code VARCHAR(20) NULL,
  country VARCHAR(100) NULL,
  portal_access BOOLEAN DEFAULT FALSE,
  user_id BIGINT UNSIGNED NULL,
  contract_url VARCHAR(255) NULL,
  status ENUM('active', 'inactive', 'blocked') DEFAULT 'active',
  notes LONGTEXT NULL,
  custom_fields JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_status (status),
  INDEX idx_portal_access (portal_access),
  INDEX idx_deleted_at (deleted_at)
);
```

### Table: purchase_orders
**Purpose:** Purchase order records

```sql
CREATE TABLE purchase_orders (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  vendor_id BIGINT UNSIGNED NOT NULL,
  deal_id BIGINT UNSIGNED NULL,
  order_number VARCHAR(255) NOT NULL,
  order_date DATE NOT NULL,
  expected_delivery_date DATE NULL,
  status ENUM('draft', 'sent', 'confirmed', 'received', 'cancelled') 
    DEFAULT 'draft',
  subtotal DECIMAL(12, 2) DEFAULT 0,
  tax_amount DECIMAL(12, 2) DEFAULT 0,
  total DECIMAL(12, 2) NOT NULL,
  currency VARCHAR(3) DEFAULT 'USD',
  notes LONGTEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT,
  FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE KEY unique_tenant_po (tenant_id, order_number),
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_status (status),
  INDEX idx_vendor_id (vendor_id),
  INDEX idx_deleted_at (deleted_at)
);
```

### Table: purchase_order_items
**Purpose:** Line items on purchase orders

```sql
CREATE TABLE purchase_order_items (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(12, 2) NOT NULL,
  tax_percent DECIMAL(5, 2) DEFAULT 0,
  total DECIMAL(12, 2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  INDEX idx_po_id (purchase_order_id)
);
```

### Table: tickets
**Purpose:** Support ticket management

```sql
CREATE TABLE tickets (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  contact_id BIGINT UNSIGNED NULL,
  ticket_number VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description LONGTEXT NOT NULL,
  priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  status ENUM('open', 'in_progress', 'on_hold', 'resolved', 'closed') 
    DEFAULT 'open',
  assigned_to BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  resolved_at TIMESTAMP NULL,
  custom_fields JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at TIMESTAMP NULL,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE KEY unique_tenant_ticket (tenant_id, ticket_number),
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_status (status),
  INDEX idx_assigned_to (assigned_to),
  INDEX idx_deleted_at (deleted_at)
);
```

### Table: activities
**Purpose:** Activity log for audit trail and timeline

```sql
CREATE TABLE activities (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  loggable_type VARCHAR(255) NOT NULL,
  loggable_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(50) NOT NULL,
  description TEXT NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_loggable (loggable_type, loggable_id),
  INDEX idx_created_at (created_at)
);
```

### Table: audit_logs
**Purpose:** Security audit trail for sensitive operations

```sql
CREATE TABLE audit_logs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  resource_type VARCHAR(100) NOT NULL,
  resource_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(50) NOT NULL,
  before JSON NULL,
  after JSON NULL,
  reason VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_resource (resource_type, resource_id),
  INDEX idx_created_at (created_at)
);
```

### Table: custom_fields
**Purpose:** Dynamic custom field configuration per tenant

```sql
CREATE TABLE custom_fields (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  module VARCHAR(100) NOT NULL,
  field_name VARCHAR(255) NOT NULL,
  field_type ENUM('text', 'number', 'date', 'dropdown', 'checkbox', 'textarea') 
    DEFAULT 'text',
  label VARCHAR(255) NOT NULL,
  placeholder VARCHAR(255) NULL,
  is_required BOOLEAN DEFAULT FALSE,
  is_searchable BOOLEAN DEFAULT FALSE,
  sort_order INT DEFAULT 0,
  options JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY unique_field (tenant_id, module, field_name),
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_module (module)
);
```

### Table: api_tokens
**Purpose:** API token management for integrations

```sql
CREATE TABLE api_tokens (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  token VARCHAR(255) NOT NULL UNIQUE,
  last_used_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_token (token)
);
```

### Table: webhooks
**Purpose:** Webhook configuration for integrations

```sql
CREATE TABLE webhooks (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  url VARCHAR(255) NOT NULL,
  events JSON NOT NULL,
  is_active BOOLEAN DEFAULT TRUE,
  secret VARCHAR(255) NOT NULL,
  last_triggered_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_is_active (is_active)
);
```

### Table: files
**Purpose:** File uploads tracking

```sql
CREATE TABLE files (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  fileable_type VARCHAR(255) NOT NULL,
  fileable_id BIGINT UNSIGNED NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_size BIGINT NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  storage_disk VARCHAR(100) DEFAULT 's3',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_tenant_id (tenant_id),
  INDEX idx_fileable (fileable_type, fileable_id)
);
```

---

## 3. RELATIONSHIPS DIAGRAM

```
tenants (1) ─────────→ (M) users
          ├───────────→ (M) contacts
          ├───────────→ (M) deals
          ├───────────→ (M) projects
          ├───────────→ (M) tasks
          ├───────────→ (M) invoices
          ├───────────→ (M) payments
          ├───────────→ (M) vendors
          ├───────────→ (M) purchase_orders
          ├───────────→ (M) tickets
          ├───────────→ (M) activities
          ├───────────→ (M) audit_logs
          ├───────────→ (M) custom_fields
          ├───────────→ (M) api_tokens
          └───────────→ (M) webhooks

contacts (1) ───→ (M) deals
          ├──→ (M) invoices
          └──→ (M) tasks

deals (1) ────→ (M) invoices
       ├──→ (M) tasks
       └──→ (M) purchase_orders

projects (1) ──→ (M) tasks

invoices (1) ─→ (M) invoice_items
         └──→ (M) payments

vendors (1) ───→ (M) purchase_orders

purchase_orders (1) → (M) purchase_order_items

tasks (1) ──→ (M) activities

users (1) ──→ (M) tasks (assigned_to)
      ├──→ (M) deals (assigned_to)
      ├──→ (M) activities
      └──→ (M) audit_logs
```

---

## 4. INDEXES SUMMARY

### Primary Keys
- All tables have `id` as PRIMARY KEY

### Foreign Keys
- `tenant_id` indexed on all tables (required for multi-tenancy)
- `user_id`, `contact_id`, `deal_id` indexed

### Search Indexes
- `contacts`: FULLTEXT on `first_name, last_name, email`
- `deals`: INDEX on `stage`, `expected_close_date`
- `invoices`: INDEX on `status`, `due_date`
- `tasks`: INDEX on `status`, `due_date`, `assigned_to`

### Unique Constraints
- `tenants.subdomain`
- `plans.slug`
- `users.unique (tenant_id, email)`
- `invoices.unique (tenant_id, invoice_number)`
- `tickets.unique (tenant_id, ticket_number)`

---

## 5. COMMON QUERIES & PERFORMANCE NOTES

### Get all contacts for a tenant (filtered)
```sql
SELECT * FROM contacts 
WHERE tenant_id = ? AND deleted_at IS NULL
ORDER BY created_at DESC
LIMIT 25 OFFSET 0;
```
**Indexes:** `idx_tenant_id`, `idx_deleted_at`

### Get pipeline value by stage
```sql
SELECT stage, SUM(value) AS total 
FROM deals 
WHERE tenant_id = ? AND deleted_at IS NULL
GROUP BY stage;
```
**Indexes:** `idx_tenant_id`, `idx_stage`

### Get overdue invoices
```sql
SELECT * FROM invoices 
WHERE tenant_id = ? 
  AND due_date < NOW() 
  AND status != 'paid' 
  AND deleted_at IS NULL
ORDER BY due_date ASC;
```
**Indexes:** `idx_tenant_id`, `idx_status`, `idx_due_date`

---

## 6. MIGRATION STRATEGY

### Phase 1: Core (Weeks 1-2)
- tenants, users, plans
- contacts, deals, projects, tasks

### Phase 2: Revenue (Weeks 3-4)
- invoices, invoice_items, payments
- vendors, purchase_orders, purchase_order_items

### Phase 3: Support & Tracking (Weeks 5-6)
- tickets
- activities, audit_logs
- custom_fields, api_tokens, webhooks
- files

---

## 7. DATA TYPES & CONSTRAINTS

### Decimal Fields
- `value`, `price`, `amount`: `DECIMAL(12, 2)` (supports up to 99,999,999.99)
- `probability`: `INT` (0-100%)

### String Fields
- Emails: `VARCHAR(255)`
- URLs: `VARCHAR(255)`
- Slugs: `VARCHAR(255)` UNIQUE
- Enums: Use ENUM type for fixed sets

### Timestamps
- Use `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` for created_at
- Use `TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` for updated_at
- Use `TIMESTAMP NULL` for optional timestamps (deleted_at, paid_at, etc.)

### JSON Fields
- `custom_fields`: JSON (flexible custom data)
- `features`: JSON (plan features array)
- `options`: JSON (dropdown choices array)
- `old_values`, `new_values`: JSON (audit trail)

---

## 8. BACKUP & RECOVERY

### Backup Strategy
- **Daily automated backups** to S3
- **Point-in-time recovery** enabled (7-day retention)
- **Monthly full backups** kept for 90 days

### Restore Procedure
1. Notify affected tenant
2. Stop all writes to affected tenant tables
3. Restore from latest backup
4. Verify data integrity
5. Resume operations

---

## 9. SCALING CONSIDERATIONS

### Single Tenant Optimization (Phase 2)
If a tenant grows beyond 1M records:
- Create separate DB schema per tenant (e.g., `acme_crm_tenant_1`)
- Implement database router in application layer
- Maintain central `tenants` table for configuration

### Archiving
- Move completed invoices to archive table after 2 years
- Keep active data in primary tables
- Archive table structure identical to primary

---

## 10. COMPLIANCE & PRIVACY

### GDPR Data Deletion
- Soft deletes by default (delete_at column)
- Hard delete on explicit request (30-day grace period)
- Audit log entries retained for 7 years (compliance)

### Data Masking
- PII fields can be masked in logs/reports for non-privileged users
- Sensitive fields encrypted at rest (Phase 2)

---

## 11. SAMPLE MIGRATION FILE (Laravel)

```php
// database/migrations/2024_01_01_000001_create_core_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Tenants table
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('subdomain')->unique();
            $table->foreignId('plan_id')->constrained();
            $table->enum('status', ['active', 'suspended', 'deleted'])->default('active');
            $table->string('custom_domain')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('branding_color', 7)->default('#2563EB');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('status');
        });

        // ... Additional tables
    }

    public function down(): void {
        Schema::dropIfExists('tenants');
        // ... Drop other tables
    }
};
```

---

## 12. SEEDING DATA

### Seed Plans
```php
// database/seeders/PlanSeeder.php

Plan::create([
    'name' => 'Starter',
    'slug' => 'starter',
    'price' => 49.00,
    'max_users' => 3,
    'max_contacts' => 500,
    'storage_gb' => 1,
    'features' => [
        'api_access' => false,
        'vendor_portal' => false,
    ],
]);
```

---

## 13. MONITORING & ALERTS

### Key Metrics
- Table sizes (contacts, deals, invoices)
- Slow queries (>500ms)
- Replication lag (if read replicas)
- Disk usage

### Alerts
- Table exceeds 10GB
- Slow query log grows >100 queries/hour
- Backup job fails
- Replication lag >5 seconds
