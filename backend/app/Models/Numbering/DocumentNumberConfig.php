<?php

namespace App\Models\Numbering;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant numbering CONFIGURATION for one document type.
 *
 * Deliberately holds no sequence cursor — the current number is derived from
 * DocumentNumberSequence. `epoch` is the only counter-ish field and it is not a
 * cursor: it is bumped on an explicit reset so the next allocation opens a new
 * sequence row (non-destructive reset).
 */
class DocumentNumberConfig extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'document_number_configs';

    protected $fillable = [
        'tenant_id', 'document_type',
        'format', 'prefix', 'suffix', 'minimum_digits', 'padding',
        'starting_number', 'reset_rule', 'epoch',
        'enabled', 'locked', 'manual_override', 'decrement_on_delete',
        'updated_by',
    ];

    protected $casts = [
        'minimum_digits'      => 'integer',
        'starting_number'     => 'integer',
        'epoch'               => 'integer',
        'enabled'             => 'boolean',
        'locked'              => 'boolean',
        'manual_override'     => 'boolean',
        'decrement_on_delete' => 'boolean',
    ];

    /**
     * Sequence rows for this type. Scope by tenant at the call site
     * (->sequences()->forTenant($id)) — baking $this->tenant_id into the relation
     * breaks eager loading, where the instance has no tenant_id yet.
     */
    public function sequences()
    {
        return $this->hasMany(DocumentNumberSequence::class, 'document_type', 'document_type');
    }
}
