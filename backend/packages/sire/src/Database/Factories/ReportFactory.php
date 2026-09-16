<?php

namespace Sire\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sire\Models\Report;

/**
 * SIRE — factory for Report.
 *
 * Defaults cover only the columns the database refuses to be without. Every test
 * states the values it actually cares about, so a default that guessed at
 * anything else would quietly become a second source of truth for the fixture.
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'tenant_id'     => 1,
            'report_number' => 'SIR-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'title'         => $this->faker->sentence(6),
            'description'   => $this->faker->paragraph(),
            'status'        => \Sire\Support\SireStatus::NEW,
            'workflow_track' => \Sire\Support\SireTrack::DEFECT,
        ];
    }
}
