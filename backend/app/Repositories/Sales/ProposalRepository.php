<?php

namespace App\Repositories\Sales;

use App\Models\Customer\Client;
use App\Models\Sales\Lead;
use App\Models\Sales\Proposal;
use App\Repositories\BaseRepository;
use Illuminate\Support\Collection;

class ProposalRepository extends BaseRepository
{
    protected string $modelClass = Proposal::class;

    public function filtered(int $tenantId, ?string $status, ?string $search)
    {
        $query = Proposal::forTenant($tenantId)->with(['lineItems', 'assignedUser']);

        if ($status && $status !== 'All') {
            $query->ofStatus($status);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', '%'.$search.'%')
                  ->orWhere('proposal_to', 'like', '%'.$search.'%');
            });
        }

        return $this->withRecipientName($query->latest()->get(), $tenantId);
    }

    /**
     * Fill the `client` attribute the list's Client column reads.
     *
     * A proposal points at either a customer or a lead through rel_type/rel_id.
     * That is not a relation Eloquent can eager load, so nothing ever set
     * `client` and the column rendered blank on every row. Resolved here in at
     * most two extra queries rather than one per row.
     */
    private function withRecipientName(Collection $proposals, int $tenantId): Collection
    {
        $idsOf = fn (string $type) => $proposals
            ->where('rel_type', $type)
            ->pluck('rel_id')
            ->filter()
            ->unique()
            ->all();

        $customerIds = $idsOf('customer');
        $leadIds     = $idsOf('lead');

        $customers = $customerIds
            ? Client::forTenant($tenantId)->whereIn('id', $customerIds)->pluck('company', 'id')
            : collect();

        // Leads are titled by contact name, falling back to the company — the
        // same order the proposal form's own lead picker uses.
        $leads = $leadIds
            ? Lead::forTenant($tenantId)->whereIn('id', $leadIds)
                ->get(['id', 'name', 'company'])
                ->mapWithKeys(fn (Lead $l) => [$l->id => $l->name ?: $l->company])
            : collect();

        return $proposals->each(function (Proposal $proposal) use ($customers, $leads) {
            $name = $proposal->rel_type === 'lead'
                ? $leads->get($proposal->rel_id)
                : $customers->get($proposal->rel_id);

            // proposal_to is the free-text recipient the wizard captures, and is
            // the right fallback when the linked record is gone or unnamed.
            $proposal->setAttribute('client', $name ?: ($proposal->proposal_to ?: null));
        });
    }
}
