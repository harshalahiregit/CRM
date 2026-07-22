<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'discount_type' => 'nullable|in:none,before_tax,after_tax',
            'discount_mode' => 'nullable|in:fixed,percent',
            'discount_value'=> 'nullable|numeric|min:0',
            'subject'       => 'sometimes|string|max:255',
            'reference_no'  => 'nullable|string|max:255',
            'terms'         => 'nullable|string',
            'rel_type'      => 'sometimes|in:lead,customer',
            'rel_id'        => 'sometimes|integer',
            'contact_id'    => 'nullable|integer',
            'assigned_to'   => 'nullable|exists:users,id',
            'date'          => 'sometimes|date',
            'open_till'     => 'nullable|date',
            'status'        => 'sometimes|in:Draft,Open,Sent,Accepted,Declined,Expired',
            'notes'         => 'nullable|string',
            'tags'          => 'nullable|string',
            'allow_comments'=> 'nullable|boolean',
            'public_view_otp_enabled' => 'nullable|boolean',
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
            'line_items.*.unit'        => 'nullable|string|max:50',
            'line_items.*.description' => 'nullable|string',
        ];
    }
}
