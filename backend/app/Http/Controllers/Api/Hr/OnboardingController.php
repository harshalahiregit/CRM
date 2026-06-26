<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrOnboarding;
use App\Models\HrEmployee;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OnboardingController extends Controller
{
    public function index(Request $request)
    {
        $query = HrOnboarding::query();
        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'candidate_id'   => 'nullable|exists:hr_candidates,id',
            'candidate_name' => 'required|string',
            'position'       => 'required|string',
            'joining_date'   => 'required|date',
            'department'     => 'nullable|string',
        ]);

        $record = HrOnboarding::create([...$validated, 'status' => 'Pending']);
        return response()->json($record, 201);
    }

    public function show(HrOnboarding $onboarding)
    {
        return response()->json($onboarding);
    }

    public function toggleStep(Request $request, HrOnboarding $onboarding)
    {
        $request->validate(['step' => 'required|in:doc_verification,joining_confirmed,emp_id_generated,dept_assigned,manager_assigned,record_created']);

        $col = 'step_'.$request->step;
        $onboarding->update([$col => !$onboarding->$col]);

        // Recalculate status
        $steps = [
            $onboarding->step_doc_verification,
            $onboarding->step_joining_confirmed,
            $onboarding->step_emp_id_generated,
            $onboarding->step_dept_assigned,
            $onboarding->step_manager_assigned,
            $onboarding->step_record_created,
        ];
        $doneCount = count(array_filter($steps));
        $status = $doneCount === 0 ? 'Pending' : ($doneCount === 6 ? 'Completed' : 'In Progress');
        $onboarding->update(['status' => $status]);

        // If all done, auto-create employee record
        if ($status === 'Completed' && !HrEmployee::where('candidate_id', $onboarding->candidate_id)->exists()) {
            $empCode = 'SNE-'.date('Y').'-'.str_pad(HrEmployee::count() + 1, 3, '0', STR_PAD_LEFT);
            HrEmployee::create([
                'employee_code'          => $empCode,
                'candidate_id'           => $onboarding->candidate_id,
                'onboarding_id'          => $onboarding->id,
                'name'                   => $onboarding->candidate_name,
                'department'             => $onboarding->department,
                'designation'            => $onboarding->position,
                'reporting_manager_name' => $onboarding->reporting_manager_name,
                'joining_date'           => $onboarding->joining_date,
                'status'                 => 'Active',
            ]);
        }

        return response()->json($onboarding->fresh());
    }

    public function destroy(HrOnboarding $onboarding)
    {
        $onboarding->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
