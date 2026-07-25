<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\HelpdeskService;
use Illuminate\Http\Request;

/**
 * Capabilities for the current user, so the frontend nav can decide what to show
 * without duplicating the helpdesk's role rules. `is_manager` is true for an
 * admin, a tenant-wide ticket manager, or anyone who manages at least one
 * department — the audience for Settings / Widget / KB-Admin.
 */
class HelpdeskMeController extends Controller
{
    use ApiResponse;

    public function __construct(private HelpdeskService $helpdesk)
    {
    }

    public function show(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'role'       => $user->role,
            'is_admin'   => $user->role === 'admin',
            'is_manager' => $this->helpdesk->isManagerAnywhere($user->tenant_id, $user->id, $user->role),
        ], 'Capabilities retrieved');
    }
}
