<?php

namespace App\Services\Contract;

use App\Exceptions\BusinessException;
use App\Models\Contract\Contract;
use App\Models\Contract\ContractCategory;
use App\Models\Contract\ContractDiscussion;
use App\Models\Contract\ContractLink;
use App\Support\Contract\ContractStatus;
use Illuminate\Support\Facades\DB;

/**
 * The Contract module's own service.
 *
 * Everything here works on this module's tables. Where it needs a customer or a
 * vendor it stores a morph pointer and a snapshot of the name — it never writes
 * to Sales, Purchase or TPV, and never queries their tables directly.
 */
class ContractService
{
    /** Counterparty kinds this module may point at, as stable API keys. */
    public const PARTY_TYPES = [
        'customer'        => \App\Models\Customer\Client::class,
        'vendor'          => \App\Models\Vendor\Vendor::class,
        'purchase_vendor' => \App\Models\Purchase\PurchaseVendor::class,
    ];

    /** The display field on each counterparty, for the name snapshot. */
    private const PARTY_NAME_FIELD = [
        'customer'        => 'company',
        'vendor'          => 'company_name',
        'purchase_vendor' => 'company_name',
    ];

    public function __construct(private ContractSigningService $signing) {}

    /* ── Listing & stats ────────────────────────────────────────── */

    public function list(int $tenantId, array $filters = [])
    {
        $q = Contract::forTenant($tenantId)
            ->with(['category:id,name', 'signatures', 'creator:id,name']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['category_id'])) {
            $q->where('contract_category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $q->where(fn ($x) => $x->where('title', 'like', "%{$s}%")
                ->orWhere('reference_no', 'like', "%{$s}%")
                ->orWhere('party_name', 'like', "%{$s}%"));
        }
        if (! empty($filters['expiring'])) {
            $q->expiringSoon();
        }
        // "Every contract with this customer" -- the same question
        // /contracts/for/{type}/{id} answers, but asked through the list so it
        // composes with the status, category and search filters beside it.
        if (! empty($filters['party_type']) && ! empty($filters['party_id'])) {
            $class = self::PARTY_TYPES[$filters['party_type']] ?? null;
            if (! $class) {
                throw new BusinessException('Unknown counterparty type.', 422);
            }
            $q->where('party_type', $class)->where('party_id', (int) $filters['party_id']);
        }

