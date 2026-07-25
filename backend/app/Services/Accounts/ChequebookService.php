<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Accounts\Chequebook;
use Illuminate\Support\Facades\Log;

/**
 * Chequebook inventory management (spec §1). Owns the lifecycle of a physical
 * book — onboarding it with its serial range, tracking leaf consumption, and
 * retiring it — plus the inventory metrics that drive the Cheques dashboard.
 * The actual leaf allocation lives on the model ({@see Chequebook::allocateNext})
 * so it can run inside the issuing cheque's transaction.
 */
class ChequebookService
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function list(int $tenantId, array $filters = [])
    {
        $query = Chequebook::forTenant($tenantId)->with('bankAccount:id,bank_name,account_no');

        if (! empty($filters['bank_account_id'])) {
            $query->where('bank_account_id', $filters['bank_account_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('id')->get();
    }

    /** Inventory counters for the dashboard (spec §1 — books & leaves). */
    public function summary(int $tenantId): array
    {
        $books = Chequebook::forTenant($tenantId)->get();
        $active = $books->where('status', 'active');

        return [
            'total_books'      => $books->count(),
            'active_books'     => $active->count(),
            'total_leaves'     => (int) $books->sum('total_leaves'),
            'available_leaves' => (int) $active->sum(fn ($b) => $b->leaves_available),
            'used_leaves'      => (int) $books->sum(fn ($b) => $b->leaves_used),
        ];
    }

    public function create(array $data, int $tenantId, ?int $userId): Chequebook
    {
        $start = (int) $data['start_no'];
        $end   = (int) $data['end_no'];
        if ($end < $start) {
            throw new BusinessException('The end cheque number must be greater than or equal to the start number.');
        }

        // Zero-pad width comes from the widest number the user typed (000001 → 6).
        $digits = max(strlen((string) ($data['start_raw'] ?? $start)), strlen((string) ($data['end_raw'] ?? $end)), 1);

        $book = Chequebook::create([
            'tenant_id'       => $tenantId,
            'bank_account_id' => $data['bank_account_id'],
            'name'            => $data['name'],
            'prefix'          => $data['prefix'] ?? null,
            'start_no'        => $start,
            'end_no'          => $end,
            'next_no'         => $start,
            'digits'          => $digits,
            'total_leaves'    => $end - $start + 1,
            'status'          => 'active',
            'notes'           => $data['notes'] ?? null,
            'created_by'      => $userId,
        ]);

        $this->audit->log($tenantId, $userId, 'chequebook', $book->id, 'create', after: $book->toArray());
        Log::channel('accounts')->info('Chequebook onboarded', ['chequebook_id' => $book->id, 'tenant_id' => $tenantId]);

        return $book->load('bankAccount:id,bank_name,account_no');
    }

    public function update(Chequebook $book, array $data, int $tenantId, ?int $userId): Chequebook
    {
        $this->assert($book, $tenantId);
        $before = $book->toArray();

        // Only descriptive fields are editable once a book exists — the serial
        // range is immutable because leaves may already have been issued.
        $book->update(array_intersect_key($data, array_flip(['name', 'prefix', 'notes'])));

        $this->audit->log($tenantId, $userId, 'chequebook', $book->id, 'update', $before, $book->fresh()->toArray());
        return $book->load('bankAccount:id,bank_name,account_no');
    }

    /** Retire a book so no further leaves can be drawn from it. */
    public function close(Chequebook $book, int $tenantId, ?int $userId): Chequebook
    {
        $this->assert($book, $tenantId);
        $book->update(['status' => 'closed']);
        $this->audit->log($tenantId, $userId, 'chequebook', $book->id, 'update', null, ['status' => 'closed']);
        return $book;
    }

    public function delete(Chequebook $book, int $tenantId, ?int $userId): void
    {
        $this->assert($book, $tenantId);

        if ($book->cheques()->exists()) {
            throw new BusinessException('This chequebook has cheques issued from it and cannot be deleted. Close it instead.');
        }

        $book->delete();
        $this->audit->log($tenantId, $userId, 'chequebook', $book->id, 'update', null, null);
    }

    private function assert(Chequebook $book, int $tenantId): void
    {
        if ($book->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
