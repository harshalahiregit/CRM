# 🔐 HR Module Login Credentials

## ✅ System Status
- **Backend**: http://127.0.0.1:8000 ✅ Running
- **Frontend**: http://localhost:5173 ✅ Running
- **Database**: SQLite ✅ Connected

---

## 👥 Available User Accounts

### 1️⃣ **Admin (Full Access)**
```
Role:     🛡️ Admin
Email:    admin@demo.com
Password: password123
Access:   Complete HR Module + System Settings
```

**What Admin Can Do:**
- ✅ View all manpower requests
- ✅ Create and manage job postings
- ✅ Review all candidates
- ✅ Schedule interviews
- ✅ Generate offers
- ✅ Manage onboarding
- ✅ Access employee records
- ✅ Assign hiring managers
- ✅ Full dashboard access

---

### 2️⃣ **HR Executive (HR Operations)**
```
Role:     👔 HR Executive  
Email:    hr@demo.com
Password: password123
Access:   HR Module (Operational Level)
```

**What HR Executive Can Do:**
- ✅ View all manpower requests
- ✅ Create job postings
- ✅ Manage candidates
- ✅ Schedule interviews
- ✅ Generate offers
- ✅ Handle onboarding
- ✅ Assign hiring managers to requests
- ⚠️ Cannot approve manpower requests (manager only)

---

### 3️⃣ **Hiring Manager (Department Manager)**
```
Role:     📋 Hiring Manager
Email:    manager@demo.com
Password: password123
Access:   Limited to Assigned Requests
```

**What Hiring Manager Can Do:**
- ✅ View only assigned manpower requests
- ✅ Approve/reject manpower requests
- ✅ View candidates for their positions
- ✅ Participate in interviews
- ⚠️ Cannot create job postings
- ⚠️ Cannot assign other managers

---

## 🚀 How to Login

### **Step-by-Step Process:**

1. **Open Browser**: Go to http://localhost:5173

2. **Select Role** (Dropdown at top):
   - For full access: Select **🛡️ Admin**
   - For HR operations: Select **👔 HR Executive**
   - For department manager: Select **📋 Hiring Manager**

3. **Enter Email**: 
   - Admin: `admin@demo.com`
   - HR: `hr@demo.com`
   - Manager: `manager@demo.com`

4. **Enter Password**: `password123`

5. **Click LOGIN**

---

## 📊 Test Data Available

### Current System Data (Tenant 2):
- **Job Postings**: 12 total (7 active)
- **Candidates**: 24 total (19 active)
- **Open Positions**: 14
- **Pipeline Stages**: 6

---

## ⚙️ Role-Based Access Summary

| Feature | Admin | HR Executive | Hiring Manager |
|---------|-------|--------------|----------------|
| Dashboard | ✅ Full | ✅ Full | ✅ Limited |
| Manpower Requests | ✅ All | ✅ All | ✅ Assigned Only |
| Approve Requests | ✅ Yes | ❌ No | ✅ Yes |
| Job Postings | ✅ CRUD | ✅ CRUD | ❌ View Only |
| Candidates | ✅ All | ✅ All | ✅ For Assigned Jobs |
| Interviews | ✅ All | ✅ All | ✅ For Assigned Jobs |
| Offers | ✅ All | ✅ All | ✅ For Assigned Jobs |
| Onboarding | ✅ All | ✅ All | ❌ View Only |
| Employees | ✅ All | ✅ All | ❌ View Only |

---

## 🔧 Technical Details

### Authentication Flow:
1. Frontend sends: `{ email, password, role }`
2. Backend validates against `users` table with matching role
3. Returns JWT token + user data
4. Frontend stores token and redirects to dashboard

### Supported Roles in System:
- `admin` - Super Admin (CRM + HR)
- `hr_executive` - HR Operations
- `hiring_manager` - Department Manager
- `vendor` - Vendor Portal
- `third_party_vendor` - TPV Portal
- `client` - Client Portal

---

## 🎯 Quick Test Scenarios

### **Test 1: Admin Full Access**
```
Login as: admin@demo.com (Admin role)
Navigate to: HR Module → Dashboard
Expected: See all KPIs, charts, and data
```

### **Test 2: HR Executive Operations**
```
Login as: hr@demo.com (HR Executive role)
Navigate to: Candidates → Add Candidate
Expected: Can create and manage candidates
```

### **Test 3: Hiring Manager Limited Access**
```
Login as: manager@demo.com (Hiring Manager role)
Navigate to: Manpower Requests
Expected: See only requests assigned to this manager
```

---

## 📱 Next Steps: Enable Notifications

### For Email (Gmail SMTP):
1. Generate App Password at: https://myaccount.google.com/apppasswords
2. Update `.env` file with credentials
3. Test with: `php artisan test:emails`

### For WhatsApp (Twilio):
1. Sign up at: https://www.twilio.com/try-twilio
2. Get Account SID and Auth Token
3. Update `.env` file
4. Test with: `php artisan test:whatsapp --phone=+919403443775`

---

**Updated**: July 4, 2026
**System Version**: v1.0.0
**Status**: ✅ All roles active and working
