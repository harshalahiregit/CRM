<?php

namespace Sire\Services;

use Sire\Models\Report;
use Sire\Dto\SireUserIdentity;
use Sire\Contracts\SireAuthorizationProvider;
use Sire\Contracts\SireNotificationProvider;
use Sire\Dto\SireNotification;
use Sire\Contracts\SireSettingsProvider;
use Sire\Support\SireEvents;
use Illuminate\Support\Facades\Cache;

/**
 * SIRE — the thin layer between the workflow and the existing notification engine.
 *
 * It does NOT deliver anything. Channels, per-tenant templates, user preferences,
 * escalation and the engine's own dedupe all stay where they are. This class only
 * answers "who, if anyone, should hear about this", because that question is
 * SIRE-specific and the engine cannot answer it.
 *
 * Four anti-spam rules, in order of how much noise they remove:
 *
 *   1. The actor never hears about their own action. By far the largest source of
 *      pointless notifications in a workflow tool — a developer does not need an
 *      email saying they started development.
 *   2. Recipients are de-duplicated. Reporter and assignee are often the same
 *      person; they get one notification, not two.
 *   3. Low-value events (COLLAPSIBLE) are collapsed to one per recipient per
 *      report per window. Nothing that means "you now have work" is collapsible.
 *   4. SLA warnings and breaches fire ONCE per clock, tracked in columns on the
 *      report — otherwise the 15-minute sweep would re-send every 15 minutes for
 *      as long as the issue stayed breached.
 */
class SireNotifier
{
    /** How long a collapsible event stays collapsed for one recipient + report. */
    private const COLLAPSE_WINDOW_MINUTES = 60;

    /** SireEvents role token => authorization roster name. */
    private const ROSTER_FOR_ROLE = [
        'admin'           => 'leads',
        'lead'            => 'leads',
        'qa'              => 'qa',
        'developer'       => 'developers',
        'release_manager' => 'release_managers',
    ];

    public function __construct(
        private readonly SireNotificationProvider $notifications,
        private readonly SireAuthorizationProvider $authorization,
        private readonly SireSettingsProvider $settings,
        private readonly SireWatcherService $watchers,
    ) {
    }

    /**
     * @param  array<string, mixed>  $extra  merged into the template payload
     */
    public function send(Report $report, string $event, ?SireUserIdentity $actor = null, array $extra = []): void
    {
        if (! array_key_exists($event, SireEvents::RECIPIENTS)) {
            return;
        }

        if (! $this->eventEnabled($report, $event)) {
            return;
        }

        $recipients = $this->resolveRecipients($report, $event, $actor, $extra);

        if ($recipients === []) {
            return;
        }

        $this->dispatch(
            (int) $report->tenant_id,
            $recipients['users'],
            $event,
            array_merge([
                'report' => $report,
                'actor'  => $actor,
                'roles'  => $recipients['roles'],
            ], $extra),
        );
    }

    /**
     * SLA notices fire once per clock per state. `$clock` is 'ack' or 'resolve';
     * the highest state already sent is stored on the report, so a breach still
     * gets through after a warning, but neither repeats.
     *
     * Returns true if a notification was actually sent.
     */
    public function sendSlaNotice(Report $report, string $clock, string $state): bool
    {
        $column = "sla_{$clock}_notified_state";
        $already = $report->{$column};

        $rank = ['warning' => 1, 'breached' => 2];
        $newRank = $rank[$state] ?? 0;
        $oldRank = $rank[$already] ?? 0;

        if ($newRank === 0 || $newRank <= $oldRank) {
            return false; // nothing new to say
        }

        $event = $state === 'breached' ? SireEvents::SLA_BREACHED : SireEvents::SLA_WARNING;

        // Written in the same transaction as the dispatch by the caller, so a
        // catch-up run after downtime cannot produce a notification storm.
        $report->{$column} = $state;
        $report->save();

        $this->send($report, $event, null, ['clock' => $clock, 'sla_state' => $state]);

        return true;
    }

