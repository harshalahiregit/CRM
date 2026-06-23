# UI/UX Design Document
## Multi-Tenant SaaS CRM Platform

---

## 1. DESIGN PHILOSOPHY

### Core Principles
1. **Clarity First** — Minimize cognitive load, clear hierarchy
2. **Consistency** — Same patterns across all modules
3. **Efficiency** — Keyboard shortcuts, bulk actions, smart defaults
4. **Accessibility** — WCAG 2.1 AA compliant
5. **Performance** — Instant feedback, no jank

### Visual Language
- **Modern & Professional** — B2B SaaS aesthetic
- **Dark Mode Ready** — System preference default
- **Mobile-Responsive** — Mobile-first design
- **High Contrast** — Accessible text hierarchy

---

## 2. COLOR PALETTE

### Primary
- **Brand Blue:** `#2563EB` (buttons, links, active states)
- **Accent Green:** `#10B981` (success, confirmation)
- **Warning Orange:** `#F59E0B` (alerts, warnings)
- **Danger Red:** `#EF4444` (delete, critical actions)

### Neutral
- **Text Dark:** `#1F2937` (primary text)
- **Text Medium:** `#6B7280` (secondary text)
- **Text Light:** `#9CA3AF` (disabled, hints)
- **Background:** `#FFFFFF` (light mode), `#111827` (dark mode)
- **Border:** `#E5E7EB` (light mode), `#374151` (dark mode)

### Functional
- **Info:** `#3B82F6` (information messages)
- **Success:** `#10B981` (confirmations)
- **Warning:** `#FBBF24` (cautions)
- **Error:** `#EF4444` (errors, failures)

---

## 3. TYPOGRAPHY

### Font Stack
```css
font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, 
             Ubuntu, Cantarell, sans-serif;
```

### Scale (rem, base 16px)
| Use Case | Size | Weight | Line Height |
|----------|------|--------|-------------|
| Display / Hero | 2.25rem (36px) | 700 | 2.5rem |
| H1 / Page Title | 1.875rem (30px) | 700 | 2.25rem |
| H2 / Section | 1.5rem (24px) | 600 | 2rem |
| H3 / Subsection | 1.25rem (20px) | 600 | 1.75rem |
| Body Large | 1.125rem (18px) | 400 | 1.75rem |
| Body Regular | 1rem (16px) | 400 | 1.5rem |
| Body Small | 0.875rem (14px) | 400 | 1.375rem |
| Caption | 0.75rem (12px) | 500 | 1.25rem |

---

## 4. LAYOUT & SPACING

### Grid System
- **Base Unit:** 4px
- **Main Container:** Max 1440px width, centered
- **Padding:** 16px (mobile), 24px (tablet), 32px (desktop)
- **Gap between columns:** 16px or 24px

### Spacing Scale (multiples of 4px)
```
xs: 4px, sm: 8px, md: 12px, lg: 16px, xl: 24px, 2xl: 32px, 3xl: 48px
```

### Breakpoints
| Device | Breakpoint |
|--------|-----------|
| Mobile | 0px - 639px |
| Tablet | 640px - 1023px |
| Desktop | 1024px+ |

---

## 5. COMPONENT LIBRARY (shadcn/ui based)

### Navigation Components
- **Header/Navbar** — Logo, user menu, search, notifications
- **Sidebar** — Collapsible, dark mode aware, module icons
- **Breadcrumbs** — Current page location
- **Tabs** — Module switching (Contacts, Deals, Tasks, etc.)

### Form Components
- **Input** — Text fields, email, phone, date
- **Textarea** — Rich text, multi-line notes
- **Select/Dropdown** — Single/multi-select (custom fields)
- **Checkbox & Radio** — Yes/no, option selection
- **Date Picker** — Calendar UI for due dates, close dates
- **Rich Text Editor** — Quill.js integration for notes/descriptions
- **File Upload** — Drag-drop, progress indicator

### Data Display
- **Table** — Sortable, filterable, paginated
- **Card** — Summary, single record display
- **List** — Vertical item list
- **Badge** — Status indicators (Won, Lost, Open, etc.)
- **Timeline** — Activity log, deal progression
- **Kanban Board** — Drag-drop task/deal staging

### Feedback Components
- **Toast / Notification** — Success, error, info messages
- **Modal / Dialog** — Confirmation, form submission
- **Alert** — Inline warnings, info
- **Loading Spinner** — Page/section loading
- **Empty State** — No data found messaging

### Charts & Reporting
- **Bar Chart** — Revenue by month, pipeline by stage
- **Line Chart** — Trend over time
- **Pie Chart** — Composition (e.g., deal stages)
- **Number Card** — KPI display (total deals, won this month)

---

