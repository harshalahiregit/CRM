<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenants exist in tests mainly so that something owns a row, and the thing that
 * matters about them is that two of them are genuinely different. Name, slug and
 * subdomain are therefore unique rather than pretty.
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);

        return [
            'name'      => $this->faker->company(),
            'slug'      => $slug,
            'subdomain' => $slug,
            'plan'      => 'professional',
            'status'    => 'active',
        ];
    }
}
