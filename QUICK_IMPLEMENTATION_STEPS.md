# 🚀 QUICK IMPLEMENTATION STEPS - TrulyTalent + Internal/External Flow

**Time Required:** 12-14 hours  
**Result:** Complete recruiter system with auto-sync

---

## ⚡ STEP-BY-STEP (Copy-Paste Ready)

### STEP 1: Run Migrations (5 mins)

```bash
cd backend
php artisan make:migration add_trulytalent_and_internal_fields
```

**Migration file:**
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // TrulyTalent integration fields
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->string('external_job_id')->nullable()->after('applicant_count');
            $table->string('external_platform')->nullable()->after('external_job_id');
            $table->timestamp('synced_at')->nullable()->after('external_platform');
            
            // Internal posting period
            $table->integer('internal_days')->default(15)->after('synced_at');
            $table->timestamp('internal_period_ends_at')->nullable()->after('internal_days');
            $table->timestamp('external_opened_at')->nullable()->after('internal_period_ends_at');
            $table->string('filled_by')->nullable()->after('external_opened_at');
        });

        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->string('external_candidate_id')->nullable()->after('final_decision');
            $table->boolean('is_internal_candidate')->default(false)->after('external_candidate_id');
            $table->foreignId('employee_id')->nullable()->after('is_internal_candidate');
        });
    }

    public function down()
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->dropColumn([
                'external_job_id', 'external_platform', 'synced_at',
                'internal_days', 'internal_period_ends_at', 'external_opened_at', 'filled_by'
            ]);
        });

        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn(['external_candidate_id', 'is_internal_candidate', 'employee_id']);
        });
    }
};
```

```bash
php artisan migrate
```

---

### STEP 2: Create Webhook Controller (10 mins)

```bash
php artisan make:controller Api/TrulyTalentWebhookController
```

**File: `backend/app/Http/Controllers/Api/TrulyTalentWebhookController.php`**

*(Full code in TRULYTALENT_WEBHOOK_IMPLEMENTATION_PLAN.md - Section Step 1.1)*

---

### STEP 3: Add Webhook Route (2 mins)

**File: `backend/routes/api.php`**

Add this line:
```php
// TrulyTalent webhook (public, no auth)
Route::post('/webhooks/trulytalent', [App\Http\Controllers\Api\TrulyTalentWebhookController::class, 'handle']);
```

---

### STEP 4: Add Config (3 mins)

**File: `backend/.env`**

Add:
```env
TRULYTALENT_API_KEY=your_api_key_here
TRULYTALENT_WEBHOOK_SECRET=your_webhook_secret_here
TRULYTALENT_API_URL=https://api.trulytalent.com/v1
```

**File: `backend/config/services.php`**

Add:
```php
'trulytalent' => [
    'api_key' => env('TRULYTALENT_API_KEY'),
    'webhook_secret' => env('TRULYTALENT_WEBHOOK_SECRET'),
    'api_url' => env('TRULYTALENT_API_URL', 'https://api.trulytalent.com/v1'),
],
```

---

### STEP 5: Test Webhook (5 mins)

**Create file: `backend/test_webhook.php`**

```php
<?php
require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\Http;

$payload = [
    'event' => 'candidate.applied',
    'tenant_id' => 2, // Your tenant ID
    'job_id' => 'TT-12345',
    'candidate' => [
        'id' => 'TT-CAND-001',
        'name' => 'Test Candidate',
        'email' => 'test@example.com',
        'phone' => '+919876543210',
        'location' => 'Mumbai',
        'experience' => 5,
        'skills' => ['PHP', 'Laravel'],
    ],
];

$response = Http::post('http://127.0.0.1:8000/api/webhooks/trulytalent', $payload);

echo "Status: {$response->status()}\n";
echo "Response: {$response->body()}\n";
```

Run:
```bash
php test_webhook.php
```

---

### STEP 6: Create Auto-Convert Command (15 mins)

```bash
php artisan make:command ConvertInternalPostings
```

**File: `backend/app/Console/Commands/ConvertInternalPostings.php`**

*(Full code in TRULYTALENT_WEBHOOK_IMPLEMENTATION_PLAN.md - Section Step 2.2)*

**Register in: `backend/app/Console/Kernel.php`**

Add:
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('hr:convert-internal-postings')->daily();
    // Existing schedules...
}
```

