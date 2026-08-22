<?php

namespace App\Support\Purchase;

/**
 * State of a Purchase MOM decision (Sangoe TPV §9 — decisions recorded in a
 * meeting's minutes). A decision is a durable record, not a workflow: it stands
 * as Active until it is Superseded by a later decision or Rescinded. Any of the
 * three states is directly selectable — there is no ordered transition map.
 *
 * Purchase-owned mirror of the shared decision_statuses vocab — the two engines
 * never share code or tables. Stored on purchase_mom_decisions.status.
 */
final class PurchaseMomDecisionStatus
{
    public const ACTIVE = 'Active';

    public const SUPERSEDED = 'Superseded';

    public const RESCINDED = 'Rescinded';

    public const ALL = [self::ACTIVE, self::SUPERSEDED, self::RESCINDED];

    public const LABELS = [
        self::ACTIVE     => 'Active',
        self::SUPERSEDED => 'Superseded',
        self::RESCINDED  => 'Rescinded',
    ];

    public static function label(?string $v): string
    {
        return self::LABELS[$v] ?? (string) ($v ?: self::ACTIVE);
    }

    public static function isValid(?string $v): bool
    {
        return in_array($v, self::ALL, true);
    }
}
