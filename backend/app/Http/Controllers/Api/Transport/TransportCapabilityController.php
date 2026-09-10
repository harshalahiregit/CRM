<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Transport\TransportPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What may THIS user do in Transport.
 *
 * Added while fixing the "admin cannot delete a vehicle" report. The delete
 * endpoint existed and worked; the UI simply never offered the button, because
 * it had no way to know whether the API would accept it — and guessing would
 * have produced the fake affordance the team conventions forbid.
 *
 * TransportPermissionService::grantsFor already had the answer and its own
 * docblock already said why it matters: "A UI that hides what the backend would
 * refuse is honest; a UI that shows a button the API rejects is the 'fake
 * success' pattern the team conventions forbid." This publishes it.
 *
 * Deliberately NOT behind a transport.permission gate: asking what you may do
 * requires no permission, and gating it would mean a user with no grants got a
 * 403 instead of an honest empty list.
 */
class TransportCapabilityController extends Controller
{
    use ApiResponse;

    public function __construct(private TransportPermissionService $permissions)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            // key => scope (all | own | assigned), only the ones actually held.
            'grants' => $this->permissions->grantsFor($user),
            'role'   => $this->permissions->stosRole($user),
        ], 'Transport capabilities retrieved');
    }
}
