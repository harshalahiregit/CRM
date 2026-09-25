<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrOffer;
use App\Models\Tenant;
use App\Services\Hr\OfferService;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * How long an offer stays open when nobody names a date.
 *
 * Seven days was written into three separate expressions — the offer raised
 * automatically from onboarding, regenerating an offer, and extending one — so
 * a company that gives candidates a fortnight had to retype the date every time
 * or accept a week.
 *
 * A DEFAULT, NOT A RULE, and most of this file defends that distinction. An
 * explicit validity_date always wins, and an offer deliberately created with no
 * date still has none: a blank validity means an offer with no deadline, which
 * is a supported state and not something a setting should quietly fill in. Only
 * the three places that already invented a date consult it.
 *
 * The offer token model is untouched here. Nothing in this file issues, reads or
 * stores a credential — validity is the offer's commercial deadline and has
 * never been the link's lifetime.
 */
class OfferValidityConfigTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('hr_documents');
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'ovc-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'ovc-b', 'status' => 'active']);
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function setDays($days, ?Tenant $t = null): void
    {
        app(SettingsService::class)->set(($t ?: $this->a)->id, HrSetting::GROUP, 'offer_validity_days', $days);
    }

    private function offer(array $attrs = [], ?Tenant $t = null): HrOffer
    {
        $t = $t ?: $this->a;
        $candidate = HrCandidate::create([
            'tenant_id' => $t->id, 'name' => 'C'.substr(uniqid(), -5),
            'email' => uniqid().'@cand.test', 'phone' => '9000000000',
            'whatsapp_opt_in' => false, 'stage' => 'Offer', 'status' => 'Active',
        ]);

        return HrOffer::create(array_merge([
            'tenant_id' => $t->id, 'candidate_id' => $candidate->id,
            'position' => 'Analyst', 'department' => 'Operations', 'offered_ctc' => 900000,
            'joining_date' => '2026-08-01', 'validity_date' => '2026-06-10',
            'status' => 'Sent', 'sent_at' => now(),
        ], $attrs));
    }

    private function offers(): OfferService
    {
        return app(OfferService::class);
    }

    /* ═══════════════ 1. THE DEFAULT ═════════════════════════════════ */

    /** Unset, the setting behaves exactly as the hardcoded week did. */
    public function test_an_unconfigured_tenant_still_gets_seven_days(): void
    {
        $offer = $this->offer(['status' => 'Expired']);

        $this->offers()->extend($offer, null);

        $this->assertSame('2026-06-08', $offer->fresh()->validity_date->toDateString());
    }

    /** @test */
    public function the_configured_number_of_days_is_used_when_extending(): void
    {
        $this->setDays(14);
        $offer = $this->offer(['status' => 'Expired']);

        $this->offers()->extend($offer, null);

        $this->assertSame('2026-06-15', $offer->fresh()->validity_date->toDateString());
    }

    /** @test */
    public function the_configured_number_of_days_is_used_when_regenerating(): void
    {
        $this->setDays(21);
        // Regeneration counts from the offer's existing validity date.
        $offer = $this->offer(['status' => 'Declined', 'validity_date' => '2026-06-10']);

        $this->offers()->regenerate($offer, null);

        $this->assertSame('2026-07-01', $offer->fresh()->validity_date->toDateString());
    }

    /* ═══════════════ 2. EXPLICIT ALWAYS WINS ════════════════════════ */

    /** @test */
    public function an_explicit_date_overrides_the_setting_everywhere(): void
    {
        $this->setDays(30);

        $extended = $this->offer(['status' => 'Expired']);
        $this->offers()->extend($extended, '2026-06-05');
        $this->assertSame('2026-06-05', $extended->fresh()->validity_date->toDateString());

        $regenerated = $this->offer(['status' => 'Declined']);
        $this->offers()->regenerate($regenerated, '2026-06-07');
        $this->assertSame('2026-06-07', $regenerated->fresh()->validity_date->toDateString());
    }

    /**
     * An offer created with no validity date still has none.
     *
     * The open-ended offer is a supported state — a separately reported gap,
     * deliberately not closed by this setting. If the setting started filling
     * it in, that would be a behaviour change smuggled in as configuration.
     *
     * @test
     */
    public function a_blank_validity_on_creation_is_left_blank(): void
    {
        $this->setDays(14);
        $offer = $this->offer(['validity_date' => null]);

        $this->assertNull($offer->fresh()->validity_date);

        // And it does not expire, exactly as before.
        $this->offers()->expireIfDue($offer);
        $this->assertSame('Sent', $offer->fresh()->status);
    }

    /* ═══════════════ 3. GUARDS ══════════════════════════════════════ */

    /**
     * An unusable setting falls back rather than minting a dead offer.
     *
     * Zero or a negative number would produce an offer whose validity had
     * already passed when it was created — expired before it was sent.
     *
     * @test
     */
    public function an_unusable_setting_falls_back_to_seven_days(): void
    {
        foreach ([0, -5, 'abc', ''] as $bad) {
            $this->setDays($bad);
            $offer = $this->offer(['status' => 'Expired']);

            $this->offers()->extend($offer, null);

            $this->assertSame('2026-06-08', $offer->fresh()->validity_date->toDateString(),
                'setting '.var_export($bad, true).' should fall back');
        }
    }

    /* ═══════════════ 4. TENANT ISOLATION ════════════════════════════ */

    /** @test */
    public function the_setting_does_not_leak_between_tenants(): void
    {
        $this->setDays(30, $this->a);

        $mine   = $this->offer(['status' => 'Expired'], $this->a);
        $theirs = $this->offer(['status' => 'Expired'], $this->b);

        $this->offers()->extend($mine, null);
        $this->offers()->extend($theirs, null);

        $this->assertSame('2026-07-01', $mine->fresh()->validity_date->toDateString());
        $this->assertSame('2026-06-08', $theirs->fresh()->validity_date->toDateString(), 'B is unconfigured.');
    }

    /* ═══════════════ 5. NOTHING ELSE MOVED ══════════════════════════ */

    /** Expiry still follows validity_date, unchanged. */
    public function test_expiry_semantics_are_untouched(): void
    {
        $this->setDays(30);
        $past = $this->offer(['validity_date' => '2026-05-01']);

        $this->offers()->expireIfDue($past);

        $this->assertSame('Expired', $past->fresh()->status);
    }

    /** The scheduled sweep still only considers offers that have a date. */
    public function test_the_expiry_command_still_skips_offers_with_no_validity(): void
    {
        $this->setDays(14);
        $open = $this->offer(['validity_date' => null]);

        $this->artisan('offers:expire-due')->assertSuccessful();

        $this->assertSame('Sent', $open->fresh()->status);
    }

    /** @test */
    public function the_setting_is_registered_and_renders_on_the_settings_screen(): void
    {
        $this->assertTrue(HrSetting::isKey('offer_validity_days'));
        $this->assertSame(7, HrSetting::defaults()['offer_validity_days']);

        $keys = collect(HrSetting::schema())->flatten(1)->pluck('key')->all();
        $this->assertContains('offer_validity_days', $keys);

        $rules = HrSetting::registryGroup()['offer_validity_days']['rules'] ?? [];
        $this->assertContains('integer', $rules, 'Validation comes from the registry, not a hand-written rule.');
    }
}
