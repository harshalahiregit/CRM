<?php

namespace App\Models\Task;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskChecklistTemplate extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'items', 'created_by'];

    protected $casts = ['items' => 'array'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
