<?php

namespace App\Support\Purchase;

/**
 * Payment modes for purchase invoice payments. A short-string enum kept out of
 * loose magic strings (the User.role situation is the cautionary tale).
 */
final class PurchasePaymentMode
{
    public const BANK_TRANSFER = 'Bank_Transfer';
    public const CASH          = 'Cash';
    public const CHEQUE        = 'Cheque';
    public const UPI           = 'UPI';
    public const CARD          = 'Card';
    public const OTHER         = 'Other';

    public const ALL = [
        self::BANK_TRANSFER, self::CASH, self::CHEQUE, self::UPI, self::CARD, self::OTHER,
    ];

    public const LABELS = [
        self::BANK_TRANSFER => 'Bank Transfer',
        self::CASH          => 'Cash',
        self::CHEQUE        => 'Cheque',
        self::UPI           => 'UPI',
        self::CARD          => 'Card',
        self::OTHER         => 'Other',
    ];

    public static function label(?string $mode): string
    {
        return self::LABELS[$mode] ?? (string) $mode;
    }

    public static function isValid(?string $mode): bool
    {
        return in_array($mode, self::ALL, true);
    }
}
