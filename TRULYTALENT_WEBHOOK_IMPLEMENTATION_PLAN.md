# TrulyTalent Webhook + Internal/External Recruitment Flow - Implementation Plan

**Date:** July 4, 2026  
**Requirement:** Integrate TrulyTalent webhooks + Complete internal-external recruitment flow

---

## 🎯 REQUIREMENT SUMMARY

**User Need:**
1. **TrulyTalent Webhook Integration** → Auto-sync candidates from TrulyTalent platform
2. **Internal-First Recruitment Flow** → Post internally (10-15 days) → Then external
3. **Recruiter-Style Usage** → Complete end-to-end recruitment automation

---

## 📋 SHORT IMPLEMENTATION PLAN

### **PHASE 1: TrulyTalent Webhook (3-4 hours)**

#### Step 1.1: Create Webhook Endpoint (1 hour)
```php
// routes/api.php
Route::post('/webhooks/trulytalent', [TrulyTalentWebhookController::class, 'handle'])
    ->withoutMiddleware(['auth:sanctum']); // Public webhook

// app/Http/Controllers/Api/TrulyTalentWebhookController.php
class TrulyTalentWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Verify webhook signature (security)
        if (!$this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();
        $tenantId = $payload['tenant_id'] ?? null;
        $event = $payload['event'] ?? null; // 'candidate.applied', 'candidate.updated'

        Log::info('TrulyTalent webhook received', $payload);

        switch ($event) {
            case 'candidate.applied':
                $this->handleCandidateApplied($payload, $tenantId);
                break;
            case 'candidate.updated':
                $this->handleCandidateUpdated($payload, $tenantId);
                break;
            case 'job.posted':
                $this->handleJobPosted($payload, $tenantId);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    private function handleCandidateApplied($data, $tenantId)
    {
        // Find job posting by external ID
        $jobPosting = HrJobPosting::where('tenant_id', $tenantId)
            ->where('external_job_id', $data['job_id'])
            ->first();

        if (!$jobPosting) {
            Log::warning('Job not found for webhook', ['job_id' => $data['job_id']]);
            return;
        }

        // Create candidate
        $candidate = HrCandidate::create([
            'tenant_id' => $tenantId,
            'job_posting_id' => $jobPosting->id,
            'name' => $data['candidate']['name'],
            'email' => $data['candidate']['email'],
            'phone' => $data['candidate']['phone'],
            'location' => $data['candidate']['location'] ?? null,
            'current_company' => $data['candidate']['company'] ?? null,
            'experience_years' => $data['candidate']['experience'] ?? 0,
            'source' => 'TrulyTalent',
            'stage' => 'Applied',
            'external_candidate_id' => $data['candidate']['id'], // Store TrulyTalent ID
            'resume_path' => $data['candidate']['resume_url'] ?? null,
            'linkedin_url' => $data['candidate']['linkedin'] ?? null,
            'skills' => $data['candidate']['skills'] ?? [],
            'is_internal_candidate' => false, // External from TrulyTalent
        ]);

        // Auto-compute AI score
        $aiData = app(CandidateController::class)->computeAiScore($candidate->toArray());
        $candidate->update([
            'ai_score' => $aiData['score'],
            'ai_breakdown' => $aiData['breakdown'],
        ]);

        Log::info('Candidate created from webhook', ['id' => $candidate->id]);
    }

    private function verifySignature(Request $request)
    {
        // Verify webhook came from TrulyTalent
        $signature = $request->header('X-TrulyTalent-Signature');
        $secret = config('services.trulytalent.webhook_secret');
        
        $computed = hash_hmac('sha256', $request->getContent(), $secret);
        
        return hash_equals($signature, $computed);
    }
}
```

