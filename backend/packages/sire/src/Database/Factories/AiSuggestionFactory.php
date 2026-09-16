<?php

namespace Sire\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sire\Models\AiSuggestion;

/**
 * SIRE — factory for AiSuggestion.
 *
 * Defaults cover only the columns the database refuses to be without. Every test
 * states the values it actually cares about, so a default that guessed at
 * anything else would quietly become a second source of truth for the fixture.
 */
class AiSuggestionFactory extends Factory
{
    protected $model = AiSuggestion::class;

    public function definition(): array
    {
        return [
            'tenant_id'    => 1,
            'subject_type' => 'report',
            'subject_id'   => 1,
            'capability'   => \Sire\Support\Ai\AiCapability::CLASSIFICATION,
        ];
    }
}
