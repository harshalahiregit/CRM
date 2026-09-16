<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Medical\MedicalWorkflow;
use Illuminate\Database\Eloquent\Model;

/** One entry on a certificate's back-and-forth timeline (Purchase side). */
class PurchaseMedicalMessage extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_medical_messages';

    protected $fillable = [
        'tenant_id', 'medical_id', 'purchase_worker_id',
        'iteration_no', 'action', 'author_side', 'author_id', 'author_name',
        'reason_code', 'body', 'attachment_path', 'attachment_name',
    ];

    protected $casts = [
        'iteration_no' => 'integer',
    ];

    protected $appends = ['action_label'];

    public function medical()
    {
        return $this->belongsTo(PurchaseWorkerMedical::class, 'medical_id');
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
