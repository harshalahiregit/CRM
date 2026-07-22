<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\ContractType;
use App\Models\Sales\SalesContract;
use App\Repositories\Sales\SalesContractRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractService
{
    public function __construct(
        private SalesContractRepository $contractRepository,
        private ContentPageService $contentPages,
    ) {
    }

    public function list(int $tenantId, array $filters)
    {
        return $this->contractRepository->filtered($tenantId, $filters);
    }

    public function expiringSoon(int $tenantId, int $days = 30)
    {
        return $this->contractRepository->expiringSoon($tenantId, $days);
    }

    public function create(array $data, int $tenantId, int $userId): SalesContract
    {
        $contract = DB::transaction(function () use ($data, $tenantId, $userId) {
            $pages = $data['pages'] ?? null;
            unset($data['pages']);
            $contract = SalesContract::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $userId,
            ]);
            if (is_array($pages)) {
                $this->contentPages->syncPages($contract, $pages, $tenantId);
            }
            $contract->logActivity('created', "Contract \"{$contract->subject}\" created", null, null, $userId);
            return $contract;
        });

        Log::channel('sales')->info('Contract created', ['contract_id' => $contract->id, 'tenant_id' => $tenantId]);

        return $this->loadFull($contract);
    }

    public function show(SalesContract $contract, int $tenantId): SalesContract
    {
        $this->assertTenant($contract, $tenantId);
        return $this->loadFull($contract);
    }

    public function update(SalesContract $contract, array $data, int $tenantId): SalesContract
    {
        $this->assertTenant($contract, $tenantId);
        $pages = $data['pages'] ?? null;
        $hasPages = array_key_exists('pages', $data);
        unset($data['pages']);
        $contract->update($data);
        if ($hasPages) {
            $this->contentPages->syncPages($contract, $pages ?? [], $tenantId);
        }
        return $this->loadFull($contract->fresh());
    }

    public function delete(SalesContract $contract, int $tenantId): void
    {
        $this->assertTenant($contract, $tenantId);
        $contract->delete();
    }

    public function updateStatus(SalesContract $contract, string $status, int $tenantId, int $userId): SalesContract
    {
        $this->assertTenant($contract, $tenantId);
        $contract->update(['status' => $status]);
        $contract->logActivity('status_changed', "Contract status → {$status}", null, $status, $userId);
        return $this->loadFull($contract->fresh());
    }

    /**
     * Renew a contract: create a new version copying its terms, link back via
     * renewed_from_id, and mark the old one renewed. Mirrors old-CRM renewal.
     */
    public function renew(SalesContract $contract, array $data, int $tenantId, int $userId): SalesContract
    {
        $this->assertTenant($contract, $tenantId);

        return DB::transaction(function () use ($contract, $data, $tenantId, $userId) {
            $new = SalesContract::create([
                'tenant_id'          => $tenantId,
                'subject'            => $contract->subject,
                'client_id'          => $contract->client_id,
                'contract_type_id'   => $contract->contract_type_id,
                'value'              => $data['value'] ?? $contract->value,
                'currency'           => $contract->currency,
                'start_date'         => $data['start_date'] ?? now()->toDateString(),
                'end_date'           => $data['end_date'] ?? null,
                'description'        => $contract->description,
                'status'             => 'active',
                'renewed_from_id'    => $contract->id,
                'renewal_notice_days'=> $contract->renewal_notice_days,
                'version'            => $contract->version + 1,
                'created_by'         => $userId,
            ]);

            $contract->update(['status' => 'renewed', 'is_renewed' => true]);
            $contract->logActivity('renewed', "Renewed as {$new->reference_no}", null, null, $userId);
            $new->logActivity('created', "Renewed from {$contract->reference_no}", null, null, $userId);

            Log::channel('sales')->info('Contract renewed', [
                'contract_id' => $contract->id, 'new_id' => $new->id, 'tenant_id' => $tenantId,
            ]);

            return $this->loadFull($new);
        });
    }

    /**
     * Internal (staff-side) signing. $sig: method draw|type|upload, image
     * (data URL), name. IP/user-agent are captured server-side for the
     * audit trail — never trusted from the payload.
     */
    public function sign(SalesContract $contract, array $sig, int $tenantId, ?int $userId, ?string $ip, ?string $userAgent): SalesContract
    {
        $this->assertTenant($contract, $tenantId);

        $contract->update([
            'signature_data' => json_encode([
                'method'     => $sig['method'] ?? 'draw',
                'image'      => $sig['image'] ?? null,
                'name'       => $sig['name'],
                'email'      => $sig['email'] ?? null,
                'ip'         => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'at'         => now()->toIso8601String(),
            ]),
            'signed_by_name' => $sig['name'],
            'signed_at'      => now(),
            'status'         => $contract->status === 'draft' ? 'active' : $contract->status,
        ]);
        $contract->logActivity('updated', "Contract signed by {$sig['name']}", null, null, $userId);

        return $this->loadFull($contract->fresh());
    }

    /* ── Public portal (token-scoped, unauthenticated) ─────────── */

    public function findByPublicToken(string $token): SalesContract
    {
        $contract = SalesContract::where('public_token', $token)->first();
        if (! $contract) {
            throw new \App\Exceptions\ResourceNotFoundException('Contract');
        }

        return $contract;
    }

    /** Whitelisted public payload — no internal fields. */
    public function publicPayload(SalesContract $contract): array
    {
        $contract->loadMissing(['pages', 'type:id,name', 'client:id,company']);
        $sig = $contract->signature_data ? json_decode($contract->signature_data, true) : null;

        return [
            'reference_no' => $contract->reference_no,
            'subject'      => $contract->subject,
            'type'         => $contract->type?->name,
            'client'       => $contract->client?->company,
            'value'        => $contract->value,
            'currency'     => $contract->currency,
            'start_date'   => $contract->start_date?->toDateString(),
            'end_date'     => $contract->end_date?->toDateString(),
            'description'  => $contract->description,
            'status'       => $contract->status,
            'signed_at'    => $contract->signed_at,
            'signed_by'    => $contract->signed_by_name,
            'signature_image' => $sig['image'] ?? null,
            'pages'        => $contract->pages->map(fn ($pg) => ['title' => $pg->title, 'content' => $pg->content])->values(),
            'comments'     => $contract->comments()->with('author:id,name')->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'author' => $c->author?->name ?? $c->author_name ?? 'Client',
                    'is_staff' => (bool) $c->user_id,
                    'body' => $c->body,
                    'at' => $c->created_at?->toDateTimeString(),
                ])->values(),
        ];
    }

    /** Client acceptance via the public link. LOCKS after the first signature. */
    public function publicSign(SalesContract $contract, array $sig, ?string $ip, ?string $userAgent): SalesContract
    {
        return DB::transaction(function () use ($contract, $sig, $ip, $userAgent) {
            $locked = SalesContract::whereKey($contract->id)->lockForUpdate()->first();
            if ($locked->signed_at) {
                throw new \App\Exceptions\BusinessException('This contract has already been signed.', 409);
            }

            return $this->sign($locked, $sig, $locked->tenant_id, null, $ip, $userAgent);
        });
    }

    /* ── Comments (discussion thread) ──────────────────────────── */

    public function addComment(SalesContract $contract, string $body, int $tenantId, int $userId)
    {
        $this->assertTenant($contract, $tenantId);

        return $contract->comments()->create([
            'tenant_id' => $tenantId,
            'user_id'   => $userId,
            'body'      => $body,
        ])->load('author:id,name');
    }

    /** Client-side comment from the public page (old-CRM discussion parity). */
    public function addPublicComment(SalesContract $contract, string $authorName, string $body)
    {
        return $contract->comments()->create([
            'tenant_id'   => $contract->tenant_id,
            'user_id'     => null,
            'author_name' => mb_substr(trim($authorName) !== '' ? trim($authorName) : 'Client', 0, 120),
            'body'        => $body,
        ]);
    }

    public function deleteComment(SalesContract $contract, int $commentId, int $tenantId, int $userId): void
    {
        $this->assertTenant($contract, $tenantId);
        $comment = $contract->comments()->whereKey($commentId)->firstOrFail();
        abort_if($comment->user_id !== $userId, 403, 'Only the author can delete a comment.');
        $comment->delete();
    }

    /* ── Contract Types ─────────────────────────────────────────── */
    public function types(int $tenantId)
    {
        return ContractType::forTenant($tenantId)->orderBy('name')->get();
    }

    public function createType(string $name, int $tenantId): ContractType
    {
        return ContractType::create(['tenant_id' => $tenantId, 'name' => $name]);
    }

    public function deleteType(ContractType $type, int $tenantId): void
    {
        if ($type->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
        $type->delete();
    }

    /* ── Helpers ────────────────────────────────────────────────── */
    private function loadFull(SalesContract $contract): SalesContract
    {
        // refresh() re-hydrates DB-default columns (reference_no, version) that
        // aren't set in the insert payload before returning.
        return $contract->refresh()->load([
            'client:id,company', 'type:id,name', 'creator:id,name',
            'renewedFrom:id,reference_no', 'pages', 'comments.author:id,name',
        ]);
    }

    private function assertTenant(SalesContract $contract, int $tenantId): void
    {
        if ($contract->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
