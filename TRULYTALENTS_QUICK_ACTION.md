# 🚀 TrulyTalents Integration - Quick Action Plan

**Status:** Needs API Access from TrulyTalents  
**Interim Solution:** Manual posting (ready to implement)

---

## ⚡ DO THIS TODAY (30 mins)

### 1. Contact TrulyTalents for API (15 mins)

**Find Contact Info:**
- Visit: https://trulytalents.com/contact
- Or search: "TrulyTalents contact" / "TrulyTalents business"

**Email Template (Copy-Paste Ready):**

```
To: [contact email from their website]
Subject: API Integration Request - HR Recruitment System

Dear TrulyTalents Team,

Namaste!

We are building an HR Recruitment Management System and want to integrate
with TrulyTalents.com to provide better service to our clients.

Our Requirements:
1. Post jobs from our system to TrulyTalents automatically
2. Receive candidate applications via webhook/API  
3. Sync job status between systems

Questions:
- Do you provide REST API for job posting?
- Do you support webhooks for candidate applications?
- Is API documentation available?
- What is the pricing for API access?
- Can we get sandbox/test environment?

About Our System:
- Tech Stack: Laravel (PHP Backend) + React (Frontend)
- Expected Volume: 50-100 job postings per month
- Client Base: Growing SMBs and enterprises
- Integration Timeline: Next 2-3 weeks

We would love to partner with TrulyTalents to streamline recruitment
for our mutual clients.

Please let us know next steps for API integration.

Best regards,
[Your Name]
[Your Company]
[Your Phone: +91-XXXXXXXXXX]
[Your Email]
```

**Send to:**
- ✅ Email (primary contact)
- ✅ Contact form on website
- ✅ Call them (if phone number available)

---

### 2. Implement Interim Solution (15 mins read + 2 hours coding)

**While waiting for API response, implement manual quick-post:**

#### Database Migration (5 mins):

```bash
cd backend
php artisan make:migration add_external_posting_tracking
```

**File: `database/migrations/XXXX_add_external_posting_tracking.php`**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->json('external_job_ids')->nullable()->after('applicant_count');
            // Stores: {"trulytalents": "TT-12345", "linkedin": "LI-67890"}
        });
    }

    public function down()
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->dropColumn('external_job_ids');
        });
    }
};
```

```bash
php artisan migrate
```

#### Backend Helper (10 mins):

**File: `backend/app/Http/Controllers/Api/Hr/JobPostingController.php`**

Add method:

```php
/**
 * Update external job ID (after manual posting)
 */
public function updateExternalId(Request $request, HrJobPosting $jobPosting)
{
    $request->validate([
        'platform' => 'required|in:trulytalents,linkedin,naukri',
        'external_id' => 'required|string',
    ]);

    $externalIds = $jobPosting->external_job_ids ?? [];
    $externalIds[$request->platform] = $request->external_id;
    
    $jobPosting->update(['external_job_ids' => $externalIds]);

    return response()->json([
        'message' => 'External job ID saved',
        'external_ids' => $externalIds,
    ]);
}
```

**File: `backend/routes/api.php`**

Add route:

```php
Route::patch('/hr/job-postings/{jobPosting}/external-id', 
    [App\Http\Controllers\Api\Hr\JobPostingController::class, 'updateExternalId']
)->middleware(['auth:sanctum', 'role:hr_executive,admin']);
```

#### Frontend Component (1.5 hours):

**File: `frontend/src/pages/hr/JobPostingDetail.jsx`** (or create new)

```jsx
import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import api from '../../services/api';
import { toast } from 'react-hot-toast';
import { ExternalLink, Copy, CheckCircle } from 'lucide-react';

