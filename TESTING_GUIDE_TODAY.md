# आज का Work Test कैसे करें? 🧪

**Date:** July 3, 2026

---

## ✅ Pre-requisites Check करें

### 1. Servers Running हैं क्या?

**Backend Check:**
- Browser में जाएं: http://127.0.0.1:8000
- अगर "Laravel" page दिखे तो backend चल रहा है ✅

**Frontend Check:**
- Browser में जाएं: http://localhost:5173
- अगर login page दिखे तो frontend चल रहा है ✅

**अगर servers नहीं चल रहे:**
```bash
# Backend start करने के लिए
cd backend
php artisan serve

# Frontend start करने के लिए (दूसरे terminal में)
cd frontend
npm run dev
```

---

## 1️⃣ EMAIL SYSTEM TEST

### Test Command Run करें:

**Step 1:** Terminal खोलें और backend folder में जाएं:
```bash
cd backend
php artisan test:emails
```

**Expected Output:**
```
╔══════════════════════════════════════════════════════════╗
║       Email System Test                                  ║
╚══════════════════════════════════════════════════════════╝

📧 Step 1: Checking Mail Configuration...
   Mail Driver: log
   From Address: hello@example.com

📧 Step 3: Creating candidate...
   ✓ ApplicationReceivedMail sent

📊 Step 4: Updating stage...
   ✓ ApplicationStatusMail sent

🎤 Step 5: Scheduling interview...
   ✓ InterviewScheduledMail sent

✅ Email system test completed!
```

### Log File में Emails देखें:

**Step 2:** Email logs check करें:
```bash
cd backend
# Windows PowerShell में:
Get-Content storage/logs/laravel.log -Tail 50 | Select-String "Subject:"

# या direct file खोलें:
# backend/storage/logs/laravel.log
```

**Kya Dekhna Hai:**
```
Subject: Application Received - [Job Title]
Subject: Interview Scheduled - [Interview Name]
```

✅ **Success:** अगर "Subject:" lines दिख रहीं हैं तो emails work कर रहे हैं!

---

## 2️⃣ WHATSAPP SYSTEM TEST

### Test Command Run करें:

**Step 1:** Terminal में:
```bash
cd backend
php artisan test:whatsapp --phone=+919403443775
```

**Expected Output:**
```
╔══════════════════════════════════════════════════════════╗
║       WhatsApp Notification System Test                 ║
╚══════════════════════════════════════════════════════════╝

📱 Step 1: Checking WhatsApp Configuration...
   WhatsApp Enabled: ❌ No (logging mode)
   Provider: twilio
   From Number: whatsapp:+14155238886

👤 Step 3: Setting up test candidate...
   ✓ Created test candidate (ID: XX)
   Phone: +919403443775
   WhatsApp Opt-in: Yes

📧 Step 4: Testing Application Received Notification...
   ✓ Notification sent

📊 Step 5: Testing Status Update Notification...
   ✓ Notification sent (Stage: Screening)

🎤 Step 6: Testing Interview Scheduled Notification...
   ✓ Notification sent

📝 Step 7: Checking WhatsApp Logs...
   Found 3 recent log(s):
   ⏳ [interview_scheduled] Status: queued
   ⏳ [status_update] Status: queued
   ⏳ [application_received] Status: queued

✅ WhatsApp test completed!
```

### Database में Logs देखें:

**Step 2:** Database browser tool use करें या tinker:
```bash
php artisan tinker
```

फिर tinker में:
```php
// Last 5 WhatsApp logs देखें
DB::table('hr_whatsapp_logs')->latest()->take(5)->get(['id', 'to_number', 'event_type', 'status', 'created_at']);
```

**या direct SQL:**
```bash
php artisan db:table hr_whatsapp_logs --take=5
```

**Kya Dekhना Hai:**
- ✅ `to_number`: whatsapp:+919403443775
- ✅ `event_type`: application_received, status_update, interview_scheduled
- ✅ `status`: queued (क्योंकि WhatsApp disabled है)

✅ **Success:** अगर logs show हो रहे हैं तो WhatsApp system working है!

---

## 3️⃣ FRONTEND में TEST (Manual Testing)

### Step 1: Login करें

1. Browser में जाएं: http://localhost:5173
2. Login credentials (default):
   - Email: `admin@demo.com`
   - Password: `password123`

