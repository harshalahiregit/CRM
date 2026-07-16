<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Models\Sales\Lead;
use App\Models\Sales\LeadActivity;
use App\Models\Sales\SalesActivity;
use Illuminate\Support\Collection;

class SalesActivityService
{
    /**
     * Allowlist of short subject keys → fully-qualified model class.
     * The API accepts the short key; we resolve to the FQCN stored in
     * sales_activities.subject_type. Never a global Relation::morphMap.
     */
    public const SUBJECT_MAP = [
        'lead'       => \App\Models\Sales\Lead::class,
        'proposal'   => \App\Models\Sales\Proposal::class,
        'estimate'   => \App\Models\Sales\Estimate::class,
        'invoice'    => \App\Models\Sales\SalesInvoice::class,
        'credit_note'=> \App\Models\Sales\CreditNote::class,
        'contract'   => \App\Models\Sales\SalesContract::class,
        'task'       => \App\Models\Sales\Task::class,
        'customer'   => \App\Models\Customer\Client::class,
    ];

    public function resolveSubjectClass(string $subjectKey): string
    {
        if (! isset(self::SUBJECT_MAP[$subjectKey])) {
            throw new BusinessException("Unknown activity subject type: {$subjectKey}", 422);
        }

        return self::SUBJECT_MAP[$subjectKey];
    }

    /**
     * Unified, normalized timeline for a subject. When the subject is a lead,
     * the existing lead_activities rows are merged in so historical lead events
     * are not lost (the lead never wrote to sales_activities).
     *
     * @return Collection<int, array{id:string,type:string,description:string,old_value:?string,new_value:?string,performed_by:?string,date:?string}>
     */
    public function timeline(int $tenantId, string $subjectKey, int $subjectId): Collection
    {
        $subjectClass = $this->resolveSubjectClass($subjectKey);

        $events = SalesActivity::forTenant($tenantId)
            ->forSubject($subjectClass, $subjectId)
            ->with('performer:id,name')
            ->latest()
            ->get()
            ->map(fn (SalesActivity $a) => [
                'id'           => 'sa-' . $a->id,
                'type'         => $a->type,
                'description'  => $a->description,
                'old_value'    => $a->old_value,
                'new_value'    => $a->new_value,
                'performed_by' => $a->performer?->name,
                'date'         => $a->created_at?->toIso8601String(),
            ]);

        if ($subjectKey === 'lead') {
            $leadEvents = LeadActivity::forTenant($tenantId)
                ->where('lead_id', $subjectId)
                ->with('performer:id,name')
                ->latest()
                ->get()
                ->map(fn (LeadActivity $a) => [
                    'id'           => 'la-' . $a->id,
                    'type'         => $a->type,
                    'description'  => $a->description,
                    'old_value'    => $a->old_value,
                    'new_value'    => $a->new_value,
                    'performed_by' => $a->performer?->name,
                    'date'         => $a->created_at?->toIso8601String(),
                ]);

            $events = $events->concat($leadEvents);
        }

        return $events->sortByDesc('date')->values();
    }
}