## 6. LAYOUT TEMPLATES

### Dashboard Layout
```
┌─────────────────────────────────────────┐
│  Header (Logo, Search, Notifications)   │
├──────────┬──────────────────────────────┤
│          │                              │
│ Sidebar  │  Main Content Area           │
│          │  (Grid of KPI cards + charts)│
│          │                              │
└──────────┴──────────────────────────────┘
```

### List View Layout
```
┌─────────────────────────────────────────┐
│  Header (Page Title + Action Buttons)   │
├─────────────────────────────────────────┤
│  Filters & Search Bar                   │
├─────────────────────────────────────────┤
│  Table / Card Grid                      │
│  (Sortable, filterable, paginated)      │
├─────────────────────────────────────────┤
│  Pagination Controls                    │
└─────────────────────────────────────────┘
```

### Detail View Layout
```
┌─────────────────────────────────────────┐
│  Header (Breadcrumb + Actions)          │
├─────────────────────────────────────────┤
│  ┌─────────────────────────────────┐    │
│  │  Main Panel (Record Details)    │    │
│  │  - Primary info, status badge   │    │
│  │  - Editable fields              │    │
│  │  - Action buttons               │    │
│  └─────────────────────────────────┘    │
│  ┌─────────────────────────────────┐    │
│  │  Activity / Related Records      │    │
│  │  - Timeline, linked tasks, etc. │    │
│  └─────────────────────────────────┘    │
└─────────────────────────────────────────┘
```

---

## 7. KEY SCREENS

### Authentication Flow
1. **Login** — Email, password, "Remember me", forgot password link
2. **Register** — Name, email, password, company name, terms acceptance
3. **Tenant Setup** — Subdomain selection, plan choice, payment (if paid tier)
4. **Verification** — Email verification, onboarding checklist

### Dashboard
- **Sales Overview** — Pipeline value by stage, win rate chart
- **Activity Feed** — Recent deals, calls logged, invoices sent
- **Quick Stats** — Total contacts, open deals, overdue invoices
- **Coming Up** — Tasks due today, meetings scheduled

### Contacts Module
- **Contact List** — Table: Name, Company, Email, Phone, Last Activity
- **Contact Detail** — Full info, activity timeline, linked deals/tasks
- **Contact Edit** — Form to update fields, add custom fields
- **Bulk Import** — CSV upload with field mapping

### Deals Module
- **Pipeline Board** — Kanban: Columns by stage, drag-drop cards
- **Deals List** — Table: Deal name, value, stage, owner, close date
- **Deal Detail** — Contacts involved, tasks, invoices, activity log
- **Deal Create/Edit** — Form: Name, value, stage, contact, close date

### Tasks Module
- **Task List/Board** — Kanban or table: Task title, assigned to, due date
- **Task Detail** — Description, attachments, comments, linked records
- **Task Create/Edit** — Form with optional deal/contact link

### Invoicing Module
- **Invoice List** — Table: Number, customer, amount, status, due date
- **Invoice Detail** — Line items, totals, payment status, send button
- **Invoice Create** — Form: Contact, items, dates, terms
- **Payment Tracking** — List of payments, reconciliation status

### Vendor Portal
- **Vendor Dashboard** — Orders assigned, upcoming deliverables
- **Purchase Order View** — List and detail of POs
- **Vendor Profile** — Edit contact info, availability

### Reports
- **Sales Report** — Revenue by period, by team member, pipeline forecast
- **Activity Report** — Calls, emails, meetings logged per user
- **Invoice Report** — Paid, overdue, pending amounts

---

## 8. INTERACTION PATTERNS

### Lists & Tables
- **Sorting:** Click column header to sort ascending/descending
- **Filtering:** Sidebar or top bar with filter controls
- **Search:** Global search with autocomplete
- **Pagination:** "Show 10/25/50" rows + page navigation
- **Bulk Actions:** Checkboxes + action toolbar for selected

### Forms
- **Validation:** Real-time feedback (inline error messages)
- **Submit Button:** Disabled until form is valid
- **Auto-save:** Drafts saved every 30s (optional)
- **Field Hints:** Placeholder text, helper text below inputs

### Modals & Dialogs
- **Confirmation:** "Are you sure?" with Cancel/Delete buttons
- **Forms in Modal:** Save/Cancel buttons at bottom
- **Dismissible:** Click outside or X button to close

### Notifications
- **Toast Position:** Bottom-right corner
- **Auto-dismiss:** 5s for info/success, persistent for error
- **Action Buttons:** "Undo" on delete, "View" on creation

### Keyboard Shortcuts
- `Ctrl/Cmd + K` — Global search
- `Ctrl/Cmd + N` — New record
- `Ctrl/Cmd + S` — Save
- `Esc` — Close modal, cancel action
- `Tab` — Navigation between form fields

