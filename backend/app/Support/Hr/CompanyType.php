<?php

namespace App\Support\Hr;

/** External company relationship type (Company Portal). */
class CompanyType
{
    public const CLIENT         = 'Client';
    public const VENDOR         = 'Vendor';
    public const PARTNER        = 'Partner';
    public const CONSULTANT     = 'Consultant';
    public const SUB_CONTRACTOR = 'Sub Contractor';

    public const ALL = [
        self::CLIENT, self::VENDOR, self::PARTNER, self::CONSULTANT, self::SUB_CONTRACTOR,
    ];
}
