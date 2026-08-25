<?php

namespace App\Support\Tpv;

/** TPV compliance categories + statuses (Sangoe TPV §21). */
class ComplianceCatalog
{
    public const CATEGORIES = [
        'Legal', 'Labour', 'Licences', 'Statutory', 'Contractual', 'HSE', 'Training',
        'Medical', 'Risk_Assessment', 'Method_Statement', 'PPE', 'Environment', 'Quality', 'Security',
        // §21 additions — the doc's fuller category set.
        'Environmental_Requirements', 'Waste', 'Chemicals', 'Pollution', 'Certifications',
        'Inspection', 'QA_QC', 'Identification', 'Background_Verification', 'Access',
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
