<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseOnboarding;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The company-profile step says WHY it refused, and keeps what was typed.
 *
 * Two failures were reported together, and they turned out to be the same one.
 *
 * The step-2 profile is a long form — company, contact, bank, GST, PAN, address.
 * Its bank block carries `required_with` both ways, so an account number typed
 * without its IFSC failed the WHOLE save. On the explicit save the vendor was
 * shown the literal words "Validation failed" and nothing else: the frontend read
 * the server's headline before the per-field detail, and that headline is the
 * same three words for every validation failure there has ever been. On the way
 * to the next step the same 422 was swallowed silently — so everything else they
 * had typed, a dozen fields of it, was thrown away because one field was
 * half-finished, and nobody was told anything.
 *
 * Three things are proven here, on BOTH engines, because a vendor is a vendor:
 *
 *   1. A draft judges each field on its own — what stands is stored, what does
 *      not is NAMED rather than failing the lot.
 *   2. A strict save's messages name the box on the form, not the JSON path.
 *      "The IFSC field is required when Account number is present", not
 *      "The profile.bank ifsc field is required when profile.bank account
 *      number is present".
 *   3. A field the form sends and the server does not know is reported, not
 *      dropped in silence — which is exactly how the Purchase form's address
 *      went missing on every save for as long as it existed.
 *
 * @see \App\Support\Shared\OnboardingProfileDraft
 */
class OnboardingProfileValidationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $vendorUser;

    private PurchaseVendor $pVendor;

    private User $admin;

    /** What a vendor has typed by the time they move on: mostly good, partly not. */
    private const HALF_FILLED = [
        'company_name' => 'Southgate Industrial Supplies',
        'contact_person' => 'Rita Bose',
        'contact_email' => 'rita@southgate.example',
        'registered_address' => '12 MG Road, Andheri East',
        'city' => 'Mumbai',
        'bank_account_number' => '50100234567890',   // the IFSC is still to come
    ];

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendorUser = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Vendor', 'role' => 'third_party_vendor',
            'email' => 'v-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->pVendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Southgate',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => 'sg-'.Str::random(4).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);

        $this->admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya Nair', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /**
     * The same save, made by staff on the ADMIN surface.
     *
     * Each engine shares ONE FormRequest between its admin and portal
     * controllers, so the rules and the draft handling are common by
     * construction — but the controllers are not, and the Purchase admin one was
     * missed when the draft handling went into the other three.
     */
    private function saveProfileAsAdmin(string $engine, $ob, array $profile, bool $draft = false)
    {
        Sanctum::actingAs($this->admin);
        $url = $engine === 'tpv'
            ? "/api/tpv/onboarding/{$ob->id}/profile"
            : "/api/purchase/onboarding/{$ob->id}/profile";

        return $this->postJson($url, ['profile' => $profile, 'draft' => $draft]);
    }

    private function tpv(): TpvOnboarding
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme',
            'status' => VendorStatus::DRAFT, 'user_id' => $this->vendorUser->id,
        ]);

        return TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'status' => 'In_Progress', 'current_step' => 2,
        ]);
    }

    private function purchase(): PurchaseOnboarding
    {
        return PurchaseOnboarding::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->pVendor->id,
            'status' => 'In_Progress', 'current_step' => 2,
        ]);
    }

    /** Sign in as the right identity and post to the right engine's endpoint. */
    private function saveProfile(string $engine, $ob, array $profile, bool $draft = false)
    {
        if ($engine === 'tpv') {
            Sanctum::actingAs($this->vendorUser);
            $url = "/api/portal/onboarding/{$ob->id}/profile";
        } else {
            Sanctum::actingAs($this->pVendor);
            $url = "/api/portal/purchase/onboarding/{$ob->id}/profile";
        }

        return $this->postJson($url, ['profile' => $profile, 'draft' => $draft]);
    }

    private function onboardingFor(string $engine)
    {
        return $engine === 'tpv' ? $this->tpv() : $this->purchase();
    }

    public static function engines(): array
    {
        return ['TPV' => ['tpv'], 'Purchase' => ['purchase']];
    }

    /* ── a draft keeps what stands on its own ────────────────────────────── */

    /**
     * The reported loss: one unfinished field must not cost the other five.
     *
     * @dataProvider engines
     */
    public function test_an_unfinished_field_does_not_throw_away_the_rest(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $this->saveProfile($engine, $ob, self::HALF_FILLED, draft: true)->assertOk();

        $saved = $ob->fresh()->profile;

        foreach (['company_name', 'contact_person', 'contact_email', 'registered_address', 'city'] as $field) {
            $this->assertSame(self::HALF_FILLED[$field], $saved[$field] ?? null,
                "$field was typed and must still be there");
        }
        $this->assertSame('50100234567890', $saved['bank_account_number'] ?? null,
            'an account number without its IFSC is unfinished, not invalid');
    }

    /**
     * And what could NOT be kept is named, in words about the form.
     *
     * @dataProvider engines
     */
    public function test_a_draft_names_what_it_could_not_store(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $res = $this->saveProfile($engine, $ob, self::HALF_FILLED + [
            'pincode' => '4000',            // half typed
            'gst_number' => '27AAECS12',    // half typed
        ], draft: true)->assertOk();

        $skipped = $res->json('skipped');

        $this->assertArrayHasKey('pincode', $skipped);
        $this->assertArrayHasKey('gst_number', $skipped);
        $this->assertStringContainsString('Pincode', $skipped['pincode']);
        $this->assertStringContainsString('GST number', $skipped['gst_number']);

        $saved = $ob->fresh()->profile;
        $this->assertArrayNotHasKey('pincode', $saved, 'a half-typed pincode is not stored as if it were real');
        $this->assertSame('Mumbai', $saved['city'], 'and the rest of the form is untouched by it');
    }

    /**
     * A draft in which nothing is finished is still not an error.
     *
     * `profile` is `required` on the strict path, and an empty array fails that —
     * so sifting everything out would have turned a bad draft into a 422 telling
     * the vendor their profile was missing.
     *
     * @dataProvider engines
     */
    public function test_a_draft_with_nothing_worth_keeping_is_not_an_error(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $this->saveProfile($engine, $ob, ['pincode' => '40'], draft: true)
            ->assertOk()
            ->assertJsonPath('skipped.pincode', 'The Pincode field must be 6 digits.');
    }

    /* ── the strict save says why, in the form's own words ───────────────── */

    /**
     * Save & Continue still refuses a half-finished bank block — and says so
     * in a sentence naming boxes the vendor can actually see.
     *
     * @dataProvider engines
     */
    public function test_a_strict_save_names_the_field_on_the_form(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $res = $this->saveProfile($engine, $ob, self::HALF_FILLED)->assertStatus(422);

        $message = $res->json('errors.profile\\.bank_ifsc.0')
            ?? collect($res->json('errors'))->flatten()->first();

        $this->assertStringContainsString('IFSC', $message);
        $this->assertStringContainsString('Account number', $message);
        $this->assertStringNotContainsString('profile.', $message,
            'a payload path is not a thing the vendor has ever seen');
    }

    /**
     * The detail is what matters, and it must be there to be read.
     *
     * The headline is the same three words for every validation failure in the
     * system; the frontend used to show it and stop. Whatever the headline says,
     * `errors` has to carry the per-field reason.
     *
     * @dataProvider engines
     */
    public function test_the_response_carries_a_reason_per_field(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $res = $this->saveProfile($engine, $ob, [
            'company_name' => 'Southgate',
            'pincode' => '4000',
            'gst_number' => '27AAECS12',
        ])->assertStatus(422);

        $errors = $res->json('errors');

        $this->assertArrayHasKey('profile.pincode', $errors);
        $this->assertArrayHasKey('profile.gst_number', $errors);
    }

    /* ── nothing the form sends may vanish in silence ────────────────────── */

    /**
     * The Purchase form sent `address`; no rule matched it, so `validated()`
     * dropped it and the save returned 200. The vendor typed their registered
     * address, was told it saved, and it was never there.
     *
     * The form now sends `registered_address`. This proves the address survives
     * a real request on both engines, and that an unknown key is REPORTED rather
     * than disappearing — so the next time somebody adds a field to the form and
     * forgets the rule, they are told instead of shipping it.
     *
     * @dataProvider engines
     */
    public function test_the_registered_address_is_actually_stored(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $this->saveProfile($engine, $ob, [
            'company_name' => 'Southgate',
            'registered_address' => '12 MG Road, Andheri East',
        ], draft: true)->assertOk();

        $this->assertSame('12 MG Road, Andheri East', $ob->fresh()->profile['registered_address'] ?? null);
    }

    /** @dataProvider engines */
    public function test_a_field_the_server_does_not_know_is_reported_not_dropped(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $res = $this->saveProfile($engine, $ob, [
            'company_name' => 'Southgate',
            'address' => '12 MG Road',      // the old, unmatched key
        ], draft: true)->assertOk();

        $this->assertArrayHasKey('address', $res->json('skipped'),
            'silence is how a dropped field survives to production');
        $this->assertArrayNotHasKey('address', $ob->fresh()->profile);
    }

    /* ── the two engines accept the same form ───────────────────────────── */

    /**
     * Purchase's rule set was a strict SUBSET of TPV's — 33 of 48 fields, with
     * nothing going the other way. That is not a policy difference; it is a copy
     * that never caught up, and `validated()` drops any key no rule matches, so
     * every one of those 15 fields would have been thrown away in silence the
     * moment the two forms were shared. Exactly how the registered address was
     * lost, at fifteen times the scale.
     *
     * One flow, one kind of vendor, one accepted shape.
     */
    public function test_both_engines_accept_the_same_profile_fields(): void
    {
        $fields = fn (string $cls) => collect(array_keys((new $cls)->rules()))
            ->reject(fn ($k) => in_array($k, ['profile', 'draft'], true))
            ->sort()->values()->all();

        $tpv = $fields(\App\Http\Requests\Tpv\SaveOnboardingProfileRequest::class);
        $purchase = $fields(\App\Http\Requests\Purchase\SavePurchaseOnboardingProfileRequest::class);

        $this->assertSame($tpv, $purchase,
            'a vendor is a vendor — the two forms must accept the same shape');
    }

    /**
     * And the fields Purchase was missing actually store, through a real request.
     *
     * A matching rule list is necessary but not sufficient: the value still has
     * to survive validation and land in the profile.
     *
     * @dataProvider engines
     */
    public function test_the_fields_purchase_was_missing_are_stored(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $added = [
            'dob' => '1985-04-12',
            'gender' => 'Female',
            'emergency_contact' => 'Anil Bose',
            'emergency_phone' => '9820011223',
            'authorized_id_proof' => 'Aadhaar',
            'estimated_workforce' => 24,
            'company_reg_date' => '2011-06-01',
            'registration_date' => '2011-06-01',
            'linkedin' => 'https://linkedin.com/company/southgate',
            'facebook' => 'https://facebook.com/southgate',
            'twitter' => 'https://x.com/southgate',
            'instagram' => 'https://instagram.com/southgate',
            'youtube' => 'https://youtube.com/@southgate',
            'portfolio' => 'https://southgate.example/work',
            'profile_photo' => 'https://southgate.example/logo.png',
        ];

        $res = $this->saveProfile($engine, $ob, ['company_name' => 'Southgate'] + $added, draft: true)->assertOk();

        $this->assertSame([], $res->json('skipped'), 'none of them may be set aside as unknown');

        $saved = $ob->fresh()->profile;
        foreach ($added as $field => $value) {
            $this->assertSame($value, $saved[$field] ?? null, "$field must survive a real request");
        }
    }

    /* ── and a cleared box clears ────────────────────────────────────────── */

    /**
     * Deleting a value has to stick.
     *
     * The save merges onto what is stored, and the wizard used to DROP empty
     * fields from the payload — so clearing a box sent nothing about it, the
     * merge kept the old value, and the deleted text reappeared on the next
     * load. Empty is now sent as null, which the merge stores.
     *
     * @dataProvider engines
     */
    public function test_clearing_a_field_actually_clears_it(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $this->saveProfile($engine, $ob, ['company_name' => 'Southgate', 'city' => 'Mumbai'], draft: true)->assertOk();
        $this->assertSame('Mumbai', $ob->fresh()->profile['city']);

        $this->saveProfile($engine, $ob, ['company_name' => 'Southgate', 'city' => null], draft: true)->assertOk();

        $this->assertNull($ob->fresh()->profile['city'] ?? null,
            'a box the vendor emptied stays empty');
    }

    /* ── the admin surface behaves the same ──────────────────────────────── */

    /**
     * Staff editing a vendor's profile get the same treatment as the vendor.
     *
     * @dataProvider engines
     */
    public function test_the_admin_surface_keeps_a_half_filled_draft_too(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $res = $this->saveProfileAsAdmin($engine, $ob, self::HALF_FILLED + ['pincode' => '4000'], draft: true)
            ->assertOk();

        $this->assertArrayHasKey('pincode', $res->json('skipped'),
            'the admin is told what could not be stored, in the same words');
        $this->assertSame('Mumbai', $ob->fresh()->profile['city'],
            'and the rest of what they typed is kept');
    }

    /**
     * The Purchase ADMIN controller was the fourth of four and had been missed:
     * it read `validated()['profile']` unguarded, so a draft that sifted down to
     * nothing was a 500 rather than a no-op.
     *
     * @dataProvider engines
     */
    public function test_an_empty_admin_draft_is_not_a_server_error(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $this->saveProfileAsAdmin($engine, $ob, ['pincode' => '40'], draft: true)
            ->assertOk()
            ->assertJsonPath('skipped.pincode', 'The Pincode field must be 6 digits.');
    }

    /**
     * And a strict admin save still names the box, not the payload path.
     *
     * @dataProvider engines
     */
    public function test_the_admin_surface_names_the_field_on_the_form(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $res = $this->saveProfileAsAdmin($engine, $ob, self::HALF_FILLED)->assertStatus(422);

        $message = collect($res->json('errors'))->flatten()->first();

        $this->assertStringContainsString('IFSC', $message);
        $this->assertStringNotContainsString('profile.', $message);
    }

    /* ── the draft path is not a way around the rules ────────────────────── */

    /**
     * A draft is a convenience, not a loophole: it may not finish an onboarding,
     * and it still refuses an onboarding that is closed.
     *
     * @dataProvider engines
     */
    public function test_a_draft_cannot_touch_a_closed_onboarding(string $engine): void
    {
        $ob = $this->onboardingFor($engine);
        $ob->forceFill(['status' => 'Approved'])->save();

        $this->saveProfile($engine, $ob, ['company_name' => 'Sneaky Rename Ltd'], draft: true)
            ->assertStatus(422);

        $this->assertNotSame('Sneaky Rename Ltd', $ob->fresh()->profile['company_name'] ?? null);
    }

    /** @dataProvider engines */
    public function test_a_draft_does_not_advance_the_status(string $engine): void
    {
        $ob = $this->onboardingFor($engine);

        $this->saveProfile($engine, $ob, self::HALF_FILLED, draft: true)->assertOk();

        $this->assertSame('In_Progress', $ob->fresh()->status);
    }
}
