<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreClientRequest;
use App\Http\Requests\Customer\UpdateClientRequest;
use App\Models\Customer\Client;
use App\Services\Customer\ClientImportExportService;
use App\Services\Customer\ClientLedgerService;
use App\Services\Customer\ClientService;
use App\Services\Customer\CustomFieldService;
use App\Support\Spreadsheet;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClientController extends Controller
{
    public function __construct(
        private ClientService $clients,
        private ClientLedgerService $ledger,
        private ClientImportExportService $io,
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
        $data['financials']    = $this->ledger->financials($client, $tenantId);
        return response()->json($data);
    }

    /* ── Update ───────────────────────────────────────────────── */
    public function update(UpdateClientRequest $request, Client $client)
    {
        $updated = $this->clients->update($client, $request->validated(), $request->user()->tenant_id);
        return response()->json($updated);
    }

    /* ── Toggle active/inactive (one-click) ───────────────────── */
    public function toggleActive(Client $client, Request $request)
    {
        $updated = $this->clients->toggleActive($client, $request->user()->tenant_id);
        return response()->json(['id' => $updated->id, 'active' => $updated->active]);
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
        return response()->json($this->ledger->taxSummary($client, $request->user()->tenant_id));
    }

    /* ── Support tickets loop-in ──────────────────────────────── */
    public function tickets(Client $client, Request $request)
    {
        return response()->json($this->ledger->relatedTickets($client, $request->user()->tenant_id));
    }

    /* ── Sales-document loop-ins ──────────────────────────────── */
    public function invoices(Client $client, Request $request)
    {
        return response()->json($this->ledger->invoices($client, $request->user()->tenant_id));
    }

    public function estimates(Client $client, Request $request)
    {
        return response()->json($this->ledger->estimates($client, $request->user()->tenant_id));
    }

    public function proposals(Client $client, Request $request)
    {
        return response()->json($this->ledger->proposals($client, $request->user()->tenant_id));
    }

    public function creditNotes(Client $client, Request $request)
    {
        return response()->json($this->ledger->creditNotes($client, $request->user()->tenant_id));
    }

    public function payments(Client $client, Request $request)
    {
        return response()->json($this->ledger->payments($client, $request->user()->tenant_id));
    }

    public function statement(Client $client, Request $request)
    {
        return response()->json($this->ledger->statement($client, $request->user()->tenant_id));
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

    /* ── Import (CSV or Excel) ────────────────────────────────── */
    public function import(Request $request)
    {
        $request->validate([
            'file'     => 'required|file|mimes:csv,txt,xlsx,xls|max:5120',
            'simulate' => 'nullable|boolean',
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'csv');
        $rows = Spreadsheet::readRows($file->getRealPath(), $ext);

        $result = $this->io->import(
            $rows,
            $request->user()->tenant_id,
            $request->user()->id,
            $request->boolean('simulate'),
        );

        return response()->json($result);
    }

    /* ── Export (csv | xlsx) ──────────────────────────────────── */
    public function export(Request $request): Response
    {
        $format = strtolower($request->query('format', 'csv')) === 'xlsx' ? 'xlsx' : 'csv';
        $rows   = $this->io->exportRows($request->user()->tenant_id, $request->only('search'));
        $name   = 'customers_' . now()->format('Y-m-d') . '.' . $format;

        return Spreadsheet::download($rows, $name, $format);
    }

    /* ── Sample import template (csv | xlsx) ──────────────────── */
    public function sample(Request $request): Response
    {
        $format = strtolower($request->query('format', 'csv')) === 'xlsx' ? 'xlsx' : 'csv';
        $name   = 'customers_import_template.' . $format;

        return Spreadsheet::download($this->io->sampleRows(), $name, $format);
    }
}