    /**
     * Release-level events. Separate from send() because a release has no
     * assignee, reporter or QA owner — its audience is whoever governs releases,
     * which the engine resolves from roles rather than from the row.
     *
     * An override notification is never collapsed and never suppressed for the
     * actor: the whole point is that somebody other than the person who did it
     * finds out.
     */
    public function sendReleaseEvent(\Sire\Models\Release $release, string $event, ?SireUserIdentity $actor = null): void
    {
        $disabled = (array) $this->settings->get((int) $release->tenant_id, 'sire.notifications.disabled', []);
        if (in_array($event, $disabled, true) && $event !== 'sire.release.overridden') {
            return;
        }

        $roles = $event === 'sire.release.overridden'
            ? ['admin', 'lead', 'qa']   // an override is everyone's business
            : ['admin', 'lead'];

        $this->dispatch(
            (int) $release->tenant_id,
            $this->expandRoles((int) $release->tenant_id, $roles, $actor),
            $event,
            ['release' => $release, 'actor' => $actor, 'roles' => $roles],
        );
    }

    /** Reopening restarts the story: previous SLA notices no longer apply. */
    public function resetSlaNotices(Report $report): void
    {
        $report->sla_ack_notified_state = null;
        $report->sla_resolve_notified_state = null;
    }

    /**
     * @return array{users: array<int, int>, roles: array<int, string>}
     */
    private function resolveRecipients(Report $report, string $event, ?SireUserIdentity $actor, array $extra): array
    {
        $users = [];
        $roles = [];

        foreach (SireEvents::RECIPIENTS[$event] as $token) {
            if (str_starts_with($token, 'role:')) {
                $roles[] = substr($token, 5);

                continue;
            }

            // Watchers are a LIST, not one person, so they cannot go through the
            // single-id match below. Everything after this point -- actor
            // suppression, de-duplication, collapsing -- applies to them exactly
            // as it does to the assignee, which is the point of expanding here
            // rather than at dispatch.
            if ($token === 'watchers') {
                $users = array_merge($users, $this->watchers->watcherIds($report));

                continue;
            }

            // Everyone working the issue besides its owner. They are doing the
            // work; being the second name on it should not mean hearing about it
            // second-hand.
            if ($token === 'assignee') {
                $users = array_merge($users, \Sire\Models\ReportAssignee::query()
                    ->forTenant($report->tenant_id)
                    ->where('report_id', $report->id)
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
                    ->all());
            }

            $id = match ($token) {
                'assignee'          => $report->assignee_id,
                'qa_assignee'       => $report->qa_assignee_id,
                'reporter'          => $report->reporter_id,
                'previous_assignee' => $extra['previous_assignee_id'] ?? null,
                default             => null,
            };

            if ($id !== null) {
                $users[] = (int) $id;
            }
        }

        // Role audiences become people here, so that every downstream rule --
        // actor suppression, de-duplication, collapsing -- applies to them
        // identically. Expanding later would let a lead receive a collapsed
        // event twice: once as the assignee, once as a lead.
        if ($roles !== []) {
            $users = array_merge($users, $this->expandRoles((int) $report->tenant_id, $roles));
        }

        // Rule 1: never tell someone about a thing they just did.
        if ($actor !== null) {
            $users = array_filter($users, fn (int $id) => $id !== (int) $actor->id);
        }

        // Rule 2: reporter and assignee are frequently the same person.
        $users = array_values(array_unique($users));

        // Rule 3: collapse the low-value ones.
        if (in_array($event, SireEvents::COLLAPSIBLE, true)) {
            $users = array_values(array_filter(
                $users,
                fn (int $id) => $this->claimCollapseSlot($report, $event, $id),
            ));
        }

        return ['users' => $users, 'roles' => array_values(array_unique($roles))];
    }

    /** True the first time in the window, false while the slot is held. */
    private function claimCollapseSlot(Report $report, string $event, int $userId): bool
    {
        $key = "sire:notify:{$report->tenant_id}:{$report->id}:{$event}:{$userId}";

        return Cache::add($key, 1, now()->addMinutes(self::COLLAPSE_WINDOW_MINUTES));
    }

    /**
     * A tenant can silence an event entirely:
     *   sire.notifications.disabled = ['sire.report.development_started', ...]
     *
     * Deliberately a mute list in the existing settings store, not a new
     * preferences table. Per-user preferences remain the engine's job.
     */
    private function eventEnabled(Report $report, string $event): bool
    {
        $disabled = (array) $this->settings->get((int) $report->tenant_id, 'sire.notifications.disabled', []);

        return ! in_array($event, $disabled, true);
    }

