<?php

namespace App\Support\Purchase;

/**
 * The five-stage Purchase onboarding approval chain, in order:
 * Registration → Document → Commercial → Purchase → Final Activation.
 * Stored on purchase_approvals.stage as a plain string. Purchase-owned — no
 * relationship to any shared/TPV approval definition.
 */
final class PurchaseApprovalStage
{
    public const REGISTRATION = 'registration';
    public const DOCUMENT     = 'document';
    public const COMMERCIAL   = 'commercial';
    public const PURCHASE     = 'purchase';
    public const ACTIVATION   = 'activation';

    /** stage => sequence (1-based order). */
    public const CHAIN = [
        self::REGISTRATION => 1,
        self::DOCUMENT     => 2,
        self::COMMERCIAL   => 3,
        self::PURCHASE     => 4,
        self::ACTIVATION   => 5,
    ];

    public const LABELS = [
        self::REGISTRATION => 'Registration Approval',
        self::DOCUMENT     => 'Document Approval',
        self::COMMERCIAL   => 'Commercial Approval',
        self::PURCHASE     => 'Purchase Approval',
        self::ACTIVATION   => 'Final Activation',
    ];

    /** All stages in order. */
    public static function all(): array
    {
        return array_keys(self::CHAIN);
    }

    public static function sequence(string $stage): int
    {
        return self::CHAIN[$stage] ?? 0;
    }

    public static function isValid(?string $stage): bool
    {
        return $stage !== null && array_key_exists($stage, self::CHAIN);
    }

    public static function label(?string $stage): string
    {
        return self::LABELS[$stage] ?? (string) $stage;
    }

    /** Stages that must be approved before the given one (all earlier stages). */
    public static function prerequisites(string $stage): array
    {
        $seq = self::sequence($stage);

        return array_values(array_filter(self::all(), fn ($s) => self::sequence($s) < $seq));
    }
}
