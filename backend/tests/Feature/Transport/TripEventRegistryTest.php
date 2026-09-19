<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TripEvent;
use App\Support\Transport\TripEventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `trip_events.event_type` is an OPEN column, and this is what stops it drifting.
 *
 * ── THE DECISION THIS GUARDS ────────────────────────────────────────────
 * The column is open because a locked enum would mean Person 2 and Person 3
 * cannot record an event without a migration from Person 1 — and of CTD §31's
 * sixteen example entries, seven are theirs. Both source documents label their
 * type lists "Example"; neither closes one.
 *
 * The cost of an open column is drift. Three developers will produce
 * TemperatureAlert, temp_alert and TEMPERATURE_BREACH for one thing inside a
 * month, and a timeline with three names for one event is not a timeline.
 *
 * So: anyone may append to TripEventType::REGISTRY, with no approval and no
 * migration. What nobody may do is WRITE a type that is not in it. This test
 * reads every distinct event_type in the table and fails on the first one
 * missing — which makes drift visible the day it happens rather than the day
 * somebody reads a timeline and cannot explain it.
 */
class TripEventRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => 1, 'name' => 'Alpha', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();
    }

    public function test_every_event_type_in_the_table_is_in_the_registry(): void
    {
        // Seeded so the assertion has something to chew on in a fresh database;
        // in a real one this reads whatever three sections have written.
        foreach (['trip.created', 'trip.dispatched', 'genset.on', 'pod.uploaded'] as $type) {
            $this->write($type);
        }

        $unregistered = DB::table('trip_events')
            ->select('event_type')->distinct()->pluck('event_type')
            ->reject(fn (string $t) => TripEventType::isKnown($t))
            ->values()->all();

        $this->assertSame([], $unregistered, sprintf(
            "These event types are in trip_events and not in TripEventType::REGISTRY:\n  %s\n\n"
            ."Add each one to that file with its category, source, owner, a human sentence and the\n"
            ."source line it comes from — then tell the group. No approval and no migration are\n"
            ."needed; the registry is open precisely so P2 and P3 never wait on P1 to record\n"
            .'something. What it must not become is three names for one event.',
            implode("\n  ", $unregistered),
        ));
    }

    public function test_every_registry_entry_is_well_formed(): void
    {
        foreach (TripEventType::REGISTRY as $type => $row) {
            $this->assertCount(5, $row, "$type must be [category, source, owner, label, citation]");

            [$category, $source, $owner, $label, $cite] = $row;

            $this->assertContains($category, TripEventType::CATEGORIES,
                "$type's category must be one of CTD §101's nine");
            $this->assertContains($source, TripEventType::SOURCES,
                "$type's source must be one of CTD §33's ten");
            $this->assertContains($owner, [TripEventType::P1, TripEventType::P2, TripEventType::P3],
                "$type must say who emits it");
            $this->assertNotSame('', trim($label), "$type needs a human sentence");
            $this->assertNotSame('', trim($cite),
                "$type must cite its source line, or say 'derived'. Step 11 has no events table "
                .'at all (D-113), so the citation is what stands in for the missing registry row.');
        }
    }

    public function test_the_categories_are_ctd_101s_nine_and_35s_filters_map_onto_them(): void
    {
        $this->assertCount(9, TripEventType::CATEGORIES);

        // §35's seven filters are §101's nine seen from one screen. Every one of
        // them must land on a real category or a filter would show nothing.
        foreach (TripEventType::CTD_35_FILTERS as $filter => $category) {
            $this->assertContains($category, TripEventType::CATEGORIES,
                "CTD §35's '$filter' filter must map onto one of §101's categories");
        }
    }

    public function test_the_registry_covers_every_entry_in_ctd_31s_worked_timeline(): void
    {
        // CTD §31 is the demonstration script's own example. If a line of it has
        // no type, the timeline cannot show that line — which is the whole
        // reason this table exists.
        $ctd31 = [
            'order.approved', 'driver.allocated', 'vehicle.allocated',
            'documents.handed_over', 'trip.dispatched', 'gps.activated', 'genset.on',
            'port.entry', 'port.exit', 'trip.delivered',
            'feedback.requested', 'feedback.received', 'pod.uploaded', 'billing.ready',
        ];

        $missing = array_values(array_filter($ctd31, fn (string $t) => ! TripEventType::isKnown($t)));

        $this->assertSame([], $missing, 'CTD §31 lines with no registered event type: '.implode(', ', $missing));
    }

    public function test_an_unregistered_type_still_reads_as_english(): void
    {
        // The open column working as intended: a type nobody has registered yet
        // must not render as a raw key on a client's screen while it waits.
        $this->assertSame('Gate weighbridge', TripEventType::label('gate.weighbridge'));
        $this->assertFalse(TripEventType::isKnown('gate.weighbridge'));
    }

    public function test_p2_and_p3_own_types_they_can_record_without_asking_us(): void
    {
        // The whole argument for an open column, asserted rather than assumed.
        $this->assertNotEmpty(TripEventType::ownedBy(TripEventType::P2));
        $this->assertNotEmpty(TripEventType::ownedBy(TripEventType::P3));
        $this->assertContains('genset.on', TripEventType::ownedBy(TripEventType::P2));
        $this->assertContains('pod.uploaded', TripEventType::ownedBy(TripEventType::P3));
    }

    private function write(string $type): void
    {
        TripEvent::create([
            'tenant_id' => 1, 'event_type' => $type,
            'category' => TripEventType::categoryFor($type) ?? TripEventType::OPERATIONAL,
            'source' => TripEventType::sourceFor($type) ?? TripEventType::SYSTEM,
            'occurred_at' => now(), 'recorded_at' => now(),
            'summary' => TripEventType::label($type),
        ]);
    }
}
