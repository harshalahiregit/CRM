<?php

namespace App\Models\Tpv;

use App\Models\Inventory\Product;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the PPE requirement matrix: a role needs this Inventory product.
 *
 * The rule owns no stock and no product detail — only the pointer. Everything
 * shown about the item (name, sku, availability) is read from Inventory.
 */
class TpvPpeRequirement extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_ppe_requirements';

    /**
     * Which worker attribute a rule matches on.
     *
     * 'all' has no value and applies to every worker. The rest name a column on
     * tpv_workers — a scope whose column does not exist could never match, so the
     * list is deliberately short and tied to what a worker actually records.
     */
    public const SCOPES = [
        'all'            => 'All Workers',
        'designation'    => 'Job Role / Designation',
        'skill_category' => 'Skill Category',
    ];

    /**
     * §18 PPE classes. Only 'mandatory' gates the badge/gate; 'optional' and
     * 'conditional' are advisory (conditional carries a `condition` note).
     */
    public const CLASSES = ['mandatory', 'optional', 'conditional'];

    protected $fillable = [
        'tenant_id', 'scope_type', 'scope_value', 'hazard', 'activity',
        'ppe_class', 'condition', 'product_id', 'qty', 'replacement_frequency_days',
        'verification_required', 'is_active', 'created_by',
    ];

    protected $casts = [
        'qty'                        => 'integer',
        'replacement_frequency_days' => 'integer',
        'verification_required'      => 'boolean',
        'is_active'                  => 'boolean',
    ];

    /** Only mandatory rules block a badge (Rule 5); optional/conditional never do. */
    public function isMandatory(): bool
    {
        return ($this->ppe_class ?? 'mandatory') === 'mandatory';
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Does this rule apply to the given worker? */
    public function matches(TpvWorker $worker): bool
    {
        if ($this->scope_type === 'all') {
            return true;
        }

        $actual = $worker->{$this->scope_type} ?? null;

        return $actual !== null
            && strcasecmp(trim((string) $actual), trim((string) $this->scope_value)) === 0;
    }
}
