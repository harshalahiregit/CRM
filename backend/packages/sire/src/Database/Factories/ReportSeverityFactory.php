<?php

namespace Sire\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sire\Models\ReportSeverity;

/**
 * SIRE — factory for ReportSeverity.
 *
 * Defaults cover only the columns the database refuses to be without. Every test
 * states the values it actually cares about, so a default that guessed at
 * anything else would quietly become a second source of truth for the fixture.
 */
class ReportSeverityFactory extends Factory
{
    protected $model = ReportSeverity::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'name'      => 'S2 - High',
            'code'      => 's2',
            'level'     => 3,
        ];
    }
}
