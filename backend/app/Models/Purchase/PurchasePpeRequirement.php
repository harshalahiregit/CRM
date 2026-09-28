<?php

namespace App\Models\Purchase;

use App\Models\Inventory\Product;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the Purchase PPE requirement matrix: a role needs this item.
 *
 * The rule owns no stock and no product detail — only the pointer. Everything
 * shown about the item (name, sku, availability) is read from Inventory, which
 * is also where an issue moves stock from.
 *
 * Mirrors TpvPpeRequirement so one matrix screen can serve both engines.
 */
class PurchasePpeRequirement extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_ppe_requirements';

    /**
     * Which worker attribute a rule matches on.
     *
     * 'all' has no value and applies to every worker. The other two name real
     * columns on purchase_workers — a scope whose column does not exist could
     * never match, so the list stays tied to what a worker actually records.
     */
    public const SCOPES = [
        'all' => 'All Workers',
        'designation' => 'Job Role / Designation',
        'skill_category' => 'Skill Category',
    ];

    /**
     * Only 'mandatory' gates the badge and the site gate. 'optional' and
     * 'conditional' are advisory — conditional carries a `condition` note saying
     * when it applies.
     */
    public const CLASSES = ['mandatory', 'optional', 'conditional'];

    protected $fillable = [
        'tenant_id', 'scope_type', 'scope_value', 'hazard', 'activity',
        'ppe_class', 'condition', 'product_id', 'qty', 'replacement_frequency_days',
        'verification_required', 'is_active', 'created_by',
    ];

    protected $casts = [
        'qty' => 'integer',
        'replacement_frequency_days' => 'integer',
        'verification_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** Only mandatory rules block a badge; optional and conditional never do. */
    public function isMandatory(): bool
    {
        return ($this->ppe_class ?? 'mandatory') === 'mandatory';
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Does this rule apply to the given worker?
     *
     * Job/skill scope only. TPV additionally narrows by activity and hazard,
     * because a TPV worker is assigned to an activity that carries both;
     * purchase_workers records NEITHER, so narrowing on them here would produce
     * a rule that silently matches nobody — worse than not having the dimension.
     * The columns exist for shape parity and are descriptive until a Purchase
     * worker gains an activity, at which point this method is the one change.
     */
    public function matches(PurchaseWorker $worker): bool
    {
        if ($this->scope_type === 'all') {
            return true;
        }

        $actual = $worker->{$this->scope_type} ?? null;

        return $actual !== null
            && strcasecmp(trim((string) $actual), trim((string) $this->scope_value)) === 0;
    }
}
