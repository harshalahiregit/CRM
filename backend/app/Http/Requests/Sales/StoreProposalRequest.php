<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'       => 'required|string|max:255',
            'reference_no'  => 'nullable|string|max:255',
            'terms'         => 'nullable|string',
            'rel_type'      => 'nullable|in:lead,customer',
            'rel_id'        => 'nullable|integer',
            'contact_id'    => 'nullable|integer',
            'project_id'    => 'nullable|integer',
            'date'          => 'required|date',
            'open_till'     => 'nullable|date',
            'currency'      => 'nullable|string|size:3',
            'discount_type' => 'nullable|in:none,before_tax,after_tax',
            'discount_mode' => 'nullable|in:fixed,percent',
            'discount_value'=> 'nullable|numeric|min:0',
            'status'        => 'nullable|in:Draft,Open,Sent,Accepted,Declined,Expired',
            'assigned_to'   => 'nullable|exists:users,id',
            'proposal_to'   => 'nullable|string|max:255',
            'address'       => 'nullable|string',
            'city'          => 'nullable|string',
            'state'         => 'nullable|string',
            'country'       => 'nullable|string',
            'zip'           => 'nullable|string',
            'email'         => 'nullable|email',
            'phone'         => 'nullable|string',
            'allow_comments'=> 'nullable|boolean',
            'public_view_otp_enabled' => 'nullable|boolean',
            'template_id'   => 'nullable|integer',
            'tags'          => 'nullable|string',
            'notes'         => 'nullable|string',
            'cover'            => 'nullable|array',
            'cover.enabled'    => 'nullable|boolean',
            'cover.image'      => 'nullable|string|max:1500000',
            'cover.title'      => 'nullable|string|max:255',
            'cover.heading'    => 'nullable|string|max:500',
            'pages'            => 'nullable|array',
            'pages.*.title'    => 'nullable|string|max:255',
            'pages.*.content'  => 'nullable|string',
            'line_items'    => 'nullable|array',
            'line_items.*.item_name'   => 'required_with:line_items|string',
            'line_items.*.qty'         => 'required_with:line_items|numeric|min:0',
            'line_items.*.rate'        => 'required_with:line_items|numeric|min:0',
            'line_items.*.tax'         => 'nullable|numeric|min:0|max:100',
            'line_items.*.taxes'        => 'nullable|array',
            'line_items.*.taxes.*.name' => 'required_with:line_items.*.taxes|string|max:60',
            'line_items.*.taxes.*.rate' => 'required_with:line_items.*.taxes|numeric|min:0|max:100',
            'line_items.*.discount'    => 'nullable|numeric|min:0',
            'line_items.*.discount_mode' => 'nullable|in:fixed,percent',
            'line_items.*.unit'        => 'nullable|string',
            'line_items.*.description' => 'nullable|string',
        ];
    }
}
