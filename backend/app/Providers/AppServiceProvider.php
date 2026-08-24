<?php

namespace App\Providers;

use App\Contracts\AI\AIProviderInterface;
use App\Contracts\Hr\AttendanceProvider;
use App\Contracts\ProjectDirectoryContract;
use App\Services\AI\AIProviderFactory;
use App\Services\Customer\Contracts\TicketIntakeContract;
use App\Services\Customer\CustomerDirectoryService;
use App\Services\Customer\TicketIntakeUnavailable;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Helpdesk\SlaService;
use App\Services\Hr\Attendance\PlaceholderAttendanceProvider;
use App\Services\Integration\ProjectDirectoryService;
use App\Services\Numbering\DatabaseDocumentNumberService;
use App\Services\Numbering\DocumentNumberServiceInterface;
use App\Support\Email\MergeFields\MergeFieldRegistry;
use App\Support\Numbering\Placeholders\PlaceholderRegistry;
use App\Support\Numbering\Reset\ResetStrategyRegistry;
use App\Support\Shared\MeetingTypeCatalog;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Helpdesk (and other modules) resolve customer data through this
        // contract. Now backed by the real Customer module; MockCustomerService
        // is retained only as a reference/testing fallback.
        $this->app->bind(CustomerServiceContract::class, CustomerDirectoryService::class);
        // Reverse direction: Customer / Sales / Accounts resolve projects through
        // this contract so they never query the Projects module's tables. Backs
        // the "billable → project" picker on expenses and the project link on
        // cheques and vendor bills.
        $this->app->bind(ProjectDirectoryContract::class, ProjectDirectoryService::class);
        // Customer -> Helpdesk, the reverse of CustomerServiceContract above: the
        // client portal raises tickets through this rather than inserting rows,
        // so numbering, SLA, routing and the acknowledgement email all stay
        // inside HelpdeskService::createTicket().
        //
        // bindIf, not bind: Helpdesk owns the real implementation and registers
        // it here too. bindIf means whichever order the two lines end up in,
        // Helpdesk's wins — this only applies when nothing else has bound it.
        // Until then the portal hides the form (see ClientPortalController::me).
        $this->app->bindIf(TicketIntakeContract::class, TicketIntakeUnavailable::class);
        // SlaService caches each tenant's SLA targets + paused/closed status sets
        // for the life of the request. That cache is what stops compute() from
        // querying once per ticket — a 31-ticket list costs 2 config queries
        // instead of 62 — so the service must be resolved ONCE per request, not
        // rebuilt at each injection point.
        //
        // `scoped` rather than `singleton`: identical under PHP-FPM, but a
        // singleton under Octane would survive the request and keep serving stale
        // targets after an admin edits them. `scoped` is dropped at the request
        // boundary, which is exactly the lifetime the cache should have.
        $this->app->scoped(SlaService::class);

        // Meeting-type catalogue — merges config/meetings.php with the tenant's
        // meeting_types rows. `scoped` for the same reason: it memoises the merge
        // per request (the KickoffMeeting label accessor reads it on every row) and
        // is dropped at the request boundary so an admin edit is seen next request.
        $this->app->scoped(MeetingTypeCatalog::class);

        // Vendor-neutral AI provider — resolved from config('ai.provider').
        $this->app->bind(
            AIProviderInterface::class,
            fn () => AIProviderFactory::make()
        );

        // Document Numbering Engine — the single source of truth for every
        // document number. Modules depend on the interface only, so the storage
        // strategy can change without touching a single caller.
        $this->app->bind(
            DocumentNumberServiceInterface::class,
            DatabaseDocumentNumberService::class
        );

        // Extension registries are SINGLETONS on purpose: each one supports runtime
        // register() so a module can add a placeholder / reset rule / merge field
        // from its own service provider. With a fresh instance per resolution those
        // registrations would land on a throwaway object and silently do nothing.
        // They hold only tenant-agnostic resolvers, so sharing them is safe.
        $this->app->singleton(PlaceholderRegistry::class);
        $this->app->singleton(ResetStrategyRegistry::class);
        $this->app->singleton(MergeFieldRegistry::class);

        // Payroll attendance boundary — placeholder until SangoeTrack integration.
        // Swap this binding for a SangoeTrackAttendanceProvider to go live; payroll
        // logic depends only on the AttendanceProvider interface.
        $this->app->bind(
            AttendanceProvider::class,
            PlaceholderAttendanceProvider::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