        return $q->orderByDesc('created_at')->get();
    }

    /** The dashboard's top-line numbers. */
    public function stats(int $tenantId): array
    {
        $base = fn () => Contract::forTenant($tenantId);

        return [
            'total'        => $base()->count(),
            'draft'        => $base()->where('status', ContractStatus::DRAFT)->count(),
            'awaiting'     => $base()->whereIn('status', [ContractStatus::SENT])->count(),
            // "Signed" on the dashboard means fully executed. Counting one-sided
            // signatures here would overstate how much is actually agreed.
            'signed'       => $base()->whereNotNull('fully_signed_at')->count(),
            'active'       => $base()->where('status', ContractStatus::ACTIVE)->count(),
            'expiring'     => $base()->expiringSoon()->count(),
            'total_value'  => (float) $base()->whereIn('status', ContractStatus::OPEN)->sum('value'),
            'by_category'  => Contract::forTenant($tenantId)
                ->selectRaw('contract_category_id, count(*) c')
                ->groupBy('contract_category_id')
                ->with('category:id,name')
                ->get()
                ->map(fn ($r) => [
                    'category' => $r->category?->name ?? 'Uncategorised',
                    'count'    => (int) $r->c,
                ])->values()->all(),
        ];
    }

    public function find(int $id, int $tenantId): Contract
    {
        $c = Contract::forTenant($tenantId)
            ->with(['category:id,name', 'pages', 'signatures', 'attachments',
                'links', 'discussions.author:id,name', 'creator:id,name', 'party'])
            ->find($id);

        if (! $c) {
            throw new BusinessException('Contract not found.', 404);
        }

        return $c;
    }

    /* ── Create & update ────────────────────────────────────────── */

    public function create(array $data, int $tenantId, ?int $userId): Contract
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $contract = Contract::create($this->attributes($data, $tenantId) + [
                'created_by' => $userId,
                'status'     => $data['status'] ?? ContractStatus::DRAFT,
            ]);

            $this->syncPages($contract, $data['pages'] ?? []);
            $this->syncLinks($contract, $data['links'] ?? [], $userId);

            return $this->find($contract->id, $tenantId);
        });
    }

    public function update(Contract $contract, array $data, int $tenantId, ?int $userId): Contract
    {
        $this->assertTenant($contract, $tenantId);

        // Once both sides have signed, the words are the agreement. Editing the
        // terms underneath two signatures would make the signed PDF and the
        // record say different things.
        if ($contract->fully_signed_at && array_key_exists('pages', $data)) {
            throw new BusinessException(
                'This contract is signed by both parties — its terms can no longer be edited. '
                .'Create a renewal or a new version instead.', 422);
        }

        return DB::transaction(function () use ($contract, $data, $tenantId, $userId) {
            $contract->update($this->attributes($data, $tenantId, $contract));

            if (array_key_exists('pages', $data)) {
                $this->syncPages($contract, $data['pages'] ?? []);
            }
            if (array_key_exists('links', $data)) {
                $this->syncLinks($contract, $data['links'] ?? [], $userId);
            }

            return $this->find($contract->id, $tenantId);
        });
    }

    /** Only the keys that were actually sent, so a partial update stays partial. */
    private function attributes(array $data, int $tenantId, ?Contract $existing = null): array
    {
        $out = ['tenant_id' => $tenantId];

        foreach (['title', 'description', 'contract_category_id', 'value', 'currency',
            'start_date', 'end_date', 'renewal_notice_days', 'party_email'] as $k) {
            if (array_key_exists($k, $data)) {
                // The description is composed in the same rich editor as the
                // terms, so it arrives as browser HTML and is printed into the
                // PDF unescaped. Same allowlist pass as the pages.
                $out[$k] = $k === 'description'
                    ? \App\Support\HtmlSanitizer::clean($data[$k])
                    : $data[$k];
            }
        }

        if (array_key_exists('party_type', $data)) {
            // array_merge, not `+=`. Union keeps the LEFT operand's keys, so the
            // null this loop had already written for `party_email` silently beat
            // the address resolveParty had just looked up -- the fallback could
            // never fire for any client that sends the key at all. resolveParty
            // already prefers what the caller sent, so letting it win is right.
            $out = array_merge($out, $this->resolveParty($data, $tenantId));
        }

        if (array_key_exists('status', $data) && ContractStatus::isValid($data['status'])) {
            $out['status'] = $data['status'];
        }

        return $out;
    }

    /**
     * Point at a customer or vendor, and snapshot their name.
     *
     * The snapshot matters: the agreement was with the name printed on the page.
     * If the linked record is later renamed or removed, the contract must still
     * print what was actually agreed rather than a blank or a new name.
     */
    private function resolveParty(array $data, int $tenantId): array
    {
        $key = $data['party_type'] ?? null;

        if (! $key) {
            return ['party_type' => null, 'party_id' => null];
        }

        if (! isset(self::PARTY_TYPES[$key])) {
            throw new BusinessException('Unknown counterparty type.', 422);
        }

        $class = self::PARTY_TYPES[$key];
        $model = $class::forTenant($tenantId)->find($data['party_id'] ?? null);

        if (! $model) {
            throw new BusinessException('That customer or vendor was not found.', 404);
        }

        // `?:`, not `??`. The form posts party_name: '' and party_email: '' from
        // an untouched field, and `??` only catches null -- so a caller sending
        // an id with a blank name saved a blank counterparty, which prints as a
        // blank line on the PDF. An empty string here means "I did not supply
        // one", which is exactly what the fallback is for.
        return [
            'party_type'  => $class,
            'party_id'    => $model->id,
            'party_name'  => ($data['party_name'] ?? null) ?: ($model->{self::PARTY_NAME_FIELD[$key]} ?? null),
            'party_email' => ($data['party_email'] ?? null) ?: $this->partyEmail($key, $model),
        ];
    }

    /**
     * Where each kind of counterparty keeps the address you would write to.
     *
     * A customer does not have one. `clients` has no email column at all -- the
     * address belongs to a contact person, because a company does not sign
     * anything, a named human does. Reading `$model->email` here therefore
     * returned nothing for the commonest party type, and the signing request
     * opened with an empty To field.
     */
    private function partyEmail(string $key, $model): ?string
    {
        if ($key !== 'customer') {
            return $model->email ?? null;
        }

        return $model->primaryContact?->email
            ?: $model->contacts()->orderByDesc('is_primary')->value('email');
    }

    /** Replace the page set, keeping the order the client sent. */
    private function syncPages(Contract $contract, array $pages): void
    {
        $contract->pages()->delete();

        foreach (array_values($pages) as $i => $p) {
            if (trim((string) ($p['content'] ?? '')) === '' && trim((string) ($p['title'] ?? '')) === '') {
                continue;   // an empty page is not a page
            }
            $contract->pages()->create([
                'tenant_id'  => $contract->tenant_id,
                'sort_order' => $i,
                'title'      => $p['title'] ?? null,
                // Terms are composed in a rich editor now, so this is HTML from
                // a browser. It is printed into the PDF with {!! !!} and shown
                // on the counterparty's page, which makes an allowlist pass
                // mandatory rather than tidy -- an unsanitised <script> or a
                // remote <img> would ride into every copy of the contract.
                'content'    => \App\Support\HtmlSanitizer::clean($p['content'] ?? null),
            ]);
        }
    }

    /** Soft links out to tasks, notes and projects. */
    private function syncLinks(Contract $contract, array $links, ?int $userId): void
    {
        $contract->links()->delete();

        foreach ($links as $l) {
            if (empty($l['linkable_type']) || empty($l['linkable_id'])) {
                continue;
            }
            ContractLink::create([
                'tenant_id'     => $contract->tenant_id,
                'contract_id'   => $contract->id,
                'linkable_type' => (string) $l['linkable_type'],
                'linkable_id'   => (int) $l['linkable_id'],
                'label'         => $l['label'] ?? null,
                'created_by'    => $userId,
            ]);
        }
    }

    /* ── Status, renewal, discussion ────────────────────────────── */

    public function setStatus(Contract $contract, string $status, int $tenantId): Contract
    {
        $this->assertTenant($contract, $tenantId);

        if (! ContractStatus::isValid($status)) {
            throw new BusinessException('Unknown contract status.', 422);
        }

        $contract->update(['status' => $status]);

        return $this->find($contract->id, $tenantId);
    }

    /**
     * Renew: a NEW contract carrying the same terms, pointing back at the old.
     *
     * Not an edit of the original. The expired agreement is the record of what
     * was in force last year, and overwriting its dates would erase that.
     */
    public function renew(Contract $contract, array $data, int $tenantId, ?int $userId): Contract
    {
        $this->assertTenant($contract, $tenantId);

        return DB::transaction(function () use ($contract, $data, $tenantId, $userId) {
            $fresh = Contract::create([
                'tenant_id'            => $tenantId,
                'title'                => $data['title'] ?? $contract->title.' (Renewal)',
                'description'          => $contract->description,
                'contract_category_id' => $contract->contract_category_id,
                'party_type'           => $contract->party_type,
                'party_id'             => $contract->party_id,
                'party_name'           => $contract->party_name,
                'party_email'          => $contract->party_email,
                'value'                => $data['value'] ?? $contract->value,
                'currency'             => $contract->currency,
                'start_date'           => $data['start_date'] ?? null,
                'end_date'             => $data['end_date'] ?? null,
                'renewal_notice_days'  => $contract->renewal_notice_days,
                'renewed_from_id'      => $contract->id,
                'status'               => ContractStatus::DRAFT,
                'created_by'           => $userId,
            ]);

            // The terms carry over — a renewal that arrives with no clauses is
            // a blank page somebody has to retype.
            foreach ($contract->pages as $p) {
                $fresh->pages()->create([
                    'tenant_id' => $tenantId, 'sort_order' => $p->sort_order,
                    'title' => $p->title, 'content' => $p->content,
                ]);
            }

            return $this->find($fresh->id, $tenantId);
        });
    }

    public function comment(Contract $contract, string $body, int $tenantId, ?int $userId, ?string $guestName = null): ContractDiscussion
    {
        $this->assertTenant($contract, $tenantId);

        return ContractDiscussion::create([
            'tenant_id'   => $tenantId,
            'contract_id' => $contract->id,
            'user_id'     => $userId,
            'guest_name'  => $userId ? null : $guestName,
            'body'        => $body,
        ]);
    }

    /* ── Categories ─────────────────────────────────────────────── */

    public function categories(int $tenantId)
    {
        return ContractCategory::forTenant($tenantId)->orderBy('name')->get();
    }

    /** Created inline from the form's + button, so it must tolerate a repeat. */
    public function createCategory(array $data, int $tenantId, ?int $userId): ContractCategory
    {
        $name = trim($data['name']);

        $existing = ContractCategory::forTenant($tenantId)->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }

        return ContractCategory::create([
            'tenant_id'   => $tenantId,
            'name'        => $name,
            'description' => $data['description'] ?? null,
            'created_by'  => $userId,
        ]);
    }

    private function assertTenant(Contract $contract, int $tenantId): void
    {
        if ((int) $contract->tenant_id !== $tenantId) {
            throw new BusinessException('Contract not found.', 404);
        }
    }
}
