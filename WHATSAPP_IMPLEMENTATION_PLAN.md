# WhatsApp Notifications Implementation Plan

**Feature:** WhatsApp Notifications for HR Recruitment  
**Timeline:** 2-3 days  
**Priority:** High (Next after email system verification)  
**Status:** 📋 Ready to Start

---

## 🎯 Objective

Enable WhatsApp notifications to send interview reminders and status updates to candidates via WhatsApp, complementing the existing email system.

---

## 📊 Scope

### In Scope ✅
1. WhatsApp notifications for key recruitment events
2. Integration with existing email triggers
3. Candidate opt-in/opt-out functionality
4. Message templates for common scenarios
5. Queue-based sending for reliability
6. Status tracking (sent, delivered, failed)

### Out of Scope ❌
1. Two-way WhatsApp conversations
2. WhatsApp chatbot functionality
3. Rich media (images, PDFs) in messages
4. WhatsApp group notifications
5. Interactive WhatsApp buttons/forms

---

## 🔔 Notification Types to Implement

### Priority 1: Critical Notifications (Day 1)

#### 1. Interview Scheduled
**Trigger:** When an interview is scheduled  
**Recipient:** Candidate  
**Message:**
```
Hi [Name],

Your interview for [Job Title] has been scheduled!

📅 Date: [Date]
⏰ Time: [Time]
⏱️ Duration: [Duration] minutes
🎤 Round: [Round Name]
👤 Interviewer: [Interviewer Name]

📞 Meeting Link: [Google Meet URL]

Please join on time. Good luck!

- [Company Name] HR Team
```

#### 2. Interview Reminder (24h before)
**Trigger:** Automated 24 hours before scheduled interview  
**Recipient:** Candidate  
**Message:**
```
Hi [Name],

Reminder: Your interview for [Job Title] is tomorrow!

📅 Date: [Date]
⏰ Time: [Time]
📞 Meeting Link: [Link]

See you soon!

- [Company Name] HR Team
```

### Priority 2: Status Updates (Day 2)

#### 3. Application Received
**Trigger:** When candidate applies  
**Recipient:** Candidate  
**Message:**
```
Hi [Name],

Thank you for applying to [Job Title] at [Company Name]!

We have received your application and our team will review it shortly.

Application ID: #[ID]

We'll keep you updated!

- [Company Name] HR Team
```

#### 4. Stage Update
**Trigger:** When candidate stage changes  
**Recipient:** Candidate  
**Messages by Stage:**

**Screening:**
```
Hi [Name],

Good news! Your application for [Job Title] has moved to the screening stage.

Our team is reviewing your profile in detail.

- [Company Name] HR Team
```

**Selected:**
```
Hi [Name],

Congratulations! 🎉

You have been selected for [Job Title] at [Company Name]!

Our HR team will contact you shortly with next steps.

- [Company Name] HR Team
```

**Rejected:**
```
Hi [Name],

Thank you for your interest in [Job Title] at [Company Name].

After careful consideration, we have decided to move forward with other candidates.

We encourage you to apply for future openings.

Best wishes!

- [Company Name] HR Team
```

### Priority 3: Additional Notifications (Day 3)

#### 5. Offer Released
**Trigger:** When offer is sent  
**Recipient:** Candidate  
**Message:**
```
Hi [Name],

Great news! We're pleased to extend an offer for [Job Title]!

You will receive the offer letter via email shortly.

Please review and respond by [Validity Date].

Welcome to the team!

- [Company Name] HR Team
```

#### 6. Onboarding Started
**Trigger:** When onboarding process begins  
**Recipient:** New hire  
**Message:**
```
Hi [Name],

Welcome to [Company Name]! 🎉

Your onboarding process has started.

Joining Date: [Date]
Employee ID: [ID]

Please check your email for detailed instructions.

- [Company Name] HR Team
```

---

## 🏗️ Technical Architecture

### WhatsApp API Options

#### Option 1: Twilio (Recommended)
**Pros:**
- Easy setup and integration
- Official WhatsApp Business API
- Good documentation
- Laravel package available
- Pay-as-you-go pricing

**Cons:**
- Costs per message (~$0.005 - $0.10 depending on country)
- Requires Facebook Business Manager verification

**Setup:**
```bash
composer require twilio/sdk
```

#### Option 2: WhatsApp Business Platform
**Pros:**
- Official Meta/Facebook solution
- More features
- Better for high volume

**Cons:**
- More complex setup
- Requires business verification
- Longer approval process

#### Option 3: Third-party Services (Gupshup, MessageBird, etc.)
**Pros:**
- Regional availability
- Competitive pricing

