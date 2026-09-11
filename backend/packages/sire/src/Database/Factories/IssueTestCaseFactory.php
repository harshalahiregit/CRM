<?php

namespace Sire\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sire\Models\IssueTestCase;

/**
 * SIRE — factory for IssueTestCase.
 *
 * Defaults cover only the columns the database refuses to be without. Every test
 * states the values it actually cares about, so a default that guessed at
 * anything else would quietly become a second source of truth for the fixture.
 */
class IssueTestCaseFactory extends Factory
{
    protected $model = IssueTestCase::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'report_id' => 1,
            'category'  => \Sire\Support\SireTestCategory::HAPPY_PATH,
            'title'     => $this->faker->sentence(5),
        ];
    }
}
