<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseWorkerMedical;
use App\Models\Tpv\TpvWorkerMedical;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Shared\MedicalClearanceMessage as Msg;
use Tests\TestCase;

/**
 * The badge blocker says what is wrong, whose move it is, and what to do.
 *
 * The reported line, in full: "1 item blocking the entry badge — Medical Report
 * is Pending — awaiting quality check." Every word true, none of it useful. It
 * does not say whether the vendor has missed something, whether they can fix it,
 * or what happens next — so "pending" reads as "you still owe us something" and
 * the vendor goes looking for a document that is already submitted.
 *
 * The distinction these tests protect is WHOSE MOVE IT IS. A blocker the vendor
 * can clear must name the action; a blocker sitting in someone else's queue must
 * say plainly that nothing is needed, because that is the fact that stops the
 * search.
 */
class MedicalClearanceMessageTest extends TestCase
{
    private const PENDING = 'Medical Report is Pending';

    /** An unsaved record is enough: the message is derived, never queried. */
    private function medical(array $attrs): PurchaseWorkerMedical
    {
        return (new PurchaseWorkerMedical())->forceFill($attrs);
    }

    /* ── the reported case ───────────────────────────────────────────────── */

    /**
     * Waiting on the quality team is NOT a vendor to-do, and must not read like
     * one. This is the exact state that produced the complaint.
     */
    public function test_awaiting_quality_check_tells_the_vendor_to_do_nothing(): void
    {
        $c = Msg::for($this->medical([
            'qc_status' => MedicalQcStatus::PENDING,
            'updated_at' => now()->subDays(6),
        ]), self::PENDING);

        $this->assertSame('pending', $c['status']);
        $this->assertSame(Msg::OWNER_QUALITY, $c['owner'], 'the ball is not in the vendor\'s court');
        $this->assertNull($c['action'], 'inventing a task here is what sent people hunting');

        $this->assertStringContainsString('submitted', $c['message'],
            'it must say the certificate IS in, not merely that something is pending');
        $this->assertStringContainsString('quality team', $c['message']);
        $this->assertStringContainsString('6 days ago', $c['message'],
            'how long it has waited is what decides whether chasing is reasonable');
    }

    /* ── the states the vendor CAN clear ─────────────────────────────────── */

    public function test_a_missing_examination_names_the_step_to_use(): void
    {
        $c = Msg::for(null, self::PENDING);

        $this->assertSame('missing', $c['status']);
        $this->assertSame(Msg::OWNER_VENDOR, $c['owner']);
        $this->assertStringContainsString('Step 2', $c['action']);
    }

    /**
     * A query quotes the quality team's own words.
     *
     * "The quality team has queried the certificate" sends the vendor back to
     * ask what was wrong. The note is the whole point of leaving one.
     */
    public function test_a_query_quotes_the_reason_and_asks_for_a_reply(): void
    {
        $c = Msg::for($this->medical([
            'qc_status' => MedicalQcStatus::HOLD,
            'qc_note' => 'Certificate is illegible',
        ]), self::PENDING);

        $this->assertSame('hold', $c['status']);
        $this->assertSame(Msg::OWNER_VENDOR, $c['owner']);
        $this->assertStringContainsString('Certificate is illegible', $c['message']);
        $this->assertStringContainsString('reply', $c['action']);
    }

    public function test_a_rejection_quotes_its_reason_and_asks_for_a_re_examination(): void
    {
        $c = Msg::for($this->medical([
            'qc_status' => MedicalQcStatus::REJECTED,
            'qc_note' => 'Doctor not on the approved panel',
        ]), self::PENDING);

        $this->assertSame('rejected', $c['status']);
        $this->assertSame(Msg::OWNER_VENDOR, $c['owner']);
        $this->assertStringContainsString('Doctor not on the approved panel', $c['message']);
        $this->assertStringContainsString('re-examination', $c['action']);
    }

    /** With no note left, the sentence still reads properly. */
    public function test_a_rejection_without_a_note_is_still_a_sentence(): void
    {
        $c = Msg::for($this->medical(['qc_status' => MedicalQcStatus::REJECTED]), self::PENDING);

        $this->assertStringNotContainsString('  ', $c['message']);
        $this->assertStringContainsString('rejected this medical.', $c['message']);
    }

    /** An Unfit outcome says the actual outcome, not just "not Fit". */
    public function test_an_unfit_outcome_names_the_outcome(): void
    {
        $c = Msg::for($this->medical([
            'qc_status' => MedicalQcStatus::APPROVED,
            'fitness_status' => 'Unfit',
            'expiry_date' => now()->addYear(),
        ]), self::PENDING);

        $this->assertSame('unfit', $c['status']);
        $this->assertStringContainsString('Unfit', $c['message']);
        $this->assertSame(Msg::OWNER_VENDOR, $c['owner']);
    }

    public function test_an_expired_certificate_gives_the_date(): void
    {
        $c = Msg::for($this->medical([
            'qc_status' => MedicalQcStatus::APPROVED,
            'fitness_status' => 'Fit',
            'expiry_date' => now()->subDays(3),
        ]), self::PENDING);

        $this->assertSame('expired', $c['status']);
        $this->assertStringContainsString(now()->subDays(3)->format('d M Y'), $c['message']);
        $this->assertStringContainsString('current certificate', $c['action']);
    }

    /* ── nothing wrong ───────────────────────────────────────────────────── */

    public function test_a_cleared_medical_asks_nothing_of_anybody(): void
    {
        $c = Msg::for($this->medical([
            'qc_status' => MedicalQcStatus::APPROVED,
            'fitness_status' => 'Fit',
            'expiry_date' => now()->addYear(),
        ]), self::PENDING);

        $this->assertSame('approved', $c['status']);
        $this->assertSame(Msg::OWNER_NONE, $c['owner']);
        $this->assertNull($c['action']);
    }

    /* ── and both engines answer identically ─────────────────────────────── */

    /**
     * A vendor is a vendor. The two engines keep separate models and separate
     * tables, but the sentence a person reads must not depend on which one they
     * happen to be onboarding through.
     */
    public function test_tpv_and_purchase_produce_the_same_answer(): void
    {
        $attrs = ['qc_status' => MedicalQcStatus::PENDING, 'updated_at' => now()->subDay()];

        $purchase = Msg::for((new PurchaseWorkerMedical())->forceFill($attrs), self::PENDING);
        $tpv = Msg::for((new TpvWorkerMedical())->forceFill($attrs), self::PENDING);

        $this->assertSame($purchase, $tpv);
    }

    /** The tenant's own wording for "pending" is respected, not overwritten. */
    public function test_the_tenants_own_pending_wording_is_used(): void
    {
        $c = Msg::for(null, 'Health check outstanding');

        $this->assertStringStartsWith('Health check outstanding', $c['message']);
    }
}
