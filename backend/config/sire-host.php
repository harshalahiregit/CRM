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
        // Off by default in SIRE, because no SIRE feature needs an address and
        // what it never reads it cannot leak. Turned on deliberately so the
        // notification provider below has somewhere to send mail.
        'email_field' => 'email',
    ],

    /*
    | Delivery is the host's job. Without this SIRE runs on its shipped provider,
    | which LOGS the notification and sends nothing -- so filing or assigning an
    | issue told nobody at all.
    |
    | SIRE still decides the audience (actor excluded, de-duplicated, collapsed);
    | this only carries it to the bell and, for events that mean "you now have
    | work", to the tenant's own SMTP.
    */
    'providers' => [
        'notification' => \App\Sire\Host\HostNotificationProvider::class,
        // The Customer Directory at /app/customers. Read-only: SIRE names a
        // client on a defect so "which customers are hitting this" has an
        // answer, and never writes to one.
        'customer'     => \App\Sire\Host\HostCustomerProvider::class,
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
