<?php

namespace App\Models\Hr;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HrApprovalHistory extends Model
{
    use HasFactory;

    protected $table = 'hr_approval_history';

    protected $fillable = [
        'tenant_id', 'request_id', 'level', 'action', 'actor_id',
        'remarks', 'old_values', 'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function manpowerRequest()
    {
        return $this->belongsTo(HrManpowerRequest::class, 'request_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // Legacy alias
    public function user()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Helper to quickly log an action */
    public static function logAction(
        HrManpowerRequest $request,
        string $action,
        ?string $remarks = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): self {
        return self::create([
            'request_id' => $request->id,
            'level'      => 'General',
            'action'     => $action,
            'actor_id'   => auth()->id(),
            'remarks'    => $remarks,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
