<?php

namespace Sire\Installation;

/**
 * SIRE — writes the resolved settings to a config file the host owns.
 *
 * WHY A SEPARATE FILE RATHER THAN REWRITING config/sire.php
 *
 * config/sire.php is SIRE's, published from the package, and full of the
 * comments that explain what every setting means. Rewriting it programmatically
 * would destroy those comments the first time anyone ran the installer, and
 * `sire:install` run twice would leave a file nobody could read.
 *
 * So installation writes config/sire-host.php — a small, generated, mechanical
 * file containing ONLY what discovery and the administrator decided — and
 * config/sire.php merges it. Anyone can read the generated file at a glance,
 * diff it, commit it, or delete it to start again.
 *
 * IDEMPOTENT BY CONSTRUCTION
 *
 * The file is regenerated from the full resolved set every time. Running the
 * installer twice produces byte-identical output; there is no append path and
 * therefore no way to accumulate duplicates.
 */
final class ConfigWriter
{
    public function __construct(private readonly string $path)
    {
    }

    public static function defaultPath(): string
    {
        return config_path('sire-host.php');
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @return array<string, mixed> the settings already written, flattened */
    public function current(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $data = @include $this->path;

        return is_array($data) ? $this->flatten($data, 'sire') : [];
    }

    /**
     * @param  array<string, mixed> $settings dotted keys, e.g. 'sire.tenant.strategy'
     * @return string the path written
     */
    public function write(array $settings): string
    {
        $tree = [];

        foreach ($settings as $key => $value) {
            // 'sire.tenant.strategy' -> ['tenant']['strategy']; the leading
            // 'sire.' is the file's identity, not part of its shape.
            $path = explode('.', preg_replace('/^sire\./', '', (string) $key));
            $cursor = &$tree;

            foreach ($path as $segment) {
                $cursor[$segment] ??= [];
                $cursor = &$cursor[$segment];
            }

            $cursor = $value;
            unset($cursor);
        }

        $body = $this->export($tree, 1);

        $php = <<<PHP
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
         * Generated: {$this->timestamp()}
         */
        return [
        {$body}
        ];

        PHP;

        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }

        file_put_contents($this->path, $php);

        return $this->path;
    }

    /** @param array<string, mixed> $value */
    private function export(array $value, int $depth): string
    {
        $pad = str_repeat('    ', $depth);
        $lines = [];

        foreach ($value as $key => $item) {
            $exported = is_array($item) && $item !== [] && ! array_is_list($item)
                ? "[\n".$this->export($item, $depth + 1)."\n{$pad}]"
                : $this->scalar($item);

            $lines[] = "{$pad}".var_export((string) $key, true)." => {$exported},";
        }

        return implode("\n", $lines);
    }

    private function scalar(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            return '['.implode(', ', array_map(fn ($v) => $this->scalar($v), $value)).']';
        }

        // var_export renders null, bool, int and string correctly and safely —
        // no interpolation, so nothing a role name contains can escape.
        return var_export($value, true);
    }

    /** @return array<string, mixed> */
    private function flatten(array $data, string $prefix): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $full = "{$prefix}.{$key}";

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $out += $this->flatten($value, $full);

                continue;
            }

            $out[$full] = $value;
        }

        return $out;
    }

    private function timestamp(): string
    {
        return function_exists('now') ? now()->toDateTimeString() : date('Y-m-d H:i:s');
    }
}