Test manually:
```bash
php artisan hr:convert-internal-postings
```

---

### STEP 7: Employee Portal Backend (20 mins)

```bash
php artisan make:controller Api/EmployeePortalController
```

**File: `backend/app/Http/Controllers/Api/EmployeePortalController.php`**

*(Full code in TRULYTALENT_WEBHOOK_IMPLEMENTATION_PLAN.md - Section Step 2.3)*

**Add routes in: `backend/routes/api.php`**

```php
// Employee internal job portal
Route::middleware(['auth:sanctum', 'role:staff'])->prefix('employee')->group(function () {
    Route::get('/internal-jobs', [App\Http\Controllers\Api\EmployeePortalController::class, 'internalJobs']);
    Route::post('/apply/{jobId}', [App\Http\Controllers\Api\EmployeePortalController::class, 'apply']);
    Route::get('/my-applications', [App\Http\Controllers\Api\EmployeePortalController::class, 'myApplications']);
});
```

---

### STEP 8: Employee Portal Frontend (30 mins)

**Create file: `frontend/src/pages/employee/InternalJobsPage.jsx`**

*(Full code in TRULYTALENT_WEBHOOK_IMPLEMENTATION_PLAN.md - Section Step 2.3)*

**Add route in: `frontend/src/App.jsx`**

```jsx
// Inside <Routes>
<Route path="/app/employee/internal-jobs" element={<InternalJobsPage />} />
```

**Add to Sidebar: `frontend/src/components/layout/Sidebar.jsx`**

```jsx
// Add in Employee section (if user.role === 'staff')
{user?.role === 'staff' && (
  <NavLink to="/app/employee/internal-jobs">
    <Briefcase size={18} />
    Internal Jobs
  </NavLink>
)}
```

---

### STEP 9: Update Job Creation Form (20 mins)

**File: `frontend/src/pages/hr/JobPostingForm.jsx`** (or wherever job form is)

Add this field:
```jsx
<div className="form-group">
  <label>Posting Type *</label>
  <select 
    name="posting_type" 
    value={formData.posting_type || 'External'} 
    onChange={handleChange}
    required
  >
    <option value="Internal">🔒 Internal Only (Employees first - 15 days)</option>
    <option value="External">🌍 External Only (Public job boards)</option>
    <option value="Both">🔓 Both (Internal + External simultaneously)</option>
  </select>
  
  {formData.posting_type === 'Internal' && (
    <div className="mt-2">
      <label>Internal Period (Days)</label>
      <input 
        type="number" 
        name="internal_days" 
        value={formData.internal_days || 15}
        onChange={handleChange}
        min="1"
        max="30"
        className="form-input"
      />
      <p className="text-sm text-gray-500 mt-1">
        Job will auto-convert to External after this period
      </p>
    </div>
  )}
</div>
```

**Update submit handler to include new fields:**
```jsx
const handleSubmit = async (e) => {
  e.preventDefault();
  
  const payload = {
    ...formData,
    // Auto-calculate internal_period_ends_at
    internal_period_ends_at: formData.posting_type === 'Internal' 
      ? moment().add(formData.internal_days || 15, 'days').format('YYYY-MM-DD HH:mm:ss')
      : null,
  };
  
  // Rest of submit logic...
};
```

---

### STEP 10: Update Dashboard (20 mins)

**Backend: `backend/app/Http/Controllers/Api/Hr/HRDashboardController.php`**

Add these metrics:
```php
public function index(Request $request)
{
    $tenantId = $request->user()->tenant_id;

    return response()->json([
        // ... existing metrics
        
        // NEW: Internal/External metrics
        'internal_postings' => HrJobPosting::where('tenant_id', $tenantId)
            ->where('posting_type', 'Internal')->count(),
        'external_postings' => HrJobPosting::where('tenant_id', $tenantId)
            ->where('posting_type', 'External')->count(),
        'internal_candidates' => HrCandidate::where('tenant_id', $tenantId)
            ->where('is_internal_candidate', true)->count(),
        'external_candidates' => HrCandidate::where('tenant_id', $tenantId)
            ->where('is_internal_candidate', false)->count(),
        'internal_fill_rate' => $this->getInternalFillRate($tenantId),
        'trulytalent_synced_today' => HrCandidate::where('tenant_id', $tenantId)
            ->where('source', 'TrulyTalent')
            ->whereDate('created_at', today())
            ->count(),
    ]);
}

private function getInternalFillRate($tenantId)
{
    $total = HrCandidate::where('tenant_id', $tenantId)->where('stage', 'Hired')->count();
    if ($total === 0) return 0;
    
    $internal = HrCandidate::where('tenant_id', $tenantId)
        ->where('is_internal_candidate', true)
        ->where('stage', 'Hired')->count();
    
    return round(($internal / $total) * 100, 1);
}
```

