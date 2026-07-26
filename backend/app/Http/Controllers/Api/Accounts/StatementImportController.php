<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\BankStatementImport;
use App\Services\Accounts\StatementImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;

class StatementImportController extends Controller
{
    public function __construct(private StatementImportService $imports)
    {
    }

    /** List import batches for a bank account. */
    public function index(Request $request, int $bankAccount)
    {
        return response()->json($this->imports->imports($request->user()->tenant_id, $bankAccount));
    }

    /** List imported statement lines for a bank account. */
    public function lines(Request $request, int $bankAccount)
    {
        return response()->json($this->imports->lines($request->user()->tenant_id, $bankAccount, $request->only([
            'match_status', 'per_page',
        ])));
    }

    /** Upload a CSV/XLSX bank statement. */
    public function store(Request $request, int $bankAccount)
    {
        $request->validate([
            'file'     => 'required|file|mimes:csv,txt,xlsx,xls|max:5120',
            'simulate' => 'nullable|boolean',
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'csv');
        $rows = Spreadsheet::readRows($file->getRealPath(), $ext);

        $result = $this->imports->import(
            $bankAccount,
            $rows,
            in_array($ext, ['xlsx', 'xls'], true) ? 'xlsx' : 'csv',
            $file->getClientOriginalName(),
            $request->user()->tenant_id,
            $request->user()->id,
            $request->boolean('simulate'),
        );

        return response()->json($result);
    }

    /** Revert (delete) an import batch and its lines. */
    public function destroy(BankStatementImport $import, Request $request)
    {
        $this->imports->revert($import, $request->user()->tenant_id);
        return response()->json(['message' => 'Import reverted']);
    }
}
