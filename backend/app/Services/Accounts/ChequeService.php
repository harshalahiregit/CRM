<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Accounts\Cheque;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Cheque register with lifecycle status and first-class PDC handling. A cheque
 * dated in the future (or explicitly flagged) is a PDC: it sits as `post_dated`
 * until its due date, when the scheduled job (accounts:pdc-due) surfaces it.
 *
 * Phase 2 keeps cheques as a register; auto-posting the bank leg on clearing is a
 * later-phase concern (it will go through PostingService), so status changes here
 * do not themselves write to the ledger.
 */
class ChequeService
{
    /** Allowed status transitions (kept deliberately permissive but guarded). */
    private const TRANSITIONS = [
        'post_dated' => ['issued', 'deposited', 'cancelled'],
        'issued'     => ['presented', 'deposited', 'cleared', 'bounced', 'cancelled'],
        'deposited'  => ['presented', 'cleared', 'bounced', 'cancelled'],
        'presented'  => ['cleared', 'bounced', 'cancelled'],
        'bounced'    => ['deposited', 'cancelled'],
        'cleared'    => [],
        'cancelled'  => [],
    ];

    public function __construct(private AuditLogger $audit)
    {
    }

    public function list(int $tenantId, array $filters)
    {
        $query = Cheque::forTenant($tenantId)
            ->with('bankAccount:id,bank_name')
            ->search($filters['search'] ?? null);

        foreach (['direction', 'status'] as $f) {
            if (! empty($filters[$f])) {
                $query->where($f, $filters[$f]);
            }
        }
        if (isset($filters['is_pdc']) && $filters['is_pdc'] !== '') {
            $query->where('is_pdc', (bool) $filters['is_pdc']);
        }
        if (! empty($filters['bank_account_id'])) {
            $query->where('bank_account_id', $filters['bank_account_id']);
        }

        return $query->orderByDesc('cheque_date')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 50));
    }

    public function summary(int $tenantId): array
    {
        $base = fn () => Cheque::forTenant($tenantId);
        return [
            'total'        => $base()->count(),
            'pending'      => $base()->whereIn('status', ['issued', 'deposited', 'presented', 'post_dated'])->count(),
            'pdc'          => $base()->where('is_pdc', true)->where('status', 'post_dated')->count(),
            'pdc_due'      => $base()->where('is_pdc', true)->where('status', 'post_dated')
                                ->whereDate('pdc_due_date', '<=', Carbon::today())->count(),
            'bounced'      => $base()->where('status', 'bounced')->count(),
        ];
    }

    public function create(array $data, int $tenantId, ?int $userId): Cheque
    {
        $chequeDate = $data['cheque_date'];
        $isPdc = (bool) ($data['is_pdc'] ?? false) || Carbon::parse($chequeDate)->isFuture();

        $cheque = Cheque::create([
            'tenant_id'       => $tenantId,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'voucher_id'      => $data['voucher_id'] ?? null,
            'direction'       => $data['direction'],
            'cheque_no'       => $data['cheque_no'] ?? null,
            'cheque_date'     => $chequeDate,
            'party_name'      => $data['party_name'] ?? null,
            'amount'          => $data['amount'] ?? 0,
            'status'          => $isPdc ? 'post_dated' : ($data['status'] ?? 'issued'),
            'is_pdc'          => $isPdc,
            'pdc_due_date'    => $isPdc ? ($data['pdc_due_date'] ?? $chequeDate) : null,
            'memo'            => $data['memo'] ?? null,
            'created_by'      => $userId,
        ]);

        $this->audit->log($tenantId, $userId, 'cheque', $cheque->id, 'create', after: $cheque->toArray());
        Log::channel('accounts')->info('Cheque recorded', ['cheque_id' => $cheque->id, 'tenant_id' => $tenantId]);

        return $cheque->load('bankAccount:id,bank_name');
    }

    public function update(Cheque $cheque, array $data, int $tenantId, ?int $userId): Cheque
    {
        $this->assert($cheque, $tenantId);
        $before = $cheque->toArray();
        $cheque->update(array_intersect_key($data, array_flip([
            'bank_account_id', 'cheque_no', 'cheque_date', 'party_name', 'amount', 'memo', 'pdc_due_date',
        ])));
        $this->audit->log($tenantId, $userId, 'cheque', $cheque->id, 'update', $before, $cheque->fresh()->toArray());
        return $cheque->load('bankAccount:id,bank_name');
    }

    public function changeStatus(Cheque $cheque, string $status, ?string $clearedDate, int $tenantId, ?int $userId): Cheque
    {
        $this->assert($cheque, $tenantId);

        $allowed = self::TRANSITIONS[$cheque->status] ?? [];
        if (! in_array($status, $allowed, true)) {
            throw new BusinessException("Cannot move a {$cheque->status} cheque to {$status}.");
        }

        $before = $cheque->toArray();
        $cheque->update([
            'status'       => $status,
            'cleared_date' => $status === 'cleared' ? ($clearedDate ?? Carbon::today()->toDateString()) : $cheque->cleared_date,
            'is_pdc'       => $status === 'post_dated' ? $cheque->is_pdc : ($cheque->status === 'post_dated' ? false : $cheque->is_pdc),
        ]);

        $this->audit->log($tenantId, $userId, 'cheque', $cheque->id, 'update', $before, $cheque->fresh()->toArray());
        Log::channel('accounts')->info('Cheque status changed', ['cheque_id' => $cheque->id, 'from' => $before['status'], 'to' => $status]);

        return $cheque;
    }

    public function delete(Cheque $cheque, int $tenantId, ?int $userId): void
    {
        $this->assert($cheque, $tenantId);
        $cheque->delete();
        $this->audit->log($tenantId, $userId, 'cheque', $cheque->id, 'update', null, null);
    }

    /** PDCs whose due date has arrived (for the scheduled job and the "due" UI list). */
    public function duePdcs(int $tenantId, ?string $asOf = null)
    {
        $asOf = $asOf ?: Carbon::today()->toDateString();
        return Cheque::forTenant($tenantId)
            ->where('is_pdc', true)->where('status', 'post_dated')
            ->whereDate('pdc_due_date', '<=', $asOf)
            ->orderBy('pdc_due_date')->get();
    }

    private function assert(Cheque $cheque, int $tenantId): void
    {
        if ($cheque->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
