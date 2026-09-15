<?php

namespace Sire\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sire\Models\ReportWatcher;

/**
 * SIRE — factory for ReportWatcher.
 *
 * Defaults cover only the columns the database refuses to be without. Every test
 * states the values it actually cares about, so a default that guessed at
 * anything else would quietly become a second source of truth for the fixture.
 */
class ReportWatcherFactory extends Factory
{
    protected $model = ReportWatcher::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'report_id' => 1,
            'user_id'   => 1,
        ];
    }
}
