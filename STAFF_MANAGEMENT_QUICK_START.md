# 🚀 Staff Management - Quick Start Guide

## 📍 How to Access

### **Step 1: Login as Admin**
```
URL: http://localhost:5173
Role: Admin
Email: admin@demo.com
Password: password123
```

### **Step 2: Navigate to Staff Management**

**Option A: Using Sidebar**
```
Sidebar → Scroll Down → Admin Tools → 🛠️ Staff Management
```

**Option B: Direct URL**
```
http://localhost:5173/app/admin/staff
```

---

## ⚡ Quick Actions

### ➕ **Add New Staff Member**

1. Click **"+ Add Staff"** button (top right)
2. Fill the form:
   ```
   Name: Jane Smith
   Email: jane@example.com
   Phone: +91 9876543210
   Password: password123
   Designation: HR Executive (select from dropdown)
   Department: HR (select from dropdown)
   Job Title: Senior HR Executive
   Status: Active (default)
   ```
3. Click **"Create Staff"**
4. ✅ Done! New staff can now login with "Staff / Employee" role

### ✏️ **Edit Existing Staff**

1. Find the staff member in the table
2. Click **⋮** (three dots) in Actions column
3. Click **"Edit"**
4. Modify any fields
5. Click **"Update Staff"**
6. ✅ Changes saved!

### 🔄 **Quick Status Toggle**

1. Find staff member in table
2. Click **⋮** → **"Toggle Status"**
3. ✅ Status instantly switches (Active ↔ Inactive)

### 🗑️ **Delete Staff Member**

1. Find staff member
2. Click **⋮** → **"Delete"**
3. Confirm in popup
4. ✅ Staff deleted permanently

### 🔍 **Search & Filter**

**Search by Name or Email:**
```
Type in search box → Results filter instantly
```

**Filter by Designation:**
```
Select from "All Designations" dropdown
Options: HR Executive, Hiring Manager, Team Lead, etc.
```

**Filter by Status:**
```
Select from "All Status" dropdown
Options: Active, Inactive, Suspended
```

---

## 📊 Current Staff Members

### **Staff 1: HR Executive**
```
Role: Staff / Employee
Email: hr@demo.com
Password: password123
Internal Role: hr_executive
Department: Not set
Status: Active
```

### **Staff 2: Hiring Manager**
```
Role: Staff / Employee
Email: manager@demo.com
Password: password123
Internal Role: hiring_manager
Department: Not set
Status: Active
```

---

## 🎯 Common Tasks

### **Task 1: Onboard New HR Team Member**
```
1. Click "+ Add Staff"
2. Name: "Priya Sharma"
3. Email: "priya@demo.com"
4. Password: "password123"
5. Designation: "HR Executive"
6. Department: "HR"
7. Status: "Active"
8. Save

✅ Priya can now login as Staff / Employee
```

### **Task 2: Create Team Lead**
```
1. Click "+ Add Staff"
2. Name: "Rahul Patil"
3. Email: "rahul@demo.com"
4. Password: "password123"
5. Designation: "Team Lead"
6. Department: "Engineering"
7. Status: "Active"
8. Save

✅ New Team Lead created
```

### **Task 3: Suspend Staff Temporarily**
```
1. Find staff: "Priya Sharma"
2. Actions → "Toggle Status"
3. Status changes to "Inactive"

✅ Priya cannot login until reactivated
```

### **Task 4: Update Department**
```
1. Find staff: "Rahul Patil"
2. Actions → "Edit"
3. Change Department: "Engineering" → "Sales"
4. Save

✅ Department updated
```

---

## 🎨 Available Designations

### **Predefined Roles:**
- HR Executive
- Hiring Manager
- Team Lead
- Project Manager
- Department Head
- Senior Executive
- Junior Executive

**Note:** You can type any custom designation!

---

## 🏢 Available Departments

### **Predefined Departments:**
- HR
- Engineering
- Sales
- Marketing
- Finance
- Operations
- Product
- Customer Support

**Note:** You can type any custom department!

---

## 🔐 Security Features

### **Admin-Only Access**
- ✅ Only users with `role='admin'` can access
- ✅ Staff, Vendors, Clients cannot see this page
- ✅ Direct URL access blocked for non-admins
- ✅ Sidebar link only shows for admins

