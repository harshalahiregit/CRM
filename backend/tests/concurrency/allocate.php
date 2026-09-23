<?php
// One dispatcher, racing another — see race-allocation.sh. D-136.
require '/home/mohammad-raza/Desktop/sangoe_crm/backend/vendor/autoload.php';
$app = require_once '/home/mohammad-raza/Desktop/sangoe_crm/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\TripAssignmentService;

$tripId    = (int) $argv[1];
$vehicleId = (int) $argv[2];
$startAt   = (float) $argv[3];   // unix time both processes wait for

// Line both runners up on the same instant so they genuinely overlap.
while (microtime(true) < $startAt) { usleep(200); }

$trip  = TransportTrip::withoutGlobalScopes()->find($tripId);
$actor = User::find((int) $argv[5]);

try {
    $a = app(TripAssignmentService::class)->assign($trip, $vehicleId, null, (int) $argv[4], $actor);
    echo "OK assignment #{$a->id} trip {$tripId}\n";
} catch (\Throwable $e) {
    echo "REFUSED ".class_basename($e).": ".$e->getMessage()."\n";
}
