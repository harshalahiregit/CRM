<?php
/**
 * Quick artisan helper — run database setup
 * Usage: php setup.php
 */

$steps = [
    'Generate APP_KEY'       => 'php artisan key:generate --ansi',
    'Publish Sanctum'        => 'php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --ansi',
    'Run Migrations'         => 'php artisan migrate --force --ansi',
    'Seed Database'          => 'php artisan db:seed --force --ansi',
];

foreach ($steps as $label => $cmd) {
    echo "\n[→] $label\n";
    echo "    $cmd\n";
    passthru($cmd, $code);
    if ($code !== 0) {
        echo "\n[✗] FAILED at: $label\n";
        exit(1);
    }
    echo "[✓] Done\n";
}

echo "\n\n✅ Setup complete! Run: php artisan serve\n";
