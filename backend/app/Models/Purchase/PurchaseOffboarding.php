<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Purchase offboarding/closure workflow — mirror of TpvOffboarding (parity). */
class PurchaseOffboarding extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_offboardings';

    public const FINAL_STATUSES = ['Closed', 'Replaced', 'Suspended', 'Blacklisted'];

    /** The default exit checklist (key → label), tuned for supplier closure. */
    public const CHECKLIST = [
        'contract_closure' => 'Contract closed',
        'open_orders_settled' => 'Open orders settled',
        'goods_returns_cleared' => 'Goods / returns cleared',
        'invoices_settled' => 'Invoices settled',
        'documents_archived' => 'Documents archived',
        'open_actions_closed' => 'Open actions closed',
        'ncr_capa_closure' => 'NCR / CAPA closed',
        'financial_closure' => 'Financial closure',
        'catalog_delisted' => 'Catalog items delisted',
        'final_performance_review' => 'Final performance review',
    ];

    protected $fillable = [
        'tenant_id', 'reference', 'purchase_vendor_id', 'reason', 'checklist', 'status',
        'final_status', 'completed_at', 'completed_by', 'lessons_learned', 'created_by',
    ];

    protected $casts = [
        'checklist' => 'array',
        'completed_at' => 'datetime',
    ];

    protected $appends = ['progress'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseOffboarding $o) {
            if (empty($o->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $o->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $o->reference = 'POFF-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function getProgressAttribute(): int
    {
        $items = $this->checklist ?? [];
        if (empty($items)) {
            return 0;
        }
        $done = collect($items)->where('done', true)->count();

        return (int) round($done / count($items) * 100);
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public static function defaultChecklist(): array
    {
        return collect(self::CHECKLIST)->map(fn ($label, $key) => [
            'key' => $key, 'label' => $label, 'done' => false, 'notes' => null,
        ])->values()->all();
    }
}
