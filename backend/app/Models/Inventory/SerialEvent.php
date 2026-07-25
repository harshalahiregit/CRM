<?php

namespace App\Models\Inventory;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** One entry in a serialised unit's life history (service/repair/etc.). */
class SerialEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_serial_events';

    public const TYPES = ['service', 'repair', 'replacement', 'inspection', 'status_change', 'note'];

    protected $fillable = [
        'tenant_id', 'serial_id', 'event_type', 'status_from', 'status_to',
        'description', 'cost', 'vendor', 'reference', 'performed_at', 'performed_by',
    ];

    protected $casts = [
        'performed_at' => 'date',
        'cost'         => 'decimal:2',
    ];

    public function serial() { return $this->belongsTo(Serial::class, 'serial_id'); }
    public function performer() { return $this->belongsTo(User::class, 'performed_by'); }
}
