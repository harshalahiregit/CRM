<?php

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/hr.php';
require __DIR__.'/sales.php';
require __DIR__.'/customer.php';
require __DIR__.'/accounts.php';
require __DIR__.'/api_helpdesk.php';
require __DIR__.'/api_projects.php';
require __DIR__.'/api_tasks.php';
require __DIR__.'/api_inventory.php';
require __DIR__.'/settings.php';
require __DIR__.'/public.php';

// Vendor master + third-party-vendor (TPV) module and its shared engines.
require __DIR__.'/vendors.php';
require __DIR__.'/tpv.php';
require __DIR__.'/compliance.php';
require __DIR__.'/shared.php';
