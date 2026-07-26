<?php

namespace App\Support\Hr;

/**
 * External company account lifecycle (Company Portal).
 *
 *   Pending_Approval → Active → Inactive
 *                   └→ Rejected
 *
 * Both the company row and its portal user(s) move together at HR approval.
 * (Legacy HR-created companies use Active/Inactive directly.)
 */
class CompanyAccountStatus
{
    public const PENDING_APPROVAL = 'Pending_Approval';
    public const ACTIVE           = 'Active';
    public const INACTIVE         = 'Inactive';
    public const REJECTED         = 'Rejected';

    public const ALL = [
        self::PENDING_APPROVAL, self::ACTIVE, self::INACTIVE, self::REJECTED,
    ];
}
