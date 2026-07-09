# Staff Display Issue - Solution Guide

## Problem Summary
Staff Management page shows "No staff members found" even though backend has 2 staff members and API returns correct data.

## Root Cause
The token stored in the browser's localStorage is old or invalid, causing 500 errors on API requests.

## Solution - Step by Step

### Step 1: Log Out
1. Go to your application in the browser (http://localhost:5173)
2. Click the logout button to clear the old session
3. This will clear the invalid token from localStorage

### Step 2: Clear Browser Storage (Important!)
Before logging in again, manually clear the storage:

**Option A - Using Browser Console (F12):**
```javascript
localStorage.clear()
sessionStorage.clear()
console.log('Storage cleared!')
```

**Option B - Using Browser DevTools:**
1. Press F12 to open DevTools
2. Go to "Application" tab (Chrome) or "Storage" tab (Firefox)
3. Click "Local Storage" → "http://localhost:5173"
4. Click "Clear All" or delete the "token" key
5. Do the same for "Session Storage"

### Step 3: Log In Again
1. Go to http://localhost:5173
2. Login with admin credentials:
   - **Email:** admin@demo.com
   - **Password:** password123
   - **Role:** Select "Admin" from dropdown
3. Click "Sign In"

### Step 4: Access Staff Management
1. After successful login, you should see the Admin Dashboard
2. Look for "Admin Tools" section in the left sidebar
3. Click "Staff Management"
4. You should now see 2 staff members:
   - HR Executive (hr@demo.com)
   - Hiring Manager (manager@demo.com)

## Verification

### Backend Status (Already Verified ✓)
```
✓ Database has 2 staff members
✓ API endpoint working: GET /api/admin/staff/stats → 200 OK
✓ API endpoint working: GET /api/admin/staff → 200 OK with 2 records
✓ Routes are registered correctly (9 routes)
✓ Admin user exists with role="admin" and tenant_id=2
✓ Staff members exist with role="staff" and tenant_id=2
✓ Middleware is configured correctly
```

### Frontend Checklist
After following the steps above, verify:
- [ ] No 500 errors in browser console (F12)
- [ ] Stats cards show: Total Staff: 2, Active Staff: 2, Inactive Staff: 0
- [ ] Table shows 2 staff members with their details
- [ ] Search and filter dropdowns work
- [ ] "Add Staff" button is visible

## Test Token (For Manual Testing)
If you need to test with a fresh token directly, use this one:
```
25|ODfT0wFJkZGM2RiMPiNJLh7DNfQHfsKJI7kW6Mcod4b95548
```

To use it:
1. Open browser console (F12)
2. Run:
```javascript
localStorage.setItem('token', '25|ODfT0wFJkZGM2RiMPiNJLh7DNfQHfsKJI7kW6Mcod4b95548')
window.location.reload()
```

## Common Issues

### Issue: Still shows "No staff members found"
**Solution:**
1. Check browser console (F12) for any errors
2. Verify the token exists: `localStorage.getItem('token')` should return a string like "XX|YYYYY"
3. Try hard refresh: Ctrl + Shift + R (Windows) or Cmd + Shift + R (Mac)
4. Clear browser cache completely and try again

### Issue: 401 Unauthorized error
**Solution:**
- Token is missing or invalid
- Follow Step 1-3 again (logout, clear storage, login)

### Issue: 403 Forbidden error
**Solution:**
- User logged in with wrong role
- Make sure to select "Admin" from dropdown during login

### Issue: 500 Internal Server Error
**Solution:**
- Check if backend server is running: `php artisan serve`
- Check backend logs: `backend/storage/logs/laravel.log`
- Clear backend cache:
  ```bash
  cd backend
  php artisan cache:clear
  php artisan config:clear
  php artisan route:clear
  ```

## Debug Information Display
The frontend now shows debug info when empty:
- User Role
- User ID
- Tenant ID
- Token status
- Loading status

This helps identify the exact issue if the problem persists.

## Next Steps
Once staff is displaying correctly:
1. Test "Add Staff" functionality
2. Test "Edit Staff" functionality
3. Test "Toggle Status" functionality
4. Test "Delete Staff" functionality
5. Test search and filters
6. Test pagination (if you add more staff)

## Status: Backend Working ✓ | Frontend Needs Fresh Login
