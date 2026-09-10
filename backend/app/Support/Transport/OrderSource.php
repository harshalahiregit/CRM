<?php

namespace App\Support\Transport;

/**
 * Where a Transport Order came from.
 *
 * STOS-OPS §6 lists the six origins — Sales, Customer, API, manual Operations
 * entry, recurring order, bulk import — and adds a requirement in its own
 * sentence: "Every creation must identify source." So this is mandatory on
 * create, not descriptive metadata.
 *
 * Only `manual` is reachable in this ticket; the other five are the channels
 * that later work will create orders through. They are declared now so the
 * column's vocabulary is fixed before anything writes to it.
 *
 * Transport-owned. Stored on transport_orders.source as a plain string.
 */
final class OrderSource
{
    public const SALES       = 'sales';
    public const CUSTOMER    = 'customer';
    public const API         = 'api';
    public const MANUAL      = 'manual';
    public const RECURRING   = 'recurring';
    public const BULK_IMPORT = 'bulk_import';

    public const ALL = [
        self::SALES, self::CUSTOMER, self::API,
        self::MANUAL, self::RECURRING, self::BULK_IMPORT,
    ];

    /** Operations entering an order by hand — the only path this ticket builds. */
    public const DEFAULT = self::MANUAL;

    public const LABELS = [
        self::SALES       => 'Sales',
        self::CUSTOMER    => 'Customer',
        self::API         => 'API',
        self::MANUAL      => 'Manual (Operations)',
        self::RECURRING   => 'Recurring order',
        self::BULK_IMPORT => 'Bulk import',
    ];

    public static function isValid(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }
}
