<?php

namespace App\Support\Purchase;

/**
 * Purchase-vendor compliance categories + statuses. The Purchase-side mirror of
 * the TPV compliance engine (parity rule), kept in its own namespace so the two
 * modules stay isolated on their separate databases.
 */
class PurchaseComplianceCatalog
{
    public const CATEGORIES = [
        'Legal', 'Labour', 'Licences', 'Statutory', 'Contractual', 'HSE', 'Training',
        'Medical', 'Risk_Assessment', 'Method_Statement', 'PPE', 'Environment', 'Quality', 'Security',
    ];

    public const STATUSES = [
        'Compliant', 'Partially_Compliant', 'Non_Compliant', 'Expiring', 'Expired', 'Waived', 'Under_Review',
    ];

    /** Statuses that count as "in good standing" for a compliance %. */
    public const OK_STATUSES = ['Compliant', 'Waived'];

    public static function label(?string $v): string
    {
        return $v ? str_replace('_', ' ', $v) : 'Unknown';
    }
}
