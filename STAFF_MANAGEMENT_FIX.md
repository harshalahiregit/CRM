# 🔧 Staff Management 500 Error - Complete Fix Guide

**Problem:** Staff Management page shows "No staff members found" with 500 Internal Server errors and "Unauthenticated" / "Unverified token" messages.

**Root Cause:** Token authentication is failing. Either:
1. Token has expired
2. Token is invalid/corrupted in localStorage
3. Personal access tokens table has issues
4. CORS/middleware misconfiguration

---

## 🚀 Quick Fix Options

### Option 1: Clear Storage & Re-login (Recommended)
**This is the fastest and safest fix**

1. **Open Browser Console** (Press F12)
2. **Clear storage:**
   ```javascript
   localStorage.clear()
   sessionStorage.clear()
   ```
3. **Refresh and go to login:** `http://localhost:5173`
4. **Login with:**
   - Email: `admin@demo.com`
   - Password: `password123`
   - Role: `Admin`
5. **Navigate to Staff Management** from sidebar

---

### Option 2: Backend Token Reset (If Option 1 doesn't work)

#### Step 1: Check Database
```bash
cd backend
php artisan tinker
```

```php
// Check if admin user exists
$admin = User::where('email', 'admin@demo.com')->first();
dd($admin);

// Check tokens
$admin->tokens()->get();

// Delete all old tokens
$admin->tokens()->delete();
```

#### Step 2: Regenerate Fresh Token
```php
// Still in tinker
$token = $admin->createToken('fresh-token', ['*'], now()->addDays(30))->plainTextToken;
echo "New Token: " . $token;
```

Copy this token and use it in frontend localStorage:
```javascript
localStorage.setItem('token', 'YOUR_TOKEN_HERE')
```

---

### Option 3: Verify Backend is Running Correctly

#### Check Laravel Server
```bash
cd backend
php artisan serve
```

#### Check Route is Accessible
```bash
curl -X GET http://127.0.0.1:8000/api/admin/staff/stats \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

Expected response:
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

---

## 🔍 Debugging Steps

### 1. Check Laravel Logs
```bash
cd backend
tail -f storage/logs/laravel.log
```

Look for authentication errors or SQL issues.

### 2. Check Browser Network Tab
1. Open DevTools (F12)
2. Go to Network tab
3. Filter by "Fetch/XHR"
4. Reload the staff page
5. Look at the failing requests:
   - **401 Unauthorized** = Bad/expired token
   - **403 Forbidden** = User doesn't have admin role
   - **500 Internal Server Error** = Backend error (check logs)

### 3. Verify Token in Browser
```javascript
// In browser console
const token = localStorage.getItem('token')
console.log('Token:', token)

// Decode token ID (first part before |)
const tokenId = token.split('|')[0]
console.log('Token ID:', tokenId)
```

### 4. Check Frontend API Configuration
Look for the API base URL in your frontend:
- Should be: `http://127.0.0.1:8000/api` or `http://localhost:8000/api`
- Not: `http://localhost:5173/api` (this would call Vite server, not Laravel)

---

## 🛠 Backend Fixes (If Issues Persist)

### Fix 1: Ensure Sanctum Middleware is Registered

**File:** `backend/bootstrap/app.php`

Check if middleware alias is registered:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \App\Http\Middleware\EnsureUserHasRole::class,
    ]);
})
```

### Fix 2: Check CORS Configuration

**File:** `backend/config/cors.php`

Should allow your frontend:
```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],

'allowed_origins' => [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
],

'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true,
```

### Fix 3: Verify Sanctum Stateful Domains

**File:** `backend/.env`

Add frontend URL:
```env
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:3000
SESSION_DOMAIN=localhost
```

### Fix 4: Check Personal Access Tokens Table

```bash
cd backend
php artisan migrate:status
```

If `personal_access_tokens` is missing:
```bash
php artisan migrate --path=/database/migrations/2026_06_23_123838_create_personal_access_tokens_table.php
```

---

## 🧪 Test After Fix

### Test 1: Login Endpoint
```bash
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@demo.com",
    "password": "password123",
    "role": "admin"
  }'
```

Should return:
```json
{
  "status": "success",
  "message": "Login successful",
  "data": {
    "access_token": "...",
    "user": { ... }
  }
}
```

### Test 2: Protected Endpoint
```bash
curl -X GET http://127.0.0.1:8000/api/admin/staff/stats \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

Should return stats, not 401/500.

---

## 🎯 Most Likely Solution

**99% of the time, this is a stale/invalid token issue.**

### Quick Command Sequence:
```bash
# 1. Clear frontend storage
Open browser console (F12) and run:
localStorage.clear()

# 2. Restart Laravel (if needed)
cd backend
php artisan cache:clear
php artisan config:clear
php artisan serve

# 3. Login again
Go to http://localhost:5173 and login
```

---

## 📞 Still Not Working?

Run this diagnostic script to check everything:

**File:** `backend/diagnose.php`
```php
<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Diagnostic Report ===\n\n";

// Check database connection
try {
    DB::connection()->getPdo();
    echo "✓ Database connected\n";
} catch (\Exception $e) {
    echo "✗ Database error: {$e->getMessage()}\n";
}

// Check admin user
$admin = \App\Models\User::where('email', 'admin@demo.com')->first();
if ($admin) {
    echo "✓ Admin user exists (ID: {$admin->id})\n";
    echo "  Role: {$admin->role}\n";
    echo "  Status: {$admin->status}\n";
    echo "  Tokens: " . $admin->tokens()->count() . "\n";
} else {
    echo "✗ Admin user not found\n";
}

// Check personal_access_tokens table
try {
    $tokenCount = DB::table('personal_access_tokens')->count();
    echo "✓ personal_access_tokens table exists ({$tokenCount} tokens)\n";
} catch (\Exception $e) {
    echo "✗ personal_access_tokens table missing\n";
}

// Check staff users
$staffCount = \App\Models\User::where('role', 'staff')->count();
echo "✓ Staff members in database: {$staffCount}\n";

echo "\n=== End Report ===\n";
```

Run with:
```bash
cd backend
php diagnose.php
```

---

## Summary

**Most Common Fix:**
1. Clear localStorage in browser console
2. Re-login at http://localhost:5173
3. Navigate to Staff Management

**If that doesn't work:**
1. Check Laravel logs for actual error
2. Regenerate token via tinker
3. Verify CORS and Sanctum config

The backend code looks correct, so this is almost certainly a **frontend token storage issue**.