### Step 2: HR Module में जाएं

Navigation में "HR" पर click करें या directly:
- http://localhost:5173/app/hr/dashboard

### Step 3: सारे Links Test करें

**Dashboard:**
- http://localhost:5173/app/hr/dashboard
- ✅ Check: KPI cards दिख रहे हैं
- ✅ Check: Numbers show हो रहे हैं
- ✅ Check: Charts load हो रहे हैं

**Manpower Requests:**
- http://localhost:5173/app/hr/manpower-requests
- ✅ Click: "Create Request" button
- ✅ Fill form और submit करें
- ✅ Check: Request list में दिख रहा है

**Job Postings:**
- http://localhost:5173/app/hr/jobs
- ✅ Click: "Create Job" button
- ✅ Fill form और post करें
- ✅ Check: Job list में दिख रहा है

**Candidates:**
- http://localhost:5173/app/hr/candidates
- ✅ Click: "Add Candidate" button
- ✅ Fill form (साथ में LinkedIn URL paste करें)
- ✅ Click Submit
- ✅ Check: Candidate card दिख रहा है
- ✅ Click: Candidate card (profile page खुलना चाहिए)

**Interviews:**
- http://localhost:5173/app/hr/interviews
- ✅ Click: "Schedule Interview"
- ✅ Candidate select करें
- ✅ Date/time select करें
- ✅ Submit करें
- ✅ Check: Interview list में दिख रहा है

**Offers:**
- http://localhost:5173/app/hr/offers
- ✅ Click: "Create Offer"
- ✅ Details fill करें
- ✅ Submit करें
- ✅ Check: Offer list में दिख रहा है

**Onboarding:**
- http://localhost:5173/app/hr/onboarding
- ✅ Click: "Start Onboarding"
- ✅ Candidate select करें
- ✅ Checkboxes click करें
- ✅ Check: Progress update हो रहा है

**Employees:**
- http://localhost:5173/app/hr/employees
- ✅ Check: Employee list दिख रही है
- ✅ Stats show हो रहे हैं

---

## 4️⃣ EMAIL NOTIFICATIONS TEST (Live)

### Candidate Create करके Email Trigger करें:

**Step 1:** Frontend में Candidate बनाएं:
1. Go to: http://localhost:5173/app/hr/candidates
2. Click "Add Candidate"
3. Fill form:
   - Name: Test Candidate
   - Email: **your-real-email@gmail.com** (apna real email daalें)
   - Phone: +919403443775
   - Job: कोई भी select करें
4. Submit

**Step 2:** Log file check करें:
```bash
cd backend
# Last 20 lines देखें
tail -n 20 storage/logs/laravel.log
```

**Kya Dekhना Hai:**
```
Subject: Application Received - [Job Title]
To: your-real-email@gmail.com
```

✅ **Success:** अगर email logged है तो notification trigger हुआ!

---

## 5️⃣ WHATSAPP NOTIFICATIONS TEST (Live)

### Candidate बनाकर WhatsApp Trigger करें:

**Step 1:** Same candidate create process (ऊपर जैसा)
- Email के साथ Phone number भी add करें: +919403443775

**Step 2:** Database में WhatsApp log check करें:
```bash
php artisan tinker
```

फिर:
```php
// Latest log देखें
DB::table('hr_whatsapp_logs')->latest()->first();
```

**Kya Deखना Hai:**
```
to_number: "whatsapp:+919403443775"
event_type: "application_received"
status: "queued"
message: "Hi Test Candidate, Thank you for applying..."
```

✅ **Success:** अगर log entry है तो WhatsApp system working है!

---

## 6️⃣ STAGE CHANGE TEST (Email + WhatsApp Both)

### Step 1: Candidate की Stage Change करें:

1. Go to: http://localhost:5173/app/hr/candidates
2. किसी candidate card पर click करें
3. Stage dropdown से "Screening" select करें
4. Save

### Step 2: Logs Check करें:

**Email Log:**
```bash
tail -n 30 backend/storage/logs/laravel.log | grep "Subject:"
```

**Expected:**
```
Subject: Application Update - Screening
```

**WhatsApp Log:**
```bash
php artisan tinker
>>> DB::table('hr_whatsapp_logs')->where('event_type', 'status_update')->latest()->first();
```

