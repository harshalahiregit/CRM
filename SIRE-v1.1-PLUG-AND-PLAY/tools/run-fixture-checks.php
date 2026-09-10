<?php

/**
 * SIRE — run the real classification logic against the reference host fixtures.
 *
 * Plain PHP, no Laravel, no PHPUnit, no database. It loads the actual SIRE
 * classes — RoleClassifier, SireLoginType, Compatibility — and asserts they
 * reach the documented conclusion for three deliberately different hosts.
 *
 * The point is that these are the SAME classes the installer uses. A fixture
 * suite that reimplemented the rules would prove only that the reimplementation
 * agrees with itself.
 *
 * Usage: php tools/run-fixture-checks.php [--json]
 */

$root = dirname(__DIR__);

// A tiny PSR-4 loader: composer's autoloader is not available when SIRE is a
// bare ZIP, and requiring one to run the checks would defeat the purpose.
spl_autoload_register(static function (string $class) use ($root): void {
    if (! str_starts_with($class, 'Sire\\')) {
        return;
    }

    $path = $root.'/src/'.str_replace('\\', '/', substr($class, 5)).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});

// SireLoginType reads config() for its mapping. In fixture runs there is no
// Laravel, so we supply the one function it needs, backed by a global the
// harness sets per fixture.
if (! function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        global $sireFixtureConfig;

        return $sireFixtureConfig[$key] ?? $default;
    }
}

$failures = [];
$passes = 0;

function check(string $fixture, string $what, mixed $actual, mixed $expected): void
{
    global $failures, $passes;

    // Order is not meaningful for role lists; content is.
    if (is_array($actual) && is_array($expected)) {
        sort($actual);
        sort($expected);
    }

    if ($actual === $expected) {
        $passes++;

        return;
    }

    $failures[] = sprintf(
        "%s · %s\n      expected: %s\n      actual:   %s",
        $fixture, $what,
        json_encode($expected),
        json_encode($actual),
    );
}

$classifier = new Sire\Discovery\RoleClassifier;

foreach (glob($root.'/fixtures/hosts/*.json') as $file) {
    $fixture = json_decode((string) file_get_contents($file), true);
    $name = $fixture['name'];
    $discovered = $fixture['discovered'];
    $expected = $fixture['expected'];

    // ---- 1. role classification -------------------------------------------
    $classified = $classifier->classifyAll($discovered['roles.available']);

    foreach (['admin', 'internal_user', 'customer', 'vendor'] as $type) {
        check($name, "login_types.{$type}", $classified[$type], $expected["login_types.{$type}"]);
    }

    // Every role must land somewhere, or somebody silently loses access.
    check($name, 'no role left unclassified', $classified['unclassified'], []);

    // ---- 2. the login-type gate actually gates -----------------------------
    $GLOBALS['sireFixtureConfig'] = [
        'sire.login_types.admin'         => $expected['login_types.admin'],
        'sire.login_types.internal_user' => $expected['login_types.internal_user'],
        'sire.login_types.customer'      => $expected['login_types.customer'],
        'sire.login_types.vendor'        => $expected['login_types.vendor'],
    ];

    foreach ($expected['login_types.admin'] as $role) {
        check($name, "ADMIN role '{$role}' reaches SIRE", Sire\Support\SireLoginType::permits(
            new Sire\Dto\SireUserIdentity(1, 1, 'T', $role),
        ), true);
    }

    foreach ($expected['login_types.internal_user'] as $role) {
        check($name, "INTERNAL role '{$role}' reaches SIRE", Sire\Support\SireLoginType::permits(
            new Sire\Dto\SireUserIdentity(1, 1, 'T', $role),
        ), true);
    }

    // The one that matters most: outside accounts must be refused.
    foreach (array_merge($expected['login_types.customer'], $expected['login_types.vendor']) as $role) {
        check($name, "OUTSIDE role '{$role}' is BLOCKED", Sire\Support\SireLoginType::permits(
            new Sire\Dto\SireUserIdentity(1, 1, 'T', $role),
        ), false);
    }

    // An unmapped role gets nothing, whatever it is called.
    check($name, 'unmapped role is blocked', Sire\Support\SireLoginType::permits(
        new Sire\Dto\SireUserIdentity(1, 1, 'T', 'some_role_nobody_mapped'),
    ), false);

    // ---- 3. tenancy --------------------------------------------------------
    $columns = $discovered['tenant.columns_present'];

    check(
        $name,
        'tenant attribute',
        $columns === [] ? null : $columns[0],
        $expected['tenant.attribute'],
    );

    // solo has no tenant column: discovery must propose NOTHING rather than
    // guess, because a wrong guess here is silent.
    check(
        $name,
        'tenant strategy proposal',
        $columns === [] ? null : 'user_attribute',
        $expected['tenant.strategy'],
    );

    // ---- 4. auth middleware ------------------------------------------------
    $packages = $discovered['auth.packages'];
    $guards = $discovered['auth.guards'];

    $middleware = match (true) {
        in_array('laravel/sanctum', $packages, true)  => ['auth:sanctum'],
        in_array('laravel/passport', $packages, true) => ['auth:api'],
        default => ['auth:'.$guards[0]],
    };

    check($name, 'auth middleware', $middleware, $expected['host.auth_middleware']);

    // ---- 5. frontend -------------------------------------------------------
    $fe = $discovered['frontend.packages'];

    $type = match (true) {
        in_array('@inertiajs/react', $fe, true) => 'inertia-react',
        in_array('react', $fe, true)            => 'react',
        in_array('vue', $fe, true)              => 'vue',
        default                                  => null,
    };

    check($name, 'frontend type', $type, $expected['frontend.type']);

    // ---- 6. nothing SIRE needs is missing ----------------------------------
    // solo has no audit, notes or KB tables at all. SIRE must not require them.
    check($name, 'installs without host audit/notes/KB', true, ! ($expected['compatibility.blocked'] ?? false));
}

if (in_array('--json', $argv, true)) {
    echo json_encode(['passes' => $passes, 'failures' => $failures], JSON_PRETTY_PRINT), PHP_EOL;
    exit($failures === [] ? 0 : 1);
}

echo PHP_EOL, 'SIRE reference host fixtures', PHP_EOL, PHP_EOL;

if ($failures === []) {
    printf("  %d checks passed across %d host shapes.%s", $passes, count(glob($root.'/fixtures/hosts/*.json')), PHP_EOL);
    echo PHP_EOL;
    exit(0);
}

printf("  %d passed, %d FAILED%s%s", $passes, count($failures), PHP_EOL, PHP_EOL);

foreach ($failures as $failure) {
    echo '    ', $failure, PHP_EOL, PHP_EOL;
}

exit(1);
