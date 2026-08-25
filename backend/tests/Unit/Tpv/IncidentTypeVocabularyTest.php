<?php

namespace Tests\Unit\Tpv;

use App\Models\Tpv\HsseIncident;
use PHPUnit\Framework\TestCase;

/**
 * §23 incident taxonomy. The doc names First Aid, Medical Treatment, LTI, Security,
 * Unsafe Act and Unsafe Condition as incident types; the original event types stay
 * valid so historical incidents keep classifying.
 */
class IncidentTypeVocabularyTest extends TestCase
{
    public function test_doc_incident_types_are_offered(): void
    {
        foreach (['First_Aid', 'Medical_Treatment', 'LTI', 'Security', 'Unsafe_Act', 'Unsafe_Condition'] as $type) {
            $this->assertContains($type, HsseIncident::TYPES, "$type should be an incident type");
        }
    }

    public function test_original_types_are_retained(): void
    {
        foreach (['Injury', 'Near_Miss', 'Property_Damage', 'Environmental', 'Fire', 'Fatality', 'Other'] as $type) {
            $this->assertContains($type, HsseIncident::TYPES, "$type must remain valid");
        }
    }
}
