<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationEngine;
use App\Services\Notifications\NotificationQueueService;
use App\Services\Notifications\NotificationService;
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
        private NotificationService $prefs,
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
                // The MODULE, not the literal 'HR'. Nothing is registered under
                // 'HR', so the engine skipped every one of these silently and
                // no push, email or WhatsApp ever left the building. The module
                // is also what PushChannel switches on to decide where a tap
                // should land, so 'HR' made every notification a dead tap too.
                $this->moduleFor($kind),
                $event,
                [
                    'recipient_user_ids' => [$user->id],
                    // WhatsApp is opt-in per workspace, from Settings >
                    // Notifications, because every message costs the tenant
                    // money. It is off in the registry default, so nothing
                    // starts sending because this line was added.
                    'channels'           => $this->channelsFor($employee->tenant_id),
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

    /**
     * The registered module a request kind belongs to.
     *
     * Deliberately the same names PushChannel::typeFor() switches on, so a tap
     * target and a notification registration can never disagree.
     */
    private function moduleFor(string $kind): string
    {
        return match ($kind) {
            'Leave'                 => 'Leave',
            'Expense Claim'         => 'Expense',
            'Advance'               => 'Advance',
            'Attendance Correction' => 'Attendance',
            'Attendance'            => 'Attendance',
            default                 => $kind,
        };
    }

    /**
     * Which channels this workspace wants for HR notifications.
     *
     * In-app and push are unconditional -- they are the app's own bell and cost
     * nothing. WhatsApp is asked for, so an admin turns it on in Settings
     * rather than someone editing this file.
     */
    private function channelsFor(int $tenantId): array
    {
        $channels = ['in_app', 'push'];

        if ($this->prefs->allows('whatsapp', 'HR', $tenantId)) {
            $channels[] = 'whatsapp';
        }

        return $channels;
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
