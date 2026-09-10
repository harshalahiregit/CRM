<?php

namespace Sire\Discovery;

/**
 * SIRE — everything discovery learned about the host, as one document.
 *
 * Written to storage/app/sire/host-profile.json, which matters: `storage/app`
 * is not web-reachable, and this file describes an application's authentication,
 * tenancy and role model. It is a map of the doors.
 *
 * WHAT IT NEVER CONTAINS
 *
 * No passwords, tokens, API keys, database credentials, cookies, session data or
 * environment secrets. Discovery reads SHAPES — class names, column names,
 * package names — never values. `redacted()` is the belt to that braces, and
 * `sire:host-profile` prints only what it returns.
 *
 * WHY IT IS CACHED RATHER THAN COMPUTED
 *
 * Discovery inspects the schema, the container and the filesystem. Doing that on
 * a page load would be indefensible, so it runs during installation or when a
 * developer asks, and everything afterwards reads this file. A host that changes
 * shape re-runs `sire:discover`.
 */
final class HostProfile
{
    public const VERSION = 1;

    /** @param array<string, Finding> $findings keyed by dotted path, e.g. 'tenant.attribute' */
    public function __construct(
        public readonly array $findings = [],
        public readonly ?string $generatedAt = null,
        public readonly int $schemaVersion = self::VERSION,
    ) {
    }

    public function get(string $key): Finding
    {
        return $this->findings[$key] ?? Finding::absent();
    }

    public function value(string $key, mixed $default = null): mixed
    {
        $finding = $this->get($key);

        return $finding->isPresent() ? $finding->value : $default;
    }

    public function has(string $key): bool
    {
        return $this->get($key)->isPresent();
    }

    /** @return array<string, Finding> every finding whose key starts with $prefix */
    public function section(string $prefix): array
    {
        $out = [];

        foreach ($this->findings as $key => $finding) {
            if (str_starts_with($key, $prefix.'.') || $key === $prefix) {
                $out[$key] = $finding;
            }
        }

        return $out;
    }

    public function with(string $key, Finding $finding): self
    {
        return new self([...$this->findings, $key => $finding], $this->generatedAt, $this->schemaVersion);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'schema_version' => $this->schemaVersion,
            'generated_at'   => $this->generatedAt,
            'findings'       => [],
        ];

        foreach ($this->findings as $key => $finding) {
            $out['findings'][$key] = $finding->toArray();
        }

        return $out;
    }

    /**
     * The version safe to print, paste into a ticket, or email to support.
     *
     * Discovery already avoids reading secrets, so this is defence in depth
     * rather than the primary control — but "we were careful" is not a thing
     * anyone should have to take on trust about a file describing their auth
     * configuration.
     */
    public function redacted(): array
    {
        $unsafe = '/(password|secret|token|key|credential|dsn|salt|hash|cookie|session)/i';
        $data = $this->toArray();

        foreach ($data['findings'] as $key => $finding) {
            if (preg_match($unsafe, $key)) {
                $data['findings'][$key]['value'] = '[redacted]';
                unset($data['findings'][$key]['evidence']);

                continue;
            }

            $value = $finding['value'] ?? null;

            if (is_string($value) && preg_match($unsafe, $value) && str_contains($value, '=')) {
                $data['findings'][$key]['value'] = '[redacted]';
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $findings = [];

        foreach ((array) ($data['findings'] ?? []) as $key => $finding) {
            $findings[$key] = Finding::fromArray((array) $finding);
        }

        return new self($findings, $data['generated_at'] ?? null, (int) ($data['schema_version'] ?? self::VERSION));
    }

    /** Findings a human must confirm before the installer may act on them. */
    public function needsConfirmation(): array
    {
        return array_values(array_filter(
            array_keys($this->findings),
            static fn (string $key) => in_array($key, self::SECURITY_SENSITIVE, true),
        ));
    }

    /**
     * The keys the installer will never apply silently, whatever the confidence.
     *
     * Each one, wrong, is a security incident rather than a bug: the wrong
     * tenant source leaks data between customers; the wrong role mapping shows
     * the defect backlog to the people it is about.
     */
    public const SECURITY_SENSITIVE = [
        'tenant.strategy',
        'tenant.attribute',
        'auth.guard',
        'auth.middleware',
        'roles.admin',
        'roles.internal_user',
        'roles.customer',
        'roles.vendor',
        'permissions.provider',
        'attachments.disk',
    ];
}
