<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One notification dispatched about a PurchaseVendor. Purchase-owned; TPV keeps
 * its own TpvNotificationLog. Never stores credentials or raw transport errors.
 */
class PurchaseNotificationLog extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_notification_logs';

    public const TYPE_ACTIVATED = 'account_activated';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'type', 'channel',
        'subject', 'recipient', 'status', 'sent_at', 'response',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'vendor_id');
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
