<?php

namespace App\Models\Task;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One notification/email switch for one workspace. Read through
 * TaskConfigService, never directly — that's where the defaults live, so a key
 * a tenant has never touched still answers sensibly.
 */
class TaskSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'task_settings';

    protected $fillable = ['tenant_id', 'key', 'value'];

    protected $casts = ['value' => 'array'];
}
