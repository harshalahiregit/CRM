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

            // ── Increment C: Localization ────────────────────────────────
            'localization' => [
                'language'       => ['cast' => 'string', 'default' => 'en',           'rules' => ['nullable', 'string', 'max:10']],
                'locale'         => ['cast' => 'string', 'default' => 'en_IN',        'rules' => ['nullable', 'string', 'max:20']],
                'timezone'       => ['cast' => 'string', 'default' => 'Asia/Kolkata', 'rules' => ['nullable', 'string', 'max:64', 'timezone']],
                'date_format'    => ['cast' => 'string', 'default' => 'd/m/Y',        'rules' => ['nullable', 'string', 'in:d/m/Y,m/d/Y,Y-m-d,d-m-Y,d M Y,M d Y']],
                'time_format'    => ['cast' => 'string', 'default' => '12',           'rules' => ['nullable', 'string', 'in:12,24']],
                'week_start_day' => ['cast' => 'int',    'default' => 1,              'rules' => ['nullable', 'integer', 'min:0', 'max:6']],
            ],

            // ── Increment C: Currency & number format ────────────────────
            'currency' => [
                'code'                => ['cast' => 'string', 'default' => 'INR', 'rules' => ['nullable', 'string', 'size:3']],
                'symbol'              => ['cast' => 'string', 'default' => '₹',   'rules' => ['nullable', 'string', 'max:8']],
                'symbol_position'     => ['cast' => 'string', 'default' => 'before', 'rules' => ['nullable', 'string', 'in:before,after,before_space,after_space']],
                'decimal_places'      => ['cast' => 'int',    'default' => 2,     'rules' => ['nullable', 'integer', 'min:0', 'max:6']],
                'decimal_separator'   => ['cast' => 'string', 'default' => '.',   'rules' => ['nullable', 'string', 'max:1']],
                'thousands_separator' => ['cast' => 'string', 'default' => ',',   'rules' => ['nullable', 'string', 'max:1']],
                'negative_format'     => ['cast' => 'string', 'default' => 'minus', 'rules' => ['nullable', 'string', 'in:minus,parentheses']],
                'grouping'            => ['cast' => 'string', 'default' => 'indian', 'rules' => ['nullable', 'string', 'in:indian,western']],
            ],

            // ── Increment D: Upload ──────────────────────────────────────
            'upload' => [
                'max_upload_mb'        => ['cast' => 'int',    'default' => 10, 'rules' => ['nullable', 'integer', 'min:1', 'max:1024']],
                'avatar_max_mb'        => ['cast' => 'int',    'default' => 2,  'rules' => ['nullable', 'integer', 'min:1', 'max:64']],
                'logo_max_mb'          => ['cast' => 'int',    'default' => 2,  'rules' => ['nullable', 'integer', 'min:1', 'max:64']],
                'attachment_max_mb'    => ['cast' => 'int',    'default' => 20, 'rules' => ['nullable', 'integer', 'min:1', 'max:1024']],
                'allowed_image_ext'    => ['cast' => 'string', 'default' => 'jpg,jpeg,png,gif,webp,svg',                'rules' => ['nullable', 'string', 'max:500']],
                'allowed_document_ext' => ['cast' => 'string', 'default' => 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',   'rules' => ['nullable', 'string', 'max:500']],
                'allowed_video_ext'    => ['cast' => 'string', 'default' => 'mp4,mov,avi,mkv,webm',                     'rules' => ['nullable', 'string', 'max:500']],
                'allowed_archive_ext'  => ['cast' => 'string', 'default' => 'zip,rar,7z,tar,gz',                        'rules' => ['nullable', 'string', 'max:500']],
                'storage_disk'         => ['cast' => 'string', 'default' => 'local', 'rules' => ['nullable', 'string', 'in:local,public,s3']],
                'public_visibility'    => ['cast' => 'bool',   'default' => false, 'rules' => ['nullable', 'boolean']],
            ],

            // ── Increment D: Security (settings only — no auth changes) ───
            'security' => [
                'password_min_length'                => ['cast' => 'int',    'default' => 8,     'rules' => ['nullable', 'integer', 'min:6', 'max:128']],
                'require_uppercase'                  => ['cast' => 'bool',   'default' => true,  'rules' => ['nullable', 'boolean']],
                'require_lowercase'                  => ['cast' => 'bool',   'default' => true,  'rules' => ['nullable', 'boolean']],
                'require_numbers'                    => ['cast' => 'bool',   'default' => true,  'rules' => ['nullable', 'boolean']],
                'require_special'                    => ['cast' => 'bool',   'default' => false, 'rules' => ['nullable', 'boolean']],
                'password_expiry_days'               => ['cast' => 'int',    'default' => 0,     'rules' => ['nullable', 'integer', 'min:0', 'max:3650']],
                'failed_login_lockout'               => ['cast' => 'int',    'default' => 5,     'rules' => ['nullable', 'integer', 'min:0', 'max:100']],
                'lockout_duration_minutes'           => ['cast' => 'int',    'default' => 15,    'rules' => ['nullable', 'integer', 'min:0', 'max:1440']],
                'session_timeout_minutes'            => ['cast' => 'int',    'default' => 120,   'rules' => ['nullable', 'integer', 'min:0', 'max:43200']],
                'remember_me_days'                   => ['cast' => 'int',    'default' => 30,    'rules' => ['nullable', 'integer', 'min:0', 'max:365']],
                'force_logout_after_password_change' => ['cast' => 'bool',   'default' => true,  'rules' => ['nullable', 'boolean']],
                'single_session_only'                => ['cast' => 'bool',   'default' => false, 'rules' => ['nullable', 'boolean']],
                'two_factor_enabled'                 => ['cast' => 'bool',   'default' => false, 'rules' => ['nullable', 'boolean']],
                'api_token_expiry_days'              => ['cast' => 'int',    'default' => 0,     'rules' => ['nullable', 'integer', 'min:0', 'max:3650']],
                'ip_whitelist'                       => ['cast' => 'string', 'default' => '',    'rules' => ['nullable', 'string', 'max:2000']],
                'trusted_domains'                    => ['cast' => 'string', 'default' => '',    'rules' => ['nullable', 'string', 'max:2000']],
            ],

            // ── Increment D: Notification preferences (tenant defaults) ──
            'notifications' => [
                'email'      => ['cast' => 'bool',  'default' => true,  'rules' => ['nullable', 'boolean']],
                'browser'    => ['cast' => 'bool',  'default' => true,  'rules' => ['nullable', 'boolean']],
                'whatsapp'   => ['cast' => 'bool',  'default' => false, 'rules' => ['nullable', 'boolean']],
                'sms'        => ['cast' => 'bool',  'default' => false, 'rules' => ['nullable', 'boolean']],
                'push'       => ['cast' => 'bool',  'default' => false, 'rules' => ['nullable', 'boolean']],
                'categories' => ['cast' => 'array', 'default' => self::notificationMatrix(), 'rules' => ['nullable', 'array']],
            ],
        ];
    }

    /** Categories the notification matrix covers. */
    public const NOTIFICATION_CATEGORIES = [
        'HR', 'Recruitment', 'Payroll', 'Purchase', 'Inventory',
        'Sales', 'Projects', 'Helpdesk', 'Approvals', 'Security', 'System',
    ];

    /** Delivery channels for the notification matrix. */
    public const NOTIFICATION_CHANNELS = ['email', 'browser', 'whatsapp', 'sms', 'push'];

    /** Default per-category × per-channel matrix (tenant defaults; email+browser on). */
    public static function notificationMatrix(): array
    {
        $row = ['email' => true, 'browser' => true, 'whatsapp' => false, 'sms' => false, 'push' => false];
        $matrix = [];
        foreach (self::NOTIFICATION_CATEGORIES as $category) {
            $matrix[$category] = $row;
        }

        return $matrix;
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
