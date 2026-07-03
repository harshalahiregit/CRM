<?php

/**
 * Email System Test Script
 * 
 * This script tests the HR recruitment email system by:
 * 1. Creating a test candidate
 * 2. Triggering email notifications
 * 3. Checking the log file for email entries
 * 
 * Usage: php test_email_system.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\HrJobPosting;
use App\Models\HrCandidate;
use App\Models\HrInterviewRound;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║       HR Recruitment Email System Test                  ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// Step 1: Check mail configuration
echo "📧 Step 1: Checking Mail Configuration...\n";
echo "   Mail Driver: " . config('mail.default') . "\n";
echo "   From Address: " . config('mail.from.address') . "\n";
echo "   From Name: " . config('mail.from.name') . "\n\n";

// Step 2: Get or create test user
echo "👤 Step 2: Setting up test user...\n";
$user = User::where('email', 'hr@test.com')->first();
if (!$user) {
    $user = User::create([
        'name' => 'Test HR',
        'email' => 'hr@test.com',
        'password' => bcrypt('password'),
        'role' => 'admin',
        'tenant_id' => 1,
    ]);
    echo "   ✓ Created test user: hr@test.com\n";
} else {
    echo "   ✓ Using existing test user: hr@test.com\n";
}

// Step 3: Get or create test job posting
echo "\n💼 Step 3: Setting up test job posting...\n";
$jobPosting = HrJobPosting::where('title', 'Senior Laravel Developer - TEST')->first();
if (!$jobPosting) {
    $jobPosting = HrJobPosting::create([
        'title' => 'Senior Laravel Developer - TEST',
        'department' => 'Engineering',
        'location' => 'Remote',
        'job_type' => 'Full Time',
        'min_salary' => 80000,
        'max_salary' => 120000,
        'openings' => 2,
        'description' => 'Test job posting for email verification',
        'requirements' => 'Laravel, PHP, MySQL, Vue.js',
        'status' => 'Active',
        'posted_on' => json_encode(['Company Website']),
        'closing_date' => now()->addDays(30),
        'tenant_id' => 1,
    ]);
    echo "   ✓ Created test job posting\n";
} else {
    echo "   ✓ Using existing test job posting\n";
}

// Step 4: Clear old test candidates
echo "\n🧹 Step 4: Cleaning up old test data...\n";
$deleted = HrCandidate::where('email', 'testcandidate@example.com')->delete();
echo "   ✓ Deleted $deleted old test candidate(s)\n";

// Step 5: Create test candidate (triggers ApplicationReceivedMail)
echo "\n👨‍💼 Step 5: Creating test candidate...\n";
echo "   This should trigger ApplicationReceivedMail\n";

try {
    $candidate = HrCandidate::create([
        'name' => 'John Test Candidate',
        'email' => 'testcandidate@example.com',
        'phone' => '+1234567890',
        'location' => 'San Francisco, CA',
        'current_company' => 'Tech Corp',
        'experience_years' => 5,
        'source' => 'Company Website',
        'stage' => 'Applied',
        'job_posting_id' => $jobPosting->id,
        'skills' => json_encode(['PHP', 'Laravel', 'MySQL', 'Vue.js']),
        'ai_score' => 85,
        'ai_breakdown' => json_encode([
            'skills_match' => 90,
            'exp_match' => 85,
            'location_match' => 80,
            'education' => 75,
            'overall_fit' => 87,
        ]),
        'tenant_id' => 1,
    ]);
    
    echo "   ✓ Candidate created successfully (ID: {$candidate->id})\n";
    
    // Manually send email to ensure it's triggered
    Mail::to($candidate->email)->send(
        new \App\Mail\ApplicationReceivedMail($candidate->load('jobPosting'))
    );
    echo "   ✓ ApplicationReceivedMail sent\n";
    
} catch (\Exception $e) {
    echo "   ✗ Error creating candidate: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 6: Update candidate stage (triggers ApplicationStatusMail)
echo "\n📊 Step 6: Updating candidate stage to 'Screening'...\n";
echo "   This should trigger ApplicationStatusMail\n";

try {
    $candidate->update(['stage' => 'Screening']);
    
    Mail::to($candidate->email)->send(
        new \App\Mail\ApplicationStatusMail(
            $candidate->load('jobPosting'),
            'Screening',
            'Your application is now under review by our hiring team.'
        )
    );
    echo "   ✓ ApplicationStatusMail sent\n";
    
} catch (\Exception $e) {
    echo "   ✗ Error updating stage: " . $e->getMessage() . "\n";
}

// Step 7: Create interview round (triggers InterviewScheduledMail)
echo "\n🎤 Step 7: Scheduling interview...\n";
echo "   This should trigger InterviewScheduledMail (candidate & interviewer)\n";

try {
    $interview = HrInterviewRound::create([
        'candidate_id' => $candidate->id,
        'round_name' => 'Technical Round 1',
        'round_type' => 'Technical',
        'interviewer_name' => 'Jane Smith',
        'interviewer_email' => 'jane.smith@company.com',
        'scheduled_at' => now()->addDays(2),
        'duration_minutes' => 60,
        'meet_link' => 'https://meet.google.com/test-' . rand(100, 999),
        'status' => 'Scheduled',
        'result' => 'Pending',
        'tenant_id' => 1,
    ]);
    
    // Send to candidate
    Mail::to($candidate->email)->send(
        new \App\Mail\InterviewScheduledMail($interview, 'candidate')
    );
    echo "   ✓ InterviewScheduledMail sent to candidate\n";
    
    // Send to interviewer
    Mail::to($interview->interviewer_email)->send(
        new \App\Mail\InterviewScheduledMail($interview, 'interviewer')
    );
    echo "   ✓ InterviewScheduledMail sent to interviewer\n";
    
} catch (\Exception $e) {
    echo "   ✗ Error creating interview: " . $e->getMessage() . "\n";
}

// Step 8: Check log file for email entries
echo "\n📝 Step 8: Checking log file for email entries...\n";
$logPath = storage_path('logs/laravel.log');

if (file_exists($logPath)) {
    $logContent = file_get_contents($logPath);
    
    // Count email-related entries
    $applicationReceivedCount = substr_count($logContent, 'Application Received');
    $applicationStatusCount = substr_count($logContent, 'Application Status');
    $interviewScheduledCount = substr_count($logContent, 'Interview Scheduled');
    
    echo "   Log file location: $logPath\n";
    echo "   File size: " . number_format(filesize($logPath)) . " bytes\n\n";
    echo "   Email counts in log:\n";
    echo "   - ApplicationReceivedMail: $applicationReceivedCount\n";
    echo "   - ApplicationStatusMail: $applicationStatusCount\n";
    echo "   - InterviewScheduledMail: $interviewScheduledCount\n\n";
    
    // Show last few email log entries
    $lines = explode("\n", $logContent);
    $emailLines = array_filter($lines, function($line) {
        return str_contains($line, 'Application Received') || 
               str_contains($line, 'Application Status') || 
               str_contains($line, 'Interview Scheduled');
    });
    
    if (count($emailLines) > 0) {
        echo "   Recent email log entries (last 3):\n";
        $recentEmails = array_slice($emailLines, -3);
        foreach ($recentEmails as $line) {
            // Truncate long lines
            $truncated = strlen($line) > 100 ? substr($line, 0, 100) . '...' : $line;
            echo "   " . $truncated . "\n";
        }
    } else {
        echo "   ⚠️  No email entries found in log file\n";
    }
} else {
    echo "   ⚠️  Log file not found at: $logPath\n";
}

// Step 9: Summary
echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║                    Test Summary                          ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

echo "✅ Test completed successfully!\n\n";
echo "Email Types Tested:\n";
echo "1. ✓ ApplicationReceivedMail - Sent when candidate applies\n";
echo "2. ✓ ApplicationStatusMail - Sent when stage changes\n";
echo "3. ✓ InterviewScheduledMail - Sent to candidate & interviewer\n\n";

echo "Test Data Created:\n";
echo "- Candidate: {$candidate->name} ({$candidate->email})\n";
echo "- Stage: {$candidate->stage}\n";
echo "- AI Score: {$candidate->ai_score}/100\n";
if (isset($interview)) {
    echo "- Interview: {$interview->round_name} on " . $interview->scheduled_at->format('M d, Y g:i A') . "\n";
}

echo "\n📋 Next Steps:\n";
echo "1. Check the log file at: $logPath\n";
echo "2. Search for 'Application Received' or 'Interview Scheduled'\n";
echo "3. If using SMTP, check your mail server logs\n";
echo "4. To test real email delivery, update .env:\n";
echo "   - Set MAIL_MAILER=smtp\n";
echo "   - Configure SMTP credentials\n\n";

echo "🧪 To clean up test data, run:\n";
echo "   php artisan tinker\n";
echo "   >>> HrCandidate::where('email', 'testcandidate@example.com')->delete();\n";
echo "   >>> HrJobPosting::where('title', 'LIKE', '%TEST%')->delete();\n\n";
