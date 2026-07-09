<?php

namespace App\Console\Commands;

use App\Models\Hr\HrInterviewRound;
use App\Notifications\WhatsApp\InterviewReminderNotification;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SendInterviewReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:interview-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send WhatsApp reminders for upcoming interviews (24 hours before)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔔 Checking for upcoming interviews...');
        
        $hoursBeforeReminder = config('whatsapp.reminders.hours_before', 24);
        
        // Get interviews scheduled between 23-25 hours from now
        $targetTime = now()->addHours($hoursBeforeReminder);
        $startWindow = $targetTime->copy()->subHour();
        $endWindow = $targetTime->copy()->addHour();
        
        $this->line("   Time window: {$startWindow->format('Y-m-d H:i')} to {$endWindow->format('Y-m-d H:i')}");
        
        // Find scheduled interviews in this window that haven't been reminded
        $interviews = HrInterviewRound::with('candidate')
            ->where('status', 'Scheduled')
            ->whereBetween('scheduled_at', [$startWindow, $endWindow])
            ->whereNull('reminder_sent_at') // Only send once
            ->get();
        
        $this->line("   Found {$interviews->count()} interview(s) needing reminders");
        $this->newLine();
        
        $sentCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        
        foreach ($interviews as $interview) {
            $candidate = $interview->candidate;
            
            if (!$candidate) {
                $this->warn("   ⚠️  Interview #{$interview->id}: No candidate found");
                $skippedCount++;
                continue;
            }
            
            if (!$candidate->canReceiveWhatsApp()) {
                $this->line("   ⏭️  Interview #{$interview->id}: Candidate {$candidate->name} - WhatsApp not enabled");
                $skippedCount++;
                continue;
            }
            
            try {
                InterviewReminderNotification::send($interview);
                
                // Mark as reminded
                $interview->update(['reminder_sent_at' => now()]);
                
                $this->info("   ✅ Interview #{$interview->id}: Reminder sent to {$candidate->name} ({$candidate->phone})");
                $sentCount++;
                
            } catch (\Exception $e) {
                $this->error("   ❌ Interview #{$interview->id}: Failed - {$e->getMessage()}");
                $failedCount++;
            }
        }
        
        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 Summary:');
        $this->line("   ✅ Sent: {$sentCount}");
        $this->line("   ⏭️  Skipped: {$skippedCount}");
        $this->line("   ❌ Failed: {$failedCount}");
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        return 0;
    }
}
