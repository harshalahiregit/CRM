# 🎯 Quick Login Reference

## Login Page URL
**Frontend:** http://localhost:5173

---

## 🔑 Login Credentials

### Option 1: Admin (Full Access)
```
Role Dropdown: Admin
Email:         admin@demo.com
Password:      password123
```

### Option 2: HR Executive (Staff)
```
Role Dropdown: Staff / Employee    ⬅️ SELECT THIS
Email:         hr@demo.com
Password:      password123
```

### Option 3: Hiring Manager (Staff)
```
Role Dropdown: Staff / Employee    ⬅️ SELECT THIS
Email:         manager@demo.com
Password:      password123
```

---

## ⚠️ Important!

**For HR Executive and Hiring Manager:**
- ❌ DON'T look for "HR Executive" or "Hiring Manager" in dropdown
- ✅ DO select "Staff / Employee" instead
- The system knows their internal role automatically

---

## 📋 What Each Role Can Do

### Admin
- ✅ Everything
- ✅ All HR features
- ✅ System settings
- ✅ User management

### HR Executive (Staff)
- ✅ Manage candidates
- ✅ Create job postings
- ✅ Schedule interviews
- ✅ Generate offers
- ✅ Handle onboarding
- ⚠️ Cannot approve manpower requests

### Hiring Manager (Staff)
- ✅ View assigned manpower requests
- ✅ Approve/reject requests
- ✅ View candidates for their positions
- ⚠️ Cannot create job postings
- ⚠️ Cannot see all requests

---

## 🎨 Login Dropdown Shows

```
🛡️ Admin
👔 Staff / Employee        ⬅️ HR + Managers use this
🏭 Vendor
🤝 Third-Party Vendor
👤 Client / Customer
```

---

## ✅ Quick Test

1. Open: http://localhost:5173
2. Select: **Staff / Employee**
3. Enter: **hr@demo.com**
4. Password: **password123**
5. Click: **LOGIN**
6. ✨ You're in!

---

**Updated:** July 4, 2026
