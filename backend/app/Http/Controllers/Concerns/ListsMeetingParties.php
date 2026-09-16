<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Shared\MeetingPartyDirectory;
use Illuminate\Http\Request;

/**
 * The two endpoints behind the four-column attendance grid.
 *
 * A trait rather than a base class because the two meeting controllers already
 * have separate parents and separate models — they are the same engine over
 * different tables, and every previous attempt to share behaviour between them
 * by inheritance ended with one of them quietly reading the other's records.
 * Here there is nothing to get wrong: both delegate to the same read-only
 * directory and neither adds a line of its own.
 */
trait ListsMeetingParties
{
    /** The four columns, their selectable companies, and the internal team. */
    public function parties(Request $request, MeetingPartyDirectory $directory)
    {
        return response()->json([
            'parties' => $directory->parties((int) $request->user()->tenant_id),
        ]);
    }

    /** One company's registered people, for the column that picked it. */
    public function partyPeople(Request $request, MeetingPartyDirectory $directory)
    {
        $data = $request->validate([
            'party'     => 'required|string|max:16',
            'entity_id' => 'required|integer|min:1',
        ]);

        abort_unless(MeetingPartyDirectory::isParty($data['party']), 422, 'Unknown party.');

        return response()->json([
            'people' => $directory->people(
                (int) $request->user()->tenant_id,
                $data['party'],
                (int) $data['entity_id'],
            ),
        ]);
    }
}
