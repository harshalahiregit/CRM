<?php

namespace Sire\Discovery\Detectors;

use Illuminate\Support\Facades\Schema;
use Sire\Discovery\Finding;

/**
 * SIRE — the six optional subsystems: notifications, attachments, audit, notes,
 * knowledge base, application version.
 *
 * ABSENCE IS A RESULT, NOT A FAILURE.
 *
 * SIRE runs with none of these. It owns its own settings, notes, audit trail and
 * issue numbering, stores attachments on a Laravel disk, logs notifications
 * rather than sending them, and treats a missing knowledge base as "no related
 * articles". So every finding here is a suggestion for something the host could
 * connect, never a prerequisite it must.
 *
 * That is why nothing in this detector reaches MEDIUM without direct evidence: a
 * table called `notes` is a strong hint and a weak fact, and proposing an
 * integration that turns out to be someone's unrelated model wastes a
 * developer's afternoon. Better to say "found a candidate, confirm it".
 */
class IntegrationDetector implements Detector
{
    private const TABLE_HINTS = [
        'audit'       => ['audit_logs', 'audits', 'activity_log', 'activity_logs', 'auditing'],
        'notes'       => ['notes', 'comments', 'remarks'],
        'attachments' => ['attachments', 'media', 'files', 'documents', 'uploads'],
        'notifications' => ['notifications'],
        'settings'    => ['settings', 'tenant_settings', 'configurations', 'options'],
        'knowledge'   => ['kb_articles', 'knowledge_base_articles', 'knowledgebase_articles', 'articles', 'help_articles'],
        'versions'    => ['releases', 'versions', 'deployments', 'app_versions'],
    ];

    private const PACKAGE_HINTS = [
        'audit'       => ['owen-it/laravel-auditing', 'spatie/laravel-activitylog'],
        'attachments' => ['spatie/laravel-medialibrary'],
        'settings'    => ['spatie/laravel-settings', 'anlutro/l4-settings'],
        'notifications' => ['laravel/nova', 'bensampo/laravel-enum'],
    ];

    public function __construct(private readonly ComposerReader $composer)
    {
    }

    public function name(): string
    {
        return 'integrations';
    }

    public function detect(): array
    {
        $out = [];

        foreach (self::TABLE_HINTS as $area => $tables) {
            $found = [];

            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    $found[] = $table;
                }
            }

            $package = $this->composer->firstOf(self::PACKAGE_HINTS[$area] ?? []);

            if ($package !== null) {
                $out["integrations.{$area}"] = Finding::found(
                    ['package' => $package, 'tables' => $found],
                    Finding::MEDIUM,
                    ["composer.json requires {$package}"],
                );

                continue;
            }

            $out["integrations.{$area}"] = $found === []
                ? Finding::absent(["no table matching ".implode('/', $tables)])
                : Finding::found(
                    ['tables' => $found],
                    // A matching table name is a candidate, not a conclusion.
                    Finding::LOW,
                    ['found table(s): '.implode(', ', $found)],
                    ['SIRE works without this — connect it only if it is what you think it is'],
                );
        }

        // Laravel's own notification table is a special case worth naming: if it
        // exists, the host almost certainly uses database notifications, which is
        // the easiest possible notification provider to write.
        $out['integrations.notifications.database'] = Schema::hasTable('notifications')
            ? Finding::found(true, Finding::HIGH, ['the standard Laravel notifications table exists'])
            : Finding::absent(['no notifications table']);

        // Version, from the places a Laravel app usually keeps it.
        $version = config('app.version') ?? env('APP_VERSION');

        $out['version.source'] = $version
            ? Finding::found(['config' => 'app.version', 'value' => (string) $version], Finding::HIGH, ["config('app.version')"])
            : Finding::absent(["config('app.version') and APP_VERSION are both unset"]);

        return $out;
    }
}
