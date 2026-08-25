<?php

namespace Tests\Unit\Tpv;

use App\Models\Tpv\WorkPermit;
use PHPUnit\Framework\TestCase;

/**
 * §19 permit-type vocabulary. The doc names Isolation, Shutdown and Critical Work
 * as first-class permit types and renames the catch-all from "General" to "Other";
 * the retired "General" value must still validate so historical permits survive.
 */
class PermitTypeVocabularyTest extends TestCase
{
    public function test_doc_permit_types_are_offered(): void
    {
        foreach (['Isolation', 'Shutdown', 'Critical_Work', 'Other'] as $type) {
            $this->assertContains($type, WorkPermit::TYPES, "$type should be an offered permit type");
        }
    }

    public function test_general_is_retired_from_the_pickers(): void
    {
        $this->assertNotContains('General', WorkPermit::TYPES);
    }

    public function test_legacy_general_still_validates_on_write(): void
    {
        $this->assertContains('General', WorkPermit::acceptedTypes());
    }

    public function test_accepted_types_are_a_superset_of_offered_types(): void
    {
        foreach (WorkPermit::TYPES as $type) {
            $this->assertContains($type, WorkPermit::acceptedTypes());
        }
    }
}
