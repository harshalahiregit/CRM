<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreWebToLeadFormRequest;
use App\Models\Sales\WebToLeadForm;
use App\Services\Sales\WebToLeadService;
use Illuminate\Http\Request;

class WebToLeadFormController extends Controller
{
    public function __construct(private WebToLeadService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($request->user()->tenant_id));
    }

    public function store(StoreWebToLeadFormRequest $request)
    {
        $form = $this->service->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($form, 201);
    }

    public function show(WebToLeadForm $webToLeadForm, Request $request)
    {
        return response()->json($this->service->show($webToLeadForm, $request->user()->tenant_id));
    }

    public function update(StoreWebToLeadFormRequest $request, WebToLeadForm $webToLeadForm)
    {
        return response()->json($this->service->update($webToLeadForm, $request->validated(), $request->user()->tenant_id));
    }

    public function destroy(WebToLeadForm $webToLeadForm, Request $request)
    {
        $this->service->delete($webToLeadForm, $request->user()->tenant_id);
        return response()->json(['message' => 'Form deleted']);
    }
}