**Cons:**
- Platform-specific implementation

**Recommendation:** Start with Twilio for speed and reliability

---

## 📋 Implementation Steps

### Day 1: Setup & Core Infrastructure (6-8 hours)

#### 1.1 Install Twilio SDK
```bash
cd backend
composer require twilio/sdk
```

#### 1.2 Update Environment Configuration
Add to `.env`:
```env
WHATSAPP_ENABLED=true
WHATSAPP_PROVIDER=twilio
TWILIO_ACCOUNT_SID=your_account_sid
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_WHATSAPP_FROM=whatsapp:+14155238886  # Twilio sandbox number
```

#### 1.3 Create Configuration File
Create `backend/config/whatsapp.php`:
```php
<?php

return [
    'enabled' => env('WHATSAPP_ENABLED', false),
    'provider' => env('WHATSAPP_PROVIDER', 'twilio'),
    
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886'),
    ],
    
    'queue' => env('WHATSAPP_QUEUE', 'default'),
    'retry' => env('WHATSAPP_RETRY_TIMES', 3),
];
```

#### 1.4 Update Database Schema
Create migration: `add_whatsapp_fields_to_hr_candidates`
```php
Schema::table('hr_candidates', function (Blueprint $table) {
    $table->boolean('whatsapp_opt_in')->default(true);
    $table->string('whatsapp_number')->nullable(); // Use phone field if null
});
```

Create table: `hr_whatsapp_logs`
```php
Schema::create('hr_whatsapp_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('candidate_id')->nullable()->constrained('hr_candidates');
    $table->string('to_number');
    $table->string('message_sid')->nullable();
    $table->string('event_type'); // interview_scheduled, status_update, etc.
    $table->text('message');
    $table->enum('status', ['queued', 'sent', 'delivered', 'failed', 'undelivered']);
    $table->text('error_message')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('delivered_at')->nullable();
    $table->timestamps();
});
```

#### 1.5 Create WhatsApp Service
Create `backend/app/Services/WhatsAppService.php`:
```php
<?php

namespace App\Services;

use Twilio\Rest\Client;
use App\Models\HrWhatsAppLog;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected $client;
    protected $from;
    
    public function __construct()
    {
        if (config('whatsapp.enabled') && config('whatsapp.provider') === 'twilio') {
            $this->client = new Client(
                config('whatsapp.twilio.account_sid'),
                config('whatsapp.twilio.auth_token')
            );
            $this->from = config('whatsapp.twilio.from');
        }
    }
    
    public function send($to, $message, $eventType, $candidateId = null)
    {
        if (!config('whatsapp.enabled')) {
            Log::info('WhatsApp disabled. Message not sent.', [
                'to' => $to,
                'event_type' => $eventType,
            ]);
            return null;
        }
        
        // Format phone number for WhatsApp
        $to = $this->formatPhoneNumber($to);
        
        // Create log entry
        $log = HrWhatsAppLog::create([
            'candidate_id' => $candidateId,
            'to_number' => $to,
            'event_type' => $eventType,
            'message' => $message,
            'status' => 'queued',
        ]);
        
        try {
            $result = $this->client->messages->create(
                $to,
                [
                    'from' => $this->from,
                    'body' => $message,
                ]
            );
            
            $log->update([
                'message_sid' => $result->sid,
                'status' => 'sent',
                'sent_at' => now(),
            ]);
            
            Log::info('WhatsApp message sent', [
                'sid' => $result->sid,
                'to' => $to,
                'event_type' => $eventType,
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            
            Log::error('WhatsApp send failed', [
                'to' => $to,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
    
    protected function formatPhoneNumber($phone)
    {
        // Remove spaces, dashes, parentheses
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
        
        // Add whatsapp: prefix if not present
        if (!str_starts_with($phone, 'whatsapp:')) {
            // Add + if not present
            if (!str_starts_with($phone, '+')) {
                $phone = '+' . $phone;
            }
            $phone = 'whatsapp:' . $phone;
        }
        
        return $phone;
    }
    
    public function updateStatus($messageSid, $status)
    {
        $log = HrWhatsAppLog::where('message_sid', $messageSid)->first();
        
        if ($log) {
            $log->update(['status' => $status]);
            
            if ($status === 'delivered') {
                $log->update(['delivered_at' => now()]);
            }
        }
    }
}
```

