<?php

namespace Tests\Feature\Portal;

use App\Models\Helpdesk\KbArticle;
use App\Models\Helpdesk\KbCategory;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase Vendor Portal — Knowledge Base (read-only). The vendor sees only
 * PUBLISHED articles in its own tenant; drafts and other tenants stay hidden.
 */
class PurchasePortalKbTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function vendor(): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE, 'portal_status' => 'active',
        ]);
    }

    public function test_vendor_reads_published_articles_only(): void
    {
        $v = $this->vendor();
        $cat = KbCategory::create(['tenant_id' => self::TENANT, 'name' => 'HSSE', 'slug' => 'hsse']);
        $pub = KbArticle::create(['tenant_id' => self::TENANT, 'category_id' => $cat->id, 'title' => 'Safety Guide', 'excerpt' => 'How to', 'content' => '<p>Body</p>', 'is_published' => true, 'public_slug' => 'safety-guide', 'published_at' => now()]);
        KbArticle::create(['tenant_id' => self::TENANT, 'category_id' => $cat->id, 'title' => 'Draft', 'content' => 'x', 'is_published' => false, 'public_slug' => 'draft-x']);

        Sanctum::actingAs($v);
        $res = $this->getJson('/api/portal/purchase/kb')->assertOk()->json('data');
        $slugs = collect($res)->pluck('slug')->all();
        $this->assertContains('safety-guide', $slugs);
        $this->assertNotContains('draft-x', $slugs);

        $this->getJson("/api/portal/purchase/kb/{$pub->public_slug}")
            ->assertOk()->assertJsonPath('data.content', '<p>Body</p>');
    }

    public function test_draft_article_is_not_reachable_by_slug(): void
    {
        $v = $this->vendor();
        $cat = KbCategory::create(['tenant_id' => self::TENANT, 'name' => 'HSSE', 'slug' => 'hsse']);
        KbArticle::create(['tenant_id' => self::TENANT, 'category_id' => $cat->id, 'title' => 'Draft', 'content' => 'x', 'is_published' => false, 'public_slug' => 'draft-y']);

        Sanctum::actingAs($v);
        $this->getJson('/api/portal/purchase/kb/draft-y')->assertNotFound();
    }
}
