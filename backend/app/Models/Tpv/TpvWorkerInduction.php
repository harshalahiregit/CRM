<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TpvWorkerInduction extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_worker_inductions';

    protected $fillable = [
        'tenant_id','tpv_worker_id','recorded_by','trainer_name','training_date','duration_minutes',
        'topics','score','passed','photo_path','signature_path','thumbprint_path',
    ];

    protected $casts = [
        'training_date'    => 'date',
        'duration_minutes' => 'integer',
        'topics'           => 'array',
        'score'            => 'integer',
        'passed'           => 'boolean',
    ];

    public function worker()
    {
        return $this->belongsTo(TpvWorker::class, 'tpv_worker_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
