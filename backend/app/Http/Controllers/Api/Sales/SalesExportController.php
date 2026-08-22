<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesExportService;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One export endpoint for every Sales list: /sales/export/{type}?format=csv
 *
 * Columns live in SalesExportService so a list declares what it exports in one
 * place instead of each page growing its own endpoint.
 */
class SalesExportController extends Controller
{
    public function __construct(private SalesExportService $exports)
    {
    }

    public function __invoke(Request $request, string $type): BinaryFileResponse
    {
        $format = strtolower($request->query('format', 'csv')) === 'xlsx' ? 'xlsx' : 'csv';

        $rows = $this->exports->rows(
            $type,
            $request->user()->tenant_id,
            $request->only('status', 'type', 'search', 'status_id', 'mode'),
        );

        return Spreadsheet::download($rows, $this->exports->filename($type, $format), $format);
    }
}
