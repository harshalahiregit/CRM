<?php

namespace App\Support\Tpv;

/** TPV inspection-type catalogue (Sangoe TPV §22). */
class InspectionType
{
    public const ALL = [
        'Pre_Mobilisation', 'HSE', 'Site', 'PPE', 'Workforce', 'Equipment',
        'Compliance', 'Behavioural_Safety', 'Housekeeping', 'Environmental', 'Vendor_Audit',
    ];

    public static function label(?string $type): string
    {
        return $type ? ucwords(str_replace('_', ' ', $type)) : 'Unknown';
    }
}
