<?php

namespace Tests\Feature\Settings;

use App\Services\Numbering\DocumentNumberServiceInterface;
use App\Exceptions\BusinessException;
use App\Models\Numbering\DocumentNumberConfig;
use App\Models\Tenant;
use App\Support\Numbering\DocumentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A numbering series counts the way it was told to.
 *
 * The engine could already be given a manual baseline, but it only ever counted
 * upwards in ones — so "set manual baselines and paths (increasing or
 * decreasing) from the UI" was half-built: the baseline was there, the path was
 * not. `decrement_on_delete` looked like it might be the missing half and is
 * not; it rolls the cursor back when a document is DELETED so its number can be
 * reused, and says nothing about which way the series runs.
 *
 * These tests hold the arithmetic, including the two things a descending series
 * makes possible that an ascending one never could: running out, and preview
 * disagreeing with allocation.
 */
class NumberingDirectionTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function service(): DocumentNumberServiceInterface
    {
        return app(DocumentNumberServiceInterface::class);
    }

    /** A configured, enabled series of the given shape. */
    private function configure(array $overrides = []): DocumentNumberConfig
    {
        return DocumentNumberConfig::create(array_merge(
            DocumentTypeRegistry::defaults('invoice'),
            ['tenant_id' => self::TENANT, 'enabled' => true, 'reset_rule' => 'never', 'format' => '{PREFIX}-{NEXT}'],
            $overrides,
        ));
    }

    private function nextFew(int $count = 3): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->service()->generate(self::TENANT, 'invoice');
        }

        return $out;
    }

    /* ── Up: unchanged ──────────────────────────────────────────────────── */

    public function test_a_series_still_counts_up_in_ones_by_default(): void
    {
        // The default must reproduce the old behaviour exactly, or adding this
        // feature silently renumbers every series already in use.
        $this->configure(['starting_number' => 1]);

        $this->assertSame(['INV-001', 'INV-002', 'INV-003'], $this->nextFew());
    }

    public function test_the_manual_baseline_is_still_honoured(): void
    {
        $this->configure(['starting_number' => 500]);

        $this->assertSame(['INV-500', 'INV-501', 'INV-502'], $this->nextFew());
    }

    /* ── Down ───────────────────────────────────────────────────────────── */

    public function test_a_series_can_count_down(): void
    {
        $this->configure(['starting_number' => 5000, 'direction' => 'down']);

        $this->assertSame(['INV-5000', 'INV-4999', 'INV-4998'], $this->nextFew());
    }

    public function test_the_first_number_down_is_exactly_the_baseline(): void
    {
        // The seed row sits one step behind the baseline. Downwards that means
        // one step AHEAD of it — get the sign wrong and the first document ever
        // issued is off by one, in the direction nobody checks.
        $this->configure(['starting_number' => 100, 'direction' => 'down']);

        $this->assertSame('INV-100', $this->service()->generate(self::TENANT, 'invoice'));
    }

    /* ── Step ───────────────────────────────────────────────────────────── */

    public function test_a_series_can_move_in_steps(): void
    {
        $this->configure(['starting_number' => 100, 'step' => 10]);

        $this->assertSame(['INV-100', 'INV-110', 'INV-120'], $this->nextFew());
    }

    public function test_step_and_direction_work_together(): void
    {
        $this->configure(['starting_number' => 1000, 'direction' => 'down', 'step' => 25]);

        $this->assertSame(['INV-1000', 'INV-975', 'INV-950'], $this->nextFew());
    }

    public function test_a_zero_step_is_treated_as_one(): void
    {
        // A series that never moves would hand the same number to every
        // document. Whatever is stored, the engine must still advance.
        $this->configure(['starting_number' => 7, 'step' => 0]);

        $this->assertSame(['INV-007', 'INV-008'], $this->nextFew(2));
    }

    /* ── Running out ────────────────────────────────────────────────────── */

    public function test_a_descending_series_refuses_to_pass_its_floor(): void
    {
        // Only a descending series can exhaust itself. Handing out 0, then -1,
        // as document numbers would be far worse than refusing.
        $this->configure(['starting_number' => 2, 'direction' => 'down']);

        $this->assertSame(['INV-002', 'INV-001'], $this->nextFew(2));

        $this->expectException(BusinessException::class);
        $this->service()->generate(self::TENANT, 'invoice');
    }

    /* ── Preview must match what is issued ──────────────────────────────── */

    public function test_preview_agrees_with_the_number_actually_issued(): void
    {
        // Preview is read-only and computes the next number separately, so it is
        // exactly where the two could drift apart — and a preview that lies
        // about the next invoice number is worse than no preview.
        $this->configure(['starting_number' => 900, 'direction' => 'down', 'step' => 5]);

        foreach (range(1, 3) as $ignored) {
            $shown = $this->service()->preview(self::TENANT, 'invoice');
            $this->assertSame($shown, $this->service()->generate(self::TENANT, 'invoice'));
        }
    }

    /* ── The types the brief named ──────────────────────────────────────── */

    public function test_every_document_type_the_brief_asked_for_exists(): void
    {
        // Sales asked for Proposals, Proforma Invoices and Tax Invoices; Purchase
        // for vendor codes. A proforma and a tax invoice are separate legal
        // documents and cannot share the invoice series.
        foreach ([
            'invoice' => 'Invoice',
            'proforma_invoice' => 'Proforma Invoice',
            'tax_invoice' => 'Tax Invoice',
            'estimate' => 'Estimate',
            'proposal' => 'Proposal',
            'purchase_vendor' => 'Purchase Vendor Code',
            'ticket' => 'Ticket',
        ] as $key => $label) {
            $this->assertTrue(DocumentTypeRegistry::exists($key), "{$key} is missing from the registry");
            $this->assertSame($label, DocumentTypeRegistry::get($key)['label']);
        }
    }

    public function test_the_new_types_number_independently_of_each_other(): void
    {
        // Sharing a sequence would mean issuing INV-001 and then TAX-002.
        foreach (['invoice', 'proforma_invoice', 'tax_invoice'] as $type) {
            DocumentNumberConfig::create(array_merge(
                DocumentTypeRegistry::defaults($type),
                ['tenant_id' => self::TENANT, 'enabled' => true, 'reset_rule' => 'never', 'format' => '{PREFIX}-{NEXT}'],
            ));
        }

        $this->assertSame('INV-001', $this->service()->generate(self::TENANT, 'invoice'));
        $this->assertSame('PI-001', $this->service()->generate(self::TENANT, 'proforma_invoice'));
        $this->assertSame('TAX-001', $this->service()->generate(self::TENANT, 'tax_invoice'));
    }

    /* ── The UI can set it ──────────────────────────────────────────────── */

    public function test_direction_and_step_are_accepted_from_settings(): void
    {
        $admin = \App\Models\User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->putJson('/api/settings/numbering/invoice', [
            'format' => '{PREFIX}-{NEXT}', 'prefix' => 'INV',
            'starting_number' => 800, 'direction' => 'down', 'step' => 4,
            'enabled' => true, 'reset_rule' => 'never',
        ])->assertSuccessful();

        $this->assertSame('INV-800', $this->service()->generate(self::TENANT, 'invoice'));
        $this->assertSame('INV-796', $this->service()->generate(self::TENANT, 'invoice'));
    }

    public function test_a_direction_nobody_offers_is_refused(): void
    {
        $admin = \App\Models\User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'b@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->putJson('/api/settings/numbering/invoice', [
            'format' => '{PREFIX}-{NEXT}', 'direction' => 'sideways',
        ])->assertStatus(422)->assertJsonValidationErrors('direction');
    }
}
