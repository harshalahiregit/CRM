<?php

use App\Providers\AccountingIntegrationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\SalesNumberingServiceProvider;

return [
    AppServiceProvider::class,
    AccountingIntegrationServiceProvider::class,
    SalesNumberingServiceProvider::class,
];