#### Step 1.2: Add Database Fields (30 mins)
```php
// Migration: add_trulytalent_fields_to_hr_tables.php
Schema::table('hr_job_postings', function (Blueprint $table) {
    $table->string('external_job_id')->nullable(); // TrulyTalent job ID
    $table->string('external_platform')->nullable(); // 'TrulyTalent', 'LinkedIn', etc.
    $table->timestamp('synced_at')->nullable(); // Last sync time
});

Schema::table('hr_candidates', function (Blueprint $table) {
    $table->string('external_candidate_id')->nullable(); // TrulyTalent candidate ID
    $table->boolean('is_internal_candidate')->default(false); // Internal vs External
    $table->foreignId('employee_id')->nullable(); // Link to users table if internal
});
```

#### Step 1.3: Add Config (15 mins)
```php
// config/services.php
'trulytalent' => [
    'api_key' => env('TRULYTALENT_API_KEY'),
    'webhook_secret' => env('TRULYTALENT_WEBHOOK_SECRET'),
    'api_url' => env('TRULYTALENT_API_URL', 'https://api.trulytalent.com/v1'),
],

// .env
TRULYTALENT_API_KEY=your_api_key_here
TRULYTALENT_WEBHOOK_SECRET=your_webhook_secret_here
```

#### Step 1.4: Test Webhook (30 mins)
```php
// Test script: backend/test_trulytalent_webhook.php
<?php
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Simulate webhook payload
$payload = [
    'event' => 'candidate.applied',
    'tenant_id' => 2,
    'job_id' => 'TT-12345',
    'candidate' => [
        'id' => 'TT-CAND-001',
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '+919876543210',
        'location' => 'Mumbai, India',
        'company' => 'Tech Corp',
        'experience' => 5,
        'skills' => ['PHP', 'Laravel', 'React'],
        'resume_url' => 'https://trulytalent.com/resumes/john.pdf',
    ],
];

$secret = config('services.trulytalent.webhook_secret');
$signature = hash_hmac('sha256', json_encode($payload), $secret);

$response = Http::withHeaders([
    'X-TrulyTalent-Signature' => $signature,
])->post('http://127.0.0.1:8000/api/webhooks/trulytalent', $payload);

echo "Status: {$response->status()}\n";
echo "Response: {$response->body()}\n";
```

---

### **PHASE 2: Internal-First Recruitment Flow (5-6 hours)**

#### Step 2.1: Add Internal Period Fields (30 mins)
```php
// Migration: add_internal_posting_period.php
Schema::table('hr_job_postings', function (Blueprint $table) {
    $table->integer('internal_days')->default(15); // How many days for internal
    $table->timestamp('internal_period_ends_at')->nullable();
    $table->timestamp('external_opened_at')->nullable();
    $table->string('filled_by')->nullable(); // 'Internal', 'External', or null
});
```

#### Step 2.2: Auto-Convert Internal → External (2 hours)
```php
// app/Console/Commands/ConvertInternalPostings.php
<?php
namespace App\Console\Commands;

use App\Models\HrJobPosting;
use App\Models\HrCandidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Mail\InternalPeriodExpiredMail;

class ConvertInternalPostings extends Command
{
    protected $signature = 'hr:convert-internal-postings';
    protected $description = 'Convert internal postings to external after period expires';

    public function handle()
    {
        $postings = HrJobPosting::where('posting_type', 'Internal')
            ->where('status', 'Active')
            ->whereNotNull('internal_period_ends_at')
            ->where('internal_period_ends_at', '<', now())
            ->get();

        foreach ($postings as $posting) {
            // Check if any qualified internal candidates exist
            $qualified = HrCandidate::where('job_posting_id', $posting->id)
                ->where('is_internal_candidate', true)
                ->whereIn('stage', ['Interview', 'Offer', 'Hired'])
                ->exists();

            if ($qualified) {
                $this->info("Posting #{$posting->id} has qualified internal candidates - skipping");
                continue;
            }

            // Convert to External
            $posting->update([
                'posting_type' => 'External',
                'external_opened_at' => now(),
            ]);

            // Send notification to HR
            $hrUsers = \App\Models\User::where('tenant_id', $posting->tenant_id)
                ->where('internal_role', 'hr_executive')
                ->get();

            foreach ($hrUsers as $hr) {
                Mail::to($hr->email)->send(new InternalPeriodExpiredMail($posting));
            }

            $this->info("✅ Converted posting #{$posting->id} to External");
        }

        $this->info("Processed {$postings->count()} postings");
    }
}

// Register in app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('hr:convert-internal-postings')->daily();
}
```

