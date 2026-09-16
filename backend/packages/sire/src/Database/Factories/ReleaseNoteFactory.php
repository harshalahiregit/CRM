<?php

namespace Sire\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sire\Models\ReleaseNote;

/**
 * SIRE — factory for ReleaseNote.
 *
 * Defaults cover only the columns the database refuses to be without. Every test
 * states the values it actually cares about, so a default that guessed at
 * anything else would quietly become a second source of truth for the fixture.
 */
class ReleaseNoteFactory extends Factory
{
    protected $model = ReleaseNote::class;

    public function definition(): array
    {
        return [
            'tenant_id'  => 1,
            'release_id' => 1,
            'audience'   => 'internal',
            'status'    => 'draft',
        ];
    }
}
