<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class TaskStatus extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'task_statuses';

    protected $fillable = [
        'tenant_id', 'name', 'color', 'sort_order', 'is_default', 'is_completed_status',
    ];

    protected $casts = [
        'is_default'          => 'boolean',
        'is_completed_status' => 'boolean',
    ];

    public function tasks()
    {
        return $this->hasMany(Task::class, 'status_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public static function getDefault(int $tenantId): ?self
    {
        return static::forTenant($tenantId)->where('is_default', true)->first();
    }

    /**
     * The default set of task statuses (matches the old CRM's task pipeline).
     * Seeded lazily per tenant by TaskService when none exist.
     */
    public static function defaults(): array
    {
        return [
            ['name' => 'Not Started',      'color' => '#94a3b8', 'sort_order' => 1, 'is_default' => true,  'is_completed_status' => false],
            ['name' => 'In Progress',      'color' => '#3b82f6', 'sort_order' => 2, 'is_default' => false, 'is_completed_status' => false],
            ['name' => 'Awaiting Feedback','color' => '#f59e0b', 'sort_order' => 3, 'is_default' => false, 'is_completed_status' => false],
            ['name' => 'Testing',          'color' => '#a78bfa', 'sort_order' => 4, 'is_default' => false, 'is_completed_status' => false],
            ['name' => 'Complete',         'color' => '#10b981', 'sort_order' => 5, 'is_default' => false, 'is_completed_status' => true],
        ];
    }
}
