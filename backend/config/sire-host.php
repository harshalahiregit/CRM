<?php

/**
 * SIRE — host configuration. GENERATED FILE.
 *
 * Written by `php artisan sire:install`. Regenerated in full each time, so
 * running the installer twice produces an identical file and never duplicates
 * anything.
 *
 * Edit it freely — the installer will offer to keep your values on its next
 * run. Delete it and `sire:install` starts from discovery again.
 *
 * config/sire.php merges this on top of SIRE's defaults, so anything absent
 * here simply keeps its documented default.
 *
 * Generated: 2026-09-09 13:43:58
 */
return [
    'user' => [
        'model' => 'App\\Models\\User',
        'table' => 'users',
        'id_field' => 'id',
        'name_field' => 'name',
        'role_field' => 'role',
    ],
    'tenant' => [
        'model' => 'App\\Models\\Tenant',
        'strategy' => 'user_attribute',
        'attribute' => 'tenant_id',
    ],
    'host' => [
        'auth_middleware' => ['auth:sanctum'],
    ],
    'login_types' => [
        'admin' => ['admin'],
        'internal_user' => ['staff'],
        'customer' => ['client'],
        'vendor' => ['vendor', 'third_party_vendor'],
    ],
    'installed_at' => '2026-09-09T13:43:58+00:00',
];
