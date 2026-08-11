<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\Appointment;
use App\Services\Sales\AppointmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $appointments)
    {
    }

    /** Appointments for one subject, e.g. ?subject_type=lead&subject_id=12 */
    public function index(Request $request)
    {
        $data = $request->validate([
            'subject_type' => 'required|string|max:40',
            'subject_id'   => 'required|integer|min:1',
        ]);

        return response()->json($this->appointments->listForSubject(
            $request->user()->tenant_id,
            $data['subject_type'],
            (int) $data['subject_id'],
        ));
    }

    /** Agenda view — everything scheduled in the next N days. */
    public function upcoming(Request $request)
    {
        return response()->json($this->appointments->upcoming(
            $request->user()->tenant_id,
            (int) $request->query('days', 14),
        ));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));

        return response()->json($this->appointments->create(
            $data,
            $request->user()->tenant_id,
            $request->user()->id,
        ), 201);
    }

    public function update(Request $request, Appointment $appointment)
    {
        $data = $request->validate($this->rules(false));

        return response()->json($this->appointments->update(
            $appointment,
            $data,
            $request->user()->tenant_id,
        ));
    }

    public function complete(Request $request, Appointment $appointment)
    {
        $data = $request->validate([
            'outcome' => 'required|string',
            'status'  => ['nullable', Rule::in(['completed', 'no_show', 'cancelled'])],
        ]);

        return response()->json($this->appointments->complete(
            $appointment,
            $data,
            $request->user()->tenant_id,
        ));
    }

    public function destroy(Request $request, Appointment $appointment)
    {
        $this->appointments->delete($appointment, $request->user()->tenant_id);

        return response()->json(['message' => 'Appointment deleted']);
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes|required';

        return [
            'subject_type' => $creating ? 'required|string|max:40' : 'prohibited',
            'subject_id'   => $creating ? 'required|integer|min:1' : 'prohibited',
            'title'        => $required . '|string|max:255',
            'description'  => 'nullable|string',
            'starts_at'    => $required . '|date',
            // Cross-field ordering is checked in the service, which can compare a
            // partial update against the stored value.
            'ends_at'      => 'nullable|date',
            'location'     => 'nullable|string|max:255',
            'mode'         => ['nullable', Rule::in(Appointment::MODES)],
            'meeting_url'  => 'nullable|url|max:500',
            'status'       => ['nullable', Rule::in(Appointment::STATUSES)],
            'outcome'      => 'nullable|string',
            'assigned_to'  => 'nullable|integer|exists:users,id',
            'remind_before_minutes' => 'nullable|integer|min:0|max:20160',
        ];
    }
}
