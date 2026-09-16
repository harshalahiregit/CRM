<?php

namespace Sire\Discovery\Detectors;

/**
 * SIRE — reads the host's composer.json, once.
 *
 * Several detectors want to know whether a package is installed, and each of
 * them opening and decoding the same file would be both wasteful and a place for
 * three different error-handling behaviours to appear.
 *
 * Read-only, and tolerant: a host with no readable composer.json (a deployment
 * that strips it, an unusual layout) yields "no packages" rather than an
 * exception. Every caller treats absence as a normal answer.
 */
class ComposerReader
{
    /** @var array<string, string>|null package => constraint */
    private ?array $packages = null;

    public function __construct(private readonly ?string $path = null)
    {
    }

    /** @return array<string, string> */
    public function packages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $path = $this->path ?? base_path('composer.json');

        if (! is_readable($path)) {
            return $this->packages = [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            return $this->packages = [];
        }

        return $this->packages = array_merge(
            (array) ($data['require'] ?? []),
            (array) ($data['require-dev'] ?? []),
        );
    }

    public function requires(string $package): bool
    {
        return array_key_exists($package, $this->packages());
    }

    /** The first of $candidates that is installed, or null. */
    public function firstOf(array $candidates): ?string
    {
        foreach ($candidates as $package) {
            if ($this->requires($package)) {
                return $package;
            }
        }

        return null;
    }

    /** Every installed package whose name matches a pattern. */
    public function matching(string $pattern): array
    {
        return array_values(array_filter(
            array_keys($this->packages()),
            static fn (string $name) => (bool) preg_match($pattern, $name),
        ));
    }
}
