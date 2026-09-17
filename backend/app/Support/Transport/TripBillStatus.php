<?php

namespace App\Support\Transport;

/**
 * Where a trip's billing linkage has got to.  SNG-TRN-015.  D-60.
 *
 * Two states, and the boundary between them is the boundary between two
 * modules. Transport can reach the first and never the second.
 *
 *   prepared   Transport has ruled the trip billable and frozen what it is
 *              worth. invoice_id is NULL. This is as far as this module goes.
 *   invoiced   Accounts has raised the invoice and filled invoice_id in.
 *              Set by ACCOUNTS, not here — EVT-010 InvoicePosted names Accounts
 *              as its producer, and FORBID-002 keeps Transport out of the books.
 *
 * `invoiced` is therefore DECLARED and not reachable from any Transport code
 * path. That is deliberate rather than unfinished: naming the state now means
 * Accounts has somewhere to land and nobody invents a second spelling for it,
 * and it is the same declared-not-wired discipline TripStatus and AdvanceStatus
 * both follow.
 *
 * ── CONSTRUCTED — THERE IS NO REGISTERED VOCABULARY ─────────────────────
 * Step 11 registers eight enums and none describes a bill. DB-012's only field
 * row is FLD-016 `invoice_id`. So this vocabulary is built from what the two
 * modules actually need to say to each other, and recorded as D-60.
 */
final class TripBillStatus
{
    /** Transport's terminal state. Ready to invoice; not invoiced. */
    public const PREPARED = 'prepared';

    /** Accounts' state. Transport never writes it. */
    public const INVOICED = 'invoiced';

    /** @var list<string> */
    public const ALL = [self::PREPARED, self::INVOICED];

    public const INITIAL = self::PREPARED;

    /** What Transport is permitted to set. The list is short on purpose. */
    public const TRANSPORT_MAY_SET = [self::PREPARED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PREPARED => 'Ready to invoice',
            self::INVOICED => 'Invoiced',
            default        => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
