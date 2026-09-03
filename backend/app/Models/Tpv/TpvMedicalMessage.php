<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Medical\MedicalWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry on a certificate's back-and-forth timeline (TPV side).
 *
 * Every submission, verdict and reply is a row here, so the complete
 * communication history between the quality team and the vendor is a query
 * rather than a reconstruction from email.
 */
class TpvMedicalMessage extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_medical_messages';

    protected $fillable = [
        'tenant_id', 'medical_id', 'tpv_worker_id',
        'iteration_no', 'action', 'author_side', 'author_id', 'author_name',
        'reason_code', 'body', 'attachment_path', 'attachment_name',
    ];

    protected $casts = [
        'iteration_no' => 'integer',
    ];

    protected $appends = ['action_label'];

    public function medical()
    {
        return $this->belongsTo(TpvWorkerMedical::class, 'medical_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function getActionLabelAttribute(): string
    {
        return MedicalWorkflow::actionLabel($this->action);
    }
}
