<?php

namespace App\Support\Tpv;

/**
 * The TPV approval-type catalogue (Sangoe TPV §12). The central approval engine
 * handles many kinds of approval, not just onboarding; this is the authoritative
 * list of types + their display labels.
 */
class ApprovalType
{
    public const VENDOR_REGISTRATION = 'vendor_registration';

    public const PREQUALIFICATION = 'prequalification';

    public const RISK_APPROVAL = 'risk_approval';

    public const VENDOR_ACTIVATION = 'vendor_activation';

    public const TEMPORARY_VENDOR = 'temporary_vendor';

    public const CONTRACT = 'contract';

    public const WORK_ORDER = 'work_order';

    public const WORKFORCE = 'workforce';

    public const WORKER_REPLACEMENT = 'worker_replacement';

    public const DOCUMENT_EXCEPTION = 'document_exception';

    public const COMPLIANCE_DEVIATION = 'compliance_deviation';

    public const PPE_EXCEPTION = 'ppe_exception';

    public const PERMIT = 'permit';

    public const VENDOR_SUSPENSION = 'vendor_suspension';

    public const VENDOR_REACTIVATION = 'vendor_reactivation';

    public const VENDOR_RENEWAL = 'vendor_renewal';

    public const VENDOR_CLOSURE = 'vendor_closure';

    public const OTHER = 'other';

    public const LABELS = [
        self::VENDOR_REGISTRATION => 'Vendor Registration',
        self::PREQUALIFICATION => 'Prequalification',
        self::RISK_APPROVAL => 'Risk Approval',
        self::VENDOR_ACTIVATION => 'Vendor Activation',
        self::TEMPORARY_VENDOR => 'Temporary Vendor',
        self::CONTRACT => 'Contract',
        self::WORK_ORDER => 'Work Order',
        self::WORKFORCE => 'Workforce',
        self::WORKER_REPLACEMENT => 'Worker Replacement',
        self::DOCUMENT_EXCEPTION => 'Document Exception',
        self::COMPLIANCE_DEVIATION => 'Compliance Deviation',
        self::PPE_EXCEPTION => 'PPE Exception',
        self::PERMIT => 'Permit',
        self::VENDOR_SUSPENSION => 'Vendor Suspension',
        self::VENDOR_REACTIVATION => 'Vendor Reactivation',
        self::VENDOR_RENEWAL => 'Vendor Renewal',
        self::VENDOR_CLOSURE => 'Vendor Closure',
        self::OTHER => 'Other',
    ];

    public const ALL = [
        self::VENDOR_REGISTRATION, self::PREQUALIFICATION, self::RISK_APPROVAL, self::VENDOR_ACTIVATION,
        self::TEMPORARY_VENDOR, self::CONTRACT, self::WORK_ORDER, self::WORKFORCE, self::WORKER_REPLACEMENT,
        self::DOCUMENT_EXCEPTION, self::COMPLIANCE_DEVIATION, self::PPE_EXCEPTION, self::PERMIT,
        self::VENDOR_SUSPENSION, self::VENDOR_REACTIVATION, self::VENDOR_RENEWAL, self::VENDOR_CLOSURE, self::OTHER,
    ];

    public static function label(?string $type): string
    {
        return self::LABELS[$type] ?? ($type ? ucwords(str_replace('_', ' ', $type)) : 'Unknown');
    }

    /** [{value,label}] for a frontend dropdown. */
    public static function options(): array
    {
        return array_map(fn ($t) => ['value' => $t, 'label' => self::LABELS[$t]], self::ALL);
    }
}