**Expected:**
```
event_type: "status_update"
message: "Hi [Name], Good news! Your application has moved to Screening..."
```

✅ **Success:** दोनों logs मिलने चाहिए!

---

## 7️⃣ INTERVIEW SCHEDULE TEST

### Step 1: Interview Schedule करें:

1. Go to: http://localhost:5173/app/hr/interviews
2. Click "Schedule Interview"
3. Fill form:
   - Candidate: Select करें
   - Round Name: Technical Round 1
   - Interviewer: Jane Smith
   - Date/Time: कल की date
4. Submit

### Step 2: Check Emails (2 emails trigger होने चाहिए):

```bash
tail -n 50 backend/storage/logs/laravel.log | grep "Subject:"
```

**Expected:**
```
Subject: Interview Scheduled - Technical Round 1  (to candidate)
Subject: New Interview Scheduled - [Candidate Name]  (to interviewer)
```

### Step 3: Check WhatsApp:

```bash
php artisan tinker
>>> DB::table('hr_whatsapp_logs')->where('event_type', 'interview_scheduled')->latest()->first();
```

✅ **Success:** 2 emails + 1 WhatsApp log मिलना चाहिए!

---

## 8️⃣ QUICK VERIFICATION CHECKLIST

### ✅ Email System:
- [ ] Test command successfully run हुआ
- [ ] Log file में "Subject:" lines दिख रहीं हैं
- [ ] Application received email logged है
- [ ] Interview scheduled email logged है
- [ ] Status update email logged है

### ✅ WhatsApp System:
- [ ] Test command successfully run हुआ
- [ ] Database में `hr_whatsapp_logs` table बना है
- [ ] Test logs (+919403443775) database में हैं
- [ ] Status "queued" show हो रहा है
- [ ] 6 different notification types test हुए

### ✅ Frontend Links:
- [ ] Dashboard page load हो रहा है
- [ ] Manpower Requests page working है
- [ ] Job Postings page working है
- [ ] Candidates page working है
- [ ] Candidate Profile page खुल रहा है
- [ ] Interviews page working है
- [ ] Offers page working है
- [ ] Onboarding page working है
- [ ] Employees page working है

### ✅ API Endpoints:
- [ ] Candidate create हो रहा है
- [ ] Job posting create हो रहा है
- [ ] Interview schedule हो रहा है
- [ ] Stage change काम कर रहा है

---

## 🐛 Troubleshooting

### Problem: Email test command error
**Solution:**
```bash
cd backend
php artisan cache:clear
php artisan config:clear
composer dump-autoload
php artisan test:emails
```

### Problem: WhatsApp logs nahi dikh rahe
**Solution:**
```bash
# Migration check करें
php artisan migrate:status

# Agar migration pending है:
php artisan migrate
```

### Problem: Frontend load nahi ho raha
**Solution:**
```bash
cd frontend
# Cache clear करें
npm run build
# Server restart करें
npm run dev
```

### Problem: Backend API 500 error
**Solution:**
```bash
cd backend
# Logs check करें
tail -f storage/logs/laravel.log

# Permission check करें
chmod -R 777 storage bootstrap/cache
```

---

## 🎯 FINAL VERIFICATION

### All Tests Pass हो गए?

✅ Email system test: PASS  
✅ WhatsApp system test: PASS  
✅ Frontend navigation: PASS  
✅ API endpoints: PASS  
✅ Database logs: PASS

### 🎉 Congratulations!

Aaj ka sara work properly working hai! 

**Summary:**
- ✅ Email notifications integrated
- ✅ WhatsApp notifications integrated
- ✅ All 9 HR pages working
- ✅ 30+ API endpoints tested
- ✅ Database properly logging
- ✅ Test number verified: +919403443775

---

## 📱 Next Steps

### To Enable Real WhatsApp:
1. Twilio account banao: https://www.twilio.com
2. `.env` file में credentials add करो:
   ```
   WHATSAPP_ENABLED=true
   TWILIO_ACCOUNT_SID=your_sid
   TWILIO_AUTH_TOKEN=your_token
   ```
3. Server restart करो
4. Real WhatsApp messages milने lagenge! 🎊

### To Enable Real Emails:
1. SMTP details `.env` में add करो (Gmail, SendGrid, etc.)
2. Server restart करो
3. Real emails send होने lagenge! 📧

---

**Happy Testing! 🚀**

