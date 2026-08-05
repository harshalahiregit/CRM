<?php

namespace Tests\Unit\Hr;

use App\Support\Hr\WorkStates;
use PHPUnit\Framework\TestCase;

/**
 * The state vocabulary. The critical behaviour is the LAST group: a city must not
 * normalise to anything, because that is what stops a leftover `location` value
 * from quietly resolving a tax rule.
 */
class WorkStatesTest extends TestCase
{
    public function test_it_accepts_the_canonical_name_in_any_case(): void
    {
        $this->assertSame('Maharashtra', WorkStates::normalize('Maharashtra'));
        $this->assertSame('Maharashtra', WorkStates::normalize('maharashtra'));
        $this->assertSame('Maharashtra', WorkStates::normalize('  MAHARASHTRA  '));
    }

    public function test_it_accepts_the_two_letter_code(): void
    {
        $this->assertSame('Maharashtra', WorkStates::normalize('MH'));
        $this->assertSame('Karnataka', WorkStates::normalize('ka'));
        $this->assertSame('Delhi', WorkStates::normalize('DL'));
    }

    public function test_it_resolves_renamed_and_colloquial_states(): void
    {
        $this->assertSame('Odisha', WorkStates::normalize('Orissa'));
        $this->assertSame('Puducherry', WorkStates::normalize('Pondicherry'));
        $this->assertSame('Uttarakhand', WorkStates::normalize('Uttaranchal'));
        $this->assertSame('Delhi', WorkStates::normalize('New Delhi'));
        $this->assertSame('Tamil Nadu', WorkStates::normalize('tamilnadu'));
    }

    public function test_it_unifies_ampersand_and_and(): void
    {
        $this->assertSame('Jammu and Kashmir', WorkStates::normalize('Jammu & Kashmir'));
        $this->assertSame('Jammu and Kashmir', WorkStates::normalize('J&K'));
        $this->assertSame('Dadra and Nagar Haveli and Daman and Diu', WorkStates::normalize('Daman & Diu'));
    }

    public function test_a_city_is_not_a_state(): void
    {
        // The whole reason this class exists — none of these may resolve.
        foreach (['Pune', 'MUMBAI', 'Bengaluru', 'Hyderabad', 'Noida', 'Gurgaon'] as $city) {
            $this->assertNull(WorkStates::normalize($city), "{$city} is a city, not a jurisdiction");
        }
    }

    public function test_blank_input_is_null_not_an_error(): void
    {
        $this->assertNull(WorkStates::normalize(null));
        $this->assertNull(WorkStates::normalize(''));
        $this->assertNull(WorkStates::normalize('   '));
    }

    public function test_the_option_list_covers_all_states_and_union_territories(): void
    {
        $options = WorkStates::options();

        $this->assertCount(36, $options, '28 states + 8 union territories');
        $this->assertSame('Andaman and Nicobar Islands', $options[0]['name'], 'alphabetical');
        foreach ($options as $o) {
            $this->assertSame($o['name'], WorkStates::normalize($o['code']),
                "every offered code must normalise back to its own name ({$o['code']})");
        }
    }
}
