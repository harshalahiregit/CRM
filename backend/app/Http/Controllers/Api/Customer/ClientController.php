<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreClientRequest;
use App\Http\Requests\Customer\UpdateClientRequest;
use App\Models\Customer\Client;
use App\Services\Customer\ClientService;
use App\Services\Customer\CustomFieldService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    public function __construct(
        private ClientService $clients,
        private CustomFieldService $customFields,
    ) {
    }

    /* ── List (paginated table) ───────────────────────────────── */
    public function index(Request $request)
    {
        return response()->json($this->clients->list($request->user()->tenant_id, $request->only([
            'search', 'active', 'group_id', 'sort', 'order', 'per_page',
        ])));
    }

    /* ── Summary KPIs (header) ────────────────────────────────── */
    public function summary(Request $request)
    {
        return response()->json($this->clients->summary($request->user()->tenant_id));
    }

    /* ── Create ───────────────────────────────────────────────── */
    public function store(StoreClientRequest $request)
    {
        $client = $this->clients->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($client, 201);
    }

    /* ── Show (profile) ───────────────────────────────────────── */
    public function show(Client $client, Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $data = $this->clients->show($client, $tenantId)->toArray();
        $data['custom_fields'] = $this->customFields->valuesFor($tenantId, 'customers', $client->id);
        $data['financials']    = $this->clients->financials($client, $tenantId);
        return response()->json($data);
    }

    /* ── Update ───────────────────────────────────────────────── */
    public function update(UpdateClientRequest $request, Client $client)
    {
        $updated = $this->clients->update($client, $request->validated(), $request->user()->tenant_id);
        return response()->json($updated);
    }

    /* ── Delete ───────────────────────────────────────────────── */
    public function destroy(Client $client, Request $request)
    {
        $this->clients->delete($client, $request->user()->tenant_id);
        return response()->json(['message' => 'Customer deleted']);
    }

    /* ── Tax tab (GST / TDS rollup) ───────────────────────────── */
    public function taxSummary(Client $client, Request $request)
    {
        return response()->json($this->clients->taxSummary($client, $request->user()->tenant_id));
    }

    /* ── Support tickets loop-in ──────────────────────────────── */
    public function tickets(Client $client, Request $request)
    {
        return response()->json($this->clients->relatedTickets($client, $request->user()->tenant_id));
    }

    /* ── Sales-document loop-ins ──────────────────────────────── */
    public function invoices(Client $client, Request $request)
    {
        return response()->json($this->clients->invoices($client, $request->user()->tenant_id));
    }

    public function estimates(Client $client, Request $request)
    {
        return response()->json($this->clients->estimates($client, $request->user()->tenant_id));
    }

    public function proposals(Client $client, Request $request)
    {
        return response()->json($this->clients->proposals($client, $request->user()->tenant_id));
    }

    public function creditNotes(Client $client, Request $request)
    {
        return response()->json($this->clients->creditNotes($client, $request->user()->tenant_id));
    }

    public function payments(Client $client, Request $request)
    {
        return response()->json($this->clients->payments($client, $request->user()->tenant_id));
    }

    public function statement(Client $client, Request $request)
    {
        return response()->json($this->clients->statement($client, $request->user()->tenant_id));
    }

    /* ── Customer admins (account managers) ───────────────────── */
    public function admins(Client $client, Request $request)
    {
        return response()->json([
            'assigned'   => $this->clients->admins($client, $request->user()->tenant_id),
            'assignable' => $this->clients->assignableStaff($request->user()->tenant_id),
        ]);
    }

    public function syncAdmins(Client $client, Request $request)
    {
        $data = $request->validate(['user_ids' => 'array', 'user_ids.*' => 'integer']);
        return response()->json(
            $this->clients->syncAdmins($client, $data['user_ids'] ?? [], $request->user()->tenant_id)
        );
    }

    /* ── Import (CSV) ─────────────────────────────────────────── */
    public function import(Request $request)
    {
        $request->validate([
            'file'     => 'required|file|mimes:csv,txt|max:5120',
            'simulate' => 'nullable|boolean',
        ]);

        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath(), FILE_SKIP_EMPTY_LINES));

        $result = $this->clients->import(
            $rows,
            $request->user()->tenant_id,
            $request->user()->id,
            $request->boolean('simulate'),
        );

        return response()->json($result);
    }

    /* ── Export (csv | xls) ───────────────────────────────────── */
    public function export(Request $request): StreamedResponse
    {
        $format = $request->query('format') === 'xls' ? 'xls' : 'csv';
        $rows   = $this->clients->exportRows($request->user()->tenant_id, $request->only('search'));
        $name   = 'customers_' . now()->format('Y-m-d') . '.' . $format;

        if ($format === 'xls') {
            return $this->streamXls($rows, $name);
        }

        return $this->streamCsv($rows, $name);
    }

    private function streamCsv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Excel-compatible export without a spreadsheet dependency: an HTML table
     * with the .xls mime type, which Excel/LibreOffice open natively.
     */
    private function streamXls(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            echo "<table border='1'>";
            foreach ($rows as $i => $row) {
                echo '<tr>';
                $tag = $i === 0 ? 'th' : 'td';
                foreach ($row as $cell) {
                    $safe = htmlspecialchars((string) $cell, ENT_QUOTES);
                    echo "<{$tag}>{$safe}</{$tag}>";
                }
                echo '</tr>';
            }
            echo '</table>';
        }, $filename, ['Content-Type' => 'application/vnd.ms-excel']);
    }
}
