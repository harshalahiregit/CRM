<?php

namespace Sire;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Sire\Contracts as Contract;
use Sire\Contracts\Extension\TimelineContributor;
use Sire\AI\AiTimelineContributor;
use Sire\Services\SireTimelineService;

/**
 * SIRE — the whole installation, in one provider.
 *
 * Composer-installed hosts get this automatically through package discovery.
 * Everyone else adds one line:
 *
 *     // bootstrap/providers.php  (Laravel 11/12)
 *     Sire\SireServiceProvider::class,
 *
 *     // config/app.php 'providers'  (Laravel 10)
 *     Sire\SireServiceProvider::class,
 *
 * That single line brings config, routes, migrations, commands, the scheduler
 * entry and all thirteen SDK bindings.
 *
 * WHERE THE HOST GETS CONNECTED
 *
 * Every dependency SIRE has on its host arrives through one of thirteen SDK
 * interfaces, and every one is bound below from config('sire.providers'). To
 * connect a host subsystem, point that config key at your own class. Nothing
 * else in SIRE changes — no service, no controller, no model.
 *
 * SIRE ships a working implementation of all thirteen, so it runs before any of
 * them are wired. That is the difference between a module you can evaluate and
 * one you have to finish first.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 * No AI provider is registered. AI is off by default and its providers resolve
 * through SireAiRegistry's hardcoded allowlist — never by instantiating a class
 * name read from a tenant setting.
 *
 * No host service provider is modified, no host route file is edited, no host
 * migration is touched. SIRE adds; it does not rewrite.
 */
class SireServiceProvider extends ServiceProvider
{
    /** SDK interface => config('sire.providers') key. */
    private const PROVIDERS = [
        Contract\SireTenantProvider::class        => 'tenant',
        Contract\SireUserProvider::class          => 'user',
        Contract\SireAuthorizationProvider::class => 'authorization',
        Contract\SireNotificationProvider::class  => 'notification',
        Contract\SireAttachmentProvider::class    => 'attachment',
        Contract\SireAuditProvider::class         => 'audit',
        Contract\SireNotesProvider::class         => 'notes',
        Contract\SireNumberingProvider::class     => 'numbering',
        Contract\SireSettingsProvider::class      => 'settings',
        Contract\SireSlaProvider::class           => 'sla',
        Contract\SireKnowledgeProvider::class     => 'knowledge',
        Contract\SireVersionProvider::class       => 'version',
        Contract\SireContextProvider::class       => 'context',
        Contract\SireCustomerProvider::class      => 'customer',
    ];

    private const COMMANDS = [
        Console\Commands\SireDiscover::class,
        Console\Commands\SireCompatibility::class,
        Console\Commands\SireInstall::class,
        Console\Commands\SireSeedDefaults::class,
        Console\Commands\SireDoctor::class,
        Console\Commands\SireArchitecture::class,
        Console\Commands\SireHostProfile::class,
        Console\Commands\SireUninstall::class,
        Console\Commands\RunSireSchedule::class,
        Console\Commands\IndexSireIssues::class,
        Console\Commands\ExportSireWorkflow::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sire.php', 'sire');

        foreach (self::PROVIDERS as $interface => $key) {
            $this->app->singleton($interface, function ($app) use ($interface, $key) {
                $implementation = config("sire.providers.{$key}");

                if (! is_string($implementation) || ! class_exists($implementation)) {
                    throw new RuntimeException(
                        "SIRE: config('sire.providers.{$key}') does not name a loadable class. "
                        ."Expected an implementation of {$interface}. Run: php artisan sire:doctor"
                    );
                }

                $instance = $app->make($implementation);

                // Checked at binding, not at first use. A mistyped provider should
                // fail on boot with the interface name in the message, not three
                // screens into a QA session with a type error.
                if (! $instance instanceof $interface) {
                    throw new RuntimeException("SIRE: {$implementation} must implement {$interface}.");
                }

                return $instance;
            });
        }

        // The timeline accepts optional contributors so nothing outside the core
        // has to be referenced BY the core. Unbind this tag and the timeline is
        // exactly what it was before AI existed.
        $this->app->tag([AiTimelineContributor::class], 'sire.timeline.contributors');

        $this->app->when(SireTimelineService::class)
            ->needs(TimelineContributor::class.'[]')
            ->give(fn ($app) => iterator_to_array($app->tagged('sire.timeline.contributors')));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/sire.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/sire.php' => $this->app->configPath('sire.php'),
        ], 'sire-config');

        // The SPA sources. Published rather than compiled: SIRE cannot know a
        // host's bundler, alias map or design system, and shipping a built
        // bundle would freeze all three.
        $this->publishes([
            __DIR__.'/../resources/js' => $this->app->resourcePath('js/sire'),
        ], 'sire-frontend');

        // Migrations too, for hosts that would rather own the files than load
        // them from the package — a real preference when a DBA reviews every
        // migration before it runs.
        $this->publishes([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'sire-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands(self::COMMANDS);
        }

        $this->registerSchedule();
    }

    /**
     * The only scheduled work SIRE needs: one command, every fifteen minutes,
     * recomputing SLA state and sending warning/breach notices — each firing
     * once per clock.
     *
     * No queue worker, no Horizon, no Redis. With no scheduler at all, SLA state
     * is still computed on read and only the proactive notices are lost, which
     * is why this is a config flag rather than a requirement.
     */
    private function registerSchedule(): void
    {
        if (! config('sire.schedule.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $interval = (string) config('sire.schedule.interval', 'everyFifteenMinutes');

            $event = $schedule->command('sire:run-schedule');

            // Guarded: an interval typo should not fatal the scheduler for the
            // whole application. Fall back to the documented default.
            if (method_exists($event, $interval)) {
                $event->{$interval}();
            } else {
                $event->everyFifteenMinutes();
            }

            $event->withoutOverlapping()->runInBackground();

            // The duplicate index. Without it, duplicate detection and
            // classification have no neighbours to reason about and abstain on
            // every issue -- which looks exactly like "nothing similar exists"
            // and is the difference between a backlog of forty and a backlog of
            // forty that is really twelve.
            //
            // Hourly, not every fifteen minutes: it is a rebuild of derived
            // rows, nobody is waiting on it, and it reads every open issue.
            // Skipped entirely when AI is off for every tenant, so a workspace
            // that never enables it pays nothing.
            if (config('sire.schedule.index_issues', true)) {
                $schedule->command('sire:index-issues')
                    ->hourly()
                    ->withoutOverlapping()
                    ->runInBackground();
            }
        });
    }
}
