<?php

namespace App\Models\Shared;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Party\PartyType;
use Illuminate\Database\Eloquent\Model;

/**
 * One named person at a Client, Vendor or TPV who owns this task.
 *
 * Sits BESIDE task_assignees / project_members, never instead of them: those
 * hold internal staff (a users row), this one is somebody at another company who
 * may have no login in this system at all. Keeping them apart is what lets
 * "assign to a person" mean the same thing on both sides of the glass without a
 * users-id column having to pretend a contact is a user.
 *
 * `subject_type` + `subject_id` say what the row hangs off — a task or a
 * project. One table rather than one per module, because the rules about who may
 * be assigned and what they then see have to be one set of rules.
 */
class PartyAssignee extends Model
{
    use BelongsToTenant;

    protected $table = 'party_assignees';

    /** What a row can hang off. See the generalise migration for why. */
    public const SUBJECT_TASK = 'task';

    public const SUBJECT_PROJECT = 'project';

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id', 'party_type', 'party_id',
        'org_type', 'org_id', 'name', 'email', 'assigned_by',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'party_id'   => 'integer',
        'org_id'     => 'integer',
    ];

    protected $appends = ['party_label', 'org_label'];

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * The live contact record, or null if it has since been deleted.
     *
     * Not an Eloquent morph: the three contact tables have no shared interface
     * and morphTo would need a morph map naming classes in three other modules,
     * which is exactly the coupling the isolation rule forbids. The snapshot on
     * this row is what the UI reads; this is for when something needs the truth.
     */
    public function party(): ?Model
    {
        if (! PartyType::isValidType($this->party_type)) {
            return null;
        }

        $model = PartyType::config($this->party_type)['model'];

        return $model::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->find($this->party_id);
    }

    public function getPartyLabelAttribute(): string
    {
        return PartyType::label($this->party_type);
    }

    public function getOrgLabelAttribute(): string
    {
        return PartyType::orgLabel($this->org_type);
    }

    /** The chip the UI draws, with no further lookups. */
    public function toChip(): array
    {
        return [
            'id'          => (int) $this->id,
            'subject_type' => (string) $this->subject_type,
            'party_type'  => (string) $this->party_type,
            'party_id'    => (int) $this->party_id,
            'org_type'    => (string) $this->org_type,
            'org_id'      => (int) $this->org_id,
            'name'        => (string) ($this->name ?: 'Contact #'.$this->party_id),
            'email'       => (string) ($this->email ?? ''),
            'party_label' => $this->party_label,
            'org_label'   => $this->org_label,
        ];
    }
}
