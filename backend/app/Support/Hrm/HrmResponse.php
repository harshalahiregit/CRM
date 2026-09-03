<?php

namespace App\Support\Hrm;

use Illuminate\Http\JsonResponse;

/**
 * SangoeTrack's response envelope, so the app does not have to change.
 *
 * The app is the fragile side of this migration: 271 parse sites, and a key it
 * does not recognise renders BLANK rather than failing. So the CRM answers in
 * their shape instead of asking Dart to learn ours.
 *
 * Their conventions, which are not Laravel's and are deliberately reproduced:
 *
 *   status      an INTEGER, 1 for success and 0 for a refusal — not the string
 *               'success' the rest of this API uses.
 *   HTTP 200    even for a refusal. The app checks the body, not the status
 *               line, so a 422 is read as a transport failure rather than
 *               "you typed the wrong password".
 *   HTTP 403    for validation problems, which is what their backend returns.
 *   HTTP 401    ONLY for a genuinely dead session. The app treats 401 as
 *               destructive — it wipes local storage, including the cached
 *               clock-in state, and drops the user at the login screen with no
 *               explanation. Returning it for a permission problem would sign
 *               somebody out mid-shift.
 */
class HrmResponse
{
    public static function ok(array $data = [], string $message = 'Success'): JsonResponse
    {
        return response()->json([
            'status'  => 1,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * A refusal the app should show to the person.
     *
     * 200 on purpose. Their app reads `status` from the body; a non-2xx here is
     * surfaced as "something went wrong" instead of the actual reason.
     */
    public static function fail(string $message, array $data = []): JsonResponse
    {
        return response()->json([
            'status'  => 0,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /** Validation, their way: 403 with the same envelope. */
    public static function invalid(string $message, array $errors = []): JsonResponse
    {
        return response()->json([
            'status'  => 0,
            'message' => $message,
            'errors'  => $errors,
        ], 403);
    }

    /**
     * The session is genuinely gone.
     *
     * Use this and only this for a dead token. It logs the user out of the app
     * and clears what they had cached, so it must never stand in for "you are
     * not allowed to do that".
     */
    public static function unauthenticated(string $message = 'Session expired. Please sign in again.'): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => $message], 401);
    }
}
