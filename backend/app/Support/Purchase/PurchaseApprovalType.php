<?php

namespace App\Support\Purchase;

/**
 * The catalogue of Purchase central-approval types (Sangoe TPV §12). Purchase-
 * owned mirror of TPV's ApprovalType (parity rule) — the same 18 governance
 * approval types, on the Purchase register. Types carry no routing/authority of
 * their own; they classify entries in the central register.
 *
 * NB: this is the CENTRAL register (purchase_approval_requests). It is distinct
 * from the Purchase onboarding stage chain (purchase_approvals /
 * PurchaseApprovalStage), which is the per-onboarding multi-stage flow.
 */
final class PurchaseApprovalType
{
    public const VENDOR_REGISTRATION = 'vendor_registration';
    public const PREQUALIFICATION    = 'prequalification';
    public const RISK_APPROVAL       = 'risk_approval';
    public const VENDOR_ACTIVATION   = 'vendor_activation';
    public const TEMPORARY_VENDOR    = 'temporary_vendor';
    public const CONTRACT            = 'contract';
    public const WORK_ORDER          = 'work_order';
    public const WORKFORCE           = 'workforce';
    public const WORKER_REPLACEMENT  = 'worker_replacement';
    public const DOCUMENT_EXCEPTION  = 'document_exception';
    public const COMPLIANCE_DEVIATION = 'compliance_deviation';
    public const PPE_EXCEPTION        = 'ppe_exception';
    public const PERMIT               = 'permit';
    public const VENDOR_SUSPENSION    = 'vendor_suspension';
    public const VENDOR_REACTIVATION  = 'vendor_reactivation';
    public const VENDOR_RENEWAL       = 'vendor_renewal';
    public const VENDOR_CLOSURE       = 'vendor_closure';
    public const OTHER                = 'other';

    public const LABELS = [
        self::VENDOR_REGISTRATION  => 'Vendor Registration',
        self::PREQUALIFICATION     => 'Prequalification',
        self::RISK_APPROVAL        => 'Risk Approval',
        self::VENDOR_ACTIVATION    => 'Vendor Activation',
        self::TEMPORARY_VENDOR     => 'Temporary Vendor',
        self::CONTRACT             => 'Contract',
        self::WORK_ORDER           => 'Work Order',
        self::WORKFORCE            => 'Workforce',
        self::WORKER_REPLACEMENT   => 'Worker Replacement',
        self::DOCUMENT_EXCEPTION   => 'Document Exception',
        self::COMPLIANCE_DEVIATION => 'Compliance Deviation',
        self::PPE_EXCEPTION        => 'PPE Exception',
        self::PERMIT               => 'Permit',
        self::VENDOR_SUSPENSION    => 'Vendor Suspension',
        self::VENDOR_REACTIVATION  => 'Vendor Reactivation',
        self::VENDOR_RENEWAL       => 'Vendor Renewal',
        self::VENDOR_CLOSURE       => 'Vendor Closure',
        self::OTHER                => 'Other',
    ];

    public const ALL = [
        self::VENDOR_REGISTRATION, self::PREQUALIFICATION, self::RISK_APPROVAL,
        self::VENDOR_ACTIVATION, self::TEMPORARY_VENDOR, self::CONTRACT,
        self::WORK_ORDER, self::WORKFORCE, self::WORKER_REPLACEMENT,
        self::DOCUMENT_EXCEPTION, self::COMPLIANCE_DEVIATION, self::PPE_EXCEPTION,
        self::PERMIT, self::VENDOR_SUSPENSION, self::VENDOR_REACTIVATION,
        self::VENDOR_RENEWAL, self::VENDOR_CLOSURE, self::OTHER,
    ];

    public static function label(?string $v): string
    {
        return self::LABELS[$v] ?? ucwords(str_replace('_', ' ', (string) $v));
    }

    /** [{value,label}] for a dropdown. */
    public static function options(): array
    {
        return array_map(fn ($k) => ['value' => $k, 'label' => self::label($k)], self::ALL);
    }
}
