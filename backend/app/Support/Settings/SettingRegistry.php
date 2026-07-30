<?php

namespace App\Support\Settings;

/**
 * The single source of truth for every scalar settings group/key: its cast,
 * default, validation rules, whether it's encrypted at rest, and whether it's
 * eager-loaded. The API validates against this, SettingsService casts/persists
 * against it, and the frontend can render generically from it — nothing about the
 * settings schema is hardcoded in React.
 *
 * Add new groups (localization, finance, upload, security, notifications) here as
 * later increments land; the store + service + generic endpoints need no changes.
 */
final class SettingRegistry
{
    /**
     * group => [ key => ['cast'=>, 'default'=>, 'rules'=>[], 'encrypted'=>bool, 'autoload'=>bool] ]
     */
    public static function definition(): array
    {
        return [
            'general' => [
                'company_name'  => ['cast' => 'string', 'default' => null,      'rules' => ['nullable', 'string', 'max:191']],
                'app_name'      => ['cast' => 'string', 'default' => null,      'rules' => ['nullable', 'string', 'max:100']],
                'support_email' => ['cast' => 'string', 'default' => null,      'rules' => ['nullable', 'email', 'max:191']],
                'support_phone' => ['cast' => 'string', 'default' => null,      'rules' => ['nullable', 'string', 'max:40']],
                'website'       => ['cast' => 'string', 'default' => null,      'rules' => ['nullable', 'string', 'max:191']],
                'address'       => ['cast' => 'string', 'default' => null,      'rules' => ['nullable', 'string', 'max:500']],
            ],
            'branding' => [
                'logo_url'      => ['cast' => 'string', 'default' => null,      'rules' => ['nullable', 'string', 'max:500']],
                'logo_dark_url' => ['cast' => 'string', 'default' => null,      'rules' => ['nullable', 'string', 'max:500']],
                'favicon_url'   => ['cast' => 'string', 'default' => null,      'rules' => ['nullable', 'string', 'max:500']],
                'primary_color' => ['cast' => 'string', 'default' => '#7C3AED', 'rules' => ['nullable', 'string', 'max:20']],
            ],
        ];
    }

    public static function groups(): array
    {
        return array_keys(self::definition());
    }

    public static function groupExists(string $group): bool
    {
        return array_key_exists($group, self::definition());
    }

    public static function has(string $group, string $key): bool
    {
        return isset(self::definition()[$group][$key]);
    }

    public static function meta(string $group, string $key): ?array
    {
        return self::definition()[$group][$key] ?? null;
    }

    public static function isEncrypted(string $group, string $key): bool
    {
        return (bool) (self::meta($group, $key)['encrypted'] ?? false);
    }

    /** key => default, for one group. */
    public static function defaults(string $group): array
    {
        return array_map(fn ($m) => $m['default'] ?? null, self::definition()[$group] ?? []);
    }

    /** Laravel validation rules keyed "group.key" for the given groups. */
    public static function rules(array $groups): array
    {
        $rules = [];
        foreach ($groups as $group) {
            foreach (self::definition()[$group] ?? [] as $key => $meta) {
                $rules["{$group}.{$key}"] = $meta['rules'] ?? ['nullable'];
            }
        }

        return $rules;
    }

    /** Cast a stored/raw value to its declared PHP type. */
    public static function cast(string $group, string $key, $value)
    {
        if ($value === null) {
            return null;
        }
        return match (self::meta($group, $key)['cast'] ?? 'string') {
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int', 'integer'  => (int) $value,
            'float'           => (float) $value,
            'array', 'json'   => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            default           => (string) $value,
        };
    }

    /** Serialize a value for storage in the text column. */
    public static function forStore(string $group, string $key, $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return match (self::meta($group, $key)['cast'] ?? 'string') {
            'bool', 'boolean' => $value ? '1' : '0',
            'array', 'json'   => json_encode($value),
            default           => (string) $value,
        };
    }
}
