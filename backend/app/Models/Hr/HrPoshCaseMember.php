<?php

namespace App\Models\Hr;

use App\Exceptions\BusinessException;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One period during which one person could read one case.
 *
 * A HISTORY, not a state. Removing access ends a period; it never deletes one.
 * Adding the same person back opens a new period beside the old. That is why
 * there is no unique (case_id, user_id) index — such a constraint would force
 * a re-admission to overwrite the earlier period and erase the record of who
 * could read the file and when.
 *
 * The same shape HrEmployeeShift already uses for assignments, and for the
 * same stated reason: one table, a null end-date meaning current, rather than
 * two tables holding identical columns that drift.
 *
 * role_key is copied from the committee role BY VALUE. The role may be
 * renamed, deactivated or deleted afterwards; what this person sat as on this
 * case does not move.
 */
class HrPoshCaseMember extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_posh_case_members';

    public const SOURCE_SNAPSHOT = 'committee_snapshot';
    public const SOURCE_RECONSTITUTION = 'reconstitution';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'tenant_id', 'case_id', 'user_id', 'role_key', 'source',
        'added_by', 'added_reason', 'added_at',
        'removed_at', 'removed_by', 'removed_reason',
    ];

    protected $casts = [
        'added_at'   => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function case()
    {
        return $this->belongsTo(HrPoshCase::class, 'case_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('removed_at');
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }

    /**
     * Open a membership period.
     *
     * The one-active-period rule lives here rather than in a database
     * constraint, because the constraint that would express it — unique on
     * (case_id, user_id) — is the very thing that would destroy the history.
     * A second active period is refused rather than merged: two live rows for
     * one person would make "when did their access start" unanswerable.
     *
     * Phase 3b does not create cases. This exists so the snapshot a later
     * phase writes is correct from the first line rather than retrofitted.
     */
    public static function grant(
        HrPoshCase $case,
        int $userId,
        string $roleKey,
        string $source = self::SOURCE_SNAPSHOT,
        ?User $actor = null,
        ?string $reason = null,
    ): self {
        $live = static::where('case_id', $case->id)
            ->where('user_id', $userId)
            ->whereNull('removed_at')
            ->exists();

        if ($live) {
            throw new BusinessException('That person already has access to this case.', 422);
        }

        return static::create([
            'tenant_id'    => $case->tenant_id,
            'case_id'      => $case->id,
            'user_id'      => $userId,
            'role_key'     => $roleKey,
            'source'       => $source,
            'added_by'     => $actor?->id,
            'added_reason' => $reason,
            'added_at'     => now(),
        ]);
    }

    /**
     * Close the active period, keeping it.
     *
     * Nothing is deleted and nothing is overwritten: the row stays, stamped
     * with when access ended and why, and the person loses access immediately
     * because active means removed_at is null.
     */
    public static function revoke(
        HrPoshCase $case,
        int $userId,
        ?User $actor = null,
        ?string $reason = null,
    ): void {
        static::where('case_id', $case->id)
            ->where('user_id', $userId)
            ->whereNull('removed_at')
            ->update([
                'removed_at'     => now(),
                'removed_by'     => $actor?->id,
                'removed_reason' => $reason,
                'updated_at'     => now(),
            ]);
    }
}
