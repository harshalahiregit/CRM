<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireAuditProvider;
use Sire\Models\AuditEvent;
use Sire\Dto\SireAuditEvent;
use Sire\Dto\SireUserIdentity;

/**
 * The shipped audit provider: SIRE's own write-once table.
 *
 * A host with an audit system implements SireAuditProvider and SIRE's history
 * appears alongside everything else in that product. A host without one gets a
 * working timeline out of the box. Exactly one is ever bound — there is never a
 * second trail.
 *
 * WRITES NEVER THROW
 *
 * Audit is evidence, not flow control. A transition that succeeded while its
 * audit write failed is far better than a transition that could not happen at
 * all, so failures are reported and swallowed. `sire:doctor` surfaces a table
 * that is missing entirely, which is the case worth noticing.
 *
 * READS ARE TENANT-SCOPED as well as subject-scoped. Subject type plus id is
 * already unique; the tenant filter is defence in depth and costs one indexed
 * column.
 */
class SireLocalAuditProvider implements SireAuditProvider
{
    public function record(SireAuditEvent $event): void
    {
        try {
            AuditEvent::create([
                'tenant_id'    => $event->tenantId,
                'subject_type' => $event->subjectType,
                'subject_id'   => $event->subjectId,
                'action'       => $event->action,
                'comment'      => $event->comment,
                // Snapshotted, not referenced: a trail that goes blank when
                // someone leaves the company is not a trail.
                'actor_id'     => $event->actor?->id,
                'actor_name'   => $event->actor?->displayName,
                'actor_role'   => $event->actor?->role,
                'before'       => $event->before ?: null,
                'after'        => $event->after ?: null,
                'metadata'     => $event->metadata ?: null,
                'created_at'   => $event->recordedAt ?? now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function recordMany(array $events): void
    {
        foreach ($events as $event) {
            $this->record($event);
        }
    }

    public function for(string $subjectType, int $subjectId, int $tenantId, int $limit = 200): array
    {
        try {
            return AuditEvent::query()
                ->forTenant($tenantId)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')          // stable order for same-second events
                ->limit($limit)
                ->get()
                ->map(fn (AuditEvent $row) => new SireAuditEvent(
                    tenantId: (int) $row->tenant_id,
                    subjectType: (string) $row->subject_type,
                    subjectId: (int) $row->subject_id,
                    action: (string) $row->action,
                    actor: $row->actor_id === null ? null : new SireUserIdentity(
                        id: (int) $row->actor_id,
                        tenantId: (int) $row->tenant_id,
                        displayName: (string) ($row->actor_name ?? 'Unknown'),
                        role: $row->actor_role,
                    ),
                    comment: $row->comment,
                    before: (array) ($row->before ?? []),
                    after: (array) ($row->after ?? []),
                    metadata: (array) ($row->metadata ?? []),
                    recordedAt: $row->created_at?->toIso8601String(),
                ))
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }
}
