<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TpvGateAttendance extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_gate_attendances';

    protected $fillable = [
        'tenant_id','tpv_worker_id','work_date','check_in_at','check_out_at',
        'check_in_gate','check_out_gate','minutes_on_site',
    ];

    protected $casts = [
        'work_date'       => 'date',
        'check_in_at'     => 'datetime',
        'check_out_at'    => 'datetime',
        'minutes_on_site' => 'integer',
    ];

    protected $appends = ['on_site', 'duration_label'];

    public function worker()
    {
        return $this->belongsTo(TpvWorker::class, 'tpv_worker_id');
    }

    /** Checked in and not yet out — currently inside the site. */
    public function getOnSiteAttribute(): bool
    {
        return $this->check_in_at !== null && $this->check_out_at === null;
    }

    /** Human duration; live for anyone still on site. */
    public function getDurationLabelAttribute(): ?string
    {
        if (! $this->check_in_at) {
            return null;
        }
        $mins = $this->minutes_on_site ?? $this->check_in_at->diffInMinutes(now());

        return intdiv($mins, 60).'h '.($mins % 60).'m';
    }

    public function scopeOnSite($query)
    {
        return $query->whereNotNull('check_in_at')->whereNull('check_out_at');
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('work_date', $date);
    }
}
