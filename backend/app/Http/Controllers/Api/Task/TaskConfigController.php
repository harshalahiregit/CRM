<?php

namespace App\Http\Controllers\Api\Task;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Task\TaskConfigService;
use Illuminate\Http\Request;

/**
 * Task notification switches. Readable by any internal user (so the UI can show
 * what's on), writable only by an admin — the same barrier the rest of the
 * module uses for workspace-wide settings.
 */
class TaskConfigController extends Controller
{
    use ApiResponse;

    private const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor'];

    public function __construct(private TaskConfigService $config)
    {
    }

    public function index(Request $request)
    {
        abort_if(in_array($request->user()?->role, self::EXTERNAL_ROLES, true), 403, 'You do not have access to Tasks.');

        return $this->success($this->config->all($request->user()->tenant_id), 'Task settings retrieved');
    }

    public function update(Request $request)
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Only an admin can change task notification settings.');

        $data = $request->validate(['settings' => 'required|array']);

        return $this->success(
            $this->config->save($request->user()->tenant_id, $data['settings']),
            'Task settings saved'
        );
    }
}
