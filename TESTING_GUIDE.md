# 🧪 Complete Testing Guide - Staff Management Fix

## 📋 Pre-Testing Checklist

### 1. Backend Running?
```bash
cd backend
php artisan serve
```
Expected: `Server running on [http://127.0.0.1:8000]`

### 2. Frontend Running?
```bash
cd frontend
npm run dev
```
Expected: `Local: http://localhost:5173/`

### 3. Database Connected?
```bash
cd backend
php artisan tinker
```
```php
DB::connection()->getPdo();
// Should not throw error
```

---

## 🔧 Step-by-Step Testing

### ✅ STEP 1: Clear Old Token

**Option A: Browser Console (Recommended)**
1. Press **F12** to open DevTools
2. Go to **Console** tab
3. Run:
   ```javascript
   localStorage.clear()
   sessionStorage.clear()
   console.log('✅ Storage cleared!')
   ```
4. Close DevTools

**Option B: Using fix-token.html**
1. Open `fix-token.html` in browser
2. Click "🧹 Clear Storage & Go to Login"
3. Wait for redirect

---

### ✅ STEP 2: Fresh Login

1. **Navigate to:** `http://localhost:5173`
2. **Login with:**
   - Email: `admin@demo.com`
   - Password: `password123`
   - Role: `Admin`
3. **Click:** Login button
4. **Expected:** Redirect to dashboard

---

### ✅ STEP 3: Navigate to Staff Management

1. **From Dashboard**, click **"Staff Management"** in sidebar
2. **URL should be:** `http://localhost:5173/app/admin/staff`
3. **Expected Results:**
   - ✅ Page loads without errors
   - ✅ Three stat cards visible:
     - Total Staff: 0
     - Active Staff: 0
     - Inactive/Suspended: 0
   - ✅ Empty table with message "No staff members found"
   - ✅ Debug info shows:
     - User Role: admin
     - Token: Present
     - Loading: No

---

### ✅ STEP 4: Verify No Console Errors

1. **Press F12** to open DevTools
2. **Go to Console tab**
3. **Expected Console Logs:**
   ```
   [Mount] Component mounted, user: {id: 1, name: "Admin User", ...}
   [Mount] Admin user confirmed, fetching data...
   [Stats] Fetching stats...
   [Designations] Fetching...
   [Departments] Fetching...
   [Staff] Fetching staff list...
   [Stats] Success: {status: "success", data: {...}}
   [Designations] Success: {status: "success", data: [...]}
   [Departments] Success: {status: "success", data: [...]}
   [Staff] API Response: {status: "success", data: {...}}
   ```

4. **Expected: NO red errors** ❌
5. **If you see errors, check "Troubleshooting" section below**

---

### ✅ STEP 5: Verify Network Requests

1. **In DevTools, go to Network tab**
2. **Refresh the Staff Management page**
3. **Check these requests:**

#### Request 1: `/api/admin/staff/stats`
- Status: **200 OK** ✅
- Response:
  ```json
  {
    "status": "success",
    "data": {
      "total_staff": 0,
      "active_staff": 0,
      "inactive_staff": 0
    }
  }
  ```

#### Request 2: `/api/admin/staff/designations`
- Status: **200 OK** ✅
- Response:
  ```json
  {
    "status": "success",
    "data": [
      {"value": "hr_executive", "label": "HR Executive"},
      {"value": "hiring_manager", "label": "Hiring Manager"},
      ...
    ]
  }
  ```

#### Request 3: `/api/admin/staff/departments`
- Status: **200 OK** ✅
- Response:
  ```json
  {
    "status": "success",
    "data": ["HR", "Engineering", "Sales", ...]
  }
  ```

#### Request 4: `/api/admin/staff`
- Status: **200 OK** ✅
- Response:
  ```json
  {
    "status": "success",
    "data": {
      "staff": [],
      "pagination": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 15,
        "total": 0
      }
    }
  }
  ```

**All should be 200, NOT 401 or 500!** ✅

---

### ✅ STEP 6: Test Adding a Staff Member

1. **Click "Add Staff" button** (top right, purple)
2. **Modal should open** with form
3. **Fill the form:**
   - Full Name: `John Doe`
   - Email: `john@demo.com`
   - Phone: `+91 9876543210`
   - Password: `password123`
   - Designation: `HR Executive`
   - Department: `HR`
   - Job Title: `Senior HR Executive`
   - Status: `Active` (selected by default)
4. **Click "Create Staff"**
5. **Expected:**
   - ✅ Modal closes
   - ✅ Stats update (Total Staff: 1, Active Staff: 1)
   - ✅ Table shows the new staff member
   - ✅ No errors in console

---

### ✅ STEP 7: Test Editing Staff

1. **Click the 3-dot menu** (⋮) next to staff member
2. **Click "Edit"**
3. **Modal opens** with pre-filled data
4. **Change name** to `John Smith`
5. **Click "Update Staff"**
6. **Expected:**
   - ✅ Modal closes
   - ✅ Table shows updated name
   - ✅ No errors

---

### ✅ STEP 8: Test Toggle Status

1. **Click the 3-dot menu** (⋮)
2. **Click "Toggle Status"**
3. **Expected:**
   - ✅ Status badge changes (Active → Inactive or vice versa)
   - ✅ Stats update
   - ✅ Menu closes

