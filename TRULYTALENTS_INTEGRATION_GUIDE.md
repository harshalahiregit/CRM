# TrulyTalents.com Integration Guide - HR Module Connection

**Date:** July 4, 2026  
**Requirement:** Connect your HR module to TrulyTalents.com to sync job posts and candidates

---

## 🎯 REQUIREMENT UNDERSTANDING

**User Need (Marathi):**
> "https://trulytalents.com/ la connect pahije aapla hr module means tithle job posts vaigre ithe dskhavta yeil"

**Translation:**
> "Need to connect our HR module to TrulyTalents.com - their job posts should be visible here (in our system)"

---

## 📊 TRULYTALENTS.COM OVERVIEW

**Website:** https://trulytalents.com/  
**Type:** Job Portal / Recruitment Platform (Indian Market)  
**Services:**
- Job Posting Platform
- Resume Database
- Recruitment Portal
- Staffing Services
- College Placement
- Career & Training

**Current Status:** Website is active with jobs listed

---

## ⚠️ INTEGRATION CHALLENGE

**Problem:** TrulyTalents.com does NOT have publicly documented APIs.

**Evidence:**
- No public API documentation found
- No developer portal visible
- No integration guides available
- Website appears to be a standard job portal without API access

**This means:** We need to contact them directly OR use alternative integration methods.

---

## 🔄 INTEGRATION OPTIONS (3 Approaches)

### **OPTION 1: Direct API Integration** ⭐ (RECOMMENDED)

**Steps to Get Access:**

1. **Contact TrulyTalents Business Team**
   ```
   Email: contact@trulytalents.com
   Phone: [Check their website contact page]
   Request: Enterprise/Business API Access
   ```

2. **What to Ask For:**
   ```
   Subject: API Integration Request for Enterprise HR System
   
   Dear TrulyTalents Team,
   
   We are building an enterprise HR Recruitment Management System and would like
   to integrate with TrulyTalents.com to:
   
   1. Post jobs from our system to TrulyTalents automatically
   2. Receive candidate applications via webhook/API
   3. Sync job status (Active/Closed) between systems
   
   Do you provide:
   - REST API for job posting?
   - Webhook for candidate applications?
   - API documentation for integration?
   - Test/Sandbox environment?
   
   Our tech stack: Laravel (PHP) + React
   Expected volume: 50-100 job postings per month
   
   Looking forward to your response.
   
   Best regards,
   [Your Name]
   [Your Company]
   ```

3. **What They Might Provide:**
   - API Key / OAuth credentials
   - API documentation
   - Webhook endpoints
   - Sandbox environment for testing

4. **Once You Get API Access:**
   - Follow the implementation plan in `TRULYTALENT_WEBHOOK_IMPLEMENTATION_PLAN.md`
   - Replace "TrulyTalent" with "TrulyTalents" in code
   - Update API URLs as per their documentation

---

### **OPTION 2: Manual Job Posting (Current/Interim Solution)** ✅

**How It Works:**

Your HR system creates the job → HR manually posts to TrulyTalents.com → Candidates apply on TrulyTalents → HR manually adds to your system

**Implementation in Your System:**

```php
// backend/app/Http/Controllers/Api/Hr/JobPostingController.php

public function store(Request $request)
{
    // ... existing validation
    
    $jobPosting = HrJobPosting::create($validated);
    
    // Generate quick post link for TrulyTalents
    $jobPosting->external_post_links = [
        'trulytalents' => $this->generateTrulyTalentsLink($jobPosting),
        'linkedin' => $this->generateLinkedInLink($jobPosting),
        'naukri' => $this->generateNaukriLink($jobPosting),
    ];
    
    $jobPosting->save();
    
    return response()->json($jobPosting);
}

private function generateTrulyTalentsLink($job)
{
    // Pre-fill TrulyTalents posting form
    $params = http_build_query([
        'title' => $job->title,
        'location' => $job->location,
        'salary_from' => $job->salary_from,
        'salary_to' => $job->salary_to,
        'description' => $job->description,
    ]);
    
    return 'https://trulytalents.com/post-job?' . $params;
}
```

**Frontend Enhancement:**