#### Step 2.3: Employee Internal Job Portal (2 hours)

**Backend:**
```php
// routes/api.php
Route::middleware(['auth:sanctum', 'role:staff'])->prefix('employee')->group(function () {
    Route::get('/internal-jobs', [EmployeePortalController::class, 'internalJobs']);
    Route::post('/apply/{jobId}', [EmployeePortalController::class, 'apply']);
    Route::get('/my-applications', [EmployeePortalController::class, 'myApplications']);
});

// app/Http/Controllers/Api/EmployeePortalController.php
class EmployeePortalController extends Controller
{
    public function internalJobs(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        
        $jobs = HrJobPosting::where('tenant_id', $tenantId)
            ->whereIn('posting_type', ['Internal', 'Both'])
            ->where('status', 'Active')
            ->where(function($q) {
                $q->whereNull('internal_period_ends_at')
                  ->orWhere('internal_period_ends_at', '>', now());
            })
            ->with('candidates')
            ->get()
            ->map(function($job) use ($request) {
                // Check if current user already applied
                $job->already_applied = $job->candidates()
                    ->where('employee_id', $request->user()->id)
                    ->exists();
                return $job;
            });

        return response()->json($jobs);
    }

    public function apply(Request $request, $jobId)
    {
        $employee = $request->user();
        $job = HrJobPosting::findOrFail($jobId);

        // Check if already applied
        $exists = HrCandidate::where('job_posting_id', $jobId)
            ->where('employee_id', $employee->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'You have already applied for this job'], 422);
        }

        // Create internal candidate
        $candidate = HrCandidate::create([
            'tenant_id' => $employee->tenant_id,
            'job_posting_id' => $jobId,
            'employee_id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'department' => $employee->department,
            'current_company' => $employee->company ?? 'Current Employee',
            'source' => 'Internal Posting',
            'stage' => 'Screening', // Fast-track internal
            'is_internal_candidate' => true,
            'notes' => 'Internal candidate - Current employee',
        ]);

        // Notify hiring manager
        $manager = \App\Models\User::where('tenant_id', $employee->tenant_id)
            ->where('internal_role', 'hiring_manager')
            ->first();

        if ($manager) {
            Mail::to($manager->email)->send(
                new \App\Mail\InternalCandidateAppliedMail($candidate, $job)
            );
        }

        return response()->json($candidate, 201);
    }

    public function myApplications(Request $request)
    {
        $applications = HrCandidate::where('employee_id', $request->user()->id)
            ->with('jobPosting')
            ->latest()
            ->get();

        return response()->json($applications);
    }
}
```