#### 1.6 Create Notification Classes
Create `backend/app/Notifications/WhatsApp/InterviewScheduledNotification.php`:
```php
<?php

namespace App\Notifications\WhatsApp;

use App\Models\HrInterviewRound;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Queue;

class InterviewScheduledNotification
{
    public static function send(HrInterviewRound $interview)
    {
        $candidate = $interview->candidate;
        
        // Check if WhatsApp is enabled and candidate opted in
        if (!config('whatsapp.enabled') || 
            !$candidate->whatsapp_opt_in || 
            !$candidate->phone) {
            return;
        }
        
        $message = self::buildMessage($interview, $candidate);
        
        // Queue the WhatsApp send
        Queue::push(function ($job) use ($candidate, $message, $interview) {
            $whatsapp = new WhatsAppService();
            $whatsapp->send(
                $candidate->phone,
                $message,
                'interview_scheduled',
                $candidate->id
            );
            $job->delete();
        });
    }
    
    protected static function buildMessage($interview, $candidate)
    {
        $jobTitle = $candidate->jobPosting->title ?? 'a position';
        $date = $interview->scheduled_at->format('M d, Y');
        $time = $interview->scheduled_at->format('g:i A');
        
        return "Hi {$candidate->name},\n\n" .
               "Your interview for {$jobTitle} has been scheduled!\n\n" .
               "📅 Date: {$date}\n" .
               "⏰ Time: {$time}\n" .
               "⏱️ Duration: {$interview->duration_minutes} minutes\n" .
               "🎤 Round: {$interview->round_name}\n" .
               "👤 Interviewer: {$interview->interviewer_name}\n\n" .
               "📞 Meeting Link: {$interview->meet_link}\n\n" .
               "Please join on time. Good luck!\n\n" .
               "- " . config('app.name') . " HR Team";
    }
}
```

#### 1.7 Integrate with Existing Controllers
Update `InterviewController@store` to send WhatsApp:
```php
use App\Notifications\WhatsApp\InterviewScheduledNotification;

// After creating interview and sending emails
if ($interview) {
    // Send WhatsApp notification
    InterviewScheduledNotification::send($interview);
}
```

---

### Day 2: Message Templates & Status Updates (6-8 hours)

#### 2.1 Create All Notification Classes
- `ApplicationReceivedNotification.php`
- `StatusUpdateNotification.php`
- `OfferReleasedNotification.php`
- `OnboardingStartedNotification.php`
- `InterviewReminderNotification.php` (with scheduler)

#### 2.2 Create Message Template Service
Create `backend/app/Services/WhatsAppTemplateService.php` to centralize message formatting

#### 2.3 Update All Controllers
Integrate WhatsApp notifications into:
- `CandidateController` (application received, status updates)
- `InterviewController` (interview scheduled)
- `OfferController` (offer released)
- `OnboardingController` (onboarding started)

#### 2.4 Create Artisan Command for Testing
```bash
php artisan make:command TestWhatsApp
```

---

### Day 3: Reminders, Admin UI & Polish (6-8 hours)

#### 3.1 Implement Interview Reminder Scheduler
Create scheduled job to send reminders 24h before interviews:
```php
// In app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('whatsapp:send-interview-reminders')
             ->hourly();
}
```

#### 3.2 Create WhatsApp Status Dashboard
Add to `HRDashboardController`:
- Total WhatsApp messages sent
- Delivery rate
- Failed messages count
- Recent WhatsApp activity

#### 3.3 Add Admin Controls (Frontend)
In candidate profile:
- Toggle WhatsApp opt-in
- View WhatsApp message history
- Manually trigger WhatsApp notifications

#### 3.4 Webhook for Status Updates
Create webhook endpoint to receive delivery status from Twilio:
```php
Route::post('/webhooks/whatsapp/status', [WhatsAppWebhookController::class, 'status']);
```

#### 3.5 Testing & Documentation
- Test all notification types
- Document setup process
- Create admin guide

---

## 🧪 Testing Checklist

### Unit Tests
- [ ] WhatsAppService sends messages correctly
- [ ] Phone number formatting works
- [ ] Message templates generate correctly
- [ ] Opt-in/opt-out logic works

### Integration Tests
- [ ] Candidate applies → WhatsApp sent
- [ ] Interview scheduled → WhatsApp sent
- [ ] Stage updated → WhatsApp sent
- [ ] Queue processes messages
- [ ] Failed messages logged correctly

### Manual Tests (with Twilio Sandbox)
- [ ] Send test message to your phone
- [ ] Verify message formatting
- [ ] Check delivery status
- [ ] Test with international numbers
- [ ] Verify opt-out works

---

## 📊 Database Models

