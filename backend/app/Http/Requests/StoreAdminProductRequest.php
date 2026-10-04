<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 身分與active由route middleware驗證。
    }

    public function rules(): array
    {
        return [
            'product_code' => ['missing'],
            'images' => ['missing'],
            'stock_mode' => ['missing'],
            'category_id' => ['required', 'integer', 'min:1', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'low_stock_threshold' => ['sometimes', 'required', 'integer', 'min:0', 'max:4294967295'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'inactive', 'disabled'])],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4294967295'],
            'specifications' => ['sometimes', 'array'],
            'specifications.*' => ['array:spec_name,spec_value,sort_order'],
            'specifications.*.spec_name' => ['required', 'string', 'max:100'],
            'specifications.*.spec_value' => ['required', 'string', 'max:255'],
            'specifications.*.sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:4294967295'],
            'variants' => ['sometimes', 'array', 'min:1'],
            'variants.*' => ['array:option_name,option_value,stock,status'],
            'variants.*.option_name' => ['required', 'string', 'max:50'],
            'variants.*.option_value' => ['required', 'string', 'max:100', 'distinct:strict'],
            'variants.*.stock' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'variants.*.status' => ['sometimes', 'required', Rule::in(['active', 'inactive'])],
        ];
    }
}
