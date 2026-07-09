<?php

namespace App\Models\Sales;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Traits\BelongsToTenant;

class LeadGoal extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'type', 'period_start', 'period_end',
        'target_count', 'target_value', 'achieved_count', 'achieved_value',
        'incentive_type', 'incentive_value',
    ];

    protected $casts = [
        'period_start'   => 'date',
        'period_end'     => 'date',
        'target_value'   => 'decimal:2',
        'achieved_value' => 'decimal:2',
        'incentive_value'=> 'decimal:2',
    ];

    /* ── Relationships ─────────────────────── */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /* ── Scopes ────────────────────────────── */
    public function scopeActive($query)
    {
        return $query->where('period_start', '<=', now())
                     ->where('period_end', '>=', now());
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /* ── Helpers ────────────────────────────── */
    public function progressPercentage(): float
    {
        if ($this->target_count > 0) {
            return min(round(($this->achieved_count / $this->target_count) * 100, 1), 100);
        }
        if ($this->target_value > 0) {
            return min(round(($this->achieved_value / $this->target_value) * 100, 1), 100);
        }
        return 0;
    }

    public function earnedIncentive(): float
    {
        if ($this->progressPercentage() < 100) return 0;

        if ($this->incentive_type === 'fixed') {
            return $this->incentive_value;
        }
        if ($this->incentive_type === 'percentage') {
            return round($this->achieved_value * ($this->incentive_value / 100), 2);
        }
        return 0;
    }
}