```jsx
// frontend/src/pages/hr/JobPostingDetail.jsx

export default function JobPostingDetail() {
  const [job, setJob] = useState(null);
  
  const handlePostToExternal = (platform) => {
    const links = {
      trulytalents: job.external_post_links?.trulytalents,
      linkedin: job.external_post_links?.linkedin,
      naukri: job.external_post_links?.naukri,
    };
    
    window.open(links[platform], '_blank');
  };
  
  return (
    <div>
      <h1>{job.title}</h1>
      
      {/* Quick Post Buttons */}
      <div className="external-post-section">
        <h3>Post to Job Boards</h3>
        <div className="button-group">
          <button 
            onClick={() => handlePostToExternal('trulytalents')}
            className="btn-trulytalents"
          >
            📤 Post to TrulyTalents
          </button>
          <button 
            onClick={() => handlePostToExternal('linkedin')}
            className="btn-linkedin"
          >
            📤 Post to LinkedIn
          </button>
          <button 
            onClick={() => handlePostToExternal('naukri')}
            className="btn-naukri"
          >
            📤 Post to Naukri
          </button>
        </div>
        
        {/* Track external job IDs */}
        <div className="external-ids">
          <input 
            type="text" 
            placeholder="TrulyTalents Job ID (paste after posting)"
            onBlur={(e) => saveExternalId('trulytalents', e.target.value)}
          />
        </div>
      </div>
    </div>
  );
}
```

**Database Migration:**

```php
// Migration: add_external_posting_tracking.php
Schema::table('hr_job_postings', function (Blueprint $table) {
    $table->json('external_post_links')->nullable(); // Quick post links
    $table->json('external_job_ids')->nullable(); // Track external IDs
    // Example: {"trulytalents": "TT-12345", "linkedin": "LI-67890"}
});
```

---

### **OPTION 3: RSS/Web Scraping (NOT RECOMMENDED)** ⚠️

**Only if API is not available and manual is too time-consuming.**

TrulyTalents likely doesn't provide RSS feeds, and web scraping:
- Violates terms of service
- Breaks easily when site changes
- May be illegal
- Not reliable for production

**Do NOT use this option without legal approval.**

---

## 🚀 RECOMMENDED IMPLEMENTATION PLAN

### **PHASE 1: Contact TrulyTalents (Week 1)**

**Action Items:**
1. ✅ Visit https://trulytalents.com/contact (find contact info)
2. ✅ Send email requesting API access (use template above)
3. ✅ Call their business team
4. ✅ Ask for:
   - API documentation
   - API key/credentials
   - Webhook setup
   - Pricing (if paid service)

**Expected Response Time:** 3-7 days

---

### **PHASE 2A: If API Available (Week 2-3)**

Follow the complete implementation in `TRULYTALENT_WEBHOOK_IMPLEMENTATION_PLAN.md` with these adjustments:

1. **Update Config:**
```php
// .env
TRULYTALENTS_API_KEY=your_api_key_here
TRULYTALENTS_WEBHOOK_SECRET=your_webhook_secret_here
TRULYTALENTS_API_URL=https://api.trulytalents.com/v1  # (they'll provide this)
```

2. **Create Integration Service:**
```php
// app/Services/TrulyTalentsService.php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\HrJobPosting;

class TrulyTalentsService
{
    private $apiUrl;
    private $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('services.trulytalents.api_url');
        $this->apiKey = config('services.trulytalents.api_key');
    }

    /**
     * Post job to TrulyTalents
     */
    public function postJob(HrJobPosting $job)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '/jobs', [
                'title' => $job->title,
                'description' => $job->description,
                'requirements' => $job->requirements,
                'location' => $job->location,
                'job_type' => $job->job_type,
                'salary_from' => $job->salary_from,
                'salary_to' => $job->salary_to,
                'department' => $job->department,
                'closing_date' => $job->closing_date?->format('Y-m-d'),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Save TrulyTalents job ID
                $job->update([
                    'external_job_id' => $data['job_id'],
                    'external_platform' => 'TrulyTalents',
                    'synced_at' => now(),
                ]);

                return [
                    'success' => true,
                    'job_id' => $data['job_id'],
                    'message' => 'Job posted to TrulyTalents successfully',
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
            ];

        } catch (\Exception $e) {
            \Log::error('TrulyTalents API Error', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update job on TrulyTalents
     */
    public function updateJob(HrJobPosting $job)
    {
        if (!$job->external_job_id) {
            return ['success' => false, 'error' => 'No external job ID'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->put($this->apiUrl . '/jobs/' . $job->external_job_id, [
                'title' => $job->title,
                'description' => $job->description,
                'status' => $job->status === 'Active' ? 'open' : 'closed',
            ]);

            if ($response->successful()) {
                $job->update(['synced_at' => now()]);
                return ['success' => true];
            }

            return ['success' => false, 'error' => $response->body()];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch candidates from TrulyTalents
     */
    public function fetchCandidates($jobId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->get($this->apiUrl . "/jobs/{$jobId}/candidates");

            if ($response->successful()) {
                return $response->json();
            }

            return [];

        } catch (\Exception $e) {
            \Log::error('TrulyTalents fetch candidates error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
```

