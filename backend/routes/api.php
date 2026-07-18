<?php

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/hr.php';
require __DIR__.'/sales.php';
require __DIR__.'/public.php';

// Vendor master + third-party-vendor (TPV) module and its shared engines.
require __DIR__.'/vendors.php';
require __DIR__.'/tpv.php';
require __DIR__.'/compliance.php';
require __DIR__.'/shared.php';

// Purchase / procure-to-pay module + the vendor self-service portal.
require __DIR__.'/purchase.php';
require __DIR__.'/portal.php';
