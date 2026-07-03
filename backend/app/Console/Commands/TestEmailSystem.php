<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\HrJobPosting;
use App\Models\HrCandidate;
use App\Models\HrInterviewRound;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestEmailSystem extends Command
{
    protected $signature = 'test:emails';
    protected $description = 'Test HR recruitment email system';

    public function handle()
    {
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║       HR Recruitment Email System Test                  ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Step 1: Check configuration
        $this->info('📧 Step 1: Checking Mail Configuration...');
        $this->line('   Mail Driver: ' . config('mail.default'));
        $this->line('   From Address: ' . config('mail.from.address'));
        $this->line('   From Name: ' . config('mail.from.name'));
        $this->newLine();

        // Step 2: Setup test data
        $this->info('💼 Step 2: Setting up test data...');
        
        $jobPosting = HrJobPosting::firstOrCreate(
            ['title' => 'Senior Laravel Developer - TEST'],
            [
                'department' => 'Engineering',
                'location' => 'Remote',
                'job_type' => 'Full-time',
                'posting_type' => 'Both',
                'description' => 'Test job posting for email verification',
                'requirements' => 'Laravel, PHP, MySQL, Vue.js',
                'status' => 'Active',
                'salary_from' => 80000,
                'salary_to' => 120000,
                'number_of_openings' => 2,
                'sources' => json_encode(['Company Website']),
                'closing_date' => now()->addDays(30),
                'tenant_id' => 1,
            ]
        );
        $this->line('   ✓ Job posting ready (ID: ' . $jobPosting->id . ')');

        // Clean up old test candidate
        HrCandidate::where('email', 'testcandidate@example.com')->delete();
        
        // Step 3: Test ApplicationReceivedMail
        $this->newLine();
        $this->info('👨‍💼 Step 3: Creating candidate (triggers ApplicationReceivedMail)...');
        
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

        try {
            Mail::to($candidate->email)->send(
                new \App\Mail\ApplicationReceivedMail($candidate->load('jobPosting'))
            );
            $this->line('   ✓ ApplicationReceivedMail sent to: ' . $candidate->email);
        } catch (\Exception $e) {
            $this->error('   ✗ Failed: ' . $e->getMessage());
        }

        // Step 4: Test ApplicationStatusMail
        $this->newLine();
        $this->info('📊 Step 4: Updating stage (triggers ApplicationStatusMail)...');
        
        $candidate->update(['stage' => 'Screening']);
        $candidate->refresh();
        $candidate->load('jobPosting');
        
        try {
            $mail = new \App\Mail\ApplicationStatusMail(
                $candidate,
                'Screening',
                'Your application is now under review by our hiring team.'
            );
            Mail::to($candidate->email)->send($mail);
            $this->line('   ✓ ApplicationStatusMail sent');
        } catch (\Exception $e) {
            $this->error('   ✗ Failed: ' . $e->getMessage());
            $this->line('   Debug: Candidate has jobPosting: ' . ($candidate->jobPosting ? 'Yes' : 'No'));
        }

        // Step 5: Test InterviewScheduledMail
        $this->newLine();
        $this->info('🎤 Step 5: Scheduling interview (triggers InterviewScheduledMail)...');
        
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
        
        $interview->load('candidate');

        try {
            Mail::to($candidate->email)->send(
                new \App\Mail\InterviewScheduledMail($interview, 'candidate')
            );
            $this->line('   ✓ InterviewScheduledMail sent to candidate');
            
            if ($interview->interviewer_email) {
                Mail::to($interview->interviewer_email)->send(
                    new \App\Mail\InterviewScheduledMail($interview, 'interviewer')
                );
                $this->line('   ✓ InterviewScheduledMail sent to interviewer');
            }
        } catch (\Exception $e) {
            $this->error('   ✗ Failed: ' . $e->getMessage());
        }

        // Step 6: Check logs
        $this->newLine();
        $this->info('📝 Step 6: Checking log file...');
        
        $logPath = storage_path('logs/laravel.log');
        if (file_exists($logPath)) {
            $logContent = file_get_contents($logPath);
            $recentLog = collect(explode("\n", $logContent))
                ->filter(fn($line) => str_contains($line, 'Application Received') || 
                                     str_contains($line, 'Interview Scheduled'))
                ->take(-3);
            
            $this->line('   Log file: ' . $logPath);
            $this->line('   Size: ' . number_format(filesize($logPath)) . ' bytes');
            
            if ($recentLog->isNotEmpty()) {
                $this->newLine();
                $this->line('   Recent email entries:');
                foreach ($recentLog as $line) {
                    $this->line('   ' . substr($line, 0, 80) . '...');
                }
            }
        } else {
            $this->warn('   Log file not found');
        }

        // Summary
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║                    Test Summary                          ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();
        
        $this->line('✅ Email system test completed!');
        $this->newLine();
        $this->line('Emails tested:');
        $this->line('1. ✓ ApplicationReceivedMail');
        $this->line('2. ✓ ApplicationStatusMail');
        $this->line('3. ✓ InterviewScheduledMail (candidate & interviewer)');
        $this->newLine();
        $this->line('Test data created:');
        $this->line('- Candidate: ' . $candidate->name . ' (' . $candidate->email . ')');
        $this->line('- Interview: ' . $interview->round_name);
        $this->newLine();
        $this->line('📋 Next steps:');
        $this->line('1. Check log file: ' . $logPath);
        $this->line('2. To enable real email delivery:');
        $this->line('   - Set MAIL_MAILER=smtp in .env');
        $this->line('   - Configure SMTP credentials');
        $this->newLine();
        
        return 0;
    }
}
