<?php

namespace Sire\Installation;

/**
 * SIRE — the architecture rules, as runnable code.
 *
 * Deliberately free of Laravel: no Command, no container, no facades. It takes a
 * package root and returns findings, which means it can be run by the artisan
 * command, by the test suite, and by CI — against this package or against a
 * fork of it.
 *
 * The claim "SIRE core is CRM-agnostic" is worth exactly what this class says it
 * is. Everything else is documentation.
 *
 * WHAT IT LOOKS FOR
 *
 *   1. Host namespaces imported by core (App\Models\User, App\Services\*, …)
 *   2. Hardcoded authentication middleware (auth:sanctum in a route file)
 *   3. Hardcoded role names in comparisons ('admin', 'staff')
 *   4. Tenancy read directly off a user instead of through the provider
 *   5. Host frontend imports that climb out of the SIRE tree
 *
 * ADAPTERS ARE EXEMPT, AND THAT IS THE POINT
 *
 * src/Adapters exists precisely to touch host things, and examples/ is full of
 * deliberate App\Models\User references because that is what a host adapter
 * looks like. Flagging those would train people to ignore this check, which is
 * worse than not having it.
 */
final class ArchitectureChecker
{
    /** Directories that may legitimately reference host specifics. */
    private const EXEMPT = ['/Adapters/', '/Discovery/', '/Installation/', '/Console/'];

    public function __construct(private readonly string $package)
    {
    }

    /** @return array<int, array{name: string, status: string, detail: string, findings: array}> */
    public function run(): array
    {
        $src = $this->package.'/src';

        return [
            $this->hostNamespaces($src),
            $this->hardcodedMiddleware($this->package),
            $this->hardcodedRoles($src),
            $this->hardcodedTenantFields($src),
            $this->frontendImports($this->package.'/resources/js'),
        ];
    }

