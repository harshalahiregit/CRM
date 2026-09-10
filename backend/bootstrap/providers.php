<?php

use App\Providers\AccountingIntegrationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\SalesNumberingServiceProvider;
use App\Providers\TransportNumberingServiceProvider;

return [
    AppServiceProvider::class,
    AccountingIntegrationServiceProvider::class,
    SalesNumberingServiceProvider::class,
    TransportNumberingServiceProvider::class,
];
