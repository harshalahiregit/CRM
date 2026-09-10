<?php

/**
 * SIRE — every assumption SIRE makes about its host, in one file.
 *
 * Adapting SIRE to a different CRM is a CONFIG EDIT, not a code change. Nothing
 * here changes SIRE's behaviour; it changes only where SIRE looks and which
 * implementation answers each question.
 *
 * `php artisan sire:discover` inspects your application and proposes values.
 * `php artisan sire:install` writes the ones you confirm into config/sire-host.php,
 * which is merged over these defaults at the bottom of this file.
 *
 * THAT SPLIT IS DELIBERATE. This file is SIRE's, published from the package, and
 * most of it is the comments explaining what each setting means. Rewriting it
 * programmatically would destroy them the first time anyone ran the installer.
 * sire-host.php is small, generated, and yours: read it at a glance, diff it,
 * commit it, or delete it to start again.
 */

$sireDefaults = [

    /*
    |--------------------------------------------------------------------------
    | Host integration — routing and authentication
    |--------------------------------------------------------------------------
    | SIRE hardcodes NO middleware and NO role names. Set these to whatever your
    | application already uses: Sanctum, session, Passport, JWT, a custom guard.
    |
    | `auth_middleware` MUST authenticate. SIRE fails closed if it is empty:
    | publishing an engineering issue tracker to anonymous traffic is not a
    | default anyone should be able to reach by leaving a config blank.
    */
    'host' => [
        'route_prefix'    => env('SIRE_ROUTE_PREFIX', 'api/sire'),
        'route_name'      => 'sire.',

        // e.g. ['auth:sanctum'] · ['auth:web'] · ['auth:api'] · ['jwt.auth']
        'auth_middleware' => ['auth'],

        // Extra middleware applied after authentication. Leave empty and SIRE
        // gates on capabilities alone, which is the portable default.
        // e.g. ['role:admin,staff'] · ['can:access-sire'] · ['verified']
        'role_middleware' => [],

        // Applied before everything else. 'api' or 'web', usually.
        'middleware_group' => 'api',
    ],

    /*
    |--------------------------------------------------------------------------
    | The SDK: thirteen providers
    |--------------------------------------------------------------------------
    | Point any of these at your own class to connect that subsystem to the host.
    | Mixing is expected — wire tenancy and user first, leave the rest on SIRE's
    | own implementations for as long as you like.
    |
    | Contracts:  Sire\Contracts\Sire*Provider
    | Defaults:   Sire\Adapters\Defaults\SireLocal*
    | Stubs:      examples/host-adapters/Host*.php
    | Guide:      docs/ADAPTERS.md
    */
    'providers' => [
        'tenant'        => \Sire\Adapters\Defaults\SireLocalTenantProvider::class,
        'user'          => \Sire\Adapters\Defaults\SireLocalUserProvider::class,
        'authorization' => \Sire\Adapters\Defaults\SireLocalAuthorizationProvider::class,
        'notification'  => \Sire\Adapters\Defaults\SireLocalNotificationProvider::class,
        'attachment'    => \Sire\Adapters\Defaults\SireLocalAttachmentProvider::class,
        'audit'         => \Sire\Adapters\Defaults\SireLocalAuditProvider::class,
        'notes'         => \Sire\Adapters\Defaults\SireLocalNotesProvider::class,
        'numbering'     => \Sire\Adapters\Defaults\SireLocalNumberingProvider::class,
        'settings'      => \Sire\Adapters\Defaults\SireLocalSettingsProvider::class,
        'sla'           => \Sire\Adapters\Defaults\SireLocalSlaProvider::class,
        'knowledge'     => \Sire\Adapters\Defaults\SireLocalKnowledgeProvider::class,
        'version'       => \Sire\Adapters\Defaults\SireLocalVersionProvider::class,
        'context'       => \Sire\Adapters\Defaults\SireLocalContextProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | TENANCY — the most dangerous setting in this file
    |--------------------------------------------------------------------------
    | Every other misconfiguration fails loudly. This one fails SILENTLY, by
    | returning another tenant's data on a page that renders perfectly normally.
    |
    | `sire:install` will not write a tenant strategy without your explicit
    | confirmation, and `sire:doctor` re-checks it on every run.
    |
    | STRATEGIES
    |
    |   user_attribute   the authenticated user carries the tenant id directly.
    |                    Set `attribute` to the column: tenant_id, organization_id,
    |                    company_id, workspace_id, account_id …
    |
    |   relationship     the user belongs to a tenant model.
    |                    Set `relation` ('tenant', 'organization', …) and
    |                    `key` (usually 'id').
    |
    |   resolver         a class in your application resolves it. Set `resolver`
    |                    to a class with a method returning an int|string tenant
    |                    id. Set `method` if it is not `currentTenantId`.
    |
    |   callable         set `callable` to a `Class@method` or a closure bound in
    |                    the container. The freest option, and the one to reach
    |                    for when tenancy comes from a subdomain or a header
    |                    resolved by middleware.
    |
    |   single_tenant    the application is NOT multi-tenant. Every SIRE record
    |                    is stamped with `tenant_id` below. Choose this
    |                    deliberately: it is correct for a single-company CRM and
    |                    catastrophic for a shared one.
    */
    'tenant' => [
        'strategy'  => env('SIRE_TENANT_STRATEGY', 'user_attribute'),

        'attribute' => 'tenant_id',   // user_attribute
        'relation'  => 'tenant',      // relationship
        'key'       => 'id',          // relationship
        'resolver'  => null,          // resolver: Fully\Qualified\ClassName
        'method'    => 'currentTenantId',
        'callable'  => null,          // callable: 'Class@method'
        'tenant_id' => 1,             // single_tenant

        // Where to read a tenant's display name. Optional; null means unnamed.
        'model'      => null,
        'name_field' => 'name',
    ],

    /*
    |--------------------------------------------------------------------------
    | IDENTITY — where SIRE reads users from
    |--------------------------------------------------------------------------
    | Used only by SireLocalUserProvider. A host whose users are not an ordinary
    | Eloquent table — an identity service, LDAP, a federated directory —
    | implements SireUserProvider instead and these are ignored.
    */
    'user' => [
        'model'      => null,          // e.g. \App\Models\User::class
        'table'      => 'users',
        'id_field'   => 'id',
        'name_field' => 'name',
        'role_field' => 'role',
        'email_field' => null,         // null = SIRE never reads an email address
    ],

    /*
    |--------------------------------------------------------------------------
    | THE FOUR LOGIN TYPES
    |--------------------------------------------------------------------------
    | SIRE classifies every host role into one of four categories, and only two
    | of them reach SIRE at all.
    |
    |   ADMIN          full SIRE access, subject to capabilities
    |   INTERNAL_USER  engineering access, subject to capabilities
    |   CUSTOMER       NO SIRE access
    |   VENDOR         NO SIRE access
    |
    | These are DEFAULTS, and the mapping below is yours to edit — SIRE does not
    | know what your roles are called. `sire:discover` proposes a mapping from
    | the role names it finds; `sire:install` makes you confirm it, because
    | mapping a customer role to INTERNAL_USER exposes your defect backlog to
    | your customers.
    |
    | A role that appears in NO list gets no SIRE access. Fail closed.
    */
    'login_types' => [
        'admin'         => [],   // e.g. ['super_admin', 'administrator']
        'internal_user' => [],   // e.g. ['employee', 'developer', 'qa']
        'customer'      => [],   // e.g. ['client']
        'vendor'        => [],   // e.g. ['supplier', 'third_party_vendor']
    ],

    /*
    |--------------------------------------------------------------------------
    | Route → screen map
    |--------------------------------------------------------------------------
    | What makes Report Issue one click instead of a form: SIRE has to know which
    | screen the user was on before they say anything.
    |
    | Leave empty and SIRE resolves from whatever `sire:discover` found, then
    | falls back to a low-confidence, user-correctable guess. A screen can also
    | declare itself from the SPA — SireContext.register({...}) — which always
    | wins, and is the only way to describe a wizard whose step lives in
    | component state.
    */
    'route_map'     => [],
    'module_labels' => [],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    | Used only by SireLocalAttachmentProvider. Bind a host attachment provider
    | and its own disk, limits and scanning apply instead.
    */
    'attachments' => [
        'disk'   => env('SIRE_ATTACHMENT_DISK', 'local'),
        'path'   => 'sire',
        'max_kb' => 10240,
        'accept' => ['image/png', 'image/jpeg', 'image/webp', 'application/pdf', 'text/plain'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults applied when a tenant has no `sire.*` setting
    |--------------------------------------------------------------------------
    | Every default here is the SAFE choice: SLA reports on-track rather than
    | breaching, AI is off, release gates are on.
    |
    | Flat keys, not nested — SIRE setting names contain dots, and nesting would
    | make 'sire.sla.policies' a path rather than a key.
    */
    'defaults' => [
        'sire.auto_ready_for_release' => true,
        'sire.sla.warning_threshold'  => 0.8,
        'sire.sla.policies'           => [],
        'sire.notifications.disabled' => [],
        'sire.ai.enabled'             => false,
        'sire.ai.provider'            => 'null',
        'sire.ai.capabilities'        => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application version
    |--------------------------------------------------------------------------
    | Stamped on new issues as detected_version. Null is fine: a null version is
    | honest, a wrong one poisons every release dashboard.
    */
    'version' => env('SIRE_APP_VERSION', env('APP_VERSION')),

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    | One command, every 15 minutes: recompute SLA state and send warning/breach
    | notices. No queue worker, no Redis, no Horizon. With no scheduler at all,
    | SLA state is still computed on read; only proactive notices are lost.
    */
    'schedule' => [
        'enabled'  => true,
        'interval' => 'everyFifteenMinutes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'log_channel' => env('SIRE_LOG_CHANNEL', null),

    /*
    |--------------------------------------------------------------------------
    | Discovery / installation artefacts
    |--------------------------------------------------------------------------
    | Written under storage/app, never under public/. They describe your
    | application's shape and must not be web-reachable.
    */
    'storage_path' => 'sire',
];

/*
|--------------------------------------------------------------------------
| Host configuration, merged over the defaults above
|--------------------------------------------------------------------------
| Written by `php artisan sire:install`. Absent until you run it, which is
| why every default above is the SAFE choice rather than a convenient one:
| an un-installed SIRE denies all traffic, runs no SLA policy and has no AI.
|
| Merged RECURSIVELY, so sire-host.php can set sire.tenant.attribute without
| having to restate the whole tenant block.
*/
$sireHost = file_exists($hostConfig = __DIR__.'/sire-host.php') ? require $hostConfig : [];

/**
 * A targeted deep merge: associative arrays merge, LISTS REPLACE.
 *
 * That distinction matters more than it looks. If lists merged,
 * `login_types.customer` could never be narrowed — removing a role from the
 * host file would leave it silently granted by the default beneath. Replacing
 * means what you write is what you get, which is the only safe behaviour for a
 * setting that decides who reads the defect backlog.
 */
$sireMerge = static function (array $base, array $override) use (&$sireMerge): array {
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])
            && ! array_is_list($value) && ! array_is_list($base[$key])) {
            $base[$key] = $sireMerge($base[$key], $value);

            continue;
        }

        $base[$key] = $value;
    }

    return $base;
};

return $sireMerge($sireDefaults, is_array($sireHost) ? $sireHost : []);
