<?php

namespace App\Support\Hrm;

use Illuminate\Support\Facades\URL;

/**
 * One answer for "where is this person's photograph".
 *
 * The upload handler built its own signed URL while login and the admin lists
 * each returned a hardcoded '' -- so a picture saved from Settings showed up
 * once, in the response to the save, and was gone the next time the app read
 * the user. It looked like the upload had silently failed.
 *
 * Signed rather than a public path, because storage/avatars is a directory of
 * everybody's faces and a guessable URL hands out the lot.
 */
final class HrmAvatar
{
    /** Days a signed avatar link stays good for. Longer than a session, shorter than forever. */
    private const TTL_DAYS = 7;

    public static function url(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '';
        }

        // Already a URL somebody stored by hand (an imported profile, say).
        if (preg_match('#^(https?:)?//#i', $path) === 1) {
            return $path;
        }

        return URL::temporarySignedRoute(
            'hrm.avatar',
            now()->addDays(self::TTL_DAYS),
            ['path' => base64_encode($path)],
        );
    }
}
