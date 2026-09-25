<?php

namespace App\Models\Hr;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A record that somebody read a case.
 *
 * APPEND ONLY. Nothing updates a read and nothing deletes one, so there is no
 * updated_at column and no service path that writes to an existing row. The
 * point of the table is that it cannot be tidied up afterwards.
 *
 * Kept apart from audit_logs deliberately. Every audit browser in the product
 * queries audit_logs; putting POSH reads there would surface who opened a
 * harassment file on a screen built for something else entirely. Nothing reads
 * this table today, and no route returns it.
 *
 * Only SUCCESSFUL, authorised content reads land here. A refused attempt is a
 * security event, not evidence that content was served, and belongs on the
 * ordinary audit trail.
 */
class HrPoshCaseRead extends Model
{
    use BelongsToTenant;

    protected $table = 'hr_posh_case_reads';

    /** Nothing here is ever updated, so Eloquent's pair would always be equal. */
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'case_id', 'actor_id', 'actor_label', 'surface', 'ip', 'read_at',
    ];

    protected $casts = ['read_at' => 'datetime'];

    public function case()
    {
        return $this->belongsTo(HrPoshCase::class, 'case_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
