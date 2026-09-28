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
 *   HTTP 403    is what their backend returns for validation — NOT reproduced
 *               here, and invalid() below says why.
 *   HTTP 401    ONLY for a genuinely dead session. The app treats 401 as
 *               destructive — it wipes local storage, including the cached
 *               clock-in state, and drops the user at the login screen with no
 *               explanation. Returning it for a permission problem would sign
 *               somebody out mid-shift.
 */
class HrmResponse
{
    /**
     * @param array $extra keys the app reads as SIBLINGS of `data`, not inside it.
     *
     * The notifications screen is the case that needs this: it does
     * `res['data'] as List` and separately `res['unread_count']`, so nesting the
     * pagination inside `data` made `data` a Map and threw
     * "_Map<String, dynamic> is not a subtype of List<dynamic>" — the screen
     * died on open. Extra keys go alongside, never inside.
     */
    public static function ok(array $data = [], string $message = 'Success', array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'status'  => 1,
            'message' => $message,
            'data'    => $data,
        ], $extra));
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

    /**
     * A rejected field.
     *
     * SangoeTrack answers 403 here, and this deliberately does not, because the
     * app cannot read a 403. Its network layer keeps the body only on a 200 —
     * anything else is toasted once and discarded, returning null — so the
     * calling controller then falls back to its own generic message. The result
     * on track is two toasts, the second one useless, and the `errors` map below
     * unreachable by any screen.
     *
     * 200 with status 0 is the same envelope every other refusal on this surface
     * already uses, and the app handles it properly: one toast, naming the field.
     * Diverging from track by one status code is worth a usable error.
     */
    public static function invalid(string $message, array $errors = []): JsonResponse
    {
        return response()->json([
            'status'  => 0,
            'message' => $message,
            'errors'  => $errors,
            // The app reads `data` on some paths; an empty list keeps every
            // parse site on the success shape rather than hitting a null.
            'data'    => [],
        ]);
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