**Frontend:**
```jsx
// frontend/src/pages/employee/InternalJobsPage.jsx
import React, { useState, useEffect } from 'react';
import api from '../../services/api';
import { toast } from 'react-hot-toast';

export default function InternalJobsPage() {
  const [jobs, setJobs] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchJobs();
  }, []);

  const fetchJobs = async () => {
    try {
      const res = await api.get('/api/employee/internal-jobs');
      setJobs(res.data);
    } catch (err) {
      toast.error('Failed to load jobs');
    } finally {
      setLoading(false);
    }
  };

  const handleApply = async (jobId) => {
    if (!confirm('Are you sure you want to apply for this position?')) return;
    
    try {
      await api.post(`/api/employee/apply/${jobId}`);
      toast.success('Application submitted successfully!');
      fetchJobs(); // Reload to update already_applied status
    } catch (err) {
      toast.error(err.response?.data?.message || 'Failed to apply');
    }
  };

  const getDaysLeft = (endsAt) => {
    if (!endsAt) return null;
    const days = Math.ceil((new Date(endsAt) - new Date()) / (1000 * 60 * 60 * 24));
    return days > 0 ? days : 0;
  };

  if (loading) return <div>Loading...</div>;

  return (
    <div className="p-6">
      <h1 className="text-2xl font-bold mb-6">Internal Job Openings</h1>
      
      {jobs.length === 0 ? (
        <div className="text-center py-12 bg-gray-50 rounded-lg">
          <p className="text-gray-500">No internal job openings at the moment</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {jobs.map(job => {
            const daysLeft = getDaysLeft(job.internal_period_ends_at);
            
            return (
              <div key={job.id} className="bg-white rounded-lg shadow p-6 border">
                {/* Internal Badge */}
                <div className="flex items-center justify-between mb-4">
                  <span className="px-3 py-1 bg-yellow-100 text-yellow-800 text-xs font-semibold rounded-full">
                    🔒 Internal Only
                  </span>
                  {daysLeft !== null && (
                    <span className="text-sm text-gray-500">
                      {daysLeft} days left
                    </span>
                  )}
                </div>

                {/* Job Info */}
                <h3 className="text-lg font-bold mb-2">{job.title}</h3>
                <div className="text-sm text-gray-600 mb-4 space-y-1">
                  <p>📍 {job.location}</p>
                  <p>🏢 {job.department}</p>
                  <p>💼 {job.job_type}</p>
                  {job.salary_from && (
                    <p>💰 ₹{job.salary_from} - ₹{job.salary_to}</p>
                  )}
                </div>

                {/* Description */}
                <p className="text-sm text-gray-700 mb-4 line-clamp-3">
                  {job.description}
                </p>

                {/* CTA */}
                {job.already_applied ? (
                  <button disabled className="w-full py-2 bg-gray-300 text-gray-600 rounded">
                    ✅ Already Applied
                  </button>
                ) : (
                  <button 
                    onClick={() => handleApply(job.id)}
                    className="w-full py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
                  >
                    Apply Now
                  </button>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
```

#### Step 2.4: Update Job Creation Form (1 hour)
```jsx
// frontend/src/pages/hr/JobPostingForm.jsx
<div>
  <label>Posting Type *</label>
  <select 
    name="posting_type" 
    value={formData.posting_type} 
    onChange={handleChange}
    required
  >
    <option value="">Select posting type</option>
    <option value="Internal">Internal Only (15 days, then converts to External)</option>
    <option value="External">External Only (Public job boards)</option>
    <option value="Both">Internal + External (Simultaneous)</option>
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
      />
      <p className="text-sm text-gray-500">
        Job will automatically convert to External after this period if no internal candidate is selected
      </p>
    </div>
  )}
</div>
```

---

### **PHASE 3: Recruiter Dashboard (2 hours)**