**Frontend: `frontend/src/pages/hr/HRDashboard.jsx`**

Add new stat cards:
```jsx
<div className="stats-grid">
  {/* Existing stats... */}
  
  {/* NEW: Internal/External Stats */}
  <StatCard 
    icon={<Users />}
    title="Internal Candidates"
    value={stats.internal_candidates}
    color="yellow"
  />
  <StatCard 
    icon={<Globe />}
    title="External Candidates"
    value={stats.external_candidates}
    color="blue"
  />
  <StatCard 
    icon={<TrendingUp />}
    title="Internal Fill Rate"
    value={`${stats.internal_fill_rate}%`}
    color="green"
  />
  <StatCard 
    icon={<Zap />}
    title="TrulyTalent Synced Today"
    value={stats.trulytalent_synced_today}
    color="purple"
  />
</div>
```

---

## ✅ TESTING CHECKLIST

### Test 1: Webhook Integration
```bash
# Run test webhook script
php backend/test_webhook.php

# Check database
php artisan tinker
>>> HrCandidate::where('source', 'TrulyTalent')->get()
```

### Test 2: Internal Posting Flow
1. Create job with `posting_type = 'Internal'`
2. Login as employee (hr@demo.com or manager@demo.com)
3. Go to "Internal Jobs" page
4. Apply for job
5. Check candidate created with `is_internal_candidate = true`

### Test 3: Auto-Convert
```bash
# Manually trigger conversion
php artisan hr:convert-internal-postings

# Or wait for cron (daily)
```

### Test 4: Dashboard Metrics
1. Open HR Dashboard
2. Verify new stat cards show correct numbers
3. Create internal candidate → verify count increases
4. Create external candidate → verify count increases

---

## 🎯 FINAL RESULT

After completing all steps:

✅ **TrulyTalent webhook working** → Candidates auto-sync  
✅ **Internal posting flow** → Employees see internal jobs  
✅ **Auto-conversion** → Internal → External after 15 days  
✅ **Dashboard metrics** → Internal vs External tracking  
✅ **Tenant isolation** → Still 100% working  

**Total time:** 12-14 hours
**Production ready:** YES ✅

---

## 🆘 TROUBLESHOOTING

### Webhook not receiving data?
```bash
# Check logs
tail -f backend/storage/logs/laravel.log

# Test webhook manually
curl -X POST http://127.0.0.1:8000/api/webhooks/trulytalent \
  -H "Content-Type: application/json" \
  -d '{"event":"candidate.applied","tenant_id":2,...}'
```

### Employee can't see internal jobs?
```bash
# Check user role
php artisan tinker
>>> User::where('email', 'hr@demo.com')->first()->role
# Should be 'staff'

# Check job posting_type
>>> HrJobPosting::where('posting_type', 'Internal')->get()
```

### Auto-convert not working?
```bash
# Check cron is registered
php artisan schedule:list

# Run manually
php artisan hr:convert-internal-postings

# Check internal_period_ends_at is set
>>> HrJobPosting::whereNotNull('internal_period_ends_at')->get()
```

---

## 📞 NEXT STEPS

1. **Complete Steps 1-10** (12-14 hours)
2. **Test each feature** (2 hours)
3. **Deploy to staging** (1 hour)
4. **Get TrulyTalent webhook URL** and register it
5. **Production deployment** ✅

**Questions? Check:**
- `TRULYTALENT_WEBHOOK_IMPLEMENTATION_PLAN.md` (detailed plan)
- `TENANT_ISOLATION_AND_INTERNAL_RECRUITMENT_STATUS.md` (current status)

