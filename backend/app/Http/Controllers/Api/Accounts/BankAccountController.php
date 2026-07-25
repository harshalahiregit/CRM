<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreBankAccountRequest;
use App\Models\Accounts\BankAccount;
use App\Services\Accounts\BankAccountService;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function __construct(private BankAccountService $banks)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->banks->list($request->user()->tenant_id));
    }

    public function store(StoreBankAccountRequest $request)
    {
        $bank = $this->banks->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($bank, 201);
    }

    public function update(StoreBankAccountRequest $request, BankAccount $bankAccount)
    {
        $updated = $this->banks->update($bankAccount, $request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($updated);
    }

    public function destroy(BankAccount $bankAccount, Request $request)
    {
        $outcome = $this->banks->delete($bankAccount, $request->user()->tenant_id, $request->user()->id);
        return response()->json(['message' => "Bank account {$outcome}", 'outcome' => $outcome]);
    }
}
