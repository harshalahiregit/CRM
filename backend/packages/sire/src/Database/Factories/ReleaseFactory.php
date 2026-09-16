<?php

namespace Sire\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sire\Models\Release;

/**
 * SIRE — factory for Release.
 *
 * Defaults cover only the columns the database refuses to be without. Every test
 * states the values it actually cares about, so a default that guessed at
 * anything else would quietly become a second source of truth for the fixture.
 */
class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    public function definition(): array
    {
        return [
            'tenant_id'    => 1,
            'version'      => $this->faker->unique()->numerify('2026.#.#'),
            'release_type' => 'minor',
            // Stated rather than left to the column default, so the in-memory
            // model and the stored row agree about a governed field.
            'status'       => \Sire\Support\SireReleaseStatus::BLOCKED,
        ];
    }
}
