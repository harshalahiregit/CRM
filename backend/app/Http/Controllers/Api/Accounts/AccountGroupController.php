<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreAccountGroupRequest;
use App\Http\Requests\Accounts\UpdateAccountGroupRequest;
use App\Models\Accounts\AccountGroup;
use App\Services\Accounts\ChartOfAccountsService;
use Illuminate\Http\Request;

class AccountGroupController extends Controller
{
    public function __construct(private ChartOfAccountsService $coa)
    {
    }

    /** Nested tree (default) or flat list (?flat=1 for dropdowns). */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json($request->boolean('flat')
            ? $this->coa->groups($tenantId)
            : $this->coa->groupTree($tenantId));
    }

    public function store(StoreAccountGroupRequest $request)
    {
        $group = $this->coa->createGroup($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($group, 201);
    }

    public function update(UpdateAccountGroupRequest $request, AccountGroup $group)
    {
        $updated = $this->coa->updateGroup($group, $request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($updated);
    }

    public function destroy(AccountGroup $group, Request $request)
    {
        $this->coa->deleteGroup($group, $request->user()->tenant_id, $request->user()->id);
        return response()->json(['message' => 'Group deleted']);
    }
}
