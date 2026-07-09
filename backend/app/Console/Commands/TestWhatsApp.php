<?php

namespace App\Console\Commands;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrInterviewRound;
use App\Services\WhatsAppService;
use App\Notifications\WhatsApp\ApplicationReceivedNotification;
use App\Notifications\WhatsApp\InterviewScheduledNotification;
use App\Notifications\WhatsApp\StatusUpdateNotification;
use Illuminate\Console\Command;

class TestWhatsApp extends Command
{
    protected $signature = 'test:whatsapp {--phone= : Phone number to send test to}';
    protected $description = 'Test WhatsApp notification system';

    public function handle()
    {
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║       WhatsApp Notification System Test                 ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Step 1: Check configuration
        $this->info('📱 Step 1: Checking WhatsApp Configuration...');
        $enabled = config('whatsapp.enabled');
        $provider = config('whatsapp.provider');
        $from = config('whatsapp.twilio.from');
        
        $this->line('   WhatsApp Enabled: ' . ($enabled ? '✅ Yes' : '❌ No'));
        $this->line('   Provider: ' . $provider);
        $this->line('   From Number: ' . $from);
        
        if (!$enabled) {
            $this->warn('   ⚠️  WhatsApp is disabled. Messages will be logged but not sent.');
            $this->line('   To enable: Set WHATSAPP_ENABLED=true in .env');
        }
        $this->newLine();

        // Step 2: Test WhatsApp Service
        $this->info('🔧 Step 2: Testing WhatsApp Service...');
        $whatsapp = new WhatsAppService();
        $this->line('   Service Status: ' . ($whatsapp->isEnabled() ? '✅ Enabled' : '❌ Disabled'));
        $this->newLine();

        // Step 3: Get or create test candidate
        $this->info('👤 Step 3: Setting up test candidate...');
        
        $phone = $this->option('phone');
        if (!$phone) {
            $phone = $this->ask('Enter phone number to test (with country code, e.g., +911234567890)');
        }
        
        if (!$phone) {
            $this->error('Phone number is required for testing!');
            return 1;
        }

        $candidate = HrCandidate::where('phone', $phone)->first();
        
        if (!$candidate) {
            $candidate = HrCandidate::create([
                'name' => 'Test WhatsApp Candidate',
                'email' => 'whatsapp.test@example.com',
                'phone' => $phone,
                'location' => 'Test City',
                'experience_years' => 3,
                'source' => 'Direct',
                'stage' => 'Applied',
                'whatsapp_opt_in' => true,
                'tenant_id' => 1,
            ]);
            $this->line('   ✓ Created test candidate (ID: ' . $candidate->id . ')');
        } else {
            $this->line('   ✓ Using existing candidate (ID: ' . $candidate->id . ')');
            $candidate->update(['whatsapp_opt_in' => true]);
        }
        
        $this->line('   Phone: ' . $candidate->phone);
        $this->line('   WhatsApp Opt-in: ' . ($candidate->whatsapp_opt_in ? 'Yes' : 'No'));
        $this->newLine();

        // Step 4: Test Application Received Notification
        $this->info('📧 Step 4: Testing Application Received Notification...');
        try {
            ApplicationReceivedNotification::send($candidate);
            $this->line('   ✓ Notification sent');
        } catch (\Exception $e) {
            $this->error('   ✗ Failed: ' . $e->getMessage());
        }
        $this->newLine();

        // Step 5: Test Status Update Notification
        $this->info('📊 Step 5: Testing Status Update Notification...');
        try {
            StatusUpdateNotification::send($candidate, 'Screening');
            $this->line('   ✓ Notification sent (Stage: Screening)');
        } catch (\Exception $e) {
            $this->error('   ✗ Failed: ' . $e->getMessage());
        }
        $this->newLine();

        // Step 6: Test Interview Scheduled Notification
        $this->info('🎤 Step 6: Testing Interview Scheduled Notification...');
        
        $interview = HrInterviewRound::create([
            'candidate_id' => $candidate->id,
            'round_name' => 'WhatsApp Test Interview',
            'round_type' => 'Technical',
            'interviewer_name' => 'Test Interviewer',
            'interviewer_email' => 'interviewer@test.com',
            'scheduled_at' => now()->addDays(2),
            'duration_minutes' => 60,
            'meet_link' => 'https://meet.google.com/test-' . rand(100, 999),
            'status' => 'Scheduled',
            'result' => 'Pending',
            'tenant_id' => 1,
        ]);
        
        try {
            InterviewScheduledNotification::send($interview);
            $this->line('   ✓ Notification sent');
        } catch (\Exception $e) {
            $this->error('   ✗ Failed: ' . $e->getMessage());
        }
        $this->newLine();

        // Step 7: Check WhatsApp logs
        $this->info('📝 Step 7: Checking WhatsApp Logs...');
        $logs = $candidate->whatsappLogs()->latest()->take(3)->get();
        
        if ($logs->isEmpty()) {
            $this->warn('   No logs found');
        } else {
            $this->line('   Found ' . $logs->count() . ' recent log(s):');
            foreach ($logs as $log) {
                $statusEmoji = match($log->status) {
                    'sent', 'delivered' => '✅',
                    'failed' => '❌',
                    default => '⏳'
                };
                $this->line("   {$statusEmoji} [{$log->event_type}] Status: {$log->status} - " . 
                            $log->created_at->diffForHumans());
            }
        }
        $this->newLine();

        // Step 8: Get statistics
        $this->info('📊 Step 8: WhatsApp Statistics...');
        $stats = $whatsapp->getStats();
        $this->line('   Total Sent: ' . $stats['total_sent']);
        $this->line('   Total Delivered: ' . $stats['total_delivered']);
        $this->line('   Total Failed: ' . $stats['total_failed']);
        $this->line('   Total Queued: ' . $stats['total_queued']);
        $this->line('   Delivery Rate: ' . $stats['delivery_rate'] . '%');
        $this->newLine();

        // Summary
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║                    Test Summary                          ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();
        
        $this->line('✅ WhatsApp test completed!');
        $this->newLine();
        $this->line('Notifications tested:');
        $this->line('1. ✓ Application Received');
        $this->line('2. ✓ Status Update (Screening)');
        $this->line('3. ✓ Interview Scheduled');
        $this->newLine();
        
        if (!$enabled) {
            $this->warn('⚠️  WhatsApp is DISABLED - messages were logged but NOT sent');
            $this->line('To enable real sending:');
            $this->line('1. Set WHATSAPP_ENABLED=true in .env');
            $this->line('2. Add Twilio credentials to .env');
            $this->line('3. Run this test again');
        } else {
            $this->line('✅ Check your WhatsApp on ' . $phone);
            $this->line('You should have received 3 messages');
        }
        
        $this->newLine();
        $this->line('📋 Next steps:');
        $this->line('1. Check database: hr_whatsapp_logs table');
        $this->line('2. Check Laravel logs: storage/logs/laravel.log');
        $this->line('3. If enabled, check Twilio dashboard for delivery status');
        $this->newLine();

        return 0;
    }
}