---

## 9. MOBILE RESPONSIVENESS

### Breakpoints
- **Mobile (<640px):** Single column, stacked elements, bottom sheet modals
- **Tablet (640-1023px):** Two columns, sidebar toggles to hamburger
- **Desktop (1024px+):** Full layout as designed

### Mobile-Specific
- **Touch Targets:** Min 44px × 44px (buttons, links)
- **Modals:** Full-screen on mobile, centered on desktop
- **Sidebar:** Hamburger menu, overlay on mobile
- **Table:** Scroll horizontally, or card view on mobile
- **Bottom Sheet:** For modals/actions on mobile (instead of centered)

---

## 10. ACCESSIBILITY

### WCAG 2.1 AA Compliance
- ✅ Semantic HTML (buttons, inputs, labels)
- ✅ ARIA labels on icons, form fields
- ✅ Color not only indicator (use text + icon)
- ✅ Sufficient color contrast (4.5:1 for text)
- ✅ Keyboard navigation (Tab, Enter, Arrow keys)
- ✅ Focus indicators (visible ring on focus)
- ✅ Alt text on images
- ✅ Skip to main content link

### Screen Reader Support
- Form labels associated with inputs (htmlFor)
- Table headers marked as <th>
- List landmarks (<nav>, <main>, <aside>)
- Dynamic content updates announced via aria-live

---

## 11. DARK MODE

### Implementation
- **Default:** System preference (prefers-color-scheme)
- **Toggle:** User preference in settings
- **Colors:** Inverted palette (light text on dark BG)
- **Images:** Optional dark mode versions

### Color Adjustments
| Component | Light | Dark |
|-----------|-------|------|
| Background | #FFF | #111827 |
| Surface | #F3F4F6 | #1F2937 |
| Border | #E5E7EB | #374151 |
| Text | #1F2937 | #F3F4F6 |

---

## 12. DESIGN TOKENS (CSS Variables)

```css
/* Colors */
--color-primary: #2563EB;
--color-success: #10B981;
--color-warning: #F59E0B;
--color-danger: #EF4444;

/* Spacing */
--spacing-xs: 4px;
--spacing-sm: 8px;
--spacing-md: 12px;
--spacing-lg: 16px;
--spacing-xl: 24px;

/* Typography */
--font-size-sm: 0.875rem;
--font-size-base: 1rem;
--font-size-lg: 1.125rem;
--font-weight-normal: 400;
--font-weight-semibold: 600;
--font-weight-bold: 700;

/* Shadows */
--shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
--shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
--shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);

/* Border Radius */
--radius-sm: 4px;
--radius-md: 8px;
--radius-lg: 12px;

/* Transitions */
--transition-fast: 150ms ease;
--transition-normal: 300ms ease;
```

---

## 13. DESIGN SYSTEM ASSETS

### Assets to Create
- ✅ Icon set (24x24, outline style, >200 icons)
- ✅ Illustration set (empty states, onboarding, error states)
- ✅ Component storybook (Storybook.js for React)
- ✅ Design file (Figma) — shared with team
- ✅ Responsive design mockups (key screens)

---

## 14. USER FEEDBACK & ANIMATION

### Feedback
- **Success Feedback:** Green toast + checkmark icon
- **Error Feedback:** Red toast + error icon + retry button
- **Loading:** Skeleton loaders for data, spinner for actions
- **Empty State:** Illustration + helpful message + CTA

### Animation
- **Page Transitions:** Fade in (150ms)
- **Modal Opening:** Scale up + fade (200ms)
- **Button Hover:** Color shift + slight shadow
- **Skeleton Loading:** Subtle shimmer effect
- **Success Checkmark:** Bounce animation (200ms)

### Performance
- **GPU Accelerated:** Use transform, opacity only
- **Avoid:** Repaints on scroll, expensive JS during animation
- **Prefers Reduced Motion:** Respect user preference

---

## 15. TESTING & QA

### Design QA
- ✅ Cross-browser testing (Chrome, Firefox, Safari, Edge)
- ✅ Mobile testing (iOS Safari, Chrome Mobile)
- ✅ Accessibility audit (axe, WAVE, screen reader)
- ✅ Color contrast check (WebAIM)
- ✅ Performance audit (Lighthouse)

---

## 16. DESIGN RESOURCES

### Figma Libraries (To Create)
- `Colors` — All brand colors
- `Typography` — All text styles
- `Components` — Button, input, card, etc.
- `Icons` — Icon library
- `Layouts` — Grid, spacing guides

### Frontend Implementation
- Use **Tailwind CSS** for styling
- Create components in **shadcn/ui** format
- Document in **Storybook** for team reference
