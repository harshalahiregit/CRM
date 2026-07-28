<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * One notification dispatched about a TPV. TPV-owned; Purchase keeps its own
 * PurchaseNotificationLog. Never stores credentials or raw transport errors.
 */
class TpvNotificationLog extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_notification_logs';

    public const TYPE_ACTIVATED = 'account_activated';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'type', 'channel',
        'subject', 'recipient', 'status', 'sent_at', 'response',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** Has this vendor already been successfully told about `$type`? */
    public static function alreadySent(int $tenantId, int $vendorId, string $type): bool
    {
        return static::forTenant($tenantId)
            ->where('vendor_id', $vendorId)
            ->where('type', $type)
            ->where('status', 'sent')
            ->exists();
    }
}
