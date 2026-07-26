<?php

namespace App\Services\Notifications\Reminders;

/**
 * Reminder source registry (Central Notification Engine). Holds every registered
 * ReminderSource. A future module plugs a new source in here (add to $sources) —
 * the ReminderEngine and everything downstream stay unchanged.
 */
class ReminderSourceRegistry
{
    /** @var ReminderSource[] */
    private array $sources;

    public function __construct(
        ProbationConfirmationSource $probation,
        LeavePendingApprovalSource $leave,
        LearningCertificateExpirySource $learning,
    ) {
        $this->sources = [$probation, $leave, $learning];
    }

    public function for(string $module, string $event): ?ReminderSource
    {
        foreach ($this->sources as $source) {
            if ($source->module() === $module && $source->event() === $event) {
                return $source;
            }
        }

        return null;
    }

    /** @return ReminderSource[] */
    public function all(): array
    {
        return $this->sources;
    }
}
