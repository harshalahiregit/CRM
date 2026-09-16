<?php

namespace Sire\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sire\Models\ReportAssignee;

/**
 * SIRE — factory for ReportAssignee.
 *
 * Defaults cover only the columns the database refuses to be without. Every test
 * states the values it actually cares about.
 */
class ReportAssigneeFactory extends Factory
{
    protected $model = ReportAssignee::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'report_id' => 1,
            'user_id'   => 1,
        ];
    }
}
