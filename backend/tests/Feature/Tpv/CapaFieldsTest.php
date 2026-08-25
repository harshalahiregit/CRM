<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvCapa;
use App\Services\Tpv\TpvCapaService;
use App\Support\Tpv\CapaSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §25 CAPA field completeness — a CAPA now carries a distinct problem statement,
 * immediate correction (containment), and separate corrective vs. preventive
 * actions, and "compliance failure" is a valid source kind.
 */
class CapaFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_the_new_action_fields_persist(): void
    {
        $capa = app(TpvCapaService::class)->create([
            'title'                => 'Guard rail failure',
            'problem_statement'    => 'A guard rail gave way on level 3.',
            'immediate_correction' => 'Area cordoned off and work stopped.',
            'action'               => 'Replace the failed rail section.',
            'preventive_action'    => 'Monthly rail load-test added to the PM schedule.',
        ], self::TENANT, 1);

        $fresh = $capa->fresh();
        $this->assertSame('A guard rail gave way on level 3.', $fresh->problem_statement);
        $this->assertSame('Area cordoned off and work stopped.', $fresh->immediate_correction);
        $this->assertSame('Replace the failed rail section.', $fresh->action);
        $this->assertSame('Monthly rail load-test added to the PM schedule.', $fresh->preventive_action);
    }

    public function test_compliance_failure_is_a_valid_source_kind(): void
    {
        $this->assertContains('compliance_failure', CapaSource::KINDS);
        $this->assertSame('Compliance failure', CapaSource::label('compliance_failure'));

        $capa = app(TpvCapaService::class)->create(
            ['title' => 'Missing insurance renewal', 'source_kind' => 'compliance_failure'],
            self::TENANT,
            1
        );
        $this->assertSame('compliance_failure', $capa->fresh()->source_kind);
    }
}
