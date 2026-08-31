<?php

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/hr.php';
// SangoeTrack (track.sangoe.in) — relays only, owns no CRM table.
require __DIR__.'/sangoetrack.php';
require __DIR__.'/performance.php';
require __DIR__.'/leave.php';
require __DIR__.'/exit.php';
require __DIR__.'/learning.php';
require __DIR__.'/survey.php';
require __DIR__.'/probation.php';
require __DIR__.'/notifications.php';
require __DIR__.'/recruitment_services.php';
require __DIR__.'/employee_onboarding.php';
require __DIR__.'/sales.php';
require __DIR__.'/customer.php';
require __DIR__.'/accounts.php';
require __DIR__.'/api_helpdesk.php';
require __DIR__.'/api_projects.php';
require __DIR__.'/api_tasks.php';
require __DIR__.'/api_inventory.php';
require __DIR__.'/settings.php';
require __DIR__.'/public.php';

// HR recruitment: public career portal, candidate onboarding, offer acceptance.
require __DIR__.'/careers.php';
require __DIR__.'/onboarding.php';
require __DIR__.'/offer.php';

// Vendor master + third-party-vendor (TPV) module and its shared engines.
require __DIR__.'/vendors.php';
require __DIR__.'/tpv.php';
require __DIR__.'/compliance.php';
require __DIR__.'/shared.php';

// Purchase / procure-to-pay module + the vendor + company self-service portals.
require __DIR__.'/purchase.php';
require __DIR__.'/portal.php';
require __DIR__.'/company_portal.php';
