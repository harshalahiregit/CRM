<?php

namespace App\Models\Medical;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody the system has never met, examined at the gate.
 *
 * A site visitor has no user account and no client record, so there is nothing
 * for an examination to point at until this row exists. Deliberately thin: a
 * name, a way to reach them, and why they are on site.
 */
class MedicalVisitor extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'medical_visitors';

    protected $fillable = [
        'tenant_id', 'name', 'phone', 'email', 'company',
        'id_proof_type', 'id_proof_number', 'purpose', 'created_by',
    ];
}
