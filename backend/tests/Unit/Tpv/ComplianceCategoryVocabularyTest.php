<?php

namespace Tests\Unit\Tpv;

use App\Support\Tpv\ComplianceCatalog;
use PHPUnit\Framework\TestCase;

/**
 * §21 compliance category set. The doc lists more categories than the original
 * fourteen; these are added while every original category stays valid.
 */
class ComplianceCategoryVocabularyTest extends TestCase
{
    public function test_doc_added_categories_are_present(): void
    {
        foreach ([
            'Environmental_Requirements', 'Waste', 'Chemicals', 'Pollution', 'Certifications',
            'Inspection', 'QA_QC', 'Identification', 'Background_Verification', 'Access',
        ] as $cat) {
            $this->assertContains($cat, ComplianceCatalog::CATEGORIES, "$cat should be a compliance category");
        }
    }

    public function test_original_categories_are_retained(): void
    {
        foreach ([
            'Legal', 'Labour', 'Licences', 'Statutory', 'Contractual', 'HSE', 'Training',
            'Medical', 'Risk_Assessment', 'Method_Statement', 'PPE', 'Environment', 'Quality', 'Security',
        ] as $cat) {
            $this->assertContains($cat, ComplianceCatalog::CATEGORIES, "$cat must remain valid");
        }
    }

    public function test_categories_are_unique(): void
    {
        $c = ComplianceCatalog::CATEGORIES;
        $this->assertSame(array_values(array_unique($c)), array_values($c));
    }
}
