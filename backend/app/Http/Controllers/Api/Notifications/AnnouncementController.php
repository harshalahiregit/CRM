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
            // Several files, and not only PDFs — a photograph of a notice board
            // or a scanned circular is exactly what people attach. Capped per
            // file and in number so an announcement cannot be used to push a
            // large payload to every phone in the company.
            'attachments'   => 'nullable|array|max:10',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx|max:10240',
        ]);

        $result = $this->announcements->send(
            $request->user()->tenant_id,
            $data,
            $request->user(),
            $request->file('attachments', []),
        );

        if ($result['recipients'] === 0) {
            return response()->json([
                'message' => 'Nobody matched that audience, so nothing was sent.',
            ], 422);
        }

        // Two different facts, and the difference matters: everybody addressed
        // gets it in the app, but only people who have signed in on a phone get
        // a push. Saying only the first would let somebody believe an urgent
        // notice reached pockets when it reached screens nobody was looking at.
        $people = $result['recipients'].' '.($result['recipients'] === 1 ? 'person' : 'people');
        $pushed = $result['pushed'] ?? 0;

        // 'people', not 'devices': one queue item is one recipient, and a person
        // may carry two phones. Counting items as devices would overstate reach
        // by exactly the number of people with a tablet.
        $message = "Sent to {$people}.";
        $message .= $pushed > 0
            ? " {$pushed} ".($pushed === 1 ? 'person' : 'people').' reached on their phone.'
            : ' No phones reached — nobody in this audience has signed in to the app.';

        return response()->json([
            'message'    => $message,
            'recipients' => $result['recipients'],
            'pushed'     => $pushed,
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
