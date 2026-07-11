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

    private function validateField(Request $request, ?CustomField $existing = null): array
    {
        return $request->validate([
            'field_to'      => ['sometimes', 'string', 'max:30'],
            'name'          => ['required', 'string', 'max:150'],
            'type'          => ['required', Rule::in(CustomField::TYPES)],
            'options'       => ['nullable', 'string'],
            'required'      => ['nullable', 'boolean'],
            'field_order'   => ['nullable', 'integer'],
            'active'        => ['nullable', 'boolean'],
            'show_on_table' => ['nullable', 'boolean'],
            'default_value' => ['nullable', 'string'],
        ]);
    }
}
