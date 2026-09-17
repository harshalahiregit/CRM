<?php

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
require __DIR__.'/hr.php';
// SangoeTrack (track.sangoe.in) — relays only, owns no CRM table.
// The attendance app's own surface, /api/Hrm/*, answering in SangoeTrack's
// shape so the app can be repointed by changing one line.
// Who work can be assigned to. Same gate as the other option lists, so a
// salesperson can read it — /api/admin/staff is admin-only and could not serve
// the screens that need an owner picker.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->group(function () {
    Route::get('/assignees', [\App\Http\Controllers\Api\AssigneeController::class, 'index']);
});

require __DIR__.'/hrm.php';
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

// The Contract module — company-wide, its own tables. Sales/Purchase/TPV keep
// their existing contract features; this one links out to their customers and
// vendors rather than replacing them.
require __DIR__.'/contract.php';
require __DIR__.'/medical.php';
require __DIR__.'/portal.php';
require __DIR__.'/company_portal.php';

// Sangoe Transport OS (STOS) — order/trip operations and financial control.
require __DIR__.'/transport.php';
// STOS (Sangoe Transport OS) — Fleet / Telemetry / Cost / Maintenance.
// Its telemetry ingest endpoint is device-authenticated, not session-based.
require __DIR__.'/stos.php';
