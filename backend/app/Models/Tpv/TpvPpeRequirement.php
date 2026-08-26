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
     *
     * Job (scope) is the base dimension; Activity and Hazard narrow it further —
     * see matches() — completing the §18 Job + Hazard + Activity matrix.
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

    /**
     * Does this rule apply to the given worker? (§18 Job + Hazard + Activity.)
     *
     * Job/skill scope is the base dimension. Activity and Hazard then NARROW the
     * rule — but only for a worker who is actually assigned to an activity: the
     * worker's assigned activity carries both the activity name and its hazard, so
     * an activity/hazard-scoped rule applies only when they match. A worker with no
     * activity assigned matches on scope alone, so a rule's descriptive
     * activity/hazard context never over-excludes an unassigned worker.
     */
    public function matches(TpvWorker $worker): bool
    {
        // 1) Job / skill scope.
        if ($this->scope_type !== 'all') {
            $actual = $worker->{$this->scope_type} ?? null;
            if ($actual === null || strcasecmp(trim((string) $actual), trim((string) $this->scope_value)) !== 0) {
                return false;
            }
        }

        // 2) Activity + hazard — enforced only when the worker has an activity.
        $activity = $worker->activity;
        if ($activity) {
            if (filled($this->activity)
                && strcasecmp(trim((string) $this->activity), trim((string) $activity->name)) !== 0) {
                return false;
            }
            if (filled($this->hazard) && filled($activity->hazard)
                && strcasecmp(trim((string) $this->hazard), trim((string) $activity->hazard)) !== 0) {
                return false;
            }
        }

        return true;
    }
}