export default function JobPostingDetail() {
  const { id } = useParams();
  const [job, setJob] = useState(null);
  const [externalIds, setExternalIds] = useState({});
  const [showIdInput, setShowIdInput] = useState(null);

  useEffect(() => {
    fetchJob();
  }, [id]);

  const fetchJob = async () => {
    try {
      const res = await api.get(`/api/hr/job-postings/${id}`);
      setJob(res.data);
      setExternalIds(res.data.external_job_ids || {});
    } catch (err) {
      toast.error('Failed to load job');
    }
  };

  const generateTrulyTalentsUrl = () => {
    const baseUrl = 'https://trulytalents.com/employer/post-job';
    // Note: Adjust URL based on their actual post job page
    return baseUrl;
  };

  const handlePostExternal = (platform) => {
    const urls = {
      trulytalents: generateTrulyTalentsUrl(),
      linkedin: 'https://www.linkedin.com/jobs/post/',
      naukri: 'https://www.naukri.com/mnjuser/homepage',
    };

    // Copy job details to clipboard
    const jobText = `
Title: ${job.title}
Location: ${job.location}
Type: ${job.job_type}
Department: ${job.department}
${job.salary_from ? `Salary: ₹${job.salary_from} - ₹${job.salary_to}` : ''}

Description:
${job.description}

Requirements:
${job.requirements}

Apply by: ${job.closing_date || 'Open'}
    `.trim();

    navigator.clipboard.writeText(jobText);
    toast.success('Job details copied! Opening ' + platform);
    
    window.open(urls[platform], '_blank');
    setShowIdInput(platform);
  };

  const saveExternalId = async (platform, id) => {
    if (!id.trim()) return;

    try {
      const res = await api.patch(`/api/hr/job-postings/${job.id}/external-id`, {
        platform,
        external_id: id.trim(),
      });
      
      setExternalIds(res.data.external_ids);
      setShowIdInput(null);
      toast.success(`${platform} ID saved!`);
    } catch (err) {
      toast.error('Failed to save ID');
    }
  };

  if (!job) return <div>Loading...</div>;

  return (
    <div className="p-6 max-w-4xl mx-auto">
      {/* Job Details */}
      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h1 className="text-2xl font-bold mb-4">{job.title}</h1>
        <div className="grid grid-cols-2 gap-4 text-sm">
          <div><strong>Department:</strong> {job.department}</div>
          <div><strong>Location:</strong> {job.location}</div>
          <div><strong>Type:</strong> {job.job_type}</div>
          <div><strong>Status:</strong> {job.status}</div>
        </div>
      </div>

      {/* External Posting Section */}
      <div className="bg-white rounded-lg shadow p-6">
        <h2 className="text-xl font-bold mb-4">📤 Post to Job Boards</h2>
        
        <div className="space-y-4">
          {/* TrulyTalents */}
          <div className="border rounded-lg p-4">
            <div className="flex items-center justify-between mb-2">
              <h3 className="font-semibold">TrulyTalents.com</h3>
              {externalIds.trulytalents && (
                <span className="text-green-600 flex items-center gap-1">
                  <CheckCircle size={16} /> Posted
                </span>
              )}
            </div>
            
            {!externalIds.trulytalents ? (
              <div>
                <button
                  onClick={() => handlePostExternal('trulytalents')}
                  className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 flex items-center gap-2"
                >
                  <ExternalLink size={16} />
                  Post to TrulyTalents
                </button>
                <p className="text-sm text-gray-500 mt-2">
                  Job details will be copied. Paste them in TrulyTalents form.
                </p>
                
                {showIdInput === 'trulytalents' && (
                  <div className="mt-3">
                    <label className="text-sm font-medium">
                      Paste Job ID from TrulyTalents:
                    </label>
                    <input
                      type="text"
                      placeholder="e.g., TT-12345"
                      onBlur={(e) => saveExternalId('trulytalents', e.target.value)}
                      className="mt-1 w-full px-3 py-2 border rounded"
                      autoFocus
                    />
                  </div>
                )}
              </div>
            ) : (
              <div className="flex items-center gap-2 text-sm">
                <span className="font-mono bg-gray-100 px-2 py-1 rounded">
                  {externalIds.trulytalents}
                </span>
                <button
                  onClick={() => {
                    navigator.clipboard.writeText(externalIds.trulytalents);
                    toast.success('ID copied!');
                  }}
                  className="text-gray-600 hover:text-gray-900"
                >
                  <Copy size={14} />
                </button>
              </div>
            )}
          </div>

          {/* LinkedIn */}
          <div className="border rounded-lg p-4">
            <div className="flex items-center justify-between mb-2">
              <h3 className="font-semibold">LinkedIn</h3>
              {externalIds.linkedin && (
                <span className="text-green-600 flex items-center gap-1">
                  <CheckCircle size={16} /> Posted
                </span>
              )}
            </div>
            
            {!externalIds.linkedin ? (
              <div>
                <button
                  onClick={() => handlePostExternal('linkedin')}
                  className="px-4 py-2 bg-blue-700 text-white rounded hover:bg-blue-800 flex items-center gap-2"
                >
                  <ExternalLink size={16} />
                  Post to LinkedIn
                </button>
                
                {showIdInput === 'linkedin' && (
                  <div className="mt-3">
                    <label className="text-sm font-medium">
                      Paste Job ID from LinkedIn:
                    </label>
                    <input
                      type="text"
                      placeholder="e.g., 3456789012"
                      onBlur={(e) => saveExternalId('linkedin', e.target.value)}
                      className="mt-1 w-full px-3 py-2 border rounded"
                      autoFocus
                    />
                  </div>
                )}
              </div>
            ) : (
              <div className="flex items-center gap-2 text-sm">
                <span className="font-mono bg-gray-100 px-2 py-1 rounded">
                  {externalIds.linkedin}
                </span>
                <button
                  onClick={() => {
                    navigator.clipboard.writeText(externalIds.linkedin);
                    toast.success('ID copied!');
                  }}
                  className="text-gray-600 hover:text-gray-900"
                >
                  <Copy size={14} />
                </button>
              </div>
            )}
          </div>

          {/* Naukri */}
          <div className="border rounded-lg p-4">
            <div className="flex items-center justify-between mb-2">
              <h3 className="font-semibold">Naukri.com</h3>
              {externalIds.naukri && (
                <span className="text-green-600 flex items-center gap-1">
                  <CheckCircle size={16} /> Posted
                </span>
              )}
            </div>
            
            {!externalIds.naukri ? (
              <div>
                <button
                  onClick={() => handlePostExternal('naukri')}
                  className="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 flex items-center gap-2"
                >
                  <ExternalLink size={16} />
                  Post to Naukri
                </button>
                
                {showIdInput === 'naukri' && (
                  <div className="mt-3">
                    <label className="text-sm font-medium">
                      Paste Job ID from Naukri:
                    </label>
                    <input
                      type="text"
                      placeholder="e.g., NK-12345"
                      onBlur={(e) => saveExternalId('naukri', e.target.value)}
                      className="mt-1 w-full px-3 py-2 border rounded"
                      autoFocus
                    />
                  </div>
                )}
              </div>
            ) : (
              <div className="flex items-center gap-2 text-sm">
                <span className="font-mono bg-gray-100 px-2 py-1 rounded">
                  {externalIds.naukri}
                </span>
                <button
                  onClick={() => {
                    navigator.clipboard.writeText(externalIds.naukri);
                    toast.success('ID copied!');
                  }}
                  className="text-gray-600 hover:text-gray-900"
                >
                  <Copy size={14} />
                </button>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
```

---

## ✅ TESTING (10 mins)

1. **Start servers:**
```bash
# Backend
cd backend
php artisan serve

# Frontend
cd frontend
npm run dev
```

2. **Test flow:**
- Login as HR (hr@demo.com / password123)
- Go to Job Postings
- Open any job detail
- Click "Post to TrulyTalents"
- Job details copied to clipboard
- TrulyTalents website opens
- Manually post job there
- Copy job ID from TrulyTalents
- Paste in input field
- Verify saved in database

---

## 📊 TIMELINE SUMMARY

| Task | Time | Status |
|------|------|--------|
| **Contact TrulyTalents** | 15 mins | ⏰ DO TODAY |
| **Database migration** | 5 mins | Ready to run |
| **Backend route** | 10 mins | Copy-paste ready |
| **Frontend component** | 1.5 hours | Copy-paste ready |
| **Testing** | 10 mins | After implementation |
| **TOTAL** | **2 hours** | Can start now |
| **Wait for API response** | 3-7 days | - |

---

## 🎯 DECISION TREE

```
Contact TrulyTalents TODAY
         |
         ├─ Response in 3-7 days
         |  
         ├─ API Available?
         |  │
         |  ├─ YES → Full Integration (12-14 hours)
         |  │         ✅ Auto-sync
         |  │         ✅ Webhooks
         |  │         ✅ Two-way sync
         |  │
         |  └─ NO → Continue Manual Solution
         |            ✅ Quick post buttons
         |            ✅ External ID tracking
         |            ⚠️ Manual candidate entry
         |
         └─ Meanwhile: Implement Manual Solution (2 hours)
            Works immediately while waiting for API
```

---

## 📝 MARATHI QUICK SUMMARY

**आज करायचं (30 mins):**

### 1. Email पाठवा (15 mins) ✉️
```
TrulyTalents ला email करा API साठी
Template वर दिला आहे - copy-paste करा
```

### 2. Manual solution implement करा (2 hours) 💻
```
✅ Database field add करा (5 mins)
✅ Backend route add करा (10 mins)  
✅ Frontend button add करा (1.5 hours)
✅ Test करा (10 mins)

काम: Job detail page वर "Post to TrulyTalents" button येईल
Click केलं → Job details copy होतील → Website उघडेल
Manual post करा → Job ID save करा

Working: आताच! (2 hours मध्ये ready)
```

### 3. Wait करा API साठी (3-7 days) ⏰
```
API मिळाला → Full integration करा (12-14 hours)
API नाही मिळाला → Manual solution चालू ठेवा (works fine!)
```

**Start now? → Step 1 (Email) करा! ✅**

