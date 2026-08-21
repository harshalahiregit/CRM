<?php

namespace App\Services\Tpv;

use App\Models\Tpv\ComplianceEvidence;
use App\Models\Tpv\TpvCapa;
use App\Models\Tpv\TpvNcr;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorDocument;
use Illuminate\Support\Carbon;

/**
 * Unified TPV Document Vault (Sangoe TPV §30). Read-only aggregator that gives a
 * single lens over documents scattered across four stores — statutory vendor
 * documents, the compliance-evidence locker, and the closure evidence attached
 * to CAPAs and NCRs — normalised to one row shape with a computed expiry state.
 *
 * Purely additive: it never writes. Each store keeps its own write path; the
 * vault only reads and reconciles them for visibility and expiry tracking.
 */
class TpvDocumentVaultService
{
    /** Days-out that counts a document as "expiring soon". */
    public const EXPIRING_WINDOW_DAYS = 30;

    public const SOURCES = ['Statutory', 'Evidence', 'CAPA', 'NCR'];

    public const EXPIRY_STATES = ['valid', 'expiring', 'expired', 'none'];

    /**
     * Tenant-wide (or vendor-scoped) unified document list. Filters: source,
     * vendor_id, expiry (one of EXPIRY_STATES), q (title contains).
     */
    public function roster(int $tenantId, array $filters = []): array
    {
        $vendorId = $filters['vendor_id'] ?? null;
        $rows = array_merge(
            $this->statutory($tenantId, $vendorId),
            $this->evidence($tenantId, $vendorId),
            $this->actionEvidence($tenantId, $vendorId, 'CAPA'),
            $this->actionEvidence($tenantId, $vendorId, 'NCR'),
        );

        if (! empty($filters['source'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['source'] === $filters['source']));
        }
        if (! empty($filters['expiry'])) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['expiry_state'] === $filters['expiry']));
        }
        if (! empty($filters['q'])) {
            $q = mb_strtolower($filters['q']);
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['title'].' '.$r['vendor_name'].' '.$r['reference']), $q)));
        }

        // Most-urgent first: expired, then expiring, then by upload recency.
        $order = ['expired' => 0, 'expiring' => 1, 'valid' => 2, 'none' => 3];
        usort($rows, function ($a, $b) use ($order) {
            $c = ($order[$a['expiry_state']] ?? 9) <=> ($order[$b['expiry_state']] ?? 9);
            return $c !== 0 ? $c : strcmp((string) ($b['uploaded_at'] ?? ''), (string) ($a['uploaded_at'] ?? ''));
        });

        $this->fillUploaderNames($tenantId, $rows);

        return $rows;
    }

    /** One vendor's vault, grouped by source, plus that vendor's own summary. */
    public function vendorVault(int $tenantId, int $vendorId): array
    {
        $rows = $this->roster($tenantId, ['vendor_id' => $vendorId]);
        $grouped = [];
        foreach (self::SOURCES as $s) {
            $grouped[$s] = array_values(array_filter($rows, fn ($r) => $r['source'] === $s));
        }

        return ['data' => $rows, 'grouped' => $grouped, 'summary' => $this->bucketise($rows)];
    }

    /** Tenant-wide counts by source + expiry buckets, and the expiring/expired list. */
    public function summary(int $tenantId): array
    {
        $rows = $this->roster($tenantId);
        $bySource = [];
        foreach (self::SOURCES as $s) {
            $bySource[$s] = count(array_filter($rows, fn ($r) => $r['source'] === $s));
        }

        $attention = array_values(array_filter($rows, fn ($r) => in_array($r['expiry_state'], ['expired', 'expiring'], true)));

        return [
            'total'     => count($rows),
            'by_source' => $bySource,
            'expiry'    => $this->bucketise($rows),
            'attention' => array_slice($attention, 0, 25),
        ];
    }

    /* ── Source adapters ────────────────────────────────────────────────── */

    private function statutory(int $tenantId, ?int $vendorId): array
    {
        return VendorDocument::forTenant($tenantId)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->with('vendor:id,company_name,vendor_code')
            ->get()
            ->map(fn (VendorDocument $d) => $this->row(
                source: 'Statutory',
                id: $d->id,
                reference: $d->type_label,
                title: $d->type_label,
                vendor: $d->vendor,
                file: $d->file_path,
                status: $d->status_label,
                uploaderId: $d->reviewed_by,
                uploadedAt: $d->created_at,
                expiresAt: $d->expires_at,
            ))->all();
    }

    private function evidence(int $tenantId, ?int $vendorId): array
    {
        return ComplianceEvidence::forTenant($tenantId)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->with('vendor:id,company_name,vendor_code')
            ->get()
            ->map(fn (ComplianceEvidence $e) => $this->row(
                source: 'Evidence',
                id: $e->id,
                reference: $e->category,
                title: $e->title,
                vendor: $e->vendor,
                file: $e->file_url,
                status: $e->category,
                uploaderId: $e->uploaded_by,
                uploadedAt: $e->created_at,
                expiresAt: $e->valid_until,
            ))->all();
    }

    private function actionEvidence(int $tenantId, ?int $vendorId, string $kind): array
    {
        $model = $kind === 'CAPA' ? TpvCapa::class : TpvNcr::class;

        return $model::forTenant($tenantId)
            ->whereNotNull('evidence_path')
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->with('vendor:id,company_name,vendor_code')
            ->get()
            ->map(fn ($m) => $this->row(
                source: $kind,
                id: $m->id,
                reference: $m->reference,
                title: $m->title,
                vendor: $m->vendor,
                file: $m->evidence_path,
                status: $m->status,
                uploaderId: $m->raised_by,
                uploadedAt: $m->created_at,
                expiresAt: null,
            ))->all();
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    private function row(string $source, int $id, ?string $reference, ?string $title, $vendor, ?string $file, ?string $status, $uploaderId, $uploadedAt, $expiresAt): array
    {
        $expires = $expiresAt ? Carbon::parse($expiresAt) : null;

        return [
            'key'          => strtolower($source).'-'.$id,
            'source'       => $source,
            'id'           => $id,
            'reference'    => $reference ?? '',
            'title'        => $title ?? '',
            'vendor_id'    => $vendor?->id,
            'vendor_name'  => $vendor?->company_name ?? '—',
            'vendor_code'  => $vendor?->vendor_code,
            'file'         => $file,
            'has_file'     => ! empty($file),
            'status'       => $status,
            'uploaded_by'  => $uploaderId,
            'uploaded_by_name' => null,
            'uploaded_at'  => $uploadedAt ? Carbon::parse($uploadedAt)->toDateTimeString() : null,
            'expires_at'   => $expires?->toDateString(),
            'expiry_state' => $this->expiryState($expires),
        ];
    }

    private function expiryState(?Carbon $expires): string
    {
        if (! $expires) {
            return 'none';
        }
        if ($expires->isPast()) {
            return 'expired';
        }
        if ($expires->lte(now()->addDays(self::EXPIRING_WINDOW_DAYS))) {
            return 'expiring';
        }

        return 'valid';
    }

    private function bucketise(array $rows): array
    {
        $b = ['valid' => 0, 'expiring' => 0, 'expired' => 0, 'none' => 0];
        foreach ($rows as $r) {
            $b[$r['expiry_state']] = ($b[$r['expiry_state']] ?? 0) + 1;
        }

        return $b;
    }

    /** Resolve uploader ids to names in one query, in place. */
    private function fillUploaderNames(int $tenantId, array &$rows): void
    {
        $ids = array_values(array_unique(array_filter(array_column($rows, 'uploaded_by'))));
        if ($ids === []) {
            return;
        }
        $names = User::whereIn('id', $ids)->pluck('name', 'id');
        foreach ($rows as &$r) {
            $r['uploaded_by_name'] = $r['uploaded_by'] ? ($names[$r['uploaded_by']] ?? null) : null;
        }
    }
}
