<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesDocumentTemplateRequest;
use App\Http\Requests\Sales\UpdateSalesDocumentTemplateRequest;
use App\Models\Sales\SalesDocumentTemplate;
use App\Services\Sales\SalesDocumentTemplateService;
use Illuminate\Http\Request;

/**
 * Line-item templates for invoices, estimates and proposals.
 *
 * Tenant id always comes from the authenticated user, never the request, and the
 * service re-checks ownership on every write.
 */
class SalesDocumentTemplateController extends Controller
{
    public function __construct(private SalesDocumentTemplateService $templates)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->templates->list(
            $request->user()->tenant_id,
            $request->query('doc_type'),
        ));
    }

    public function show(Request $request, SalesDocumentTemplate $salesDocumentTemplate)
    {
        return response()->json($this->templates->show($salesDocumentTemplate, $request->user()->tenant_id));
    }

    public function store(StoreSalesDocumentTemplateRequest $request)
    {
        return response()->json($this->templates->create(
            $request->validated(),
            $request->user()->tenant_id,
            $request->user()->id,
        ), 201);
    }

    public function update(UpdateSalesDocumentTemplateRequest $request, SalesDocumentTemplate $salesDocumentTemplate)
    {
        return response()->json($this->templates->update(
            $salesDocumentTemplate,
            $request->validated(),
            $request->user()->tenant_id,
        ));
    }

    public function destroy(Request $request, SalesDocumentTemplate $salesDocumentTemplate)
    {
        $this->templates->delete($salesDocumentTemplate, $request->user()->tenant_id);

        return response()->json(['message' => 'Template deleted']);
    }

    public function duplicate(Request $request, SalesDocumentTemplate $salesDocumentTemplate)
    {
        return response()->json($this->templates->duplicate(
            $salesDocumentTemplate,
            $request->user()->tenant_id,
            $request->user()->id,
        ), 201);
    }

    /** Save an existing invoice / estimate / proposal as a reusable template. */
    public function saveFromDocument(Request $request)
    {
        $data = $request->validate([
            'doc_type'    => ['required', \Illuminate\Validation\Rule::in(SalesDocumentTemplate::TYPES)],
            'document_id' => 'required|integer',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        return response()->json($this->templates->saveFromDocument(
            $data['doc_type'],
            $data['document_id'],
            $data,
            $request->user()->tenant_id,
            $request->user()->id,
        ), 201);
    }
}
