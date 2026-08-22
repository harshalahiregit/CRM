<?php

namespace App\Support\Purchase;

/**
 * Purchase inspection-type catalogue — the Purchase-side mirror of TPV's
 * InspectionType (parity rule), tuned for supplier/procurement audits.
 */
class PurchaseInspectionType
{
    public const ALL = [
        'Supplier_Audit', 'Quality', 'Goods_Inbound', 'HSE', 'Compliance',
        'Facility', 'Process', 'Documentation', 'Environmental',
    ];

    public static function label(?string $type): string
    {
        return $type ? ucwords(str_replace('_', ' ', $type)) : 'Unknown';
    }
}
