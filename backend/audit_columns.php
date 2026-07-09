<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = [
    'hr_manpower_requests',
    'hr_job_postings',
    'hr_candidates',
    'hr_interview_rounds',
    'hr_offers',
    'hr_onboarding',
    'hr_employees',
];

echo "=== DB COLUMN AUDIT ===\n\n";
foreach ($tables as $t) {
    $cols = DB::getSchemaBuilder()->getColumnListing($t);
    echo strtoupper($t).":\n  ".implode(', ', $cols)."\n\n";
}
