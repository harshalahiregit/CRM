<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAttendance;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The photo taken at a punch.
 *
 * Signed rather than authenticated, for the same reason the app's file route is:
 * the register renders these in an <img>, which sends no Authorization header.
 * The signature carries the permission and expires within the hour, so a link
 * copied out of the page stops working rather than becoming a permanent public
 * URL to a photograph of somebody's face.
 */
class AttendanceSelfieController extends Controller
{
    public function show(HrAttendance $attendance, string $which): Response
    {
        $column = $which === 'out' ? 'check_out_selfie' : 'check_in_selfie';
        $path   = $attendance->{$column};

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path));
    }
}
