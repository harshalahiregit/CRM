<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationEngine;
use App\Services\Notifications\NotificationQueueService;
use Illuminate\Support\Facades\Log;

/**
 * Tells an employee what happened to something they asked for.
 *
 * Leave, expense claims, advances and attendance corrections notified NOBODY.
 * An employee submitted a request and heard nothing; it was approved and they
 * heard nothing; it was rejected and they heard nothing. The only way to find
 * out was to open the app and go looking, which is exactly what a notification
 * exists to save somebody from.
 *
 * Follows the announcement path, which is proven to deliver: the app row is
 * written FIRST so its id exists before the push goes out — a push carries the
 * id the phone looks up, and created the other way round it would carry an id
 * the phone cannot resolve, so tapping it would go nowhere.
 *
 * NEVER THROWS. A notification that fails must not roll back the approval it was
 * announcing; somebody's leave being approved is the important half. Failures
 * are logged and swallowed.
 */
class RequestNotifier
{
    public function __construct(
        private NotificationEngine $engine,
        private NotificationQueueService $queue,
    ) {
    }

    /**
     * @param  string  $kind    'Leave' | 'Expense Claim' | 'Advance' | 'Attendance Correction'
     * @param  string  $event   what happened, in words the employee would use
     */
    public function tell(
        ?HrEmployee $employee,
        string $kind,
        string $event,
        string $detail = '',
        ?User $actor = null,
        array $context = [],
    ): void {
        try {
            $user = $employee?->user;

            // No login means no phone and no bell. Not an error — plenty of
            // employees are on the payroll and not on the app.
            if (! $user) {
                return;
            }

            $title = "{$kind} {$event}";
            $body  = $detail !== '' ? $detail : "Your {$kind} request was {$event}.";

            $appId = Notification::create([
                'tenant_id' => $employee->tenant_id,
                'user_id'   => $user->id,
                'type'      => $this->typeFor($kind),
                'title'     => $title,
                'message'   => $body,
            ])->id;

            $created = $this->engine->dispatch(
                $employee->tenant_id,
                'HR',
                $kind.' '.$event,
                [
                    'recipient_user_ids' => [$user->id],
                    'channels'           => ['in_app', 'push'],
                    'context'            => array_merge([
                        'title' => $title,
                        'body'  => $body,
                    ], $context),
                    'app_notification_ids' => [$user->id => $appId],
                ],
                $actor,
            );

            // Delivered now rather than on a scheduled sweep: an approval the
            // employee hears about tomorrow morning is not a notification.
            $this->queue->processNow(
                collect($created)->pluck('id')->filter()->all(),
                $actor,
            );
        } catch (\Throwable $e) {
            Log::channel('hr')->warning('Could not notify an employee about their request', [
                'kind' => $kind, 'event' => $event, 'employee_id' => $employee?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** The tap target the app opens when somebody taps the push. */
    private function typeFor(string $kind): string
    {
        return match ($kind) {
            'Leave'                  => 'leave',
            'Expense Claim'          => 'reimbursement',
            'Advance'                => 'advance',
            'Attendance Correction'  => 'attendance',
            default                  => 'general',
        };
    }
}
