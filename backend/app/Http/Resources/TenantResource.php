<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'subdomain'      => $this->subdomain,
            'plan'           => $this->plan,
            'status'         => $this->status,
            'branding_color' => $this->branding_color,
        ];
    }
}
