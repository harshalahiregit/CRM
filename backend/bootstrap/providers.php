<?php

use App\Providers\AccountingIntegrationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\SalesNumberingServiceProvider;

return [
    AppServiceProvider::class,
    AccountingIntegrationServiceProvider::class,
    SalesNumberingServiceProvider::class,

    // SIRE (packages/sire) - copy-installed, so no package auto-discovery.
    // This one line brings its config, routes, migrations, commands and
    // the 13 SDK bindings. It adds; it edits no host file.
    Sire\SireServiceProvider::class,
];
