<?php

namespace Tests\Feature\Portal;

use App\Support\Transport\ClientVisibleFields;
use App\Support\Transport\TripStatus;
use Tests\TestCase;

/**
 * One moment, one word.
 *
 * A customer's shipment showed the status "Delivered" while the journey
 * underneath it said "Delivery confirmed" — two words for the moment their POD
 * was verified, on the same screen. Two more pairs had drifted the same way:
 * `pretrip_ok` read "Ready to leave" against "Vehicle checks completed", and
 * `in_transit` read "On the way" against "Collected and on the way".
 *
 * Nothing was wrong with either list. They were two hand-written lists that had
 * to be edited together, and eventually they were not. The status word is now
 * DERIVED from the journey vocabulary, and this is what stops the derivation
 * being quietly unpicked by someone adding a convenient special case.
 */
class StatusAndJourneySpeakOneLanguageTest extends TestCase
{
    public function test_every_status_with_a_moment_uses_that_moments_word(): void
    {
        foreach (ClientVisibleFields::STATUS_EVENT as $status => $type) {
            $this->assertArrayHasKey($type, ClientVisibleFields::CLIENT_EVENTS,
                "Status `{$status}` claims the moment `{$type}`, which is not a client-visible "
                .'event. Either the event belongs in CLIENT_EVENTS or the status does not map to it.');

            $this->assertSame(
                ClientVisibleFields::CLIENT_EVENTS[$type],
                ClientVisibleFields::statusWord($status),
                "Status `{$status}` and the journey row for `{$type}` are the same moment and must "
                .'read the same. Change the phrase in CLIENT_EVENTS, which both sides take it from.'
            );
        }
    }

    public function test_every_state_a_customer_can_meet_has_a_word(): void
    {
        $covered = array_merge(
            array_keys(ClientVisibleFields::STATUS_WORDS),
            array_keys(ClientVisibleFields::STATUS_EVENT),
        );

        // Every one of the sixteen. There is no "a customer never reaches this
        // state" exemption, because the list query scopes by customer and not
        // by status — whatever a trip of theirs is in, they see it.
        foreach (TripStatus::ALL as $status) {
            $this->assertContains($status, $covered,
                "TripStatus::{$status} has no customer-facing word, so a shipment in that state "
                .'reads "In progress" — which is true but says nothing. Add it to STATUS_EVENT if a '
                .'moment produced it, or to STATUS_WORDS if none did.');
        }
    }

    /**
     * The word §8 would take back.
     *
     * M14 is "Payment Received / Trip Closure" and requires the customer's own
     * payment. Our `trip.closed` does not, so neither "Completed" nor "Closed"
     * may be spent on it — see D-124.
     */
    public function test_closure_does_not_use_a_word_m14_claims(): void
    {
        $word = strtolower(ClientVisibleFields::eventWord('trip.closed'));

        foreach (['completed', 'closed', 'closure', 'paid', 'payment'] as $claimed) {
            $this->assertStringNotContainsString($claimed, $word,
                "\"{$word}\" uses \"{$claimed}\", which CLP §8's M14 claims for a state that also "
                .'requires payment. A customer would have to unlearn it — D-124.');
        }
    }
}