### HrWhatsAppLog Model
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrWhatsAppLog extends Model
{
    protected $fillable = [
        'candidate_id',
        'to_number',
        'message_sid',
        'event_type',
        'message',
        'status',
        'error_message',
        'sent_at',
        'delivered_at',
    ];
    
    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];
    
    public function candidate()
    {
        return $this->belongsTo(HrCandidate::class);
    }
}
```

---

## 💰 Cost Estimation

### Twilio WhatsApp Pricing (Approximate)
- India: ~$0.01 per message
- US: ~$0.005 per message
- Other countries: $0.005 - $0.10 per message

### Example Monthly Cost
**Assumptions:**
- 1000 candidates per month
- Average 3 messages per candidate
- Cost per message: $0.01

**Total:** 3000 messages × $0.01 = **$30/month**

---

## 🚀 Deployment Checklist

### Development Setup
- [ ] Install Twilio SDK
- [ ] Set up Twilio sandbox number
- [ ] Add WhatsApp opt-in to join sandbox
- [ ] Configure `.env` with test credentials
- [ ] Run migrations
- [ ] Test with your phone number

### Production Setup
- [ ] Get Twilio production account
- [ ] Request WhatsApp Business API access
- [ ] Complete Facebook Business verification
- [ ] Submit message templates for approval
- [ ] Configure production `.env`
- [ ] Set up webhook URL for status updates
- [ ] Monitor delivery rates

---

## 📱 Twilio Sandbox Setup (For Testing)

1. **Sign up for Twilio:**
   - Go to https://www.twilio.com/try-twilio
   - Sign up for free account ($15 credit)

2. **Access WhatsApp Sandbox:**
   - Console → Messaging → Try it out → Send a WhatsApp message
   - Get sandbox number: `+1 415 523 8886`

3. **Join Sandbox:**
   - Send `join [code]` to the sandbox number from your WhatsApp
   - Example: `join singing-guitar`

4. **Get Credentials:**
   - Account SID: In console dashboard
   - Auth Token: Click "Show" in dashboard
   - From Number: `whatsapp:+14155238886`

5. **Add to `.env`:**
   ```env
   WHATSAPP_ENABLED=true
   TWILIO_ACCOUNT_SID=AC...
   TWILIO_AUTH_TOKEN=...
   TWILIO_WHATSAPP_FROM=whatsapp:+14155238886
   ```

---

## 🎯 Success Metrics

### Technical Metrics
- ✅ Message delivery rate > 95%
- ✅ Average delivery time < 5 seconds
- ✅ Failed message rate < 5%
- ✅ Queue processing time < 1 minute

### User Experience Metrics
- ✅ Candidate satisfaction with notifications
- ✅ Interview show-up rate improvement
- ✅ Reduced "when is my interview?" support queries
- ✅ Faster response times from candidates

---

## 🔧 Troubleshooting Guide

### Common Issues

#### Issue 1: Messages not sending
**Symptoms:** WhatsApp messages stuck in "queued" status

**Solutions:**
- Check `.env` configuration
- Verify Twilio credentials
- Check queue is running: `php artisan queue:work`
- Check logs: `storage/logs/laravel.log`

#### Issue 2: Phone number format errors
**Symptoms:** "Invalid 'To' Phone Number" error

**Solutions:**
- Ensure phone has country code
- Format: `+[country code][number]`
- Example: `+911234567890` (India)
- Use `formatPhoneNumber()` method

#### Issue 3: Sandbox not receiving messages
**Symptoms:** Messages sent but not received

**Solutions:**
- Verify you joined sandbox: Send `join [code]`
- Check sandbox is active in Twilio console
- Try with different phone number
- Check WhatsApp is installed and working

---

## 📚 Documentation to Create

1. **Admin Guide:** How to manage WhatsApp notifications
2. **Developer Guide:** How to add new notification types
3. **Setup Guide:** Twilio account setup step-by-step
4. **Message Templates:** All message formats
5. **API Reference:** WhatsAppService methods

---

## 🎉 Expected Outcomes

After 2-3 days of implementation:

✅ **Candidates will receive:**
- Instant application confirmation
- Interview schedules via WhatsApp
- Timely reminders before interviews
- Status updates in real-time
- Offer notifications

✅ **HR Team will have:**
- Better candidate engagement
- Reduced no-shows for interviews
- Lower support ticket volume
- Delivery tracking and analytics
- Professional communication channel

✅ **System will have:**
- Reliable queue-based sending
- Comprehensive logging
- Error handling and retries
- Easy to add new notification types
- Scalable architecture

---

## 🔜 Next Steps After WhatsApp

Once WhatsApp notifications are complete:
1. ✅ Test end-to-end with real candidates
2. ✅ Gather feedback from HR team
3. ✅ Move to **TrulyTalents Webhook Integration** (5 days)

---

*Plan created: July 3, 2026*  
*Ready to start: YES*  
*Estimated completion: July 5-6, 2026*

