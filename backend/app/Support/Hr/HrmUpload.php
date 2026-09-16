<?php

namespace App\Support\Hr;

/**
 * One answer to "what may somebody attach to a request from the app?"
 *
 * The four things a person submits — an expense claim, an advance, a settlement,
 * a leave or attendance correction — each had their own idea. Reimbursement took
 * an array of office documents, advance took ONE file, settlement took at most
 * ten, and leave took none at all. So the same person photographing the same
 * medical certificate could attach it to one request and not the next, with no
 * reason they could see.
 *
 * ANY FILE TYPE. A person attaching proof should not have to know which formats
 * we thought of: a .heic from an iPhone, a .csv from a bank, an .odt from a
 * clinic. The dangerous extensions are refused by AttachmentService, which owns
 * that list and applies it to every upload in the product — repeating a `mimes:`
 * rule here would be a second, weaker copy that drifts.
 *
 * There IS a count limit, and it is not a policy about how many receipts an
 * expense may have. An unbounded array is a request that can be made to exhaust
 * memory by anybody with a login. Twenty-five is far above what a real claim
 * carries and far below what hurts.
 */
final class HrmUpload
{
    /** Per file. Above this a phone photo has not been resized by anything. */
    public const MAX_KB = 10240;

    /** Not a policy — a ceiling that stops one request eating the server. */
    public const MAX_FILES = 25;

    /**
     * Validation rules for a multi-file field.
     *
     * @param  string  $field  the array field name, e.g. 'attachments'
     * @return array<string, string>
     */
    public static function rules(string $field): array
    {
        return [
            $field       => 'nullable|array|max:'.self::MAX_FILES,
            $field.'.*'  => 'file|max:'.self::MAX_KB,
        ];
    }

    /**
     * The same, plus the single-file field the app used to post.
     *
     * Older builds of the app are still installed on people's phones and post
     * one file under the singular name. Accepting both means a person who has
     * not updated can still attach their receipt, rather than having it silently
     * dropped by a validator that only knows about the new name.
     *
     * @return array<string, string>
     */
    public static function rulesWithLegacy(string $field, string $legacyField): array
    {
        return self::rules($field) + [
            $legacyField => 'nullable|file|max:'.self::MAX_KB,
        ];
    }
}
