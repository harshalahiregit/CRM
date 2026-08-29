<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'email'         => $this->email,
            'role'          => $this->role,
            'internal_role' => $this->internal_role,
            'department'    => $this->department,
            'status'        => $this->status,
            'vendor_type'   => $this->vendor_type,
            'tpv_type'      => $this->tpv_type,
            'phone'         => $this->phone,
            'company'       => $this->company,
            'designation'   => $this->designation,
            'mail_from_name'  => $this->mail_from_name,
            'mail_from_email' => $this->mail_from_email,
            'avatar'        => $this->avatar,
            'tenant_id'     => $this->tenant_id,
            'external_company_id' => $this->external_company_id,
            'created_at'    => $this->created_at,
        ];
    }
}
