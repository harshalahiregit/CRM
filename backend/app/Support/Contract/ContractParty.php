<?php

namespace App\Support\Contract;

/**
 * The two sides of a contract.
 *
 * An agreement is between somebody outside the company and somebody inside it,
 * and both sign. Naming the two in one place keeps the API, the signature rows
 * and the PDF speaking the same words -- a stable key, never a guessed string.
 *
 * `PARTY` covers a customer and a vendor alike: they sign the same way, through
 * the same public link, and splitting them would double every branch that
 * touches signing for no behavioural difference. Which kind of counterparty it
 * is lives on the contract's morph columns, where it belongs.
 */
final class ContractParty
{
    /** The counterparty -- the customer or vendor the agreement is with. */
    public const PARTY = 'party';

    /** Our own authorised representative. */
    public const COMPANY = 'company';

    public const ALL = [self::PARTY, self::COMPANY];

    public const LABELS = [
        self::PARTY   => 'Customer / Vendor',
        self::COMPANY => 'Company Representative',
    ];

    /** How a signature may be produced -- the four the brief allows. */
    public const METHODS = ['draw', 'type', 'upload', 'stamp'];

    public static function isValid(?string $party): bool
    {
        return $party !== null && in_array($party, self::ALL, true);
    }

    public static function label(?string $party): string
    {
        return self::LABELS[$party] ?? (string) $party;
    }
}