3. **Update Job Posting Controller:**
```php
// app/Http/Controllers/Api/Hr/JobPostingController.php

use App\Services\TrulyTalentsService;

public function store(Request $request)
{
    // ... existing validation
    
    $jobPosting = HrJobPosting::create($validated);
    
    // Auto-post to TrulyTalents if external or both
    if (in_array($jobPosting->posting_type, ['External', 'Both'])) {
        $trulyTalents = app(TrulyTalentsService::class);
        $result = $trulyTalents->postJob($jobPosting);
        
        if ($result['success']) {
            return response()->json([
                'job' => $jobPosting->fresh(),
                'trulytalents' => [
                    'posted' => true,
                    'job_id' => $result['job_id'],
                ],
            ], 201);
        }
    }
    
    return response()->json($jobPosting, 201);
}

public function update(Request $request, HrJobPosting $jobPosting)
{
    $jobPosting->update($request->all());
    
    // Sync with TrulyTalents if external ID exists
    if ($jobPosting->external_job_id) {
        $trulyTalents = app(TrulyTalentsService::class);
        $trulyTalents->updateJob($jobPosting);
    }
    
    return response()->json($jobPosting);
}
```

4. **Create Webhook Handler:**
```php
// routes/api.php
Route::post('/webhooks/trulytalents', [TrulyTalentsWebhookController::class, 'handle']);

// app/Http/Controllers/Api/TrulyTalentsWebhookController.php
class TrulyTalentsWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Verify webhook signature (TrulyTalents will provide method)
        if (!$this->verifySignature($request)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        switch ($event) {
            case 'candidate.applied':
                $this->handleCandidateApplied($data);
                break;
            case 'job.viewed':
                $this->handleJobViewed($data);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    private function handleCandidateApplied($data)
    {
        // Find job by external_job_id
        $job = HrJobPosting::where('external_job_id', $data['job_id'])->first();
        
        if (!$job) {
            \Log::warning('Job not found for TrulyTalents application', $data);
            return;
        }

        // Create candidate in your system
        HrCandidate::create([
            'tenant_id' => $job->tenant_id,
            'job_posting_id' => $job->id,
            'name' => $data['candidate']['name'],
            'email' => $data['candidate']['email'],
            'phone' => $data['candidate']['phone'],
            'source' => 'TrulyTalents',
            'stage' => 'Applied',
            'external_candidate_id' => $data['candidate']['id'],
            'is_internal_candidate' => false,
        ]);

        \Log::info('Candidate synced from TrulyTalents', ['candidate' => $data['candidate']['id']]);
    }

    private function verifySignature(Request $request)
    {
        // TrulyTalents will provide signature verification method
        $signature = $request->header('X-TrulyTalents-Signature');
        $secret = config('services.trulytalents.webhook_secret');
        
        $computed = hash_hmac('sha256', $request->getContent(), $secret);
        
        return hash_equals($signature, $computed);
    }
}
```

---

### **PHASE 2B: If API NOT Available (Manual Solution)** ✅

Implement **Option 2** (Manual Posting with Quick Links):

1. ✅ Add external post links to job detail page
2. ✅ Add external_job_ids tracking
3. ✅ HR clicks "Post to TrulyTalents" → Opens pre-filled form
4. ✅ HR copies job ID from TrulyTalents back to your system
5. ✅ Candidates apply on TrulyTalents → HR manually adds to your system

**Time:** 2-3 hours (much simpler than API integration)

---

## 📊 INTEGRATION COMPARISON

