<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Services\Accounts\AccountsSetupService;
use Illuminate\Http\Request;

class SetupController extends Controller
{
    public function __construct(private AccountsSetupService $setup)
    {
    }

    /** Whether the tenant's books are initialised (drives the first-run screen). */
    public function status(Request $request)
    {
        return response()->json($this->setup->status($request->user()->tenant_id));
    }

    /** Seed the standard chart of accounts, voucher types, FY and starter ledger. */
    public function store(Request $request)
    {
        return response()->json($this->setup->setup($request->user()->tenant_id));
    }
}
