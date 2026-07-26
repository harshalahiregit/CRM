<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreReminderRequest;
use App\Models\Sales\Reminder;
use App\Services\Sales\ReminderService;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function __construct(private ReminderService $reminderService)
    {
    }

    public function index(Request $request)
    {
        $reminders = $this->reminderService->list($request->user()->tenant_id, $request->only([
            'subject_type', 'subject_id', 'staff_id', 'type', 'pending', 'from', 'to',
        ]));
        return response()->json($reminders);
    }

    public function upcoming(Request $request)
    {
        return response()->json($this->reminderService->upcoming($request->user()->tenant_id, $request->user()->id));
    }

    public function store(StoreReminderRequest $request)
    {
        $reminder = $this->reminderService->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($reminder, 201);
    }

    public function update(Request $request, Reminder $reminder)
    {
        $data = $request->validate([
            'type'     => 'sometimes|in:call,meeting,email,whatsapp,sms,visit',
            'title'    => 'sometimes|string|max:255',
            'notes'    => 'nullable|string',
            'due_at'   => 'sometimes|date',
            'priority' => 'nullable|in:low,medium,high',
            'staff_id' => 'nullable|exists:users,id',
        ]);
        return response()->json($this->reminderService->update($reminder, $data, $request->user()->tenant_id));
    }

    public function destroy(Request $request, Reminder $reminder)
    {
        $this->reminderService->delete($reminder, $request->user()->tenant_id);
        return response()->json(['message' => 'Reminder deleted']);
    }

    public function complete(Request $request, Reminder $reminder)
    {
        $data = $request->validate([
            'outcome'        => 'nullable|string',
            'next_follow_up' => 'nullable|date',
        ]);
        return response()->json($this->reminderService->complete($reminder, $data, $request->user()->tenant_id, $request->user()->id));
    }
}