    /**
     * The single delivery call in SIRE.
     *
     * Wrapped because notification delivery must never be able to fail a
     * workflow transition. A developer whose "submit to QA" throws because an
     * SMTP host is down has lost work; a QA engineer who misses one email has
     * lost a few minutes. The adapter is also required to swallow its own
     * failures — this is the second layer, not the only one.
     *
     * @param  array<int, int> $userIds
     */
    private function dispatch(int $tenantId, array $userIds, string $event, array $payload): void
    {
        if ($userIds === []) {
            return;
        }

        $notifications = [];

        foreach (array_values($userIds) as $recipientId) {
            // accepts() is asked per recipient, because a preference is a
            // property of a person, not of an event. Hosts that track none
            // return true and this costs nothing.
            if (! $this->notifications->accepts($tenantId, (int) $recipientId, $event)) {
                continue;
            }

            $notifications[] = new SireNotification(
                tenantId: $tenantId,
                event: $event,
                recipientId: (int) $recipientId,
                title: SireEvents::TITLES[$event] ?? $event,
                body: $this->bodyFor($event, $payload),
                url: $this->urlFor($payload),
                priority: SireEvents::PRIORITIES[$event] ?? SireNotification::PRIORITY_NORMAL,
                metadata: $this->metadataFor($payload),
            );
        }

        if ($notifications === []) {
            return;
        }

        try {
            $this->notifications->sendMany($notifications);
        } catch (\Throwable $e) {
            // Second layer. The provider is required to swallow its own failures
            // too, but delivery must never be able to fail a transition and one
            // guard is not enough for something this load-bearing.
            report($e);
        }
    }

    /** One sentence naming the issue, so a notification is useful without opening it. */
    private function bodyFor(string $event, array $payload): string
    {
        $report = $payload['report'] ?? null;
        $release = $payload['release'] ?? null;

        if ($report !== null) {
            return trim(sprintf('%s — %s', $report->report_number ?? '', $report->title ?? ''), ' —');
        }

        if ($release !== null) {
            return trim(sprintf('Release %s', $release->version ?? $release->name ?? ''));
        }

        return '';
    }

    /**
     * SIRE-relative, never absolute. Only the host knows its own domain and
     * deep-link scheme; a URL built here would be wrong in every installation
     * but one.
     */
    private function urlFor(array $payload): ?string
    {
        if (isset($payload['report'])) {
            return '/app/sire/cases/'.$payload['report']->id;
        }

        if (isset($payload['release'])) {
            return '/app/sire/releases/'.$payload['release']->id;
        }

        return null;
    }

    /**
     * Scalars a host template can interpolate. Deliberately NOT the model: a
     * whole Report carries the reporter's context capture, and a notification
     * payload is not the place for it.
     *
     * @return array<string, mixed>
     */
    private function metadataFor(array $payload): array
    {
        $report = $payload['report'] ?? null;

        $meta = array_filter([
            'report_id'     => $report->id ?? null,
            'report_number' => $report->report_number ?? null,
            'status'        => $report->status ?? null,
            'priority'      => $report->priority ?? null,
            'release_id'    => $payload['release']->id ?? null,
        ], static fn ($v) => $v !== null);

        foreach (['clock', 'sla_state', 'roles'] as $key) {
            if (isset($payload[$key])) {
                $meta[$key] = $payload[$key];
            }
        }

        return $meta;
    }

    /**
     * Turn role tokens into user ids.
     *
     * SireEvents names audiences by role ('role:lead') because that is how the
     * workflow thinks about them. Delivery needs people. Rosters come from the
     * authorization adapter, so a CRM with real roles answers this properly and
     * the settings-based rosters are only the fallback.
     *
     * The actor is removed here too — rule 1 applies to role audiences exactly
     * as it does to named recipients.
     *
     * @param  array<int, string> $roles
     * @return array<int, int>
     */
    private function expandRoles(int $tenantId, array $roles, ?SireUserIdentity $actor = null): array
    {
        $ids = [];

        foreach ($roles as $role) {
            foreach ($this->authorization->roster($tenantId, self::ROSTER_FOR_ROLE[$role] ?? $role) as $id) {
                $ids[] = (int) $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($actor !== null) {
            $ids = array_values(array_filter($ids, fn (int $id) => $id !== (int) $actor->id));
        }

        return $ids;
    }
}
