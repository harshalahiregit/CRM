<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrEmployee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = HrEmployee::where('tenant_id', $request->user()->tenant_id);
        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        if ($request->filled('department') && $request->department !== 'All') {
            $query->where('department', $request->department);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                  ->orWhere('employee_code', 'like', '%'.$request->search.'%')
                  ->orWhere('designation', 'like', '%'.$request->search.'%');
            });
        }
        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                   => 'required|string',
            'email'                  => 'nullable|email',
            'phone'                  => 'nullable|string',
            'dob'                    => 'nullable|date',
            'gender'                 => 'nullable|in:Male,Female,Other,Prefer not to say',
            'address'                => 'nullable|string',
            'department'             => 'required|string',
            'designation'            => 'required|string',
            'reporting_manager_name' => 'nullable|string',
            'joining_date'           => 'required|date',
            'probation_end_date'     => 'nullable|date',
            'confirmation_date'      => 'nullable|date',
            'status'                 => 'in:Active,On Leave,Inactive',
        ]);

        $validated['tenant_id'] = $request->user()->tenant_id;
        $empCode = 'SNE-'.date('Y').'-'.str_pad(HrEmployee::where('tenant_id', $request->user()->tenant_id)->count() + 1, 3, '0', STR_PAD_LEFT);
        $employee = HrEmployee::create([...$validated, 'employee_code' => $empCode]);
        return response()->json($employee, 201);
    }


    public function show(HrEmployee $employee)
    {
        return response()->json($employee);
    }

    public function update(Request $request, HrEmployee $employee)
    {
        $employee->update($request->all());
        return response()->json($employee);
    }

    public function destroy(HrEmployee $employee)
    {
        $employee->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        return response()->json([
            'total'    => HrEmployee::where('tenant_id', $tenantId)->count(),
            'active'   => HrEmployee::where('tenant_id', $tenantId)->where('status','Active')->count(),
            'on_leave' => HrEmployee::where('tenant_id', $tenantId)->where('status','On Leave')->count(),
            'inactive' => HrEmployee::where('tenant_id', $tenantId)->where('status','Inactive')->count(),
            'by_dept'  => HrEmployee::where('tenant_id', $tenantId)->select('department')
                ->selectRaw('count(*) as count')
                ->groupBy('department')->get(),
        ]);
    }
}
