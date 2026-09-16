<?php

namespace Sire\Discovery;

use Illuminate\Support\Facades\File;
use Sire\Discovery\Detectors\AuthDetector;
use Sire\Discovery\Detectors\ComposerReader;
use Sire\Discovery\Detectors\Detector;
use Sire\Discovery\Detectors\FrontendDetector;
use Sire\Discovery\Detectors\IntegrationDetector;
use Sire\Discovery\Detectors\PlatformDetector;
use Sire\Discovery\Detectors\RoleDetector;
use Sire\Discovery\Detectors\SchedulerDetector;
use Sire\Discovery\Detectors\TenancyDetector;
use Sire\Discovery\Detectors\UserDetector;

/**
 * SIRE — inspects the host and writes a profile. READ-ONLY, ALWAYS.
 *
 * Discovery reads the schema, the container, config, composer.json, package.json
 * and the filesystem. It writes exactly one file, in storage/app, and touches
 * nothing else: no migrations, no rows, no cache flush, no events, no
 * configuration. It is meant to be the kind of thing you can run against
 * production without asking anyone.
 *
 * The only data it reads — as opposed to schema — is the DISTINCT ROLE NAMES,
 * because the alternative is asking a developer to type them and hoping they do
 * not make a typo in the mapping that decides who sees the defect backlog.
 *
 * ONE FAILING DETECTOR MUST NOT COST YOU THE OTHERS
 *
 * Each runs inside its own try/catch. A host with an unreadable schema still
 * gets its framework, auth and frontend findings, and the failure is recorded as
 * a finding of its own rather than a stack trace.
 */
class HostDiscovery
{
    /** @var array<int, Detector> */
    private array $detectors;

    public function __construct(?array $detectors = null)
    {
        $composer = new ComposerReader;

        $this->detectors = $detectors ?? [
            new PlatformDetector,
            new AuthDetector($composer),
            new UserDetector,
            new TenancyDetector($composer),
            new RoleDetector($composer),
            new IntegrationDetector($composer),
            new FrontendDetector,
            new SchedulerDetector,
        ];
    }

    public function run(): HostProfile
    {
        $findings = [];

        foreach ($this->detectors as $detector) {
            try {
                foreach ($detector->detect() as $key => $finding) {
                    $findings[$key] = $finding;
                }
            } catch (\Throwable $e) {
                // Recorded, not thrown. A survey that aborts on its first
                // unreadable area is worth less than one that reports it.
                $findings["_errors.{$detector->name()}"] = Finding::found(
                    $e->getMessage(),
                    Finding::HIGH,
                    ['detector threw: '.$e::class],
                );
            }
        }

        return new HostProfile($findings, now()->toIso8601String());
    }

    /**
     * Where the profile lives.
     *
     * storage/app, never public/. This file describes an application's
     * authentication, tenancy and role model — it is a map of the doors, and it
     * must not be web-reachable.
     */
    public static function path(): string
    {
        return storage_path('app/'.trim((string) config('sire.storage_path', 'sire'), '/').'/host-profile.json');
    }

    public function save(HostProfile $profile): string
    {
        $path = self::path();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($profile->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    public static function load(): ?HostProfile
    {
        $path = self::path();

        if (! is_readable($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? HostProfile::fromArray($data) : null;
    }
}
