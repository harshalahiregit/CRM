<?php

namespace Tests\Feature\Tpv;

use App\Services\Tpv\RegistrationNumberService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegistrationNumberServiceTest extends TestCase
{
    private RegistrationNumberService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Self-provision only the sequence table this service uses, so the test
        // is independent of the full migration set (avoids unrelated modules).
        Schema::dropIfExists('tpv_registration_sequences');
        Schema::create('tpv_registration_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'year']);
        });

        $this->service = app(RegistrationNumberService::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tpv_registration_sequences');
        parent::tearDown();
    }

    public function test_first_number_is_formatted_and_padded(): void
    {
        $number = $this->service->generate(1, Carbon::create(2026, 7, 22));

        $this->assertSame('TPV-2026-00001', $number);
    }

    public function test_numbers_increment_within_a_tenant_year(): void
    {
        $when = Carbon::create(2026, 7, 22);

        $this->assertSame('TPV-2026-00001', $this->service->generate(1, $when));
        $this->assertSame('TPV-2026-00002', $this->service->generate(1, $when));
        $this->assertSame('TPV-2026-00003', $this->service->generate(1, $when));
    }

    public function test_sequences_are_isolated_per_tenant(): void
    {
        $when = Carbon::create(2026, 7, 22);

        $this->assertSame('TPV-2026-00001', $this->service->generate(1, $when));
        $this->assertSame('TPV-2026-00001', $this->service->generate(2, $when)); // tenant 2 starts fresh
        $this->assertSame('TPV-2026-00002', $this->service->generate(1, $when));
    }

    public function test_counter_resets_per_year(): void
    {
        $this->assertSame('TPV-2026-00001', $this->service->generate(1, Carbon::create(2026, 12, 31)));
        $this->assertSame('TPV-2027-00001', $this->service->generate(1, Carbon::create(2027, 1, 1)));
    }

    public function test_numbers_are_unique_across_many_generations(): void
    {
        $when = Carbon::create(2026, 7, 22);
        $numbers = [];
        for ($i = 0; $i < 50; $i++) {
            $numbers[] = $this->service->generate(1, $when);
        }

        $this->assertCount(50, array_unique($numbers));
        $this->assertSame('TPV-2026-00050', end($numbers));
    }
}
