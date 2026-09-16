<?php

namespace Sire\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sire\Models\CorrectiveAction;

/**
 * SIRE — factory for CorrectiveAction.
 *
 * Defaults cover only the columns the database refuses to be without. Every test
 * states the values it actually cares about, so a default that guessed at
 * anything else would quietly become a second source of truth for the fixture.
 */
class CorrectiveActionFactory extends Factory
{
    protected $model = CorrectiveAction::class;

    public function definition(): array
    {
        return [
            'tenant_id'   => 1,
            'action_type' => 'corrective',
            'status'   => 'open',
            'title'       => $this->faker->sentence(5),
        ];
    }
}
