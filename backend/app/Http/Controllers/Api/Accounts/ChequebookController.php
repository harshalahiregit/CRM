<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreChequebookRequest;
use App\Models\Accounts\Chequebook;
use App\Services\Accounts\ChequebookService;
use Illuminate\Http\Request;

class ChequebookController extends Controller
{
    public function __construct(private ChequebookService $chequebooks)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->chequebooks->list($request->user()->tenant_id, $request->only(['bank_account_id', 'status']))
        );
    }

    public function summary(Request $request)
    {
        return response()->json($this->chequebooks->summary($request->user()->tenant_id));
    }

    public function store(StoreChequebookRequest $request)
    {
        $book = $this->chequebooks->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($book, 201);
    }

    public function update(Request $request, Chequebook $chequebook)
    {
        $data = $request->validate([
            'name'   => 'sometimes|string|max:120',
            'prefix' => 'nullable|string|max:20',
            'notes'  => 'nullable|string|max:1000',
        ]);
        return response()->json(
            $this->chequebooks->update($chequebook, $data, $request->user()->tenant_id, $request->user()->id)
        );
    }

    public function close(Request $request, Chequebook $chequebook)
    {
        return response()->json(
            $this->chequebooks->close($chequebook, $request->user()->tenant_id, $request->user()->id)
        );
    }

    public function destroy(Request $request, Chequebook $chequebook)
    {
        $this->chequebooks->delete($chequebook, $request->user()->tenant_id, $request->user()->id);
        return response()->json(['message' => 'Chequebook deleted']);
    }
}