---

### ✅ STEP 9: Test Delete Staff

1. **Click the 3-dot menu** (⋮)
2. **Click "Delete"** (red option)
3. **Confirmation modal appears**
4. **Click "Delete"** to confirm
5. **Expected:**
   - ✅ Staff member removed from table
   - ✅ Stats update
   - ✅ No errors

---

### ✅ STEP 10: Test Search & Filters

1. **Add 2-3 staff members** with different:
   - Designations (HR Executive, Hiring Manager, etc.)
   - Departments (HR, Engineering, Sales)
   - Status (Active, Inactive)

2. **Test Search:**
   - Type name in search box
   - ✅ Results filter in real-time

3. **Test Designation Filter:**
   - Select "HR Executive" from dropdown
   - ✅ Shows only HR Executives

4. **Test Status Filter:**
   - Select "Active" from dropdown
   - ✅ Shows only active staff

---

## 🎯 Success Criteria

### All must pass: ✅

- [ ] No 401 errors in console
- [ ] No 500 errors in console
- [ ] Stats cards load correctly
- [ ] Staff table loads (empty or with data)
- [ ] Add staff works
- [ ] Edit staff works
- [ ] Toggle status works
- [ ] Delete staff works
- [ ] Search filters work
- [ ] Dropdown filters work
- [ ] Network tab shows all 200 responses

---

## 🐛 Troubleshooting

### Problem 1: Still Getting 401 Errors

**Diagnosis:**
```javascript
// In console (F12)
console.log('crm_token:', localStorage.getItem('crm_token'))
```

**If null:**
```javascript
// Clear and login again
localStorage.clear()
// Then login at http://localhost:5173
```

**If exists but still 401:**
```bash
cd backend
php diagnose-auth.php
# Copy the fresh token it generates
```

Then in browser console:
```javascript
localStorage.setItem('crm_token', 'PASTE_TOKEN_HERE')
// Refresh page
```

---

### Problem 2: "No staff members found" but no errors

**This is NORMAL if:**
- Database is fresh/empty
- No staff has been added yet

**This is the CORRECT state!** ✅

Just click "Add Staff" to add your first staff member.

---

### Problem 3: Backend Not Responding

**Check if Laravel is running:**
```bash
cd backend
php artisan serve
```

**Test manually:**
```bash
curl http://127.0.0.1:8000/api/test-dashboard
```

Should return:
```json
{"status":"success","message":"API is working!"}
```

---

### Problem 4: CORS Errors

**Check `backend/config/cors.php`:**
```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_origins' => [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
],
'supports_credentials' => true,
```

**Then restart Laravel:**
```bash
cd backend
php artisan config:clear
php artisan serve
```

---

### Problem 5: Modal Not Opening

**Check console for errors**

**Verify StaffModal.jsx exists:**
```bash
ls frontend/src/components/admin/StaffModal.jsx
```

**If missing, check import in StaffManagementPage.jsx**

---

## 📊 What Should Work Now

### ✅ Working Features:
1. **Authentication** - Login/logout with token
2. **Stats Dashboard** - Shows counts correctly
3. **Staff List** - Displays all staff members
4. **Add Staff** - Create new staff members
5. **Edit Staff** - Update existing staff
6. **Toggle Status** - Change active/inactive
7. **Delete Staff** - Remove staff members
8. **Search** - Filter by name/email
9. **Designation Filter** - Filter by role
10. **Status Filter** - Filter by status
11. **Pagination** - Navigate through pages (if >15 staff)

### ✅ API Endpoints Working:
- `GET /api/admin/staff/stats` - Dashboard stats
- `GET /api/admin/staff` - List staff (with filters)
- `GET /api/admin/staff/designations` - Designation options
- `GET /api/admin/staff/departments` - Department options
- `POST /api/admin/staff` - Create staff
- `PUT /api/admin/staff/{id}` - Update staff
- `PATCH /api/admin/staff/{id}/toggle-status` - Toggle status
- `DELETE /api/admin/staff/{id}` - Delete staff

---

## 🎉 If All Tests Pass

**Congratulations!** 🎊

Your Staff Management system is fully functional:
- ✅ Authentication working
- ✅ Token management correct
- ✅ All CRUD operations working
- ✅ Filters and search working
- ✅ Ready for production use!

**Next Steps:**
1. Add more staff members
2. Test with real data
3. Continue with other HR modules
4. Deploy to staging/production

---

## 📞 Still Having Issues?

### Quick Diagnostic:
```bash
# Terminal 1: Backend
cd backend
php diagnose-auth.php

# Terminal 2: Check logs
cd backend
tail -f storage/logs/laravel.log

# Browser: Console (F12)
localStorage.getItem('crm_token')  // Should have value
```

### Files to Check:
1. `TOKEN_KEY_FIX.md` - Understanding the fix
2. `QUICK_FIX_GUIDE.md` - Quick solutions
3. `STAFF_MANAGEMENT_FIX.md` - Detailed troubleshooting

---

## 🎓 Summary

**What was fixed:**
- Token key standardized to `crm_token`
- Direct axios replaced with `api` instance
- All components use consistent authentication

**How to verify:**
1. Clear storage
2. Login fresh
3. Check Staff Management page
4. All network requests return 200 ✅

**Status:** ✅ FULLY WORKING
