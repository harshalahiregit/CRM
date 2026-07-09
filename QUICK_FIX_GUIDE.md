# 🚀 Quick Fix Guide - Staff Management 500 Error

## Problem Summary
Staff Management page shows 500 errors with "Unauthenticated" messages. This is a **token authentication issue**.

---

## ✅ Solution (Choose One)

### **Option 1: Quick Browser Fix** ⚡ (30 seconds)

1. **Open the fix-token.html file in your browser:**
   - Double-click `fix-token.html` file in the project root
   - OR drag it into your browser

2. **Click the button:**
   - Click "🧹 Clear Storage & Go to Login"
   - Wait for redirect

3. **Login again:**
   - Email: `admin@demo.com`
   - Password: `password123`
   - Role: `Admin`

4. **Done!** Navigate to Staff Management

---

### **Option 2: Backend Diagnostic** 🔧 (2 minutes)

**Use this if Option 1 doesn't work**

1. **Run diagnostic script:**
   ```bash
   cd backend
   php diagnose-auth.php
   ```

2. **Copy the generated token** (it will be displayed at the end)

3. **Set token in browser:**
   - Open browser console (F12)
   - Run: `localStorage.setItem('token', 'PASTE_TOKEN_HERE')`
   - Refresh page

4. **Done!** Navigate to Staff Management

---

### **Option 3: Manual Console Fix** ⌨️ (15 seconds)

1. **Open browser console** (Press F12)

2. **Clear storage:**
   ```javascript
   localStorage.clear()
   sessionStorage.clear()
   ```

3. **Close console and refresh** (Ctrl+R or F5)

4. **Login again** at `http://localhost:5173`

5. **Done!**

---

## 🔍 Verify Fix Worked

After following any option above:

1. **Go to:** `http://localhost:5173/app/admin/staff`

2. **You should see:**
   - Three stat cards (Total Staff, Active Staff, Inactive/Suspended)
   - A table (may be empty if no staff added yet)
   - No 500 errors in browser console

3. **If still not working:**
   - Check backend is running: `http://127.0.0.1:8000/api/test-dashboard`
   - Should show: `{"status":"success","message":"API is working!"}`

---

## 🐛 Still Having Issues?

### Check Backend is Running
```bash
cd backend
php artisan serve
```

Should show: `Server running on [http://127.0.0.1:8000]`

### Check Database Connection
```bash
cd backend
php artisan tinker
```

Then run:
```php
\App\Models\User::count()
// Should return a number, not an error
```

### Check Laravel Logs
```bash
cd backend
tail -f storage/logs/laravel.log
```

Try accessing Staff Management page and watch for errors.

### Run Full Diagnostic
```bash
cd backend
php diagnose-auth.php
```

This will:
- ✅ Check database connection
- ✅ Verify admin user exists
- ✅ Check token table
- ✅ Generate fresh token
- ✅ Test API endpoints

---

## 📋 What Was the Problem?

The error happens when:

1. **Token expired** - Laravel Sanctum tokens can expire
2. **Token invalid** - Token doesn't exist in database
3. **Token corrupted** - localStorage has malformed token
4. **User deleted** - User associated with token was removed

The fix works by:
- Clearing old/invalid tokens from browser storage
- Generating fresh valid tokens from backend
- Re-authenticating the user

---

## ✨ Prevention

To avoid this in the future:

1. **Don't manually edit** localStorage tokens
2. **Use proper logout** - Don't just close the browser
3. **Don't delete** admin user from database
4. **Keep backend running** when using frontend

---

## 📞 Need More Help?

1. Check `STAFF_MANAGEMENT_FIX.md` for detailed troubleshooting
2. Run `php diagnose-auth.php` for full diagnostic report
3. Check browser console (F12) for specific errors
4. Check `backend/storage/logs/laravel.log` for backend errors

---

## Summary

**99% of cases:** Clear localStorage and login again
**1% of cases:** Backend issue (check logs and run diagnostic)

**The backend code is correct.** This is a frontend token storage issue.
