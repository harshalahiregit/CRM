# 🐛 Debug: Staff Not Showing

## ✅ Backend Verification - PASSED

The backend is working correctly:
- ✅ API endpoint returns 200 OK
- ✅ 2 staff members found in database
- ✅ Tenant IDs match (tenant_id: 2)
- ✅ Staff data structure correct

**Test Output:**
```json
{
  "status": "success",
  "data": {
    "staff": [
      {
        "id": 6,
        "name": "HR Executive",
        "email": "hr@demo.com",
        "role": "staff",
        "internal_role": "hr_executive"
      },
      {
        "id": 7,
        "name": "Hiring Manager",
        "email": "manager@demo.com",
        "role": "staff",
        "internal_role": "hiring_manager"
      }
    ]
  }
}
```

---

## 🔍 Frontend Debugging Steps

### **Step 1: Open Browser Console**
```
1. Open http://localhost:5173
2. Press F12 to open Developer Tools
3. Go to "Console" tab
4. Keep it open
```

### **Step 2: Login as Admin**
```
Email: admin@demo.com
Password: password123
```

### **Step 3: Go to Staff Management**
```
Sidebar → Admin Tools → Staff Management
```

### **Step 4: Check Console for Errors**

Look for these messages:

**✅ Expected (Good):**
```
Fetching staff with token: Token exists
API URL: http://127.0.0.1:8000/api/admin/staff
Staff API Response: {status: 'success', data: {...}}
```

**❌ Error Messages (Bad):**
```
Error fetching staff: AxiosError
Error status: 401 → Token problem
Error status: 403 → Permission problem
Error status: 404 → Route not found
Error status: 500 → Server error
```

---

## 🔧 Common Issues & Solutions

### **Issue 1: No Token / 401 Unauthorized**

**Symptoms:**
```
Error status: 401
OR
Fetching staff with token: No token
```

**Solution:**
```
1. Logout
2. Login again as admin@demo.com
3. Check localStorage in DevTools:
   - Application → Local Storage → http://localhost:5173
   - Should see 'token' key with value
```

### **Issue 2: 403 Forbidden**

**Symptoms:**
```
Error status: 403
Error response: {message: "Unauthorized"}
```

**Solution:**
```
Check you're logged in as admin:
1. Go to sidebar bottom
2. Should show "Admin User" with "admin" role
3. If shows different role, logout and login with admin@demo.com
```

### **Issue 3: CORS Error**

**Symptoms:**
```
Access to XMLHttpRequest blocked by CORS policy
```

**Solution:**
```bash
cd backend
php artisan config:clear
# Restart backend server
```

### **Issue 4: Network Error**

**Symptoms:**
```
Network Error
ERR_CONNECTION_REFUSED
```

**Solution:**
```
Check backend is running:
http://127.0.0.1:8000

Should show Laravel page
If not, restart: php artisan serve
```

### **Issue 5: Empty Response**

**Symptoms:**
```
Staff API Response: {data: {staff: []}}
Total: 0
```

**Solution:**
```
Wrong tenant_id - user logged in with wrong account
Logout and login with: admin@demo.com
```

---

## 🧪 Manual API Test

### **Test 1: Check Backend**
```bash
# In browser or Postman
GET http://127.0.0.1:8000/api/test-dashboard

Should return: {"status": "success", "message": "API is working!"}
```

### **Test 2: Get Auth Token**
```bash
# Login to get token
POST http://127.0.0.1:8000/api/auth/login
Body: {
  "email": "admin@demo.com",
  "password": "password123",
  "role": "admin"
}

Response will have: "access_token": "..."
Copy this token
```

### **Test 3: Test Staff Endpoint**
```bash
GET http://127.0.0.1:8000/api/admin/staff/stats
Header: Authorization: Bearer YOUR_TOKEN_HERE

Should return:
{
  "status": "success",
  "data": {
    "total_staff": 2,
    "active_staff": 2,
    "inactive_staff": 0
  }
}
```

---

## 📋 Quick Checklist

When staff not showing, check:

- [ ] Backend running? (http://127.0.0.1:8000)
- [ ] Frontend running? (http://localhost:5173)
- [ ] Logged in as admin@demo.com?
- [ ] Browser console shows errors?
- [ ] Token exists in localStorage?
- [ ] Network tab shows API call?
- [ ] API returns 200 OK?
- [ ] Response has data.staff array?

---

## 🎯 Next Steps

### **Please check:**

1. **Open Browser Console (F12)**
2. **Navigate to Staff Management page**
3. **Look for console messages**
4. **Tell me what you see:**
   - Any red error messages?
   - What does "Staff API Response" show?
   - What is "Error status" if there's an error?

### **Screenshots needed:**
- Console tab (F12)
- Network tab showing /admin/staff request
- Staff Management page

---

## 💡 Quick Fix

Try this:

```javascript
// Open browser console (F12) and run:
console.log('Token:', localStorage.getItem('token'))
console.log('User:', localStorage.getItem('user'))

// If no token, logout and login again
```

---

**Status:** Waiting for console logs to identify exact issue  
**Backend:** ✅ Working (tested and verified)  
**Database:** ✅ Has 2 staff members  
**Issue:** Likely frontend token or API call problem
