<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HrApprovalHistory extends Model
{
    use HasFactory;

    protected $table = 'hr_approval_history';

    protected $fillable = [
        'tenant_id',
        'manpower_request_id',
        'user_id',
        'action',
        'comment',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function manpowerRequest()
    {
        return $this->belongsTo(HrManpowerRequest::class, 'manpower_request_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log an approval action
     */
    public static function logAction(
        HrManpowerRequest $request,
        string $action,
        ?string $comment = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): self {
        return self::create([
            'tenant_id' => $request->tenant_id,
            'manpower_request_id' => $request->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'comment' => $comment,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
