<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\ComplianceEvidence;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Evidence locker (Doc 6) — central compliance-evidence register, tenant-scoped. */
class EvidenceLockerController extends Controller
{
    public function index(Request $request)
    {
        $rows = ComplianceEvidence::where('tenant_id', $request->user()->tenant_id)
            ->with(['vendor:id,company_name', 'uploader:id,name'])
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('vendor_id'), fn ($q, $v) => $q->where('vendor_id', $v))
            ->orderByRaw('valid_until is null, valid_until asc')
            ->get();

        // Headline counts of what needs attention.
        $summary = ['expiring' => 0, 'expired' => 0, 'total' => $rows->count()];
        foreach ($rows as $r) {
            if ($r->status === 'Expired') {
                $summary['expired']++;
            } elseif ($r->status === 'Expiring') {
                $summary['expiring']++;
            }
        }

        return response()->json(['data' => $rows, 'summary' => $summary]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category'    => ['required', Rule::in(ComplianceEvidence::CATEGORIES)],
            'title'       => 'required|string|max:200',
            'vendor_id'   => 'nullable|integer',
            'description' => 'nullable|string',
            'file_url'    => 'nullable|url|max:1000',
            'valid_from'  => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ]);
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['uploaded_by'] = $request->user()->id;

        return response()->json(ComplianceEvidence::create($data)->fresh(['vendor:id,company_name', 'uploader:id,name']), 201);
    }

    public function update(Request $request, ComplianceEvidence $evidence)
    {
        abort_unless((int) $evidence->tenant_id === (int) $request->user()->tenant_id, 404);
        $data = $request->validate([
            'category'    => ['sometimes', Rule::in(ComplianceEvidence::CATEGORIES)],
            'title'       => 'sometimes|string|max:200',
            'vendor_id'   => 'nullable|integer',
            'description' => 'nullable|string',
            'file_url'    => 'nullable|url|max:1000',
            'valid_from'  => 'nullable|date',
            'valid_until' => 'nullable|date',
        ]);
        $evidence->update($data);

        return response()->json($evidence->fresh(['vendor:id,company_name', 'uploader:id,name']));
    }

    public function destroy(Request $request, ComplianceEvidence $evidence)
    {
        abort_unless((int) $evidence->tenant_id === (int) $request->user()->tenant_id, 404);
        $evidence->delete();

        return response()->json(['deleted' => true]);
    }
}
