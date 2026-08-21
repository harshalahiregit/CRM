<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvCapa;
use App\Models\User;
use App\Support\Tpv\CapaSource;
use Illuminate\Support\Facades\Log;

/**
 * Generalised CAPA register (Sangoe TPV §25). CRUD + the lifecycle
 * Open → In_Progress → Done → Verified, with verification gated on evidence
 * (Rule 12). Additive to IncidentCapa, which remains the incident-close gate.
 */
class TpvCapaService
{
    public function list(int $tenantId, array $filters = [])
    {
        return TpvCapa::forTenant($tenantId)
            ->with(['vendor:id,company_name,vendor_code', 'assignee:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['source_kind'] ?? null, fn ($q, $k) => $q->where('source_kind', $k))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when(($filters['overdue'] ?? null) === '1' || ($filters['overdue'] ?? null) === true,
                fn ($q) => $q->whereNotNull('due_date')->whereDate('due_date', '<', now())->where('status', '!=', 'Verified'))
            ->latest('id')
            ->get();
    }

    public function create(array $data, int $tenantId, int $userId): TpvCapa
    {
        $data = $this->resolveSource($data);

        $capa = TpvCapa::create([
            ...$data,
            'tenant_id' => $tenantId,
            'raised_by' => $userId,
            'status'    => $data['status'] ?? 'Open',
        ]);

        $capa->recordAudit('CAPA Raised', null, null, [
            'reference' => $capa->reference, 'source_kind' => $capa->source_kind,
        ]);

        Log::channel('tpv')->info('TPV CAPA raised', [
            'capa_id' => $capa->id, 'tenant_id' => $tenantId, 'reference' => $capa->reference,
        ]);

        return $capa->load('vendor:id,company_name,vendor_code', 'assignee:id,name');
    }

    /**
     * Raise a CAPA directly from another governance record (NCR, inspection
     * finding, meeting issue, violation, renewal). A convenience for callers
     * that hold the source model — sets the kind and polymorphic pointer.
     */
    public function raiseFrom(string $kind, int $sourceId, array $data, int $tenantId, ?int $userId, ?int $vendorId = null): TpvCapa
    {
        return $this->create([
            ...$data,
            'source_kind' => $kind,
            'source_id'   => $sourceId,
            'source_type' => CapaSource::classFor($kind),
            'vendor_id'   => $vendorId ?? ($data['vendor_id'] ?? null),
        ], $tenantId, (int) ($userId ?? 0));
    }

    public function update(TpvCapa $capa, array $data): TpvCapa
    {
        $data = $this->resolveSource($data);
        $capa->update($data);

        return $capa->load('vendor:id,company_name,vendor_code', 'assignee:id,name');
    }

    /**
     * Advance the lifecycle. Done stamps completion; Verified is terminal and
     * refuses to close without evidence (Rule 12 — no CAPA closed on trust).
     */
    public function transition(TpvCapa $capa, string $to, User $actor, ?string $remarks = null): TpvCapa
    {
        if (! in_array($to, CapaSource::STATUSES, true)) {
            throw new BusinessException("Unknown CAPA status: {$to}.");
        }
        if ($to === 'Verified' && empty($capa->evidence_path)) {
            throw new BusinessException('Attach closure evidence before verifying this CAPA.');
        }

        $from = $capa->status;
        $patch = ['status' => $to];

        if (in_array($to, ['Done', 'Verified'], true)) {
            $patch['completed_at'] = $capa->completed_at ?? now();
        } else {
            $patch['completed_at'] = null;
        }
        if ($to === 'Verified') {
            $patch['verified_at'] = now();
            $patch['verified_by'] = $actor->id;
            if ($remarks) {
                $patch['verification_notes'] = $remarks;
            }
        }

        $capa->update($patch);
        $capa->recordAudit('CAPA '.$to, $actor, $remarks, ['from' => $from, 'to' => $to]);

        return $capa->load('vendor:id,company_name,vendor_code', 'assignee:id,name');
    }

    public function delete(TpvCapa $capa): void
    {
        $capa->delete();
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => TpvCapa::forTenant($tenantId);

        return [
            'total'       => $base()->count(),
            'open'        => $base()->whereIn('status', ['Open', 'In_Progress'])->count(),
            'done'        => $base()->where('status', 'Done')->count(),
            'verified'    => $base()->where('status', 'Verified')->count(),
            'overdue'     => $base()->whereNotNull('due_date')->whereDate('due_date', '<', now())
                ->where('status', '!=', 'Verified')->count(),
        ];
    }

    /**
     * Normalise the origin: derive the polymorphic class from source_kind when a
     * source_id is supplied, and blank the pointer for standalone/manual CAPAs.
     */
    private function resolveSource(array $data): array
    {
        if (! array_key_exists('source_kind', $data)) {
            return $data;
        }

        $kind = $data['source_kind'] ?: 'manual';
        $data['source_kind'] = $kind;

        if (! empty($data['source_id']) && CapaSource::classFor($kind)) {
            $data['source_type'] = CapaSource::classFor($kind);
        } else {
            $data['source_type'] = null;
            $data['source_id'] = null;
        }

        return $data;
    }
}
