<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create-order') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:dine_in,takeaway,online'],
            'table_id' => ['nullable', 'integer', 'exists:tables,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'platform' => ['nullable', 'string', 'max:40'],
            'customer_name' => ['nullable', 'string', 'max:60'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
