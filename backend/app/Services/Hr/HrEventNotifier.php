<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Services\Notifications\NotificationEngine;
use Illuminate\Support\Facades\Log;

/**
 * Announce an HR lifecycle event through the central notification engine.
 *
 * Payroll, loans and employee movements notified NOBODY. A run was approved,
 * disbursed and its payslips published in silence; somebody borrowed money from
 * the company and heard nothing when it was approved or paid; a promotion was
 * recorded and the person promoted found out by looking. Onboarding did notify,
 * but by raw e-mail only — no bell, no template, no per-tenant rule.
 *
 * Separate from RequestNotifier on purpose, and RequestNotifier is not touched.
 * That class answers an EMPLOYEE about a request THEY submitted, is shared with
 * the attendance app, and maps its own module vocabulary. This one announces
 * that something happened in a module, to whoever the module says, and is used
 * only by the four callers registered in config/hr_notifications.php for it.
 *
 * NEVER THROWS. A notification that fails must not roll back the thing it was
 * announcing — a loan being disbursed is the important half, and losing the
 * disbursement because a bell could not be rung would be a far worse defect
 * than a missing notification. Failures are logged and swallowed.
 */
class HrEventNotifier
{
    public function __construct(private NotificationEngine $engine)
    {
    }

    /**
     * Tell one employee something about their own record.
     *
     * Silently does nothing when the employee has no login. That is not an
     * error: plenty of people are on the payroll and not on the app, and the
     * alternative — throwing, or inventing a recipient — would either break
     * payroll or send somebody else's payslip notice to the wrong person.
     */
    public function toEmployee(
        ?HrEmployee $employee,
        string $module,
        string $event,
        array $context = [],
        ?User $actor = null,
        array $opts = [],
    ): void {
        $userId = $employee?->user_id;

        if (! $employee || ! $userId) {
            return;
        }

        $this->send((int) $employee->tenant_id, $module, $event, [
            'recipient_user_ids' => [(int) $userId],
            'context'            => array_merge(['employee' => $employee->name], $context),
        ] + $opts, $actor);
    }

    /**
     * Tell whoever is on the HR queue.
     *
     * `hr` is the only role the inbox can currently express:
     * NotificationRepository::visibleTo() shows every role-targeted row to
     * anyone who canManageHrQueue() and never matches recipient_role. Naming
     * 'finance' here would store a role nothing reads and deliver to HR anyway,
     * so the queue events say what actually happens. Distinct Finance/Accounts
     * targeting needs that repository changed and is a separate piece of work.
     */
    public function toHrQueue(
        int $tenantId,
        string $module,
        string $event,
        array $context = [],
        ?User $actor = null,
        array $opts = [],
    ): void {
        $this->send($tenantId, $module, $event, [
            'recipient_roles' => ['hr'],
            'context'         => $context,
        ] + $opts, $actor);
    }

    private function send(int $tenantId, string $module, string $event, array $opts, ?User $actor): void
    {
        try {
            $this->engine->dispatch($tenantId, $module, $event, $opts, $actor);
        } catch (\Throwable $e) {
            Log::channel('hr')->warning("Notification failed ({$module}/{$event}): ".$e->getMessage(), [
                'tenant_id' => $tenantId, 'module' => $module, 'event' => $event,
            ]);
        }
    }
}