    public function passed(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'FAIL') {
                return false;
            }
        }

        return true;
    }

    private function hostNamespaces(string $root): array
    {
        $findings = [];

        foreach ($this->phpFiles($root) as $file) {
            if ($this->exempt($file)) {
                continue;
            }

            foreach (file($file) as $no => $line) {
                if (preg_match('/^use (App\\\\[\w\\\\]+)/', trim($line), $m)) {
                    $findings[] = $this->rel($file).':'.($no + 1).'  imports '.$m[1];
                }
            }
        }

        return $this->check(
            'Host namespaces in core',
            $findings,
            'SIRE core imports no App\\* class. Adapters and examples may.',
            'Move the dependency behind an SDK contract — see docs/ADAPTERS.md.',
        );
    }

    private function hardcodedMiddleware(string $package): array
    {
        $findings = [];

        foreach (glob($package.'/routes/*.php') ?: [] as $file) {
            foreach (file($file) as $no => $line) {
                // Comments are skipped. The route file DOCUMENTS 'auth:sanctum'
                // as an example of what to put in config, and flagging that
                // would train people to ignore this check — which is worse than
                // not running it.
                $trimmed = ltrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                if (preg_match("/'(auth:[\w-]+|role:[\w,-]+|can:[\w.-]+)'/", $line, $m)) {
                    $findings[] = $this->rel($file).':'.($no + 1).'  '.$m[1];
                }
            }
        }

        return $this->check(
            'Hardcoded auth middleware',
            $findings,
            "Route middleware comes from config('sire.host.*).",
            "Replace with config('sire.host.auth_middleware').",
        );
    }

    private function hardcodedRoles(string $root): array
    {
        $findings = [];
        // Role names inside a COMPARISON. A string in a hint list or a label is
        // not a hardcoded gate, and flagging those makes the check noise.
        $pattern = "/(?:in_array\s*\(|===\s*|==\s*|whereIn\s*\([^,]+,\s*\[)[^;]{0,80}'(admin|staff|superadmin|super_admin|administrator|client|customer|vendor|supplier)'/i";

        foreach ($this->phpFiles($root) as $file) {
            if ($this->exempt($file) || str_contains($file, 'SireLoginType')) {
                continue;
            }

            foreach (file($file) as $no => $line) {
                if (preg_match($pattern, $line, $m)) {
                    $findings[] = $this->rel($file).':'.($no + 1).'  compares against '.$m[1];
                }
            }
        }

        return $this->check(
            'Hardcoded role names',
            $findings,
            'Roles reach SIRE only through SireLoginType and config(\'sire.login_types\').',
            'Use SireLoginType::forRole() or a capability check instead.',
        );
    }

    private function hardcodedTenantFields(string $root): array
    {
        $findings = [];

        foreach ($this->phpFiles($root) as $file) {
            if ($this->exempt($file)) {
                continue;
            }

            foreach (file($file) as $no => $line) {
                $trimmed = ltrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                    continue;
                }

                // THE DISTINCTION THAT MATTERS, and it is a naming convention
                // doing real work:
                //
                //   $user->tenant_id   snake_case — a HOST Eloquent model. Reading
                //                      it bypasses whichever tenant strategy the
                //                      host configured, and is wrong for four of
                //                      the five strategies.
                //
                //   $user->tenantId    camelCase — SireUserIdentity, whose tenantId
                //                      was populated BY the tenant provider. Reading
                //                      it IS going through the provider.
                //
                // So only snake_case is a finding. That is why SireUserIdentity
                // uses camelCase in the first place.
                if (preg_match('/\$(?:user|actor|viewer|author)\w*\s*(?:\?)?->\s*tenant_id\b/', $line)) {
                    $findings[] = $this->rel($file).':'.($no + 1).'  reads $user->tenant_id (host model) directly';
                }

                if (preg_match('/auth\(\)\s*->\s*user\(\)\s*(?:\?)?->\s*tenant/', $line)) {
                    $findings[] = $this->rel($file).':'.($no + 1).'  reads auth()->user()->tenant… directly';
                }
            }
        }

        return $this->check(
            'Hardcoded tenant resolution',
            $findings,
            'Tenancy is resolved only through SireTenantProvider; SireUserIdentity->tenantId carries its answer.',
            'Inject SireTenantProvider and call currentTenant().',
        );
    }

    private function frontendImports(string $js): array
    {
        $findings = [];

        if (! is_dir($js)) {
            return $this->check('Host frontend imports', [], 'No shipped SPA to check.', '');
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($js));

        foreach ($iterator as $file) {
            if (! preg_match('/\.(js|jsx)$/', $file->getFilename())) {
                continue;
            }

            foreach (file($file->getPathname()) as $no => $line) {
                $trimmed = ltrim($line);

                // Comments are skipped: the host bridge DOCUMENTS the imports it
                // exists to replace, and flagging its own examples would make
                // this check permanently red.
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                // A relative import that climbs OUT of the SIRE tree is reaching
                // into host files SIRE cannot guarantee exist.
                if (preg_match("/from\s+'((?:\.\.\/){3,}[^']+)'/", $line, $m)
                    && ! str_contains($m[1], 'sire')) {
                    $findings[] = $this->rel($file->getPathname()).':'.($no + 1).'  '.$m[1];
                }
            }
        }

        return $this->check(
            'Host frontend imports',
            $findings,
            'The SPA resolves host UI through the SireHost bridge, not by reaching up the tree.',
            'Import from lib/sire/host instead — see docs/FRONTEND-INTEGRATION.md.',
        );
    }

    // ------------------------------------------------------------------ support

    private function check(string $name, array $findings, string $passDetail, string $fixHint): array
    {
        return [
            'name'     => $name,
            'status'   => $findings === [] ? 'PASS' : 'FAIL',
            'detail'   => $findings === [] ? $passDetail : count($findings).' occurrence(s). '.$fixHint,
            'findings' => $findings,
        ];
    }

    private function exempt(string $file): bool
    {
        foreach (self::EXEMPT as $dir) {
            if (str_contains(str_replace('\\', '/', $file), $dir)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function phpFiles(string $root): array
    {
        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    private function rel(string $path): string
    {
        return ltrim(str_replace($this->package, '', $path), '/');
    }
}