### **Tenant Isolation**
- ✅ Admins only see staff from their own tenant
- ✅ Cannot access other tenants' staff
- ✅ All operations scoped to current tenant

---

## 🎛️ Page Features

### **Stats Cards (Top)**
```
┌─────────────────┬─────────────────┬─────────────────┐
│ Total Staff     │ Active Staff    │ Inactive Staff  │
│ Real-time count │ Current active  │ Suspended count │
└─────────────────┴─────────────────┴─────────────────┘
```

### **Action Bar**
```
[Search...] [Designation Filter ▼] [Status Filter ▼] [+ Add Staff]
```

### **Data Table**
```
| Avatar | Name & Email | Designation | Dept | Status | Last Active | Actions |
| 👤     | HR Executive | HR Exec     | HR   | 🟢Active| 2h ago     | ⋮      |
```

### **Actions Menu (⋮)**
```
✏️ Edit          → Opens edit modal
🔄 Toggle Status → Quick activate/deactivate
🗑️ Delete        → Remove with confirmation
```

---

## ⚠️ Important Notes

### **Password Rules**
- Minimum 8 characters
- Required when creating new staff
- Optional when editing (leave blank to keep current)

### **Email Rules**
- Must be unique across all users
- Valid email format required
- Cannot change to existing email

### **Status Options**
- **Active**: Can login and use system
- **Inactive**: Cannot login (temporary suspension)
- **Suspended**: Cannot login (admin action)

### **Deletion**
- Permanent action (cannot be undone)
- Consider "Inactive" status instead for temporary removal
- Deletes user account completely

---

## 🐛 Troubleshooting

### **Problem: Cannot see "Admin Tools" in sidebar**
```
Solution: Check you're logged in as admin@demo.com
         Staff/HR users cannot access this feature
```

### **Problem: "Unauthorized" error**
```
Solution: Your session may have expired
         Logout and login again as admin
```

### **Problem: Cannot create staff - email already exists**
```
Solution: Email must be unique
         Use different email or edit existing user
```

### **Problem: Page shows "Loading..." forever**
```
Solution: Backend may not be running
         Check: http://127.0.0.1:8000/api/admin/staff/stats
         Restart: cd backend && php artisan serve
```

---

## 🧪 Quick Test Checklist

After implementation, test these:

- [ ] Login as admin@demo.com
- [ ] See "Admin Tools" in sidebar
- [ ] Click "Staff Management"
- [ ] Page loads with 2 existing staff
- [ ] Stats show: Total=2, Active=2, Inactive=0
- [ ] Click "+ Add Staff"
- [ ] Create new staff member
- [ ] New staff appears in table
- [ ] Stats update automatically
- [ ] Edit a staff member
- [ ] Toggle status works
- [ ] Search works
- [ ] Filters work
- [ ] Delete with confirmation works
- [ ] Logout and login as hr@demo.com
- [ ] "Admin Tools" section NOT visible
- [ ] Cannot access /app/admin/staff

---

## 📞 Need Help?

### **Backend Running?**
```bash
cd backend
php artisan serve
# Should show: Server running on http://127.0.0.1:8000
```

### **Frontend Running?**
```bash
cd frontend
npm run dev
# Should show: Local: http://localhost:5173
```

### **Check Routes**
```bash
cd backend
php artisan route:list --path=admin
# Should show 9 staff management routes
```

### **Test API Directly**
```bash
# Get JWT token first by logging in
# Then test staff endpoint:
curl http://127.0.0.1:8000/api/admin/staff/stats \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

---

## 🎉 Success Indicators

✅ **Sidebar shows "Admin Tools" section**  
✅ **Can access /app/admin/staff page**  
✅ **See 2 existing staff members**  
✅ **Can create new staff**  
✅ **Can edit staff**  
✅ **Can toggle status**  
✅ **Can delete staff**  
✅ **Search and filters work**  
✅ **Non-admins cannot access**  

---

**Page URL:** http://localhost:5173/app/admin/staff  
**Admin Login:** admin@demo.com / password123  
**Status:** ✅ Ready to Use  

**Last Updated:** July 4, 2026
