<?php

namespace App\Services\Notifications;

use App\Models\Hr\HrEmployee;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * An announcement somebody typed, sent to the people they chose.
 *
 * Deliberately not a second announcements feature. It composes into the existing
 * Central Notification Engine — same events, same queue, same channels, same
 * Queue Monitor — so an announcement is auditable exactly like an automatic
 * notification, and there is one place to look when something did not arrive.
 *
 * Two in-app stores exist and both are written: `hr_notifications` is what the
 * CRM's bell and Notification Center read, and `notifications` is what the phone
 * reads. That split predates this and is a seam worth closing later; writing one
 * only would mean an announcement that reaches the office but not the site, or
 * the reverse, which is the failure this feature exists to prevent.
 */
class AnnouncementService
{
    public function __construct(
        private NotificationEngine $engine,
        private NotificationQueueService $queue,
    ) {
    }

    /**
     * @param  array{title: string, body: string, audience: string, department?: ?string, user_ids?: array, channels?: array}  $data
     * @return array{recipients: int, notifications: int, attachment: ?string}
     */
    public function send(int $tenantId, array $data, User $actor, ?UploadedFile $attachment = null): array
    {
        $recipients = $this->resolveRecipients($tenantId, $data);

        if ($recipients === []) {
            return ['recipients' => 0, 'notifications' => 0, 'attachment' => null];
        }

        $path = $attachment
            ? $attachment->store("hr/announcements/tenant_{$tenantId}", 'local')
            : null;

        $created = $this->engine->dispatch(
            $tenantId,
            'Announcement',
            'Broadcast',
            [
                'recipient_user_ids' => $recipients,
                'channels'           => $data['channels'] ?? ['in_app', 'push'],
                'context'            => [
                    'title' => $data['title'],
                    'body'  => $data['body'],
                ],
            ],
            $actor,
        );

        // The phone reads `notifications`, not `hr_notifications`. Same message,
        // the store each reader actually looks at — not a duplicate to any one
        // person, who sees it once wherever they happen to be.
        DB::transaction(function () use ($recipients, $tenantId, $data, $path) {
            foreach ($recipients as $userId) {
                Notification::create([
                    'tenant_id' => $tenantId,
                    'user_id'   => $userId,
                    'type'      => 'announcement',
                    'title'     => $data['title'],
                    'message'   => $data['body'],
                    'link'      => $path ? route('hr.announcement.file', ['path' => base64_encode($path)]) : null,
                ]);
            }
        });

        // Delivered now, not on the next scheduled sweep. Somebody just pressed
        // Send and is watching their phone; a queue item sitting Pending until a
        // cron fires reads as "push does not work", and on a machine with no
        // scheduler running it never arrives at all.
        $delivery = $this->queue->processNow(
            collect($created)->pluck('id')->filter()->all(),
            $actor,
        );

        return [
            'recipients'    => count($recipients),
            'notifications' => count($created),
            'attachment'    => $path,
            'pushed'        => $delivery['sent'],
        ];
    }

    /**
     * Who this goes to.
     *
     * Only people who can actually receive it — an employee with no linked user
     * account has no bell to ring and no device to push to, so counting them
     * would report a delivery that never happened.
     */
    private function resolveRecipients(int $tenantId, array $data): array
    {
        $employees = HrEmployee::where('tenant_id', $tenantId)
            ->whereNotNull('user_id')
            ->where('status', 'Active');

        match ($data['audience']) {
            'department' => $employees->where('department', $data['department'] ?? ''),
            'employees'  => $employees->whereIn('user_id', $data['user_ids'] ?? []),
            default      => null, // everyone
        };

        return $employees->pluck('user_id')->unique()->values()->all();
    }
}
