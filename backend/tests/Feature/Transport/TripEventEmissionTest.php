<?php

namespace Tests\Feature\Transport;

use App\Support\Transport\TripEventType;
use Tests\TestCase;

/**
 * A registered event type that nothing emits — D-115.
 *
 * ── WHY THIS TEST EXISTS ────────────────────────────────────────────────
 * TripEventRegistryTest already proves that every type WRITTEN to the table is
 * in the registry. It cannot see the opposite defect, which is the one that
 * actually happened: a type sitting in the registry that no code ever writes.
 *
 * That gap was invisible for a different reason than usual. BackfillTripEvents
 * reconstructs a dozen types from the audit trail, so every EXISTING trip had a
 * complete-looking timeline — `trip.created`, `trip.submitted`,
 * `vehicle.allocated` and `pretrip.passed` were all there, and all of them were
 * reconstructions. Nothing wrote them live. A trip created after the backfill
 * ran would simply have been missing those four lines, and the only way to
 * notice was to create a trip and read its timeline, which is how it WAS found
 * (the September 19 closure walk) rather than by any test.
 *
 * ── IT PINS THE GAP RATHER THAN FORBIDDING IT ───────────────────────────
 * Declaring a type before emitting it is legitimate — P2's telemetry and P3's
 * documents and billing are declared here precisely so the vocabulary is agreed
 * before the work lands. So this does not demand that every type be emitted. It
 * demands that the UNEMITTED SET IS EXACTLY THE ONE WRITTEN DOWN BELOW, which
 * makes both directions loud: a new type declared without an emitter, and an
 * emitter deleted from a type that had one.
 *
 * ── HOW IT LOOKS, AND THE ONE RULE THAT FOLLOWS ─────────────────────────
 * By grepping the tree for the type as a STRING LITERAL in a recorder call.
 * That is why AllocationService writes its two calls out longhand instead of
 * looping a table: a type assembled from a variable is a type this test cannot
 * see, and an audit that can be evaded by a local variable is not an audit.
 */
class TripEventEmissionTest extends TestCase
{
    /**
     * Types declared but not emitted, and why. Each entry is a promise that the
     * gap is known — not a licence to add more without saying so.
     */
    private const NOT_EMITTED_YET = [
        // P1 — ours.
        'order.approved'  => 'No order-approval transition exists; TransportOrderService has no approve(). CTD §31 lists the moment, the state machine does not.',
        'event.corrected' => 'CTD §34 corrections have no path yet — nothing in the UI can correct an event, so nothing emits this.',

        // P2 — Shivam. Telemetry; declared so the vocabulary is fixed first.
        'gps.activated'         => 'P2 — telemetry not wired.',
        'gps.position'          => 'P2 — telemetry not wired.',
        // Both, not just one. The first draft of this list said "only genset.on
        // is emitted today" — copied from a hand-run grep that had matched the
        // EXAMPLE in TripEventRecorder's own docblock and counted it as a call
        // site. This test caught it immediately, which is the argument for
        // having it: a scanner that only recognises real calls beats a grep
        // that recognises the word.
        'genset.on'             => 'P2 — telemetry not wired.',
        'genset.off'            => 'P2 — telemetry not wired.',
        'temperature.reading'   => 'P2 — telemetry not wired.',
        'temperature.excursion' => 'P2 — telemetry not wired.',
        'port.entry'            => 'P2 — geofencing not wired.',
        'port.exit'             => 'P2 — geofencing not wired.',
        'gate.in'               => 'P2 — geofencing not wired.',

        // P3 — Zafar. Documents, billing, collection, feedback, compliance.
        'documents.handed_over' => 'P3 — declared, not emitted.',
        'pod.uploaded'          => 'P3 — the document path does not record events yet.',
        'pod.verified'          => 'P3 — verification moves the trip but writes no event.',
        'billing.ready'         => 'P3 — billing writes no events.',
        'invoice.posted'        => 'P3 — billing writes no events.',
        'collection.recorded'   => 'P3 — collection writes no events.',
        'feedback.requested'    => 'P3 — feedback is TM-001 §8, not ours.',
        'feedback.received'     => 'P3 — feedback is TM-001 §8, not ours.',
        'compliance.checked'    => 'P3 — compliance writes no events.',
    ];

    public function test_the_set_of_declared_but_unemitted_event_types_is_exactly_the_documented_one(): void
    {
        $emitted  = $this->typesEmittedInApp();
        $declared = array_keys(TripEventType::REGISTRY);

        $unemitted = array_values(array_diff($declared, $emitted));
        sort($unemitted);

        $documented = array_keys(self::NOT_EMITTED_YET);
        sort($documented);

        $newlySilent = array_diff($unemitted, $documented);
        $this->assertSame([], array_values($newlySilent), sprintf(
            "These event types are declared in TripEventType but NOTHING emits them:\n  - %s\n".
            "Either wire a recorder call, or add the type to NOT_EMITTED_YET with the reason.\n".
            "A declared type nobody writes is a timeline with a hole in it, and the hole is ".
            "invisible on any trip the backfill has touched.",
            implode("\n  - ", $newlySilent),
        ));

        $nowEmitted = array_diff($documented, $unemitted);
        $this->assertSame([], array_values($nowEmitted), sprintf(
            "These types are listed as not-emitted-yet but something now emits them:\n  - %s\n".
            'Remove them from NOT_EMITTED_YET — the list has to stay true to be worth reading.',
            implode("\n  - ", $nowEmitted),
        ));
    }

    /** Every type passed as a literal to a TripEventRecorder::record() call. */
    private function typesEmittedInApp(): array
    {
        $found = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $src = file_get_contents($file);

            // The call and its arguments, up to the closing parenthesis of the
            // statement. Matching the whole call rather than a fixed number of
            // following lines means a call formatted across six lines is still
            // seen — the first version of this scanner read three lines and
            // silently missed the ones that wrapped.
            if (! preg_match_all('/record\(\s*(.*?)\);/s', $src, $calls)) {
                continue;
            }

            foreach ($calls[1] as $args) {
                if (preg_match("/^\s*'([a-z_]+\.[a-z_]+)'/", $args, $m)) {
                    $found[$m[1]] = true;
                }
            }
        }

        return array_keys($found);
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }
}
