<?php

namespace App\Console\Commands;

use App\Services\Tpv\OnboardingApprovalService;
use Illuminate\Console\Command;

/**
 * Flags onboarding approval levels that have blown their SLA as Escalated.
 * Scheduled hourly (see routes/console.php).
 */
class SendApprovalEscalations extends Command
{
    protected $signature = 'tpv:approval-escalations';

    protected $description = 'Flag onboarding approval levels past their SLA as escalated';

    public function handle(OnboardingApprovalService $approvals): int
    {
        $count = $approvals->escalatePending();
        $this->info("Approval escalation sweep: {$count} level(s) escalated.");

        return self::SUCCESS;
    }
}
