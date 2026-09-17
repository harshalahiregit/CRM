<?php

namespace Sire\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Models\Report;
use Sire\Services\SireWatcherService;

/**
 * SIRE — subscribing to an issue you are not assigned to.
 *
 * Watching decides who is TOLD, never who may see. Every one of these routes
 * still goes through assertTenantOwnership first, so adding somebody as a
 * watcher cannot widen what they can read -- if they could not open the issue
 * before, they still cannot, they will simply get a notification about one they
 * cannot open. That is the right failure: the alternative is a subscription
 * endpoint that quietly grants access.
 */
class SireWatcherController
{
    use AssertsSireTenantOwnership;
    use ResolvesSireUser;
    use SireApiResponse;

    public function __construct(private readonly SireWatcherService $watchers)
    {
    }

    /** GET /sire/reports/{report}/watchers */
    public function index(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success($this->watchers->listFor($report));
    }

    /**
     * POST /sire/reports/{report}/watchers
     *
     * No body watches yourself; `user_id` adds somebody else, which needs the
     * triage capability because it puts mail in another person's inbox.
     */
    public function store(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        $actor = $this->sireUser();
        $userId = (int) ($request->validate([
            'user_id' => ['nullable', 'integer'],
        ])['user_id'] ?? $actor->id);

        $this->watchers->watch($report, $userId, $actor);

        return $this->success($this->watchers->listFor($report));
    }

    /**
     * DELETE /sire/reports/{report}/watchers/{user}
     *
     * Anybody may remove themselves. Being unable to stop a notification you did
     * not ask for is how people build an inbox rule and stop reading any of it.
     */
    public function destroy(Request $request, Report $report, int $user): JsonResponse
    {
        $this->assertTenantOwnership($report);

        $this->watchers->unwatch($report, $user, $this->sireUser());

        return $this->success($this->watchers->listFor($report));
    }
}
