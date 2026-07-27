<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Purchase vendor's worker. Purchase-owned (purchase_workers) — completely
 * independent of the TPV tpv_workers engine. Always belongs to a purchase_vendor_id.
 */
class PurchaseWorker extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_workers';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'created_by',
        'worker_code', 'full_name', 'gender', 'dob', 'phone', 'email', 'designation',
        'id_proof_type', 'id_proof_number', 'address', 'city', 'state', 'pincode',
        'photo_path', 'status', 'notes',
    ];

    protected $casts = ['dob' => 'date'];

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function documents()
    {
        return $this->hasMany(PurchaseWorkerDocument::class, 'purchase_worker_id');
    }

    public function medicals()
    {
        return $this->hasMany(PurchaseWorkerMedical::class, 'purchase_worker_id');
    }

    public function trainings()
    {
        return $this->hasMany(PurchaseWorkerTraining::class, 'purchase_worker_id');
    }

    public function inductions()
    {
        return $this->hasMany(PurchaseWorkerInduction::class, 'purchase_worker_id');
    }

    /** The most recent medical / induction rows drive readiness. */
    public function latestMedical()
    {
        return $this->hasOne(PurchaseWorkerMedical::class, 'purchase_worker_id')->latestOfMany();
    }

    public function latestInduction()
    {
        return $this->hasOne(PurchaseWorkerInduction::class, 'purchase_worker_id')->latestOfMany();
    }
}
