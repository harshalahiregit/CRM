<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer\CustomField;
use App\Services\Customer\CustomFieldService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manages custom-field definitions. Scoped to an entity type via the
 * `field_to` query/body param (defaults to 'customers').
 */
class CustomFieldController extends Controller
{
    public function __construct(private CustomFieldService $customFields)
    {
    }

    public function index(Request $request)
    {
        $fieldTo = $request->query('field_to', 'customers');
        return response()->json(
            $this->customFields->definitions($request->user()->tenant_id, $fieldTo, onlyActive: false)
        );
    }

    public function store(Request $request)
    {
        $data = $this->validateField($request);
        $field = $this->customFields->createDefinition($data, $request->user()->tenant_id);
        return response()->json($field, 201);
    }

    public function update(Request $request, CustomField $customField)
    {
        $data = $this->validateField($request, $customField);
        $field = $this->customFields->updateDefinition($customField, $data, $request->user()->tenant_id);
        return response()->json($field);
    }

    public function destroy(Request $request, CustomField $customField)
    {
        $this->customFields->deleteDefinition($customField, $request->user()->tenant_id);
        return response()->json(['message' => 'Custom field deleted']);
    }

    /** Persist a new field ordering (array of ids, in display order). */
    public function reorder(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        $this->customFields->reorder($data['ids'], $request->user()->tenant_id);
        return response()->json(['message' => 'Order updated']);
    }

    private function validateField(Request $request, ?CustomField $existing = null): array
    {
        return $request->validate([
            'field_to'       => ['sometimes', Rule::in(CustomField::FIELD_TARGETS)],
            'name'           => ['required', 'string', 'max:150'],
            'type'           => ['required', Rule::in(CustomField::TYPES)],
            'options'        => ['nullable', 'string'],
            'required'       => ['nullable', 'boolean'],
            'field_order'    => ['nullable', 'integer', 'min:0'],
            'bs_column'      => ['nullable', Rule::in([12, 6, 4, 3])],
            'only_admin'     => ['nullable', 'boolean'],
            'display_inline' => ['nullable', 'boolean'],
            'active'                => ['nullable', 'boolean'],
            'show_on_table'         => ['nullable', 'boolean'],
            'default_value'         => ['nullable', 'string'],
            'show_on_pdf'           => ['nullable', 'boolean'],
            'show_on_client_portal' => ['nullable', 'boolean'],
            'disallow_client_edit'  => ['nullable', 'boolean'],
            'show_on_ticket_form'   => ['nullable', 'boolean'],
        ]);
    }
}