#### Step 3.1: Enhanced Dashboard with Internal/External Metrics
```php
// app/Http/Controllers/Api/Hr/HRDashboardController.php
public function index(Request $request)
{
    $tenantId = $request->user()->tenant_id;

    return response()->json([
        // Existing metrics...
        'total_open_positions' => HrJobPosting::where('tenant_id', $tenantId)->where('status', 'Active')->count(),
        'active_candidates' => HrCandidate::where('tenant_id', $tenantId)->whereNotIn('stage', ['Hired', 'Rejected'])->count(),
        
        // NEW: Internal vs External metrics
        'internal_postings' => HrJobPosting::where('tenant_id', $tenantId)
            ->where('posting_type', 'Internal')->count(),
        'external_postings' => HrJobPosting::where('tenant_id', $tenantId)
            ->where('posting_type', 'External')->count(),
        'both_postings' => HrJobPosting::where('tenant_id', $tenantId)
            ->where('posting_type', 'Both')->count(),
            
        'internal_candidates' => HrCandidate::where('tenant_id', $tenantId)
            ->where('is_internal_candidate', true)->count(),
        'external_candidates' => HrCandidate::where('tenant_id', $tenantId)
            ->where('is_internal_candidate', false)->count(),
            
        'internal_hires' => HrCandidate::where('tenant_id', $tenantId)
            ->where('is_internal_candidate', true)
            ->where('stage', 'Hired')->count(),
        'external_hires' => HrCandidate::where('tenant_id', $tenantId)
            ->where('is_internal_candidate', false)
            ->where('stage', 'Hired')->count(),
            
        // Internal fill rate (%)
        'internal_fill_rate' => $this->calculateInternalFillRate($tenantId),
        
        // Webhook sync status
        'trulytalent_synced_today' => HrCandidate::where('tenant_id', $tenantId)
            ->where('source', 'TrulyTalent')
            ->whereDate('created_at', today())
            ->count(),
    ]);
}

private function calculateInternalFillRate($tenantId)
{
    $totalHired = HrCandidate::where('tenant_id', $tenantId)
        ->where('stage', 'Hired')->count();
    
    if ($totalHired === 0) return 0;
    
    $internalHired = HrCandidate::where('tenant_id', $tenantId)
        ->where('is_internal_candidate', true)
        ->where('stage', 'Hired')->count();
    
    return round(($internalHired / $totalHired) * 100, 1);
}
```

---

## 📊 COMPLETE FLOW DIAGRAM

```
┌──────────────────────────────────────────────────────────────┐
│                    JOB POSTING CREATED                        │
│              (HR creates job in your system)                  │
└────────────────────┬─────────────────────────────────────────┘
                     │
         ┌───────────┴──────────┐
         │  Posting Type?       │
         └───────────┬──────────┘
                     │
    ┌────────────────┼────────────────┐
    │                │                │
    ▼                ▼                ▼
┌─────────┐    ┌─────────┐     ┌──────────┐
│Internal │    │  Both   │     │ External │
└────┬────┘    └────┬────┘     └─────┬────┘
     │              │                 │
     │              ├─────────────────┤
     │              │                 │
     ▼              ▼                 ▼
┌─────────────────────────┐    ┌──────────────────┐
│ Show to Employees Only  │    │ Post to External │
│ (Internal Job Portal)   │    │ (TrulyTalent +   │
│                         │    │  Job Boards)     │
│ Wait 15 days            │    └────────┬─────────┘
└────────┬────────────────┘             │
         │                              │
         │  ┌────────────────┐          │
         │  │ Webhook Event  │◄─────────┘
         │  │ (TrulyTalent)  │
         │  └────────┬───────┘
         │           │
         ▼           ▼
┌────────────────────────────────┐
│ CANDIDATES APPLY               │
│ ✅ Internal: Employee portal   │
│ ✅ External: TrulyTalent webhook│
└────────┬───────────────────────┘
         │
         ▼
┌────────────────────────────────┐
│ AI SCORING (Automatic)         │
│ Internal candidates: +10 bonus │
└────────┬───────────────────────┘
         │
         ▼
┌────────────────────────────────┐
│ SCREENING → INTERVIEW → OFFER  │
│ (Your existing workflow)       │
└────────┬───────────────────────┘
         │
         ▼
┌────────────────────────────────┐
│ HIRED                          │
│ ✅ filled_by = 'Internal' or   │
│    'External'                  │
│ ✅ Dashboard shows metrics     │
└────────────────────────────────┘
```

---

## ⏱️ TOTAL TIME ESTIMATE

