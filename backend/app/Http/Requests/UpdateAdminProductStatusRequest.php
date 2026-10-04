<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminProductStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['status' => ['required', Rule::in(['active', 'inactive', 'disabled'])]];
        foreach (['product_code', 'category_id', 'name', 'price', 'short_description', 'description',
            'stock', 'low_stock_threshold', 'specifications', 'variants', 'images'] as $field) {
            $rules[$field] = ['missing'];
        }

        return $rules;
    }
}
