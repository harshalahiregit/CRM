<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrEmployee;
use App\Services\Notifications\AnnouncementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function __construct(private AnnouncementService $announcements)
    {
    }

    /** Who an announcement can be addressed to, for the composer's pickers. */
    public function audience(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $people = HrEmployee::where('tenant_id', $tenantId)
            ->whereNotNull('user_id')
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['id', 'user_id', 'name', 'employee_code', 'department']);

        return response()->json([
            'employees'   => $people,
            'departments' => $people->pluck('department')->filter()->unique()->sort()->values(),
            // Said plainly, because "sent to 40 people" and "40 people have the
            // app" are different numbers and the difference is the point.
            'reachable'   => $people->count(),
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'title'      => 'required|string|max:150',
            'body'       => 'required|string|max:4000',
            'audience'   => ['required', Rule::in(['all', 'department', 'employees'])],
            'department' => 'required_if:audience,department|nullable|string|max:120',
            'user_ids'   => 'required_if:audience,employees|nullable|array',
            'user_ids.*' => 'integer',
            'channels'   => 'nullable|array',
            'channels.*' => ['string', Rule::in(['in_app', 'push', 'email'])],
            // A policy or a notice, which is what people attach. Capped so an
            // announcement cannot be used to move a large file to every phone.
            'attachment' => 'nullable|file|mimes:pdf|max:10240',
        ]);

        $result = $this->announcements->send(
            $request->user()->tenant_id,
            $data,
            $request->user(),
            $request->file('attachment'),
        );

        if ($result['recipients'] === 0) {
            return response()->json([
                'message' => 'Nobody matched that audience, so nothing was sent.',
            ], 422);
        }

        return response()->json([
            'message'    => "Sent to {$result['recipients']} ".($result['recipients'] === 1 ? 'person' : 'people').'.',
            'recipients' => $result['recipients'],
        ]);
    }

    /** The attached PDF, for whoever the announcement was sent to. */
    public function file(Request $request, string $path)
    {
        $decoded = base64_decode($path, true);

        // Confined to this tenant's announcements: a decoded path is user input
        // and must never be able to walk out of the folder it belongs to.
        $prefix = "hr/announcements/tenant_{$request->user()->tenant_id}/";

        abort_unless(
            $decoded && str_starts_with($decoded, $prefix) && ! str_contains($decoded, '..')
                && Storage::disk('local')->exists($decoded),
            404,
        );

        return response()->file(Storage::disk('local')->path($decoded));
    }
}
