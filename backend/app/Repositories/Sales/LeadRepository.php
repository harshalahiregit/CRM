<?php

namespace App\Repositories\Sales;

use App\Models\Sales\Lead;
use App\Models\Sales\LeadStatus;
use App\Repositories\BaseRepository;

class LeadRepository extends BaseRepository
{
    protected string $modelClass = Lead::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = Lead::forTenant($tenantId)->with(['status', 'source', 'assignedUser:id,name']);

        if (!empty($filters['status_id'])) {
            $query->where('status_id', $filters['status_id']);
        }
        if (!empty($filters['source_id'])) {
            $query->where('source_id', $filters['source_id']);
        }
        if (!empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }
        if (!empty($filters['temperature'])) {
            $query->where('lead_temperature', $filters['temperature']);
        }
        if (!empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                  ->orWhere('email', 'like', $s)
                  ->orWhere('phone', 'like', $s)
                  ->orWhere('company', 'like', $s);
            });
        }
        if (isset($filters['min_value']) && $filters['min_value'] !== null) {
            $query->where('lead_value', '>=', $filters['min_value']);
        }
        if (isset($filters['max_value']) && $filters['max_value'] !== null) {
            $query->where('lead_value', '<=', $filters['max_value']);
        }

        if (!empty($filters['lost'])) {
            $query->lost();
        } elseif (!empty($filters['junk'])) {
            $query->junk();
        } else {
            $query->active();
        }

        $sort  = $filters['sort'] ?? 'created_at';
        $order = $filters['order'] ?? 'desc';

        return $query->orderBy($sort, $order)->get();
    }

    public function active(int $tenantId)
    {
        return Lead::forTenant($tenantId)->active();
    }

    public function lost(int $tenantId)
    {
        return Lead::forTenant($tenantId)->lost();
    }

    public function kanbanColumns(int $tenantId)
    {
        $statuses = LeadStatus::forTenant($tenantId)->ordered()->get();

        $leads = $this->active($tenantId)
            ->with(['source', 'assignedUser:id,name'])
            ->orderBy('lead_order')
            ->get();

        return $statuses->map(function ($status) use ($leads) {
            $statusLeads = $leads->where('status_id', $status->id)->values();
            return [
                'id'          => $status->id,
                'name'        => $status->name,
                'color'       => $status->color,
                'is_won'      => $status->is_won_status,
                'leads'       => $statusLeads,
                'count'       => $statusLeads->count(),
                'total_value' => $statusLeads->sum('lead_value'),
            ];
        });
    }
}