| Feature | Option 1: API | Option 2: Manual | Option 3: Scraping |
|---------|---------------|------------------|-------------------|
| **Auto Post Jobs** | ✅ Yes | ⚠️ Semi-Auto | ⚠️ Manual |
| **Auto Sync Candidates** | ✅ Yes | ❌ No | ⚠️ Unreliable |
| **Maintenance** | Low | Low | High |
| **Reliability** | High | Medium | Low |
| **Legal** | ✅ Allowed | ✅ Allowed | ⚠️ Check ToS |
| **Setup Time** | 2-3 weeks | 2-3 hours | 1-2 weeks |
| **Cost** | Possible API fees | Free | Free |
| **Recommendation** | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐ |

---

## ✅ IMMEDIATE NEXT STEPS

### **Step 1: Contact TrulyTalents (TODAY)**

Email draft ready:
```
To: contact@trulytalents.com
Subject: Enterprise API Integration Request

Dear TrulyTalents Team,

We are developing an enterprise HR recruitment system and want to integrate
with your platform. Do you provide API access for:

1. Posting jobs programmatically
2. Receiving candidate applications via webhook
3. Job status synchronization

Please share:
- API documentation
- Pricing (if applicable)
- Integration timeline

Tech Stack: Laravel + React
Expected Volume: 50-100 jobs/month

Best regards,
[Your Name]
[Company Name]
[Phone Number]
```

### **Step 2: Implement Interim Solution (THIS WEEK)**

While waiting for API response, implement manual posting:

```bash
# Run migration
php artisan make:migration add_external_posting_fields
php artisan migrate

# Add quick post buttons to job detail page
# Takes 2-3 hours
```

### **Step 3: Wait for Response (1 WEEK)**

- If API available → Follow Phase 2A (full integration)
- If API not available → Continue with manual solution
- If they want partnership → Negotiate terms

---

## 🎯 EXPECTED OUTCOMES

### **With API Integration:**
```
Your System                    TrulyTalents.com
─────────────────────────────────────────────────
Create Job → (API Post) →     Job Live
                              Candidate Applies
Candidate Created ← (Webhook) ← Application
Interview Process →
Hired → (API Update) →        Job Closed
```

### **With Manual Integration:**
```
Your System                    TrulyTalents.com
─────────────────────────────────────────────────
Create Job → Click Button →   Manually Post
                              Candidate Applies
Manually Add Candidate ←      Download Resume
Interview Process →
Hired → Manual Update →       Manual Close
```

---

## 📝 MARATHI SUMMARY

**तुमचा प्रश्न:** TrulyTalents.com ला आपला HR module connect करायचा आहे

**उत्तर:**

### **समस्या:**
TrulyTalents.com ची public API नाही (publicly available नाही)

### **उपाय (3 options):**

#### **Option 1: API घ्या (Best) ⭐**
```
1. TrulyTalents ला email/call करा
2. त्यांचा API access मागा
3. मिळाला तर: full auto-sync (12-14 hours work)
4. नाही मिळाला तर: Option 2 use करा
```

#### **Option 2: Manual (Quick Solution) ✅**
```
1. Job create केली तुमच्या system मध्ये
2. "Post to TrulyTalents" button दाबा
3. TrulyTalents website उघडेल (pre-filled form)
4. Manual post करा
5. Job ID copy करून तुमच्या system मध्ये save करा

Time: 2-3 hours implement करायला
Works: आताच start करता येईल
```

#### **Option 3: Web Scraping (DON'T USE) ❌**
```
Legal issues + unreliable
```

### **Recommended Plan:**

**आज:** TrulyTalents ला email करा API साठी  
**या week:** Manual solution implement करा (2-3 hours)  
**1 week wait:** API response येईल का ते बघा  
**If API gets:** Full integration करा (12-14 hours)  
**If no API:** Continue manual solution (works fine) ✅

---

## 📞 CONTACT INFORMATION

**TrulyTalents.com:**
- Website: https://trulytalents.com/
- Contact: [Check website for current contact info]
- Email: [Find on contact page]
- Phone: [Find on contact page]

**What to Ask:**
- API documentation
- Webhook support
- Integration guide
- Pricing
- Test environment

---

**Ready to start? Begin with Step 1 (contact them) TODAY!** ✅