| Phase | Task | Hours |
|-------|------|-------|
| **Phase 1** | TrulyTalent Webhook | 3-4h |
| • Step 1.1 | Webhook endpoint + controller | 1h |
| • Step 1.2 | Database fields | 0.5h |
| • Step 1.3 | Config + env setup | 0.25h |
| • Step 1.4 | Testing | 0.5h |
| • Step 1.5 | Push to TrulyTalent endpoint | 1h |
| **Phase 2** | Internal-External Flow | 5-6h |
| • Step 2.1 | Database fields | 0.5h |
| • Step 2.2 | Auto-convert cron job | 2h |
| • Step 2.3 | Employee portal (backend + frontend) | 2h |
| • Step 2.4 | Job form updates | 1h |
| **Phase 3** | Recruiter Dashboard | 2h |
| • Step 3.1 | Enhanced metrics API | 1h |
| • Step 3.2 | Dashboard UI updates | 1h |
| **Testing** | End-to-end testing | 2h |
| **TOTAL** | | **12-14 hours** |

---

## 🚀 QUICK START CHECKLIST

### Day 1 (4 hours): TrulyTalent Webhook
- [ ] Create webhook controller
- [ ] Add database fields (external_job_id, external_candidate_id, is_internal_candidate)
- [ ] Run migrations
- [ ] Add config in .env
- [ ] Test with sample webhook payload
- [ ] Register webhook URL with TrulyTalent

### Day 2 (6 hours): Internal Flow
- [ ] Add internal_days, internal_period_ends_at fields
- [ ] Create auto-convert command
- [ ] Register cron job
- [ ] Build employee portal backend
- [ ] Build employee portal frontend
- [ ] Update job creation form

### Day 3 (4 hours): Dashboard + Testing
- [ ] Add internal/external metrics to dashboard API
- [ ] Update dashboard UI with new metrics
- [ ] Test complete flow:
  - Create Internal job
  - Employee applies
  - Wait (or manually trigger cron)
  - Job converts to External
  - TrulyTalent webhook creates external candidate
  - Both show in candidates list
  - Verify dashboard metrics

---

## 💡 KEY POINTS

### 1. **Tenant Isolation** (Already Working ✅)
- All webhooks must include `tenant_id`
- All queries filter by `tenant_id`
- No changes needed

### 2. **TrulyTalent Integration** (New ⚠️)
- Webhook receives candidate data
- Auto-creates candidate in your system
- Marks as `is_internal_candidate = false`
- Source = 'TrulyTalent'

### 3. **Internal-First Flow** (New ⚠️)
- Job created with `posting_type = 'Internal'`
- Shows in employee portal
- After 15 days: auto-converts to 'External'
- Then posts to TrulyTalent via webhook

### 4. **Recruiter Benefits** ✅
- Single dashboard shows all candidates (internal + external)
- AI scoring works for both
- Email/WhatsApp notifications work for both
- One unified recruitment pipeline

---

## 📝 MARATHI SUMMARY

**काय करायचं आहे:**

### 1. **TrulyTalent Webhook** (3-4 तास)
```
TrulyTalent वरून candidate apply केला
    ↓
Webhook तुमच्या system ला trigger करतो
    ↓
Automatically candidate तुमच्या database मध्ये येतो
    ↓
तुम्हाला manual entry नको
```

### 2. **Internal-External Flow** (5-6 तास)
```
Job create केली (posting_type = Internal)
    ↓
15 days सुद्धा फक्त employees ला दिसते
    ↓
Employees apply करू शकतात
    ↓
15 days नंतर automatically External होते
    ↓
TrulyTalent ला पण post होते
    ↓
External candidates येतात webhook वरून
```

### 3. **Dashboard** (2 तास)
```
तुम्हाला दिसेल:
- Internal postings: 5
- External postings: 10
- Internal candidates: 8
- External candidates: 25
- Internal hires: 2 (25% internal fill rate)
- TrulyTalent synced today: 5 candidates
```

**Total काम: 12-14 तास**

**Result:** Full recruiter-style system with auto-sync + internal-first flow ✅

