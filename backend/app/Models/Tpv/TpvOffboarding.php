<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A TPV offboarding/closure workflow (Sangoe TPV §29). */
class TpvOffboarding extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_offboardings';

    public const FINAL_STATUSES = ['Closed', 'Replaced', 'Suspended', 'Blacklisted'];

    /** The default exit checklist (key → label). */
    public const CHECKLIST = [
        'contract_closure' => 'Contract closed',
        'workforce_exit' => 'Workforce exited',
        'gate_access_revoked' => 'Gate access revoked',
        'id_pass_returned' => 'ID / pass returned',
        'equipment_returned' => 'Equipment returned',
        'ppe_returned' => 'PPE returned',
        'documents_archived' => 'Documents archived',
        'open_actions_closed' => 'Open actions closed',
        'ncr_capa_closure' => 'NCR / CAPA closed',
        'financial_closure' => 'Financial closure',
        'asset_material_clearance' => 'Asset / material clearance',
        'final_performance_review' => 'Final performance review',
    ];

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'reason', 'checklist', 'status',
        'final_status', 'completed_at', 'completed_by', 'lessons_learned', 'created_by',
    ];

    protected $casts = [
        'checklist' => 'array',
        'completed_at' => 'datetime',
    ];

    protected $appends = ['progress'];

    protected static function booted(): void
    {
        static::creating(function (TpvOffboarding $o) {
            if (empty($o->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $o->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $o->reference = 'OFF-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /** Fraction of checklist items done (0–100). */
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
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** Build the default checklist rows. */
    public static function defaultChecklist(): array
    {
        return collect(self::CHECKLIST)->map(fn ($label, $key) => [
            'key' => $key, 'label' => $label, 'done' => false, 'notes' => null,
        ])->values()->all();
    }
}
