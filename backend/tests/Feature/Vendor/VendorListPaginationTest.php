<?php

namespace Tests\Feature\Vendor;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/vendors — opt-in pagination.
 *
 * The endpoint is read by six callers that expect a bare array, one of which
 * (the task form's vendor picker) destructures it directly and would render
 * nothing against a paginated envelope. So pagination is keyed on `per_page`
 * being present, and the first test here is the one that matters: without it,
 * the response shape must not have changed at all.
 */
class VendorListPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'email' => 'a'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]));
    }

    private function vendor(array $attrs = []): Vendor
    {
        return Vendor::create(array_merge([
            'tenant_id'    => self::TENANT,
            'company_name' => 'Acme '.uniqid(),
            'vendor_code'  => 'V'.substr(uniqid(), -6),
            'email'        => 'v'.uniqid().'@test.com',
            'status'       => 'Active',
        ], $attrs));
    }

    public function test_without_per_page_the_response_is_still_a_bare_array(): void
    {
        $this->vendor();
        $this->vendor();

        $body = $this->getJson('/api/vendors')->assertOk()->json();

        // The contract six other callers depend on. If this ever becomes an
        // object, the task-form vendor picker silently renders an empty list.
        $this->assertIsList($body);
        $this->assertCount(2, $body);
    }

    public function test_per_page_returns_the_standard_pagination_envelope(): void
    {
        foreach (range(1, 5) as $i) {
            $this->vendor();
        }

        $this->getJson('/api/vendors?per_page=2')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'last_page', 'per_page', 'total'])
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 5)
            ->assertJsonCount(2, 'data');
    }

    public function test_pages_return_different_rows(): void
    {
        foreach (range(1, 4) as $i) {
            $this->vendor();
        }

        $first  = $this->getJson('/api/vendors?per_page=2&page=1')->json('data.*.id');
        $second = $this->getJson('/api/vendors?per_page=2&page=2')->json('data.*.id');

        $this->assertNotEmpty($first);
        $this->assertEmpty(array_intersect($first, $second), 'Pages must not overlap.');
    }

    public function test_search_is_applied_on_the_server(): void
    {
        $this->vendor(['company_name' => 'Findable Industries']);
        $this->vendor(['company_name' => 'Unrelated Traders']);

        $this->getJson('/api/vendors?per_page=25&search=Findable')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.company_name', 'Findable Industries');
    }

    public function test_sorting_reverses_with_the_direction(): void
    {
        $this->vendor(['company_name' => 'Alpha']);
        $this->vendor(['company_name' => 'Zulu']);

        $asc  = $this->getJson('/api/vendors?per_page=25&sort_column=company_name&sort_direction=asc')->json('data.*.company_name');
        $desc = $this->getJson('/api/vendors?per_page=25&sort_column=company_name&sort_direction=desc')->json('data.*.company_name');

        $this->assertSame('Alpha', $asc[0]);
        $this->assertSame('Zulu', $desc[0]);
    }

    public function test_an_unlisted_sort_column_is_ignored_rather_than_injected(): void
    {
        $this->vendor();

        // orderBy interpolates its column into the SQL. A non-whitelisted value
        // must fall back to the default order, not reach the database and not
        // fail the request.
        $this->getJson('/api/vendors?per_page=5&sort_column=id%3BDROP+TABLE+vendors')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);

        $this->assertDatabaseCount('vendors', 1);
    }

    public function test_per_page_is_capped(): void
    {
        $this->vendor();

        // An unbounded per_page would let one request pull the whole table and
        // defeat the point of paginating.
        $this->getJson('/api/vendors?per_page=99999')
            ->assertOk()
            ->assertJsonPath('per_page', 200);
    }

    public function test_the_list_stays_tenant_scoped_when_paginated(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();

        $this->vendor();
        Vendor::create([
            'tenant_id' => 2, 'company_name' => 'Other Tenant Co',
            'vendor_code' => 'X1', 'email' => 'x@test.com', 'status' => 'Active',
        ]);

        $this->getJson('/api/vendors?per_page=25')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonMissing(['company_name' => 'Other Tenant Co']);
    }
}
